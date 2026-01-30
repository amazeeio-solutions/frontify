<?php

namespace Drupal\frontify\Plugin\Field\FieldWidget;

use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\link\Plugin\Field\FieldWidget\LinkWidget;

/**
 * Frontify asset field widget.
 *
 * @todo make it specific to Frontify Image, images doesn't apply to other media types.
 */
#[FieldWidget(
  id: "frontify_asset_field_widget",
  label: new TranslatableMarkup("Frontify asset"),
  description: new TranslatableMarkup("Exposes Frontify asset fields."),
  field_types: ["frontify_asset_field"],
)]
class FrontifyAssetFieldWidget extends LinkWidget {

  protected const int PREVIEW_IMAGE_WIDTH = 400;

  /**
   * Form element validation handler for the 'uri' element.
   *
   * Disallows saving inaccessible or untrusted URLs.
   */
  public static function validateUriElement($element, FormStateInterface $form_state, $form): void {
    $uri = static::getUserEnteredStringAsUri($element['#value']);
    $form_state->setValueForElement($element, $uri);
    if ($element['#required'] && !$uri) {
      $form_state->setError($element, t('Field @fieldname is required.', ['@fieldname' => $element['#title']]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as &$value) {
      $value['uri'] = static::getUserEnteredStringAsUri($value['uri']);
      $value['name'] = $value['frontify_fields']['name'];
      $value['id'] = $value['frontify_fields']['id'];
      $value['metadata'] = $value['frontify_fields']['metadata'];
      $value += ['options' => []];
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(
    FieldItemListInterface $items,
    $delta,
    array $element,
    array &$form,
    FormStateInterface $form_state
  ): array {
    $config = \Drupal::config('frontify.settings');
    $item = $items[$delta];

    $previewImageUri = $item->uri;
    if (!empty($previewImageUri)) {
      $previewImageUri .= '?width=' . self::PREVIEW_IMAGE_WIDTH;
    }

    $element['frontify_preview'] = [
      '#theme' => 'image',
      '#uri' => $previewImageUri,
      '#alt' => $item->name,
      '#title' => $item->name,
      '#attributes' => [
        'class' => 'frontify-image-preview',
      ],
    ];

    $element['frontify_wrapper_overlay'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['frontify-wrapper-finder-overlay'],
      ],
    ];

    $element['frontify_wrapper_overlay']['frontify_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['frontify-finder-wrapper'],
      ],
    ];

    $parent = $items->getParent();
    $entityTypeId = $parent instanceof EntityAdapter ? $parent->getEntity()->getEntityTypeId() : '';

    $element['open'] = [
      '#type' => 'button',
      '#value' => $this->t('Open Frontify'),
      '#attributes' => [
        'class' => ['frontify-finder-open-button', 'btn', 'btn-primary'],
        'id' => ['frontify-asset-insert-button'],
      ],
      '#attached' => [
        'library' => [
          'frontify/frontify_once',
          'frontify/frontify_entity_form',
        ],
        'drupalSettings' => [
          'Frontify' => [
            'context' => 'entity_form',
            'api_url' => $config->get('frontify_api_url'),
            'debug_mode' => $config->get('debug_mode'),
            'preview_image_width' => self::PREVIEW_IMAGE_WIDTH,
            'parent_entity_type_id' => $entityTypeId,
          ],
        ],
      ],
      '#limit_validation_errors' => [],
    ];

    $element['uri'] = [
      '#type' => 'url',
      '#default_value' => $item->uri,
      '#element_validate' => [[static::class, 'validateUriElement']],
      '#maxlength' => 2048,
      '#attributes' => [
        'class' => ['frontify-asset-link-url', 'hidden'],
      ],
    ];
    $element['frontify_fields'] = [
      '#type' => 'details',
      '#title' => $this->t('Frontify fields'),
      '#open' => FALSE,
      '#attributes' => [
        'class' => ['frontify-asset-details'],
      ],
    ];
    $element['frontify_fields']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $item->name ?? NULL,
      '#maxlength' => 255,
      '#attributes' => [
        'class' => ['frontify-asset-name'],
      ],
    ];
    $element['frontify_fields']['id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ID'),
      '#default_value' => $item->id ?? NULL,
      '#maxlength' => 255,
      '#attributes' => [
        'class' => ['frontify-asset-id'],
      ],
    ];
    // @todo we could optionally use a JSON field.
    $element['frontify_fields']['metadata'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Metadata'),
      '#default_value' => $item->metadata ?? NULL,
      '#description' => $this->t(
        'Snapshot of the metadata used during the import.'
      ),
      '#attributes' => [
        'class' => ['frontify-asset-metadata'],
      ],
    ];

    $element += [
      '#type' => 'fieldset',
    ];

    return $element;
  }

}
