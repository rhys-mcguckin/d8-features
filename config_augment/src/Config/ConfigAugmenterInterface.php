<?php

namespace Drupal\config_augment\Config;

use Drupal\Core\Config\StorableConfigBase;
use Drupal\Core\Extension\Extension;

interface ConfigAugmenterInterface {
  /**
   * Get the extension augmentation based on the collection.
   *
   * @param \Drupal\Core\Extension\Extension $extension
   *   The extension.
   *
   * @param string $collection
   *   The collection name.
   *
   * @return array
   */
  public function getCollectionAugmentations(Extension $extension, $collection);

  /**
   * Get all the augmentations for an extension.
   *
   * @param \Drupal\Core\Extension\Extension $extension
   *
   * @return array
   */
  public function getExtensionAugmentations(Extension $extension);

  /**
   * Get the augmentation by collection and name.
   *
   * @param $collection
   * @param $name
   *
   * @return array|NULL
   */
  public function getAugmentationsByName($collection, $name);

  /**
   * Applies the augmentations for an extension to the active config.
   *
   * @param \Drupal\Core\Extension\Extension $extension
   *
   * @return void
   */
  public function applyExtensionAugmentations(Extension $extension);

  /**
   * Augments a configuration object with the overrides.
   *
   * @param \Drupal\Core\Config\StorableConfigBase $config
   * @param array $overrides
   *
   * @return \Drupal\Core\Config\StorableConfigBase
   *   Provides the config object modified but unsaved.
   */
  public function augment(StorableConfigBase $config, array $overrides);

  /**
   * Apply the augmentation based on the configuration name.
   *
   * @param string $name
   * @param array $config
   *
   * @return array
   */
  public function augmentByName($name, array $config);

  /**
   * Resets any caching of the augmentations.
   *
   * @return void
   */
  public function reset();
}
