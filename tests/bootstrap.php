<?php

// Include the Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// The blueprint schema is a downloaded artifact (not committed). Fetch it once so the validator tests
// run offline against the local copy. Needs network only on the first run per checkout.
require_once __DIR__ . '/../scripts/fetch-blueprint-schema.php';
osc_fetch_blueprint_schema(false);
