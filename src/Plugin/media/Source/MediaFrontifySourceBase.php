<?php

namespace Drupal\frontify\Plugin\media\Source;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\media\MediaInterface;
use Drupal\media\MediaSourceBase;
use Drupal\media\MediaSourceFieldConstraintsInterface;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Mime\MimeTypes;

abstract class MediaFrontifySourceBase extends MediaSourceBase implements MediaSourceFieldConstraintsInterface {

  /**
   * Key for "Name" metadata attribute.
   *
   * @var string
   */
  const METADATA_ATTRIBUTE_NAME = 'name';

  const THUMBNAIL_QUALITY = 90;

  // @2x for default Drupal Media Library thumbnail image style.
  const THUMBNAIL_WIDTH = 440;
  const THUMBNAIL_HEIGHT = 440;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ): MediaSourceBase|FrontifyImage|ContainerFactoryPluginInterface|static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('plugin.manager.field.field_type'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadataAttributes() {
    return [
      static::METADATA_ATTRIBUTE_NAME => $this->t('Name'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(MediaInterface $media, $attribute_name) {
    $url = $media->get($this->configuration['source_field'])->uri;

    switch ($attribute_name) {
      // Name is already set in FrontifyMediaImageForm::createMediaFromValues().
      case 'thumbnail_width':
        return static::THUMBNAIL_WIDTH;

      case 'thumbnail_height':
        return static::THUMBNAIL_HEIGHT;

      case 'thumbnail_uri':
        if (!$this->supportsThumbnail($url)) {
          return 'public://media-icons/generic/no-thumbnail.png';
        }
        $uri = $this->getLocalThumbnailUri(
          $url . '?width=' . static::THUMBNAIL_WIDTH . '&quality=' . static::THUMBNAIL_QUALITY
        ) ?: parent::getMetadata($media, 'thumbnail_uri');
        return $uri;

      case 'thumbnail_alt_value':
        // Name is fine for this use case.
        return $media->getName();

      default:
        return parent::getMetadata($media, $attribute_name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSourceFieldConstraints(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['deduplicate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Deduplicate'),
      '#default_value' => $this->configuration['deduplicate'],
      '#description' => $this->t('When inserting a Media reference in host entities, do not import from Frontify and use the existing Media if it already exists. Also validate the uniqueness of the Frontify ID per media type when adding via the global media library.'),
    ];

    $form['disable_global_add'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Prevent to add globally [not implemented yet]'),
      '#default_value' => $this->configuration['disable_global_add'],
      '#access' => FALSE,
      '#description' => $this->t('Disable the global "Add" feature (example: /media/add/frontify_image). When using a DAM, it make sense to only add a reference via host entities and not create them globally. This is especially the case since we replace the Media Library with the Frontify Finder.'),
    ];

    $form['allowed_extensions'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Allowed extensions'),
      '#default_value' => $this->configuration['allowed_extensions'],
      '#description' => $this->t('Allowed extensions, space delimited.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $configuration = $this->getConfiguration();
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return parent::defaultConfiguration() + [
      'deduplicate' => 1,
      'disable_global_add' => 0,
      'allowed_extensions' => 'gif jpeg jpg png svg webp',
    ];
  }

  /**
   * Checks if a given uri supports the thumbnail generation.
   *
   * Use cases: svg and other unsupported formats.
   *
   * @param string $uri
   *
   * @return bool
   */
  private function supportsThumbnail(string $uri): bool {
    $path = parse_url($uri, PHP_URL_PATH);
    if ($path) {
      $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));
      if ($extension && in_array($extension, ['jpg', 'jpeg', 'gif', 'png', 'webp'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns the local URI for a resource thumbnail.
   *
   * If the thumbnail is not already locally stored, this method will attempt
   * to download it.
   *
   * @param string $url
   *   The thumbnail URL.
   *
   * @return string|null
   *   The local thumbnail URI, or NULL if it could not be downloaded, or if the
   *   resource has no thumbnail at all.
   */
  private function getLocalThumbnailUri(string $url): ?string {
    $directoryName = 'frontify_image_thumbnails';
    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = \Drupal::service('file_system');
    $directory = PublicStream::basePath() . DIRECTORY_SEPARATOR . $directoryName;
    $logger = \Drupal::service('logger.factory')->get('frontify');
    // The local thumbnail doesn't exist yet, so try to download it.
    // First, ensure that the destination directory is writable or
    // log an error and bail out.
    if (!$fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      $logger->warning('Could not prepare thumbnail destination directory @dir for Frontify media.', [
        '@dir' => $directory,
      ]);
      return NULL;
    }

    // The local filename of the thumbnail is a hash of its remote URL.
    // If a file with that name already exists in the thumbnails directory,
    // regardless of its extension, return its URI.
    $hash = Crypt::hashBase64($url);
    $files = $fileSystem->scanDirectory($directory, "/^{$hash}\..*/");
    if (count($files) > 0) {
      $file = reset($files);
      $local_thumbnail_uri = 'public://' . $directoryName . '/' . $file->filename;
      return $local_thumbnail_uri;
    }

    // The local thumbnail doesn't exist yet, so we need to download it.
    try {
      $response = \Drupal::httpClient()->request('GET', $url);
      if ($response->getStatusCode() === 200) {
        $local_thumbnail_uri = $directory . DIRECTORY_SEPARATOR . $hash . '.' . $this->getThumbnailFileExtensionFromUrl($url, $response);
        $fileSystem->saveData((string) $response->getBody(), $local_thumbnail_uri, FileExists::Replace);
        $local_thumbnail_uri = 'public://' . $directoryName . '/' . $hash . '.' . $this->getThumbnailFileExtensionFromUrl($url, $response);
        return $local_thumbnail_uri;
      }
    }
    catch (TransferException $e) {
      $logger->warning($e->getMessage());
    }
    catch (FileException $e) {
      $logger->warning('Could not download remote thumbnail from :url. Error: @message.', [
        ':url' => $url,
        '@message' => $e->getMessage(),
      ]);
    }
    return NULL;
  }

  /**
   * Tries to determine the file extension of a thumbnail.
   *
   * @param string $thumbnail_url
   *   The remote URL of the thumbnail.
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The response for the downloaded thumbnail.
   *
   * @return string|null
   *   The file extension, or NULL if it could not be determined.
   */
  private function getThumbnailFileExtensionFromUrl(string $thumbnail_url, ResponseInterface $response): ?string {
    // First, try to glean the extension from the URL path.
    $path = parse_url($thumbnail_url, PHP_URL_PATH);
    if ($path) {
      $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));
      if ($extension) {
        return $extension;
      }
    }

    // If the URL didn't give us any clues about the file extension, see if the
    // response headers will give us a MIME type.
    $content_type = $response->getHeader('Content-Type');
    // If there was no Content-Type header, there's nothing else we can do.
    if (empty($content_type)) {
      return NULL;
    }
    $extensions = MimeTypes::getDefault()->getExtensions(reset($content_type));
    if ($extensions) {
      return reset($extensions);
    }
    // If no file extension could be determined from the Content-Type header,
    // we're stumped.
    return NULL;
  }

}
