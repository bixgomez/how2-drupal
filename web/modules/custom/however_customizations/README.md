# However Customizations

A custom Drupal module that provides specialized functionality for the However project.

## Features

### Auto-generated Volume Titles

Automatically generates titles for volume content types based on their volume number:

- **however_volume**: Titles are formatted as "How(ever) Volume #"
- **how2_volume**: Titles are formatted as "How2 Volume #"

The title field is set to read-only in the UI with explanatory text.

### Volume Number Propagation

Automatically propagates volume numbers from volume entities to issue entities:

- When a `how2_issue` or `journal_issue` node references a volume, its `field_volume_number` is updated to match the referenced volume's number
- This ensures consistency across the site

### Enhanced Entity Autocomplete

Extends Drupal's entity autocomplete to include additional information in the dropdown results:

- Shows volume and issue information in autocomplete menus
- Makes content easier to find and identify

## Drush Commands

### `however-customizations:update-volume-numbers`

**Alias**: `how-vol`

Updates volume numbers for all `how2_issue` and `journal_issue` nodes based on their volume references.

Usage:
```
drush however-customizations:update-volume-numbers
drush how-vol
```

## Installation

1. Place the module in your Drupal installation under `/modules/custom/`
2. Enable the module using Drush or the Drupal admin interface:
   ```
   drush en however_customizations
   ```

## Requirements

- Drupal 9 or 10

## Troubleshooting

- If titles aren't generating correctly, make sure the `field_volume_number` exists and has a value
- After making changes to the module, clear cache with `drush cr`

## Credits

Custom module developed for the However project.
