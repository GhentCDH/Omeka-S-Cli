<?php

/**
 * Download the canonical blueprint schema into the local assets tree.
 *
 * The schema is not committed to the repository: it is a downloaded artifact. This script fetches it
 * from the shared repo (BlueprintValidator::SCHEMA_ID) into BlueprintValidator::schemaFile(), so it
 * can be bundled into the PHAR at build time and used as the offline fallback / by the test suite.
 *
 * Usage:
 *   php scripts/fetch-blueprint-schema.php            # download only if the local copy is missing
 *   php scripts/fetch-blueprint-schema.php --force    # always re-download (used by the PHAR build)
 *
 * It also defines osc_fetch_schema() so tests/bootstrap.php can reuse it without spawning a process.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use OSC\Blueprint\BlueprintValidator;
use OSC\Helper\ResourceFetcher;

/**
 * Download the schema into the bundled location.
 *
 * @param bool $force When false (default) the download is skipped if the local copy already exists.
 * @throws Exception When the schema cannot be fetched (and no local copy is present).
 */
function osc_fetch_blueprint_schema(bool $force = false): void
{
    $dest = (new BlueprintValidator())->schemaFile();

    if (!$force && is_file($dest)) {
        return;
    }

    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new Exception("Could not create schema directory: {$dir}");
    }

    $schema = ResourceFetcher::fetch(BlueprintValidator::SCHEMA_ID);
    if (file_put_contents($dest, $schema) === false) {
        throw new Exception("Could not write schema to: {$dest}");
    }
}

// Run only when invoked directly (not when required by the test bootstrap).
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $force = in_array('--force', $argv, true);
    osc_fetch_blueprint_schema($force);
    fwrite(STDOUT, 'Schema ready at ' . (new BlueprintValidator())->schemaFile() . PHP_EOL);
}
