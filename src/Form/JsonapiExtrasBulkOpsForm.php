<?php

namespace Drupal\jsonapi_extras_bulk\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\jsonapi_extras\ResourceType\ConfigurableResourceTypeRepository;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\jsonapi_extras\Entity\JsonapiResourceConfig;
use Drupal\jsonapi_extras\Plugin\ResourceFieldEnhancerManager;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Configure JSON:API bulk operations.
 */
class JsonapiExtrasBulkOpsForm extends FormBase {

  /**
   * The JSON:API extras config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The JSON:API configurable resource type repository.
   *
   * @var \Drupal\jsonapi_extras\ResourceType\ConfigurableResourceTypeRepository
   */
  protected $resourceTypeRepository;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManager
   */
  protected $fieldManager;

  /**
   * The field enhancer manager.
   *
   * @var \Drupal\jsonapi_extras\Plugin\ResourceFieldEnhancerManager
   */
  protected $enhancerManager;

  /**
   * Constructs a \Drupal\system\ConfigFormBase object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\jsonapi_extras\ResourceType\ConfigurableResourceTypeRepository $resource_type_repository
   *   The JSON:API configurable resource type repository.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Entity\EntityFieldManager $field_manager
   *   The entity field manager.
   * @param \Drupal\jsonapi_extras\Plugin\ResourceFieldEnhancerManager $enhancer_manager
   *   The plugin manager for the resource field enhancer.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ConfigurableResourceTypeRepository $resource_type_repository, EntityTypeManagerInterface $entity_type_manager, EntityFieldManager $field_manager, ResourceFieldEnhancerManager $enhancer_manager) {
    $this->config = $config_factory;
    $this->resourceTypeRepository = $resource_type_repository;
    $this->entityTypeManager = $entity_type_manager;
    $this->fieldManager = $field_manager;
    $this->enhancerManager = $enhancer_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('jsonapi.resource_type.repository'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('plugin.manager.resource_field_enhancer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'jsonapi_extras_bulk_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['disable'] = [
      '#type'   => 'submit',
      '#value'  => $this->t('Disable all resources'),
      '#submit' => ['::disableResourcesSubmitForm']
    ];
    $form['enable'] = [
      '#type'   => 'submit',
      '#value'  => $this->t('Enable all resources'),
      '#submit' => ['::enableResourcesSubmitForm']
    ];
    return $form;
  }

  /**
   * Disable all resources.
   */
  public function disableResourcesSubmitForm(array &$form, FormStateInterface $form_state) {
    $this->setResourceStatus(FALSE);
    $this->messenger()->addMessage('All resources successfully *disabled*.');
  }

  /**
   * Enable all resources.
   */
  public function enableResourcesSubmitForm(array &$form, FormStateInterface $form_state) {
    $this->setResourceStatus(TRUE);
    $this->messenger()->addMessage('All resources successfully *enabled*.');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    return parent::submitForm($form, $form_state);
  }

  /**
   * Set resource status.
   */
  protected function setResourceStatus($status) {
    foreach ($this->resourceTypeRepository->all() as $resource_type) {
      $bundle = $resource_type->getBundle();
      $entity_type_id = $resource_type->getEntityTypeId();
      $resource_config_id = sprintf('%s--%s', $entity_type_id, $bundle);
      $storage = $this->entityTypeManager->getStorage('jsonapi_resource_config');
      
      // Get the JSON:API resource type.
      $existing_entity = $storage->load($resource_config_id);
      if ($existing_entity) {
        $existing_entity->set('disabled', !$status);
        $existing_entity->save();
      }
      else {
        // Otherwise we need to build and save it.
        $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
        $field_names = $this->getAllFieldNames($entity_type, $bundle);

        $entity = $storage->create([
          'bundle' => $bundle,
          'id' => $resource_config_id,
          'resourceType' => $resource_config_id,
          'path' => sprintf('%s/%s', $entity_type_id, $bundle),
          'disabled' => !$status,
        ]);

        $resourceFields = [];
        foreach ($field_names as $field_name) {
          if ($overrides = $this->buildOverridesField($field_name, $entity)) {
            $resourceFields[$field_name] = $overrides;
          }
        }
        $entity->set('resourceFields', $resourceFields);
        $entity->save();
      }
    }
    \Drupal::service('jsonapi.resource_type.repository')->reset();
    \Drupal::service('router.builder')->setRebuildNeeded();
  }

  /**
   * Gets all field names for a given entity type and bundle.
   * (copied and modified from Drupal\jsonapi_extras\Form\JsonapiResourceConfigForm)
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type for which to get all field names.
   * @param string $bundle
   *   The bundle for which to get all field names.
   *
   * @todo This is a copy of ResourceTypeRepository::getAllFieldNames. We can't
   * reuse that code because it's protected.
   *
   * @return string[]
   *   All field names.
   */
  protected function getAllFieldNames(EntityTypeInterface $entity_type, $bundle) {
    if (is_a($entity_type->getClass(), FieldableEntityInterface::class, TRUE)) {
      $field_definitions = $this->fieldManager->getFieldDefinitions(
        $entity_type->id(),
        $bundle
      );
      return array_keys($field_definitions);
    }
    elseif (is_a($entity_type->getClass(), ConfigEntityInterface::class, TRUE)) {
      // @todo Uncomment the first line, remove everything else once https://www.drupal.org/project/drupal/issues/2483407 lands.
      // return array_keys($entity_type->getPropertiesToExport());
      $export_properties = $entity_type->getPropertiesToExport();
      if ($export_properties !== NULL) {
        return array_keys($export_properties);
      }
      else {
        return ['id', 'type', 'uuid', '_core'];
      }
    }

    return [];
  }

  /**
   * Builds the part of the form that overrides the field.
   * (copied and modified from Drupal\jsonapi_extras\Form\JsonapiResourceConfigForm)
   *
   * @param string $field_name
   *   The field name of the field being overridden.
   * @param \Drupal\jsonapi_extras\Entity\JsonapiResourceConfig $entity
   *   The config entity backed by this form.
   *
   * @return array
   *   The partial form.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  protected function buildOverridesField($field_name, JsonapiResourceConfig $entity) {
    $rfs = $entity->get('resourceFields') ?: [];
    $resource_fields = array_filter($rfs, function (array $resource_field) use ($field_name) {
      return $resource_field['fieldName'] == $field_name;
    });
    $resource_field = array_shift($resource_fields);
    $overrides_form = [];
    $overrides_form['disabled'] = (bool) $resource_field['disabled'];
    $overrides_form['fieldName'] = $field_name;
    $overrides_form['publicName'] = empty($resource_field['publicName']) ? $field_name : $resource_field['publicName'];
    $overrides_form['enhancer']['id'] = empty($resource_field['enhancer']['id']) ? '' : $resource_field['enhancer']['id'];
    return $overrides_form;
  }

}
