<?php

namespace Drupal\however_customizations;

use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityAutocompleteMatcher as CoreEntityAutocompleteMatcher;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extends the core EntityAutocompleteMatcher to customize autocomplete results.
 */
class EntityAutocompleteMatcher extends CoreEntityAutocompleteMatcher
{

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
    SelectionPluginManagerInterface $selection_manager,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    // Pass both arguments to the parent.
    $this->entityTypeManager = $entity_type_manager;
    parent::__construct($selection_manager, $entity_type_manager);
  }

  /**
   * {@inheritdoc}
   */
  public function getMatches($target_type, $selection_handler, $selection_settings, $string = '')
  {
    $matches = parent::getMatches($target_type, $selection_handler, $selection_settings, $string);

    // Only enhance node matches
    if ($target_type === 'node') {
      $node_storage = $this->entityTypeManager->getStorage('node');

      foreach ($matches as &$match) {
        // Extract the entity ID from the match value
        if (preg_match('/\((\d+)\)$/', $match['value'], $matches_id)) {
          $entity_id = $matches_id[1];
          $node = $node_storage->load($entity_id);

          if ($node) {
            $additional_info = $node->bundle();
            $volume_value = $this->getFieldValue($node, 'field_volume_reference');
            $issue_value = $this->getFieldValue($node, 'field_issue_reference');

            // Add volume and issue if available
            if ($volume_value || $issue_value) {
              $parts = [];
              if ($volume_value) $parts[] = 'Vol: ' . $volume_value;
              if ($issue_value) $parts[] = 'Issue: ' . $issue_value;
              $additional_info .= ' - ' . implode(', ', $parts);
            }

            // Update the match label
            $original_label = preg_replace('/\s*\(\d+\)$/', '', $match['label']);
            $match['label'] = $original_label . ' [' . $additional_info . '] (' . $entity_id . ')';
          }
        }
      }
    }

    return $matches;
  }

  /**
   * Helper to get field value (entity reference or simple value).
   */
  private function getFieldValue($node, $field_name)
  {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return '';
    }

    $field = $node->get($field_name);
    return $field->entity ? $field->entity->label() : $field->value;
  }
}
