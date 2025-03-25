<?php

namespace Drupal\however_customizations;

use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityAutocompleteMatcher as CoreEntityAutocompleteMatcher;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extends the core EntityAutocompleteMatcher to customize autocomplete results.
 */
class EntityAutocompleteMatcher extends CoreEntityAutocompleteMatcher {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(SelectionPluginManagerInterface $selection_manager) {
    parent::__construct($selection_manager);
    $this->entityTypeManager = \Drupal::entityTypeManager();
  }

  /**
   * {@inheritdoc}
   */
  public function getMatches($target_type, $selection_handler, $selection_settings, $string = '') {
    $matches = parent::getMatches($target_type, $selection_handler, $selection_settings, $string);

    // Only enhance node matches
    if ($target_type === 'node') {
      foreach ($matches as &$match) {
        // Extract the entity ID from the match value
        preg_match('/\((\d+)\)$/', $match['value'], $matches_id);
        if (!empty($matches_id[1])) {
          $entity_id = $matches_id[1];
          
          // Load the node to get additional info
          $node = $this->entityTypeManager->getStorage('node')->load($entity_id);
          if ($node) {
            // Add node type and created date to the label
            $created = \Drupal::service('date.formatter')->format($node->getCreatedTime(), 'short');
            
            // Update the match label to include additional info
            // Keep the entity ID in parentheses at the end for selection
            $original_label = preg_replace('/\s*\(\d+\)$/', '', $match['label']);
            $match['label'] = $original_label . ' [' . $node->bundle() . ' - ' . $created . '] (' . $entity_id . ')';
          }
        }
      }
    }

    return $matches;
  }

}