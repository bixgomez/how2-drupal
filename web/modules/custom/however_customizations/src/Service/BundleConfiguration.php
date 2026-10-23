<?php

namespace Drupal\however_customizations\Service;

use Drupal\however_customizations\MigrationStage;

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
   * Get the legacy bundles that are migrated in a stage.
   *
   * @param \Drupal\however_customizations\MigrationStage $stage
   *   The migration stage.
   *
   * @return array
   *   Legacy bundle machine names. The unified bundle has the stage's name.
   */
  public function getLegacyBundles(MigrationStage $stage): array {
    return match ($stage) {
      MigrationStage::Volume => ['however_volume', 'how2_volume'],
      MigrationStage::Issue => ['journal_issue', 'how2_issue'],
      MigrationStage::Section => ['how_ever_section', 'how2_section'],
      MigrationStage::Article => ['how_ever_article', 'how2_article'],
    };
  }

  /**
   * Get the field map used to migrate a legacy bundle to its unified bundle.
   *
   * Only fields whose value is copied unchanged are listed. References to
   * other nodes and paragraphs are not part of this map.
   *
   * @param string $legacy_bundle
   *   The legacy bundle machine name.
   *
   * @return array
   *   Legacy field names mapped to unified field names; an empty array for
   *   bundles whose migration is not defined yet.
   */
  public function getMigrationFieldMap(string $legacy_bundle): array {
    $volume_fields = [
      'field_volume_number' => 'field_volume_number',
      'field_journal_name' => 'field_journal_name',
    ];
    $issue_fields = [
      'body' => 'body',
      'field_archive_url' => 'field_archive_url',
      'field_cover_thumbnail' => 'field_cover_thumbnail',
      'field_issue_number' => 'field_issue_number',
      'field_journal_page_images_media' => 'field_journal_page_images_media',
      'field_journal_pdf' => 'field_issue_pdf',
      'field_masthead' => 'field_masthead',
      'field_publication_date_display' => 'field_publication_date_display',
      'field_short_description' => 'field_short_description',
      'field_volume_number' => 'field_volume_number',
    ];

    $section_fields = [
      'body' => 'body',
      'field_archive_url' => 'field_archive_url',
      'field_issue_number' => 'field_issue_number',
      'field_journal_page_images_media' => 'field_journal_page_images_media',
      'field_links_section_title' => 'field_links_section_title',
      'field_show_full_section_page' => 'field_show_full_section_page',
      'field_subtitle' => 'field_subtitle',
      'field_tags' => 'field_tags',
      'field_teaser_text' => 'field_teaser_text',
      'field_title_display' => 'field_title_display',
      'field_volume_number' => 'field_volume_number',
      'field_working_notes' => 'field_working_notes',
    ];

    return match ($legacy_bundle) {
      'however_volume' => $volume_fields + ['field_however_page_facimile_link' => 'field_page_facsimile_link'],
      'how2_volume' => $volume_fields + ['field_archive_url' => 'field_archive_url'],
      'journal_issue', 'how2_issue' => $issue_fields,
      'how_ever_section', 'how2_section' => $section_fields,
      default => [],
    };
  }

  /**
   * Get the node reference fields that are remapped to migrated parents.
   *
   * Each legacy reference is replaced by the unified node recorded in the
   * migration map for the referenced legacy node, so the referenced stage has
   * to be migrated first.
   *
   * @param string $legacy_bundle
   *   The legacy bundle machine name.
   *
   * @return array
   *   Legacy field names mapped to ['field' => unified field name,
   *   'stage' => the MigrationStage of the referenced nodes].
   */
  public function getMigrationReferenceMap(string $legacy_bundle): array {
    return match ($legacy_bundle) {
      'journal_issue', 'how2_issue' => [
        'field_volume_reference' => ['field' => 'field_volume_reference', 'stage' => MigrationStage::Volume],
      ],
      'how_ever_section', 'how2_section' => [
        'field_issue_reference' => ['field' => 'field_issue_reference', 'stage' => MigrationStage::Issue],
        'field_volume_reference' => ['field' => 'field_volume_reference', 'stage' => MigrationStage::Volume],
      ],
      default => [],
    };
  }

  /**
   * Get the paragraph fields that are copied as new paragraphs.
   *
   * A paragraph belongs to one host node, so each migrated node receives its
   * own duplicates and the legacy node keeps its paragraphs.
   *
   * @param string $legacy_bundle
   *   The legacy bundle machine name.
   *
   * @return array
   *   Legacy field names mapped to unified field names.
   */
  public function getMigrationParagraphFields(string $legacy_bundle): array {
    return match ($legacy_bundle) {
      'journal_issue', 'how2_issue' => ['field_person_bio' => 'field_person_bio'],
      'how_ever_section', 'how2_section' => [
        'field_image_caption' => 'field_image_caption',
        'field_person_bio' => 'field_person_bio',
      ],
      default => [],
    };
  }

  /**
   * Get the piece paragraph fields that are converted to unified paragraphs.
   *
   * The pieces of a section are paragraphs of legacy types (for example
   * how2_section_links) that have to become the unified types, in the same
   * order. See getMigrationPieceTypes().
   *
   * @param string $legacy_bundle
   *   The legacy bundle machine name.
   *
   * @return array
   *   Legacy field names mapped to unified field names.
   */
  public function getMigrationPieceMap(string $legacy_bundle): array {
    return match ($legacy_bundle) {
      'how_ever_section' => ['field_how_ever_piece' => 'field_piece'],
      'how2_section' => ['field_how2_piece' => 'field_piece'],
      default => [],
    };
  }

  /**
   * Get how each legacy piece paragraph type becomes a unified paragraph.
   *
   * Only values that do not refer to other publication nodes are copied. The
   * unit of a section_links paragraph (an article) and the target of a
   * subsection paragraph (a section) are left empty; the links pass fills them
   * once the articles and sections exist, matching the legacy and unified
   * paragraphs by their position in the piece field.
   *
   * @return array
   *   Legacy paragraph bundles mapped to ['type' => unified paragraph bundle,
   *   'fields' => legacy field name => unified field name].
   */
  public function getMigrationPieceTypes(): array {
    $links = ['type' => 'section_links', 'fields' => ['field_notes' => 'field_notes']];
    $subsection = ['type' => 'subsection', 'fields' => []];

    return [
      'section_links' => $links,
      'how2_section_links' => $links,
      'how_ever_subsection' => $subsection,
      'how2_subsection' => $subsection,
      'link_section_title' => [
        'type' => 'link_section_title',
        'fields' => ['field_link_section_title' => 'field_link_section_title'],
      ],
      'open_title_text' => [
        'type' => 'open_title_text',
        'fields' => [
          'field_text' => 'field_text',
          'field_title' => 'field_title',
          'field_title_display' => 'field_title_display',
        ],
      ],
    ];
  }

  /**
   * Check whether the presave hook is skipped when a stage creates nodes.
   *
   * For issues the hook would regenerate the title, so it is skipped to keep
   * the legacy titles (including the custom ones) and numbers exactly as they
   * are. For the other stages the hook only fills the volume and issue numbers
   * from the parent, which many legacy sections lack, so it runs. The
   * migrator sets the journal itself in both cases.
   *
   * @param \Drupal\however_customizations\MigrationStage $stage
   *   The migration stage.
   *
   * @return bool
   *   TRUE if the presave hook must not run.
   */
  public function skipsPresaveHook(MigrationStage $stage): bool {
    return $stage === MigrationStage::Issue;
  }

  /**
   * Get the reference fields a node copies its journal from.
   *
   * The fields are listed in priority order; the first one that holds a node
   * with a journal supplies the value for the node's own field_journal.
   *
   * @param string $bundle
   *   The bundle machine name.
   *
   * @return array
   *   Reference field names, or an empty array when the bundle does not copy
   *   its journal from a parent.
   */
  public function getJournalSourceFields(string $bundle): array {
    switch ($bundle) {
      case 'page_facsimiles':
        return ['field_issue_reference'];

      case 'issue':
        return ['field_volume_reference'];

      case 'section':
        return ['field_issue_reference', 'field_volume_reference'];

      case 'article':
        return ['field_issue_reference', 'field_section_reference', 'field_volume_reference'];

      default:
        return [];
    }
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
      'node_page_facsimiles_edit_form' => 'page_facsimiles',
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
        return ['title', 'field_volume_number', 'field_journal'];

      case 'article':
      case 'section':
        return ['field_volume_number', 'field_issue_number', 'field_journal'];

      case 'page_facsimiles':
        return ['field_journal'];

      default:
        return [];
    }
  }

}
