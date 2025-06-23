<?php

namespace Drupal\however_customizations\Drush\Commands;

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

  /**
   * Updates titles for all content types with auto-generated titles.
   *
   * @command however-customizations:update-titles
   * @aliases how-titles
   */
  public function updateTitles() {
    $this->output()->writeln('Starting title update...');
    
    // Content types to process with their respective fields
    $content_mappings = [
      'however_volume' => [
        'fields' => ['field_volume_number'],
        'title_pattern' => 'How(ever) Volume {volume}',
      ],
      'how2_volume' => [
        'fields' => ['field_volume_number'],
        'title_pattern' => 'How2 Volume {volume}',
      ],
      'journal_issue' => [
        'fields' => ['field_volume_number', 'field_issue_number'],
        'title_pattern' => 'How(ever) Volume {volume} Issue {issue}',
      ],
      'how2_issue' => [
        'fields' => ['field_volume_number', 'field_issue_number'],
        'title_pattern' => 'How2 Volume {volume} Issue {issue}',
      ],
    ];
    
    $total_updated = 0;
    
    foreach ($content_mappings as $content_type => $mapping) {
      // Load all nodes of this type
      $query = \Drupal::entityQuery('node')
        ->condition('type', $content_type)
        ->accessCheck(FALSE);
      $nids = $query->execute();
      
      if (!empty($nids)) {
        $this->output()->writeln("Processing {$content_type}: " . count($nids) . " nodes found.");
        $count = 0;
        
        // Process nodes in smaller batches to avoid memory issues
        $chunks = array_chunk($nids, 50, TRUE);
        
        foreach ($chunks as $chunk) {
          $nodes = Node::loadMultiple($chunk);
          
          foreach ($nodes as $node) {
            $fields_exist = TRUE;
            $replacements = [];
            
            // Check if all required fields exist and have values
            foreach ($mapping['fields'] as $field) {
              if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
                $fields_exist = FALSE;
                break;
              }
              
              // Store field values for replacement
              if ($field === 'field_volume_number') {
                $replacements['{volume}'] = $node->get($field)->value;
              } elseif ($field === 'field_issue_number') {
                $replacements['{issue}'] = $node->get($field)->value;
              }
            }
            
            if ($fields_exist) {
              // Create title by replacing placeholders
              $title = $mapping['title_pattern'];
              foreach ($replacements as $placeholder => $value) {
                $title = str_replace($placeholder, $value, $title);
              }
              
              // Only update if title has changed
              if ($node->getTitle() !== $title) {
                $node->setTitle($title);
                $node->however_skip_presave = TRUE;
                $node->save();
                $count++;
              }
            }
          }
        }
        
        $this->output()->writeln("Updated {$count} {$content_type} nodes.");
        $total_updated += $count;
      }
    }
    
    $this->output()->writeln("Title update complete. Updated {$total_updated} nodes in total.");
  }

  /**
   * Creates page facsimiles nodes for all existing issues.
   *
   * @command however-customizations:create-page-facsimiles
   * @aliases how-pages
   */
  public function createPageFacsimiles() {
    $this->output()->writeln('Creating page facsimiles for existing issues...');
  
    // Content types to process
    $issue_types = ['how2_issue', 'journal_issue'];
    $total_created = 0;
    
    foreach ($issue_types as $issue_type) {
      // Load all nodes of this type that have the required fields
      $query = \Drupal::entityQuery('node')
        ->condition('type', $issue_type)
        ->exists('field_volume_number')
        ->exists('field_issue_number')
        ->accessCheck(FALSE);
      $nids = $query->execute();
      
      $this->output()->writeln("Found " . count($nids) . " {$issue_type} nodes.");
      
      if (!empty($nids)) {
        $nodes = Node::loadMultiple($nids);
        
        foreach ($nodes as $node) {
          // Check if page facsimiles already exists for this issue
          $existing_query = \Drupal::entityQuery('node')
            ->condition('type', 'page_facsimiles')
            ->condition('field_issue_reference', $node->id())
            ->accessCheck(FALSE);
          $existing = $existing_query->execute();
          
          if (!empty($existing)) {
            $this->output()->writeln("Page facsimiles already exists for {$node->getTitle()}");
            continue;
          }
          
          $this->output()->writeln("Need to create page facsimiles for {$node->getTitle()}");
          // Extract data for the page facsimiles node
          $volume_number = $node->get('field_volume_number')->value;
          $issue_number = $node->get('field_issue_number')->value;

          // Determine journal name based on content type
          $journal_name = ($issue_type === 'how2_issue') ? 'how2' : 'however';

          $this->output()->writeln("Ready to create page facsimiles for {$node->getTitle()} - {$journal_name} v{$volume_number} n{$issue_number}");
          // Create the page facsimiles node
          $page_facsimiles = Node::create([
            'type' => 'page_facsimiles',
            'title' => "{$journal_name} Volume {$volume_number} Issue {$issue_number}: Page Facsimiles",
            'field_issue_reference' => $node->id(),
            'field_volume_number' => $volume_number,
            'field_issue_number' => $issue_number,
            'field_journal_name' => $journal_name,
            'status' => 1, // Published
          ]);

          $page_facsimiles->save();
          $total_created++;

          $this->output()->writeln("✓ Created page facsimiles for {$node->getTitle()}");
        }
      }
    }
  }
}
