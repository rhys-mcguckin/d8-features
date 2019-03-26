<?php


namespace Drupal\config_augment\Config;


use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorage;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Config\StorableConfigBase;
use Drupal\Core\Config\ConfigCollectionInfo;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\user\Entity\Role;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class ConfigAugmenter implements ConfigAugmenterInterface {

  /**
   * @var \Drupal\Core\Config\ConfigManagerInterface
   */
  protected $configManager;

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  protected $entityTypeManager;

  /**
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * @var array
   */
  protected $collections;

  /**
   * @var array
   */
  protected $names;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    ConfigManagerInterface $configManager,
    ModuleHandlerInterface $moduleHandler,
    EntityTypeManagerInterface $entityTypeManager,
    EventDispatcherInterface $eventDispatcher,
    LoggerChannelInterface $logger
  ) {
    $this->configManager = $configManager;
    $this->configFactory = $configFactory;
    $this->moduleHandler = $moduleHandler;
    $this->entityTypeManager = $entityTypeManager;
    $this->logger = $logger;
    $this->eventDispatcher = $eventDispatcher;
  }

  /**
   * Always provide the latest config collection information.
   *
   * N.B. Cached collection info may ignore installed information when
   * performing the configuration rewrites.
   *
   * @return \Drupal\Core\Config\ConfigCollectionInfo
   */
  protected function getCollectionInfo() {
    $collectionInfo = new ConfigCollectionInfo();
    $this->eventDispatcher->dispatch(ConfigEvents::COLLECTION_INFO, $collectionInfo);
    return $collectionInfo;
  }

  /**
   * {@inheritdoc}
   */
  public function getCollectionAugmentations(Extension $extension, $collection) {
    $extension_name = $extension->getName();
    if (isset($this->collections[$extension_name][$collection])) {
      return $this->collections[$extension_name][$collection];
    }

    // Generate the filename we want to locate.
    $collection_path = array_filter(explode('.', $collection));
    $augment_path = [$extension->getPath(), 'config', 'augment'];

    // Get the expected path for the augmentation of the particular name.
    $path = implode(DIRECTORY_SEPARATOR, array_merge($augment_path, $collection_path));

    // Nothing exists there so ignore.
    if (!file_exists($path)) {
      return NULL;
    }

    // Process the augmentations into their base names.
    $data = [];
    if (file_exists($path) && $files = file_scan_directory($path, '/^.*\.yml$/i', ['recurse' => FALSE])) {
      foreach ($files as $file) {
        try {
          $data[$file->name] = Yaml::parse(file_get_contents($path . DIRECTORY_SEPARATOR . $file->name . '.yml'));
        }
        catch (ParseException $e) {}
      }
    }

    $this->collections[$extension_name][$collection] = $data;

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function getExtensionAugmentations(Extension $extension) {
    // Generate the augmentations by collection.
    $data = [];
    foreach ($this->getCollectionInfo()->getCollectionNames() as $collection) {
      $data[$collection] = $this->getCollectionAugmentations($extension, $collection);
    }
    return array_filter($data);
  }

  /**
   * {@inheritdoc}
   */
  public function getAugmentationsByName($collection, $name) {
    // Get the augmentations by name.
    if (isset($this->names[$collection][$name])) {
      return $this->names[$collection][$name];
    }

    // We have already loaded the augmentations.
    if (isset($this->names)) {
      return NULL;
    }

    $this->names = [];

    $modules = $this->moduleHandler->getModuleList();
    foreach ($modules as $module) {
      $augmentations = $this->getExtensionAugmentations($module);
      if (!$augmentations) {
        continue;
      }

      foreach ($augmentations as $collection_name => $names) {
        if (!isset($this->names[$collection_name])) {
          $this->names[$collection_name] = [];
        }

        // Cycle through each augmentation, and apply that to the collections.
        foreach ($names as $key => $data) {
          if (!isset($this->names[$collection_name][$key])) {
            $this->names[$collection_name][$key] = [];
          }
          $this->names[$collection_name][$key] = NestedArray::mergeDeep($this->names[$collection_name][$key], $data);
        }
      }
    }

    return isset($this->names[$collection][$name]) ? $this->names[$collection][$name] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function applyExtensionAugmentations(Extension $extension) {
    // Get the default collection.
    $default_collection = StorageInterface::DEFAULT_COLLECTION;

    // Get the collections that are meant to be applied from the extension.
    $collections = $this->getExtensionAugmentations($extension);

    // Get the collection information as we need to apply it.
    $info = $this->getCollectionInfo();

    // Generate the list of all available config so we do not overwrite
    // config that does not yet exist.
    $items = $this->configFactory->listAll();

    // Map the items so we can perform a quicker search of existence.
    $items = array_combine($items, $items);

    // Process the default collection information.
    if (!empty($collections[$default_collection])) {
      foreach ($collections[$default_collection] as $name => $data) {
        // Skip non-existent config names.
        if (!isset($items[$name])) {
          continue;
        }

        // Get the configuration.
        $config = $this->configFactory->getEditable($name);

        // Augment and save the configuration.
        $this->augment($config, $data)->save();
      }
      unset($collections[StorageInterface::DEFAULT_COLLECTION]);
    }

    // Process each of the collections.
    foreach ($collections as $collection => $keys) {
      /** @var \Drupal\Core\Config\ConfigFactoryOverrideInterface $override_factory */
      $override_factory = $info->getOverrideService($collection);
      if (!$override_factory) {
        continue;
      }

      // Process each of the configuration updates.
      foreach ($keys as $name => $data) {
        // Create the configuration object from the override factory.
        $config = $override_factory->createConfigObject($name, $collection);

        // Update the override configuration.
        $this->augment($config, $data)->save();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function augment(StorableConfigBase $config, array $overrides) {
    $raw_data = $config instanceof Config ? $config->getRawData() : $config->get();
    $data = NestedArray::mergeDeep($raw_data, $overrides);
    return $config->setData($data);
  }

  /**
   * Process a config entity such that presave behaviour applies to the updated
   * configuration.
   *
   * @param $name
   * @param array $config
   *
   * @return array|mixed[]
   */
  protected function processConfigEntity($name, array $config) {
    // Process the individual augmentations by type.
    $entity_type_id = $this->configManager->getEntityTypeIdByName($name);
    if ($entity_type_id) {
      /** @var \Drupal\Core\Config\Entity\ConfigEntityTypeInterface $entity_type */
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      $config_object = $this->entityTypeManager->getStorage($entity_type_id)->create($config);
      if ($config_object instanceof EntityInterface) {
        $config_object->preSave($this->entityTypeManager->getStorage($entity_type_id));
      }
      $config = $config_object->toArray();
    }
    return $config;
  }

  /**
   * {@inheritdoc}
   */
  public function augmentByName($name, array $config) {
    // Get the augmentations that are meant to be applied to the contents
    $augmentations = $this->getAugmentationsByName(StorageInterface::DEFAULT_COLLECTION, $name);
    if ($augmentations) {
      $config = NestedArray::mergeDeep($config, $augmentations);
      $config = $this->processConfigEntity($name, $config);

    }

    // Generate the overrides for the name based on the config override factories.
    $info = $this->getCollectionInfo();
    foreach ($info->getCollectionNames(FALSE) as $collection) {
      // Get the collection overrides.
      $override_factory = $info->getOverrideService($collection);

      // Save the config object information.
      $config_object = $override_factory->createConfigObject($name, $collection);
      $raw_data = $config_object instanceof Config ? $config_object->getRawData() : $config_object->get();

      // TODO: This should apply the config entity updates to ensure correct ordering of configuration.

      // Apply the augmentations by collection to the factory config.
      $augmentations = $this->getAugmentationsByName($collection, $name);
      if ($augmentations) {
        $this->augment($config_object, $augmentations);
        $config_object->save();
      }

      // Apply configuration overrides assuming they exist.
      $collection_overrides = $override_factory->loadOverrides([$name]);
      if (!empty($collection_overrides[$name])) {
        $config = NestedArray::mergeDeepArray([$config, $collection_overrides[$name]], TRUE);
      }

      // Reset the configuration object to it's original data.
      $config_object->setData($raw_data)->save(TRUE);
    }

    // Apply the global config updates based on the standard behaviour.
    if (isset($GLOBALS['config'][$name])) {
      $config = NestedArray::mergeDeepArray([$config, $GLOBALS['config'][$name]], TRUE);
    }

    return $config;
  }

  /**
   * {@inheritdoc}
   */
  public function reset() {
    unset($this->names);
    unset($this->collections);
  }
}