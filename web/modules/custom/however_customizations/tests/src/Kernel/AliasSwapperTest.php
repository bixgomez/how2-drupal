<?php

/**
 * @file
 * AliasSwapperTest.php
 *
 * To run this test:
 *
 * 1. From the DDEV project root:
 *    ddev ssh
 *    cd web
 *
 * 2. Run this test:
 *    SIMPLETEST_DB=mysql://db:db@db/db ../vendor/bin/phpunit -c ./core/phpunit.xml.dist ./modules/custom/however_customizations/tests/src/Kernel/AliasSwapperTest.php
 */

namespace Drupal\Tests\however_customizations\Kernel;

use Drupal\however_customizations\Service\AliasSwapper;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\pathauto\Entity\PathautoPattern;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests handing the URL aliases of a legacy node over to a unified node.
 */
#[Group('however_customizations')]
#[RunTestsInSeparateProcesses]
class AliasSwapperTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'however_customizations',
    'node',
    'field',
    'text',
    'filter',
    'user',
    'system',
    'path',
    'path_alias',
    'token',
    'pathauto',
  ];

  /**
   * The alias swapper.
   *
   * @var \Drupal\however_customizations\Service\AliasSwapper
   */
  protected AliasSwapper $swapper;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('however_customizations', ['however_migration_map']);
    $this->installConfig(['system', 'pathauto']);

    foreach (['however_volume', 'volume'] as $type) {
      NodeType::create(['type' => $type, 'name' => $type])->save();
    }

    // The unified volumes get /vol/[title]; the legacy ones have no pattern.
    $pattern = PathautoPattern::create([
      'id' => 'volume',
      'type' => 'canonical_entities:node',
      'pattern' => '/vol/[node:title]',
      'weight' => 0,
    ]);
    $pattern->addSelectionCondition([
      'id' => 'entity_bundle:node',
      'bundles' => ['volume' => 'volume'],
      'negate' => FALSE,
      'context_mapping' => ['node' => 'node'],
    ]);
    $pattern->save();

    $this->swapper = $this->container->get('however_customizations.alias_swapper');
  }

  /**
   * Creates a node without a pathauto alias for legacy volumes.
   */
  protected function createNode(string $type, string $title): NodeInterface {
    $node = Node::create(['type' => $type, 'title' => $title, 'status' => 1]);
    $node->save();
    return $node;
  }

  /**
   * Adds an alias row to a node.
   */
  protected function addAlias(NodeInterface $node, string $alias, string $langcode = 'en'): void {
    PathAlias::create(['path' => '/node/' . $node->id(), 'alias' => $alias, 'langcode' => $langcode])->save();
  }

  /**
   * Lists the alias strings of a node, sorted.
   *
   * @return string[]
   *   The aliases.
   */
  protected function aliasesOf(int|string $nid): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('path_alias');
    $aliases = array_map(fn ($row) => $row->getAlias(), $storage->loadByProperties(['path' => '/node/' . $nid]));
    sort($aliases);
    return $aliases;
  }

  /**
   * Reads the pathauto state of a node: 1 regenerate, 0 keep, NULL not stored.
   */
  protected function stateOf(int|string $nid): ?int {
    $state = $this->container->get('keyvalue')->get('pathauto_state.node')->get($nid);
    return $state === NULL ? NULL : (int) $state;
  }

  /**
   * Tests that detach moves the aliases under /old and restore moves them back.
   */
  public function testDetachAndRestore(): void {
    $legacy = $this->createNode('however_volume', 'Volume 1');
    $this->addAlias($legacy, '/however/v1');
    $this->addAlias($legacy, '/however/v1-0', 'und');

    $originals = $this->swapper->detach($legacy->id());

    $this->assertEqualsCanonicalizing([
      ['alias' => '/however/v1', 'langcode' => 'en'],
      ['alias' => '/however/v1-0', 'langcode' => 'und'],
    ], $originals);
    $this->assertSame(['/old/however/v1', '/old/however/v1-0'], $this->aliasesOf($legacy->id()));

    $this->assertSame([], $this->swapper->detach($legacy->id()), 'Aliases that are already under /old are not moved again.');
    $this->assertSame(['/old/however/v1', '/old/however/v1-0'], $this->aliasesOf($legacy->id()));

    $this->assertSame(2, $this->swapper->restore($legacy->id()));
    $this->assertSame(['/however/v1', '/however/v1-0'], $this->aliasesOf($legacy->id()));
    $this->assertSame(0, $this->swapper->restore($legacy->id()), 'Restoring a node that was never swapped changes nothing.');
    $this->assertSame(['/however/v1', '/however/v1-0'], $this->aliasesOf($legacy->id()));
  }

  /**
   * Tests that a node without an alias is left alone.
   */
  public function testNodeWithoutAliasIsLeftAlone(): void {
    $legacy = $this->createNode('however_volume', 'Volume 1');
    $unified = $this->createNode('volume', 'Volume 1');
    $generated = $this->aliasesOf($unified->id());
    $this->assertSame(['/vol/volume-1'], $generated);

    $originals = $this->swapper->detach($legacy->id());
    $this->assertSame([], $originals);
    $this->swapper->assign($unified, $originals);

    $this->assertSame([], $this->aliasesOf($legacy->id()));
    $this->assertSame($generated, $this->aliasesOf($unified->id()), 'The pathauto alias stays when the legacy node had none.');
  }

  /**
   * Tests that the unified node takes the original alias when pathauto agrees.
   */
  public function testAssignKeepsAnAliasThePatternProduces(): void {
    $legacy = $this->createNode('however_volume', 'Volume 1');
    $this->addAlias($legacy, '/vol/volume-1');
    $originals = $this->swapper->detach($legacy->id());

    $unified = $this->createNode('volume', 'Volume 1');
    $this->swapper->assign($unified, $originals);

    $this->assertSame(['/old/vol/volume-1'], $this->aliasesOf($legacy->id()));
    $this->assertSame(['/vol/volume-1'], $this->aliasesOf($unified->id()));
    $this->assertSame(1, $this->stateOf($unified->id()), 'Pathauto keeps managing the alias because it gives the same one.');
  }

  /**
   * Tests that an alias the pattern does not produce is kept as it is.
   */
  public function testAssignKeepsACustomAliasAndStopsPathauto(): void {
    $legacy = $this->createNode('however_volume', 'Volume 1');
    $this->addAlias($legacy, '/however/v1-0');
    $originals = $this->swapper->detach($legacy->id());

    $unified = $this->createNode('volume', 'Volume 1');
    $this->assertSame(['/vol/volume-1'], $this->aliasesOf($unified->id()));
    $this->swapper->assign($unified, $originals);

    $this->assertSame(['/however/v1-0'], $this->aliasesOf($unified->id()), 'The generated alias is replaced by the original.');
    $this->assertSame(0, $this->stateOf($unified->id()));

    $reloaded = Node::load($unified->id());
    $reloaded->setTitle('Volume 1 renamed');
    $reloaded->save();
    $this->assertSame(['/however/v1-0'], $this->aliasesOf($unified->id()), 'Saving the unified node does not change the alias.');
  }

  /**
   * Tests that the original alias and language are copied exactly.
   */
  public function testAssignCopiesLanguageAndExtraAliases(): void {
    $legacy = $this->createNode('however_volume', 'Volume 1');
    $this->addAlias($legacy, '/vol/volume-1');
    $this->addAlias($legacy, '/old-link', 'und');
    $originals = $this->swapper->detach($legacy->id());

    $unified = $this->createNode('volume', 'Volume 1');
    $this->swapper->assign($unified, $originals);

    $this->assertSame(['/old-link', '/vol/volume-1'], $this->aliasesOf($unified->id()));
    $rows = $this->container->get('entity_type.manager')->getStorage('path_alias')->loadByProperties(['path' => '/node/' . $unified->id(), 'alias' => '/old-link']);
    $this->assertSame('und', reset($rows)->language()->getId());
  }

}
