<?php

namespace Drupal\features_augment;

use Drupal\config_augment\Config\ConfigAugmenterInterface;
use Drupal\config_update\ConfigRevertInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\features\FeaturesManager;
use Drupal\features\FeaturesManagerInterface;
use Drupal\features\Package;

/**
 * Class for customizing the test for pre existing configuration.
 *
 * Decorates the ConfigInstaller with findPreExistingConfiguration() modified
 * to allow Feature modules to be installed.
 */
class FeaturesAugmentManager extends FeaturesManager {

  /**
   * The features manager.
   *
   * @var \Drupal\features\FeaturesManagerInterface
   */
  protected $featuresManager;

  /**
   * @var \Drupal\config_augment\Config\ConfigAugmenterInterface
   */
  protected $configAugmenter;

  public function __construct(
    FeaturesManagerInterface $features_manager,
    $root,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    StorageInterface $config_storage,
    ConfigManagerInterface $config_manager,
    ModuleHandlerInterface $module_handler,
    ConfigRevertInterface $config_reverter,
    ConfigAugmenterInterface $config_augmenter
  ) {
    $this->featuresManager = $features_manager;
    $this->configAugmenter = $config_augmenter;
    parent::__construct($root, $entity_type_manager, $config_factory, $config_storage, $config_manager, $module_handler, $config_reverter);
  }

  /**
   * {@inheritdoc}
   */
  public function detectOverrides(Package $feature, $include_new = FALSE) {
    /** @var \Drupal\config_update\ConfigDiffInterface $config_diff */
    $config_diff = \Drupal::service('config_update.config_diff');

    $different = [];
    foreach ($feature->getConfig() as $name) {
      $active = $this->configStorage->read($name);
      $extension = $this->extensionStorages->read($name);
      $extension = !empty($extension) ? $extension : [];

      // Apply the augmentations along with overrides so that the feature
      // can rightly see that nothing has changed.
      $extension = $this->configAugmenter->augmentByName($name, $extension);
      if (($include_new || !empty($extension)) && !$config_diff->same($extension, $active)) {
        $different[] = $name;
      }
    }

    if (!empty($different)) {
      $feature->setState(FeaturesManagerInterface::STATE_OVERRIDDEN);
    }
    return $different;
  }
}
