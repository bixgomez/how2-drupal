# However Customizations

A custom Drupal module that provides specialized functionality for the However project.

## Overview

This module automates content management for two academic journals (**How(ever)** and **How2**) by handling the complex relationships between volumes, issues, sections, and articles.  It eliminates manual data entry, ensures consistency across dozens of pieces of content, and provides intelligent navigation throughout the publication hierarchy.

### Key Problems Solved:
- **Eliminates repetitive data entry** through automatic field synchronization
- **Maintains consistency** with auto-generated titles and standardized formatting  
- **Prevents broken relationships** by managing content lifecycle automatically
- **Improves editorial workflow** with smart form enhancements and bulk operations
- **Enables seamless navigation** through intelligent prev/next linking

The module serves as the backbone for managing a large archive of literary journal content, ensuring that volume numbers, issue numbers, and titles remain synchronized across all related content without manual intervention.

## Features Overview

This module handles content relationships, automatic content generation, navigation, and maintenance tasks across the complex publication hierarchy of volumes, issues, sections, and articles.

## Content Architecture

The module manages four main content types for each journal:

### How(ever) Journal
- **however_volume** - Volume containers
- **journal_issue** - Individual issues within volumes  
- **how_ever_section** - Article groupings within issues
- **how_ever_article** - Individual articles

### How2 Journal
- **how2_volume** - Volume containers
- **how2_issue** - Individual issues within volumes
- **how2_section** - Article groupings within issues  
- **how2_article** - Individual articles

### Supporting Content
- **page_facsimiles** - Automatically generated for each issue to display page images

## Core Features

### 1. Auto-Generated Titles

Automatically generates and maintains titles for content based on their hierarchical relationships:

#### Volumes
- **however_volume**: "How(ever) Volume 1", "How(ever) Volume 2", etc.
- **how2_volume**: "How2 Volume 1", "How2 Volume 2", etc.

#### Issues  
- **journal_issue**: "How(ever) Volume 1 Issue 1", etc.
- **how2_issue**: "How2 Volume 1 Issue 1", etc.

**Implementation:** Title fields are disabled in the admin UI and auto-populated via `hook_entity_presave()` based on volume/issue number fields.

### 2. Field Synchronization

Automatically propagates volume and issue numbers throughout the content hierarchy:

#### Issue → Volume Sync
- When issues reference a volume, the issue's `field_volume_number` automatically matches the volume's number
- Ensures consistent numbering across the hierarchy

#### Article/Section → Issue Sync  
- When articles or sections reference an issue, they automatically inherit:
  - `field_volume_number` from the issue
  - `field_issue_number` from the issue
- Maintains accurate metadata for all content

**Implementation:** Uses `hook_entity_presave()` with configurable field mappings to sync data between referenced entities.

### 3. Enhanced Entity Autocomplete

Extends Drupal's core autocomplete functionality to show contextual information:

- **Volume information** in autocomplete results
- **Issue information** for better content identification
- **Content type indicators** for easier selection

**Example:** Instead of just "Article Title", shows "Article Title [how2_article - Vol: How2 Volume 1, Issue: How2 Volume 1 Issue 2] (123)"

### 4. Publication Navigation

Provides intelligent prev/next navigation throughout the publication hierarchy:

#### Volume Navigation
- Previous/next volume links within the same journal
- Handles missing volumes gracefully

#### Issue Navigation  
- Previous/next issue within the same volume
- Automatically jumps to previous volume's last issue when reaching volume boundaries
- Automatically jumps to next volume's first issue when appropriate

**Implementation:** Custom `PublicationNavigationService` with template variables for `volume_navigation` and `issue_navigation`.

### 5. Automatic Page Facsimiles

Automatically creates and manages page facsimile nodes:

#### On Issue Creation
- Creates corresponding `page_facsimiles` node for each new issue
- Pre-populates with journal name, volume, and issue numbers
- Establishes proper entity reference relationships

#### On Issue Deletion
- Automatically removes orphaned page facsimile nodes
- Keeps the site clean and prevents broken references

### 6. Form Enhancements

Improves the content editing experience:

#### Disabled Auto-Generated Fields
- Title fields disabled with helpful explanations
- Volume/issue number fields disabled when auto-populated
- Clear descriptions explaining automatic field population

#### Smart Field Descriptions
- Context-sensitive help text
- Examples of auto-generated content
- Guidance for content editors

### 7. Paragraph Integration

Special handling for Paragraphs module integration:

- **Journal TOC paragraph type** for table of contents display
- **Field synchronization** works with paragraph-embedded content
- **URL replacement** targets paragraph text fields specifically

## Drush Commands

The module provides several powerful Drush commands for content management:

### `however-customizations:update-volume-numbers`
**Alias:** `how-vol`  
**When to run:** After bulk imports or when field synchronization gets out of sync  
**Frequency:** As needed for maintenance

Bulk updates volume and issue numbers across all content types based on their entity references.

```bash
drush however-customizations:update-volume-numbers
drush how-vol
```

**What it does:**
- Processes all issues and updates volume numbers from referenced volumes
- Processes all articles/sections and updates volume/issue numbers from referenced issues  
- Handles large datasets with batch processing
- Skips automatic hooks to prevent loops

### `however-customizations:update-titles`
**Alias:** `how-titles`  
**When to run:** After bulk imports or if title formatting rules change  
**Frequency:** As needed for maintenance

Regenerates titles for all volume and issue content based on current number fields.

```bash
drush however-customizations:update-titles
drush how-titles
```

**What it does:**
- Updates titles for all volume content types
- Updates titles for all issue content types
- Only updates titles that have actually changed
- Processes in batches for performance

### `however-customizations:create-masthead-articles`
**Alias:** `how-masthead`  
**When to run:** One-time migration (already completed)  
**Frequency:** Only needed once during initial setup

Creates masthead articles from existing issue masthead content.

```bash
drush however-customizations:create-masthead-articles
drush how-masthead
```

**What it does:**
- Extracts masthead content from issues' `field_masthead`
- Creates dedicated masthead articles for each issue
- Adds masthead articles to the beginning of issue sections
- Prevents duplicate creation

### `however-customizations:create-page-facsimiles`
**Alias:** `how-pages`  
**When to run:** One-time migration (already completed)  
**Frequency:** Only needed once; new issues auto-create their page facsimiles

Creates page facsimile nodes for all existing issues that don't have them.

```bash
drush however-customizations:create-page-facsimiles
drush how-pages
```

**What it does:**
- Scans all existing issues
- Creates missing page facsimile nodes
- Pre-populates with proper metadata
- Prevents duplicate creation

**Note:** New issues automatically create page facsimiles via `hook_node_insert()`, so this command was only needed for existing content.

### `however-customizations:replace-urls`
**Alias:** `how-urls`  
**When to run:** One-time domain migration (already completed)  
**Frequency:** Only needed if domain changes again

Replaces old absolute URLs with relative paths across all text content.

```bash
drush however-customizations:replace-urls
drush how-urls
```

**What it does:**
- Converts `https://howeverhow2archive.org/path` to `/path`
- Processes both node fields and paragraph entities
- Handles all text field types (text, text_long, text_with_summary)
- Provides detailed progress reporting

## Installation

1. **Place the module** in your Drupal installation:
   ```bash
   cp -r however_customizations /path/to/drupal/modules/custom/
   ```

2. **Enable the module:**
   ```bash
   drush en however_customizations
   ```

3. **Clear cache:**
   ```bash
   drush cr
   ```

## Configuration

### Field Requirements

For the module to function properly, ensure these fields exist:

#### Volume Content Types
- `field_volume_number` (integer)

#### Issue Content Types  
- `field_volume_number` (integer)
- `field_issue_number` (integer)
- `field_volume_reference` (entity reference to volume)

#### Article/Section Content Types
- `field_volume_number` (integer) 
- `field_issue_number` (integer)
- `field_issue_reference` (entity reference to issue)

#### Page Facsimiles Content Type
- `field_issue_reference` (entity reference to issue)
- `field_volume_number` (integer)
- `field_issue_number` (integer)  
- `field_journal_name` (text)

### Services

The module registers these services:

- **`however_customizations.autocomplete_matcher`** - Enhanced autocomplete
- **`however_customizations.publication_navigation`** - Navigation service

## Usage in Templates

### Volume Navigation

```twig
{% if volume_navigation.prev %}
  <a href="{{ volume_navigation.prev.url }}">Previous Volume</a>
{% endif %}

{% if volume_navigation.next %}
  <a href="{{ volume_navigation.next.url }}">Next Volume</a>  
{% endif %}
```

### Issue Navigation

```twig
{% if issue_navigation.prev %}
  <a href="{{ issue_navigation.prev.url }}">Previous Issue</a>
{% endif %}

{% if issue_navigation.next %}
  <a href="{{ issue_navigation.next.url }}">Next Issue</a>
{% endif %}
```

### Journal Detection

```twig
{% if journal_machine_name == 'however' %}
  <!-- How(ever) specific content -->
{% elseif journal_machine_name == 'how2' %}
  <!-- How2 specific content -->  
{% endif %}
```

## Content Workflow

### Creating New Content

1. **Create Volume:** Add volume with `field_volume_number`
   - Title auto-generates as "How(ever) Volume X" or "How2 Volume X"

2. **Create Issue:** Add issue with `field_issue_number` and reference to volume
   - `field_volume_number` auto-populates from referenced volume
   - Title auto-generates as "Journal Volume X Issue Y"
   - Page facsimiles node auto-creates

3. **Create Articles/Sections:** Reference the issue
   - Volume and issue numbers auto-populate
   - Content appears in issue navigation

### Maintenance Tasks

- **Run `how-vol`** after bulk imports to sync all numbers
- **Run `how-titles`** after changing numbering schemes  
- **Run `how-urls`** after domain migrations
- **Run `how-masthead`** to extract masthead content into articles
- **Run `how-pages`** to create missing page facsimile nodes

## Troubleshooting

### Titles Not Generating
- Verify `field_volume_number` and `field_issue_number` exist and have values
- Check that content types match expected names
- Clear cache: `drush cr`

### Numbers Not Syncing
- Ensure entity reference fields are properly configured
- Run: `drush how-vol` to force sync
- Check for circular references

### Navigation Not Working
- Verify field names match module expectations
- Ensure content is published (`status = 1`)
- Check entity reference integrity

### Autocomplete Issues
- Clear cache: `drush cr`
- Verify service registration in `however_customizations.services.yml`
- Check field configurations

### Performance Issues
- All Drush commands use batch processing for large datasets
- Monitor memory usage during bulk operations
- Consider running commands during off-peak hours

## Development Notes

### Hook Implementation
- `hook_entity_presave()` handles automatic field population
- `hook_form_alter()` manages form field states
- `hook_preprocess_node()` adds navigation variables
- `hook_node_insert()` and `hook_node_delete()` manage page facsimiles

### Field Mapping Configuration
The module uses internal arrays to define field relationships. Modify these in `however_customizations_entity_presave()` to adjust behavior.

### Batch Processing
All Drush commands use chunked processing (50 entities per batch) to handle large datasets efficiently.

## Requirements

- **Drupal:** 9 or 10
- **PHP:** 7.4 or higher  
- **Modules:** Core Entity, Field, Node modules
- **Optional:** Paragraphs module for enhanced functionality

## Testing

### Running Tests

From your DDEV environment:

```bash
# SSH into container and navigate to web directory
ddev ssh
cd web

# Run all module tests
SIMPLETEST_DB=mysql://db:db@db/db ../vendor/bin/phpunit --debug --verbose --testdox -c ./core/phpunit.xml.dist ./modules/custom/however_customizations/tests/

# Run specific test class
SIMPLETEST_DB=mysql://db:db@db/db ../vendor/bin/phpunit --debug --verbose --testdox -c ./core/phpunit.xml.dist ./modules/custom/however_customizations/tests/src/Kernel/PublicationNavigationServiceTest.php

## Credits

Custom module developed for the However project by Richard Gilbert.
