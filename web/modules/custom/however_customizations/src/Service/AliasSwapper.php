<?php

namespace Drupal\however_customizations\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\node\NodeInterface;
use Drupal\pathauto\PathautoGeneratorInterface;

/**
 * Hands the URL aliases of a legacy node over to its unified node.
 *
 * The aliases of the legacy node are moved under /old (the string is kept, so
 * suffixes such as -0 and hand-edited aliases survive), and the unified node
 * gets the original alias strings. The public URLs therefore stay the same
 * while the legacy pages remain reachable under /old. Restoring strips the
 * prefix again.
 */
class AliasSwapper {

  /**
   * The prefix the legacy aliases are moved under.
   */
  const PREFIX = '/old';

  /**
   * Pathauto state: do not regenerate the alias.
   */
  const STATE_SKIP = 0;

  /**
   * Pathauto state: regenerate the alias from the pattern on save.
   */
  const STATE_CREATE = 1;

  /**
   * Constructs an AliasSwapper object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyValue
   *   The key value factory, where pathauto keeps its per-node state.
   * @param \Drupal\pathauto\PathautoGeneratorInterface|null $generator
   *   The pathauto generator, when the pathauto module is installed.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected KeyValueFactoryInterface $keyValue,
    protected ?PathautoGeneratorInterface $generator = NULL,
  ) {}

  /**
   * Moves the aliases of a legacy node under the /old prefix.
   *
   * @param int|string $nid
   *   The legacy node ID.
   *
   * @return array
   *   The original aliases, each as ['alias' => string, 'langcode' => string].
   *   An alias that is already under /old is not touched and not returned.
   */
  public function detach(int|string $nid): array {
    $originals = [];
    foreach ($this->loadAliases($nid) as $row) {
      $alias = $row->getAlias();
      if (str_starts_with($alias, self::PREFIX . '/')) {
        continue;
      }
      $originals[] = ['alias' => $alias, 'langcode' => $row->language()->getId()];
      $row->setAlias(self::PREFIX . $alias)->save();
    }
    return $originals;
  }

  /**
   * Moves the aliases of a legacy node back out from under /old.
   *
   * Aliases that are not under /old, for example those of a node that was never
   * swapped, are left alone.
   *
   * @param int|string $nid
   *   The legacy node ID.
   *
   * @return int
   *   The number of aliases restored.
   */
  public function restore(int|string $nid): int {
    $restored = 0;
    foreach ($this->loadAliases($nid) as $row) {
      $alias = $row->getAlias();
      if (str_starts_with($alias, self::PREFIX . '/')) {
        $row->setAlias(substr($alias, strlen(self::PREFIX)))->save();
        $restored++;
      }
    }
    return $restored;
  }

  /**
   * Moves the aliases of the facsimile pages of a legacy issue back.
   *
   * @param int|string $issue_nid
   *   The legacy issue node ID.
   *
   * @return int
   *   The number of aliases restored.
   */
  public function restoreFacsimiles(int|string $issue_nid): int {
    $restored = 0;
    foreach ($this->findFacsimileIds((int) $issue_nid) as $nid) {
      $restored += $this->restore($nid);
    }
    return $restored;
  }

  /**
   * Finds the facsimile pages that point at an issue.
   *
   * @param int $issue_nid
   *   The issue node ID.
   *
   * @return int[]
   *   The facsimile page node IDs, lowest first.
   */
  public function findFacsimileIds(int $issue_nid): array {
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'page_facsimiles')
      ->condition('field_issue_reference', $issue_nid)
      ->sort('nid')
      ->execute();
    return array_values(array_map('intval', $ids));
  }

  /**
   * Gives a unified node exactly the original aliases of its legacy node.
   *
   * An alias that pathauto generated for the unified node and that differs from
   * the originals is deleted. Pathauto is then set to regenerate the alias only
   * if its pattern produces the original alias; otherwise the alias is kept
   * as it is, so that saving the node does not change the URL. Nothing happens
   * when the legacy node had no alias.
   *
   * @param \Drupal\node\NodeInterface $unified
   *   The saved unified node.
   * @param array $originals
   *   The aliases returned by detach().
   */
  public function assign(NodeInterface $unified, array $originals): void {
    if (!$originals) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('path_alias');
    $missing = $originals;
    foreach ($this->loadAliases($unified->id()) as $row) {
      $found = FALSE;
      foreach ($missing as $index => $original) {
        if ($row->getAlias() === $original['alias'] && $row->language()->getId() === $original['langcode']) {
          unset($missing[$index]);
          $found = TRUE;
          break;
        }
      }
      if (!$found) {
        $row->delete();
      }
    }
    foreach ($missing as $original) {
      $storage->create([
        'path' => '/node/' . $unified->id(),
        'alias' => $original['alias'],
        'langcode' => $original['langcode'],
      ])->save();
    }

    $this->setPathautoState($unified, $originals[0]['alias']);
  }

  /**
   * Sets pathauto to regenerate the alias only when that gives the same alias.
   *
   * @param \Drupal\node\NodeInterface $unified
   *   The unified node.
   * @param string $alias
   *   The alias the node must keep.
   */
  protected function setPathautoState(NodeInterface $unified, string $alias): void {
    if (!$this->generator || !$this->generator->getPatternByEntity($unified)) {
      return;
    }
    $generated = $this->generator->createEntityAlias($unified, 'return');
    $this->keyValue->get('pathauto_state.node')->set($unified->id(), $generated === $alias ? self::STATE_CREATE : self::STATE_SKIP);
  }

  /**
   * Loads the path alias entities of a node.
   *
   * @param int|string $nid
   *   The node ID.
   *
   * @return \Drupal\path_alias\PathAliasInterface[]
   *   The alias entities.
   */
  protected function loadAliases(int|string $nid): array {
    $storage = $this->entityTypeManager->getStorage('path_alias');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('path', '/node/' . $nid)->sort('id')->execute();
    return $storage->loadMultiple($ids);
  }

}
