<?php

namespace Drupal\however_customizations\Drush\Commands;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drush\Commands\DrushCommands;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Drush commands for However Customizations.
 */
class HoweverCustomizationsCommands extends DrushCommands {

  /**
   * Exports nodes changed after a cutoff for cross-site reconciliation.
   *
   * @command however-customizations:reconcile-export
   * @aliases how-reconcile-export
   * @option changed-after Local cutoff datetime. Defaults to the agreed test cutoff.
   * @option timezone Timezone used to parse changed-after. Defaults to site timezone.
   * @option types Optional comma-separated node bundles to export.
   * @option output Output JSON file. Prints to stdout when omitted.
   * @usage drush how-reconcile-export --changed-after="2025-11-28 00:00:00" --output=/tmp/however-reconcile.json
   */
  public function reconcileExport(array $options = [
    'changed-after' => '2025-11-28 00:00:00',
    'timezone' => NULL,
    'types' => '',
    'output' => NULL,
  ]) {
    $options += [
      'changed-after' => '2025-11-28 00:00:00',
      'timezone' => NULL,
      'types' => '',
      'output' => NULL,
    ];

    $timezone_name = $this->getTimezoneName($options['timezone']);
    $cutoff = $this->parseLocalTimestamp($options['changed-after'], $timezone_name);
    $types = $this->parseCommaList($options['types']);

    $query = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('changed', $cutoff, '>')
      ->sort('changed', 'ASC');

    if (!empty($types)) {
      $query->condition('type', $types, 'IN');
    }

    $nids = $query->execute();
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $nodes = empty($nids) ? [] : $storage->loadMultiple($nids);

    $generated_at = \Drupal::time()->getRequestTime();
    $timezone = new \DateTimeZone($timezone_name);
    $payload = [
      'format' => 'however_content_reconciliation',
      'format_version' => 1,
      'generated_at' => $generated_at,
      'generated_at_local' => (new \DateTimeImmutable('@' . $generated_at))->setTimezone($timezone)->format('Y-m-d H:i:s T'),
      'site_name' => \Drupal::config('system.site')->get('name'),
      'site_url' => $this->getCurrentSiteUrl(),
      'cutoff' => [
        'input' => $options['changed-after'],
        'timezone' => $timezone_name,
        'timestamp' => $cutoff,
      ],
      'filters' => [
        'types' => $types,
      ],
      'nodes' => [],
    ];

    foreach ($nodes as $node) {
      $payload['nodes'][] = $this->exportNodePayload($node, $timezone_name);
    }

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === FALSE) {
      throw new \RuntimeException('Unable to encode reconciliation export JSON.');
    }

    if (!empty($options['output'])) {
      if (file_put_contents($options['output'], $json . PHP_EOL) === FALSE) {
        throw new \RuntimeException('Unable to write reconciliation export to ' . $options['output']);
      }
      $this->output()->writeln(sprintf('Exported %d nodes to %s.', count($payload['nodes']), $options['output']));
    }
    else {
      $this->output()->write($json . PHP_EOL);
    }
  }

  /**
   * Imports a reconciliation export when the source node is newer.
   *
   * The command is a dry-run unless --apply is supplied.
   *
   * @command however-customizations:reconcile-import
   * @aliases how-reconcile-import
   * @param string $input Reconciliation JSON export path.
   * @option apply Actually write updates. Without this option, import is a dry-run.
   * @option create-missing Create destination nodes that do not yet exist by UUID.
   * @option force-equal Apply even when source and destination changed timestamps are equal.
   * @option report Optional CSV report file.
   * @usage drush how-reconcile-import /tmp/however-reconcile.json
   * @usage drush how-reconcile-import /tmp/however-reconcile.json --apply
   */
  public function reconcileImport($input, array $options = [
    'apply' => FALSE,
    'create-missing' => FALSE,
    'force-equal' => FALSE,
    'report' => NULL,
  ]) {
    $options += [
      'apply' => FALSE,
      'create-missing' => FALSE,
      'force-equal' => FALSE,
      'report' => NULL,
    ];

    if (!is_readable($input)) {
      throw new \InvalidArgumentException('Input file is not readable: ' . $input);
    }

    $payload = json_decode(file_get_contents($input), TRUE);
    if (!is_array($payload) || ($payload['format'] ?? '') !== 'however_content_reconciliation') {
      throw new \InvalidArgumentException('Input file is not a however content reconciliation export.');
    }

    $apply = $this->isTruthy($options['apply']);
    $create_missing = $this->isTruthy($options['create-missing']);
    $force_equal = $this->isTruthy($options['force-equal']);
    $nodes = $payload['nodes'] ?? [];

    if (!$apply) {
      $this->output()->writeln('Dry-run only. Add --apply to write changes.');
    }

    $created_uuids = [];
    if ($apply && $create_missing) {
      $created_uuids = $this->createMissingNodeSkeletons($nodes);
    }

    $summary = [
      'create' => 0,
      'update' => 0,
      'skip_destination_newer' => 0,
      'skip_equal' => 0,
      'missing' => 0,
      'error' => 0,
    ];
    $report_rows = [];

    foreach ($nodes as $source_node) {
      $uuid = $source_node['uuid'] ?? '';
      $source_changed = (int) ($source_node['changed'] ?? 0);
      $destination = $uuid ? $this->loadEntityByUuid('node', $uuid) : NULL;
      $destination_changed = $destination instanceof Node ? (int) $destination->getChangedTime() : NULL;
      $warnings = [];
      $action = 'update';
      $message = '';

      if (!$destination) {
        if ($create_missing) {
          $action = 'create';
          $message = $apply ? 'Created missing destination node.' : 'Would create missing destination node.';
        }
        else {
          $action = 'missing';
          $message = 'Destination node is missing. Re-run with --create-missing to create it.';
        }
      }
      elseif (isset($created_uuids[$uuid])) {
        $action = 'create';
        $message = 'Created missing destination node.';
      }
      elseif ($destination_changed > $source_changed) {
        $action = 'skip_destination_newer';
        $message = 'Destination is newer than source.';
      }
      elseif ($destination_changed === $source_changed && !$force_equal) {
        $action = 'skip_equal';
        $message = 'Destination timestamp matches source.';
      }

      if (in_array($action, ['create', 'update'], TRUE) && $destination instanceof Node) {
        if ($apply) {
          try {
            $this->applyNodePayload($destination, $source_node, $warnings);
            $message = $action === 'create' ? 'Created and populated destination node.' : 'Updated destination node.';
            $destination_changed = (int) $destination->getChangedTime();
          }
          catch (\Throwable $exception) {
            $action = 'error';
            $message = $exception->getMessage();
          }
        }
        else {
          $message = $action === 'create' ? 'Would create and populate destination node.' : 'Would update destination node.';
        }
      }

      $summary[$action] = ($summary[$action] ?? 0) + 1;
      $report_rows[] = [
        'action' => $action,
        'uuid' => $uuid,
        'type' => $source_node['bundle'] ?? '',
        'title' => $source_node['title'] ?? '',
        'source_nid' => $source_node['nid'] ?? '',
        'source_changed' => $this->formatTimestamp($source_changed),
        'destination_nid' => $destination instanceof Node ? $destination->id() : '',
        'destination_changed' => $destination_changed ? $this->formatTimestamp($destination_changed) : '',
        'message' => $message,
        'warnings' => implode(' | ', $warnings),
      ];

      $this->output()->writeln(sprintf(
        '%s %s %s "%s" - %s',
        strtoupper($action),
        $source_node['bundle'] ?? 'node',
        $source_node['nid'] ?? '',
        $source_node['title'] ?? '',
        $message
      ));

      foreach ($warnings as $warning) {
        $this->output()->writeln('  WARNING: ' . $warning);
      }
    }

    if (!empty($options['report'])) {
      $this->writeCsvReport($options['report'], $report_rows);
      $this->output()->writeln('Wrote report to ' . $options['report']);
    }

    $this->output()->writeln('Summary:');
    foreach ($summary as $action => $count) {
      $this->output()->writeln("  {$action}: {$count}");
    }
  }

  /**
   * Restores node changed timestamps from a reconciliation export and report.
   *
   * @command however-customizations:reconcile-restore-changed
   * @aliases how-reconcile-restore-changed
   * @param string $input Reconciliation JSON export path.
   * @option report CSV apply report path generated by how-reconcile-import.
   * @option actions Comma-separated report actions to restore.
   * @usage drush how-reconcile-restore-changed /tmp/however-reconcile.json --report=/tmp/however-reconcile-apply.csv
   */
  public function reconcileRestoreChanged($input, array $options = [
    'report' => NULL,
    'actions' => 'update,create',
  ]) {
    $options += [
      'report' => NULL,
      'actions' => 'update,create',
    ];

    if (!is_readable($input)) {
      throw new \InvalidArgumentException('Input file is not readable: ' . $input);
    }
    if (empty($options['report']) || !is_readable($options['report'])) {
      throw new \InvalidArgumentException('Report file is not readable: ' . ($options['report'] ?: ''));
    }

    $payload = json_decode(file_get_contents($input), TRUE);
    if (!is_array($payload) || ($payload['format'] ?? '') !== 'however_content_reconciliation') {
      throw new \InvalidArgumentException('Input file is not a however content reconciliation export.');
    }

    $source_by_uuid = [];
    foreach (($payload['nodes'] ?? []) as $source_node) {
      if (!empty($source_node['uuid']) && !empty($source_node['changed'])) {
        $source_by_uuid[$source_node['uuid']] = $source_node;
      }
    }

    $actions = array_flip($this->parseCommaList($options['actions']));
    $rows = $this->readCsvReport($options['report']);
    $count = 0;
    $missing = 0;

    foreach ($rows as $row) {
      $action = $row['action'] ?? '';
      $uuid = $row['uuid'] ?? '';
      if (!isset($actions[$action]) || empty($source_by_uuid[$uuid])) {
        continue;
      }

      $node = $this->loadEntityByUuid('node', $uuid);
      if (!$node instanceof Node) {
        $missing++;
        $this->output()->writeln("MISSING {$uuid} - destination node not found.");
        continue;
      }

      $changed = (int) $source_by_uuid[$uuid]['changed'];
      $this->forceNodeChangedTimestamp($node, $changed);
      $count++;
      $this->output()->writeln(sprintf(
        'RESTORED node %s "%s" to %s.',
        $node->id(),
        $node->label(),
        $this->formatTimestamp($changed)
      ));
    }

    $this->output()->writeln("Restored changed timestamps for {$count} nodes. Missing: {$missing}.");
  }

  /**
   * Gets the timezone used for reconciliation timestamps.
   */
  protected function getTimezoneName($timezone_option) {
    if (!empty($timezone_option)) {
      return $timezone_option;
    }

    return \Drupal::config('system.date')->get('timezone.default') ?: date_default_timezone_get();
  }

  /**
   * Parses a local datetime in the requested timezone.
   */
  protected function parseLocalTimestamp($datetime, $timezone_name) {
    $date = new \DateTimeImmutable($datetime, new \DateTimeZone($timezone_name));
    return $date->getTimestamp();
  }

  /**
   * Parses comma-separated option values.
   */
  protected function parseCommaList($value) {
    if (empty($value)) {
      return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $value))));
  }

  /**
   * Parses Drush boolean options.
   */
  protected function isTruthy($value) {
    if (is_bool($value)) {
      return $value;
    }

    if ($value === NULL) {
      return FALSE;
    }

    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], TRUE);
  }

  /**
   * Returns the current site URL when available.
   */
  protected function getCurrentSiteUrl() {
    try {
      $request = \Drupal::request();
      if ($request && $request->getHost()) {
        return $request->getSchemeAndHttpHost();
      }
    }
    catch (\Throwable $exception) {
      // Drush may not have a meaningful HTTP request.
    }

    return '';
  }

  /**
   * Exports a node and its importable fields.
   */
  protected function exportNodePayload(Node $node, $timezone_name) {
    return [
      'nid' => (int) $node->id(),
      'uuid' => $node->uuid(),
      'bundle' => $node->bundle(),
      'title' => $node->label(),
      'status' => (int) $node->isPublished(),
      'langcode' => $node->language()->getId(),
      'created' => (int) $node->getCreatedTime(),
      'changed' => (int) $node->getChangedTime(),
      'changed_local' => $this->formatTimestamp((int) $node->getChangedTime(), $timezone_name),
      'fields' => $this->exportEntityFields($node),
    ];
  }

  /**
   * Exports a paragraph and its importable fields.
   */
  protected function exportParagraphPayload(Paragraph $paragraph) {
    return [
      'id' => (int) $paragraph->id(),
      'revision_id' => (int) $paragraph->getRevisionId(),
      'uuid' => $paragraph->uuid(),
      'bundle' => $paragraph->bundle(),
      'langcode' => $paragraph->language()->getId(),
      'fields' => $this->exportEntityFields($paragraph),
    ];
  }

  /**
   * Exports field values from a content entity.
   */
  protected function exportEntityFields(ContentEntityInterface $entity) {
    $fields = [];

    foreach ($entity->getFieldDefinitions() as $field_name => $definition) {
      if ($definition->isComputed() || !$entity->hasField($field_name)) {
        continue;
      }

      $field = $entity->get($field_name);
      $field_type = $definition->getType();
      $target_type = $definition->getSetting('target_type');

      $fields[$field_name] = [
        'type' => $field_type,
        'target_type' => $target_type,
        'items' => $this->exportFieldItems($field, $field_type, $target_type),
      ];
    }

    return $fields;
  }

  /**
   * Exports field items with UUID metadata for referenced entities.
   */
  protected function exportFieldItems($field, $field_type, $target_type) {
    $items = [];
    $referenced_entities = [];

    if (in_array($field_type, ['entity_reference', 'entity_reference_revisions'], TRUE)) {
      $referenced_entities = $field->referencedEntities();
    }

    foreach ($field->getValue() as $delta => $value) {
      if ($field_type === 'entity_reference_revisions' && $target_type === 'paragraph') {
        $paragraph = $referenced_entities[$delta] ?? NULL;
        if ($paragraph instanceof Paragraph) {
          $items[] = [
            'entity' => $this->exportParagraphPayload($paragraph),
          ];
        }
        continue;
      }

      $item = $value;
      $referenced = $referenced_entities[$delta] ?? NULL;
      if (!$referenced && isset($value['target_id']) && $field->get($delta)) {
        try {
          $referenced = $field->get($delta)->entity;
        }
        catch (\Throwable $exception) {
          $referenced = NULL;
        }
      }

      if ($referenced instanceof EntityInterface) {
        $item['_target_type'] = $referenced->getEntityTypeId();
        $item['_target_uuid'] = $referenced->uuid();
        $item['_target_label'] = $referenced->label();
      }

      $items[] = $item;
    }

    return $items;
  }

  /**
   * Applies a source node payload to a destination node.
   */
  protected function applyNodePayload(Node $destination, array $source_node, array &$warnings) {
    if ($destination->bundle() !== ($source_node['bundle'] ?? '')) {
      throw new \RuntimeException(sprintf(
        'Bundle mismatch for UUID %s: destination is %s, source is %s.',
        $source_node['uuid'] ?? '',
        $destination->bundle(),
        $source_node['bundle'] ?? ''
      ));
    }

    $destination->however_skip_presave = TRUE;
    foreach (($source_node['fields'] ?? []) as $field_name => $field_payload) {
      if (!$this->canImportField($destination, $field_name)) {
        continue;
      }

      $value = $this->buildImportFieldValue($destination, $field_name, $field_payload, $warnings);
      if ($value === NULL) {
        continue;
      }

      $destination->set($field_name, $value);
    }

    if (isset($source_node['created']) && $destination->hasField('created')) {
      $destination->setCreatedTime((int) $source_node['created']);
    }
    if (isset($source_node['changed']) && $destination->hasField('changed')) {
      $destination->setChangedTime((int) $source_node['changed']);
    }

    $destination->save();

    if (isset($source_node['changed'])) {
      $this->forceNodeChangedTimestamp($destination, (int) $source_node['changed']);
    }
  }

  /**
   * Builds an import value for a destination field.
   */
  protected function buildImportFieldValue(ContentEntityInterface $destination, $field_name, array $field_payload, array &$warnings) {
    $field_type = $field_payload['type'] ?? '';
    $target_type = $field_payload['target_type'] ?? NULL;
    $items = $field_payload['items'] ?? [];

    if ($field_type === 'entity_reference_revisions' && $target_type === 'paragraph') {
      $value = [];
      foreach ($items as $item) {
        if (empty($item['entity']) || !is_array($item['entity'])) {
          continue;
        }
        $paragraph = $this->createOrUpdateParagraphFromPayload($item['entity'], $destination, $field_name, $warnings);
        $value[] = [
          'target_id' => $paragraph->id(),
          'target_revision_id' => $paragraph->getRevisionId(),
        ];
      }
      return $value;
    }

    $value = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }

      if (!empty($item['_target_uuid'])) {
        $referenced = $this->loadEntityByUuid($item['_target_type'] ?? $target_type, $item['_target_uuid']);
        if (!$referenced) {
          $warnings[] = sprintf(
            'Preserved existing %s because referenced %s "%s" (%s) is missing.',
            $field_name,
            $item['_target_type'] ?? $target_type,
            $item['_target_label'] ?? '',
            $item['_target_uuid']
          );
          return NULL;
        }
        $item['target_id'] = $referenced->id();
      }

      foreach (array_keys($item) as $key) {
        if (strpos($key, '_') === 0) {
          unset($item[$key]);
        }
      }

      if ($field_type === 'path') {
        unset($item['pid']);
      }

      $value[] = $item;
    }

    return $value;
  }

  /**
   * Creates or updates a paragraph from exported payload.
   */
  protected function createOrUpdateParagraphFromPayload(array $payload, ContentEntityInterface $parent, $parent_field_name, array &$warnings) {
    $uuid = $payload['uuid'] ?? '';
    $paragraph = $uuid ? $this->loadEntityByUuid('paragraph', $uuid) : NULL;

    if (!$paragraph instanceof Paragraph) {
      $values = [
        'type' => $payload['bundle'] ?? '',
      ];
      if (!empty($uuid)) {
        $values['uuid'] = $uuid;
      }
      if (!empty($payload['langcode'])) {
        $values['langcode'] = $payload['langcode'];
      }
      $paragraph = Paragraph::create($values);
    }
    elseif ($paragraph->bundle() !== ($payload['bundle'] ?? '')) {
      $warnings[] = sprintf(
        'Existing paragraph %s has bundle %s, but source bundle is %s.',
        $uuid,
        $paragraph->bundle(),
        $payload['bundle'] ?? ''
      );
      return $paragraph;
    }

    foreach (($payload['fields'] ?? []) as $field_name => $field_payload) {
      if (!$this->canImportField($paragraph, $field_name)) {
        continue;
      }

      $value = $this->buildImportFieldValue($paragraph, $field_name, $field_payload, $warnings);
      if ($value === NULL) {
        continue;
      }

      $paragraph->set($field_name, $value);
    }

    if (method_exists($paragraph, 'setParentEntity')) {
      $paragraph->setParentEntity($parent, $parent_field_name);
    }

    $paragraph->save();
    return $paragraph;
  }

  /**
   * Creates skeleton destination nodes for missing UUIDs.
   */
  protected function createMissingNodeSkeletons(array $nodes) {
    $created = [];

    foreach ($nodes as $source_node) {
      $uuid = $source_node['uuid'] ?? '';
      if (!$uuid || $this->loadEntityByUuid('node', $uuid)) {
        continue;
      }

      $values = [
        'type' => $source_node['bundle'] ?? '',
        'uuid' => $uuid,
        'title' => $source_node['title'] ?? $uuid,
        'status' => (int) ($source_node['status'] ?? 0),
        'uid' => 1,
      ];
      if (!empty($source_node['langcode'])) {
        $values['langcode'] = $source_node['langcode'];
      }
      if (isset($source_node['created'])) {
        $values['created'] = (int) $source_node['created'];
      }
      if (isset($source_node['changed'])) {
        $values['changed'] = (int) $source_node['changed'];
      }

      $node = Node::create($values);
      $node->however_skip_presave = TRUE;
      $node->save();
      $created[$uuid] = TRUE;
    }

    return $created;
  }

  /**
   * Determines whether a field should be imported.
   */
  protected function canImportField(ContentEntityInterface $entity, $field_name) {
    $blocked = [
      'nid',
      'id',
      'vid',
      'revision_id',
      'uuid',
      'type',
      'langcode',
      'default_langcode',
      'revision_default',
      'revision_translation_affected',
      'revision_timestamp',
      'revision_uid',
      'revision_log',
      'parent_id',
      'parent_type',
      'parent_field_name',
    ];

    if (in_array($field_name, $blocked, TRUE) || !$entity->hasField($field_name)) {
      return FALSE;
    }

    return !$entity->getFieldDefinition($field_name)->isComputed();
  }

  /**
   * Loads a content entity by UUID.
   */
  protected function loadEntityByUuid($entity_type_id, $uuid) {
    if (empty($entity_type_id) || empty($uuid)) {
      return NULL;
    }

    $storage = \Drupal::entityTypeManager()->getStorage($entity_type_id);
    $entities = $storage->loadByProperties(['uuid' => $uuid]);
    return $entities ? reset($entities) : NULL;
  }

  /**
   * Formats a timestamp for reports.
   */
  protected function formatTimestamp($timestamp, $timezone_name = NULL) {
    if (!$timestamp) {
      return '';
    }

    $timezone_name = $timezone_name ?: $this->getTimezoneName(NULL);
    return (new \DateTimeImmutable('@' . $timestamp))
      ->setTimezone(new \DateTimeZone($timezone_name))
      ->format('Y-m-d H:i:s T');
  }

  /**
   * Writes a CSV import report.
   */
  protected function writeCsvReport($path, array $rows) {
    $handle = fopen($path, 'w');
    if (!$handle) {
      throw new \RuntimeException('Unable to write report to ' . $path);
    }

    $headers = [
      'action',
      'uuid',
      'type',
      'title',
      'source_nid',
      'source_changed',
      'destination_nid',
      'destination_changed',
      'message',
      'warnings',
    ];
    fputcsv($handle, $headers);

    foreach ($rows as $row) {
      fputcsv($handle, array_map(function ($header) use ($row) {
        return $row[$header] ?? '';
      }, $headers));
    }

    fclose($handle);
  }

  /**
   * Reads a CSV import report.
   */
  protected function readCsvReport($path) {
    $handle = fopen($path, 'r');
    if (!$handle) {
      throw new \RuntimeException('Unable to read report from ' . $path);
    }

    $headers = fgetcsv($handle);
    $rows = [];
    while (($data = fgetcsv($handle)) !== FALSE) {
      if (count($data) !== count($headers)) {
        continue;
      }
      $rows[] = array_combine($headers, $data);
    }

    fclose($handle);
    return $rows;
  }

  /**
   * Forces node changed timestamps after save.
   *
   * Drupal's changed field can be advanced during entity saves. For this
   * reconciliation workflow the source timestamp is part of the imported
   * content record, so set it directly after the entity has been saved.
   */
  protected function forceNodeChangedTimestamp(Node $node, $changed) {
    $database = \Drupal::database();
    $database->update('node_field_data')
      ->fields(['changed' => $changed])
      ->condition('nid', $node->id())
      ->execute();

    $database->update('node_field_revision')
      ->fields(['changed' => $changed])
      ->condition('nid', $node->id())
      ->condition('vid', $node->getRevisionId())
      ->execute();

    $node->setChangedTime($changed);
    \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node->id()]);
  }

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
