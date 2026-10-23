<?php

/**
 * @file
 * UnifiedBundleJournalTest.php
 *
 * To run this test:
 *
 * 1. From the DDEV project root:
 *    ddev ssh
 *    cd web
 *
 * 2. Run this test:
 *    SIMPLETEST_DB=mysql://db:db@db/db ../vendor/bin/phpunit -c ./core/phpunit.xml.dist ./modules/custom/however_customizations/tests/src/Kernel/UnifiedBundleJournalTest.php
 */

namespace Drupal\Tests\however_customizations\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests journal handling on the unified volume, issue, section, and article.
 */
#[Group('however_customizations')]
#[RunTestsInSeparateProcesses]
class UnifiedBundleJournalTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'however_customizations',
    'node',
    'field',
    'text',
    'options',
    'user',
    'system',
  ];

  /**
   * The How(ever) journal node.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $however;

  /**
   * The How2 journal node.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $how2;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system']);

    foreach (['journal', 'volume', 'issue', 'section', 'article', 'page_facsimiles'] as $type) {
      NodeType::create(['type' => $type, 'name' => $type])->save();
    }

    $this->addField('field_volume_number', 'integer', ['volume', 'issue', 'section', 'article', 'page_facsimiles']);
    $this->addField('field_issue_number', 'integer', ['issue', 'section', 'article', 'page_facsimiles']);
    $this->addField('field_journal', 'entity_reference', ['volume', 'issue', 'section', 'article', 'page_facsimiles'], ['target_type' => 'node']);
    $this->addField('field_volume_reference', 'entity_reference', ['issue', 'section', 'article'], ['target_type' => 'node']);
    $this->addField('field_issue_reference', 'entity_reference', ['section', 'article', 'page_facsimiles'], ['target_type' => 'node']);
    $this->addField('field_section_reference', 'entity_reference', ['article'], ['target_type' => 'node']);
    $this->addField('field_journal_page_images_media', 'entity_reference', ['issue'], ['target_type' => 'node']);
    $this->addField('field_journal_name', 'list_string', ['journal', 'page_facsimiles'], [
      'allowed_values' => ['however' => 'How(ever)', 'how2' => 'How2'],
    ]);

    $this->however = Node::create(['type' => 'journal', 'title' => 'How(ever)', 'field_journal_name' => 'however']);
    $this->however->save();
    $this->how2 = Node::create(['type' => 'journal', 'title' => 'How2', 'field_journal_name' => 'how2']);
    $this->how2->save();
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
   * Creates the legacy How(ever) volume and issue bundles with their fields.
   *
   * The field storages are the ones created in setUp().
   */
  protected function createLegacyBundles(): void {
    foreach (['however_volume', 'journal_issue'] as $type) {
      NodeType::create(['type' => $type, 'name' => $type])->save();
    }

    $legacy_fields = [
      'however_volume' => ['field_volume_number'],
      'journal_issue' => ['field_volume_number', 'field_issue_number', 'field_volume_reference', 'field_journal_page_images_media'],
    ];
    foreach ($legacy_fields as $bundle => $names) {
      foreach ($names as $name) {
        FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => $bundle])->save();
      }
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
   * Creates a unified volume in the given journal.
   */
  protected function createVolume(NodeInterface $journal, int $number, int $created): NodeInterface {
    return $this->createNode([
      'type' => 'volume',
      'field_volume_number' => $number,
      'field_journal' => $journal->id(),
      'created' => $created,
    ]);
  }

  /**
   * Creates a unified issue under the given volume.
   */
  protected function createIssue(NodeInterface $volume, int $number, int $created, array $extra = []): NodeInterface {
    return $this->createNode($extra + [
      'type' => 'issue',
      'field_volume_reference' => $volume->id(),
      'field_issue_number' => $number,
      'created' => $created,
    ]);
  }

  /**
   * Tests that children copy the journal from their parent on save.
   */
  public function testJournalIsCopiedFromParent(): void {
    $volume = $this->createVolume($this->however, 1, 100);
    $issue = $this->createIssue($volume, 1, 100);
    $this->assertEquals($this->however->id(), $issue->field_journal->target_id, 'Issue copies the journal from its volume.');

    $section = $this->createNode(['type' => 'section', 'field_issue_reference' => $issue->id()]);
    $this->assertEquals($this->however->id(), $section->field_journal->target_id, 'Section copies the journal from its issue.');

    $article = $this->createNode(['type' => 'article', 'field_issue_reference' => $issue->id()]);
    $this->assertEquals($this->however->id(), $article->field_journal->target_id, 'Article copies the journal from its issue.');

    $article_by_section = $this->createNode(['type' => 'article', 'field_section_reference' => $section->id()]);
    $this->assertEquals($this->however->id(), $article_by_section->field_journal->target_id, 'Article with only a section copies the journal from the section.');

    $article_by_volume = $this->createNode(['type' => 'article', 'field_volume_reference' => $volume->id()]);
    $this->assertEquals($this->however->id(), $article_by_volume->field_journal->target_id, 'Article with only a volume copies the journal from the volume.');
  }

  /**
   * Tests that a stored journal that disagrees with the parent is replaced.
   */
  public function testJournalFollowsParentOnResave(): void {
    $volume = $this->createVolume($this->however, 1, 100);
    $issue = $this->createIssue($volume, 1, 100, ['field_journal' => $this->how2->id()]);
    $this->assertEquals($this->however->id(), $issue->field_journal->target_id);

    $other_volume = $this->createVolume($this->how2, 1, 100);
    $issue->set('field_volume_reference', $other_volume->id());
    $issue->save();
    $this->assertEquals($this->how2->id(), $issue->field_journal->target_id, 'Moving an issue to another volume changes its journal on save.');
  }

  /**
   * Tests that a node without a parent keeps an empty journal.
   */
  public function testNodeWithoutParentHasNoJournal(): void {
    $article = $this->createNode(['type' => 'article']);
    $this->assertTrue($article->field_journal->isEmpty());
  }

  /**
   * Tests that volume and issue navigation does not cross journals.
   */
  public function testNavigationStaysWithinJournal(): void {
    $navigation = $this->container->get('however_customizations.publication_navigation');
    $now = time();

    $how2_volume_1 = $this->createVolume($this->how2, 1, $now - 500);
    $however_volume_1 = $this->createVolume($this->however, 1, $now - 400);
    $however_volume_2 = $this->createVolume($this->however, 2, $now - 300);

    $result = $navigation->getVolumeNavigation($however_volume_2);
    $this->assertEquals($however_volume_1->id(), $result['prev']->id(), 'Previous volume is in the same journal.');
    $this->assertNull($result['next']);

    $result = $navigation->getVolumeNavigation($however_volume_1);
    $this->assertEquals($however_volume_2->id(), $result['next']->id(), 'Next volume is in the same journal.');

    $result = $navigation->getVolumeNavigation($how2_volume_1);
    $this->assertNull($result['prev']);
    $this->assertNull($result['next'], 'A How2 volume does not link to a How(ever) volume.');

    $how2_issue = $this->createIssue($how2_volume_1, 6, $now - 200);
    $however_issue_a = $this->createIssue($however_volume_1, 4, $now - 100);
    $however_issue_b = $this->createIssue($however_volume_2, 1, $now - 50);

    $result = $navigation->getIssueNavigation($however_issue_b);
    $this->assertEquals($however_issue_a->id(), $result['prev']->id(), 'Previous issue is the last issue of the previous volume in the same journal.');

    $result = $navigation->getIssueNavigation($however_issue_a);
    $this->assertEquals($however_issue_b->id(), $result['next']->id(), 'Next issue is the first issue of the next volume in the same journal.');

    $result = $navigation->getIssueNavigation($how2_issue);
    $this->assertNull($result['prev']);
    $this->assertNull($result['next'], 'A How2 issue does not link to a How(ever) issue.');
  }

  /**
   * Tests the journal value stored on automatically created facsimiles.
   */
  public function testFacsimilesUseJournalMachineName(): void {
    $however_volume = $this->createVolume($this->however, 5, 100);
    $however_issue = $this->createIssue($however_volume, 3, 100, ['field_journal_page_images_media' => $however_volume->id()]);
    $facsimiles = $this->loadFacsimiles($however_issue);
    $this->assertCount(1, $facsimiles);
    $node = reset($facsimiles);
    $this->assertEquals('however', $node->field_journal_name->value);
    $this->assertEquals('However Volume 5 Issue 3: Page Facsimiles', $node->getTitle());
    $this->assertEquals($this->however->id(), $node->field_journal->target_id, 'Facsimile takes the journal from its issue.');

    $how2_volume = $this->createVolume($this->how2, 2, 100);
    $how2_issue = $this->createIssue($how2_volume, 1, 100, ['field_journal_page_images_media' => $how2_volume->id()]);
    $facsimiles = $this->loadFacsimiles($how2_issue);
    $this->assertCount(1, $facsimiles);
    $node = reset($facsimiles);
    $this->assertEquals('how2', $node->field_journal_name->value);
    $this->assertEquals($this->how2->id(), $node->field_journal->target_id);
  }

  /**
   * Tests that a facsimile of a legacy issue gets the journal from the bundle.
   */
  public function testLegacyIssueFacsimileGetsJournal(): void {
    $this->createLegacyBundles();

    $volume = $this->createNode(['type' => 'however_volume', 'field_volume_number' => 1]);
    $issue = $this->createNode([
      'type' => 'journal_issue',
      'field_volume_reference' => $volume->id(),
      'field_issue_number' => 7,
      'field_journal_page_images_media' => $volume->id(),
    ]);

    $facsimiles = $this->loadFacsimiles($issue);
    $this->assertCount(1, $facsimiles);
    $node = reset($facsimiles);
    $this->assertEquals('however', $node->field_journal_name->value);
    $this->assertEquals('However Volume 1 Issue 7: Page Facsimiles', $node->getTitle());
    $this->assertEquals($this->however->id(), $node->field_journal->target_id, 'Facsimile of a legacy issue takes the journal that matches the issue bundle.');
  }

  /**
   * Tests that a resaved facsimile follows its issue.
   */
  public function testFacsimileJournalFollowsIssueOnResave(): void {
    $volume = $this->createVolume($this->however, 5, 100);
    $issue = $this->createIssue($volume, 3, 100, ['field_journal_page_images_media' => $volume->id()]);
    $facsimiles = $this->loadFacsimiles($issue);
    $facsimile = reset($facsimiles);
    $facsimile->set('field_journal', $this->how2->id());
    $facsimile->save();
    $this->assertEquals($this->however->id(), $facsimile->field_journal->target_id);
  }

  /**
   * Tests that facsimiles are not created when the journal is unknown.
   */
  public function testFacsimilesSkippedWithoutJournal(): void {
    $volume = $this->createNode(['type' => 'volume', 'field_volume_number' => 9]);
    $issue = $this->createIssue($volume, 1, 100, ['field_journal_page_images_media' => $volume->id()]);
    $this->assertCount(0, $this->loadFacsimiles($issue));
  }

  /**
   * Tests that a unified issue gets its title on the first save.
   */
  public function testUnifiedIssueTitleIsGeneratedOnFirstSave(): void {
    $however_volume = $this->createVolume($this->however, 5, 100);
    $issue = $this->createIssue($however_volume, 3, 100);
    $this->assertEquals(5, $issue->field_volume_number->value, 'Volume number is copied from the volume.');
    $this->assertEquals('How(ever) Volume 5 Issue 3', $issue->getTitle());

    $how2_volume = $this->createVolume($this->how2, 2, 100);
    $issue = $this->createIssue($how2_volume, 1, 100);
    $this->assertEquals('How2 Volume 2 Issue 1', $issue->getTitle());
  }

  /**
   * Tests that a legacy issue gets its title on the first save.
   */
  public function testLegacyIssueTitleIsGeneratedOnFirstSave(): void {
    $this->createLegacyBundles();

    $volume = $this->createNode(['type' => 'however_volume', 'field_volume_number' => 1]);
    $this->assertEquals('How(ever) Volume 1', $volume->getTitle());

    $issue = $this->createNode([
      'type' => 'journal_issue',
      'field_volume_reference' => $volume->id(),
      'field_issue_number' => 7,
    ]);
    $this->assertEquals(1, $issue->field_volume_number->value, 'Volume number is copied from the volume.');
    $this->assertEquals('How(ever) Volume 1 Issue 7', $issue->getTitle());
  }

  /**
   * Loads the page_facsimiles nodes that reference an issue.
   */
  protected function loadFacsimiles(NodeInterface $issue): array {
    $ids = \Drupal::entityQuery('node')
      ->condition('type', 'page_facsimiles')
      ->condition('field_issue_reference', $issue->id())
      ->accessCheck(FALSE)
      ->execute();
    return Node::loadMultiple($ids);
  }

}
