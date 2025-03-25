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
        // Try different patterns to extract entity ID
        $entity_id = NULL;
        
        // Standard pattern: "Label (123)"
        if (preg_match('/\((\d+)\)$/', $match['value'], $matches_id)) {
          $entity_id = $matches_id[1];
        }
        // Alternative: might be embedded differently
        elseif (preg_match('/\[nid:(\d+)\]/', $match['value'], $matches_id)) {
          $entity_id = $matches_id[1];
        }
        
        if ($entity_id) {
          // Load the node to get additional info
          $node = $this->entityTypeManager->getStorage('node')->load($entity_id);
          if ($node) {
            // Add node type and created date to the label
            $created = \Drupal::service('date.formatter')->format($node->getCreatedTime(), 'short');
            
            // Update the match label to include additional info
            // Keep the original format for selection
            $original_label = preg_replace('/\s*\(\d+\)$/', '', $match['label']);
            $original_label = preg_replace('/\s*\[nid:\d+\]$/', '', $original_label);
            
            // Add our custom info while preserving the entity ID format
            if (strpos($match['label'], '(') !== FALSE) {
              $match['label'] = $original_label . ' [' . $node->bundle() . ' - ' . $created . '] (' . $entity_id . ')';
            } 
            elseif (strpos($match['label'], '[nid:') !== FALSE) {
              $match['label'] = $original_label . ' [' . $node->bundle() . ' - ' . $created . '] [nid:' . $entity_id . ']';
            }
            else {
              // Some other format, just append our info
              $match['label'] = $match['label'] . ' [' . $node->bundle() . ' - ' . $created . ']';
            }
          }
        }
      }
    }

    return $matches;
  }

}