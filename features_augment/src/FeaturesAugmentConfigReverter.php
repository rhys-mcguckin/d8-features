<?php


namespace Drupal\features_augment;

use Drupal\config_augment\Config\ConfigAugmenterInterface;
use Drupal\config_update\ConfigReverter;
use Drupal\config_update\ConfigRevertEvent;
use Drupal\config_update\ConfigRevertInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class FeaturesAugmentConfigReverter extends ConfigReverter {

  /**
   * @var \Drupal\config_update\ConfigReverter
   */
  protected $configReverter;

  /**
   * @var \Drupal\config_augment\Config\ConfigAugmenterInterface
   */
  protected $configAugmenter;

  /**
   * FeaturesAugmentConfigReverter constructor.
   *
   * @param \Drupal\config_update\ConfigReverter $inner
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   * @param \Drupal\Core\Config\StorageInterface $active_config_storage
   * @param \Drupal\Core\Config\StorageInterface $extension_config_storage
   * @param \Drupal\Core\Config\StorageInterface $extension_optional_config_storage
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher
   * @param \Drupal\config_augment\Config\ConfigAugmenterInterface $configAugmenter
   *
   */
  public function __construct(
    ConfigReverter $inner,
    EntityTypeManagerInterface $entity_manager,
    StorageInterface $active_config_storage,
    StorageInterface $extension_config_storage,
    StorageInterface $extension_optional_config_storage,
    ConfigFactoryInterface $config_factory,
    EventDispatcherInterface $dispatcher,
    ConfigAugmenterInterface $configAugmenter
  ) {
    $this->configReverter = $inner;
    $this->configAugmenter = $configAugmenter;
    parent::__construct($entity_manager, $active_config_storage, $extension_config_storage, $extension_optional_config_storage, $config_factory, $dispatcher);
  }

  /**
   * {@inheritdoc}
   */
  public function import($type, $name) {
    // Read the config from the file. Note: Do not call getFromExtension() here
    // because we need $full_name below.
    $full_name = $this->getFullName($type, $name);
    $value = FALSE;
    if ($full_name) {
      $value = $this->extensionConfigStorage->read($full_name);
      if (!$value) {
        $value = $this->extensionOptionalConfigStorage->read($full_name);
      }
    }
    if (!$value) {
      return FALSE;
    }

    $value = $this->configAugmenter->augmentByName($full_name, $value);

    // Save it as a new config entity or simple config.
    if ($type == 'system.simple') {
      $this->configFactory->getEditable($full_name)->setData($value)->save();
    }
    else {
      $entity_storage = $this->entityManager->getStorage($type);
      $entity = $entity_storage->createFromStorageRecord($value);
      $entity->save();
    }

    // Trigger an event notifying of this change.
    $event = new ConfigRevertEvent($type, $name);
    $this->dispatcher->dispatch(ConfigRevertInterface::IMPORT, $event);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function revert($type, $name) {
    // Read the config from the file. Note: Do not call getFromExtension() here
    // because we need $full_name below.
    $value = FALSE;
    $full_name = $this->getFullName($type, $name);
    if ($full_name) {
      $value = $this->extensionConfigStorage->read($full_name);
      if (!$value) {
        $value = $this->extensionOptionalConfigStorage->read($full_name);
      }
    }
    if (!$value) {
      return FALSE;
    }

    // Make sure the configuration exists currently in active storage.
    if (!$this->activeConfigStorage->read($full_name)) {
      return FALSE;
    }

    $value = $this->configAugmenter->augmentByName($full_name, $value);

    // Load the current config and replace the value, retaining the config
    // hash (which is part of the _core config key's value).
    if ($type == 'system.simple') {
      $config = $this->configFactory->getEditable($full_name);
      $core = $config->get('_core');
      $config
        ->setData($value)
        ->set('_core', $core)
        ->save();
    }
    else {
      $definition = $this->entityManager->getDefinition($type);
      $id_key = $definition->getKey('id');
      $id = $value[$id_key];
      $entity_storage = $this->entityManager->getStorage($type);
      $entity = $entity_storage->load($id);
      $core = $entity->get('_core');
      $entity = $entity_storage->updateFromStorageRecord($entity, $value);
      $entity->set('_core', $core);
      $entity->save();
    }

    // Trigger an event notifying of this change.
    $event = new ConfigRevertEvent($type, $name);
    $this->dispatcher->dispatch(ConfigRevertInterface::REVERT, $event);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getFromExtension($type, $name) {
    $value = FALSE;
    $full_name = $this->getFullName($type, $name);
    if ($full_name) {
      $value = $this->extensionConfigStorage->read($full_name);
      if (!$value) {
        $value = $this->extensionOptionalConfigStorage->read($full_name);
      }
    }
    if ($value) {
      $value = $this->configAugmenter->augmentByName($full_name, $value);
    }
    return $value;
  }
}
