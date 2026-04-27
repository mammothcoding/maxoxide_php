<?php

declare(strict_types=1);

// Manual bootstrap — loads the library without composer autoload.
// Use this when running examples directly from the source tree:
//
//   php examples/live_api_test.php

$srcDir = __DIR__ . '/src';

// Order matters: dependencies must be loaded before dependents.
require_once $srcDir . '/MaxException.php';
require_once $srcDir . '/Types.php';
require_once $srcDir . '/Update.php';
require_once $srcDir . '/Bot.php';
require_once $srcDir . '/Dispatcher.php';
require_once $srcDir . '/Webhook.php';
