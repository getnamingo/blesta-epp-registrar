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

Configure::set('Epp.registry_profiles', [
    'generic' => 'Generic RFC EPP',
    'GE' => 'Caucasus Online (.ge)',
    'EU' => 'EURid (.eu)',
    'FR' => 'AFNIC (.fr)',
    'HR' => 'CARNET (.hr)',
    'LV' => 'NIC.LV (.lv)',
    'MX' => 'NIC Mexico (.mx)',
    'PL' => 'NASK (.pl)',
    'PT' => 'DNS.PT (.pt)',
    'RO' => 'RoTLD (.ro)',
    'SE' => 'IIS (.se/.nu)',
    'SI' => 'ARNES / Register.si (.si)',
    'SWITCH' => 'SWITCH (.ch/.li)',
    'UA' => 'Hostmaster (.ua)',
    'VRSN' => 'Verisign'
]);

Configure::set('Epp.dnssec_algorithms', [
    '8' => '8: RSA/SHA-256',
    '13' => '13: ECDSA P-256/SHA-256',
    '14' => '14: ECDSA P-384/SHA-384',
    '15' => '15: Ed25519',
    '16' => '16: Ed448'
]);

Configure::set('Epp.dnssec_digests', [
    '2' => '2: SHA-256',
    '4' => '4: SHA-384'
]);

Configure::set('Epp.default_login_objects', implode(', ', [
    'urn:ietf:params:xml:ns:domain-1.0',
    'urn:ietf:params:xml:ns:contact-1.0',
    'urn:ietf:params:xml:ns:host-1.0'
]));

Configure::set('Epp.default_login_extensions', implode(', ', [
    'urn:ietf:params:xml:ns:secDNS-1.1',
    'urn:ietf:params:xml:ns:rgp-1.0'
]));
