<?php

namespace Drupal\Tests\config_augment\Kernel;

use Drupal\Core\Config\StorageInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;

/**
 * @coversDefaultClass \Drupal\config_augment\Config\ConfigAugmenter
 *
 * @group config_augment
 */
class ConfigAugmenterTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['system', 'user', 'config_augment', 'config_augment_test', 'config_augment_test_augment', 'language'];

  /**
   * The active configuration storage.
   *
   * @var \Drupal\Core\Config\CachedStorage
   */
  protected $activeConfigStorage;

  /**
   * The configuration augmenter.
   *
   * @var \Drupal\config_augment\Config\ConfigAugmenterInterface
   */
  protected $configAugmenter;

  /**
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The language config factory override service.
   *
   * @var \Drupal\language\Config\LanguageConfigFactoryOverrideInterface
   */
  protected $languageConfigFactoryOverride;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->installSchema('system', ['sequence']);
    $this->installEntitySchema('user_role');
    $this->installConfig(['language', 'config_augment_test']);

    $this->container->get('language_manager')->reset();

    $this->configAugmenter = $this->container->get('config_augment.config_augmenter');
    $this->activeConfigStorage = $this->container->get('config.storage');
    $this->moduleHandler = $this->container->get('module_handler');
    $this->languageConfigFactoryOverride = $this->container->get('language.config_factory_override');
  }

  /**
   * @covers ::getCollectionAugmentations
   */
  public function testGetCollectionAugmentations() {
    $extension = $this->moduleHandler->getModule('config_augment_test_augment');

    // Test the default collection behaviour.
    $data = $this->configAugmenter->getCollectionAugmentations($extension, StorageInterface::DEFAULT_COLLECTION);
    $this->assertArrayHasKey('user.role.test1', $data);
    $this->assertArrayHasKey('user.role.test2', $data);
    $this->assertArrayNotHasKey('user.role.test4', $data);

    // Test a config override collection.
    $data = $this->configAugmenter->getCollectionAugmentations($extension, 'language.fr');
    $this->assertArrayNotHasKey('user.role.test1', $data);
    $this->assertArrayHasKey('user.role.test4', $data);
  }

  /**
   * @covers ::getExtensionAugmentations
   */
  public function testGetExtensionAugmentations() {
    $extension = $this->moduleHandler->getModule('config_augment_test_augment');

    // Test the default collection behaviour.
    $data = $this->configAugmenter->getExtensionAugmentations($extension);
    $this->assertArrayHasKey('', $data);
    $this->assertArrayHasKey('language.fr', $data);
  }

  /**
   * @covers ::applyExtensionAugmentations
   */
  function testApplyExtensionAugmentations() {
    $expected_original_data = [
      'label' => 'Test 1',
      'is_admin' => FALSE,
      'permissions' => [
        'access user profiles',
      ],
    ];

    // Verify that the original configuration data exists.
    $data = $this->activeConfigStorage->read('user.role.test1');
    $this->assertIdentical($data['label'], $expected_original_data['label']);
    $this->assertIdentical($data['permissions'], $expected_original_data['permissions']);

    // Augment the configuration.
    $extension = $this->moduleHandler->getModule('config_augment_test_augment');
    $this->configAugmenter->applyExtensionAugmentations($extension);

    // Test a rewrite where config_augment is not set.
    // Test that data is modified.
    $expected_rewritten_data = [
      'label' => 'Test 1 rewritten',
      // Unchanged.
      'is_admin' => FALSE,
      // Merged.
      'permissions' => [
        'access user profiles',
        'change own username',
      ],
    ];
    $user_role = $this->activeConfigStorage->read('user.role.test1');
    $this->assertEquals($user_role['label'], $expected_rewritten_data['label']);
    $this->assertEquals($user_role['is_admin'], $expected_rewritten_data['is_admin']);
    $this->assertEquals($user_role['permissions'], $expected_rewritten_data['permissions']);

    // Test a rewrite where config_augment is set to an unsupported value.
    // Test that data is modified.
    $expected_rewritten_data = [
      'label' => 'Test 2 rewritten',
      // Unchanged.
      'is_admin' => FALSE,
      // Merged.
      'permissions' => [
        'access user profiles',
        'change own username',
      ],
    ];
    $user_role = $this->activeConfigStorage->read('user.role.test2');
    $this->assertEquals($user_role['label'], $expected_rewritten_data['label']);
    $this->assertEquals($user_role['is_admin'], $expected_rewritten_data['is_admin']);
    $this->assertEquals($user_role['permissions'], $expected_rewritten_data['permissions']);
    // Test that the "config_augment" key was unset.
    $this->assertFalse(isset($user_role['config_augment']));

    // Test a multilingual rewrite.
    $expected_rewritten_data = [
      'label' => 'Test 4 réécrit',
    ];
    $user_role = $this->languageConfigFactoryOverride->getOverride('fr', 'user.role.test4')->get();
    $this->assertEquals($user_role['label'], $expected_rewritten_data['label']);
  }

  /**
   * @covers ::getAugmentationsByName
   */
  public function testGetAugmentationsByName() {
    // Check default collections.
    $data = $this->configAugmenter->getAugmentationsByName(StorageInterface::DEFAULT_COLLECTION, 'user.role.test2');
    $subset = [
      'label' => 'Test 2 rewritten',
      'permissions' => [
        'change own username'
      ]
    ];
    $this->assertArraySubset($subset, $data);

    // Check non-default collections.
    $data = $this->configAugmenter->getAugmentationsByName('language.fr', 'user.role.test4');
    $subset = [
      'label' => 'Test 4 réécrit'
    ];
    $this->assertArraySubset($subset, $data);
  }

  /**
   * @covers ::augmentByName
   */
  public function testAugmentByName() {
    $original_data = [
      'label' => 'Test 1',
      'is_admin' => FALSE,
      'permissions' => [
        'access user profiles',
      ],
    ];

    // Test default collection rewritten.
    $data = $this->configAugmenter->augmentByName('user.role.test2', $original_data);
    $this->assertEquals('Test 2 rewritten', $data['label']);

    // Test override factory not rewritten.
    $this->languageConfigFactoryOverride->setLanguage(ConfigurableLanguage::createFromLangcode('en'));
    $data = $this->configAugmenter->augmentByName('user.role.test4', $original_data);
    $this->assertEquals('Test 1', $data['label']);

    // Test override factory rewritten with different language.
    $this->languageConfigFactoryOverride->setLanguage(ConfigurableLanguage::createFromLangcode('fr'));
    $data = $this->configAugmenter->augmentByName('user.role.test4', $original_data);
    $this->assertEquals('Test 4 réécrit', $data['label']);
  }
}
