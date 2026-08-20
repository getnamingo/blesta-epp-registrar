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
