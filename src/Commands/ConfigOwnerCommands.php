<?php

namespace Drupal\config_owner\Commands;

use Drupal\config_owner\OwnedConfig;
use Drupal\config_owner\OwnedConfigStorageComparerFactory;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drush\Commands\DrushCommands;
use Drush\Drupal\Commands\config\ConfigCommands;
use Drush\Drupal\Commands\config\ConfigImportCommands;
use Drush\Symfony\DrushArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Commands for importing the owned configs.
 */
class ConfigOwnerCommands extends DrushCommands {

  use StringTranslationTrait;

  /**
   * @var \Drush\Drupal\Commands\config\ConfigImportCommands
   */
  protected $configImportCommands;

  /**
   * @var \Drupal\config_owner\OwnedConfigStorageComparerFactory
   */
  protected $storageComparerFactory;

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * ConfigImportCommands constructor.
   *
   * @param \Drush\Drupal\Commands\config\ConfigImportCommands $configImportCommands
   * @param \Drupal\config_owner\OwnedConfigStorageComparerFactory $storageComparerFactory
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   */
  public function __construct(ConfigImportCommands $configImportCommands, OwnedConfigStorageComparerFactory $storageComparerFactory, ConfigFactoryInterface $configFactory, ModuleHandlerInterface $moduleHandler) {
    $this->configImportCommands = $configImportCommands;
    $this->storageComparerFactory = $storageComparerFactory;
    $this->configFactory = $configFactory;
    $this->moduleHandler = $moduleHandler;
  }

  /**
   * Imports all the owned configs into the active storage.
   *
   * @command config-owner:import
   */
  public function import() {
    $storage_comparer = $this->storageComparerFactory->create();

    if (!$storage_comparer->createChangelist()->hasChanges()) {
      $this->logger()->notice(('There are no changes to import.'));
      return;
    }

    $change_list = [];
    foreach ($storage_comparer->getAllCollectionNames() as $collection) {
      $change_list[$collection] = $storage_comparer->getChangelist(null, $collection);
    }
    $table = ConfigCommands::configChangesTable($change_list, $this->output());
    $table->render();

    if ($this->io()->confirm(dt('Import the listed configuration changes?'))) {
      return drush_op([$this->configImportCommands, 'doImport'], $storage_comparer);
    }
  }

  /**
   * Exports a configuration object to a module's config "owned" folder.
   *
   * @param string $module_name
   *   Optional.
   * @param string $config_name
   *   Optional.
   *
   * @interact-module-name
   * @interact-config-name
   *
   * @command config-owner:export
   * @usage drush config-owner:export module_name system.site
   *   Exports the system.site config object to the "owned" folder of the
   * module_name module. Leaving the arguments empty will allow you to select
   * the values interactively
   */
  public function export($module_name, $config_name) {
    $config = $this->configFactory->get($config_name);
    if (!$config instanceof Config) {
      $this->logger()->error($this->t('The requested config does not exist.'));
      return;
    }

    if (!$this->moduleHandler->moduleExists($module_name)) {
      $this->logger()->error($this->t('The module does not exist.'));
      return;
    }

    $directory = OwnedConfig::CONFIG_OWNED_DIRECTORY;
    $path = drupal_get_path('module', $module_name);
    $storage = new FileStorage($path . '/' . $directory, StorageInterface::DEFAULT_COLLECTION);
    $raw = $config->getRawData();
    unset($raw['uuid']);
    unset($raw['_core']);
    $storage->write($config_name, $raw);
    $this->logger()->success($this->t('The configuration "@config_name" has been written to the module "@module_name".', ['@config_name' => $config_name, '@module_name' => $this->moduleHandler->getName($module_name)])->render());
  }

  /**
   * Command hook to interactively select the available module names.
   *
   * @param \Drush\Symfony\DrushArgvInput $input
   * @param \Symfony\Component\Console\Output\ConsoleOutput $output
   *
   * @hook interact @interact-module-name
   */
  public function interactConfigName(DrushArgvInput $input, ConsoleOutput $output) {
    if (empty($input->getArgument('module_name'))) {
      $extensions = $this->moduleHandler->getModuleList();
      $choices = [];
      foreach ($extensions as $name => $extension) {
        $choices[$name] = $extension->getName();
      }
      $choice = $this->io()->choice('Choose a module', $choices);
      $input->setArgument('module_name', $choice);
    }
  }

}