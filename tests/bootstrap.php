<?php

// Include the Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// The blueprint schema is a downloaded artifact (not committed). Fetch it once so the validator tests
// run offline against the local copy. Needs network only on the first run per checkout.
require_once __DIR__ . '/../scripts/fetch-blueprint-schema.php';
osc_fetch_blueprint_schema(false);

// Shared test fixtures (the Tests\ namespace is not autoloaded)
require_once __DIR__ . '/Resource/FakeCatalog.php';
require_once __DIR__ . '/Repository/FakeRepository.php';
require_once __DIR__ . '/Repository/TemporaryCache.php';

// Tests start online, whatever the environment exports (an offline build server may set it); tests of
// offline mode switch it on themselves.
putenv('OMEKA_S_CLI_OFFLINE');
unset($_ENV['OMEKA_S_CLI_OFFLINE']);
