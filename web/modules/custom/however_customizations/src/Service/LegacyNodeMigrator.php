<?php

namespace Drupal\however_customizations\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\however_customizations\MigrationStage;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

/**
 * Creates unified nodes from legacy nodes, one migration stage at a time.
 *
 * Legacy nodes are only read. Each unified node is created together with its
 * map row in one transaction, and a legacy node that is already mapped is
 * skipped, so a stage can be run again after a failure or a partial run.
 */
class LegacyNodeMigrator {

  /**
   * Journal node IDs by machine name.
   *
   * @var int[]
   */
  protected array $journalIds = [];

  /**
   * Constructs a LegacyNodeMigrator object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\however_customizations\Service\BundleConfiguration $bundleConfig
   *   The bundle configuration service.
   * @param \Drupal\however_customizations\Service\MigrationMap $map
   *   The migration map.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected BundleConfiguration $bundleConfig,
    protected MigrationMap $map,
  ) {}

  /**
   * Migrates the legacy nodes of a stage that are not yet migrated.
   *
   * @param \Drupal\however_customizations\MigrationStage $stage
   *   The migration stage.
   * @param bool $apply
   *   TRUE to create the unified nodes; FALSE to only report what would be
   *   created.
   * @param int|null $limit
   *   The most legacy nodes to process, not counting nodes that are already
   *   migrated; NULL for no limit.
   *
   * @return array
   *   An array with 'rows' (one entry per legacy node visited, each with
   *   action, old_nid, bundle, title, and new_nid) and 'counts' (created,
   *   would_create, and skipped).
   *
   * @throws \LogicException
   *   If the stage has no field map for one of its legacy bundles.
   * @throws \RuntimeException
   *   If a journal node cannot be found.
   */
  public function migrate(MigrationStage $stage, bool $apply = FALSE, ?int $limit = NULL): array {
    $legacy_bundles = $this->bundleConfig->getLegacyBundles($stage);
    foreach ($legacy_bundles as $legacy_bundle) {
      if (!$this->bundleConfig->getMigrationFieldMap($legacy_bundle)) {
        throw new \LogicException(sprintf('The %s stage cannot be migrated yet: no field map is defined for %s.', $stage->value, $legacy_bundle));
      }
      $this->getJournalId($legacy_bundle);
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $legacy_bundles, 'IN')
      ->sort('nid')
      ->execute();

    $result = [
      'rows' => [],
      'counts' => ['created' => 0, 'would_create' => 0, 'skipped' => 0],
    ];
    $processed = 0;

    foreach ($storage->loadMultiple($nids) as $legacy) {
      $row = [
        'old_nid' => (int) $legacy->id(),
        'bundle' => $legacy->bundle(),
        'title' => $legacy->getTitle(),
        'new_nid' => $this->map->getNewNid($legacy->id()),
      ];

      if ($row['new_nid'] !== NULL) {
        $result['rows'][] = ['action' => 'skipped'] + $row;
        $result['counts']['skipped']++;
        continue;
      }

      if ($limit !== NULL && $processed >= $limit) {
        break;
      }
      $processed++;

      if ($apply) {
        $row['new_nid'] = (int) $this->migrateNode($stage, $legacy)->id();
        $result['rows'][] = ['action' => 'created'] + $row;
        $result['counts']['created']++;
      }
      else {
        $result['rows'][] = ['action' => 'would_create'] + $row;
        $result['counts']['would_create']++;
      }
    }

    return $result;
  }

  /**
   * Creates the unified node for a legacy node and records the pair.
   *
   * @param \Drupal\however_customizations\MigrationStage $stage
   *   The migration stage.
   * @param \Drupal\node\NodeInterface $legacy
   *   The legacy node.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved unified node.
   */
  protected function migrateNode(MigrationStage $stage, NodeInterface $legacy): NodeInterface {
    $transaction = $this->database->startTransaction();
    try {
      $unified = $this->buildUnifiedNode($stage, $legacy);
      $unified->save();
      $this->map->record($stage, $legacy, $unified);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }

    return $unified;
  }

  /**
   * Builds the unified node for a legacy node without saving it.
   *
   * Copies the title, status, owner, created time, promote and sticky flags,
   * language, the fields in the legacy bundle's field map, and sets the
   * journal from the legacy bundle name.
   *
   * @param \Drupal\however_customizations\MigrationStage $stage
   *   The migration stage.
   * @param \Drupal\node\NodeInterface $legacy
   *   The legacy node.
   *
   * @return \Drupal\node\NodeInterface
   *   The unsaved unified node.
   */
  protected function buildUnifiedNode(MigrationStage $stage, NodeInterface $legacy): NodeInterface {
    $unified = Node::create([
      'type' => $stage->value,
      'title' => $legacy->getTitle(),
      'status' => $legacy->isPublished(),
      'uid' => $legacy->getOwnerId(),
      'created' => $legacy->getCreatedTime(),
      'promote' => $legacy->isPromoted(),
      'sticky' => $legacy->isSticky(),
      'langcode' => $legacy->language()->getId(),
    ]);

    foreach ($this->bundleConfig->getMigrationFieldMap($legacy->bundle()) as $legacy_field => $unified_field) {
      if (!$unified->hasField($unified_field)) {
        throw new \LogicException(sprintf('The %s bundle has no %s field.', $stage->value, $unified_field));
      }
      if (!$legacy->get($legacy_field)->isEmpty()) {
        $unified->set($unified_field, $legacy->get($legacy_field)->getValue());
      }
    }

    if ($unified->hasField('field_journal')) {
      $unified->set('field_journal', $this->getJournalId($legacy->bundle()));
    }

    return $unified;
  }

  /**
   * Gets the journal node ID for a legacy bundle.
   *
   * @param string $legacy_bundle
   *   The legacy bundle machine name.
   *
   * @return int
   *   The ID of the journal node whose field_journal_name matches the bundle.
   *
   * @throws \RuntimeException
   *   If the bundle has no journal or no journal node matches.
   */
  protected function getJournalId(string $legacy_bundle): int {
    $journal_name = $this->bundleConfig->getJournalFromBundle($legacy_bundle);
    if (!$journal_name) {
      throw new \RuntimeException(sprintf('The journal for the %s bundle cannot be determined.', $legacy_bundle));
    }

    if (!isset($this->journalIds[$journal_name])) {
      $ids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'journal')
        ->condition('field_journal_name', $journal_name)
        ->range(0, 1)
        ->execute();
      if (!$ids) {
        throw new \RuntimeException(sprintf('No journal node has the machine name %s.', $journal_name));
      }
      $this->journalIds[$journal_name] = (int) reset($ids);
    }

    return $this->journalIds[$journal_name];
  }

}
