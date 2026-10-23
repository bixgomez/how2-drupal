<?php

namespace Drupal\however_customizations\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\however_customizations\MigrationStage;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Creates unified nodes from legacy nodes, one migration stage at a time.
 *
 * Legacy nodes are only read. Each unified node is created together with its
 * map row (and, for issues, its facsimiles node) in one transaction, and a legacy node that is already mapped is
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
   *   action, old_nid, bundle, title, new_nid, and note) and 'counts'
   *   (created, would_create, skipped, and blocked). A node is blocked when a
   *   parent it refers to is not migrated yet; it is never created.
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
      'counts' => ['created' => 0, 'would_create' => 0, 'skipped' => 0, 'blocked' => 0],
    ];
    $processed = 0;

    foreach ($storage->loadMultiple($nids) as $legacy) {
      $row = [
        'old_nid' => (int) $legacy->id(),
        'bundle' => $legacy->bundle(),
        'title' => $legacy->getTitle(),
        'new_nid' => $this->map->getNewNid($legacy->id()),
        'note' => $this->describe($stage, $legacy),
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

      $problem = $this->findProblem($legacy);
      if ($problem) {
        $row['note'] = $problem;
        $result['rows'][] = ['action' => 'blocked'] + $row;
        $result['counts']['blocked']++;
        continue;
      }

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
   * Describes what is special about a legacy node for the report.
   *
   * @param \Drupal\however_customizations\MigrationStage $stage
   *   The migration stage.
   * @param \Drupal\node\NodeInterface $legacy
   *   The legacy node.
   *
   * @return string
   *   A short note, or an empty string.
   */
  protected function describe(MigrationStage $stage, NodeInterface $legacy): string {
    if ($stage === MigrationStage::Issue && $this->findFacsimileIds((int) $legacy->id())) {
      return 'has a facsimile page';
    }
    if ($stage === MigrationStage::Section) {
      $pieces = 0;
      foreach ($this->bundleConfig->getMigrationPieceMap($legacy->bundle()) as $legacy_field => $unified_field) {
        $pieces += count($legacy->get($legacy_field));
      }
      return $pieces ? sprintf('%d %s', $pieces, $pieces === 1 ? 'piece' : 'pieces') : '';
    }
    return '';
  }

  /**
   * Finds a reason why a legacy node cannot be migrated yet.
   *
   * @param \Drupal\node\NodeInterface $legacy
   *   The legacy node.
   *
   * @return string|null
   *   A description of the first parent that is not migrated, or NULL.
   */
  protected function findProblem(NodeInterface $legacy): ?string {
    foreach ($this->bundleConfig->getMigrationReferenceMap($legacy->bundle()) as $legacy_field => $target) {
      foreach ($legacy->get($legacy_field)->getValue() as $item) {
        if (!$this->map->isMapped((int) $item['target_id'])) {
          return sprintf('%s refers to node %d, which is not migrated (%s stage).', $legacy_field, $item['target_id'], $target['stage']->value);
        }
      }
    }
    return NULL;
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
      if ($stage === MigrationStage::Issue) {
        $this->copyFacsimiles($legacy, $unified);
      }
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
   * language, and the fields in the legacy bundle's field map. Node references
   * are replaced by the migrated parents, paragraphs are duplicated, and the
   * journal and its machine name are set from the legacy bundle name. For the
   * stages that keep their legacy titles the presave hook is skipped.
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

    foreach ($this->bundleConfig->getMigrationReferenceMap($legacy->bundle()) as $legacy_field => $target) {
      if (!$unified->hasField($target['field'])) {
        throw new \LogicException(sprintf('The %s bundle has no %s field.', $stage->value, $target['field']));
      }
      $values = [];
      foreach ($legacy->get($legacy_field)->getValue() as $item) {
        $values[] = ['target_id' => $this->map->getNewNid((int) $item['target_id'])];
      }
      $unified->set($target['field'], $values);
    }

    foreach ($this->bundleConfig->getMigrationParagraphFields($legacy->bundle()) as $legacy_field => $unified_field) {
      if (!$unified->hasField($unified_field)) {
        throw new \LogicException(sprintf('The %s bundle has no %s field.', $stage->value, $unified_field));
      }
      $copies = [];
      foreach ($legacy->get($legacy_field)->referencedEntities() as $paragraph) {
        $copies[] = $paragraph->createDuplicate();
      }
      $unified->set($unified_field, $copies);
    }

    foreach ($this->bundleConfig->getMigrationPieceMap($legacy->bundle()) as $legacy_field => $unified_field) {
      if (!$unified->hasField($unified_field)) {
        throw new \LogicException(sprintf('The %s bundle has no %s field.', $stage->value, $unified_field));
      }
      $unified->set($unified_field, $this->convertPieces($legacy, $legacy_field));
    }

    if ($unified->hasField('field_journal')) {
      $unified->set('field_journal', $this->getJournalId($legacy->bundle()));
    }
    if ($unified->hasField('field_journal_name') && $unified->get('field_journal_name')->isEmpty()) {
      $unified->set('field_journal_name', $this->bundleConfig->getJournalFromBundle($legacy->bundle()));
    }

    if ($this->bundleConfig->skipsPresaveHook($stage)) {
      $unified->however_skip_presave = TRUE;
    }

    return $unified;
  }

  /**
   * Converts the piece paragraphs of a legacy node to unified paragraphs.
   *
   * Each piece becomes a new paragraph of the unified type, in the same order.
   * Values that refer to articles or sections are left empty (see
   * BundleConfiguration::getMigrationPieceTypes()).
   *
   * @param \Drupal\node\NodeInterface $legacy
   *   The legacy node.
   * @param string $legacy_field
   *   The legacy piece field.
   *
   * @return \Drupal\paragraphs\Entity\Paragraph[]
   *   The new, unsaved paragraphs.
   *
   * @throws \LogicException
   *   If a piece has a paragraph type that has no conversion.
   */
  protected function convertPieces(NodeInterface $legacy, string $legacy_field): array {
    $types = $this->bundleConfig->getMigrationPieceTypes();
    $converted = [];
    foreach ($legacy->get($legacy_field)->referencedEntities() as $piece) {
      if (!isset($types[$piece->bundle()])) {
        throw new \LogicException(sprintf('Node %d has a %s paragraph, which has no conversion.', $legacy->id(), $piece->bundle()));
      }
      $definition = $types[$piece->bundle()];
      $copy = Paragraph::create(['type' => $definition['type']]);
      foreach ($definition['fields'] as $from => $to) {
        if (!$piece->get($from)->isEmpty()) {
          $copy->set($to, $piece->get($from)->getValue());
        }
      }
      $converted[] = $copy;
    }
    return $converted;
  }

  /**
   * Gives a migrated issue the facsimiles node its legacy issue has.
   *
   * Saving an issue with page images already creates a facsimiles node (the
   * node insert hook). If the unified issue has none after that, each
   * facsimiles node of the legacy issue is copied and points at the unified
   * issue. The legacy facsimiles are only read. Deleting the unified issue
   * deletes the copies, so rolling back the issue stage removes them.
   *
   * @param \Drupal\node\NodeInterface $legacy
   *   The legacy issue.
   * @param \Drupal\node\NodeInterface $unified
   *   The saved unified issue.
   */
  protected function copyFacsimiles(NodeInterface $legacy, NodeInterface $unified): void {
    $legacy_ids = $this->findFacsimileIds((int) $legacy->id());
    if (!$legacy_ids || $this->findFacsimileIds((int) $unified->id())) {
      return;
    }

    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($legacy_ids) as $facsimile) {
      $copy = Node::create([
        'type' => 'page_facsimiles',
        'title' => $facsimile->getTitle(),
        'status' => $facsimile->isPublished(),
        'uid' => $facsimile->getOwnerId(),
        'created' => $facsimile->getCreatedTime(),
        'langcode' => $facsimile->language()->getId(),
        'field_issue_reference' => $unified->id(),
      ]);
      foreach (['body', 'field_volume_number', 'field_issue_number', 'field_journal_name'] as $field) {
        if ($copy->hasField($field) && !$facsimile->get($field)->isEmpty()) {
          $copy->set($field, $facsimile->get($field)->getValue());
        }
      }
      $copy->save();
    }
  }

  /**
   * Finds the facsimiles nodes that point at an issue.
   *
   * @param int $issue_nid
   *   The issue node ID.
   *
   * @return int[]
   *   The facsimiles node IDs, lowest first.
   */
  protected function findFacsimileIds(int $issue_nid): array {
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'page_facsimiles')
      ->condition('field_issue_reference', $issue_nid)
      ->sort('nid')
      ->execute();
    return array_values(array_map('intval', $ids));
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
