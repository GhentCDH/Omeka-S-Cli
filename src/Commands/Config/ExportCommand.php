<?php

namespace OSC\Commands\Config;

use Exception;
use OSC\Commands\AbstractCommand;
use OSC\Settings\SettingsExport;
use OSC\Settings\SettingsSerializer;
use OSC\Settings\SettingsScope;
use OSC\Helper\ModuleSettingsMapper;

/**
 * Export an instance's settings to per-module, per-scope JSONC files.
 *
 * Global settings (the "setting" table), site settings ("site_setting") and user settings
 * ("user_setting") are read from the database and handed, together with a {@see SettingsScope}, to
 * {@see SettingsExport}, which attributes each key to its module, applies the scope and groups the
 * result into {@see \OSC\Settings\SettingsDocument}s. This command only fetches rows and writes files; the
 * looping, attribution and filtering live in those objects.
 */
class ExportCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('config:export', 'Export instance settings to JSONC files');
        $this->argument('[output-dir]', 'Directory to write the settings files to', '.');
        $this->option('--module', 'Only export settings of this module (or "core")', 'strval');
        $this->option('--scope', 'Which settings to export: setting, site_setting, user_setting or all', 'strval', 'all');
        $this->option('--site', 'Only export site settings for this site id', 'intval');
        $this->option('--user', 'Only export user settings for this user id', 'intval');
        $this->usage(
            'config:export [OUTPUT-DIR] [--module=ID] [--scope=all|setting|site_setting|user_setting] '
            . '[--site=ID] [--user=ID]<eol/>'
            . '<eol/>Examples:<eol/><eol/>'
            . '* Export everything into ./config:<eol/>'
            . 'config:export ./config<eol/>'
            . '<eol/>'
            . '* Export only the Log module global settings:<eol/>'
            . 'config:export ./config --module=Log --scope=setting<eol/>'
        );
    }

    public function execute(
        ?string $outputDir = '.',
        ?string $module = null,
        ?string $scope = 'all',
        ?int $site = null,
        ?int $user = null
    ): void {
        $outputDir = $outputDir ?: '.';
        $exportedAt = gmdate('c');

        $omekaInstance = $this->getOmekaInstance();
        $serviceManager = $omekaInstance->getServiceManager();
        $connection = $serviceManager->get('Omeka\Connection');

        // Build the module → version map and the key → module mapper.
        $moduleVersions = [];
        $moduleIds = [];
        foreach ($omekaInstance->getModuleApi()->getModules() as $moduleObject) {
            $id = $moduleObject->getId();
            $moduleIds[] = $id;
            $db = $moduleObject->getDb();
            $ini = $moduleObject->getIni();
            $moduleVersions[$id] = (is_array($db) ? ($db['version'] ?? null) : null)
                ?? (is_array($ini) ? ($ini['version'] ?? null) : null);
        }

        $export = new SettingsExport(
            SettingsScope::fromOptions($scope, $module, $site, $user),
            new ModuleSettingsMapper($moduleIds),
            $moduleVersions,
            $this->getOmekaVersion(),
            $connection->fetchAllKeyValue('SELECT id, slug FROM site'),
            $connection->fetchAllKeyValue('SELECT id, email FROM user'),
            $exportedAt,
        );

        $export->setSettings($connection->fetchAllAssociative('SELECT id, value FROM setting'));
        $export->setSiteSettings($connection->fetchAllAssociative('SELECT id, site_id, value FROM site_setting'));
        $export->setUserSettings($connection->fetchAllAssociative('SELECT id, user_id, value FROM user_setting'));

        $files = $export->files();
        if (!$files) {
            $this->warn('No settings matched the given filters. Nothing was written.', true);
            return;
        }

        if (!is_dir($outputDir) && !@mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            throw new Exception("Could not create output directory '{$outputDir}'.");
        }

        $serializer = new SettingsSerializer();
        foreach ($files as $file) {
            $this->debug('Wrote ' . $serializer->write($file, $outputDir), true);
        }

        $this->ok('Exported ' . count($files) . " settings file(s) to '{$outputDir}'.", true);
    }
}
