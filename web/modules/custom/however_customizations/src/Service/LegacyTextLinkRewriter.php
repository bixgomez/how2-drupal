<?php

namespace Drupal\however_customizations\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\entity_reference_revisions\EntityNeedsSaveInterface;
use Drupal\however_customizations\MigrationStage;
use Drupal\node\NodeInterface;

/**
 * Points the node links inside the text of unified nodes at the unified nodes.
 *
 * Editors typed links such as <a href="/node/317"> into text fields, and the
 * stages copy that text as it is, so the links still lead to the legacy nodes.
 * This rewrites them, on the unified nodes and their paragraphs only, to the
 * unified twin recorded in the migration map. Only local links of the exact
 * form /node/N (with an optional query or fragment) are touched: links to other
 * sites, other paths such as /node/N/edit, and nodes that are not mapped are
 * left as they are, and the unmapped ones are reported. Legacy nodes are never
 * changed, and the rewrite can be reversed.
 */
class LegacyTextLinkRewriter {

  /**
   * The field types whose text can hold links.
   */
  protected const TEXT_TYPES = ['text', 'text_long', 'text_with_summary'];

  /**
   * Matches the start of a local node link in an href attribute.
   */
  protected const LINK_PATTERN = '~(href\s*=\s*["\'])/node/(\d+)(?=["\'#?])~i';

  /**
   * Constructs a LegacyTextLinkRewriter object.
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
   * Rewrites the node links in the text of the unified nodes.
   *
   * @param bool $apply
   *   TRUE to save the changes; FALSE to only report them.
   * @param bool $reverse
   *   TRUE to point the links back at the legacy nodes.
   *
   * @return array
   *   An array with 'nodes' (unified nodes changed or that would be), 'links'
   *   (links rewritten or that would be), 'fields' (links by "entity type
   *   bundle field"), and 'unmapped' (links left alone because the node they
   *   point at is not mapped, each with the unified nid, the field, and the
   *   linked nid).
   */
  public function rewrite(bool $apply = FALSE, bool $reverse = FALSE): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $result = ['nodes' => 0, 'links' => 0, 'fields' => [], 'unmapped' => []];

    foreach ($this->map->getRows() as $map_row) {
      $unified = $storage->load($map_row['new_nid']);
      if (!$unified) {
        continue;
      }

      $changed_paragraphs = [];
      $node_links = 0;
      foreach ($this->entitiesOf($unified) as $entity) {
        $links = $this->rewriteEntity($entity, $apply, $reverse, $result, (int) $unified->id());
        if (!$links) {
          continue;
        }
        $node_links += $links;
        if ($entity !== $unified) {
          $changed_paragraphs[] = $entity;
        }
      }

      if (!$node_links) {
        continue;
      }
      $result['nodes']++;
      $result['links'] += $node_links;
      if ($apply) {
        $this->save($unified, MigrationStage::from($map_row['stage']), $changed_paragraphs);
      }
      $storage->resetCache([$unified->id()]);
    }

    return $result;
  }

  /**
   * Gets a node and the paragraphs it holds, directly or nested.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The unified node.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   The node first, then its paragraphs.
   */
  protected function entitiesOf(NodeInterface $node): array {
    $found = [$node];
    $queue = [$node];
    while ($queue) {
      $entity = array_shift($queue);
      foreach ($entity->getFieldDefinitions() as $name => $definition) {
        if ($definition->getType() !== 'entity_reference_revisions' || $definition->getSetting('target_type') !== 'paragraph') {
          continue;
        }
        foreach ($entity->get($name)->referencedEntities() as $paragraph) {
          $found[] = $paragraph;
          $queue[] = $paragraph;
        }
      }
    }
    return $found;
  }

  /**
   * Rewrites the links in the text fields of one entity, in memory.
   *
   * The entity is only changed when $apply is TRUE: entities are cached, and a
   * dry run must not leave changed copies behind.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The node or paragraph.
   * @param bool $apply
   *   TRUE to set the new text on the entity.
   * @param bool $reverse
   *   TRUE to point the links back at the legacy nodes.
   * @param array $result
   *   The result being built; its fields and unmapped lists are added to.
   * @param int $unified_nid
   *   The unified node the entity belongs to.
   *
   * @return int
   *   The number of links rewritten.
   */
  protected function rewriteEntity(ContentEntityInterface $entity, bool $apply, bool $reverse, array &$result, int $unified_nid): int {
    $total = 0;
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if (!in_array($definition->getType(), self::TEXT_TYPES, TRUE) || $definition->isComputed()) {
        continue;
      }
      $label = sprintf('%s %s %s', $entity->getEntityTypeId(), $entity->bundle(), $name);
      $values = $entity->get($name)->getValue();
      $touched = FALSE;
      foreach ($values as $delta => $item) {
        foreach (['value', 'summary'] as $property) {
          if (empty($item[$property]) || !is_string($item[$property])) {
            continue;
          }
          $count = 0;
          $values[$delta][$property] = preg_replace_callback(self::LINK_PATTERN, function (array $match) use ($reverse, $label, $unified_nid, &$count, &$result) {
            $nid = (int) $match[2];
            $target = $reverse ? $this->map->getOldNid($nid) : $this->map->getNewNid($nid);
            if ($target === NULL) {
              // A link to a unified node is already rewritten, not unmapped.
              if (!$reverse && $this->map->getOldNid($nid) === NULL) {
                $result['unmapped'][] = ['unified_nid' => $unified_nid, 'field' => $label, 'nid' => $nid];
              }
              return $match[0];
            }
            $count++;
            return $match[1] . '/node/' . $target;
          }, $item[$property]);
          if ($count) {
            $touched = TRUE;
            $total += $count;
            $result['fields'][$label] = ($result['fields'][$label] ?? 0) + $count;
          }
        }
      }
      if ($touched && $apply) {
        $entity->set($name, $values);
      }
    }
    return $total;
  }

  /**
   * Saves a unified node, with the paragraphs that changed, in a transaction.
   *
   * @param \Drupal\node\NodeInterface $unified
   *   The unified node.
   * @param \Drupal\however_customizations\MigrationStage $stage
   *   The stage of the node.
   * @param \Drupal\Core\Entity\ContentEntityInterface[] $paragraphs
   *   The paragraphs that were changed in memory.
   */
  protected function save(NodeInterface $unified, MigrationStage $stage, array $paragraphs): void {
    $transaction = $this->database->startTransaction();
    try {
      foreach ($paragraphs as $paragraph) {
        if ($paragraph instanceof EntityNeedsSaveInterface) {
          $paragraph->setNeedsSave(TRUE);
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

}
