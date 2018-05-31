<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\services;

use Craft;
use craft\helpers\FileHelper;
use craft\helpers\Path as PathHelper;
use Symfony\Component\Yaml\Yaml;
use yii\base\Component;

/**
 * Project config service.
 * An instance of the ProjectConfig service is globally accessible in Craft via [[\craft\base\ApplicationTrait::ProjectConfig()|<code>Craft::$app->projectConfig</code>]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class ProjectConfig extends Component
{
    // Constants
    // =========================================================================

    // Cache settings
    // -------------------------------------------------------------------------
    const CACHE_KEY = 'project.config.files';
    const CACHE_DURATION = 60 * 60 * 24 * 30;

    // Config entities
    // -------------------------------------------------------------------------
    const ENTITY_SITES = 'sites';
    const ENTITY_SECTIONS = 'sections';
    const ENTITY_FIELDS = 'fields';
    const ENTITY_VOLUMES = 'volumes';

    // Public methods
    // =========================================================================
    /**
     * @param string $path
     * @return array|mixed|null
     * @throws \yii\web\ServerErrorHttpException
     */
    public function get(string $path) {
        $snapshot = $this->getCurrentSnapshot();
        $pathParts = explode('.', $path);

        $current = $snapshot;

        foreach ($pathParts as $pathPart) {
            if (array_key_exists($pathPart, $current)) {
                $current = $current[$pathPart];
            } else {
                return null;
            }
        }

        return $current;
    }

    /**
     * Whether there is an update pending based on config and snapshot.
     *
     * @return bool
     */
    public function isUpdatePending(): bool
    {
        $configSnapshot = $this->generateSnapshotFromConfigFiles();
        $currentSnapshot = $this->getCurrentSnapshot();

        $flatConfig = [];
        $flatCurrent = [];

        unset($configSnapshot['imports']);

        // flatten both snapshots so we can compare them.

        $flatten = function ($array, $path, &$result) use (&$flatten) {
            foreach ($array as $key => $value) {
                $thisPath = $path.'#'.$key;

                if (is_array($value)) {
                    $flatten($value, $thisPath, $result);
                    $result[$thisPath] = '.';
                } else {
                    $result[$thisPath] = $value;
                }
            }
        };

        $flatten($configSnapshot, '', $flatConfig);
        $flatten($currentSnapshot, '', $flatCurrent);


        foreach ($flatConfig as $key => $value) {
            if (!array_key_exists($key, $flatCurrent) || $flatCurrent[$key] !== $value) {
                return true;
            }
            unset($flatCurrent[$key]);
        }

        return !empty($flatCurrent);
    }

    /**
     * Returns true if config mapping might have changed due to changes in file config tree or modify times.
     *
     * @return bool
     */
    public function isConfigMapOutdated(): bool {
        $yamlTree = $this->getConfigFileModifiedTimes();
        $cachedTree = $this->getConfigFileModifyDates();

        // Tree has changed
        if (\count(array_diff_key($yamlTree, $cachedTree)) || \count(array_diff_key($cachedTree, $yamlTree))) {
            return true;
        }

        // Date modified has changed
        foreach ($yamlTree as $file => $dateModified) {
            if ($dateModified !== $cachedTree[$file]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get config file modified dates.
     *
     * @return array
     */
    public function getConfigFileModifyDates(): array
    {
        $cachedTimes = Craft::$app->getCache()->get(self::CACHE_KEY);

        if (!$cachedTimes) {
            return [];
        }

        $this->updateDateModifiedCache($cachedTimes);

        return $cachedTimes;
    }

    /**
     * Update config file modified date cache. If no modified dates passed, the config file tree will be parsed
     * to figure out the modified dates.
     *
     * @param array|null $fileList
     * @return bool
     */
    public function updateDateModifiedCache(array $fileList = null): bool
    {
        if (!$fileList) {
            $fileList = $this->getConfigFileModifiedTimes();
        }

        return Craft::$app->getCache()->set(self::CACHE_KEY, $fileList, self::CACHE_DURATION);
    }

    /**
     * Retrieve a a config file tree with modified times based on the main `system.yml` configuration file.
     *
     * @return array
     */
    public function getConfigFileModifiedTimes(): array
    {
        $fileList = $this->_getConfigFileList();

        $output = [];

        foreach ($fileList as $file) {
            $output[$file] = FileHelper::lastModifiedTime($file);
        }

        return $output;
    }

    /**
     * Generate the configuration snapshot based on the configuration files.
     *
     * @return array
     */
    public function generateSnapshotFromConfigFiles(): array
    {
        $fileList = $this->_getConfigFileList();

        $snapshot = [];

        foreach ($fileList as $file) {
            $config = Yaml::parseFile($file);
            $snapshot = array_merge($snapshot, $config);
        }

        return $snapshot;
    }

    /**
     * Get the stored snapshot.
     *
     * @return array
     * @throws \yii\web\ServerErrorHttpException
     */
    public function getCurrentSnapshot(): array
    {
        return unserialize(Craft::$app->getInfo()->configSnapshot, ['allowed_classes' => false]);
    }

    /**
     * Generate the configuration mapping data from configuration files.
     *
     * @return array
     */
    public function generateConfigMap(): array
    {
        $fileList = $this->_getConfigFileList();

        $nodes = [];
        $map = [];

        $traverseAndExtract = function ($config, $prefix, &$map) use (&$traverseAndExtract) {
            foreach ($config as $key => $value) {
                // Does it look like a UID?
                if (preg_match('/[0-f]{8}-[0-f]{4}-[0-f]{4}-[0-f]{4}-[0-f]{12}/i', $key)) {
                    $map[$key] = $prefix.'.'.$key;
                }

                if (\is_array($value)) {
                    $traverseAndExtract($value, $prefix.(substr($prefix, -1) !== '/' ? '.' : '').$key, $map);
                }
            }
        };

        foreach ($fileList as $file) {
            $config = Yaml::parseFile($file);

            // Take record of top nodes
            $topNodes = array_keys($config);
            foreach ($topNodes as $topNode) {
                $nodes[$topNode] = $file;
            }

            $traverseAndExtract($config, $file.'/', $map);
        }

        unset($nodes['imports']);

        return [
            'nodes' => $nodes,
            'map' => $map
        ];
    }

    // Private methods
    // =========================================================================
    /**
     * Load the system.yml file and figure out all the files imported and used.
     *
     * @return array
     */
    private function _getConfigFileList() : array {
        $basePath = Craft::$app->getPath()->getConfigPath();
        $baseFile = $basePath.'/system.yml';

        $traverseFile = function($filePath) use (&$traverseFile) {
            $fileList = [$filePath];
            $config = Yaml::parseFile($filePath);
            $fileDir = pathinfo($filePath, PATHINFO_DIRNAME);

            if (isset($config['imports'])) {
                foreach ($config['imports'] as $file) {
                    if (PathHelper::ensurePathIsContained($file)) {
                        $fileList = array_merge($fileList, $traverseFile($fileDir.'/'.$file));
                    }
                }
            }

            return $fileList;
        };


        return $traverseFile($baseFile);
    }
}
