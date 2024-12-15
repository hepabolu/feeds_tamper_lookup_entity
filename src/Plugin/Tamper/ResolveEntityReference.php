<?php

declare(strict_types=1);

namespace Drupal\feeds_tamper_lookup_entity\Plugin\tamper;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\tamper\TamperableItemInterface;
use Drupal\tamper\TamperBase;

/**
 * Plugin implementation of the 'resolve_entity_reference' tamper plugin.
 *
 * @Tamper(
 *   id = "lookup_entity_reference",
 *   label = @Translation("Lookup Entity"),
 *   description = @Translation(
 *   "Resolves an entity reference based on a source field"),
 *   category = "Other"
 * )
 */
class ResolveEntityReference extends TamperBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function tamper($data, ?TamperableItemInterface $item = NULL) {
    // Log data for debugging.
    //    Drupal::logger('feeds_tamper_lookup_entity')
    //      ->debug('Processing: @data', ['@data' => print_r($data, TRUE)]);

    // Retrieve configuration.
    $entity_type = $this->configuration['entity_type'] ?? NULL;
    $bundle = $this->configuration['bundle'] ?? NULL;
    $lookup_field = $this->configuration['lookup_field'] ?? NULL;
    $return_field = $this->configuration['return_field'] ?? 'entity_id';

    // If any critical configuration is missing, return data unmodified.
    if (!$entity_type || !$bundle || !$lookup_field) {
      return $data;
    }

    // If data is empty, return it without processing.
    if (empty($data)) {
      return $data;
    }

    // Load entities matching the lookup field value.
    //    $entity_type = $entity_type . '_type';
    try {
      $entity_storage = Drupal::entityTypeManager()
        ->getStorage($entity_type . '_type');
      //      $query = $entity_storage->getQuery()

      $typeField = 'type';
      if ($entity_type === 'media') {
        // in the media tables the 'type' field is called 'bundle'
        $typeField = 'bundle';
      }

      $query = Drupal::entityQuery($entity_type)
        ->condition($typeField, $bundle)
        ->condition($lookup_field, $data)
        ->accessCheck(TRUE); // Ensure the query explicitly checks access.

      $entity_ids = $query->execute();

      // If no matching entities are found, return the original data.
      if (empty($entity_ids)) {
        return $data;
      }

      if ($entity_type === 'node') {
        $entities = Node::loadMultiple($entity_ids);
      }
      if ($entity_type === 'media') {
        $entities = Media::loadMultiple($entity_ids);
      }

      if (!$entities) {
        // If the entity couldn't be loaded, return the original data.
        return $data;
      }
      // For now only Load the first matching entity.
      //      $entity = reset($entities);

      $new_data = [];
      foreach ($entities as $entity) {
        if ($return_field === 'entity_id') {
          $new_data[] = $entity->id();
        }
        // Check if the entity has the requested return field.
        elseif ($entity->hasField($return_field)) {
          // Return the value of the return field.
          $new_data[] = $entity->get($return_field)->value;
        }
        else {
          // If the return field is not available, log a warning and return original data.
          Drupal::logger('feeds_tamper_lookup_entity')
            ->warning('Field "@field" not found on entity of type "@type" with ID @id.', [
              '@field' => $return_field,
              '@type' => $entity_type,
              '@id' => $entity->id(),
            ]);
        }
      }

      if (empty($new_data)) {
        return $data;
      }
      return $new_data;

    } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
      Drupal::logger('feeds_tamper_lookup_entity')->error($e->getMessage());
    }

    return $data;
  }


  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
        'entity_type' => 'node',
        'bundle' => '',
        'lookup_field' => '',
        'return_field' => 'entity_id',
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {

    // Entity type dropdown.
    $form['entity_type'] = [
      '#id' => 'entity_type',
      '#type' => 'select',
      '#title' => $this->t('Entity type'),
      '#options' => [
        'node' => $this->t('Content (Node)'),
        'media' => $this->t('Media'),
      ],
      '#default_value' => $this->configuration['entity_type'],
      '#description' => $this->t('Select the entity type to use for lookup and reference.'),
      '#required' => TRUE,
      '#ajax' => [
        'callback' => [$this, 'ajaxUpdateBundleOptions'],
        'event' => 'change',
        'wrapper' => 'bundle-wrapper',
        // This ID needs to match the 'prefix'/'suffix' in the bundle field.
      ],
    ];

    // Bundle field with dynamic options.
    $form['bundle'] = [
      '#id' => 'bundle',
      '#type' => 'select',
      '#title' => $this->t('Bundle'),
      '#default_value' => $this->configuration['bundle'],
      '#description' => $this->t('Select the content type or media type to filter by.'),
      '#required' => TRUE,
      '#prefix' => '<div id="bundle-wrapper">',
      '#suffix' => '</div>',
      '#options' => $this->getBundleOptions($this->configuration['entity_type']),
      '#validated' => TRUE,
      '#ajax' => [
        'callback' => [$this, 'ajaxUpdateFieldOptions'],
        'event' => 'change',
        'wrapper' => [
          'lookup-field-wrapper',
          'return-field-wrapper',
        ],
      ],
    ];

    // Lookup field.
    $form['lookup_field'] = [
      '#id' => 'lookup_field',
      '#type' => 'select',
      '#title' => $this->t('Lookup field'),
      '#default_value' => $this->configuration['lookup_field'],
      '#description' => $this->t('The field to use for the lookup, e.g., "field_old_id".'),
      '#required' => TRUE,
      '#validated' => TRUE,
      '#prefix' => '<div id="lookup-field-wrapper">',
      '#suffix' => '</div>',
      '#options' => $this->getFieldOptions($this->configuration['entity_type'], $this->configuration['bundle']),
    ];

    // Return field.
    $form['return_field'] = [
      '#id' => 'return_field',
      '#type' => 'select',
      '#title' => $this->t('Return field'),
      '#default_value' => $this->configuration['return_field'],
      '#description' => $this->t('The field to return from the found entity, e.g., "entity_id"
       for the node id or another field. Default is "entity_id".'),
      '#validated' => TRUE,
      '#prefix' => '<div id="return-field-wrapper">',
      '#suffix' => '</div>',
      '#options' => $this->getFieldOptions($this->configuration['entity_type'], $this->configuration['bundle']),
    ];

    return $form;
  }

  /**
   * AJAX callback to update the bundle options based on entity type.
   */
  public function ajaxUpdateBundleOptions(array $form, FormStateInterface $form_state): AjaxResponse {
    //    Drupal::logger('feeds_tamper_lookup_entity')
    //      ->debug('ajaxUpdateBundleOptions form.plugin_configuration: @result',
    //        ['@result' => print_r(array_keys($form['plugin_configuration']), TRUE)]);

    $response = new AjaxResponse();

    $element = $form['plugin_configuration']['bundle'];

    //    Drupal::logger('feeds_tamper_lookup_entity')
    //      ->debug('ajaxUpdateBundleOptions element: @result', ['@result' => print_r($element, TRUE)]);

    $bundle_field = $this->processBundleField($element, $form_state);

    //    Drupal::logger('feeds_tamper_lookup_entity')
    //      ->debug('ajaxUpdateBundleOptions bundle: @result', ['@result' => print_r($bundle_field, TRUE)]);

    // Return the updated 'bundle' field wrapped in the appropriate container.
    $response->addCommand(new ReplaceCommand('#bundle-wrapper', $bundle_field));

    // Update the lookup field to reflect the new bundle selection.
    $element = $form['plugin_configuration']['lookup_field'];
    $lookup_field = $this->processLookupAndReturnField($element, $form_state);
    $response->addCommand(new ReplaceCommand('#lookup-field-wrapper', $lookup_field));

    // Replace the return field options dynamically.
    $element = $form['plugin_configuration']['return_field'];
    $return_field = $this->processLookupAndReturnField($element, $form_state);
    $response->addCommand(new ReplaceCommand('#return-field-wrapper', $return_field));

    return $response;
  }

  /**
   * AJAX callback to update the lookup field options based on the bundle.
   */
  public function ajaxUpdateFieldOptions(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();

    // Replace the lookup field options dynamically.
    $element = $form['plugin_configuration']['lookup_field'];
    $lookup_field = $this->processLookupAndReturnField($element, $form_state);
    $response->addCommand(new ReplaceCommand('#lookup-field-wrapper', $lookup_field));

    // Replace the return field options dynamically.
    $element = $form['plugin_configuration']['return_field'];
    $return_field = $this->processLookupAndReturnField($element, $form_state);
    $response->addCommand(new ReplaceCommand('#return-field-wrapper', $return_field));

    return $response;
  }

  /**
   * Process callback for the bundle field.
   */
  public function processBundleField(array &$element, FormStateInterface $form_state): array {
    // Fetch the selected entity type (either 'node' or 'media').
    //    $entity_type = $form_state->getValue([
    //      'plugin_configuration',
    //      'entity_type',
    //    ], 'node');

    $triggeringElement = $form_state->getTriggeringElement();
    $entity_type = $triggeringElement['#value'] ?? 'node';

    //    Drupal::logger('feeds_tamper_lookup_entity')
    //      ->debug('processBundleField entity type: @result', ['@result' => $entity_type]);
    //    Drupal::logger('feeds_tamper_lookup_entity')
    //      ->debug('processBundleField form_state: @result', ['@result' => print_r($form_state->getValue('plugin_configuration'), TRUE)]);

    // Initialize an empty array to store bundle options.
    $bundle_options = $this->getBundleOptions($entity_type);

    // Update the options for the 'bundle' field with the appropriate options.
    $element['#options'] = $bundle_options;

    //    Drupal::logger('feeds_tamper_lookup_entity')
    //      ->debug('processBundleField element: @result', ['@result' => print_r($element, TRUE)]);

    return $element;
  }

  /**
   * Process callback for the lookup field.
   */
  public function processLookupAndReturnField(array &$element, FormStateInterface $form_state): array {
    // Fetch the selected entity type (either 'node' or 'media').
    $entity_type = $form_state->getValue([
      'plugin_configuration',
      'entity_type',
    ], '');

    $triggeringElement = $form_state->getTriggeringElement();
    $bundle_type = $triggeringElement['#value'];

    //    Drupal::logger('feeds_tamper_lookup_entity')
    //      ->debug('processLookupField entity and bundle type: @entity . @bundle',
    //        ['@entity' => $entity_type, '@bundle' => $bundle_type]);
    //    Drupal::logger('feeds_tamper_lookup_entity')
    //      ->debug('processLookupField form_state: @result',
    //        ['@result' => print_r($form_state->getValue('plugin_configuration'), TRUE)]);

    // Initialize an empty array to store bundle options.
    $lookup_options = $this->getFieldOptions($entity_type, $bundle_type);

    // Update the options for the 'bundle' field with the appropriate options.
    $element['#options'] = $lookup_options;

    //    Drupal::logger('feeds_tamper_lookup_entity')
    //      ->debug('processLookupField element: @result', ['@result' => print_r($element, TRUE)]);

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['entity_type'] = $form_state->getValue('entity_type');
    $this->configuration['bundle'] = $form_state->getValue('bundle');
    $this->configuration['lookup_field'] = $form_state->getValue('lookup_field');
    $this->configuration['return_field'] = $form_state->getValue('return_field');
  }

  /**
   * Retrieves bundle options for the specified entity type.
   */
  protected function getBundleOptions(string $entity_type): array {
    $options = [];
    try {
      $entity_type_manager = Drupal::entityTypeManager();

      if ($entity_type === 'node') {
        $content_types = $entity_type_manager->getStorage('node_type')
          ->loadMultiple();
        foreach ($content_types as $type_id => $type) {
          $options[$type_id] = $type->label();
        }
      }
      elseif ($entity_type === 'media') {
        $media_types = $entity_type_manager->getStorage('media_type')
          ->loadMultiple();
        foreach ($media_types as $type_id => $type) {
          $options[$type_id] = $type->label();
        }
      }
    } catch (\Exception $e) {
      Drupal::logger('feeds_tamper_lookup_entity')->error($e->getMessage());
    }

    return $options;
  }

  /**
   * Retrieves lookup field options for the selected bundle.
   */
  protected function getFieldOptions(string $entity_type, string $bundle): array {
    $options = [];
    try {
      $field_manager = Drupal::service('entity_field.manager');

      if (!$entity_type || !$bundle) {
        return $options;
      }

      // Get fields for the selected entity type and bundle.
      $fields = $field_manager->getFieldDefinitions($entity_type, $bundle);

      foreach ($fields as $field_name => $field_definition) {
        $options[$field_name] = $field_definition->getLabel();
      }
    } catch (\Exception $e) {
      Drupal::logger('feeds_tamper_lookup_entity')->error($e->getMessage());
    }

    return $options;
  }

}
