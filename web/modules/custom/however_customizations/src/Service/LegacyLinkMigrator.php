<?php

namespace Drupal\however_customizations\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\entity_reference_revisions\EntityNeedsSaveInterface;
use Drupal\however_customizations\MigrationStage;
use Drupal\node\NodeInterface;

/**
 * Fills the references between the unified nodes, after the four stages.
 *
 * The stages leave the forward references empty: the issues of a volume, the
 * sections of an issue, and the article and subsection references in the
 * pieces of a section. This copies what the legacy page shows, translated
 * through the migration map: each legacy list is copied in order, and each
 * piece reference is copied from the legacy piece at the same position.
 * Entries that point at deleted nodes and repeated entries are dropped and
 * reported. Legacy nodes are only read. Running it again changes only what is
 * not yet linked.
 */
class LegacyLinkMigrator {

  /**
   * The stages whose unified nodes get references, in order.
   *
   * @var \Drupal\however_customizations\MigrationStage[]
   */
  protected const LINKED_STAGES = [MigrationStage::Volume, MigrationStage::Issue, MigrationStage::Section];

  /**
   * Constructs a LegacyLinkMigrator object.
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
   * Fills the references of the migrated volumes, issues, and sections.
   *
   * @param bool $apply
   *   TRUE to save the references; FALSE to only report what would be done.
   * @param int|null $limit
   *   The most nodes to change, or NULL for no limit.
   *
   * @return array
   *   An array with 'rows' (one per node that was or would be changed, or is
   *   blocked, each with action, stage, old_nid, title, new_nid, and note) and
   *   'counts' (linked, would_link, skipped, and blocked). A node is blocked
   *   when a node it refers to exists but is not migrated.
   */
  public function link(bool $apply = FALSE, ?int $limit = NULL): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $result = [
      'rows' => [],
      'counts' => ['linked' => 0, 'would_link' => 0, 'skipped' => 0, 'blocked' => 0],
    ];
    $processed = 0;

    foreach (self::LINKED_STAGES as $stage) {
      foreach ($this->map->getRows($stage) as $map_row) {
        $legacy = $storage->load($map_row['old_nid']);
        $unified = $storage->load($map_row['new_nid']);
        $row = [
          'stage' => $stage->value,
          'old_nid' => (int) $map_row['old_nid'],
          'title' => $unified ? $unified->getTitle() : '',
          'new_nid' => (int) $map_row['new_nid'],
        ];

        if (!$legacy || !$unified) {
          $result['rows'][] = ['action' => 'blocked', 'note' => 'a node of the pair no longer exists'] + $row;
          $result['counts']['blocked']++;
          continue;
        }

        $plan = $stage === MigrationStage::Section ? $this->planPieces($legacy, $unified) : $this->planList($stage, $legacy, $unified);
        if ($plan['problem']) {
          $result['rows'][] = ['action' => 'blocked', 'note' => $plan['problem']] + $row;
          $result['counts']['blocked']++;
          continue;
        }
        if (!$plan['changes']) {
          $result['counts']['skipped']++;
          if ($plan['notes']) {
            $result['rows'][] = ['action' => 'skipped', 'note' => implode('; ', $plan['notes'])] + $row;
          }
          continue;
        }

        if ($limit !== NULL && $processed >= $limit) {
          break 2;
        }
        $processed++;

        if ($apply) {
          $this->applyPlan($stage, $unified, $plan);
          $result['counts']['linked']++;
        }
        else {
          $result['counts']['would_link']++;
        }
        $result['rows'][] = ['action' => $apply ? 'linked' : 'would_link', 'note' => implode('; ', array_merge([$plan['summary']], $plan['notes']))] + $row;
      }
    }

    return $result;
  }

  /**
   * Clears the references the links pass fills.
   *
   * Only the unified nodes in the migration map are touched. The fields are
   * empty before the links pass, so this restores that state.
   *
   * @param bool $apply
   *   TRUE to clear the references; FALSE to only count them.
   *
   * @return array
   *   Counts keyed by 'nodes' (unified nodes that have references) and
   *   'references' (the references in them).
   */
  public function unlink(bool $apply = FALSE): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $result = ['nodes' => 0, 'references' => 0];

    foreach (self::LINKED_STAGES as $stage) {
      foreach ($this->map->getRows($stage) as $map_row) {
        $unified = $storage->load($map_row['new_nid']);
        $legacy = $storage->load($map_row['old_nid']);
        if (!$unified || !$legacy) {
          continue;
        }

        $changes = [];
        $references = 0;
        if ($stage === MigrationStage::Section) {
          $pieces = $unified->hasField('field_piece') ? $unified->get('field_piece')->referencedEntities() : [];
          foreach ($pieces as $position => $piece) {
            foreach (['field_unit', 'field_subsection'] as $field) {
              if ($piece->hasField($field) && !$piece->get($field)->isEmpty()) {
                $changes[] = ['position' => $position, 'field' => $field, 'values' => NULL];
                $references++;
              }
            }
          }
        }
        else {
          foreach ($this->bundleConfig->getLinkListFields($legacy->bundle()) as $unified_field) {
            $count = count($unified->get($unified_field));
            if ($count) {
              $changes[] = ['position' => NULL, 'field' => $unified_field, 'values' => []];
              $references += $count;
            }
          }
        }

        if (!$changes) {
          continue;
        }
        $result['nodes']++;
        $result['references'] += $references;
        if ($apply) {
          $this->applyPlan($stage, $unified, ['changes' => $changes]);
        }
      }
    }

    return $result;
  }

  /**
   * Works out the list a unified volume or issue should have.
   *
   * @param \Drupal\however_customizations\MigrationStage $stage
   *   The stage of the node (volume or issue).
   * @param \Drupal\node\NodeInterface $legacy
   *   The legacy volume or issue.
   * @param \Drupal\node\NodeInterface $unified
   *   The unified volume or issue.
   *
   * @return array
   *   A plan: 'changes' (each with field, position NULL, and the new values),
   *   'summary', 'notes', and 'problem' (NULL, or why the node cannot be
   *   linked yet).
   */
  protected function planList(MigrationStage $stage, NodeInterface $legacy, NodeInterface $unified): array {
    $plan = ['changes' => [], 'summary' => '', 'notes' => [], 'problem' => NULL];
    $total = 0;

    foreach ($this->bundleConfig->getLinkListFields($legacy->bundle()) as $legacy_field => $unified_field) {
      $desired = [];
      $seen = [];
      foreach ($legacy->get($legacy_field)->getValue() as $item) {
        $old_nid = (int) $item['target_id'];
        if (!$this->nodeExists($old_nid)) {
          $plan['notes'][] = sprintf('drops deleted node %d', $old_nid);
          continue;
        }
        if (isset($seen[$old_nid])) {
          $plan['notes'][] = sprintf('drops repeated node %d', $old_nid);
          continue;
        }
        $seen[$old_nid] = TRUE;

        $new_nid = $this->map->getNewNid($old_nid);
        if ($new_nid === NULL) {
          $plan['problem'] = sprintf('%s refers to node %d, which is not migrated.', $legacy_field, $old_nid);
          continue;
        }
        if ($stage === MigrationStage::Issue) {
          $entry = $this->entityTypeManager->getStorage('node')->load($old_nid);
          $belongs_to = $entry && $entry->hasField('field_issue_reference') ? (int) $entry->get('field_issue_reference')->target_id : 0;
          if ($belongs_to && $belongs_to !== (int) $legacy->id()) {
            $plan['notes'][] = sprintf('keeps node %d, which belongs to issue %d', $old_nid, $belongs_to);
          }
        }
        $desired[] = $new_nid;
      }

      $current = array_map('intval', array_column($unified->get($unified_field)->getValue(), 'target_id'));
      $total += count($desired);
      if ($desired !== $current) {
        $plan['changes'][] = ['position' => NULL, 'field' => $unified_field, 'values' => $desired];
      }
    }

    $plan['summary'] = sprintf('%d %s', $total, $total === 1 ? 'entry' : 'entries');
    return $plan;
  }

  /**
   * Works out the article and subsection references of a unified section.
   *
   * Each piece of the legacy section is paired with the unified piece at the
   * same position, which the section stage created in the same order.
   *
   * @param \Drupal\node\NodeInterface $legacy
   *   The legacy section.
   * @param \Drupal\node\NodeInterface $unified
   *   The unified section.
   *
   * @return array
   *   A plan, as for planList(); each change has the piece position, the
   *   unified field, and the node ID to set (NULL to leave it empty).
   */
  protected function planPieces(NodeInterface $legacy, NodeInterface $unified): array {
    $plan = ['changes' => [], 'summary' => '', 'notes' => [], 'problem' => NULL];
    $piece_fields = $this->bundleConfig->getMigrationPieceMap($legacy->bundle());
    if (!$piece_fields) {
      return $plan;
    }

    $legacy_pieces = $legacy->get(array_key_first($piece_fields))->referencedEntities();
    $unified_pieces = $unified->get('field_piece')->referencedEntities();
    if (count($legacy_pieces) !== count($unified_pieces)) {
      $plan['problem'] = sprintf('the section has %d pieces and the unified section %d.', count($legacy_pieces), count($unified_pieces));
      return $plan;
    }

    $link_fields = $this->bundleConfig->getLinkPieceFields();
    $links = 0;
    $subsections = 0;
    $empty = 0;
    foreach ($legacy_pieces as $position => $piece) {
      if (!isset($link_fields[$piece->bundle()])) {
        continue;
      }
      [$legacy_field, $unified_field] = $link_fields[$piece->bundle()];
      $twin = $unified_pieces[$position];
      if (!$twin->hasField($unified_field)) {
        $plan['problem'] = sprintf('the unified piece %d has no %s field.', $position, $unified_field);
        return $plan;
      }

      $old_nid = (int) $piece->get($legacy_field)->target_id;
      $desired = NULL;
      if (!$old_nid) {
        $empty++;
      }
      elseif (!$this->nodeExists($old_nid)) {
        $plan['notes'][] = sprintf('drops deleted node %d (piece %d)', $old_nid, $position);
      }
      else {
        $desired = $this->map->getNewNid($old_nid);
        if ($desired === NULL) {
          $plan['problem'] = sprintf('piece %d refers to node %d, which is not migrated.', $position, $old_nid);
          return $plan;
        }
        $unified_field === 'field_subsection' ? $subsections++ : $links++;
      }

      $current = $twin->get($unified_field)->target_id;
      $current = $current ? (int) $current : NULL;
      if ($desired !== $current) {
        $plan['changes'][] = ['position' => $position, 'field' => $unified_field, 'values' => $desired];
      }
    }

    if ($empty) {
      $plan['notes'][] = sprintf('%d empty %s in the legacy data', $empty, $empty === 1 ? 'reference' : 'references');
    }
    $plan['summary'] = sprintf('%d %s, %d %s', $links, $links === 1 ? 'article link' : 'article links', $subsections, $subsections === 1 ? 'subsection' : 'subsections');
    return $plan;
  }

  /**
   * Saves the changes of a plan on a unified node, in one transaction.
   *
   * @param \Drupal\however_customizations\MigrationStage $stage
   *   The stage of the node.
   * @param \Drupal\node\NodeInterface $unified
   *   The unified node.
   * @param array $plan
   *   A plan with 'changes'.
   */
  protected function applyPlan(MigrationStage $stage, NodeInterface $unified, array $plan): void {
    $transaction = $this->database->startTransaction();
    try {
      $pieces = NULL;
      foreach ($plan['changes'] as $change) {
        if ($change['position'] === NULL) {
          $values = array_map(fn ($nid) => ['target_id' => $nid], $change['values']);
          $unified->set($change['field'], $values);
          continue;
        }

        // A paragraph is saved with its host when it is marked as changed.
        $pieces ??= $unified->get('field_piece')->referencedEntities();
        $piece = $pieces[$change['position']];
        $piece->set($change['field'], $change['values']);
        if ($piece instanceof EntityNeedsSaveInterface) {
          $piece->setNeedsSave(TRUE);
        }
      }

      if ($this->bundleConfig->skipsPresaveHook($stage)) {
        $unified->however_skip_presave = TRUE;
      }
      $unified->save();
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Checks whether a node still exists.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return bool
   *   TRUE if the node exists.
   */
  protected function nodeExists(int $nid): bool {
    return (bool) $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('nid', $nid)
      ->range(0, 1)
      ->execute();
  }

}
