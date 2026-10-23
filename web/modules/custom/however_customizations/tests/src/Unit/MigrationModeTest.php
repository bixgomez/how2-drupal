<?php

/**
 * @file
 * MigrationModeTest.php
 *
 * To run this test:
 *
 * 1. From the DDEV project root:
 *    ddev ssh
 *    cd web
 *
 * 2. Run this test:
 *    ../vendor/bin/phpunit -c ./core/phpunit.xml.dist ./modules/custom/however_customizations/tests/src/Unit/MigrationModeTest.php
 */

namespace Drupal\Tests\however_customizations\Unit;

use Drupal\however_customizations\Drush\Commands\ConsolidationMigrationCommands;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the mode flags of the migration commands.
 */
#[Group('however_customizations')]
class MigrationModeTest extends UnitTestCase {

  /**
   * Tests the flag combinations that select a mode.
   */
  #[DataProvider('validProvider')]
  public function testValidCombinations(array $options, string $expected): void {
    $this->assertSame($expected, ConsolidationMigrationCommands::parseMode($options));
  }

  /**
   * Data provider for the valid combinations.
   */
  public static function validProvider(): array {
    return [
      'dryrun' => [['dryrun' => TRUE], 'dryrun'],
      'execute' => [['execute' => TRUE], 'execute'],
      'rollback' => [['rollback' => TRUE], 'rollback'],
      'rollback with dryrun' => [['rollback' => TRUE, 'dryrun' => TRUE], 'rollback-dryrun'],
      'dryrun with limit' => [['dryrun' => TRUE, 'limit' => '3'], 'dryrun'],
      'execute with limit' => [['execute' => TRUE, 'limit' => '3'], 'execute'],
      'false flags are off' => [['dryrun' => TRUE, 'execute' => FALSE, 'rollback' => FALSE], 'dryrun'],
      'string zero is off' => [['dryrun' => TRUE, 'execute' => '0'], 'dryrun'],
    ];
  }

  /**
   * Tests the flag combinations that are refused.
   */
  #[DataProvider('invalidProvider')]
  public function testInvalidCombinations(array $options, string $message): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage($message);

    ConsolidationMigrationCommands::parseMode($options);
  }

  /**
   * Data provider for the refused combinations.
   */
  public static function invalidProvider(): array {
    return [
      'no flag' => [[], 'Choose one of'],
      'all flags off' => [['dryrun' => FALSE, 'execute' => FALSE, 'rollback' => FALSE], 'Choose one of'],
      'limit alone' => [['limit' => '2'], 'Choose one of'],
      'execute with dryrun' => [['execute' => TRUE, 'dryrun' => TRUE], 'cannot be combined'],
      'execute with rollback' => [['execute' => TRUE, 'rollback' => TRUE], 'cannot be combined'],
      'rollback with limit' => [['rollback' => TRUE, 'limit' => '2'], '--limit cannot be used'],
    ];
  }

}
