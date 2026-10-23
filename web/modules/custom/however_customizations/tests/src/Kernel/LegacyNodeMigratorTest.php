<?php

/**
 * @file
 * LegacyNodeMigratorTest.php
 *
 * To run this test:
 *
 * 1. From the DDEV project root:
 *    ddev ssh
 *    cd web
 *
 * 2. Run this test:
 *    SIMPLETEST_DB=mysql://db:db@db/db ../vendor/bin/phpunit -c ./core/phpunit.xml.dist ./modules/custom/however_customizations/tests/src/Kernel/LegacyNodeMigratorTest.php
 */

namespace Drupal\Tests\however_customizations\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\however_customizations\MigrationStage;
use Drupal\however_customizations\Service\LegacyNodeMigrator;
use Drupal\however_customizations\Service\MigrationMap;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the migration of legacy volumes to the unified volume bundle.
 */
#[Group('however_customizations')]
#[RunTestsInSeparateProcesses]
class LegacyNodeMigratorTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'however_customizations',
    'node',
    'field',
    'text',
    'options',
    'link',
    'user',
    'system',
  ];

  /**
   * The migrator.
   *
   * @var \Drupal\however_customizations\Service\LegacyNodeMigrator
   */
  protected LegacyNodeMigrator $migrator;

  /**
   * The migration map.
   *
   * @var \Drupal\however_customizations\Service\MigrationMap
   */
  protected MigrationMap $map;

  /**
   * The ID of the user that owns the first legacy volume.
   *
   * @var int
   */
  protected int $editorId;

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

    $bundles = ['journal', 'volume', 'however_volume', 'how2_volume'];
    foreach ($bundles as $type) {
      NodeType::create(['type' => $type, 'name' => $type])->save();
    }

    $journal_names = ['journal', 'volume', 'however_volume', 'how2_volume'];
    $this->addField('field_journal_name', 'list_string', $journal_names, [
      'allowed_values' => ['however' => 'How(ever)', 'how2' => 'How2'],
    ]);
    $this->addField('field_volume_number', 'integer', ['volume', 'however_volume', 'how2_volume']);
    $this->addField('field_journal', 'entity_reference', ['volume'], ['target_type' => 'node']);
    $this->addField('field_page_facsimile_link', 'link', ['volume']);
    $this->addField('field_archive_url', 'link', ['volume', 'how2_volume']);
    $this->addField('field_however_page_facimile_link', 'link', ['however_volume']);

    $this->createNode(['type' => 'journal', 'title' => 'How(ever)', 'field_journal_name' => 'however']);
    $this->createNode(['type' => 'journal', 'title' => 'How2', 'field_journal_name' => 'how2']);

    $this->map = $this->container->get('however_customizations.migration_map');
    $this->migrator = $this->container->get('however_customizations.legacy_node_migrator');
  }

  /**
   * Creates a field storage and an instance on each given bundle.
   */
  protected function addField(string $name, string $type, array $bundles, array $storage_settings = []): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => $type,
      'settings' => $storage_settings,
    ])->save();

    foreach ($bundles as $bundle) {
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => $bundle,
      ])->save();
    }
  }

  /**
   * Creates and saves a published node.
   */
  protected function createNode(array $values): NodeInterface {
    $node = Node::create($values + ['title' => 'Test', 'status' => 1]);
    $node->save();
    return $node;
  }

  /**
   * Creates the legacy volumes: two How(ever), one How2.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The legacy volumes keyed by a short name.
   */
  protected function createLegacyVolumes(): array {
    $editor = User::create(['name' => 'editor', 'status' => 1]);
    $editor->save();
    $this->editorId = (int) $editor->id();

    return [
      'however1' => $this->createNode([
        'type' => 'however_volume',
        'field_volume_number' => 1,
        'field_journal_name' => 'however',
        'field_however_page_facimile_link' => ['uri' => 'https://example.com/however/1', 'title' => 'Facsimile'],
        'created' => 1000000,
        'uid' => $this->editorId,
      ]),
      'however2' => $this->createNode([
        'type' => 'however_volume',
        'field_volume_number' => 2,
        'field_journal_name' => 'however',
        'status' => 0,
        'promote' => 1,
        'sticky' => 1,
      ]),
      'how2' => $this->createNode([
        'type' => 'how2_volume',
        'field_volume_number' => 3,
        'field_journal_name' => 'how2',
        'field_archive_url' => ['uri' => 'https://example.com/how2/3', 'title' => 'Archive'],
      ]),
    ];
  }

  /**
   * Loads the unified volumes ordered by volume number.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The unified volumes, keyed by volume number.
   */
  protected function loadUnifiedVolumes(): array {
    $nodes = $this->container->get('entity_type.manager')->getStorage('node')->loadByProperties(['type' => 'volume']);
    $by_number = [];
    foreach ($nodes as $node) {
      $by_number[(int) $node->get('field_volume_number')->value] = $node;
    }
    ksort($by_number);
    return $by_number;
  }

  /**
   * Tests that a dry run reports the work and changes nothing.
   */
  public function testDryRunChangesNothing(): void {
    $this->createLegacyVolumes();

    $result = $this->migrator->migrate(MigrationStage::Volume);

    $this->assertSame(3, $result['counts']['would_create']);
    $this->assertSame(0, $result['counts']['created']);
    $this->assertCount(3, $result['rows']);
    $this->assertSame('would_create', $result['rows'][0]['action']);
    $this->assertEmpty($this->loadUnifiedVolumes());
    $this->assertSame(0, $this->map->count());
  }

  /**
   * Tests that applying creates the unified volumes and records the map.
   */
  public function testApplyCreatesVolumes(): void {
    $legacy = $this->createLegacyVolumes();
    $journals = $this->container->get('entity_type.manager')->getStorage('node')->loadByProperties(['type' => 'journal']);
    $journal_ids = [];
    foreach ($journals as $journal) {
      $journal_ids[$journal->get('field_journal_name')->value] = (int) $journal->id();
    }

    $result = $this->migrator->migrate(MigrationStage::Volume, TRUE);

    $this->assertSame(3, $result['counts']['created']);
    $volumes = $this->loadUnifiedVolumes();
    $this->assertCount(3, $volumes);
    $this->assertSame(3, $this->map->count(MigrationStage::Volume));

    $first = $volumes[1];
    $this->assertEquals($journal_ids['however'], $first->get('field_journal')->target_id);
    $this->assertSame('however', $first->get('field_journal_name')->value);
    $this->assertSame('https://example.com/however/1', $first->get('field_page_facsimile_link')->uri);
    $this->assertSame('Facsimile', $first->get('field_page_facsimile_link')->title);
    $this->assertSame(1000000, (int) $first->getCreatedTime());
    $this->assertSame($this->editorId, (int) $first->getOwnerId());
    $this->assertSame($legacy['however1']->id(), (string) $this->map->getOldNid($first->id()));
    $this->assertEquals($first->id(), $this->map->getNewNid($legacy['however1']->id()));

    $second = $volumes[2];
    $this->assertFalse($second->isPublished());
    $this->assertTrue($second->isPromoted());
    $this->assertTrue($second->isSticky());
    $this->assertTrue($second->get('field_page_facsimile_link')->isEmpty());

    $third = $volumes[3];
    $this->assertEquals($journal_ids['how2'], $third->get('field_journal')->target_id);
    $this->assertSame('https://example.com/how2/3', $third->get('field_archive_url')->uri);

    foreach ($volumes as $volume) {
      $this->assertNotEmpty($volume->getTitle());
    }
    $this->assertSame('How(ever) Volume 1', $first->getTitle());
    $this->assertSame('How2 Volume 3', $third->getTitle());

    foreach ($legacy as $node) {
      $this->assertNotNull(Node::load($node->id()), 'The legacy node is untouched.');
    }
  }

  /**
   * Tests that a second run skips volumes that are already migrated.
   */
  public function testRerunSkipsMigratedVolumes(): void {
    $this->createLegacyVolumes();
    $this->migrator->migrate(MigrationStage::Volume, TRUE);

    $result = $this->migrator->migrate(MigrationStage::Volume, TRUE);

    $this->assertSame(0, $result['counts']['created']);
    $this->assertSame(3, $result['counts']['skipped']);
    $this->assertCount(3, $this->loadUnifiedVolumes());
    $this->assertSame(3, $this->map->count(MigrationStage::Volume));
  }

  /**
   * Tests that the limit counts only volumes that are not yet migrated.
   */
  public function testLimitCountsOnlyUnmigratedVolumes(): void {
    $this->createLegacyVolumes();

    $first_run = $this->migrator->migrate(MigrationStage::Volume, TRUE, 2);
    $this->assertSame(2, $first_run['counts']['created']);
    $this->assertCount(2, $this->loadUnifiedVolumes());

    $second_run = $this->migrator->migrate(MigrationStage::Volume, TRUE, 2);
    $this->assertSame(1, $second_run['counts']['created']);
    $this->assertSame(2, $second_run['counts']['skipped']);
    $this->assertCount(3, $this->loadUnifiedVolumes());
  }

  /**
   * Tests that a rollback removes the volumes and lets the stage run again.
   */
  public function testRollbackThenRerun(): void {
    $this->createLegacyVolumes();
    $this->migrator->migrate(MigrationStage::Volume, TRUE);

    $rollback = $this->map->rollback(MigrationStage::Volume, TRUE);
    $this->assertSame(3, $rollback['deleted']);
    $this->assertEmpty($this->loadUnifiedVolumes());
    $this->assertSame(0, $this->map->count());

    $result = $this->migrator->migrate(MigrationStage::Volume, TRUE);
    $this->assertSame(3, $result['counts']['created']);
    $this->assertCount(3, $this->loadUnifiedVolumes());
  }

  /**
   * Tests that a stage without a field map is refused before anything runs.
   */
  public function testStageWithoutFieldMapIsRefused(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('issue stage cannot be migrated yet');

    $this->migrator->migrate(MigrationStage::Issue, TRUE);
  }

  /**
   * Tests that a missing journal node fails cleanly with nothing created.
   */
  public function testMissingJournalFailsWithNothingCreated(): void {
    $this->createLegacyVolumes();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->delete($storage->loadByProperties(['type' => 'journal', 'field_journal_name' => 'how2']));

    try {
      $this->migrator->migrate(MigrationStage::Volume, TRUE);
      $this->fail('Expected a RuntimeException for the missing journal.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('how2', $e->getMessage());
    }

    $this->assertEmpty($this->loadUnifiedVolumes());
    $this->assertSame(0, $this->map->count());
  }

}
