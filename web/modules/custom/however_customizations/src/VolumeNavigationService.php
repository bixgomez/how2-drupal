<?php

namespace Drupal\however_customizations;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service to handle volume navigation (prev/next links).
 */
class VolumeNavigationService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a VolumeNavigationService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Get previous and next volumes for a given volume.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The volume node.
   *
   * @return array
   *   An array with 'prev' and 'next' keys, each containing a node or NULL.
   */
  public function getVolumeNavigation($node) {
    $result = [
      'prev' => NULL,
      'next' => NULL,
    ];
    
    // Only process volume content types.
    $volume_types = ['however_volume', 'how2_volume'];
    if (!in_array($node->bundle(), $volume_types)) {
      return $result;
    }
    
    // Get the volume number.
    if (!$node->hasField('field_volume_number') || $node->field_volume_number->isEmpty()) {
      return $result;
    }
    
    $volume_number = (int) $node->field_volume_number->value;
    $bundle = $node->bundle();
    
    // Find previous volume (volume_number - 1).
    $prev_nodes = $this->findVolumeByNumber($bundle, $volume_number - 1);
    if (!empty($prev_nodes)) {
      $result['prev'] = reset($prev_nodes);
    }
    
    // Find next volume (volume_number + 1).
    $next_nodes = $this->findVolumeByNumber($bundle, $volume_number + 1);
    if (!empty($next_nodes)) {
      $result['next'] = reset($next_nodes);
    }
    
    return $result;
  }
  
  /**
   * Find a volume node by its volume number.
   *
   * @param string $bundle
   *   The bundle (content type).
   * @param int $volume_number
   *   The volume number to find.
   *
   * @return array
   *   Array of nodes found (usually just one).
   */
  protected function findVolumeByNumber($bundle, $volume_number) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', $bundle)
      ->condition('field_volume_number', $volume_number)
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->sort('created', 'ASC')
      ->range(0, 1);
      
    $nids = $query->execute();
    
    if (empty($nids)) {
      return [];
    }
    
    return $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
  }
}
