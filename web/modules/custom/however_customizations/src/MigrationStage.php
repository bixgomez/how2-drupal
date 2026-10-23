<?php

namespace Drupal\however_customizations;

/**
 * The stages of the legacy-to-unified content migration, in dependency order.
 *
 * A stage's nodes can only be created once the nodes of the stages before it
 * exist, and can only be removed once the nodes of the stages after it are
 * gone.
 */
enum MigrationStage: string {

  case Volume = 'volume';
  case Issue = 'issue';
  case Section = 'section';
  case Article = 'article';

  /**
   * Gets the stages that depend on this stage's nodes.
   *
   * @return \Drupal\however_customizations\MigrationStage[]
   *   The stages that run after this one, in dependency order.
   */
  public function laterStages(): array {
    $cases = self::cases();
    return array_slice($cases, array_search($this, $cases, TRUE) + 1);
  }

}
