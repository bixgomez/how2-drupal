<?php

namespace Drupal\Tests\however_customizations\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;

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
}
