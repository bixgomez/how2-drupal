<?php

namespace Drupal\however_customizations\Drush\Commands;

use Drupal\however_customizations\MigrationStage;
use Drupal\however_customizations\Service\LegacyNodeMigrator;
use Drupal\however_customizations\Service\MigrationMap;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\UserAbortException;

/**
 * Drush commands for migrating legacy publication nodes to the unified bundles.
 *
 * Every stage command needs exactly one mode flag: --dryrun, --execute, or
 * --rollback. Without a flag nothing happens. --rollback can be combined with
 * --dryrun to list what it would delete.
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
   * Choose one mode: --dryrun reports what would be created, --execute creates
   * the unified volumes and records them in the migration map, --rollback
   * deletes the unified volumes that were created. Legacy volumes that are
   * already migrated are skipped.
   *
   * @command however-customizations:migrate-volumes
   * @aliases how-migrate-volumes
   * @option dryrun Report what would be created (or, with --rollback, deleted); change nothing.
   * @option execute Create the unified volumes.
   * @option rollback Delete the unified volumes created by this command, after asking.
   * @option limit With --dryrun or --execute: process at most this many legacy volumes that are not yet migrated.
   * @usage drush how-migrate-volumes --dryrun
   *   Report the volumes that would be created.
   * @usage drush how-migrate-volumes --execute
   *   Create the unified volumes.
   * @usage drush how-migrate-volumes --rollback
   *   Delete the migrated volumes after confirmation.
   */
  public function migrateVolumes(array $options = ['dryrun' => FALSE, 'execute' => FALSE, 'rollback' => FALSE, 'limit' => NULL]): void {
    $this->runStage(MigrationStage::Volume, $options);
  }

  /**
   * Migrates the legacy issues to the unified issue bundle.
   *
   * Choose one mode: --dryrun reports what would be created, --execute creates
   * the unified issues, --rollback deletes them together with the facsimile
   * pages that belong to them. The volumes must be migrated first: each
   * issue's volume reference is replaced by the unified volume, and an issue
   * whose volume is not migrated is reported as blocked and is not created.
   * Titles are copied as they are, so custom issue titles are kept. Each
   * issue gets a facsimile page if its legacy issue has one. Section lists are
   * not set here; the links pass sets them.
   *
   * @command however-customizations:migrate-issues
   * @aliases how-migrate-issues
   * @option dryrun Report what would be created (or, with --rollback, deleted); change nothing.
   * @option execute Create the unified issues and their facsimile pages.
   * @option rollback Delete the unified issues created by this command and their facsimile pages, after asking.
   * @option limit With --dryrun or --execute: process at most this many legacy issues that are not yet migrated.
   * @usage drush how-migrate-issues --dryrun
   *   Report the issues that would be created.
   * @usage drush how-migrate-issues --execute
   *   Create the unified issues.
   * @usage drush how-migrate-issues --rollback
   *   Delete the migrated issues after confirmation.
   */
  public function migrateIssues(array $options = ['dryrun' => FALSE, 'execute' => FALSE, 'rollback' => FALSE, 'limit' => NULL]): void {
    $this->runStage(MigrationStage::Issue, $options);
  }

  /**
   * Migrates the legacy sections to the unified section bundle.
   *
   * Choose one mode: --dryrun reports what would be created, --execute creates
   * the unified sections, --rollback deletes them with their paragraphs. The
   * volumes and issues must be migrated first: a section's volume and issue
   * references are replaced by the unified nodes, and a section whose parent
   * is not migrated is reported as blocked and is not created. The piece
   * paragraphs are recreated as the unified types in the same order, but the
   * articles and subsections they refer to are left empty; the links pass
   * fills them. Titles and numbers are copied as they are.
   *
   * @command however-customizations:migrate-sections
   * @aliases how-migrate-sections
   * @option dryrun Report what would be created (or, with --rollback, deleted); change nothing.
   * @option execute Create the unified sections.
   * @option rollback Delete the unified sections created by this command, after asking.
   * @option limit With --dryrun or --execute: process at most this many legacy sections that are not yet migrated.
   * @usage drush how-migrate-sections --dryrun
   *   Report the sections that would be created.
   * @usage drush how-migrate-sections --execute
   *   Create the unified sections.
   * @usage drush how-migrate-sections --rollback
   *   Delete the migrated sections after confirmation.
   */
  public function migrateSections(array $options = ['dryrun' => FALSE, 'execute' => FALSE, 'rollback' => FALSE, 'limit' => NULL]): void {
    $this->runStage(MigrationStage::Section, $options);
  }

  /**
   * Migrates the legacy articles to the unified article bundle.
   *
   * Choose one mode: --dryrun reports what would be created, --execute creates
   * the unified articles, --rollback deletes them with their paragraphs. The
   * volumes, issues, and sections must be migrated first: an article's
   * volume, issue, and section references are replaced by the unified nodes,
   * and an article whose parent is not migrated is reported as blocked and is
   * not created. A reference to a node that no longer exists (a deleted
   * section) is dropped and noted in the report. The body sections and person
   * bios are duplicated. The presave hook runs, so the numbers are filled from
   * the migrated issue. The pieces of the sections that refer to articles are
   * filled later by the links pass.
   *
   * @command however-customizations:migrate-articles
   * @aliases how-migrate-articles
   * @option dryrun Report what would be created (or, with --rollback, deleted); change nothing.
   * @option execute Create the unified articles.
   * @option rollback Delete the unified articles created by this command, after asking.
   * @option limit With --dryrun or --execute: process at most this many legacy articles that are not yet migrated.
   * @usage drush how-migrate-articles --dryrun
   *   Report the articles that would be created.
   * @usage drush how-migrate-articles --execute
   *   Create the unified articles.
   * @usage drush how-migrate-articles --rollback
   *   Delete the migrated articles after confirmation.
   */
  public function migrateArticles(array $options = ['dryrun' => FALSE, 'execute' => FALSE, 'rollback' => FALSE, 'limit' => NULL]): void {
    $this->runStage(MigrationStage::Article, $options);
  }

  /**
   * Works out which mode the command options select.
   *
   * @param array $options
   *   The command options.
   *
   * @return string
   *   'dryrun', 'execute', 'rollback', or 'rollback-dryrun' (a rollback that
   *   only lists what it would delete).
   *
   * @throws \InvalidArgumentException
   *   If no mode is chosen, or the chosen flags cannot be combined.
   */
  public static function parseMode(array $options): string {
    $dryrun = self::isTruthy($options['dryrun'] ?? FALSE);
    $execute = self::isTruthy($options['execute'] ?? FALSE);
    $rollback = self::isTruthy($options['rollback'] ?? FALSE);
    $limit = isset($options['limit']) && $options['limit'] !== '';

    if (!$dryrun && !$execute && !$rollback) {
      throw new \InvalidArgumentException('Choose one of --dryrun, --execute, or --rollback. Nothing was changed.');
    }
    if ($execute && ($dryrun || $rollback)) {
      throw new \InvalidArgumentException('--execute cannot be combined with --dryrun or --rollback.');
    }
    if ($rollback && $limit) {
      throw new \InvalidArgumentException('--limit cannot be used with --rollback.');
    }

    if ($rollback) {
      return $dryrun ? 'rollback-dryrun' : 'rollback';
    }
    return $execute ? 'execute' : 'dryrun';
  }

  /**
   * Runs one stage in the mode the options select.
   *
   * @param \Drupal\however_customizations\MigrationStage $stage
   *   The migration stage.
   * @param array $options
   *   The command options.
   */
  protected function runStage(MigrationStage $stage, array $options): void {
    $mode = self::parseMode($options);

    if ($mode === 'dryrun' || $mode === 'execute') {
      $limit = isset($options['limit']) && $options['limit'] !== '' ? (int) $options['limit'] : NULL;
      $this->migrateStage($stage, $mode === 'execute', $limit);
      return;
    }

    $this->rollbackStage($stage, $mode === 'rollback-dryrun');
  }

  /**
   * Migrates one stage and prints a report.
   *
   * @param \Drupal\however_customizations\MigrationStage $stage
   *   The migration stage.
   * @param bool $execute
   *   TRUE to create the nodes; FALSE for a dry run.
   * @param int|null $limit
   *   The most legacy nodes to process, or NULL for no limit.
   */
  protected function migrateStage(MigrationStage $stage, bool $execute, ?int $limit): void {
    $result = $this->migrator->migrate($stage, $execute, $limit);

    $rows = [];
    foreach ($result['rows'] as $row) {
      $rows[] = [$row['action'], $row['old_nid'], $row['bundle'], $row['title'], $row['new_nid'] ?? '', $row['note']];
    }
    $this->io()->table(['Action', 'Legacy nid', 'Legacy bundle', 'Title', 'Unified nid', 'Note'], $rows);

    $counts = $result['counts'];
    $this->io()->writeln(sprintf('%s: %d created, %d would be created, %d already migrated, %d blocked.', $stage->value, $counts['created'], $counts['would_create'], $counts['skipped'], $counts['blocked']));
    if (!$execute && $counts['would_create']) {
      $this->io()->note('Dry run. Nothing was changed. Run again with --execute to create the unified nodes.');
    }
    if ($counts['blocked']) {
      throw new \RuntimeException(sprintf('%d legacy nodes are blocked because a parent is not migrated. Migrate the earlier stage first.', $counts['blocked']));
    }
  }

  /**
   * Rolls back one stage: lists or deletes the unified nodes it created.
   *
   * Only unified nodes recorded in the migration map are deleted; legacy nodes
   * are never touched. Deleting a unified issue also deletes the facsimile
   * pages that point at it. A stage cannot be rolled back while a later stage
   * is still mapped.
   *
   * @param \Drupal\however_customizations\MigrationStage $stage
   *   The migration stage.
   * @param bool $dryrun
   *   TRUE to only report what would be deleted.
   *
   * @throws \Drush\Exceptions\UserAbortException
   *   If the user does not confirm the deletion.
   */
  protected function rollbackStage(MigrationStage $stage, bool $dryrun): void {
    $preview = $this->map->rollback($stage, FALSE);
    $this->io()->table(['Stage', 'Recorded', 'Already gone'], [[$stage->value, $preview['mapped'], $preview['already_gone']]]);

    if ($dryrun) {
      $this->io()->note(sprintf('Dry run. Nothing was changed. A rollback would delete %d unified %s node(s)%s.', $preview['mapped'] - $preview['already_gone'], $stage->value, $stage === MigrationStage::Issue ? ' and their facsimile pages' : ''));
      return;
    }
    if (!$preview['mapped']) {
      $this->io()->note(sprintf('The %s stage has nothing to roll back.', $stage->value));
      return;
    }

    $question = sprintf('Delete %d unified %s node(s)%s? The legacy nodes are not touched.', $preview['mapped'] - $preview['already_gone'], $stage->value, $stage === MigrationStage::Issue ? ' and their facsimile pages' : '');
    if (!$this->io()->confirm($question)) {
      throw new UserAbortException();
    }

    $result = $this->map->rollback($stage, TRUE);
    $this->io()->success(sprintf('Rolled back the %s stage: %d deleted, %d were already gone.', $stage->value, $result['deleted'], $result['already_gone']));
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
  protected static function isTruthy(mixed $value): bool {
    return !in_array(strtolower((string) $value), ['', '0', 'false'], TRUE);
  }

}
