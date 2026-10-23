<?php

namespace Drupal\however_customizations\Drush\Commands;

use Drupal\however_customizations\MigrationStage;
use Drupal\however_customizations\Service\LegacyNodeMigrator;
use Drupal\however_customizations\Service\MigrationMap;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for migrating legacy publication nodes to the unified bundles.
 */
class ConsolidationMigrationCommands extends DrushCommands {

  /**
   * Constructs a ConsolidationMigrationCommands object.
   *
   * @param \Drupal\however_customizations\Service\LegacyNodeMigrator $migrator
   *   The legacy node migrator.
   * @param \Drupal\however_customizations\Service\MigrationMap $map
   *   The migration map.
   */
  public function __construct(
    protected LegacyNodeMigrator $migrator,
    protected MigrationMap $map,
  ) {
    parent::__construct();
  }

  /**
   * Migrates the legacy volumes to the unified volume bundle.
   *
   * Without --apply the command only reports what it would create. Legacy
   * volumes that are already migrated are skipped.
   *
   * @command however-customizations:migrate-volumes
   * @aliases how-migrate-volumes
   * @option apply Create the unified volumes and record them in the migration map.
   * @option limit Process at most this many legacy volumes that are not yet migrated.
   * @usage drush how-migrate-volumes
   *   Report the volumes that would be created.
   * @usage drush how-migrate-volumes --apply
   *   Create the unified volumes.
   */
  public function migrateVolumes(array $options = ['apply' => FALSE, 'limit' => NULL]): void {
    $this->migrateStage(MigrationStage::Volume, $options);
  }

  /**
   * Rolls back a migration stage by deleting the unified nodes it created.
   *
   * Only unified nodes recorded in the migration map are deleted; legacy nodes
   * are never touched. Without --apply the command only reports what it would
   * delete. A stage cannot be rolled back while a later stage is still mapped.
   *
   * @command however-customizations:migrate-rollback
   * @aliases how-migrate-rollback
   * @param string $stage The stage to roll back: volume, issue, section, or article.
   * @option apply Delete the unified nodes and their migration map rows.
   * @usage drush how-migrate-rollback volume
   *   Report the volumes that would be deleted.
   * @usage drush how-migrate-rollback volume --apply
   *   Delete the migrated volumes.
   */
  public function rollback(string $stage, array $options = ['apply' => FALSE]): void {
    $migration_stage = MigrationStage::tryFrom($stage);
    if (!$migration_stage) {
      throw new \InvalidArgumentException(sprintf('Unknown stage "%s". Use volume, issue, section, or article.', $stage));
    }

    $apply = $this->isTruthy($options['apply'] ?? FALSE);
    $result = $this->map->rollback($migration_stage, $apply);

    $this->io()->table(['Stage', 'Recorded', 'Deleted', 'Already gone'], [[
      $migration_stage->value,
      $result['mapped'],
      $result['deleted'],
      $result['already_gone'],
    ]]);
    if ($apply) {
      $this->io()->success(sprintf('Rolled back the %s stage.', $migration_stage->value));
    }
    else {
      $this->io()->note('Dry run. Run again with --apply to delete the unified nodes.');
    }
  }

  /**
   * Runs one stage and prints a report.
   *
   * @param \Drupal\however_customizations\MigrationStage $stage
   *   The migration stage.
   * @param array $options
   *   The command options.
   */
  protected function migrateStage(MigrationStage $stage, array $options): void {
    $apply = $this->isTruthy($options['apply'] ?? FALSE);
    $limit = isset($options['limit']) && $options['limit'] !== '' ? (int) $options['limit'] : NULL;

    $result = $this->migrator->migrate($stage, $apply, $limit);

    $rows = [];
    foreach ($result['rows'] as $row) {
      $rows[] = [$row['action'], $row['old_nid'], $row['bundle'], $row['title'], $row['new_nid'] ?? ''];
    }
    $this->io()->table(['Action', 'Legacy nid', 'Legacy bundle', 'Title', 'Unified nid'], $rows);

    $counts = $result['counts'];
    $this->io()->writeln(sprintf('%s: %d created, %d would be created, %d already migrated.', $stage->value, $counts['created'], $counts['would_create'], $counts['skipped']));
    if (!$apply && $counts['would_create']) {
      $this->io()->note('Dry run. Run again with --apply to create the unified nodes.');
    }
  }

  /**
   * Converts a command option to a boolean.
   *
   * @param mixed $value
   *   The option value.
   *
   * @return bool
   *   TRUE for any value that is not empty, "0", or "false".
   */
  protected function isTruthy(mixed $value): bool {
    return !in_array(strtolower((string) $value), ['', '0', 'false'], TRUE);
  }

}
