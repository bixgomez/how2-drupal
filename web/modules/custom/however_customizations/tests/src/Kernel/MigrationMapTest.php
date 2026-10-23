<?php

/**
 * @file
 * MigrationMapTest.php
 *
 * To run this test:
 *
 * 1. From the DDEV project root:
 *    ddev ssh
 *    cd web
 *
 * 2. Run this test:
 *    SIMPLETEST_DB=mysql://db:db@db/db ../vendor/bin/phpunit -c ./core/phpunit.xml.dist ./modules/custom/however_customizations/tests/src/Kernel/MigrationMapTest.php
 */

namespace Drupal\Tests\however_customizations\Kernel;

use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\however_customizations\MigrationStage;
use Drupal\however_customizations\Service\MigrationMap;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the legacy-to-unified migration map.
 */
#[Group('however_customizations')]
#[RunTestsInSeparateProcesses]
class MigrationMapTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'however_customizations',
    'node',
    'field',
    'text',
    'user',
    'system',
  ];

  /**
   * The migration map.
   *
   * @var \Drupal\however_customizations\Service\MigrationMap
   */
  protected MigrationMap $map;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('however_customizations', ['however_migration_map']);
    $this->installConfig(['system']);

    foreach (['however_volume', 'volume'] as $type) {
      NodeType::create(['type' => $type, 'name' => $type])->save();
    }

    $this->map = $this->container->get('however_customizations.migration_map');
  }

  /**
   * Creates and saves a node.
   */
  protected function createNode(string $type): NodeInterface {
    $node = Node::create(['type' => $type, 'title' => 'Test', 'status' => 1]);
    $node->save();
    return $node;
  }

  /**
   * Creates a legacy node and a unified node and records them as a pair.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The legacy node and the unified node.
   */
  protected function recordPair(MigrationStage $stage): array {
    $old = $this->createNode('however_volume');
    $new = $this->createNode('volume');
    $this->map->record($stage, $old, $new);
    return [$old, $new];
  }

  /**
   * Tests that a recorded pair can be looked up in both directions.
   */
  public function testRecordAndLookup(): void {
    [$old, $new] = $this->recordPair(MigrationStage::Volume);

    $this->assertTrue($this->map->isMapped($old->id()));
    $this->assertSame((int) $new->id(), $this->map->getNewNid($old->id()));
    $this->assertSame((int) $old->id(), $this->map->getOldNid($new->id()));

    $rows = $this->map->getRows();
    $this->assertCount(1, $rows);
    $this->assertSame('volume', $rows[0]['stage']);
    $this->assertEquals($old->id(), $rows[0]['old_nid']);
    $this->assertSame('however_volume', $rows[0]['old_bundle']);
    $this->assertSame($old->uuid(), $rows[0]['old_uuid']);
    $this->assertEquals($new->id(), $rows[0]['new_nid']);
    $this->assertSame($new->uuid(), $rows[0]['new_uuid']);
    $this->assertGreaterThan(0, (int) $rows[0]['created']);
  }

  /**
   * Tests the answers for nodes that are not mapped.
   */
  public function testUnmappedNodes(): void {
    $node = $this->createNode('however_volume');

    $this->assertFalse($this->map->isMapped($node->id()));
    $this->assertNull($this->map->getNewNid($node->id()));
    $this->assertNull($this->map->getOldNid($node->id()));
    $this->assertSame(0, $this->map->count());
  }

  /**
   * Tests that a legacy node can only be mapped once.
   */
  public function testLegacyNodeCannotBeMappedTwice(): void {
    [$old] = $this->recordPair(MigrationStage::Volume);

    $this->expectException(IntegrityConstraintViolationException::class);
    $this->map->record(MigrationStage::Volume, $old, $this->createNode('volume'));
  }

  /**
   * Tests rows and counts per stage.
   */
  public function testRowsAndCountsPerStage(): void {
    $this->recordPair(MigrationStage::Volume);
    $this->recordPair(MigrationStage::Volume);
    $this->recordPair(MigrationStage::Issue);

    $this->assertSame(3, $this->map->count());
    $this->assertSame(2, $this->map->count(MigrationStage::Volume));
    $this->assertSame(1, $this->map->count(MigrationStage::Issue));
    $this->assertSame(0, $this->map->count(MigrationStage::Section));
    $this->assertCount(2, $this->map->getRows(MigrationStage::Volume));
    $this->assertCount(3, $this->map->getRows());
  }

  /**
   * Tests that stages are ordered by dependency.
   */
  public function testLaterStages(): void {
    $this->assertSame([MigrationStage::Issue, MigrationStage::Section, MigrationStage::Article], MigrationStage::Volume->laterStages());
    $this->assertSame([], MigrationStage::Article->laterStages());
  }

  /**
   * Tests that a rollback without apply changes nothing.
   */
  public function testRollbackDryRunChangesNothing(): void {
    [$old, $new] = $this->recordPair(MigrationStage::Volume);

    $result = $this->map->rollback(MigrationStage::Volume);

    $this->assertSame(['mapped' => 1, 'deleted' => 0, 'already_gone' => 0], $result);
    $this->assertNotNull(Node::load($new->id()));
    $this->assertNotNull(Node::load($old->id()));
    $this->assertSame(1, $this->map->count(MigrationStage::Volume));
  }

  /**
   * Tests that a rollback deletes only the unified nodes and their map rows.
   */
  public function testRollbackDeletesUnifiedNodesOnly(): void {
    [$old, $new] = $this->recordPair(MigrationStage::Volume);
    $unrelated = $this->createNode('volume');

    $result = $this->map->rollback(MigrationStage::Volume, TRUE);

    $this->assertSame(['mapped' => 1, 'deleted' => 1, 'already_gone' => 0], $result);
    $this->assertNull(Node::load($new->id()), 'The unified node is deleted.');
    $this->assertNotNull(Node::load($old->id()), 'The legacy node is kept.');
    $this->assertNotNull(Node::load($unrelated->id()), 'A node that is not in the map is kept.');
    $this->assertSame(0, $this->map->count());
    $this->assertFalse($this->map->isMapped($old->id()), 'The legacy node can be migrated again.');
  }

  /**
   * Tests that a rollback copes with unified nodes that are already gone.
   */
  public function testRollbackCountsNodesAlreadyGone(): void {
    [$old, $new] = $this->recordPair(MigrationStage::Volume);
    $new->delete();

    $result = $this->map->rollback(MigrationStage::Volume, TRUE);

    $this->assertSame(['mapped' => 1, 'deleted' => 0, 'already_gone' => 1], $result);
    $this->assertSame(0, $this->map->count());
  }

  /**
   * Tests that a stage cannot be rolled back before the stages after it.
   */
  public function testRollbackRefusedWhileLaterStageIsMapped(): void {
    [, $volume] = $this->recordPair(MigrationStage::Volume);
    $this->recordPair(MigrationStage::Issue);

    try {
      $this->map->rollback(MigrationStage::Volume, TRUE);
      $this->fail('Rolling back the volume stage should have been refused.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('Roll back the issue stage first', $e->getMessage());
    }
    $this->assertNotNull(Node::load($volume->id()), 'Nothing was deleted.');
    $this->assertSame(2, $this->map->count());

    $this->map->rollback(MigrationStage::Issue, TRUE);
    $this->map->rollback(MigrationStage::Volume, TRUE);
    $this->assertSame(0, $this->map->count());
  }

  /**
   * Tests that the update hook creates the table, and can run again.
   */
  public function testUpdateHookCreatesTheTable(): void {
    $schema = $this->container->get('database')->schema();
    $schema->dropTable(MigrationMap::TABLE);
    $this->assertFalse($schema->tableExists(MigrationMap::TABLE));

    $this->container->get('module_handler')->loadInclude('however_customizations', 'install');
    $this->assertStringContainsString('Created', however_customizations_update_11001());
    $this->assertTrue($schema->tableExists(MigrationMap::TABLE));
    $this->assertStringContainsString('already exists', however_customizations_update_11001());
  }

}
