<?php

namespace Drupal\however_customizations\Commands;

use Drush\Commands\DrushCommands;
use Drupal\node\Entity\Node;

/**
 * Drush commands for However Customizations.
 */
class HoweverCustomizationsCommands extends DrushCommands {

  /**
   * Updates volume numbers for how2_issue and journal_issue nodes.
   *
   * @command however-customizations:update-volume-numbers
   * @aliases how-vol
   */
  public function updateVolumeNumbers() {
    $this->output()->writeln('Starting volume number update...');
    
    // Content types to process
    $content_types = ['how2_issue', 'journal_issue'];
    $count = 0;
    
    foreach ($content_types as $content_type) {
      // Load all nodes of this type
      $query = \Drupal::entityQuery('node')
        ->condition('type', $content_type)
        ->accessCheck(FALSE);
      $nids = $query->execute();
      
      if (!empty($nids)) {
        $this->output()->writeln("Processing {$content_type}: " . count($nids) . " nodes found.");
        
        // Process nodes in smaller batches to avoid memory issues
        $chunks = array_chunk($nids, 50, TRUE);
        
        foreach ($chunks as $chunk) {
          $nodes = Node::loadMultiple($chunk);
          
          foreach ($nodes as $node) {
            // Skip if no volume reference
            if ($node->field_volume_reference->isEmpty()) {
              continue;
            }
            
            // Get referenced volume
            $volume_reference = $node->field_volume_reference->entity;
            
            if ($volume_reference && 
                $volume_reference->hasField('field_volume_number') && 
                !$volume_reference->field_volume_number->isEmpty()) {
              
              // Get and set volume number
              $volume_number = $volume_reference->field_volume_number->value;
              $node->field_volume_number->value = $volume_number;
              
              // Save node but skip the hook we created earlier
              $node->however_skip_presave = TRUE;
              $node->save();
              $count++;
            }
          }
        }
      }
    }
    
    $this->output()->writeln("Update complete. Updated $count nodes.");
  }
}