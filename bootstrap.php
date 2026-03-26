<?php

declare(strict_types=1);

// Manual bootstrap — loads the library without composer autoload.
// Use this when running examples directly from the source tree:
//
//   php examples/live_api_test.php

$srcDir = __DIR__ . '/src';

// Order matters: dependencies must be loaded before dependents.
require $srcDir . '/MaxException.php';
require $srcDir . '/Types.php';
require $srcDir . '/Update.php';
require $srcDir . '/Bot.php';
require $srcDir . '/Dispatcher.php';
require $srcDir . '/Webhook.php';
