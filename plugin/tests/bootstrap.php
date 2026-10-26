<?php

/**
 * PHPUnit bootstrap for the unit suite.
 *
 * Loads the Composer autoloader (src/ and tests/ namespaces) and the minimal
 * set of WordPress function stubs the registrars call. No WordPress core is
 * loaded: the unit suite must stay fast and independent of a database.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Support/wp-stubs.php';
