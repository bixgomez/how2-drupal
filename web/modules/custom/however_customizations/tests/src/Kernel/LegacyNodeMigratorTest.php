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
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the migration of legacy volumes and issues to the unified bundles.
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
    'file',
    'entity_reference_revisions',
    'paragraphs',
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
    $this->installEntitySchema('paragraph');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('however_customizations', ['however_migration_map']);
    $this->installConfig(['system']);

    $bundles = ['journal', 'volume', 'however_volume', 'how2_volume', 'issue', 'journal_issue', 'how2_issue', 'page_facsimiles'];
    foreach ($bundles as $type) {
      NodeType::create(['type' => $type, 'name' => $type])->save();
    }

    $journal_names = ['journal', 'volume', 'however_volume', 'how2_volume', 'issue', 'page_facsimiles'];
    $this->addField('field_journal_name', 'list_string', $journal_names, [
      'allowed_values' => ['however' => 'How(ever)', 'how2' => 'How2'],
    ]);
    $this->addField('field_volume_number', 'integer', ['volume', 'however_volume', 'how2_volume']);
    $this->addField('field_journal', 'entity_reference', ['volume'], ['target_type' => 'node']);
    $this->addField('field_page_facsimile_link', 'link', ['volume']);
    $this->addField('field_archive_url', 'link', ['volume', 'how2_volume']);
    $this->addField('field_however_page_facimile_link', 'link', ['however_volume']);
    $this->addIssueFields();

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
   * Creates the issue fields and the person bio paragraph type.
   *
   * Types that need other modules in production (media, image) are replaced
   * by simple types; the migrator only copies the values.
   */
  protected function addIssueFields(): void {
    $issues = ['journal_issue', 'how2_issue', 'issue'];
    $this->addField('field_issue_number', 'integer', array_merge($issues, ['page_facsimiles']));
    $this->addField('body', 'text_with_summary', $issues);
    $this->addField('field_cover_thumbnail', 'string', $issues);
    $this->addField('field_masthead', 'text_long', $issues);
    $this->addField('field_publication_date_display', 'string', $issues);
    $this->addField('field_short_description', 'string', $issues);
    $this->addField('field_journal_pdf', 'entity_reference', ['journal_issue', 'how2_issue'], ['target_type' => 'node']);
    $this->addField('field_issue_pdf', 'entity_reference', ['issue'], ['target_type' => 'node']);
    $this->addField('field_journal_page_images_media', 'entity_reference', $issues, ['target_type' => 'node', 'cardinality' => -1]);
    $this->addField('field_volume_reference', 'entity_reference', $issues, ['target_type' => 'node']);
    $this->addField('field_issue_reference', 'entity_reference', ['page_facsimiles'], ['target_type' => 'node']);
    $this->addField('field_person_bio', 'entity_reference_revisions', $issues, ['target_type' => 'paragraph', 'cardinality' => -1]);
    $this->addInstances('field_archive_url', $issues);
    $this->addInstances('field_volume_number', array_merge($issues, ['page_facsimiles']));
    $this->addInstances('field_journal', ['issue', 'page_facsimiles']);

    ParagraphsType::create(['id' => 'person_bio', 'label' => 'Person bio'])->save();
    $this->addParagraphField('field_override_bio', 'text_long');
  }

  /**
   * Adds instances of existing field storage to bundles.
   */
  protected function addInstances(string $name, array $bundles): void {
    foreach ($bundles as $bundle) {
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => $bundle])->save();
    }
  }

  /**
   * Adds a field to the person bio paragraph type.
   */
  protected function addParagraphField(string $name, string $type): void {
    FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'paragraph', 'type' => $type])->save();
    FieldConfig::create(['field_name' => $name, 'entity_type' => 'paragraph', 'bundle' => 'person_bio'])->save();
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
    $this->expectExceptionMessage('section stage cannot be migrated yet');

    $this->migrator->migrate(MigrationStage::Section, TRUE);
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

  /**
   * Creates a legacy issue without running the title and sync hook.
   *
   * The legacy data holds titles the hook would replace, so the hook is
   * skipped as it was when the real data was saved.
   */
  protected function createLegacyIssue(array $values): NodeInterface {
    $issue = Node::create($values + ['title' => 'Test', 'status' => 1]);
    $issue->however_skip_presave = TRUE;
    $issue->save();
    return $issue;
  }

  /**
   * Loads the unified issues keyed by the legacy nid they came from.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The unified issues.
   */
  protected function loadUnifiedIssues(): array {
    $issues = [];
    foreach ($this->map->getRows(MigrationStage::Issue) as $row) {
      $issues[$row['old_nid']] = Node::load($row['new_nid']);
    }
    return $issues;
  }

  /**
   * Tests that issues are blocked, not created, while volumes are not migrated.
   */
  public function testIssuesAreBlockedUntilTheirVolumeIsMigrated(): void {
    $legacy = $this->createLegacyVolumes();
    $this->createLegacyIssue([
      'type' => 'journal_issue',
      'field_volume_reference' => $legacy['however1']->id(),
      'field_volume_number' => 1,
      'field_issue_number' => 1,
    ]);

    foreach ([FALSE, TRUE] as $apply) {
      $result = $this->migrator->migrate(MigrationStage::Issue, $apply);
      $this->assertSame(1, $result['counts']['blocked']);
      $this->assertSame(0, $result['counts']['created']);
      $this->assertSame('blocked', $result['rows'][0]['action']);
      $this->assertStringContainsString('not migrated', $result['rows'][0]['note']);
    }
    $this->assertSame(0, $this->map->count(MigrationStage::Issue));
  }

  /**
   * Tests that applying creates the unified issues from the legacy issues.
   */
  public function testApplyCreatesIssues(): void {
    $legacy = $this->createLegacyVolumes();
    $bio = Paragraph::create(['type' => 'person_bio', 'field_override_bio' => 'A bio']);
    $bio->save();
    $pdf = $this->createNode(['type' => 'journal', 'title' => 'Stand-in PDF']);

    $custom = $this->createLegacyIssue([
      'type' => 'journal_issue',
      'title' => 'November, 1985--Vol. 2 No. 4',
      'field_volume_reference' => $legacy['however2']->id(),
      'field_volume_number' => 2,
      'field_issue_number' => 4,
      'field_short_description' => 'Short text',
      'field_journal_pdf' => $pdf->id(),
      'field_person_bio' => [['target_id' => $bio->id(), 'target_revision_id' => $bio->getRevisionId()]],
      'created' => 2000000,
    ]);
    $how2 = $this->createLegacyIssue([
      'type' => 'how2_issue',
      'title' => 'How2 Volume 3 Issue 1',
      'field_volume_reference' => $legacy['how2']->id(),
      'field_volume_number' => 3,
      'field_issue_number' => 1,
    ]);

    $this->migrator->migrate(MigrationStage::Volume, TRUE);
    $result = $this->migrator->migrate(MigrationStage::Issue, TRUE);

    $this->assertSame(2, $result['counts']['created']);
    $issues = $this->loadUnifiedIssues();
    $new_custom = $issues[$custom->id()];

    $this->assertSame('issue', $new_custom->bundle());
    $this->assertSame('November, 1985--Vol. 2 No. 4', $new_custom->getTitle());
    $this->assertEquals($this->map->getNewNid($legacy['however2']->id()), $new_custom->get('field_volume_reference')->target_id);
    $this->assertSame('2', (string) $new_custom->get('field_volume_number')->value);
    $this->assertSame('4', (string) $new_custom->get('field_issue_number')->value);
    $this->assertSame('Short text', $new_custom->get('field_short_description')->value);
    $this->assertEquals($pdf->id(), $new_custom->get('field_issue_pdf')->target_id);
    $this->assertSame('however', $new_custom->get('field_journal_name')->value);
    $this->assertSame(2000000, (int) $new_custom->getCreatedTime());

    $journal = Node::load($new_custom->get('field_journal')->target_id);
    $this->assertSame('however', $journal->get('field_journal_name')->value);
    $how2_journal = Node::load($issues[$how2->id()]->get('field_journal')->target_id);
    $this->assertSame('how2', $how2_journal->get('field_journal_name')->value);
    $this->assertSame('how2', $issues[$how2->id()]->get('field_journal_name')->value);

    // The person bio is a copy; the legacy issue keeps its own paragraph.
    $copy = $new_custom->get('field_person_bio')->entity;
    $this->assertNotNull($copy);
    $this->assertNotEquals($bio->id(), $copy->id());
    $this->assertSame('A bio', $copy->get('field_override_bio')->value);
    $this->assertEquals($bio->id(), Node::load($custom->id())->get('field_person_bio')->target_id);

    foreach ([$custom, $how2] as $node) {
      $this->assertNotNull(Node::load($node->id()), 'The legacy issue is untouched.');
    }
  }

  /**
   * Tests that an issue without a volume is migrated without one.
   */
  public function testIssueWithoutVolumeIsMigrated(): void {
    $orphan = $this->createLegacyIssue([
      'type' => 'journal_issue',
      'title' => 'Archive page test',
      'field_issue_number' => 9,
    ]);

    $result = $this->migrator->migrate(MigrationStage::Issue, TRUE);

    $this->assertSame(1, $result['counts']['created']);
    $this->assertSame(0, $result['counts']['blocked']);
    $new = $this->loadUnifiedIssues()[$orphan->id()];
    $this->assertTrue($new->get('field_volume_reference')->isEmpty());
    $this->assertSame('Archive page test', $new->getTitle());
  }

  /**
   * Tests that every migrated issue gets the facsimiles its legacy issue has.
   *
   * An issue with page images gets its facsimiles node from the save hook; the
   * others get a copy of the legacy one. An issue without a facsimiles node
   * gets none. The legacy facsimiles are untouched, and rolling back the issues
   * removes only the facsimiles of the unified issues.
   */
  public function testMigratedIssuesGetTheirFacsimiles(): void {
    $legacy = $this->createLegacyVolumes();
    $image = $this->createNode(['type' => 'journal', 'title' => 'Stand-in image']);
    $with_images = $this->createLegacyIssue([
      'type' => 'journal_issue',
      'field_volume_reference' => $legacy['however1']->id(),
      'field_volume_number' => 1,
      'field_issue_number' => 1,
      'field_journal_page_images_media' => [['target_id' => $image->id()]],
    ]);
    $without_images = $this->createLegacyIssue([
      'type' => 'journal_issue',
      'field_volume_reference' => $legacy['however1']->id(),
      'field_volume_number' => 1,
      'field_issue_number' => 2,
    ]);
    $old_facsimile = $this->createNode([
      'type' => 'page_facsimiles',
      'title' => 'However Volume 1 Issue 2: Page Facsimiles',
      'uid' => $this->editorId,
      'created' => 3000000,
      'field_issue_reference' => $without_images->id(),
      'field_volume_number' => 1,
      'field_issue_number' => 2,
      'field_journal_name' => 'however',
    ]);
    $no_facsimile = $this->createLegacyIssue([
      'type' => 'how2_issue',
      'field_volume_reference' => $legacy['how2']->id(),
      'field_volume_number' => 3,
      'field_issue_number' => 1,
    ]);

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $facsimiles_of = fn (int|string $issue_id) => array_values($storage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'page_facsimiles')->condition('field_issue_reference', $issue_id)->execute());
    $all = fn () => count($storage->getQuery()->accessCheck(FALSE)->condition('type', 'page_facsimiles')->execute());
    $legacy_before = $all();

    $this->migrator->migrate(MigrationStage::Volume, TRUE);
    $dry = $this->migrator->migrate(MigrationStage::Issue, FALSE);
    $notes = array_column($dry['rows'], 'note', 'old_nid');
    $this->assertSame('has a facsimile page', $notes[$with_images->id()]);
    $this->assertSame('has a facsimile page', $notes[$without_images->id()]);
    $this->assertSame('', $notes[$no_facsimile->id()]);
    $this->assertSame($legacy_before, $all(), 'A dry run creates no facsimiles.');

    $this->migrator->migrate(MigrationStage::Issue, TRUE);
    $issues = $this->loadUnifiedIssues();

    $this->assertCount(1, $facsimiles_of($issues[$with_images->id()]->id()));
    $this->assertCount(1, $facsimiles_of($issues[$without_images->id()]->id()));
    $this->assertCount(0, $facsimiles_of($issues[$no_facsimile->id()]->id()));
    $this->assertSame($legacy_before + 2, $all());

    $copy = Node::load($facsimiles_of($issues[$without_images->id()]->id())[0]);
    $this->assertSame('However Volume 1 Issue 2: Page Facsimiles', $copy->getTitle());
    $this->assertSame('however', $copy->get('field_journal_name')->value);
    $this->assertSame('2', (string) $copy->get('field_issue_number')->value);
    $this->assertSame(3000000, (int) $copy->getCreatedTime());
    $this->assertSame($this->editorId, (int) $copy->getOwnerId());
    $journal = Node::load($copy->get('field_journal')->target_id);
    $this->assertSame('however', $journal->get('field_journal_name')->value);
    $this->assertEquals($old_facsimile->id(), $facsimiles_of($without_images->id())[0] ?? NULL, 'The legacy facsimile is untouched.');

    $this->map->rollback(MigrationStage::Issue, TRUE);

    $this->assertSame($legacy_before, $all());
    $this->assertNotNull(Node::load($old_facsimile->id()));
  }

}
