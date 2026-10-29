<?php

/**
 * @file
 * LegacyNodeAccessTest.php
 *
 * To run this test:
 *
 * 1. From the DDEV project root:
 *    ddev ssh
 *    cd web
 *
 * 2. Run this test:
 *    SIMPLETEST_DB=mysql://db:db@db/db ../vendor/bin/phpunit -c ./core/phpunit.xml.dist ./modules/custom/however_customizations/tests/src/Kernel/LegacyNodeAccessTest.php
 */

namespace Drupal\Tests\however_customizations\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the legacy nodes are for administrators only.
 */
#[Group('however_customizations')]
#[RunTestsInSeparateProcesses]
class LegacyNodeAccessTest extends KernelTestBase {

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

    foreach (['how2_article', 'article'] as $type) {
      NodeType::create(['type' => $type, 'name' => $type])->save();
    }
    // Uid 1 is the superuser; take it out of the way.
    User::create(['name' => 'root', 'status' => 1])->save();
  }

  /**
   * Creates a user with the given permissions.
   */
  protected function user(string $name, array $permissions): User {
    $role = Role::create(['id' => $name, 'label' => $name]);
    $role->save();
    user_role_grant_permissions($role->id(), $permissions);
    $user = User::create(['name' => $name, 'status' => 1, 'roles' => [$role->id()]]);
    $user->save();
    return $user;
  }

  /**
   * Tests who may do what with a legacy node and a unified node.
   */
  public function testLegacyNodesAreForAdministratorsOnly(): void {
    $editor_permissions = [
      'access content',
      'view own unpublished content',
      'edit any how2_article content',
      'delete any how2_article content',
      'edit any article content',
      'delete any article content',
    ];
    $editor = $this->user('editor', $editor_permissions);
    $admin = $this->user('admin', ['access content', 'bypass node access']);

    // Published and unpublished legacy nodes, one authored by the editor.
    $legacy = Node::create(['type' => 'how2_article', 'title' => 'Old', 'status' => 1, 'uid' => $editor->id()]);
    $legacy->save();
    $legacy_draft = Node::create(['type' => 'how2_article', 'title' => 'Old draft', 'status' => 0, 'uid' => $editor->id()]);
    $legacy_draft->save();
    $unified = Node::create(['type' => 'article', 'title' => 'New', 'status' => 1, 'uid' => $editor->id()]);
    $unified->save();

    foreach (['view', 'update', 'delete'] as $operation) {
      $this->assertFalse($legacy->access($operation, $editor), "Editors cannot $operation a published legacy node.");
      $this->assertFalse($legacy_draft->access($operation, $editor), "Editors cannot $operation their own unpublished legacy node.");
      $this->assertTrue($legacy->access($operation, $admin), "Administrators can $operation a legacy node.");
    }

    foreach (['view', 'update', 'delete'] as $operation) {
      $this->assertTrue($unified->access($operation, $editor), "Editors can $operation a unified node.");
    }
  }

  /**
   * Tests that anonymous users and Drush are not affected.
   */
  public function testAnonymousIsNotAffected(): void {
    user_role_grant_permissions('anonymous', ['access content']);
    $legacy = Node::create(['type' => 'how2_article', 'title' => 'Old', 'status' => 1]);
    $legacy->save();
    $anonymous = User::getAnonymousUser();
    $this->assertTrue($legacy->access('view', $anonymous), 'A published legacy node is still public until it is unpublished.');
  }

}
