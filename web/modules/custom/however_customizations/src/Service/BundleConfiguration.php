<?php

namespace Drupal\however_customizations\Service;

/**
 * Service to manage bundle configurations and relationships.
 *
 * Centralizes all bundle-related configuration, field mappings, and title patterns
 * in a single source of truth. This enables bundle-agnostic functions throughout
 * the module while supporting both legacy and new unified bundles.
 */
class BundleConfiguration {

  /**
   * Get all volume bundle types (legacy and unified).
   *
   * @return array
   *   Array of volume bundle machine names.
   */
  public function getVolumeTypes(): array {
    return ['however_volume', 'how2_volume', 'volume'];
  }

  /**
   * Get all issue bundle types (legacy and unified).
   *
   * @return array
   *   Array of issue bundle machine names.
   */
  public function getIssueTypes(): array {
    return ['journal_issue', 'how2_issue', 'issue'];
  }

  /**
   * Get all article bundle types (legacy and unified).
   *
   * @return array
   *   Array of article bundle machine names.
   */
  public function getArticleTypes(): array {
    return ['how_ever_article', 'how2_article', 'article'];
  }

  /**
   * Get all section bundle types (legacy and unified).
   *
   * @return array
   *   Array of section bundle machine names.
   */
  public function getSectionTypes(): array {
    return ['how_ever_section', 'how2_section', 'section'];
  }

  /**
   * Check if a bundle is a volume type.
   *
   * @param string $bundle
   *   The bundle machine name.
   *
   * @return bool
   *   TRUE if this is a volume bundle.
   */
  public function isVolumeBundle(string $bundle): bool {
    return in_array($bundle, $this->getVolumeTypes());
  }

  /**
   * Check if a bundle is an issue type.
   *
   * @param string $bundle
   *   The bundle machine name.
   *
   * @return bool
   *   TRUE if this is an issue bundle.
   */
  public function isIssueBundle(string $bundle): bool {
    return in_array($bundle, $this->getIssueTypes());
  }

  /**
   * Check if a bundle is an article type.
   *
   * @param string $bundle
   *   The bundle machine name.
   *
   * @return bool
   *   TRUE if this is an article bundle.
   */
  public function isArticleBundle(string $bundle): bool {
    return in_array($bundle, $this->getArticleTypes());
  }

  /**
   * Check if a bundle is a section type.
   *
   * @param string $bundle
   *   The bundle machine name.
   *
   * @return bool
   *   TRUE if this is a section bundle.
   */
  public function isSectionBundle(string $bundle): bool {
    return in_array($bundle, $this->getSectionTypes());
  }

  /**
   * Get the journal name for a given bundle.
   *
   * Determines which journal (however or how2) a bundle belongs to based on
   * the bundle machine name.
   *
   * @param string $bundle
   *   The bundle machine name.
   *
   * @return string|null
   *   The journal name ('however', 'how2') or NULL if not determinable.
   */
  public function getJournalFromBundle(string $bundle): ?string {
    if (in_array($bundle, ['however_volume', 'journal_issue', 'how_ever_article', 'how_ever_section'])) {
      return 'however';
    }
    if (in_array($bundle, ['how2_volume', 'how2_issue', 'how2_article', 'how2_section'])) {
      return 'how2';
    }
    // For unified bundles, we cannot determine journal from bundle name alone.
    // The journal must be determined from field_journal reference or context.
    return NULL;
  }

  /**
   * Get title patterns for each bundle type.
   *
   * Returns the template strings used for auto-generating titles.
   *
   * @return array
   *   Array with structure: ['bundle_name' => 'Title Pattern {volume} {issue}']
   */
  public function getTitlePatterns(): array {
    return [
      'however_volume' => 'How(ever) Volume {volume}',
      'how2_volume' => 'How2 Volume {volume}',
      'volume' => '{journal} Volume {volume}',
      'journal_issue' => 'How(ever) Volume {volume} Issue {issue}',
      'how2_issue' => 'How2 Volume {volume} Issue {issue}',
      'issue' => '{journal} Volume {volume} Issue {issue}',
    ];
  }

  /**
   * Get content field mappings for field synchronization.
   *
   * Defines which fields get populated from referenced entities and how.
   * This includes both single field copies (number_field) and multiple field
   * copies (copy_fields) for different content types.
   *
   * @return array
   *   Array mapping bundle names to field configuration.
   *   - reference_field: The field that references parent entity
   *   - number_field: Single field to copy from referenced entity
   *   - copy_fields: Multiple fields to copy [source => target]
   */
  public function getContentMappings(): array {
    return [
      // Issues: copy volume_number from referenced volume
      'journal_issue' => [
        'reference_field' => 'field_volume_reference',
        'number_field' => 'field_volume_number',
      ],
      'how2_issue' => [
        'reference_field' => 'field_volume_reference',
        'number_field' => 'field_volume_number',
      ],
      'issue' => [
        'reference_field' => 'field_volume_reference',
        'number_field' => 'field_volume_number',
      ],
      // Articles: copy volume_number and issue_number from referenced issue
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
      'article' => [
        'reference_field' => 'field_issue_reference',
        'copy_fields' => [
          'field_volume_number' => 'field_volume_number',
          'field_issue_number' => 'field_issue_number',
        ],
      ],
      // Sections: copy volume_number and issue_number from referenced issue
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
      'section' => [
        'reference_field' => 'field_issue_reference',
        'copy_fields' => [
          'field_volume_number' => 'field_volume_number',
          'field_issue_number' => 'field_issue_number',
        ],
      ],
    ];
  }

  /**
   * Get form ID mappings for bundle type detection.
   *
   * Maps Drupal form IDs to their corresponding bundle types.
   * Handles both create and edit form variants.
   *
   * @return array
   *   Array mapping form_id patterns to bundle_type.
   */
  public function getFormBundleMappings(): array {
    return [
      'node_however_volume_form' => 'volume',
      'node_however_volume_edit_form' => 'volume',
      'node_how2_volume_form' => 'volume',
      'node_how2_volume_edit_form' => 'volume',
      'node_volume_form' => 'volume',
      'node_volume_edit_form' => 'volume',
      'node_journal_issue_form' => 'issue',
      'node_journal_issue_edit_form' => 'issue',
      'node_how2_issue_form' => 'issue',
      'node_how2_issue_edit_form' => 'issue',
      'node_issue_form' => 'issue',
      'node_issue_edit_form' => 'issue',
      'node_how_ever_article_form' => 'article',
      'node_how_ever_article_edit_form' => 'article',
      'node_how2_article_form' => 'article',
      'node_how2_article_edit_form' => 'article',
      'node_article_form' => 'article',
      'node_article_edit_form' => 'article',
      'node_how_ever_section_form' => 'section',
      'node_how_ever_section_edit_form' => 'section',
      'node_how2_section_form' => 'section',
      'node_how2_section_edit_form' => 'section',
      'node_section_form' => 'section',
      'node_section_edit_form' => 'section',
    ];
  }

  /**
   * Get fields that should be disabled in forms for a given bundle type.
   *
   * @param string $bundle_type
   *   The bundle type ('volume', 'issue', 'article', 'section').
   *
   * @return array
   *   Array of field names to disable in the form.
   */
  public function getDisabledFormFields(string $bundle_type): array {
    switch ($bundle_type) {
      case 'volume':
        return ['title'];

      case 'issue':
        return ['title', 'field_volume_number'];

      case 'article':
      case 'section':
        return ['field_volume_number', 'field_issue_number'];

      default:
        return [];
    }
  }

}
