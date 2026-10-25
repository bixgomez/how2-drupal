<?php

/**
 * @file
 * LegacyNodeRedirectSubscriberTest.php
 *
 * To run this test:
 *
 * 1. From the DDEV project root:
 *    ddev ssh
 *    cd web
 *
 * 2. Run this test:
 *    SIMPLETEST_DB=mysql://db:db@db/db ../vendor/bin/phpunit -c ./core/phpunit.xml.dist ./modules/custom/however_customizations/tests/src/Kernel/LegacyNodeRedirectSubscriberTest.php
 */

namespace Drupal\Tests\however_customizations\Kernel;

use Drupal\however_customizations\MigrationStage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\path_alias\Entity\PathAlias;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests the redirect from legacy node paths to the unified nodes.
 */
#[Group('however_customizations')]
#[RunTestsInSeparateProcesses]
class LegacyNodeRedirectSubscriberTest extends KernelTestBase {

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
    'path',
    'path_alias',
  ];

  /**
   * The legacy node.
   */
  protected NodeInterface $old;

  /**
   * The unified node created from the legacy node.
   */
  protected NodeInterface $new;

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
    $this->installConfig(['system']);

    foreach (['however_volume', 'volume', 'page'] as $type) {
      NodeType::create(['type' => $type, 'name' => $type])->save();
    }
    $this->old = Node::create(['type' => 'however_volume', 'title' => 'Old', 'status' => 1]);
    $this->old->save();
    $this->new = Node::create(['type' => 'volume', 'title' => 'New', 'status' => 1]);
    $this->new->save();
    $this->container->get('however_customizations.migration_map')
      ->record(MigrationStage::Volume, $this->old, $this->new);
  }

  /**
   * Sends a request through the subscriber.
   *
   * @return \Symfony\Component\HttpFoundation\Response|null
   *   The response the subscriber set, or NULL if it did nothing.
   */
  protected function dispatch(string $uri, string $method = 'GET', int $type = HttpKernelInterface::MAIN_REQUEST) {
    $event = new RequestEvent($this->container->get('kernel'), Request::create($uri, $method), $type);
    $this->container->get('however_customizations.legacy_node_redirect_subscriber')->onRequest($event);
    return $event->getResponse();
  }

  /**
   * Turns the redirects on.
   */
  protected function enable(int $status = 302): void {
    $this->config('however_customizations.settings')
      ->set('redirect_old_nids', TRUE)
      ->set('redirect_status', $status)
      ->save();
  }

  /**
   * Tests that nothing is redirected until the setting is turned on.
   */
  public function testOffByDefault(): void {
    $this->assertNull($this->dispatch('/node/' . $this->old->id()));

    $this->config('however_customizations.settings')->set('redirect_old_nids', FALSE)->save();
    $this->assertNull($this->dispatch('/node/' . $this->old->id()));
  }

  /**
   * Tests that a migrated legacy node path goes to its unified node.
   */
  public function testMappedNodeRedirects(): void {
    $this->enable();
    $response = $this->dispatch('/node/' . $this->old->id());

    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertSame(302, $response->getStatusCode());
    $this->assertSame('/node/' . $this->new->id(), $response->getTargetUrl());
  }

  /**
   * Tests the status code and that the query string is kept.
   */
  public function testStatusCodeAndQuery(): void {
    $this->enable(301);
    $response = $this->dispatch('/node/' . $this->old->id() . '?a=1&b=2');

    $this->assertSame(301, $response->getStatusCode());
    $this->assertSame('/node/' . $this->new->id() . '?a=1&b=2', $response->getTargetUrl());

    // Anything other than 301 falls back to 302.
    $this->enable(500);
    $this->assertSame(302, $this->dispatch('/node/' . $this->old->id())->getStatusCode());
  }

  /**
   * Tests the paths and requests that must be left alone.
   */
  public function testOtherRequestsAreLeftAlone(): void {
    $this->enable();
    $old = $this->old->id();

    // A node that is not in the map, such as a basic page.
    $page = Node::create(['type' => 'page', 'title' => 'About', 'status' => 1]);
    $page->save();
    $this->assertNull($this->dispatch('/node/' . $page->id()));

    // The unified node itself, and the legacy node's other paths.
    $this->assertNull($this->dispatch('/node/' . $this->new->id()));
    $this->assertNull($this->dispatch("/node/$old/edit"));
    $this->assertNull($this->dispatch("/node/$old/delete"));
    $this->assertNull($this->dispatch('/old/however/v1'));

    // Not a plain read, or not the main request.
    $this->assertNull($this->dispatch("/node/$old", 'POST'));
    $this->assertNull($this->dispatch("/node/$old", 'GET', HttpKernelInterface::SUB_REQUEST));
  }

  /**
   * Tests that a unified node is sent on to its alias, when it has one.
   */
  public function testUnifiedNodeGoesToItsAlias(): void {
    $this->enable();
    $this->assertNull($this->dispatch('/node/' . $this->new->id()), 'Without an alias there is nothing to go to.');

    PathAlias::create(['path' => '/node/' . $this->new->id(), 'alias' => '/how2/v1/new', 'langcode' => 'en'])->save();
    $response = $this->dispatch('/node/' . $this->new->id() . '?a=1');
    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertSame(302, $response->getStatusCode());
    $this->assertSame('/how2/v1/new?a=1', $response->getTargetUrl());

    // A node that is not part of the migration is left alone, alias or not.
    $page = Node::create(['type' => 'page', 'title' => 'About', 'status' => 1]);
    $page->save();
    PathAlias::create(['path' => '/node/' . $page->id(), 'alias' => '/about', 'langcode' => 'en'])->save();
    $this->assertNull($this->dispatch('/node/' . $page->id()));

    $this->config('however_customizations.settings')->set('redirect_old_nids', FALSE)->save();
    $this->assertNull($this->dispatch('/node/' . $this->new->id()));
  }

  /**
   * Tests that removing the map row stops the redirect.
   */
  public function testRollbackStopsTheRedirect(): void {
    $this->enable();
    $this->assertNotNull($this->dispatch('/node/' . $this->old->id()));

    $this->container->get('database')
      ->delete('however_migration_map')
      ->condition('old_nid', $this->old->id())
      ->execute();
    $this->assertNull($this->dispatch('/node/' . $this->old->id()));
  }

}
