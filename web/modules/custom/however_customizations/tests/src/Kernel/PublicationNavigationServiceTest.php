<?php

/**
 * @file
 * PublicationNavigationServiceTest.php
 *
 * To run this test:
 * 
 * 1. From the DDEV project root:
 *    ddev ssh
 *    cd web
 * 
 * 2. Run all the tests:
 *    SIMPLETEST_DB=mysql://db:db@db/db ../vendor/bin/phpunit -c ./core/phpunit.xml.dist ./modules/custom/however_customizations/tests/
 * 
 * 3. To run just this specific test:
 *    SIMPLETEST_DB=mysql://db:db@db/db ../vendor/bin/phpunit -c ./core/phpunit.xml.dist ./modules/custom/however_customizations/tests/src/Kernel/PublicationNavigationServiceTest.php
 * 
 * Requirements:
 * - DDEV environment
 * - Drupal 10 with testing dependencies
 */

namespace Drupal\Tests\however_customizations\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\node\Entity\NodeType;

/**
 * Tests the PublicationNavigationService.
 *
 * @group however_customizations
 */
class PublicationNavigationServiceTest extends KernelTestBase {

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
   * The navigation service.
   *
   * @var \Drupal\however_customizations\PublicationNavigationService
   */
  protected $navigationService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installConfig(['system']);
    $this->navigationService = $this->container->get('however_customizations.publication_navigation');

    // Create however_volume content type
    NodeType::create([
      'type' => 'however_volume',
      'name' => 'However Volume',
    ])->save();

    // Create field storage for volume number
    FieldStorageConfig::create([
      'field_name' => 'field_volume_number',
      'entity_type' => 'node',
      'type' => 'integer',
    ])->save();

    // Create field instance
    FieldConfig::create([
      'field_name' => 'field_volume_number',
      'entity_type' => 'node',
      'bundle' => 'however_volume',
    ])->save();
  }

  /**
   * Test basic service instantiation.
   */
  public function testServiceExists() {
    $this->assertInstanceOf(
      'Drupal\however_customizations\PublicationNavigationService', 
      $this->navigationService
    );
  }

  /**
   * Test volume navigation.
   */
  public function testVolumeNavigation()
  {
    // Create test volume nodes
    $volume1 = Node::create([
      'type' => 'however_volume',
      'title' => 'Volume 1',
      'field_volume_number' => 1,
      'status' => 1,
    ]);
    $volume1->save();

    $volume2 = Node::create([
      'type' => 'however_volume',
      'title' => 'Volume 2',
      'field_volume_number' => 2,
      'status' => 1,
    ]);
    $volume2->save();

    $volume3 = Node::create([
      'type' => 'however_volume',
      'title' => 'Volume 3',
      'field_volume_number' => 3,
      'status' => 1,
    ]);
    $volume3->save();

    // Test navigation for volume 2 (should have both prev and next)
    $navigation = $this->navigationService->getVolumeNavigation($volume2);

    $this->assertEquals($volume1->id(), $navigation['prev']->id());
    $this->assertEquals($volume3->id(), $navigation['next']->id());
  }
}
