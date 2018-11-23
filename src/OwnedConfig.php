<?php

declare(strict_types = 1);

namespace Drupal\config_owner;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\InstallStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Plugin\PluginBase;

/**
 * OwnedConfig plugin instance class.
 */
class OwnedConfig extends PluginBase {

  /**
   * The three types of owned config.
   */
  const OWNED_CONFIG_INSTALL = 'install';
  const OWNED_CONFIG_OPTIONAL = 'optional';
  const OWNED_CONFIG_OWNED = 'owned';

  /**
   * The directory where owned config resides (config state).
   */
  const CONFIG_OWNED_DIRECTORY = 'config/owned';

  /**
   * Returns the owned config values of a given type (directory);.
   *
   * @param string $type
   *   The type of configuration.
   *
   * @return array
   *   The config values.
   */
  public function getOwnedConfigValuesByType(string $type) {
    $definition = $this->getPluginDefinition();
    if (!isset($definition[$type])) {
      return [];
    }

    $storage = $this->getStorage($type);
    $prepared_definition = $this->prepareConfigDefinition($definition[$type], $storage);
    $configs = $this->getOwnedConfigValues($prepared_definition, $storage);

    return $configs;
  }

  /**
   * Gets the owned config values.
   *
   * Given an array of config definitions specified in the plugin, return the
   * owned config values that must not be changed.
   *
   * @param array $config_definitions
   *   Config definitions from plugins.
   * @param \Drupal\Core\Config\FileStorage $storage
   *   The file storage where the config values can be found.
   *
   * @return array
   *   The config values.
   */
  protected function getOwnedConfigValues(array $config_definitions, FileStorage $storage) {
    $configs = [];
    foreach ($config_definitions as $name => $info) {
      $original_config = $storage->read($name);
      if (!$info || !isset($info['keys']) || !$info['keys']) {
        // In case no keys are specified, the entire config data is considered.
        // If by chance, the owned config ships with third party settings, we
        // remove them from the equation.
        $configs[$name] = OwnedConfigHelper::removeThirdPartySettings($original_config);
        continue;
      }

      // Otherwise, we only consider the specified keys.
      $config = [];
      foreach ($info['keys'] as $key) {
        $config = array_merge_recursive($config, $this->unflattenKey($key, $original_config));
      }
      $configs[$name] = $config;
    }

    return $configs;
  }

  /**
   * Gets the config file storage for the module this plugin belongs to.
   *
   * @param string $location
   *   The location of the config.
   *
   * @return \Drupal\Core\Config\StorageInterface
   *   The storage object.
   */
  protected function getStorage(string $location = self::OWNED_CONFIG_INSTALL) {
    $directory_map = [
      self::OWNED_CONFIG_INSTALL => InstallStorage::CONFIG_INSTALL_DIRECTORY,
      self::OWNED_CONFIG_OPTIONAL => InstallStorage::CONFIG_OPTIONAL_DIRECTORY,
      self::OWNED_CONFIG_OWNED => self::CONFIG_OWNED_DIRECTORY,
    ];

    $directory = $directory_map[$location];
    $path = drupal_get_path('module', $this->getPluginDefinition()['provider']);
    return new FileStorage($path . '/' . $directory, StorageInterface::DEFAULT_COLLECTION);
  }

  /**
   * Prepares the config definition for config extracting.
   *
   * Plugins may define configs using wildcards so we need to match those.
   *
   * @param array $definition
   *   The config definition.
   * @param \Drupal\Core\Config\StorageInterface $storage
   *   The storage object.
   *
   * @return array
   *   The prepared config definition.
   */
  protected function prepareConfigDefinition(array $definition, StorageInterface $storage) {
    $prepared = [];
    $available_names = $storage->listAll();
    foreach ($definition as $name => $info) {
      if ($storage->exists($name)) {
        // In this case the full config name was defined.
        $prepared[$name] = $info;
        continue;
      }

      foreach ($available_names as $available_name) {
        // @codingStandardsIgnoreLine
        if (fnmatch($name, $available_name)) {
          // For all the matches, we use the same info.
          $prepared[$available_name] = $info;
        }
      }
    }

    return $prepared;
  }

  /**
   * @param string $key
   * @param array $config
   *
   * @return array
   */
  protected function unflattenKey(string $key, array $config) {
    $data = [];
    $parts = explode('.', $key);

    if (count($parts) == 1 && isset($config[$key])) {
      $data[$key] = $config[$key];
      return $data;
    }

    if (in_array('*', $parts)) {
      // We are dealing with a wildcard at the end.
      array_pop($parts);
    }

    $value = NestedArray::getValue($config, $parts, $key_exists);
    if ($key_exists) {
      // Enforce the value if it existed in the active config.
      NestedArray::setValue($data, $parts, $value, TRUE);
    }

    return $data;
  }

}
