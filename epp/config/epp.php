<?php

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
    'SE' => 'IIS (.se/.nu)',
    'SWITCH' => 'SWITCH (.ch/.li)',
    'UA' => 'Hostmaster (.ua)',
    'VRSN' => 'Verisign'
]);

Configure::set('Epp.dnssec_algorithms', [
    '1' => 'RSA/MD5 (1)',
    '2' => 'Diffie-Hellman (2)',
    '3' => 'DSA/SHA-1 (3)',
    '5' => 'RSA/SHA-1 (5)',
    '6' => 'DSA-NSEC3-SHA1 (6)',
    '7' => 'RSASHA1-NSEC3-SHA1 (7)',
    '8' => 'RSA/SHA-256 (8)',
    '10' => 'RSA/SHA-512 (10)',
    '12' => 'ECC-GOST (12)',
    '13' => 'ECDSA P-256/SHA-256 (13)',
    '14' => 'ECDSA P-384/SHA-384 (14)',
    '15' => 'Ed25519 (15)',
    '16' => 'Ed448 (16)'
]);

Configure::set('Epp.dnssec_digests', [
    '1' => 'SHA-1 (1)',
    '2' => 'SHA-256 (2)',
    '3' => 'GOST R 34.11-94 (3)',
    '4' => 'SHA-384 (4)'
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
