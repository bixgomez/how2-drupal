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
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    SelectionPluginManagerInterface $selection_manager
  ) {
    // Pass both arguments to the parent.
    parent::__construct($entity_type_manager, $selection_manager);
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
            // Get the volume reference value
            $volume_value = '';
            if ($node->hasField('field_volume_reference') && !$node->get('field_volume_reference')->isEmpty()) {
              $volume_field = $node->get('field_volume_reference');
              // Check if this is an entity reference field
              if ($volume_field->entity) {
                $volume_value = $volume_field->entity->label();
              } 
              // Or a simple value field
              else {
                $volume_value = $volume_field->value;
              }
            }
            
            // Get the issue reference value
            $issue_value = '';
            if ($node->hasField('field_issue_reference') && !$node->get('field_issue_reference')->isEmpty()) {
              $issue_field = $node->get('field_issue_reference');
              // Check if this is an entity reference field
              if ($issue_field->entity) {
                $issue_value = $issue_field->entity->label();
              } 
              // Or a simple value field
              else {
                $issue_value = $issue_field->value;
              }
            }
            
            // Build the additional info string
            $additional_info = $node->bundle();
            
            // Add volume and issue if available
            if (!empty($volume_value) || !empty($issue_value)) {
              $additional_info .= ' - ';
              if (!empty($volume_value)) {
                $additional_info .= 'Vol: ' . $volume_value;
              }
              if (!empty($volume_value) && !empty($issue_value)) {
                $additional_info .= ', ';
              }
              if (!empty($issue_value)) {
                $additional_info .= 'Issue: ' . $issue_value;
              }
            }
            
            // Update the match label to include additional info
            $original_label = preg_replace('/\s*\(\d+\)$/', '', $match['label']);
            $match['label'] = $original_label . ' [' . $additional_info . '] (' . $entity_id . ')';
          }
        }
      }
    }

    return $matches;
  }

}