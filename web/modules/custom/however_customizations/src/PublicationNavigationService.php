<?php

namespace Drupal\however_customizations;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service to handle navigation (prev/next links) for publication content.
 */
class PublicationNavigationService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a PublicationNavigationService object.
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

  /**
   * Get previous and next issues for a given issue.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The issue node.
   *
   * @return array
   *   An array with 'prev' and 'next' keys, each containing a node or NULL.
   */
  public function getIssueNavigation($node) {
    $result = [
      'prev' => NULL,
      'next' => NULL,
    ];
    
    // Only process issue content types.
    $issue_types = ['journal_issue', 'how2_issue'];
    if (!in_array($node->bundle(), $issue_types)) {
      return $result;
    }
    
    // Get the volume number and issue number.
    if (!$node->hasField('field_volume_number') || $node->field_volume_number->isEmpty() ||
        !$node->hasField('field_issue_number') || $node->field_issue_number->isEmpty()) {
      return $result;
    }
    
    $volume_number = (int) $node->field_volume_number->value;
    $issue_number = (int) $node->field_issue_number->value;
    $bundle = $node->bundle();
    
    // Find previous issue (in same volume with issue_number - 1)
    $prev_nodes = $this->findIssueByNumbers($bundle, $volume_number, $issue_number - 1);
    if (!empty($prev_nodes)) {
      $result['prev'] = reset($prev_nodes);
    } 
    // If no previous issue in same volume, try to find the last issue of previous volume
    else {
      $last_issue = $this->findLastIssueInVolume($bundle, $volume_number - 1);
      if ($last_issue) {
        $result['prev'] = $last_issue;
      }
    }
    
    // Find next issue (in same volume with issue_number + 1)
    $next_nodes = $this->findIssueByNumbers($bundle, $volume_number, $issue_number + 1);
    if (!empty($next_nodes)) {
      $result['next'] = reset($next_nodes);
    } 
    // If no next issue in same volume, try to find the first issue of next volume
    else {
      $first_issue = $this->findFirstIssueInVolume($bundle, $volume_number + 1);
      if ($first_issue) {
        $result['next'] = $first_issue;
      }
    }
    
    return $result;
  }

  /**
   * Find an issue node by its volume and issue numbers.
   *
   * @param string $bundle
   *   The bundle (content type).
   * @param int $volume_number
   *   The volume number to find.
   * @param int $issue_number
   *   The issue number to find.
   *
   * @return array
   *   Array of nodes found (usually just one).
   */
  protected function findIssueByNumbers($bundle, $volume_number, $issue_number) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', $bundle)
      ->condition('field_volume_number', $volume_number)
      ->condition('field_issue_number', $issue_number)
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

  /**
   * Find the last issue in a specific volume.
   *
   * @param string $bundle
   *   The bundle (content type).
   * @param int $volume_number
   *   The volume number to find.
   *
   * @return \Drupal\node\Entity\Node|null
   *   The last issue node or NULL if none found.
   */
  protected function findLastIssueInVolume($bundle, $volume_number) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', $bundle)
      ->condition('field_volume_number', $volume_number)
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->sort('field_issue_number', 'DESC')
      ->range(0, 1);
      
    $nids = $query->execute();
    
    if (empty($nids)) {
      return NULL;
    }
    
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    return reset($nodes);
  }

  /**
   * Find the first issue in a specific volume.
   *
   * @param string $bundle
   *   The bundle (content type).
   * @param int $volume_number
   *   The volume number to find.
   *
   * @return \Drupal\node\Entity\Node|null
   *   The first issue node or NULL if none found.
   */
  protected function findFirstIssueInVolume($bundle, $volume_number) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', $bundle)
      ->condition('field_volume_number', $volume_number)
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->sort('field_issue_number', 'ASC')
      ->range(0, 1);
      
    $nids = $query->execute();
    
    if (empty($nids)) {
      return NULL;
    }
    
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    return reset($nodes);
  }
}
