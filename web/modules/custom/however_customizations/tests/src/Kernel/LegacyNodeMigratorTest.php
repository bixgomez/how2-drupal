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
 * Tests the migration of legacy volumes, issues, and sections to the unified bundles.
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

    $bundles = ['journal', 'volume', 'however_volume', 'how2_volume', 'issue', 'journal_issue', 'how2_issue', 'page_facsimiles', 'how_ever_section', 'how2_section', 'section'];
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
    $this->addSectionFields();

    $this->createNode(['type' => 'journal', 'title' => 'How(ever)', 'field_journal_name' => 'however']);
    $this->createNode(['type' => 'journal', 'title' => 'How2', 'field_journal_name' => 'how2']);

    $this->map = $this->container->get('however_customizations.migration_map');
    $this->migrator = $this->container->get('however_customizations.legacy_node_migrator');
  }

  /**
   * Creates a field storage and an instance on each given bundle.
   */
  protected function addField(string $name, string $type, array $bundles, array $storage_settings = []): void {
    $cardinality = $storage_settings['cardinality'] ?? 1;
    unset($storage_settings['cardinality']);
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => $type,
      'cardinality' => $cardinality,
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
   * Adds a paragraph field to paragraph types.
   */
  protected function addParagraphField(string $name, string $type, array $bundles = ['person_bio'], array $settings = []): void {
    FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'paragraph', 'type' => $type, 'settings' => $settings])->save();
    foreach ($bundles as $bundle) {
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'paragraph', 'bundle' => $bundle])->save();
    }
  }

  /**
   * Creates the section fields and the paragraph types used by the pieces.
   */
  protected function addSectionFields(): void {
    $sections = ['how_ever_section', 'how2_section', 'section'];
    foreach (['body', 'field_archive_url', 'field_issue_number', 'field_volume_number', 'field_journal_page_images_media', 'field_issue_reference', 'field_volume_reference', 'field_person_bio'] as $name) {
      $this->addInstances($name, $sections);
    }
    $this->addInstances('field_journal', ['section']);
    $this->addInstances('field_journal_name', ['section']);
    $this->addField('field_links_section_title', 'string', $sections);
    $this->addField('field_show_full_section_page', 'boolean', $sections);
    $this->addField('field_subtitle', 'string', $sections);
    $this->addField('field_tags', 'entity_reference', $sections, ['target_type' => 'node', 'cardinality' => -1]);
    $this->addField('field_teaser_text', 'text_long', $sections);
    $this->addField('field_title_display', 'text_long', $sections);
    $this->addField('field_working_notes', 'text_long', $sections);
    $this->addField('field_image_caption', 'entity_reference_revisions', $sections, ['target_type' => 'paragraph', 'cardinality' => -1]);
    $this->addField('field_piece', 'entity_reference_revisions', ['section'], ['target_type' => 'paragraph', 'cardinality' => -1]);
    $this->addField('field_how_ever_piece', 'entity_reference_revisions', ['how_ever_section'], ['target_type' => 'paragraph', 'cardinality' => -1]);
    $this->addField('field_how2_piece', 'entity_reference_revisions', ['how2_section'], ['target_type' => 'paragraph', 'cardinality' => -1]);

    $types = ['section_links', 'how2_section_links', 'how_ever_subsection', 'how2_subsection', 'subsection', 'link_section_title', 'open_title_text', 'photo_caption'];
    foreach ($types as $type) {
      ParagraphsType::create(['id' => $type, 'label' => $type])->save();
    }
    $this->addParagraphField('field_notes', 'text_long', ['section_links', 'how2_section_links']);
    $this->addParagraphField('field_unit', 'entity_reference', ['section_links', 'how2_section_links'], ['target_type' => 'node']);
    $this->addParagraphField('field_how_ever_subsection', 'entity_reference', ['how_ever_subsection'], ['target_type' => 'node']);
    $this->addParagraphField('field_how2_subsection', 'entity_reference', ['how2_subsection'], ['target_type' => 'node']);
    $this->addParagraphField('field_subsection', 'entity_reference', ['subsection'], ['target_type' => 'node']);
    $this->addParagraphField('field_link_section_title', 'string', ['link_section_title']);
    $this->addParagraphField('field_text', 'text_long', ['open_title_text']);
    $this->addParagraphField('field_title', 'string', ['open_title_text']);
    $this->addParagraphField('field_title_display', 'text_long', ['open_title_text']);
    $this->addParagraphField('field_caption', 'text_long', ['photo_caption']);
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
    $this->expectExceptionMessage('article stage cannot be migrated yet');

    $this->migrator->migrate(MigrationStage::Article, TRUE);
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

  /**
   * Creates a legacy section without running the presave hook.
   */
  protected function createLegacySection(array $values): NodeInterface {
    $section = Node::create($values + ['title' => 'A section', 'status' => 1]);
    $section->however_skip_presave = TRUE;
    $section->save();
    return $section;
  }

  /**
   * Runs the queue that deletes the paragraphs of deleted nodes.
   */
  protected function runOrphanPurger(): void {
    $queue = $this->container->get('queue')->get('entity_reference_revisions_orphan_purger');
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('entity_reference_revisions_orphan_purger');
    while ($item = $queue->claimItem()) {
      $worker->processItem($item->data);
      $queue->deleteItem($item);
    }
  }

  /**
   * Creates and saves a piece paragraph.
   */
  protected function createPiece(string $type, array $values = []): Paragraph {
    $paragraph = Paragraph::create(['type' => $type] + $values);
    $paragraph->save();
    return $paragraph;
  }

  /**
   * Turns paragraphs into values for an entity reference revisions field.
   */
  protected function refs(array $paragraphs): array {
    return array_map(fn (Paragraph $p) => ['target_id' => $p->id(), 'target_revision_id' => $p->getRevisionId()], $paragraphs);
  }

  /**
   * Migrates the volumes and issues that the sections depend on.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The legacy issues, keyed 'however' and 'how2'.
   */
  protected function createParentsAndMigrate(array $legacy): array {
    $issues = [
      'however' => $this->createLegacyIssue([
        'type' => 'journal_issue',
        'field_volume_reference' => $legacy['however1']->id(),
        'field_volume_number' => 1,
        'field_issue_number' => 2,
      ]),
      'how2' => $this->createLegacyIssue([
        'type' => 'how2_issue',
        'field_volume_reference' => $legacy['how2']->id(),
        'field_volume_number' => 3,
        'field_issue_number' => 1,
      ]),
    ];
    $this->migrator->migrate(MigrationStage::Volume, TRUE);
    $this->migrator->migrate(MigrationStage::Issue, TRUE);
    return $issues;
  }

  /**
   * Tests that sections are blocked until their issue and volume are migrated.
   */
  public function testSectionsAreBlockedUntilTheirParentsAreMigrated(): void {
    $legacy = $this->createLegacyVolumes();
    $issue = $this->createLegacyIssue(['type' => 'journal_issue', 'field_volume_reference' => $legacy['however1']->id()]);
    $this->createLegacySection([
      'type' => 'how_ever_section',
      'field_issue_reference' => $issue->id(),
      'field_volume_reference' => $legacy['however1']->id(),
    ]);

    foreach ([FALSE, TRUE] as $execute) {
      $result = $this->migrator->migrate(MigrationStage::Section, $execute);
      $this->assertSame(1, $result['counts']['blocked']);
      $this->assertSame('blocked', $result['rows'][0]['action']);
    }
    $this->assertSame(0, $this->map->count(MigrationStage::Section));
  }

  /**
   * Tests that applying creates the unified sections with converted pieces.
   */
  public function testApplyCreatesSections(): void {
    $legacy = $this->createLegacyVolumes();
    $parents = $this->createParentsAndMigrate($legacy);
    $article = $this->createNode(['type' => 'journal', 'title' => 'Stand-in article']);
    $bio = $this->createPiece('person_bio', ['field_override_bio' => 'A bio']);
    $caption = $this->createPiece('photo_caption', ['field_caption' => 'A caption']);

    $pieces = [
      $this->createPiece('open_title_text', ['field_title' => 'Opening', 'field_text' => 'Some text']),
      $this->createPiece('section_links', ['field_notes' => 'Note one', 'field_unit' => $article->id()]),
      $this->createPiece('how_ever_subsection', ['field_how_ever_subsection' => $article->id()]),
      $this->createPiece('link_section_title', ['field_link_section_title' => 'Links']),
    ];
    $however = $this->createLegacySection([
      'type' => 'how_ever_section',
      'title' => 'Poems',
      'field_issue_reference' => $parents['however']->id(),
      'field_volume_reference' => $legacy['however1']->id(),
      'field_volume_number' => 1,
      'field_issue_number' => 2,
      'field_subtitle' => 'A subtitle',
      'field_show_full_section_page' => 1,
      'field_how_ever_piece' => $this->refs($pieces),
      'field_person_bio' => $this->refs([$bio]),
      'field_image_caption' => $this->refs([$caption]),
      'created' => 4000000,
    ]);
    $how2_pieces = [
      $this->createPiece('how2_section_links', ['field_notes' => 'How2 note', 'field_unit' => $article->id()]),
      $this->createPiece('how2_subsection', ['field_how2_subsection' => $article->id()]),
    ];
    $how2 = $this->createLegacySection([
      'type' => 'how2_section',
      'title' => 'How2 section',
      'field_issue_reference' => $parents['how2']->id(),
      'field_volume_reference' => $legacy['how2']->id(),
      'field_how2_piece' => $this->refs($how2_pieces),
    ]);

    $paragraphs_before = count(Paragraph::loadMultiple());
    $result = $this->migrator->migrate(MigrationStage::Section, TRUE);

    $this->assertSame(2, $result['counts']['created']);
    $new = Node::load($this->map->getNewNid($however->id()));
    $new_how2 = Node::load($this->map->getNewNid($how2->id()));

    $this->assertSame('section', $new->bundle());
    $this->assertSame('Poems', $new->getTitle());
    $this->assertSame('A subtitle', $new->get('field_subtitle')->value);
    $this->assertSame('1', (string) $new->get('field_show_full_section_page')->value);
    $this->assertSame(4000000, (int) $new->getCreatedTime());
    $this->assertEquals($this->map->getNewNid($parents['however']->id()), $new->get('field_issue_reference')->target_id);
    $this->assertEquals($this->map->getNewNid($legacy['however1']->id()), $new->get('field_volume_reference')->target_id);
    $this->assertSame('however', $new->get('field_journal_name')->value);
    $this->assertSame('how2', $new_how2->get('field_journal_name')->value);
    // The legacy how2 section had no numbers; the presave hook fills them from
    // the unified issue, as it does when an editor saves a section.
    $this->assertSame('3', (string) $new_how2->get('field_volume_number')->value);
    $this->assertSame('1', (string) $new_how2->get('field_issue_number')->value);
    $journal = Node::load($new->get('field_journal')->target_id);
    $this->assertSame('however', $journal->get('field_journal_name')->value);

    // The pieces keep their order and become the unified types.
    $converted = $new->get('field_piece')->referencedEntities();
    $this->assertSame(['open_title_text', 'section_links', 'subsection', 'link_section_title'], array_map(fn ($p) => $p->bundle(), $converted));
    $this->assertSame('Opening', $converted[0]->get('field_title')->value);
    $this->assertSame('Some text', $converted[0]->get('field_text')->value);
    $this->assertSame('Note one', $converted[1]->get('field_notes')->value);
    $this->assertSame('Links', $converted[3]->get('field_link_section_title')->value);
    // The references to articles and sections are filled by the links pass.
    $this->assertTrue($converted[1]->get('field_unit')->isEmpty());
    $this->assertTrue($converted[2]->get('field_subsection')->isEmpty());

    $converted_how2 = $new_how2->get('field_piece')->referencedEntities();
    $this->assertSame(['section_links', 'subsection'], array_map(fn ($p) => $p->bundle(), $converted_how2));
    $this->assertSame('How2 note', $converted_how2[0]->get('field_notes')->value);

    // The person bio and the caption are copies; the legacy section is intact.
    $this->assertNotEquals($bio->id(), $new->get('field_person_bio')->target_id);
    $this->assertSame('A bio', $new->get('field_person_bio')->entity->get('field_override_bio')->value);
    $this->assertNotEquals($caption->id(), $new->get('field_image_caption')->target_id);
    $this->assertSame('A caption', $new->get('field_image_caption')->entity->get('field_caption')->value);
    $untouched = Node::load($however->id());
    $this->assertEquals($bio->id(), $untouched->get('field_person_bio')->target_id);
    $this->assertEquals($article->id(), $untouched->get('field_how_ever_piece')->referencedEntities()[1]->get('field_unit')->target_id);
    $this->assertCount(4, $untouched->get('field_how_ever_piece')->referencedEntities());

    // Rolling back removes the sections. Their paragraphs are queued for
    // deletion and go when the queue runs, as it does on cron.
    $this->assertGreaterThan($paragraphs_before, count(Paragraph::loadMultiple()));
    $this->map->rollback(MigrationStage::Section, TRUE);
    $this->runOrphanPurger();
    $this->assertSame($paragraphs_before, count(Paragraph::loadMultiple()));
    $this->assertNotNull(Node::load($however->id()));
  }

  /**
   * Tests that a section without a parent is migrated without one.
   */
  public function testSectionWithoutParentIsMigrated(): void {
    $orphan = $this->createLegacySection(['type' => 'how2_section', 'title' => 'editor\'s notes &']);

    $result = $this->migrator->migrate(MigrationStage::Section, TRUE);

    $this->assertSame(1, $result['counts']['created']);
    $new = Node::load($this->map->getNewNid($orphan->id()));
    $this->assertSame('editor\'s notes &', $new->getTitle());
    $this->assertTrue($new->get('field_issue_reference')->isEmpty());
    $this->assertSame('how2', $new->get('field_journal_name')->value);
  }

  /**
   * Tests that a piece paragraph of an unknown type stops the migration.
   */
  public function testUnknownPieceTypeFailsWithNothingCreated(): void {
    $stray = $this->createPiece('photo_caption');
    $this->createLegacySection(['type' => 'how_ever_section', 'field_how_ever_piece' => $this->refs([$stray])]);

    try {
      $this->migrator->migrate(MigrationStage::Section, TRUE);
      $this->fail('Expected a LogicException for the unknown piece type.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('photo_caption', $e->getMessage());
    }

    $this->assertSame(0, $this->map->count());
    $this->assertEmpty($this->container->get('entity_type.manager')->getStorage('node')->loadByProperties(['type' => 'section']));
  }

}
