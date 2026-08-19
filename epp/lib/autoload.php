<?php

declare(strict_types=1);

/**
 * Minimal loader for the separately supplied Namingo EPP client.
 *
 * Copy the Namingo `epp` directory so that this file can see:
 *     __DIR__ . '/epp/src/EppRegistryFactory.php'
 */

if (!defined('BLesta_NAMINGO_EPP_AUTOLOADED')) {
    define('BLesta_NAMINGO_EPP_AUTOLOADED', true);

    spl_autoload_register(
        static function (string $class): void {
            $prefix = 'Pinga\\Tembo\\';
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $file = __DIR__ . '/epp/src/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        },
        true,
        true
    );
}
