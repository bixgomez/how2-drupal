<?php

namespace Drupal\prf_ckeditor_footnotes\Plugin\CKEditorPlugin;

use Drupal\ckeditor\CKEditorPluginBase;
use Drupal\ckeditor\CKEditorPluginConfigurableInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\editor\Entity\Editor;

/**
 * Defines the "footnotes" plugin.
 *
 * @CKEditorPlugin(
 *   id = "footnotes",
 *   label = @Translation("CKEditor Footnotes Button")
 * )
 */
class Footnotes extends CKEditorPluginBase {

    /**
     * Get path to library folder.
     */
    public function getLibraryPath() {

        if (\Drupal::hasService('library.libraries_directory_file_finder')) {
            /** @var \Drupal\Core\Asset\LibrariesDirectoryFileFinder $library_file_finder */
            $path = \Drupal::service('library.libraries_directory_file_finder')->find('footnotes');
        }
        elseif (\Drupal::moduleHandler()->moduleExists('libraries')) {
            $path = libraries_get_path('footnotes');
        }
        else {
            $path = 'libraries/footnotes';
        }
        return $path;
    }

    /**
     * {@inheritdoc}
     */
    public function getFile() {
        return $this->getLibraryPath() . '/plugin.js';
    }

    /**
     * {@inheritdoc}
     */
    public function getButtons() {
        $path = $this->getLibraryPath();
        return [
            'Footnotes' => [
                'label' => $this->t('Footnotes'),
                'image' => $path . '/icons/footnotes.png',
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig(Editor $editor) {
        return [];
    }

}
