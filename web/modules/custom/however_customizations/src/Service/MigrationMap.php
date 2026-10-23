<?php

namespace Drupal\however_customizations\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\however_customizations\MigrationStage;
use Drupal\node\NodeInterface;

/**
 * Records which unified node was created from each legacy node.
 *
 * Every migration stage records its pairs here. A stage skips legacy nodes
 * that are already mapped, later stages look up the unified node for a
 * legacy parent, and a rollback removes the unified nodes listed for a stage.
 * Legacy nodes are never changed or deleted through this service.
 */
class MigrationMap {

  /**
   * The name of the map table.
   */
  public const TABLE = 'however_migration_map';

  /**
   * Constructs a MigrationMap object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\however_customizations\Service\AliasSwapper $aliasSwapper
   *   The alias swapper, used to give the legacy aliases back on a rollback.
   */
  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    protected AliasSwapper $aliasSwapper,
  ) {}

  /**
   * Records that a unified node was created from a legacy node.
   *
   * @param \Drupal\however_customizations\MigrationStage $stage
   *   The migration stage.
   * @param \Drupal\node\NodeInterface $old
   *   The legacy node.
   * @param \Drupal\node\NodeInterface $new
   *   The unified node.
   *
   * @throws \Drupal\Core\Database\IntegrityConstraintViolationException
   *   If the legacy node is already mapped.
   */
  public function record(MigrationStage $stage, NodeInterface $old, NodeInterface $new): void {
    $this->database->insert(self::TABLE)
      ->fields([
        'stage' => $stage->value,
        'old_nid' => $old->id(),
        'old_bundle' => $old->bundle(),
        'old_uuid' => $old->uuid(),
        'new_nid' => $new->id(),
        'new_uuid' => $new->uuid(),
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * Checks whether a legacy node has been migrated.
   *
   * @param int $old_nid
   *   The legacy node ID.
   *
   * @return bool
   *   TRUE if the legacy node is mapped.
   */
  public function isMapped(int $old_nid): bool {
    return $this->getNewNid($old_nid) !== NULL;
  }

  /**
   * Gets the unified node ID created from a legacy node.
   *
   * @param int $old_nid
   *   The legacy node ID.
   *
   * @return int|null
   *   The unified node ID, or NULL if the legacy node is not mapped.
   */
  public function getNewNid(int $old_nid): ?int {
    $new_nid = $this->database->select(self::TABLE, 'm')
      ->fields('m', ['new_nid'])
      ->condition('old_nid', $old_nid)
      ->execute()
      ->fetchField();

    return $new_nid === FALSE ? NULL : (int) $new_nid;
  }

  /**
   * Gets the legacy node ID a unified node was created from.
   *
   * @param int $new_nid
   *   The unified node ID.
   *
   * @return int|null
   *   The legacy node ID, or NULL if the unified node is not mapped.
   */
  public function getOldNid(int $new_nid): ?int {
    $old_nid = $this->database->select(self::TABLE, 'm')
      ->fields('m', ['old_nid'])
      ->condition('new_nid', $new_nid)
      ->execute()
      ->fetchField();

    return $old_nid === FALSE ? NULL : (int) $old_nid;
  }

  /**
   * Gets the recorded pairs, oldest first.
   *
   * @param \Drupal\however_customizations\MigrationStage|null $stage
   *   Restrict the result to one stage, or NULL for all stages.
   *
   * @return array
   *   Rows keyed by position, each with the stage, old_nid, old_bundle,
   *   old_uuid, new_nid, new_uuid, and created values.
   */
  public function getRows(?MigrationStage $stage = NULL): array {
    $query = $this->database->select(self::TABLE, 'm')
      ->fields('m', ['stage', 'old_nid', 'old_bundle', 'old_uuid', 'new_nid', 'new_uuid', 'created'])
      ->orderBy('id');
    if ($stage) {
      $query->condition('stage', $stage->value);
    }

    return array_map(fn ($row) => (array) $row, $query->execute()->fetchAll());
  }

  /**
   * Counts the recorded pairs.
   *
   * @param \Drupal\however_customizations\MigrationStage|null $stage
   *   Restrict the count to one stage, or NULL for all stages.
   *
   * @return int
   *   The number of recorded pairs.
   */
  public function count(?MigrationStage $stage = NULL): int {
    $query = $this->database->select(self::TABLE, 'm');
    if ($stage) {
      $query->condition('stage', $stage->value);
    }

    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Removes the unified nodes recorded for a stage.
   *
   * Only unified nodes listed in the map are deleted; legacy nodes are never
   * deleted. Their aliases are moved back out from under /old (for the issue
   * stage, also the aliases of the legacy facsimile pages); a legacy node whose
   * aliases were never moved is left as it is. A stage cannot be rolled back while a later stage still has
   * recorded pairs, because those nodes reference this stage's nodes.
   *
   * @param \Drupal\however_customizations\MigrationStage $stage
   *   The stage to roll back.
   * @param bool $apply
   *   TRUE to delete the nodes and their map rows; FALSE to only report what
   *   would be done.
   *
   * @return array
   *   Counts keyed by 'mapped' (recorded pairs), 'deleted' (unified nodes
   *   deleted), and 'already_gone' (recorded unified nodes that no longer
   *   exist). 'deleted' stays 0 unless $apply is TRUE.
   *
   * @throws \RuntimeException
   *   If a later stage still has recorded pairs.
   */
  public function rollback(MigrationStage $stage, bool $apply = FALSE): array {
    foreach ($stage->laterStages() as $later_stage) {
      if ($this->count($later_stage) > 0) {
        throw new \RuntimeException(sprintf('Cannot roll back the %s stage while the %s stage still has recorded nodes. Roll back the %s stage first.', $stage->value, $later_stage->value, $later_stage->value));
      }
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $rows = $this->getRows($stage);
    $result = ['mapped' => count($rows), 'deleted' => 0, 'already_gone' => 0];

    $transaction = $apply ? $this->database->startTransaction() : NULL;
    try {
      foreach ($rows as $row) {
        $node = $storage->load($row['new_nid']);
        if (!$node) {
          $result['already_gone']++;
        }
        elseif ($apply) {
          $node->delete();
          $result['deleted']++;
        }

        if ($apply) {
          $this->aliasSwapper->restore($row['old_nid']);
          if ($stage === MigrationStage::Issue) {
            $this->aliasSwapper->restoreFacsimiles($row['old_nid']);
          }
          $this->database->delete(self::TABLE)
            ->condition('old_nid', $row['old_nid'])
            ->execute();
        }
      }
    }
    catch (\Throwable $e) {
      $transaction?->rollBack();
      throw $e;
    }

    return $result;
  }

}
