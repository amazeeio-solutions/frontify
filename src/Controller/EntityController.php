<?php

namespace Drupal\frontify\Controller;

use Drupal\Core\Entity\Controller\EntityController as CoreEntityController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Overrides addPage method to prevent to add disabled media types.
 */
class EntityController extends CoreEntityController {

  /**
   * {@inheritDoc}
   */
  public function addPage($entity_type_id, ?Request $request = NULL) {
    $build = parent::addPage($entity_type_id);
    if ($entity_type_id !== 'media') {
      return $build;
    }

    // @fixme this section can throw in several cases,
    //   e.g. when Frontify is the sole media bundle and adding a new media.
    //   it is currently disabled in the MediaTypeRouteSubscriber
//    $bundles = $this->entityTypeBundleInfo->getBundleInfo($entity_type_id);
//    foreach ($bundles as $bundle_id => $bundle_info) {
//      $media_type = $this->entityTypeManager->getStorage('media_type')->load($bundle_id);
//      $media_type_configuration = $media_type->getSource()->getConfiguration();
//      $disable_global_add = !empty($media_type_configuration['disable_global_add']) &&
//        $media_type_configuration['disable_global_add'] === 1;
//
//      if ($disable_global_add && !empty($build['#bundles'][$bundle_id])) {
//        unset($build['#bundles'][$bundle_id]);
//      }
//    }

    return $build;
  }

}
