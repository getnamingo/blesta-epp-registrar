<?php

/*
 * Namingo EPP Registrar module for Blesta
 *
 * Copyright (c) 2023-2026 Taras Kondratyuk
 * Copyright (c) 2025-2026 Namingo contributors
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Based on Generic EPP with DNSsec Registrar Module for WHMCS written in 2019 by Lilian Rudenco (info@xpanel.com)
 * Work of Lilian Rudenco is under http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 * https://github.com/xpanel/Generic-EPP-with-DNSsec-Registrar-Module-for-WHMCS
 *
 * Licensed under the MIT License.
 * See the LICENSE file distributed with this software for the full license text.
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

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
