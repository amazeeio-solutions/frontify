<?php

namespace Drupal\frontify_entity_browser\Plugin\EntityBrowser\Widget;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\entity_browser\Events\EntitySelectionEvent;
use Drupal\entity_browser\Events\Events;
use Drupal\entity_browser\WidgetBase;
use Drupal\frontify\FrontifyUiConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Exposes the Frontify Finder.
 *
 * @EntityBrowserWidget(
 *   id = "frontify_finder",
 *   label = @Translation("Frontify Finder"),
 *   description = @Translation("Expose the Frontify Finder."),
 *   auto_select = TRUE
 * )
 */
class FrontifyFinder extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return array_merge(parent::defaultConfiguration(), [
      'media_type' => '',
      'submit_text' => $this->t('Select'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getForm(array &$original_form, FormStateInterface $form_state, array $additional_widget_parameters) {
    $form = parent::getForm($original_form, $form_state, $additional_widget_parameters);

    /** @var \Drupal\frontify\FrontifyFieldsUi $frontifyFieldUiService */
    $frontifyFieldUiService = \Drupal::service('frontify.fields.ui');

    // Configuration for Frontify UI.
    $frontifyUiConfig = new FrontifyUiConfig();
    $frontifyUiConfig->frontify_wrapper_class = '.entity-browser-form';
    $frontifyUiConfig->trigger_element = '[id="frontify-finder-open-button"]';
    $frontifyUiConfig->trigger_event = 'auto_select_entity_browser_widget';
    $frontifyUiConfig->select_add_button_class = '[id="edit-submit"]';
    $frontifyUiConfig->hide_open_button = FALSE;
    $frontifyUiConfig->enable_image_preview = TRUE;

    $fields = $frontifyFieldUiService->getFieldsUi($frontifyUiConfig);

    if (isset($fields['message'])) {
      return $fields;
    }

    $form = array_merge($form, $fields);

    // Move the open button from container to actions.
    $form['actions']['open'] = $form['container']['open'];
    unset($form['container']['open']);

    // Add hidden element used to make execution of auto-select of form.
    $form['auto_select_handler'] = [
      '#type' => 'hidden',
      '#name' => 'auto_select_handler',
      '#id' => 'auto_select_handler',
      '#attributes' => ['id' => 'auto_select_handler'],
      '#submit' => ['::submitForm'],
      '#executes_submit_callback' => TRUE,
      '#ajax' => [
        'wrapper' => 'auto_select_handler',
        'callback' => [get_class($this), 'handleAjaxCommand'],
        'event' => 'auto_select_entity_browser_widget',
        'progress' => [
          'type' => 'fullscreen',
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareEntities(array $form, FormStateInterface $form_state) {
    $entities = [];
    $media_type = $this->getType();
    $media_storage = $this->entityTypeManager->getStorage('media');
    $media_type_configuration = $media_type->getSource()->getConfiguration();
    $source_field_name = $media_type->getSource()
      ->getSourceFieldDefinition($media_type)
      ->getName();
    $deduplicate = !empty($media_type_configuration['deduplicate']) && $media_type_configuration['deduplicate'] === 1;

    if ($deduplicate) {
      // Check first if the Media exists, and if so, return it, so
      // we don't end up creating duplicates.
      $media_storage->getQuery()->accessCheck(FALSE);
      $query = $media_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('bundle', $media_type->id())
        ->condition($source_field_name . '.uri', $form_state->getValue('uri'));
      $media_ids = $query->execute();
      if (!empty($media_ids)) {
        $media_id = reset($media_ids);
        $entities[] = $media_storage->load($media_id);
        return $entities;
      }
    }

    $media = $media_storage->create([
      'bundle' => $media_type->id(),
      $source_field_name => [
        'uri' => $form_state->getValue('uri'),
        'id' => $form_state->getValue('id'),
        'name' => $form_state->getValue('name'),
        'metadata' => $form_state->getValue('metadata'),
      ],
    ]);
    $media->setName($form_state->getValue('name'));
    $entities[] = $media;
    return $entities;
  }

  /**
   * {@inheritdoc}
   */
  public function validate(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\media\MediaInterface[] $media_entities */
    $media_entities = $this->prepareEntities($form, $form_state);

    if (!empty($media_entities)) {
      foreach ($media_entities as $media_entity) {
        $violations = $media_entity->validate();
        if ($violations->count() > 0) {
          /** @var \Symfony\Component\Validator\ConstraintViolation $violation */
          foreach ($violations as $violation) {
            $form_state->setErrorByName('uri', $violation->getMessage());
          }
        }
      }
    }
    else {
      $form_state->setErrorByName('uri', $this->t('Invalid URL.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submit(array &$element, array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\media\MediaInterface[] $media_entities */
    $media_entities = $this->prepareEntities($form, $form_state);

    foreach ($media_entities as $id => $media_entity) {
      $media_entity->save();
      $media_entities[$id] = $media_entity;
    }

    $this->selectEntities($media_entities, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['media_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Media type'),
      '#required' => TRUE,
      '#description' => $this->t('The type of media entity to create from the uploaded file(s).'),
    ];

    $media_type = $this->getType();
    if ($media_type) {
      $form['media_type']['#default_value'] = $media_type->id();
    }

    $media_types = $this->entityTypeManager->getStorage('media_type')
      ->loadMultiple();
    if ($media_types) {
      foreach ($media_types as $key => $media_bundle) {
        if (!in_array($media_bundle->get('source'), ['frontify_image', 'frontify_video'])) {
          unset($media_types[$key]);
        }
      }
    }

    if (!empty($media_types)) {
      foreach ($media_types as $media_type) {
        $form['media_type']['#options'][$media_type->id()] = $media_type->label();
      }
    }
    else {
      $form['media_type']['#disabled'] = TRUE;
      $form['media_type']['#description'] = $this->t('You must @create_media_type before using this widget.', [
        '@create_media_type' => Link::createFromRoute($this->t('create a media type'), 'media.add')
          ->toString(),
      ]);
    }

    return $form;
  }

  /**
   * Handling of automated submit of uploaded files.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse|array
   *   Returns ajax commands that will be executed in front-end.
   */
  public static function handleAjaxCommand(array $form, FormStateInterface $form_state) {
    // If there are some errors during submitting of form they should be
    // displayed, that's why we are returning status message here and generated
    // errors will be displayed properly in front-end.
    if (count($form_state->getErrors()) > 0) {
      return [
        '#type' => 'status_messages',
      ];
    }

    // Output correct response if everything passed without any error.
    $ajax = new AjaxResponse();

    return $ajax;
  }

  /**
   * {@inheritdoc}
   */
  protected function selectEntities(array $entities, FormStateInterface $form_state) {
    $selected_entities = &$form_state->get(['entity_browser', 'selected_entities']);
    $selected_entities = $entities;

    $this->eventDispatcher->dispatch(
      new EntitySelectionEvent(
        $this->configuration['entity_browser_id'],
        $form_state->get(['entity_browser', 'instance_uuid']),
        $entities
      ), Events::SELECTED);
  }

  /**
   * Returns the media type that this widget creates.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   Media type.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getType() {
    return $this->entityTypeManager->getStorage('media_type')
      ->load($this->configuration['media_type']);
  }

}
