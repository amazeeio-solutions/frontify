<?php

declare(strict_types=1);

namespace Drupal\frontify\EventSubscriber;

use Drupal\Core\Routing\RouteSubscriberBase;
// Use Drupal\frontify\Controller\EntityController;.
use Symfony\Component\Routing\RouteCollection;

/**
 * Route subscriber.
 */
final class MediaTypeRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    // // Removes disabled media types from the add media page.
    //    if ($route = $collection->get('entity.media.add_page')) {
    //      $route->setDefault('_controller', EntityController::class . '::addPage');
    //    }
    // Dynamic permission for the media add form.
    // Prevents to use /media/add/[media_type] if it's disabled
    // on the media type source configuration.
    // Propagates to the admin menu Content > Media > Add media > [media_type].
    // We don't want to remove the permission to add media types but just
    // prevent to add globally and still allow media to be added
    // from host entities.
    if ($route = $collection->get('entity.media.add_form')) {
      $route->setRequirement('_access_frontify_global_add', 'TRUE');
    }
  }

}
