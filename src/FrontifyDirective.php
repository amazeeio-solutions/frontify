<?php

declare(strict_types = 1);

namespace Drupal\frontify;

use Drupal\Component\Serialization\Json;
use Drupal\graphql_directives\DirectiveArguments;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;

/**
 * Frontify directives.
 *
 * Integration with GraphQL v4 directives to produce responsive images.
 *
 * Port of Drupal\silverback_cloudinary\Plugin\GraphQL\DataProducer\ResponsiveImage.
 */
final class FrontifyDirective {

  const QUALITY = 90;

  /**
   * Returns Frontify original image props.
   *
   * @param \Drupal\graphql_directives\DirectiveArguments $args
   *
   * @return array|null
   */
  public function imageProps(DirectiveArguments $args): ?array {
    if (empty($args->value)) {
      return NULL;
    }

    // The value can be a media, skip CDN calls in this case
    // as we should already have the metadata.
    // Usage example:
    // @frontifyImageProps on a Media entity.
    if ($args->value instanceof MediaInterface) {
      $media = $args->value;
      if (
        !$media instanceof MediaInterface ||
        !$media->hasField('field_media_frontify_image') ||
        $media->get('field_media_frontify_image')->isEmpty()
      ) {
        return NULL;
      }
      $imageUrl = $media->get('field_media_frontify_image')->uri;
      $rawMetadata = $media->get('field_media_frontify_image')->metadata;
      $metadata = Json::decode($rawMetadata);
      $width = !empty($metadata['width']) ? $metadata['width'] : NULL;
      $height = !empty($metadata['height']) ? $metadata['height'] : NULL;

      // Favour dynamicPreviewUrl if available.
      // https://help.frontify.com/en/articles/8699687-cdn-links
      if (!empty($metadata['dynamicPreviewUrl'])) {
        $imageUrl = $metadata['dynamicPreviewUrl'];
      }
    // If this is a string, use getimagesize().
    // Usage example:
    // @resolveProperty(path: "field_media_frontify_image.uri") @frontifyImageProps
    } elseif (is_string($args->value)) {
      $imageUrl = $args->value;
      $imageSize = getimagesize($imageUrl);
      $width = $imageSize ? $imageSize[0] : NULL;
      $height = $imageSize ? $imageSize[1] : NULL;
    // Ignore the rest.
    } else {
      return NULL;
    }

    // Image size is not available in some cases, example: SVG.
    if (!$width || !$height) {
      return [
        'src' => $imageUrl,
        'width' => NULL,
        'height' => NULL,
        'focalPoint' => NULL,
      ];
    }

    return [
      'src' => $imageUrl,
      'width' => $width,
      'height' => $height,
      'focalPoint' => $this->getFocalPoint($imageUrl, TRUE),
    ];
  }

  /**
   * Returns Frontify responsive image props.
   *
   * @param \Drupal\graphql_directives\DirectiveArguments $args
   *
   * @return string|null
   */
  public function frontifyResponsiveImage(DirectiveArguments $args): ?string {
    if (empty($args->value)) {
      return NULL;
    }

    $image = $args->value;
    $originalImageWidth = $image['width'];
    $originalImageHeight = $image['height'];
    $focalPoint = $image['focalPoint'];

    $width = $args->args['width'];
    // Height can be null, we will use the ratio to set it in this case.
    $height = $args->args['height'];
    $sizes = $args->args['sizes'] ?? [];

    $result['src'] = $image['src'];
    $result['originalSrc'] = $image['src'];

    // If no width is given we just return the original image url.
    if (empty($width) || empty($originalImageWidth)) {
      // Prevent to encode empty values
      // that will make the Frontify query fail.
      if (!empty($image['width'])) {
        $result['width'] = $image['width'];
      }
      if (!empty($image['height'])) {
        $result['height'] = $image['height'];
      }
      if (!empty($image['focalPoint'])) {
        $result['fp'] = $image['focalPoint'];
      }
      return Json::encode($result);
    }

    $ratio = $originalImageHeight / $originalImageWidth;
    // The image width and height in the response should be the same as the ones
    // sent as parameters.
    // @todo Unless the width sent is bigger than the width of the original
    // image, since we should not scale up. TBD what to do in this case.
    $result['width'] = $width;
    $result['height'] = $height ?: (int) round($width * $ratio);

    $result['src'] = $this->getFrontifyImageUrl(
      $image['src'],
      ['width' => $result['width'], 'height' => $result['height']],
      $focalPoint
    );

    if (!empty($sizes)) {
      $result['sizes'] = $this->buildSizesString($sizes, $result['width']);
      $result['srcset'] = $this->buildSrcSetString(
        $image['src'],
        $sizes,
        ['width' => $result['width'], 'height' => $result['height']],
        $focalPoint
      );
    }

    $result = array_filter($result);

    return $result ? Json::encode($result) : NULL;
  }

  /**
   * Get the focal point from the Frontify API.
   *
   * @param string $src
   *   Frontify url.
   * @param bool $from_cache
   *   API calls can take some time, it might be the preferred way to not use
   *   the cache, because focal point can change, but that can cause
   *   timeout during SSG.
   *
   * @return float[]
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function getFocalPoint(string $src, bool $from_cache = FALSE): array {
    $result = [0.5, 0.5]; // fallback.

    // @todo we are assuming here a given field name, get it from the entity bundles that are relevant.
    $frontifyField = 'field_media_frontify_image';
    $mediaStorage = \Drupal::entityTypeManager()->getStorage('media');

    $mediaImages = $mediaStorage->loadByProperties([$frontifyField . '.uri' => $src]);
    $mediaImage = NULL;
    if (!empty($mediaImages)) {
      // Always get the latest instance of a Frontify
      // image if deduplicate is not configured.
      $mediaImage = end($mediaImages);
    }

    if (
      $mediaImage instanceof MediaInterface &&
      $mediaImage->hasField($frontifyField) &&
      !$mediaImage->get($frontifyField)->isEmpty()
    ) {
      // Load focal point from Drupal snapshot that was done
      // during initial import.
      if ($from_cache) {
        $frontifyMetadata = json_decode($mediaImage->get($frontifyField)->metadata);
        $result = $frontifyMetadata->focalPoint ?? $result;
      }
      else {
        $frontifyId = $mediaImage->get($frontifyField)->id;
        /** @var \Drupal\frontify\FrontifyApi $frontifyApi */
        $frontifyApi = \Drupal::service('frontify.api');
        $result = $frontifyApi->getFocalPoint($frontifyId) ?? $result;
      }
    }

    return $result;
  }

  /**
   * Builds a sizes string from a sizes array.
   *
   * @param array $sizes
   *   An array of image sizes.
   *   Example: [
   *    [400, 390] -> up until 400px screen width, use the 390px image
   *    [800, 780] -> up until 800px screen width, use the 780px image
   *   ].
   * @param int $default_width
   *   The default width to add at the end of the $sizes string.
   *
   * @return string
   */
  protected function buildSizesString(array $sizes, $default_width = NULL): string {
    if (empty($sizes)) {
      return '';
    }
    $sizeEntries = array_reduce($sizes, function ($carry, $sizesElement) {
      // Each size must have exactly 2 elements.
      if (count($sizesElement) !== 2) {
        return $carry;
      }
      $carry[] = "(max-width: $sizesElement[0]px) $sizesElement[1]px";
      return $carry;
    }, []);

    // At the end, add the default width.
    if (!empty($default_width)) {
      $sizeEntries[] = $default_width . 'px';
    }
    return !empty($sizeEntries) ? implode(', ', $sizeEntries) : '';
  }

  /**
   * Builds a srcset string for an original image, based on a sizes array.
   *
   * @param string $originalUrl
   *   The original image url.
   * @param array $sizes
   *   A sizes array, same is in buildSizesString().
   * @param array $defaultDimensions
   *   The default dimensions (width and, optionally, height) of the image so
   *   that we can compute the height of each of the image in the src set, by
   *   preserving the aspect ratio.
   * @param array $focalPoint
   *
   * @return string
   */
  protected function buildSrcSetString($originalUrl, array $sizes, array $defaultDimensions = [], array $focalPoint = []): string {
    if (empty($sizes)) {
      return '';
    }

    $srcSetEntries = array_reduce($sizes, function ($carry, $sizesElement) use ($defaultDimensions, $originalUrl, $focalPoint) {
      // Each size must have exactly 2 elements.
      if (count($sizesElement) !== 2) {
        return $carry;
      }
      $imageConfig = [
        'width' => $sizesElement[1],
      ];

      // If we know the default dimensions of the image, and the width of the
      // desired one, we can also calculate the height of it.
      if (!empty($defaultDimensions['width']) && !empty($defaultDimensions['height'])) {
        $imageConfig['height'] = (int) round(($imageConfig['width'] * $defaultDimensions['height']) / $defaultDimensions['width']);
      }
      $carry[] = $this->getFrontifyImageUrl($originalUrl, $imageConfig, $focalPoint) . ' ' . $imageConfig['width'] . 'w';
      return $carry;
    }, []);

    if (empty($srcSetEntries)) {
      return '';
    }
    return implode(', ', $srcSetEntries);
  }

  /**
   * Helper method to return a simple Frontify image url for an image url and
   * a config array.
   *
   * The config array can contain a width and a height.
   *
   * @param string $originalUrl
   * @param array $config
   * @param array $focalPoint
   *
   * @return string
   */
  protected function getFrontifyImageUrl(string $originalUrl, array $config = [], array $focalPoint = []): string {
    $result = $originalUrl . '?quality=' . self::QUALITY;
    if (!empty($focalPoint) && !empty($config['width']) && !empty($config['height'])) {
      $result .= '&crop=fp&fp=' . implode(',', $focalPoint);
    }

    if (empty($config['width']) && empty($config['height'])) {
      return $result;
    }

    if (!empty($config['width'])) {
      $result .= '&width=' . $config['width'];
    }
    if (!empty($config['height'])) {
      $result .= '&height=' . $config['height'];
    }

    return $result;
  }

}
