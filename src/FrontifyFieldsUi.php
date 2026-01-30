<?php

declare(strict_types=1);

namespace Drupal\frontify;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Configuration class for FrontifyFieldsUi.
 * If nothing is set then the default values will be used.
 */
final class FrontifyUiConfig {
  public ?string $frontify_wrapper_class = NULL;
  public ?string $trigger_element = NULL;
  public ?string $trigger_event = NULL;
  public ?string $select_add_button_class = NULL;
  public bool $hide_open_button = TRUE;
  public bool $enable_image_preview = FALSE;
  public string $frontify_context = 'media_library';
  public array $allowed_extensions = [];

}

/**
 * Builds the Frontify UI fields.
 * You can change one thing here and have it reflected across the entire UI.
 */
final class FrontifyFieldsUi {

  use StringTranslationTrait;

  /**
   *
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   *
   */
  public function getFieldsUi(FrontifyUiConfig $config): array {
    $fields = [];

    $drupalConfig = \Drupal::config('frontify.settings');
    $frontifyApiUrl = $drupalConfig->get('frontify_api_url');
    $frontifyDebugMode = $drupalConfig->get('debug_mode');

    if (empty($frontifyApiUrl)) {
      $fields['message'] = [
        '#theme' => 'status_messages',
        '#message_list' => [
          'error' => [$this->t('Frontify API URL is not set. Please configure it in the Frontify settings.')],
        ],
      ];
      return $fields;
    }

    // Add a container to group the input elements for styling purposes.
    $containerClasses = ['frontify-media-library-wrapper'];
    if ($config->frontify_context === 'media_library') {
      // Hide by default until Frontify finder is ready.
      // This prevents a flash of content when opening the Media library
      // and the user to interact with Drupal import
      // before having the finder fully loaded.
      $containerClasses[] = 'visually-hidden';
    }

    $fields['container'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => $containerClasses,
      ],
    ];

    $fields['container']['open'] = [
      '#type' => 'button',
      '#value' => $this->t('Open Frontify'),
      '#attributes' => [
        'class' => ['frontify-finder-open-button', 'btn', 'btn-primary'],
        'id' => ['frontify-finder-open-button'],
      ],
      '#attached' => [
        'library' => [
          'frontify/frontify_once',
          'frontify/frontify_media_library',
        ],
        'drupalSettings' => [
          'Frontify' =>
            [
              'api_url' => $frontifyApiUrl,
              'debug_mode' => $frontifyDebugMode === 1,
              'hide_open_button' => $config->hide_open_button,
              'enable_image_preview' => $config->enable_image_preview,
              'allowed_extensions' => $config->allowed_extensions,
            ],
        ],
      ],
      '#limit_validation_errors' => [],
    ];

    if ($config->frontify_wrapper_class) {
      $fields['container']['open']['#attached']['drupalSettings']['Frontify']['wrapper_class'] = $config->frontify_wrapper_class;
    }

    if ($config->frontify_context) {
      $fields['container']['open']['#attached']['drupalSettings']['Frontify']['context'] = $config->frontify_context;
    }

    if ($config->trigger_element) {
      $fields['container']['open']['#attached']['drupalSettings']['Frontify']['trigger_element'] = $config->trigger_element;
    }

    if ($config->trigger_event) {
      $fields['container']['open']['#attached']['drupalSettings']['Frontify']['trigger_event'] = $config->trigger_event;
    }

    if ($config->select_add_button_class) {
      $fields['container']['open']['#attached']['drupalSettings']['Frontify']['select_add_button_class'] = $config->select_add_button_class;
    }

    $fields['container']['frontify_finder_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['frontify-finder-wrapper'],
      ],
    ];

    $fields['container']['name_wrapper']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Frontify image name'),
      '#maxlength' => 255,
      '#required' => TRUE,
      '#attributes' => [
        'class' => ['frontify-name'],
      ],
    ];
    $fields['container']['image_preview_wrapper']['image_preview'] = [
      '#type' => 'markup',
      '#markup' => '<div class="frontify-image-preview"></div>',
    ];
    $fields['container']['frontify_fields'] = [
      '#title' => $this->t('Frontify fields'),
      '#type' => 'details',
      '#open' => FALSE,
      '#attributes' => [
        'class' => [
          'frontify-fields',
          $frontifyDebugMode !== 1 ? 'visually-hidden' : '',
        ],
      ],
    ];
    $fields['container']['frontify_fields']['id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Frontify image id'),
      '#maxlength' => 255,
      '#required' => TRUE,
      '#attributes' => [
        'class' => ['frontify-id'],
      ],
    ];
    $fields['container']['frontify_fields']['uri'] = [
      '#type' => 'url',
      '#title' => $this->t('Frontify image URL'),
      '#maxlength' => 2048,
      '#required' => TRUE,
      '#attributes' => [
        'class' => ['frontify-image-uri'],
      ],
    ];
    $fields['container']['frontify_fields']['metadata'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Frontify image metadata'),
      '#required' => TRUE,
      '#attributes' => [
        'class' => ['frontify-image-metadata'],
      ],
    ];

    return $fields;
  }

}
