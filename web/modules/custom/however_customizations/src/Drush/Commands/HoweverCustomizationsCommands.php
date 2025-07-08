<?php

namespace Drupal\however_customizations\Drush\Commands;

use Drush\Commands\DrushCommands;
use Drupal\node\Entity\Node;

/**
 * Drush commands for However Customizations.
 */
class HoweverCustomizationsCommands extends DrushCommands {

  /**
   * Replaces old domain URLs with relative paths across all text fields.
   *
   * @command however-customizations:replace-urls
   * @aliases how-urls
   */
  public function replaceUrls() {
    $this->output()->writeln('Starting URL replacement...');
    
    $old_domain = 'https://howeverhow2archive.org/';
    $new_path = '/';
    $total_updated = 0;
    
    // First, handle paragraph entities (where most links live)
    $this->output()->writeln('Processing paragraph entities...');
    
    $paragraph_query = \Drupal::entityQuery('paragraph')
      ->accessCheck(FALSE);
    $paragraph_ids = $paragraph_query->execute();
    
    if (!empty($paragraph_ids)) {
      $this->output()->writeln('Found ' . count($paragraph_ids) . ' paragraph entities to check.');
      
      // Process in batches
      $chunks = array_chunk($paragraph_ids, 50, TRUE);
      
      foreach ($chunks as $chunk) {
        $paragraphs = \Drupal::entityTypeManager()
          ->getStorage('paragraph')
          ->loadMultiple($chunk);
        
        foreach ($paragraphs as $paragraph) {
          $fields = $paragraph->getFields();
          $paragraph_updated = FALSE;
          
          foreach ($fields as $field_name => $field) {
            // Skip system fields
            if (strpos($field_name, 'field_') !== 0) {
              continue;
            }
            
            $field_type = $field->getFieldDefinition()->getType();
            if (in_array($field_type, ['text', 'text_long', 'text_with_summary'])) {
              $field_value = $field->value;
              
              if ($field_value && strpos($field_value, $old_domain) !== FALSE) {
                $new_value = str_replace($old_domain, $new_path, $field_value);
                $paragraph->set($field_name, $new_value);
                $paragraph_updated = TRUE;
                
                $this->output()->writeln("  Updated paragraph {$paragraph->id()}: {$field_name}");
              }
            }
          }
          
          if ($paragraph_updated) {
            $paragraph->save();
            $total_updated++;
          }
        }
      }
    }
    
    // Then handle regular node fields
    $this->output()->writeln('Processing node entities...');
    
    $field_storages = \Drupal::entityTypeManager()
      ->getStorage('field_storage_config')
      ->loadByProperties(['type' => ['text', 'text_long', 'text_with_summary']]);
    
    foreach ($field_storages as $field_storage) {
      $field_name = $field_storage->getName();
      $entity_type = $field_storage->getTargetEntityTypeId();
      
      // Only process node entities
      if ($entity_type !== 'node') {
        continue;
      }
      
      // Load entities that contain the old domain
      $query = \Drupal::entityQuery($entity_type)
        ->condition($field_name, $old_domain, 'CONTAINS')
        ->accessCheck(FALSE);
      
      $entity_ids = $query->execute();
      
      if (!empty($entity_ids)) {
        $entities = \Drupal::entityTypeManager()
          ->getStorage($entity_type)
          ->loadMultiple($entity_ids);
        
        foreach ($entities as $entity) {
          if ($entity->hasField($field_name)) {
            $field_value = $entity->get($field_name)->value;
            if (strpos($field_value, $old_domain) !== FALSE) {
              $new_value = str_replace($old_domain, $new_path, $field_value);
              $entity->set($field_name, $new_value);
              $entity->save();
              $total_updated++;
              
              $this->output()->writeln("  Updated {$entity_type} {$entity->id()}: {$field_name}");
            }
          }
        }
      }
    }
    
    $this->output()->writeln("URL replacement complete. Updated {$total_updated} entities total.");
  }

  /**
   * Updates volume and issue numbers for all content types with auto-populated fields.
   *
   * @command however-customizations:update-volume-numbers
   * @aliases how-vol
   */
  public function updateVolumeNumbers() {
    $this->output()->writeln('Starting volume/issue number update...');
    
    // Use the same mappings as entity_presave
    $content_mappings = [
      'how2_issue' => [
        'reference_field' => 'field_volume_reference',
        'number_field' => 'field_volume_number',
      ],
      'journal_issue' => [
        'reference_field' => 'field_volume_reference',
        'number_field' => 'field_volume_number',
      ],
      'how_ever_article' => [
        'reference_field' => 'field_issue_reference',
        'copy_fields' => [
          'field_volume_number' => 'field_volume_number',
          'field_issue_number' => 'field_issue_number',
        ],
      ],
      'how2_article' => [
        'reference_field' => 'field_issue_reference',
        'copy_fields' => [
          'field_volume_number' => 'field_volume_number',
          'field_issue_number' => 'field_issue_number',
        ],
      ],
      'how_ever_section' => [
        'reference_field' => 'field_issue_reference',
        'copy_fields' => [
          'field_volume_number' => 'field_volume_number',
          'field_issue_number' => 'field_issue_number',
        ],
      ],
      'how2_section' => [
        'reference_field' => 'field_issue_reference',
        'copy_fields' => [
          'field_volume_number' => 'field_volume_number',
          'field_issue_number' => 'field_issue_number',
        ],
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
        
        // Process nodes in smaller batches
        $chunks = array_chunk($nids, 50, TRUE);
        
        foreach ($chunks as $chunk) {
          $nodes = Node::loadMultiple($chunk);
          
          foreach ($nodes as $node) {
            // Skip if no reference
            if ($node->{$mapping['reference_field']}->isEmpty()) {
              continue;
            }
            
            // Get referenced entity
            $referenced_entity = $node->{$mapping['reference_field']}->entity;
            
            if ($referenced_entity) {
              $updated = FALSE;
              
              // Handle single field copy (issues → volumes)
              if (isset($mapping['number_field'])) {
                if ($referenced_entity->hasField('field_volume_number') && 
                    !$referenced_entity->field_volume_number->isEmpty()) {
                  
                  $volume_number = $referenced_entity->field_volume_number->value;
                  $node->{$mapping['number_field']}->value = $volume_number;
                  $updated = TRUE;
                }
              }
              
              // Handle multiple field copy (articles & sections → issues)
              if (isset($mapping['copy_fields'])) {
                foreach ($mapping['copy_fields'] as $source_field => $target_field) {
                  if ($referenced_entity->hasField($source_field) && 
                      !$referenced_entity->{$source_field}->isEmpty()) {
                    
                    $field_value = $referenced_entity->{$source_field}->value;
                    $node->{$target_field}->value = $field_value;
                    $updated = TRUE;
                  }
                }
              }
              
              if ($updated) {
                // Save with skip flag
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
    
    $this->output()->writeln("Update complete. Updated {$total_updated} nodes total.");
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
   * Creates masthead articles from issues (both How(ever) and How2).
   *
   * @command however-customizations:create-masthead-articles
   * @aliases how-masthead
   */
  public function createMastheadArticles() {
    $this->output()->writeln('Creating masthead articles from issues...');
    
    $total_created = 0;
    
    // Define issue types and their corresponding article types and sections fields
    $issue_mappings = [
      'journal_issue' => [
        'article_type' => 'how_ever_article',
        'sections_field' => 'field_sections_however',
      ],
      'how2_issue' => [
        'article_type' => 'how2_article',
        'sections_field' => 'field_sections_how2',
      ],
    ];
    
    foreach ($issue_mappings as $issue_type => $mapping) {
      // Load all nodes of this issue type that have masthead content
      $query = \Drupal::entityQuery('node')
        ->condition('type', $issue_type)
        ->exists('field_masthead')
        ->accessCheck(FALSE);
      $nids = $query->execute();
      
      $this->output()->writeln("Found " . count($nids) . " {$issue_type} nodes with masthead content.");
      
      if (!empty($nids)) {
        // Process in batches
        $chunks = array_chunk($nids, 50, TRUE);
        
        foreach ($chunks as $chunk) {
          $issues = Node::loadMultiple($chunk);
          
          foreach ($issues as $issue) {
            // Skip if masthead field is empty
            if ($issue->get('field_masthead')->isEmpty()) {
              continue;
            }
            
            // Check if masthead article already exists for this issue
            $existing_query = \Drupal::entityQuery('node')
              ->condition('type', $mapping['article_type'])
              ->condition('field_issue_reference', $issue->id())
              ->condition('title', 'Masthead')
              ->accessCheck(FALSE);
            $existing = $existing_query->execute();
            
            if (!empty($existing)) {
              $this->output()->writeln("Masthead article already exists for {$issue->getTitle()}");
              continue;
            }
            
            // Get masthead content
            $masthead_content = $issue->get('field_masthead')->value;
            $masthead_format = $issue->get('field_masthead')->format;
            
            // Get volume reference from the issue
            $volume_reference = null;
            if (!$issue->get('field_volume_reference')->isEmpty()) {
              $volume_reference = $issue->get('field_volume_reference')->target_id;
            }
            
            $this->output()->writeln("Creating masthead article for {$issue->getTitle()}");
            
            // Create the masthead article
            $masthead_article = Node::create([
              'type' => $mapping['article_type'],
              'title' => 'Masthead',
              'field_issue_reference' => $issue->id(),
              'field_volume_reference' => $volume_reference,
              'body' => [
                'value' => $masthead_content,
                'format' => $masthead_format ?: 'basic_html',
              ],
              'status' => 1, // Published
            ]);
            
            $masthead_article->save();
            $total_created++;
            
            $this->output()->writeln("✓ Created masthead article for {$issue->getTitle()}");
            
            // Now add the masthead article to the beginning of the issue's sections field
            $sections_field = $mapping['sections_field'];
            
            // Get current sections
            $current_sections = $issue->get($sections_field)->getValue();
            
            // Create new array with masthead first
            $new_sections = [
              ['target_id' => $masthead_article->id()],
            ];
            
            // Add existing sections after the masthead
            foreach ($current_sections as $section) {
              $new_sections[] = $section;
            }
            
            // Update the issue with the new sections array
            $issue->set($sections_field, $new_sections);
            $issue->however_skip_presave = TRUE; // Skip our presave hooks
            $issue->save();
            
            $this->output()->writeln("✓ Added masthead to beginning of sections for {$issue->getTitle()}");
          }
        }
      }
    }
    
    $this->output()->writeln("Masthead creation complete. Created {$total_created} articles total.");
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
