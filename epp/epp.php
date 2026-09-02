<?php
/**
 * Generic EPP registrar module for Blesta.
 *
 * Written in 2026 by Namingo Team (https://namingo.org)
 *
 * @license MIT
 */
class Epp extends RegistrarModule
{
    private const MODULE_DIR = 'epp';
    private const DEFAULT_PORT = 700;
    private const DEFAULT_PROFILE = 'generic';
    private const MAX_NAMESERVERS = 5;

    /** @var string Default view path relative to the Blesta installation. */
    private static $defaultModuleView;

    public function __construct()
    {
        Language::loadLang('epp', null, __DIR__ . DS . 'language' . DS);
        $this->loadConfig(__DIR__ . DS . 'config.json');
        Configure::load('epp', __DIR__ . DS . 'config' . DS);

        Loader::loadComponents($this, ['Input', 'Record']);
        require_once __DIR__ . DS . 'lib' . DS . 'autoload.php';

        self::$defaultModuleView = 'components' . DS . 'modules' . DS . self::MODULE_DIR . DS;
    }

    /**
     * Check the PHP features required by the separately supplied EPP client.
     */
    public function install()
    {
        $missing = [];
        foreach (['openssl', 'simplexml', 'xmlwriter'] as $extension) {
            if (!extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }

        if ($missing) {
            $this->Input->setErrors([
                'php' => ['extensions' => 'Missing required PHP extensions: ' . implode(', ', $missing)]
            ]);
        }
    }

    public function upgrade($current_version)
    {
        // No migrations are required in version 1.0.0.
    }

    public function uninstall($module_id, $last_instance)
    {
        // The module does not create database tables or cron tasks.
    }

    /**
     * Render the list of configured EPP accounts.
     */
    public function manageModule($module, array &$vars)
    {
        $this->view = new View('manage', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$defaultModuleView);
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);
        $this->view->set('module', $module);

        return $this->view->fetch();
    }

    public function manageAddRow(array &$vars)
    {
        $defaults = $this->rowDefaults();
        $vars = array_merge($defaults, $vars);

        return $this->renderRowView('add_row', $vars);
    }

    public function manageEditRow($module_row, array &$vars)
    {
        if (empty($vars)) {
            $vars = array_merge($this->rowDefaults(), (array) $module_row->meta);
            // Password inputs intentionally remain blank. Blank means retain.
            $vars['pw'] = '';
            $vars['passphrase'] = '';
        } else {
            $vars = array_merge($this->rowDefaults(), $vars);
        }

        return $this->renderRowView('edit_row', $vars);
    }

    private function renderRowView($view, array $vars)
    {
        $this->view = new View($view, 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$defaultModuleView);
        Loader::loadHelpers($this, ['Form', 'Html', 'Javascript', 'Widget']);

        Loader::loadModels($this, ['ModuleManager']);
        $modules = $this->ModuleManager->getByClass(
            Loader::fromCamelCase(get_class($this)),
            Configure::get('Blesta.company_id')
        );

        $this->view->set('module', (object) ($modules[0] ?? []));
        $this->view->set('vars', (object) $vars);
        $this->view->set('profiles', (array) Configure::get('Epp.registry_profiles'));

        return $this->view->fetch();
    }

    public function addModuleRow(array &$vars)
    {
        return $this->saveModuleRow($vars);
    }

    public function editModuleRow($module_row, array &$vars)
    {
        foreach (['pw', 'passphrase'] as $secret) {
            if (trim((string) ($vars[$secret] ?? '')) === '' && isset($module_row->meta->{$secret})) {
                $vars[$secret] = $module_row->meta->{$secret};
            }
        }

        return $this->saveModuleRow($vars);
    }

    public function deleteModuleRow($module_row)
    {
        // Nothing remote is created for an account row.
    }

    private function saveModuleRow(array &$vars)
    {
        $vars = array_merge($this->rowDefaults(), $vars);
        foreach ($this->checkboxFields() as $field) {
            $vars[$field] = $this->truthy($vars[$field] ?? false) ? 'true' : 'false';
        }

        $vars['host'] = trim((string) $vars['host']);
        $vars['port'] = (string) ((int) $vars['port']);
        $vars['tlds'] = implode(', ', $this->parseTlds($vars['tlds']));
        $vars['registry_profile'] = trim((string) $vars['registry_profile']);
        $vars['fee_currency'] = strtoupper(trim((string) $vars['fee_currency']));

        $this->Input->setRules($this->getRowRules($vars));
        if (!$this->Input->validates($vars)) {
            return;
        }

        try {
            $this->withClientMeta($vars, static function ($client) {
                return true;
            });
        } catch (\Throwable $e) {
            $this->Input->setErrors([
                'connection' => [
                    'valid' => Language::_('Epp.!error.connection', true, $e->getMessage())
                ]
            ]);
            return;
        }

        $encrypted = ['pw', 'passphrase'];
        $meta = [];
        foreach ($this->rowMetaFields() as $field) {
            $meta[] = [
                'key' => $field,
                'value' => $vars[$field] ?? '',
                'encrypted' => in_array($field, $encrypted, true) ? 1 : 0
            ];
        }

        return $meta;
    }

    private function rowDefaults()
    {
        return [
            'account_name' => '',
            'host' => '',
            'port' => (string) self::DEFAULT_PORT,
            'tls_version' => 'false',
            'verify_peer' => 'false',
            'cafile' => '',
            'local_cert' => 'cert.pem',
            'local_pk' => 'key.pem',
            'passphrase' => '',
            'clid' => '',
            'pw' => '',
            'registrarprefix' => 'epp',
            'contact_postal_type' => 'int',
            'registry_profile' => self::DEFAULT_PROFILE,
            'ns_mode' => 'hostObj',
            'tlds' => '',
            'set_authinfo_on_info' => 'false',
            'login_objects' => '',
            'login_extensions' => '',
            'gtld' => 'false',
            'min_data_set' => 'false',
            'eurid_billing_contact' => '',
            'pl_contact_prefix' => '',
            'tmch_claims_period_active' => 'false',
            'enable_fee_extension' => 'false',
            'allow_premium_domains' => 'false',
            'fee_currency' => 'USD',
            'debug_log' => 'false',
            'debug_log_path' => __DIR__ . DS . 'log'
        ];
    }

    private function rowMetaFields()
    {
        return array_keys($this->rowDefaults());
    }

    private function checkboxFields()
    {
        return [
            'tls_version',
            'verify_peer',
            'set_authinfo_on_info',
            'gtld',
            'min_data_set',
            'tmch_claims_period_active',
            'enable_fee_extension',
            'allow_premium_domains',
            'debug_log'
        ];
    }

    private function getRowRules(array $vars)
    {
        $profiles = (array) Configure::get('Epp.registry_profiles');

        return [
            'host' => [
                'required' => [
                    'rule' => ['matches', '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i'],
                    'message' => Language::_('Epp.!error.host', true)
                ]
            ],
            'port' => [
                'valid' => [
                    'rule' => static function ($port) {
                        return ctype_digit((string) $port) && (int) $port > 0 && (int) $port <= 65535;
                    },
                    'message' => Language::_('Epp.!error.port', true)
                ]
            ],
            'local_cert' => [
                'required' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Epp.!error.required', true, 'Client certificate')
                ]
            ],
            'local_pk' => [
                'required' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Epp.!error.required', true, 'Client private key')
                ]
            ],
            'clid' => [
                'required' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Epp.!error.required', true, 'Client ID')
                ]
            ],
            'pw' => [
                'required' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Epp.!error.required', true, 'Client password')
                ]
            ],
            'registry_profile' => [
                'valid' => [
                    'rule' => ['array_key_exists', $profiles],
                    'message' => Language::_('Epp.!error.profile', true)
                ]
            ],
            'ns_mode' => [
                'valid' => [
                    'rule' => ['in_array', ['hostObj', 'hostAttr']],
                    'message' => 'Select a valid nameserver mode.'
                ]
            ],
            'contact_postal_type' => [
                'valid' => [
                    'rule' => ['in_array', ['int', 'loc']],
                    'message' => 'Select a valid contact postal address type.'
                ]
            ],
            'tlds' => [
                'required' => [
                    'rule' => static function ($value) {
                        return trim((string) $value) !== '';
                    },
                    'message' => Language::_('Epp.!error.tlds', true)
                ]
            ],
            'fee_currency' => [
                'valid' => [
                    'rule' => ['matches', '/^[A-Z]{3}$/'],
                    'message' => 'Enter a three-letter ISO currency code.'
                ]
            ]
        ];
    }

    /**
     * Package configuration: TLD assignment and default nameservers.
     */
    public function getPackageFields($vars = null)
    {
        $fields = new ModuleFields();
        $vars = $vars ?: new stdClass();

        $type = $fields->label(Language::_('Epp.package.type', true), 'epp_type');
        $type->attach($fields->fieldSelect(
            'meta[type]',
            ['domain' => Language::_('Epp.package.type_domain', true)],
            $vars->meta['type'] ?? 'domain',
            ['id' => 'epp_type']
        ));
        $fields->setField($type);

        for ($i = 1; $i <= self::MAX_NAMESERVERS; $i++) {
            $label = $fields->label(Language::_('Epp.package.nameserver', true, $i), 'epp_ns' . $i);
            $label->attach($fields->fieldText(
                'meta[ns][]',
                $vars->meta['ns'][$i - 1] ?? '',
                ['id' => 'epp_ns' . $i]
            ));
            $fields->setField($label);
        }

        $eppCode = $fields->label(Language::_('Epp.package.epp_code', true));
        $eppCode->attach($fields->fieldCheckbox(
            'meta[epp_code]',
            '1',
            ($vars->meta['epp_code'] ?? '1') === '1',
            ['id' => 'epp_code'],
            $fields->label(Language::_('Epp.package.epp_code', true), 'epp_code')
        ));
        $fields->setField($eppCode);

        return $fields;
    }

    public function addPackage(array $vars = null)
    {
        return $this->savePackage($vars);
    }

    public function editPackage($package, array $vars = null)
    {
        return $this->savePackage($vars);
    }

    private function savePackage(array $vars = null)
    {
        $meta = (array) ($vars['meta'] ?? []);

        $result = [];
        foreach ($meta as $key => $value) {
            $result[] = ['key' => $key, 'value' => $value, 'encrypted' => 0];
        }
        return $result;
    }

    /**
     * Validate order/service input before provisioning.
     */
    public function validateService($package, array $vars = null)
    {
        $vars = $vars ?: [];
        $errors = [];

        try {
            $domain = $this->normalizeDomain($vars['domain'] ?? '');
        } catch (\Throwable $e) {
            $domain = '';
            $errors['domain']['valid'] = Language::_('Epp.!error.domain', true);
        }

        $transfer = $this->isTransfer($vars);
        if ($transfer && trim((string) ($vars['auth'] ?? '')) === '') {
            $errors['auth']['required'] = Language::_('Epp.!error.auth', true);
        }

        if (!$transfer) {
            try {
                $nameservers = $this->nameserversFromVars($vars);
                if (count($nameservers) < 2) {
                    $errors['nameservers']['required'] = Language::_('Epp.!error.nameservers', true);
                }
            } catch (\Throwable $e) {
                $errors['nameservers']['valid'] = $e->getMessage();
            }
        }

        if ($domain !== '' && !empty($package->meta->tlds)) {
            $allowed = array_map([$this, 'normalizeTld'], (array) $package->meta->tlds);
            $matched = false;
            foreach ($allowed as $tld) {
                if ($this->domainHasTld($domain, $tld)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $errors['domain']['tld'] = 'The domain TLD is not enabled for this package.';
            }
        }

        try {
            $row = $this->resolveRow($package->module_row ?? null);
            $profile = (string) ($row->meta->registry_profile ?? self::DEFAULT_PROFILE);
            $this->validateProfileFields($profile, $vars, $transfer);
            if (!$transfer && $this->truthy($row->meta->tmch_claims_period_active ?? false)) {
                if (trim((string) ($vars['tmch_notice_id'] ?? '')) === ''
                    || trim((string) ($vars['tmch_not_after'] ?? '')) === '') {
                    throw new \InvalidArgumentException(Language::_('Epp.!error.tmch', true));
                }
                $this->normalizeUtcDate((string) $vars['tmch_not_after']);
            }
        } catch (\Throwable $e) {
            $errors['profile']['valid'] = $e->getMessage();
        }

        if ($errors) {
            $this->Input->setErrors($errors);
            return false;
        }

        return true;
    }

    /**
     * Register or transfer a domain, then return fields Blesta should retain.
     */
    public function addService(
        $package,
        array $vars = null,
        $parent_package = null,
        $parent_service = null,
        $status = 'pending'
    ) {
        $vars = $vars ?: [];
        if (!$this->validateService($package, $vars)) {
            return;
        }

        $row = $this->resolveRow($vars['module_row_id'] ?? ($package->module_row ?? null));
        $domain = $this->normalizeDomain($vars['domain']);
        $years = $this->termFromPackage($package, $vars['pricing_id'] ?? null, 1);
        $transfer = $this->isTransfer($vars);
        $remoteMeta = [];

        if (($vars['use_module'] ?? 'true') === 'true') {
            try {
                if ($transfer) {
                    $this->performTransfer($domain, $row, [
                        'years' => $years,
                        'auth' => trim((string) $vars['auth'])
                    ]);
                    $remoteMeta['transfer_status'] = 'pending';
                } else {
                    $vars['years'] = $years;
                    $vars['_contact'] = $this->clientContact($vars['client_id'] ?? null, $vars);
                    $remoteMeta = $this->performRegistration($domain, $row, $vars);
                }
            } catch (\Throwable $e) {
                $this->setOperationError($transfer ? 'Transfer' : 'Registration', $e);
                return;
            }
        }

        $serviceFields = [
            'domain' => $domain,
            'transfer' => $transfer ? '1' : '0',
            'auth' => (string) ($vars['auth'] ?? '')
        ];
        for ($i = 1; $i <= self::MAX_NAMESERVERS; $i++) {
            $serviceFields['ns' . $i] = (string) ($vars['ns' . $i] ?? '');
        }
        foreach (['nin', 'nin_type', 'vat', 'pt_validated_date', 'tmch_notice_id', 'tmch_not_after'] as $field) {
            if (isset($vars[$field])) {
                $serviceFields[$field] = (string) $vars[$field];
            }
        }
        $serviceFields = array_merge($serviceFields, $remoteMeta);

        $encrypted = ['auth', 'auth_info'];
        $result = [];
        foreach ($serviceFields as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES);
            }
            $result[] = [
                'key' => $key,
                'value' => $value,
                'encrypted' => in_array($key, $encrypted, true) ? 1 : 0
            ];
        }

        return $result;
    }

    public function editService($package, $service, array $vars = [], $parent_package = null, $parent_service = null)
    {
        $useModule = !array_key_exists('use_module', $vars) || $this->truthy($vars['use_module']);
        $years = (int) ($vars['renew'] ?? 0);
        if ($useModule && $years > 0) {
            $domain = $this->getServiceDomain($service);
            if (!$this->renewDomain($domain, $service->module_row_id ?? $package->module_row, ['years' => $years])) {
                return;
            }
        }

        if ($useModule && array_key_exists('configoptions', $vars)) {
            $options = is_object($vars['configoptions'])
                ? (array) $vars['configoptions']
                : (array) $vars['configoptions'];
            $wasEnabled = $this->featureServiceEnabled('id_protection', $service);
            $isEnabled = isset($options['id_protection']);
            if ($wasEnabled !== $isEnabled
                && !$this->setIdProtection(
                    $this->getServiceDomain($service),
                    $isEnabled,
                    $service->module_row_id ?? $package->module_row
                )) {
                return;
            }
        }

        return null;
    }

    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        // Billing cancellation must not silently issue EPP domain:delete.
        // An explicit, typed confirmation is available in the Admin Actions tab.
        return null;
    }

    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        try {
            return $this->setHold(
                $this->getServiceDomain($service),
                true,
                $service->module_row_id ?? $package->module_row
            );
        } catch (\Throwable $e) {
            $this->setOperationError('Suspend', $e);
        }
    }

    public function unsuspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        try {
            return $this->setHold(
                $this->getServiceDomain($service),
                false,
                $service->module_row_id ?? $package->module_row
            );
        } catch (\Throwable $e) {
            $this->setOperationError('Unsuspend', $e);
        }
    }

    public function renewService($package, $service, $parent_package = null, $parent_service = null, $years = null)
    {
        $years = $years ?: $this->termFromPackage($package, $service->pricing_id ?? null, 1);
        if (!$this->renewDomain(
            $this->getServiceDomain($service),
            $service->module_row_id ?? $package->module_row,
            ['years' => $years]
        )) {
            return;
        }

        return null;
    }

    public function getAdminAddFields($package, $vars = null)
    {
        return $this->buildServiceFields($package, $vars ?: new stdClass(), false);
    }

    public function getClientAddFields($package, $vars = null)
    {
        return $this->buildServiceFields($package, $vars ?: new stdClass(), true);
    }

    private function buildServiceFields($package, $vars, $client)
    {
        $fields = new ModuleFields();
        $transfer = $this->truthy($vars->transfer ?? false) || trim((string) ($vars->auth ?? '')) !== '';

        if (!isset($vars->ns1) && isset($package->meta->ns)) {
            foreach (array_values((array) $package->meta->ns) as $index => $nameserver) {
                if ($index < self::MAX_NAMESERVERS) {
                    $vars->{'ns' . ($index + 1)} = $nameserver;
                }
            }
        }

        $domainValue = (string) ($vars->domain ?? '');
        if ($client && $domainValue !== '') {
            $fields->setField($fields->fieldHidden('domain', $domainValue, ['id' => 'epp_domain']));
        } else {
            $label = $fields->label(Language::_('Epp.service.domain', true), 'epp_domain');
            $label->attach($fields->fieldText('domain', $domainValue, ['id' => 'epp_domain']));
            $fields->setField($label);
        }

        if ($client) {
            $fields->setField($fields->fieldHidden('transfer', $transfer ? '1' : '0', ['id' => 'epp_transfer']));
        } else {
            $action = $fields->label(Language::_('Epp.service.action', true), 'epp_transfer_register');
            $registerLabel = $fields->label(Language::_('Epp.service.register', true), 'epp_transfer_register');
            $transferLabel = $fields->label(Language::_('Epp.service.transfer', true), 'epp_transfer_transfer');
            $action->attach($fields->fieldRadio(
                'transfer',
                '0',
                !$transfer,
                ['id' => 'epp_transfer_register'],
                $registerLabel
            ));
            $action->attach($fields->fieldRadio(
                'transfer',
                '1',
                $transfer,
                ['id' => 'epp_transfer_transfer'],
                $transferLabel
            ));
            $fields->setField($action);
        }

        if ($transfer || !$client) {
            $auth = $fields->label(Language::_('Epp.service.auth', true), 'epp_auth');
            $auth->attach($fields->fieldText('auth', (string) ($vars->auth ?? ''), ['id' => 'epp_auth']));
            $fields->setField($auth);
        }
        if ($transfer) {
            return $fields;
        }

        for ($i = 1; $i <= self::MAX_NAMESERVERS; $i++) {
            $label = $fields->label(Language::_('Epp.service.nameserver', true, $i), 'epp_service_ns' . $i);
            $label->attach($fields->fieldText(
                'ns' . $i,
                (string) ($vars->{'ns' . $i} ?? ''),
                ['id' => 'epp_service_ns' . $i]
            ));
            $fields->setField($label);
        }

        $row = $this->resolveRow($package->module_row ?? null);
        $profile = (string) ($row->meta->registry_profile ?? self::DEFAULT_PROFILE);
        $this->attachProfileFields($fields, $profile, $vars);

        if ($this->truthy($row->meta->tmch_claims_period_active ?? false)) {
            $notice = $fields->label(Language::_('Epp.service.tmch_notice_id', true), 'epp_tmch_notice_id');
            $notice->attach($fields->fieldText(
                'tmch_notice_id',
                (string) ($vars->tmch_notice_id ?? ''),
                ['id' => 'epp_tmch_notice_id']
            ));
            $fields->setField($notice);

            $notAfter = $fields->label(Language::_('Epp.service.tmch_not_after', true), 'epp_tmch_not_after');
            $notAfter->attach($fields->fieldText(
                'tmch_not_after',
                (string) ($vars->tmch_not_after ?? ''),
                ['id' => 'epp_tmch_not_after', 'placeholder' => '2026-12-31T23:59:59Z']
            ));
            $fields->setField($notAfter);
        }

        return $fields;
    }

    private function attachProfileFields(ModuleFields $fields, $profile, $vars)
    {
        if (in_array($profile, ['SE', 'HR', 'LV', 'GE'], true)) {
            $nin = $fields->label(Language::_('Epp.service.nin', true), 'epp_nin');
            $nin->attach($fields->fieldText('nin', (string) ($vars->nin ?? ''), ['id' => 'epp_nin']));
            $fields->setField($nin);
        }

        if ($profile === 'HR') {
            $type = $fields->label(Language::_('Epp.service.nin_type', true), 'epp_nin_type');
            $type->attach($fields->fieldSelect(
                'nin_type',
                [
                    'personal' => Language::_('Epp.service.nin_personal', true),
                    'company' => Language::_('Epp.service.nin_company', true)
                ],
                (string) ($vars->nin_type ?? 'personal'),
                ['id' => 'epp_nin_type']
            ));
            $fields->setField($type);
        }

        if (in_array($profile, ['SE', 'LV', 'PT'], true)) {
            $vat = $fields->label(Language::_('Epp.service.vat', true), 'epp_vat');
            $vat->attach($fields->fieldText('vat', (string) ($vars->vat ?? ''), ['id' => 'epp_vat']));
            $fields->setField($vat);
        }

        if ($profile === 'PT') {
            $validated = $fields->label(
                Language::_('Epp.service.pt_validated_date', true),
                'epp_pt_validated_date'
            );
            $validated->attach($fields->fieldText(
                'pt_validated_date',
                (string) ($vars->pt_validated_date ?? ''),
                ['id' => 'epp_pt_validated_date', 'placeholder' => '2026-08-17T12:00:00Z']
            ));
            $fields->setField($validated);
        }
    }

    public function getAdminEditFields($package, $vars = null)
    {
        $fields = new ModuleFields();
        $label = $fields->label('Manual renewal', 'epp_manual_renew');
        $label->attach($fields->fieldSelect(
            'renew',
            [0 => 'Do not renew', 1 => '1 year', 2 => '2 years', 3 => '3 years', 4 => '4 years', 5 => '5 years'],
            $vars->renew ?? 0,
            ['id' => 'epp_manual_renew']
        ));
        $fields->setField($label);
        return $fields;
    }

    public function getAdminServiceInfo($service, $package)
    {
        return '';
    }

    public function getClientServiceInfo($service, $package)
    {
        return '';
    }

    public function getAdminServiceTabs($service)
    {
        $tabs = [
            'tabOverview' => Language::_('Epp.tab.overview', true),
            'tabNameservers' => Language::_('Epp.tab.nameservers', true),
            'tabContacts' => Language::_('Epp.tab.contacts', true),
            'tabHosts' => Language::_('Epp.tab.hosts', true),
            'tabDnssec' => Language::_('Epp.tab.dnssec', true),
            'tabSettings' => Language::_('Epp.tab.settings', true),
            'tabAdminActions' => Language::_('Epp.tab.actions', true)
        ];

        $row = $this->resolveRow($service->module_row_id ?? null);
        if ($this->truthy($row->meta->min_data_set ?? false)) {
            unset($tabs['tabContacts']);
        }
        if ($this->usesHostAttributes($row)) {
            unset($tabs['tabHosts']);
        }
        return $tabs;
    }

    public function getClientServiceTabs($service)
    {
        $tabs = [
            'tabClientOverview' => ['name' => Language::_('Epp.tab.overview', true), 'icon' => 'fas fa-globe'],
            'tabClientNameservers' => ['name' => Language::_('Epp.tab.nameservers', true), 'icon' => 'fas fa-server'],
            'tabClientContacts' => ['name' => Language::_('Epp.tab.contacts', true), 'icon' => 'fas fa-address-card'],
            'tabClientHosts' => ['name' => Language::_('Epp.tab.hosts', true), 'icon' => 'fas fa-network-wired'],
            'tabClientDnssec' => ['name' => Language::_('Epp.tab.dnssec', true), 'icon' => 'fas fa-shield-alt'],
            'tabClientSettings' => ['name' => Language::_('Epp.tab.settings', true), 'icon' => 'fas fa-cog']
        ];

        $row = $this->resolveRow($service->module_row_id ?? null);
        if ($this->truthy($row->meta->min_data_set ?? false)) {
            unset($tabs['tabClientContacts']);
        }
        if ($this->usesHostAttributes($row)) {
            unset($tabs['tabClientHosts']);
        }
        return $tabs;
    }

    public function tabOverview($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageOverview($package, $service, false);
    }

    public function tabClientOverview($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageOverview($package, $service, true);
    }

    public function tabNameservers($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageNameservers($package, $service, $post, false);
    }

    public function tabClientNameservers($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageNameservers($package, $service, $post, true);
    }

    public function tabContacts($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageContacts($package, $service, $post, false);
    }

    public function tabClientContacts($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageContacts($package, $service, $post, true);
    }

    public function tabHosts($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageHosts($package, $service, $post, false);
    }

    public function tabClientHosts($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageHosts($package, $service, $post, true);
    }

    public function tabDnssec($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageDnssec($package, $service, $post, false);
    }

    public function tabClientDnssec($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageDnssec($package, $service, $post, true);
    }

    public function tabSettings($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageSettings($package, $service, $post, false);
    }

    public function tabClientSettings($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageSettings($package, $service, $post, true);
    }

    public function tabAdminActions($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageAdminActions($package, $service, $post);
    }

    private function manageOverview($package, $service, $client)
    {
        $domain = $this->getServiceDomain($service);
        $rowId = $service->module_row_id ?? ($package->module_row ?? null);
        $info = [];

        try {
            $info = $this->fetchDomainInfo($domain, $rowId);
        } catch (\Throwable $e) {
            $this->setOperationError('Domain information', $e);
        }

        return $this->renderTab('tab_overview', [
            'domain' => $domain,
            'info' => $info,
            'client' => $client
        ]);
    }

    private function manageNameservers($package, $service, array $post = null, $client = false)
    {
        $domain = $this->getServiceDomain($service);
        $rowId = $service->module_row_id ?? ($package->module_row ?? null);
        $nameservers = [];

        try {
            if (!empty($post)) {
                $vars = [];
                foreach (array_values((array) ($post['ns'] ?? [])) as $index => $nameserver) {
                    if ($index < self::MAX_NAMESERVERS) {
                        $vars['ns' . ($index + 1)] = trim((string) $nameserver);
                    }
                }
                $updated = $this->setDomainNameservers($domain, $rowId, $vars);
                if ($updated && !$this->Input->errors()) {
                    $this->setMessage('success', Language::_('Epp.!success.nameservers', true));
                }
            }
            foreach ($this->getDomainNameServers($domain, $rowId) as $nameserver) {
                $nameservers[] = is_array($nameserver)
                    ? (string) ($nameserver['url'] ?? '')
                    : (string) $nameserver;
            }
        } catch (\Throwable $e) {
            $this->setOperationError('Nameserver update', $e);
        }

        return $this->renderTab('tab_nameservers', [
            'domain' => $domain,
            'nameservers' => array_values((array) $nameservers),
            'client' => $client
        ]);
    }

    private function manageContacts($package, $service, array $post = null, $client = false)
    {
        $domain = $this->getServiceDomain($service);
        $rowId = $service->module_row_id ?? ($package->module_row ?? null);
        $contacts = [];
        $row = $this->resolveRow($rowId);
        $profile = (string) ($row->meta->registry_profile ?? self::DEFAULT_PROFILE);
        $serviceFields = isset($service->fields)
            ? $this->serviceFieldsToObject($service->fields)
            : new stdClass();
        $ptValidatedDate = (string) ($serviceFields->pt_validated_date ?? '');

        try {
            if (!empty($post['contacts'])) {
                $ptValidatedDate = trim((string) ($post['pt_validated_date'] ?? $ptValidatedDate));
                $updated = $this->setDomainContacts($domain, [
                    'contacts' => (array) $post['contacts'],
                    'pt_validated_date' => $ptValidatedDate
                ], $rowId);
                if ($updated && !$this->Input->errors()) {
                    if ($profile === 'PT' && isset($service->id)) {
                        $ptValidatedDate = $this->normalizeUtcDate($ptValidatedDate);
                        $this->storeServiceField(
                            (int) $service->id,
                            'pt_validated_date',
                            $ptValidatedDate
                        );
                    }
                    $this->setMessage('success', Language::_('Epp.!success.contacts', true));
                }
            }
            $contacts = $this->fetchContactsByRole($domain, $rowId);
        } catch (\Throwable $e) {
            $this->setOperationError('Contact update', $e);
        }

        return $this->renderTab('tab_contacts', [
            'domain' => $domain,
            'contacts' => $contacts,
            'profile' => $profile,
            'pt_validated_date' => $ptValidatedDate,
            'client' => $client
        ]);
    }

    private function manageHosts($package, $service, array $post = null, $client = false)
    {
        $domain = $this->getServiceDomain($service);
        $rowId = $service->module_row_id ?? ($package->module_row ?? null);
        $hosts = [];

        try {
            if (!empty($post['action'])) {
                $action = (string) $post['action'];
                $hostname = $this->normalizeDomain($post['hostname'] ?? '');
                if (!$this->isChildHost($hostname, $domain)) {
                    throw new \InvalidArgumentException(Language::_('Epp.!error.child_host', true));
                }

                if ($action === 'create') {
                    $this->createHost($hostname, trim((string) ($post['ip_address'] ?? '')), $rowId);
                } elseif ($action === 'update') {
                    $this->updateHost(
                        $hostname,
                        trim((string) ($post['current_ip_address'] ?? '')),
                        trim((string) ($post['new_ip_address'] ?? '')),
                        $rowId
                    );
                } elseif ($action === 'delete') {
                    $this->deleteHost($hostname, $rowId);
                } else {
                    throw new \InvalidArgumentException('Unknown host action.');
                }
                $this->setMessage('success', Language::_('Epp.!success.action', true));
            }
            $hosts = $this->getChildHosts($domain, $rowId);
        } catch (\Throwable $e) {
            $this->setOperationError('Child nameserver action', $e);
        }

        return $this->renderTab('tab_hosts', [
            'domain' => $domain,
            'hosts' => $hosts,
            'client' => $client
        ]);
    }

    private function manageDnssec($package, $service, array $post = null, $client = false)
    {
        $domain = $this->getServiceDomain($service);
        $rowId = $service->module_row_id ?? ($package->module_row ?? null);
        $records = [];

        try {
            if (!empty($post['action'])) {
                $record = [
                    'key_tag' => (int) ($post['key_tag'] ?? 0),
                    'algorithm' => (int) ($post['algorithm'] ?? 0),
                    'digest_type' => (int) ($post['digest_type'] ?? 0),
                    'digest' => strtoupper(preg_replace('/\s+/', '', (string) ($post['digest'] ?? '')))
                ];
                $this->validateDsRecord($record);
                $add = (string) $post['action'] === 'add';
                if (!$add && (string) $post['action'] !== 'remove') {
                    throw new \InvalidArgumentException('Unknown DNSSEC action.');
                }
                $this->updateDsRecord($domain, $record, $add, $rowId);
                $this->setMessage(
                    'success',
                    Language::_($add ? 'Epp.!success.dnssec_add' : 'Epp.!success.dnssec_remove', true)
                );
            }
            $records = $this->getDsRecords($domain, $rowId);
        } catch (\Throwable $e) {
            $this->setOperationError('DNSSEC action', $e);
        }

        return $this->renderTab('tab_dnssec', [
            'domain' => $domain,
            'records' => $records,
            'algorithms' => (array) Configure::get('Epp.dnssec_algorithms'),
            'digests' => (array) Configure::get('Epp.dnssec_digests'),
            'client' => $client
        ]);
    }

    private function manageSettings($package, $service, array $post = null, $client = false)
    {
        $domain = $this->getServiceDomain($service);
        $rowId = $service->module_row_id ?? ($package->module_row ?? null);
        $authInfo = null;
        $locked = false;
        $privacyAvailable = !$client || $this->featureServiceEnabled('id_protection', $service);

        try {
            if (!empty($post['action'])) {
                $action = (string) $post['action'];
                $completed = true;
                if ($action === 'lock') {
                    $completed = $this->lockDomain($domain, $rowId);
                } elseif ($action === 'unlock') {
                    $completed = $this->unlockDomain($domain, $rowId);
                } elseif ($action === 'privacy_enable') {
                    if (!$privacyAvailable) {
                        throw new \RuntimeException('ID protection is not enabled for this service.');
                    }
                    $completed = $this->setIdProtection($domain, true, $rowId);
                } elseif ($action === 'privacy_disable') {
                    if (!$privacyAvailable) {
                        throw new \RuntimeException('ID protection is not enabled for this service.');
                    }
                    $completed = $this->setIdProtection($domain, false, $rowId);
                } elseif ($action === 'auth_info') {
                    if (($package->meta->epp_code ?? '1') !== '1') {
                        throw new \RuntimeException('AuthInfo retrieval is disabled for this package.');
                    }
                    $authInfo = $this->getAuthInfo($domain, $rowId);
                } elseif ($action === 'auth_update' && !$client) {
                    $newCode = trim((string) ($post['new_auth_info'] ?? ''));
                    if ($newCode === '') {
                        throw new \InvalidArgumentException('Enter a new AuthInfo code.');
                    }
                    $completed = $this->updateEppCode($domain, $newCode, $rowId);
                } else {
                    throw new \InvalidArgumentException('Unknown settings action.');
                }

                if ($completed && $action !== 'auth_info' && !$this->Input->errors()) {
                    $this->setMessage('success', Language::_('Epp.!success.settings', true));
                }
            }
            $locked = $this->getDomainIsLocked($domain, $rowId);
        } catch (\Throwable $e) {
            $this->setOperationError('Domain settings', $e);
        }

        return $this->renderTab('tab_settings', [
            'domain' => $domain,
            'locked' => $locked,
            'auth_info' => $authInfo,
            'epp_code_enabled' => ($package->meta->epp_code ?? '1') === '1',
            'privacy_available' => $privacyAvailable,
            'client' => $client
        ]);
    }

    private function manageAdminActions($package, $service, array $post = null)
    {
        $domain = $this->getServiceDomain($service);
        $rowId = $service->module_row_id ?? ($package->module_row ?? null);
        $info = [];
        $transfer = [];
        $deleted = false;
        $serviceFields = isset($service->fields)
            ? $this->serviceFieldsToObject($service->fields)
            : new stdClass();
        $serviceIsTransfer = $this->truthy($serviceFields->transfer ?? false);

        try {
            if (!empty($post['action'])) {
                $action = (string) $post['action'];
                $completed = true;
                if (in_array($action, ['approve', 'cancel', 'reject'], true)) {
                    $completed = $this->performTransferAction($domain, $action, $rowId);
                } elseif ($action === 'hold') {
                    $completed = $this->setHold($domain, true, $rowId);
                } elseif ($action === 'unhold') {
                    $completed = $this->setHold($domain, false, $rowId);
                } elseif ($action === 'restore') {
                    $completed = $this->restoreDomain($domain, $rowId);
                } elseif ($action === 'delete') {
                    if (trim((string) ($post['confirm_domain'] ?? '')) !== $domain) {
                        throw new \InvalidArgumentException(Language::_('Epp.!error.confirm_domain', true));
                    }
                    $completed = $this->deleteDomain($domain, $rowId);
                    $deleted = (bool) $completed;
                } else {
                    throw new \InvalidArgumentException('Unknown registry action.');
                }
                if ($completed && !$this->Input->errors()) {
                    $this->setMessage('success', Language::_('Epp.!success.action', true));
                }
            }

            if (!$deleted) {
                try {
                    $info = $this->fetchDomainInfo($domain, $rowId);
                } catch (\Throwable $infoError) {
                    if (!$serviceIsTransfer) {
                        throw $infoError;
                    }
                }

                if ($serviceIsTransfer
                    || in_array('pendingTransfer', (array) ($info['statuses'] ?? []), true)) {
                    try {
                        $transfer = $this->queryTransfer($domain, $rowId);
                    } catch (\Throwable $transferError) {
                        if (!$info) {
                            throw $transferError;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->setOperationError('Registry action', $e);
        }

        return $this->renderTab('tab_admin_actions', [
            'domain' => $domain,
            'info' => $info,
            'transfer' => $transfer,
            'client' => false
        ]);
    }

    private function renderTab($view, array $vars)
    {
        $this->view = new View($view, 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$defaultModuleView);
        Loader::loadHelpers($this, ['Form', 'Html']);
        foreach ($vars as $key => $value) {
            $this->view->set($key, $value);
        }
        return $this->view->fetch();
    }

    /**
     * RegistrarModule availability API.
     */
    public function checkAvailability($domain, $module_row_id = null)
    {
        try {
            $domain = $this->normalizeDomain($domain);
            $row = $this->resolveRow($module_row_id);

            return $this->withClient($row, function ($client) use ($domain, $row) {
                $available = $this->checkDomainOnClient($client, $domain, $row);
                if ($available) {
                    $this->assertPremiumAllowed($client, $domain, $row, 'create', 1);
                }
                return $available;
            });
        } catch (\Throwable $e) {
            $this->setOperationError('Availability check', $e);
            return false;
        }
    }

    /**
     * EPP supports checking several names in one command. This method is used
     * by newer Domain Manager versions when available.
     */
    public function bulkCheckAvailability($domains, $module_row_id = null)
    {
        $domains = is_array($domains) ? $domains : (array) $domains;

        $result = [];
        try {
            $row = $this->resolveRow($module_row_id);
            $normalized = [];
            foreach ($domains as $domain) {
                $normalized[] = $this->normalizeDomain($domain);
            }

            return $this->withClient($row, function ($client) use ($normalized, $row) {
                $response = $this->invoke($client, 'domainCheck', ['domains' => $normalized], $row);
                $availability = $this->availabilityMap($response);
                foreach ($normalized as $domain) {
                    $available = (bool) ($availability[$domain] ?? false);
                    if ($available) {
                        $this->assertPremiumAllowed($client, $domain, $row, 'create', 1);
                    }
                    $result[$domain] = $available;
                }
                return $result;
            });
        } catch (\Throwable $e) {
            $this->setOperationError('Bulk availability check', $e);
            foreach ($domains as $domain) {
                $result[(string) $domain] = false;
            }
            return $result;
        }
    }

    public function checkTransferAvailability($domain, $module_row_id = null)
    {
        try {
            $domain = $this->normalizeDomain($domain);
            $row = $this->resolveRow($module_row_id);
            return $this->withClient($row, function ($client) use ($domain, $row) {
                return !$this->checkDomainOnClient($client, $domain, $row);
            });
        } catch (\Throwable $e) {
            $this->setOperationError('Transfer availability check', $e);
            return false;
        }
    }

    public function registerDomain($domain, $module_row_id = null, array $vars = [])
    {
        try {
            $row = $this->resolveRow($module_row_id);
            if (!isset($vars['_contact'])) {
                $vars['_contact'] = $this->clientContact($vars['client_id'] ?? null, $vars);
            }
            $this->performRegistration($this->normalizeDomain($domain), $row, $vars);
            return true;
        } catch (\Throwable $e) {
            $this->setOperationError('Registration', $e);
            return false;
        }
    }

    public function transferDomain($domain, $module_row_id = null, array $vars = [])
    {
        try {
            $row = $this->resolveRow($module_row_id);
            $this->performTransfer($this->normalizeDomain($domain), $row, [
                'years' => (int) ($vars['years'] ?? $vars['period'] ?? 1),
                'auth' => (string) ($vars['auth'] ?? $vars['epp_code'] ?? '')
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->setOperationError('Transfer', $e);
            return false;
        }
    }

    public function renewDomain($domain, $module_row_id = null, array $vars = [])
    {
        try {
            $row = $this->resolveRow($module_row_id);
            if (($row->meta->registry_profile ?? self::DEFAULT_PROFILE) === 'LV') {
                return true;
            }

            $domain = $this->normalizeDomain($domain);
            $years = max(1, (int) ($vars['years'] ?? $vars['period'] ?? 1));
            if (!$this->isValidTerm($this->getDomainTld($domain, $row), $years, false)) {
                throw new \InvalidArgumentException(Language::_('Epp.!error.term', true));
            }

            return $this->withClient($row, function ($client) use ($domain, $years, $row) {
                $this->assertPremiumAllowed($client, $domain, $row, 'renew', $years);
                $this->invoke($client, 'domainRenew', [
                    'domainname' => $domain,
                    'regperiod' => $years
                ], $row);
                return true;
            });
        } catch (\Throwable $e) {
            $this->setOperationError('Renewal', $e);
            return false;
        }
    }

    public function restoreDomain($domain, $module_row_id = null, array $vars = [])
    {
        try {
            $row = $this->resolveRow($module_row_id);
            $domain = $this->normalizeDomain($domain);
            return $this->withClient($row, function ($client) use ($domain, $row) {
                $this->invoke($client, 'domainRestore', ['domainname' => $domain], $row);
                return true;
            });
        } catch (\Throwable $e) {
            $this->setOperationError('Restore', $e);
            return false;
        }
    }

    public function deleteDomain($domain, $module_row_id = null)
    {
        $row = $this->resolveRow($module_row_id);
        $domain = $this->normalizeDomain($domain);
        return $this->withClient($row, function ($client) use ($domain, $row) {
            $this->invoke($client, 'domainDelete', ['domainname' => $domain], $row);
            return true;
        });
    }

    public function getDomainInfo($domain, $module_row_id = null)
    {
        try {
            return $this->fetchDomainInfo($domain, $module_row_id);
        } catch (\Throwable $e) {
            $this->setOperationError('Domain information', $e);
            return [];
        }
    }

    private function fetchDomainInfo($domain, $module_row_id = null)
    {
        $row = $this->resolveRow($module_row_id);
        $domain = $this->normalizeDomain($domain);
        return $this->withClient($row, function ($client) use ($domain, $row) {
            $info = $this->invoke($client, 'domainInfo', ['domainname' => $domain], $row);
            return [
                'domain' => $domain,
                'roid' => (string) ($info['roid'] ?? ''),
                'statuses' => $this->normalizeStatuses($info['status'] ?? []),
                'nameservers' => array_values(array_filter((array) ($info['ns'] ?? []))),
                'hosts' => array_values(array_filter((array) ($info['host'] ?? []))),
                'registrant' => (string) ($info['registrant'] ?? ''),
                'contacts' => (array) ($info['contact'] ?? []),
                'created' => (string) ($info['crDate'] ?? ''),
                'updated' => (string) ($info['upDate'] ?? ''),
                'expires' => (string) ($info['exDate'] ?? ''),
                'transferred' => (string) ($info['trDate'] ?? ''),
                'client_id' => (string) ($info['clID'] ?? ''),
                'ds_data' => array_values((array) ($info['dsData'] ?? []))
            ];
        });
    }

    public function getDomainNameServers($domain, $module_row_id = null)
    {
        try {
            $info = $this->fetchDomainInfo($domain, $module_row_id);
            $nameservers = [];
            foreach ($info['nameservers'] as $nameserver) {
                $nameservers[] = ['url' => (string) $nameserver, 'ips' => []];
            }
            return $nameservers;
        } catch (\Throwable $e) {
            $this->setOperationError('Nameserver lookup', $e);
            return [];
        }
    }

    public function setDomainNameservers($domain, $module_row_id = null, array $vars = [])
    {
        try {
            $row = $this->resolveRow($module_row_id);
            $domain = $this->normalizeDomain($domain);
            $nameservers = $this->nameserversFromVars($vars);
            if (count($nameservers) < 2) {
                throw new \InvalidArgumentException(Language::_('Epp.!error.nameservers', true));
            }

            return $this->withClient($row, function ($client) use ($domain, $nameservers, $row) {
                if (!$this->usesHostAttributes($row)) {
                    foreach ($nameservers as $nameserver) {
                        $this->ensureHostObject($client, $nameserver, $row);
                    }
                }

                if ($this->usesHostAttributes($row)) {
                    $payload = ['domainname' => $domain, 'nss' => []];
                    foreach ($nameservers as $nameserver) {
                        $payload['nss'][] = $this->hostAttribute($nameserver);
                    }
                } else {
                    $payload = ['domainname' => $domain];
                    foreach ($nameservers as $index => $nameserver) {
                        $payload['ns' . ($index + 1)] = $nameserver;
                    }
                }

                $this->invoke($client, 'domainUpdateNS', $payload, $row);
                return true;
            });
        } catch (\Throwable $e) {
            $this->setOperationError('Nameserver update', $e);
            return false;
        }
    }

    public function getDomainIsLocked($domain, $module_row_id = null)
    {
        try {
            $info = $this->fetchDomainInfo($domain, $module_row_id);
            foreach ($info['statuses'] as $status) {
                if (preg_match('/^client(?:Transfer|Delete|Update)Prohibited$/i', $status)) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            $this->setOperationError('Registrar-lock lookup', $e);
            return false;
        }
    }

    public function lockDomain($domain, $module_row_id = null)
    {
        try {
            return $this->setRegistrarLock($domain, true, $module_row_id);
        } catch (\Throwable $e) {
            $this->setOperationError('Registrar lock', $e);
            return false;
        }
    }

    public function unlockDomain($domain, $module_row_id = null)
    {
        try {
            return $this->setRegistrarLock($domain, false, $module_row_id);
        } catch (\Throwable $e) {
            $this->setOperationError('Registrar unlock', $e);
            return false;
        }
    }

    private function setRegistrarLock($domain, $enabled, $module_row_id = null)
    {
        $row = $this->resolveRow($module_row_id);
        $domain = $this->normalizeDomain($domain);

        return $this->withClient($row, function ($client) use ($domain, $enabled, $row) {
            $info = $this->invoke($client, 'domainInfo', ['domainname' => $domain], $row);
            $statuses = array_fill_keys($this->normalizeStatuses($info['status'] ?? []), true);
            $lockStatuses = ($row->meta->registry_profile ?? self::DEFAULT_PROFILE) === 'GE'
                ? ['clientTransferProhibited', 'clientUpdateProhibited']
                : ['clientDeleteProhibited', 'clientTransferProhibited'];

            foreach ($lockStatuses as $status) {
                $present = isset($statuses[$status]);
                if ($enabled === $present) {
                    continue;
                }
                $this->invoke($client, 'domainUpdateStatus', [
                    'domainname' => $domain,
                    'command' => $enabled ? 'add' : 'rem',
                    'status' => $status
                ], $row);
            }
            return true;
        });
    }

    public function getDomainContacts($domain, $module_row_id = null)
    {
        try {
            return array_values($this->fetchContactsByRole($domain, $module_row_id));
        } catch (\Throwable $e) {
            $this->setOperationError('Contact lookup', $e);
            return [];
        }
    }

    private function fetchContactsByRole($domain, $module_row_id = null)
    {
        $row = $this->resolveRow($module_row_id);
        if ($this->truthy($row->meta->min_data_set ?? false)) {
            return [];
        }
        $domain = $this->normalizeDomain($domain);

        return $this->withClient($row, function ($client) use ($domain, $row) {
            $domainInfo = $this->invoke($client, 'domainInfo', ['domainname' => $domain], $row);
            $ids = $this->contactIdsFromDomainInfo($domainInfo);
            $byId = [];
            $result = [];

            foreach ($ids as $role => $id) {
                if (!isset($byId[$id])) {
                    $byId[$id] = $this->invoke($client, 'contactInfo', ['contact' => $id], $row);
                }
                $contact = $byId[$id];
                [$firstName, $lastName] = $this->splitName((string) ($contact['name'] ?? ''));
                $result[$role] = [
                    'type' => $role,
                    'external_id' => $id,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'company' => (string) ($contact['org'] ?? ''),
                    'address1' => (string) ($contact['street1'] ?? ''),
                    'address2' => (string) ($contact['street2'] ?? ''),
                    'address3' => (string) ($contact['street3'] ?? ''),
                    'city' => (string) ($contact['city'] ?? ''),
                    'state' => (string) ($contact['state'] ?? ''),
                    'zip' => (string) ($contact['postal'] ?? ''),
                    'country' => (string) ($contact['country'] ?? ''),
                    'phone' => (string) ($contact['voice'] ?? ''),
                    'fax' => (string) ($contact['fax'] ?? ''),
                    'email' => (string) ($contact['email'] ?? '')
                ];
            }
            return $result;
        });
    }

    public function setDomainContacts($domain, array $vars = [], $module_row_id = null)
    {
        try {
            $row = $this->resolveRow($module_row_id);
            if ($this->truthy($row->meta->min_data_set ?? false)) {
                return true;
            }
            $domain = $this->normalizeDomain($domain);
            $submittedContacts = (array) ($vars['contacts'] ?? $vars);
            $contacts = [];
            $contactsById = [];
            foreach ($submittedContacts as $key => $contact) {
                if (!is_array($contact)) {
                    continue;
                }
                $role = !is_int($key) && !ctype_digit((string) $key)
                    ? (string) $key
                    : (string) ($contact['type'] ?? '');
                if ($role !== '') {
                    $contacts[$role] = $contact;
                }
                if (trim((string) ($contact['external_id'] ?? '')) !== '') {
                    $contactsById[(string) $contact['external_id']] = $contact;
                }
            }
            $ptValidatedDate = trim((string) ($vars['pt_validated_date'] ?? ''));

            return $this->withClient($row, function ($client) use (
                $domain,
                $contacts,
                $contactsById,
                $ptValidatedDate,
                $row
            ) {
                $domainInfo = $this->invoke($client, 'domainInfo', ['domainname' => $domain], $row);
                $ids = $this->contactIdsFromDomainInfo($domainInfo);
                $updated = [];

                foreach ($ids as $role => $id) {
                    $data = $contacts[$role] ?? $contactsById[$id] ?? null;
                    if (isset($updated[$id]) || !is_array($data)) {
                        continue;
                    }
                    if ($ptValidatedDate !== '' && empty($data['pt_validated_date'])) {
                        $data['pt_validated_date'] = $ptValidatedDate;
                    }
                    $this->validateContact($data);
                    $payload = $this->contactPayload($id, $data, $row, $role, false);
                    $this->invoke($client, 'contactUpdate', $payload, $row);
                    $updated[$id] = true;
                }
                return true;
            });
        } catch (\Throwable $e) {
            $this->setOperationError('Contact update', $e);
            return false;
        }
    }

    public function getAuthInfo($domain, $module_row_id = null)
    {
        $row = $this->resolveRow($module_row_id);
        $domain = $this->normalizeDomain($domain);
        return $this->withClient($row, function ($client) use ($domain, $row) {
            if ($this->truthy($row->meta->set_authinfo_on_info ?? false)) {
                $code = $this->randomPassword();
                $this->invoke($client, 'domainUpdateAuthinfo', [
                    'domainname' => $domain,
                    'authInfo' => $code
                ], $row);
                return $code;
            }

            $info = $this->invoke($client, 'domainInfo', ['domainname' => $domain], $row);
            $code = (string) ($info['authInfo'] ?? '');
            if ($code === '') {
                throw new \RuntimeException('The registry did not return an AuthInfo code.');
            }
            return $code;
        });
    }

    public function updateEppCode($domain, $epp_code, $module_row_id = null, array $vars = [])
    {
        try {
            $row = $this->resolveRow($module_row_id);
            $domain = $this->normalizeDomain($domain);
            $eppCode = trim((string) $epp_code);
            if ($eppCode === '') {
                throw new \InvalidArgumentException('AuthInfo cannot be empty.');
            }

            return $this->withClient($row, function ($client) use ($domain, $eppCode, $row) {
                $this->invoke($client, 'domainUpdateAuthinfo', [
                    'domainname' => $domain,
                    'authInfo' => $eppCode
                ], $row);
                return true;
            });
        } catch (\Throwable $e) {
            $this->setOperationError('AuthInfo update', $e);
            return false;
        }
    }

    public function setIdProtection($domain, $enabled, $module_row_id = null)
    {
        try {
            $row = $this->resolveRow($module_row_id);
            if ($this->truthy($row->meta->min_data_set ?? false)) {
                return true;
            }
            $domain = $this->normalizeDomain($domain);

            return $this->withClient($row, function ($client) use ($domain, $enabled, $row) {
                $this->setPrivacyOnClient($client, $domain, (bool) $enabled, $row);
                return true;
            });
        } catch (\Throwable $e) {
            $this->setOperationError('Contact privacy', $e);
            return false;
        }
    }

    private function setPrivacyOnClient($client, $domain, $enabled, $row)
    {
        $profile = (string) ($row->meta->registry_profile ?? self::DEFAULT_PROFILE);
        $escape = static function ($value) {
            return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        };

        if ($profile === 'GE') {
            $statusXml = $enabled
                ? '<domain:add><domain:status s="hiddenInWhoIs" lang="en"/></domain:add>'
                : '<domain:rem><domain:status s="hiddenInWhoIs" lang="en"/></domain:rem>';
            $xml = '<?xml version="1.0" encoding="UTF-8"?>'
                . '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">'
                . '<command><update><domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
                . '<domain:name>' . $escape($domain) . '</domain:name>' . $statusXml
                . '</domain:update></update><clTRID>' . $escape($this->transactionId('privacy'))
                . '</clTRID></command></epp>';
            $this->invoke($client, 'rawXml', ['xml' => $xml], $row);
            return;
        }

        $info = $this->invoke($client, 'domainInfo', ['domainname' => $domain], $row);
        $contactIds = array_unique(array_values($this->contactIdsFromDomainInfo($info)));
        // RFC 5733: flag=0 means withhold; flag=1 means disclose.
        $flag = $enabled ? '0' : '1';
        foreach ($contactIds as $contactId) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>'
                . '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><command><update>'
                . '<contact:update xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">'
                . '<contact:id>' . $escape($contactId) . '</contact:id><contact:chg>'
                . '<contact:disclose flag="' . $flag . '"><contact:name type="int"/>'
                . '<contact:addr type="int"/><contact:voice/><contact:fax/><contact:email/>'
                . '</contact:disclose></contact:chg></contact:update></update><clTRID>'
                . $escape($this->transactionId('privacy')) . '</clTRID></command></epp>';
            $this->invoke($client, 'rawXml', ['xml' => $xml], $row);
        }
    }

    public function createHost($hostname, $ipAddress, $module_row_id = null)
    {
        $row = $this->resolveRow($module_row_id);
        $this->assertHostObjectsSupported($row);
        $hostname = $this->normalizeDomain($hostname);
        $this->assertIp($ipAddress);
        return $this->withClient($row, function ($client) use ($hostname, $ipAddress, $row) {
            $response = $this->invoke($client, 'hostCheck', ['hostname' => $hostname], $row);
            if (!$this->hostAvailable($response)) {
                throw new \RuntimeException('The host object already exists or cannot be created.');
            }
            $this->invoke($client, 'hostCreate', [
                'hostname' => $hostname,
                'ipaddress' => $ipAddress
            ], $row);
            return true;
        });
    }

    public function updateHost($hostname, $currentIp, $newIp, $module_row_id = null)
    {
        $row = $this->resolveRow($module_row_id);
        $this->assertHostObjectsSupported($row);
        $hostname = $this->normalizeDomain($hostname);
        $this->assertIp($currentIp);
        $this->assertIp($newIp);
        return $this->withClient($row, function ($client) use ($hostname, $currentIp, $newIp, $row) {
            $this->invoke($client, 'hostUpdate', [
                'hostname' => $hostname,
                'currentipaddress' => $currentIp,
                'newipaddress' => $newIp
            ], $row);
            return true;
        });
    }

    public function deleteHost($hostname, $module_row_id = null)
    {
        $row = $this->resolveRow($module_row_id);
        $this->assertHostObjectsSupported($row);
        $hostname = $this->normalizeDomain($hostname);
        return $this->withClient($row, function ($client) use ($hostname, $row) {
            $this->invoke($client, 'hostDelete', ['hostname' => $hostname], $row);
            return true;
        });
    }

    public function getChildHosts($domain, $module_row_id = null)
    {
        $row = $this->resolveRow($module_row_id);
        $this->assertHostObjectsSupported($row);
        $domain = $this->normalizeDomain($domain);
        return $this->withClient($row, function ($client) use ($domain, $row) {
            $info = $this->invoke($client, 'domainInfo', ['domainname' => $domain], $row);
            $result = [];
            foreach (array_values(array_filter((array) ($info['host'] ?? []))) as $hostname) {
                $hostInfo = $this->invoke($client, 'hostInfo', ['hostname' => (string) $hostname], $row);
                $result[] = [
                    'hostname' => (string) ($hostInfo['name'] ?? $hostname),
                    'addresses' => array_values((array) ($hostInfo['addr'] ?? [])),
                    'statuses' => $this->normalizeStatuses($hostInfo['status'] ?? [])
                ];
            }
            return $result;
        });
    }

    public function getDsRecords($domain, $module_row_id = null)
    {
        $info = $this->fetchDomainInfo($domain, $module_row_id);
        $records = [];
        foreach ((array) $info['ds_data'] as $record) {
            if (!is_array($record)) {
                continue;
            }
            $records[] = [
                'key_tag' => (int) ($record['keyTag'] ?? 0),
                'algorithm' => (int) ($record['alg'] ?? 0),
                'digest_type' => (int) ($record['digestType'] ?? 0),
                'digest' => (string) ($record['digest'] ?? '')
            ];
        }
        return $records;
    }

    public function updateDsRecord($domain, array $record, $add, $module_row_id = null)
    {
        $this->validateDsRecord($record);
        $row = $this->resolveRow($module_row_id);
        $domain = $this->normalizeDomain($domain);
        return $this->withClient($row, function ($client) use ($domain, $record, $add, $row) {
            $this->invoke($client, 'domainUpdateDNSSEC', [
                'domainname' => $domain,
                'command' => $add ? 'add' : 'rem',
                'keyTag_1' => (int) $record['key_tag'],
                'alg_1' => (int) $record['algorithm'],
                'digestType_1' => (int) $record['digest_type'],
                'digest_1' => strtoupper((string) $record['digest'])
            ], $row);
            return true;
        });
    }

    public function setHold($domain, $enabled, $module_row_id = null)
    {
        $row = $this->resolveRow($module_row_id);
        $domain = $this->normalizeDomain($domain);
        return $this->withClient($row, function ($client) use ($domain, $enabled, $row) {
            $info = $this->invoke($client, 'domainInfo', ['domainname' => $domain], $row);
            $statuses = $this->normalizeStatuses($info['status'] ?? []);
            $hasClientHold = in_array('clientHold', $statuses, true);
            $hasServerHold = in_array('serverHold', $statuses, true);

            if ($enabled && !$hasClientHold && !$hasServerHold) {
                $this->invoke($client, 'domainUpdateStatus', [
                    'domainname' => $domain,
                    'command' => 'add',
                    'status' => 'clientHold'
                ], $row);
            } elseif (!$enabled && $hasClientHold) {
                $this->invoke($client, 'domainUpdateStatus', [
                    'domainname' => $domain,
                    'command' => 'rem',
                    'status' => 'clientHold'
                ], $row);
            }
            return true;
        });
    }

    public function performTransferAction($domain, $action, $module_row_id = null)
    {
        if (!in_array($action, ['approve', 'cancel', 'reject'], true)) {
            throw new \InvalidArgumentException('Invalid transfer action.');
        }
        $row = $this->resolveRow($module_row_id);
        $domain = $this->normalizeDomain($domain);
        return $this->withClient($row, function ($client) use ($domain, $action, $row) {
            $this->invoke($client, 'domainTransfer', [
                'domainname' => $domain,
                'op' => $action
            ], $row);
            return true;
        });
    }

    public function queryTransfer($domain, $module_row_id = null)
    {
        $row = $this->resolveRow($module_row_id);
        $domain = $this->normalizeDomain($domain);
        return $this->withClient($row, function ($client) use ($domain, $row) {
            $response = $this->invoke($client, 'domainTransfer', [
                'domainname' => $domain,
                'op' => 'query'
            ], $row);
            return [
                'status' => (string) ($response['trStatus'] ?? ''),
                'requested' => (string) ($response['reDate'] ?? ''),
                'action_date' => (string) ($response['acDate'] ?? ''),
                'expires' => (string) ($response['exDate'] ?? ''),
                'requesting_client' => (string) ($response['reID'] ?? ''),
                'acting_client' => (string) ($response['acID'] ?? '')
            ];
        });
    }

    private function performTransfer($domain, $row, array $vars)
    {
        $years = max(1, (int) ($vars['years'] ?? 1));
        $auth = trim((string) ($vars['auth'] ?? ''));
        if ($auth === '') {
            throw new \InvalidArgumentException(Language::_('Epp.!error.auth', true));
        }
        if (!$this->isValidTerm($this->getDomainTld($domain, $row), $years, true)) {
            throw new \InvalidArgumentException(Language::_('Epp.!error.term', true));
        }

        return $this->withClient($row, function ($client) use ($domain, $years, $auth, $row) {
            $payload = [
                'domainname' => $domain,
                'years' => $years,
                'authInfoPw' => $auth,
                'op' => 'request'
            ];

            if (($row->meta->registry_profile ?? self::DEFAULT_PROFILE) === 'FR') {
                $info = $this->invoke($client, 'domainInfo', ['domainname' => $domain], $row);
                foreach ((array) ($info['contact'] ?? []) as $contact) {
                    if (!is_array($contact)) {
                        continue;
                    }
                    $type = (string) ($contact['type'] ?? '');
                    if (in_array($type, ['admin', 'tech'], true)) {
                        $payload[$type] = (string) ($contact['id'] ?? '');
                    }
                }
            }

            $this->invoke($client, 'domainTransfer', $payload, $row);
            return true;
        });
    }

    public function getRegistrationDate($service, $format = 'Y-m-d H:i:s')
    {
        try {
            $info = $this->fetchDomainInfo(
                $this->getServiceDomain($service),
                $service->module_row_id ?? null
            );
            return $this->formatRegistryDate($info['created'], $format);
        } catch (\Throwable $e) {
            $this->setOperationError('Registration-date sync', $e);
            return false;
        }
    }

    public function getExpirationDate($service, $format = 'Y-m-d H:i:s')
    {
        try {
            $info = $this->fetchDomainInfo(
                $this->getServiceDomain($service),
                $service->module_row_id ?? null
            );
            return $this->formatRegistryDate($info['expires'], $format);
        } catch (\Throwable $e) {
            $this->setOperationError('Expiration-date sync', $e);
            return false;
        }
    }

    public function getServiceDomain($service)
    {
        if (isset($service->fields)) {
            foreach ($service->fields as $field) {
                if ($field->key === 'domain') {
                    return (string) $field->value;
                }
            }
        }
        return $this->getServiceName($service);
    }

    public function getTlds($module_row_id = null)
    {
        try {
            $row = $this->resolveRow($module_row_id);
            return $this->parseTlds($row->meta->tlds ?? '');
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function isValidTerm($tld, $term, $transfer = false)
    {
        $term = (int) $term;
        return $term >= 1 && $term <= ($transfer ? 1 : 10);
    }

    private function performRegistration($domain, $row, array $vars)
    {
        $years = max(1, (int) ($vars['years'] ?? 1));
        if (!$this->isValidTerm($this->getDomainTld($domain, $row), $years, false)) {
            throw new \InvalidArgumentException(Language::_('Epp.!error.term', true));
        }

        $nameservers = $this->nameserversFromVars($vars);
        if (count($nameservers) < 2) {
            throw new \InvalidArgumentException(Language::_('Epp.!error.nameservers', true));
        }

        $profile = (string) ($row->meta->registry_profile ?? self::DEFAULT_PROFILE);
        $minimumData = $this->truthy($row->meta->min_data_set ?? false);
        $contactData = (array) ($vars['_contact'] ?? []);
        if (!$minimumData) {
            $this->validateContact($contactData);
        }

        return $this->withClient($row, function ($client) use (
            $domain,
            $row,
            $vars,
            $years,
            $nameservers,
            $profile,
            $minimumData,
            $contactData
        ) {
            if (!$this->checkDomainOnClient($client, $domain, $row)) {
                throw new \RuntimeException(Language::_('Epp.!error.domain_unavailable', true));
            }
            $this->assertPremiumAllowed($client, $domain, $row, 'create', $years);

            $contactIds = [];
            $contactTypes = $minimumData ? [] : $this->contactTypesForProfile($profile);
            $registrarPrefix = strtoupper(trim((string) ($row->meta->registrarprefix ?? '')));

            foreach ($contactTypes as $role) {
                $id = strtoupper($this->randomContactId())
                    . ($registrarPrefix !== '' ? '-' . $registrarPrefix : '');

                if ($profile === 'PL' && trim((string) ($row->meta->pl_contact_prefix ?? '')) !== '') {
                    $id = trim((string) $row->meta->pl_contact_prefix) . $id;
                }
                $payload = $this->contactPayload($id, $contactData, $row, $role, true, $vars);
                $response = $this->invoke($client, 'contactCreate', $payload, $row);
                $contactIds[$role] = (string) ($response['id'] ?? $id);
            }

            if (!$this->usesHostAttributes($row)) {
                foreach ($nameservers as $nameserver) {
                    $this->ensureHostObject($client, $nameserver, $row);
                }
            }

            $authInfo = $this->randomPassword();
            $payload = [
                'domainname' => $domain,
                'period' => $years,
                'nss' => [],
                'authInfoPw' => $authInfo
            ];
            if ($this->usesHostAttributes($row)) {
                foreach ($nameservers as $nameserver) {
                    $payload['nss'][] = $this->hostAttribute($nameserver);
                }
            } else {
                $payload['nss'] = $nameservers;
            }

            if (!$minimumData) {
                $payload['registrant'] = $contactIds['registrant'] ?? null;
                $payload['contacts'] = [];
                foreach (['admin', 'tech', 'billing'] as $role) {
                    if (!empty($contactIds[$role])) {
                        $payload['contacts'][$role] = $contactIds[$role];
                    }
                }
                if ($profile === 'EU' && trim((string) ($row->meta->eurid_billing_contact ?? '')) !== '') {
                    $payload['contacts']['billing'] = trim((string) $row->meta->eurid_billing_contact);
                }
                foreach ($payload['contacts'] as $role => $contactId) {
                    // DNS.PT reads the technical contact directly while the
                    // generic profiles read the contacts map.
                    $payload[$role] = $contactId;
                }
                if (!$payload['contacts']) {
                    unset($payload['contacts']);
                }
            }

            if ($this->truthy($row->meta->tmch_claims_period_active ?? false)) {
                $noticeId = trim((string) ($vars['tmch_notice_id'] ?? ''));
                $notAfter = trim((string) ($vars['tmch_not_after'] ?? ''));
                if ($noticeId === '' || $notAfter === '') {
                    throw new \RuntimeException(Language::_('Epp.!error.tmch', true));
                }
                $payload['noticeID'] = $noticeId;
                $payload['notAfter'] = $this->normalizeUtcDate($notAfter);
                $payload['acceptedDate'] = gmdate('Y-m-d\TH:i:s.0\Z');
                $create = $this->invoke($client, 'domainCreateClaims', $payload, $row);
            } else {
                $create = $this->invoke($client, 'domainCreate', $payload, $row);
            }

            if ($profile === 'GE' && trim((string) ($contactData['company'] ?? '')) === '') {
                $this->setPrivacyOnClient($client, $domain, true, $row);
            } elseif ($this->idProtectionRequested($vars)) {
                $this->setPrivacyOnClient($client, $domain, true, $row);
            }

            return [
                'contact_ids' => $contactIds,
                'auth_info' => $authInfo,
                'registered_at' => (string) ($create['crDate'] ?? ''),
                'expires_at' => (string) ($create['exDate'] ?? '')
            ];
        });
    }

    private function contactTypesForProfile($profile)
    {
        $map = [
            'EU' => ['registrant', 'tech'],
            'SWITCH' => ['registrant', 'tech'],
            'PL' => ['registrant'],
            'GE' => ['registrant'],
            'VRSN' => ['registrant', 'admin', 'tech', 'billing'],
            'generic' => ['registrant', 'admin', 'tech', 'billing']
        ];
        return $map[$profile] ?? $map['generic'];
    }

    private function contactPayload($id, array $data, $row, $role, $create, array $serviceVars = [])
    {
        $profile = (string) ($row->meta->registry_profile ?? self::DEFAULT_PROFILE);
        $contactPostalType = ($row->meta->contact_postal_type ?? 'int') === 'loc'
            ? 'loc'
            : 'int';

        $phone = trim((string) ($data['phone'] ?? ''));
        if ($phone !== '' && substr($phone, 0, 1) !== '+') {
            $phone = '+' . $phone;
        }

        $state = trim((string) ($data['state'] ?? ''));
        if ($state !== '' && strlen($state) <= 2) {
            $state .= '-region';
        }

        $payload = [
            'id' => $id,
            'type' => $contactPostalType,
            'firstname' => (string) ($data['first_name'] ?? ''),
            'lastname' => (string) ($data['last_name'] ?? ''),
            'companyname' => (string) ($data['company'] ?? ''),
            'address1' => (string) ($data['address1'] ?? ''),
            'address2' => (string) ($data['address2'] ?? ''),
            'address3' => (string) ($data['address3'] ?? ''),
            'city' => (string) ($data['city'] ?? ''),
            'state' => $state,
            'postcode' => (string) ($data['zip'] ?? ''),
            'country' => strtoupper((string) ($data['country'] ?? '')),
            'fullphonenumber' => $phone,
            'email' => (string) ($data['email'] ?? '')
        ];
        if ($create) {
            $payload['authInfoPw'] = $this->randomPassword();
        }

        if ($create && $profile === 'EU') {
            $payload['euType'] = $role;
        } elseif ($create && $profile === 'SE') {
            $payload['orgno'] = (string) ($serviceVars['nin'] ?? '');
            $payload['vatno'] = (string) ($serviceVars['vat'] ?? '');
        } elseif ($create && $profile === 'LV') {
            $payload['regNr'] = (string) ($serviceVars['nin'] ?? '');
            $payload['vatNr'] = (string) ($serviceVars['vat'] ?? '');
        } elseif ($create && $profile === 'HR') {
            $payload['nin'] = (string) ($serviceVars['nin'] ?? '');
            $payload['nin_type'] = (string) ($serviceVars['nin_type'] ?? 'personal');
        } elseif ($profile === 'PT') {
            $validatedDate = (string) ($serviceVars['pt_validated_date'] ?? $data['pt_validated_date'] ?? '');
            if (trim($validatedDate) === '') {
                throw new \InvalidArgumentException(
                    Language::_('Epp.!error.required', true, 'PT contact validation time')
                );
            }
            if ($create) {
                $payload['vat'] = (string) ($serviceVars['vat'] ?? $data['vat'] ?? '');
            }
            $payload['validated'] = 'true';
            $payload['validatedDate'] = $this->normalizeUtcDate($validatedDate);
        } elseif ($create && $profile === 'GE') {
            $payload['nin'] = (string) ($serviceVars['nin'] ?? '');
        }

        return $payload;
    }

    private function validateContact(array $contact)
    {
        $required = [
            'first_name' => 'first name',
            'last_name' => 'last name',
            'address1' => 'address',
            'city' => 'city',
            'zip' => 'postal code',
            'country' => 'country',
            'phone' => 'phone',
            'email' => 'email'
        ];
        foreach ($required as $key => $label) {
            if (trim((string) ($contact[$key] ?? '')) === '') {
                throw new \InvalidArgumentException(Language::_('Epp.!error.contact', true, $label));
            }
        }
        if (!filter_var($contact['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(Language::_('Epp.!error.contact', true, 'email address'));
        }
        if (!preg_match('/^[A-Z]{2}$/', strtoupper((string) $contact['country']))) {
            throw new \InvalidArgumentException(Language::_('Epp.!error.contact', true, 'two-letter country code'));
        }
    }

    private function clientContact($clientId, array $fallback = [])
    {
        $contact = [
            'first_name' => (string) ($fallback['first_name'] ?? $fallback['firstname'] ?? ''),
            'last_name' => (string) ($fallback['last_name'] ?? $fallback['lastname'] ?? ''),
            'company' => (string) ($fallback['company'] ?? $fallback['companyname'] ?? ''),
            'address1' => (string) ($fallback['address1'] ?? ''),
            'address2' => (string) ($fallback['address2'] ?? ''),
            'address3' => (string) ($fallback['address3'] ?? ''),
            'city' => (string) ($fallback['city'] ?? ''),
            'state' => (string) ($fallback['state'] ?? ''),
            'zip' => (string) ($fallback['zip'] ?? $fallback['postcode'] ?? ''),
            'country' => (string) ($fallback['country'] ?? ''),
            'phone' => (string) ($fallback['phone'] ?? $fallback['fullphonenumber'] ?? ''),
            'email' => (string) ($fallback['email'] ?? '')
        ];

        if (!$clientId) {
            return $contact;
        }

        Loader::loadModels($this, ['Clients', 'Contacts']);
        $client = $this->Clients->get($clientId);
        if (!$client) {
            return $contact;
        }

        foreach (['first_name', 'last_name', 'company', 'address1', 'address2', 'city', 'state', 'zip', 'country', 'email'] as $key) {
            if (isset($client->{$key}) && trim((string) $client->{$key}) !== '') {
                $contact[$key] = (string) $client->{$key};
            }
        }
        $numbers = $this->Contacts->getNumbers($client->contact_id, 'phone');
        if (!empty($numbers[0]->number)) {
            $contact['phone'] = $this->formatPhone((string) $numbers[0]->number, (string) $client->country);
        }

        return $contact;
    }

    private function contactIdsFromDomainInfo(array $info)
    {
        $ids = [];
        if (trim((string) ($info['registrant'] ?? '')) !== '') {
            $ids['registrant'] = (string) $info['registrant'];
        }
        foreach ((array) ($info['contact'] ?? []) as $contact) {
            if (!is_array($contact)) {
                continue;
            }
            $type = (string) ($contact['type'] ?? '');
            $id = (string) ($contact['id'] ?? '');
            if ($type !== '' && $id !== '') {
                $ids[$type] = $id;
            }
        }
        return $ids;
    }

    private function checkDomainOnClient($client, $domain, $row)
    {
        $response = $this->invoke($client, 'domainCheck', ['domains' => [$domain]], $row);
        $map = $this->availabilityMap($response);
        if (!array_key_exists($domain, $map)) {
            throw new \RuntimeException('Domain check returned no result for ' . $domain . '.');
        }
        return (bool) $map[$domain];
    }

    private function availabilityMap(array $response)
    {
        $result = [];
        foreach ((array) ($response['domains'] ?? []) as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = strtolower((string) ($item['name'] ?? (is_string($key) ? $key : '')));
            if ($name === '') {
                continue;
            }
            $available = filter_var($item['avail'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($available === null) {
                $available = (int) ($item['avail'] ?? 0) === 1;
            }
            $result[$name] = $available;
        }
        return $result;
    }

    private function assertPremiumAllowed($client, $domain, $row, $command, $years)
    {
        if (!$this->truthy($row->meta->gtld ?? false)
            || !$this->truthy($row->meta->enable_fee_extension ?? false)) {
            return;
        }

        $response = $this->invoke($client, 'domainCheckFee', [
            'domainname' => $domain,
            'currency' => strtoupper((string) ($row->meta->fee_currency ?? 'USD')),
            'command' => $command,
            'years' => (int) $years
        ], $row);

        $feeClass = strtolower(trim((string) ($response['feeClass'] ?? '')));
        foreach ((array) ($response['domains'] ?? []) as $item) {
            if (is_array($item) && isset($item['feeClass'])) {
                $feeClass = strtolower(trim((string) $item['feeClass']));
                break;
            }
        }
        if ($feeClass === 'premium' && !$this->truthy($row->meta->allow_premium_domains ?? false)) {
            throw new \RuntimeException(Language::_('Epp.!error.premium', true, $domain));
        }
    }

    private function ensureHostObject($client, $hostname, $row)
    {
        $response = $this->invoke($client, 'hostCheck', ['hostname' => $hostname], $row);
        if ($this->hostAvailable($response)) {
            $this->invoke($client, 'hostCreate', ['hostname' => $hostname], $row);
        }
    }

    private function hostAvailable(array $response)
    {
        $hosts = (array) ($response['hosts'] ?? []);
        $item = reset($hosts);
        if (!is_array($item)) {
            return false;
        }
        $available = filter_var($item['avail'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        return $available ?? ((int) ($item['avail'] ?? 0) === 1);
    }

    private function hostAttribute($hostname)
    {
        $record = ['hostName' => $hostname];
        if (preg_match('/\.(?:eu|hr|ge|lv)$/i', $hostname)) {
            $ipv4 = @dns_get_record($hostname, DNS_A);
            if (!empty($ipv4[0]['ip'])) {
                $record['ipv4'] = $ipv4[0]['ip'];
            }
            $ipv6 = @dns_get_record($hostname, DNS_AAAA);
            if (!empty($ipv6[0]['ipv6'])) {
                $record['ipv6'] = $ipv6[0]['ipv6'];
            }
        }
        return $record;
    }

    private function usesHostAttributes($row)
    {
        if ((string) ($row->meta->ns_mode ?? 'hostObj') === 'hostAttr') {
            return true;
        }

        return in_array(
            (string) ($row->meta->registry_profile ?? self::DEFAULT_PROFILE),
            ['EU', 'HR', 'LV', 'GE'],
            true
        );
    }

    private function assertHostObjectsSupported($row)
    {
        if ($this->usesHostAttributes($row)) {
            throw new \RuntimeException(
                'Host object operations are unavailable when Nameserver Mode is hostAttr.'
            );
        }
    }

    private function validateDsRecord(array $record)
    {
        $keyTag = (int) ($record['key_tag'] ?? 0);
        $algorithm = (int) ($record['algorithm'] ?? 0);
        $digestType = (int) ($record['digest_type'] ?? 0);
        $digest = preg_replace('/\s+/', '', (string) ($record['digest'] ?? ''));
        if ($keyTag < 0 || $keyTag > 65535 || $algorithm < 1 || $digestType < 1
            || $digest === '' || !ctype_xdigit($digest)) {
            throw new \InvalidArgumentException('Enter a valid DNSSEC DS record.');
        }
    }

    private function validateProfileFields($profile, array $vars, $transfer)
    {
        if ($transfer) {
            return;
        }
        if (in_array($profile, ['SE', 'HR', 'LV', 'GE'], true)
            && trim((string) ($vars['nin'] ?? '')) === '') {
            throw new \InvalidArgumentException(Language::_('Epp.!error.required', true, 'National ID'));
        }
        if ($profile === 'HR' && !in_array(($vars['nin_type'] ?? ''), ['personal', 'company'], true)) {
            throw new \InvalidArgumentException('Select a valid identification type.');
        }
        if ($profile === 'PT' && trim((string) ($vars['pt_validated_date'] ?? '')) === '') {
            throw new \InvalidArgumentException(
                Language::_('Epp.!error.required', true, 'PT contact validation time')
            );
        }
        if ($profile === 'PT') {
            $this->normalizeUtcDate((string) $vars['pt_validated_date']);
        }
    }

    private function withClientMeta(array $meta, callable $callback)
    {
        $row = (object) ['meta' => (object) $meta];
        return $this->withClient($row, $callback);
    }

    private function withClient($row, callable $callback)
    {
        $client = $this->connectClient($row);
        try {
            return $callback($client);
        } finally {
            try {
                $client->logout();
            } catch (\Throwable $e) {
                // The registry may already have closed the session.
            }
        }
    }

    private function connectClient($row)
    {
        if (!class_exists('Pinga\\Tembo\\EppRegistryFactory')) {
            throw new \RuntimeException(
                Language::_('Epp.!error.library_missing', true, __DIR__ . DS . 'lib' . DS . 'epp')
            );
        }

        $meta = $row->meta;
        $profile = (string) ($meta->registry_profile ?? self::DEFAULT_PROFILE);
        $client = \Pinga\Tembo\EppRegistryFactory::create($profile);

        if ($this->truthy($meta->debug_log ?? false)) {
            if (!class_exists('Monolog\\Logger')
                || !class_exists('Monolog\\Handler\\RotatingFileHandler')
                || !class_exists('Monolog\\Formatter\\LineFormatter')) {
                throw new \RuntimeException('Raw EPP debug logging requires monolog/monolog.');
            }
            $path = trim((string) ($meta->debug_log_path ?? ''));
            $client->setLogPath($path !== '' ? $path : __DIR__ . DS . 'log');
        } else {
            $client->disableLogging();
        }

        $certificate = $this->resolveRequiredPath((string) ($meta->local_cert ?? ''), 'client certificate');
        $privateKey = $this->resolveRequiredPath((string) ($meta->local_pk ?? ''), 'client private key');
        $caFile = trim((string) ($meta->cafile ?? ''));
        if ($caFile !== '') {
            $caFile = $this->resolveRequiredPath($caFile, 'CA bundle');
        }

        if ($profile === self::DEFAULT_PROFILE) {
            $objects = $this->parseUriList((string) ($meta->login_objects ?? ''));
            $extensions = $this->parseUriList((string) ($meta->login_extensions ?? ''));
            if (!$objects) {
                $objects = $this->parseUriList((string) Configure::get('Epp.default_login_objects'));
            }
            if (!$extensions) {
                $extensions = $this->parseUriList((string) Configure::get('Epp.default_login_extensions'));
            }
            $client->setLoginObjects($objects);
            $client->setLoginExtensions($extensions);
        }

        $verify = $this->truthy($meta->verify_peer ?? false);
        $client->connect([
            'host' => trim((string) ($meta->host ?? '')),
            'port' => (int) ($meta->port ?? self::DEFAULT_PORT),
            'timeout' => 30,
            'tls' => $this->truthy($meta->tls_version ?? false) ? '1.3' : '1.2',
            'bind' => false,
            'bindip' => '0.0.0.0:0',
            'verify_peer' => $verify,
            'verify_peer_name' => false,
            'cafile' => $caFile,
            'local_cert' => $certificate,
            'local_pk' => $privateKey,
            'passphrase' => (string) ($meta->passphrase ?? ''),
            'allow_self_signed' => true
        ]);

        try {
            $login = $client->login([
                'clID' => (string) ($meta->clid ?? ''),
                'pw' => (string) ($meta->pw ?? ''),
                'prefix' => (string) ($meta->registrarprefix ?? 'epp')
            ]);
            $this->assertEppResponse((array) $login, 'login');
        } catch (\Throwable $e) {
            try {
                $client->disconnect();
            } catch (\Throwable $disconnectError) {
                // Preserve the original connection/login exception.
            }
            throw $e;
        }

        return $client;
    }

    private function invoke($client, $method, array $payload, $row)
    {
        $debug = $this->truthy($row->meta->debug_log ?? false);
        $endpoint = (string) ($row->meta->host ?? 'epp') . ':' . (string) ($row->meta->port ?? self::DEFAULT_PORT);
        if ($debug) {
            $this->log(
                $endpoint . '/' . $method,
                json_encode($this->loggablePayload($payload), JSON_UNESCAPED_SLASHES),
                'input',
                true
            );
        }

        $response = $client->{$method}($payload);
        if (!is_array($response)) {
            throw new \RuntimeException($method . ' returned an invalid response.');
        }
        $this->assertEppResponse($response, $method);

        if ($debug) {
            $this->log(
                $endpoint . '/' . $method,
                json_encode($this->loggableResponse($response), JSON_UNESCAPED_SLASHES),
                'output',
                true
            );
        }
        return $response;
    }

    private function assertEppResponse(array $response, $operation)
    {
        if (isset($response['error']) && trim((string) $response['error']) !== '') {
            throw new \RuntimeException((string) $response['error']);
        }
        if (isset($response['code']) && (int) $response['code'] >= 2000) {
            throw new \RuntimeException(
                trim((string) ($response['msg'] ?? '')) ?: $operation . ' failed with EPP code ' . $response['code']
            );
        }
    }

    private function loggablePayload(array $payload)
    {
        $allowed = [
            'domainname', 'domains', 'hostname', 'command', 'status', 'op',
            'years', 'period', 'regperiod', 'currency', 'keyTag_1', 'alg_1', 'digestType_1'
        ];
        return array_intersect_key($payload, array_flip($allowed));
    }

    private function loggableResponse(array $response)
    {
        $allowed = [
            'code', 'msg', 'error', 'name', 'crDate', 'exDate', 'trStatus',
            'feeClass', 'feeAmount', 'currency'
        ];
        return array_intersect_key($response, array_flip($allowed));
    }

    private function resolveRequiredPath($path, $label)
    {
        $path = trim((string) $path);
        if ($path === '') {
            throw new \RuntimeException(ucfirst($label) . ' path is empty.');
        }
        if (!$this->isAbsolutePath($path)) {
            $path = __DIR__ . DS . $path;
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            throw new \RuntimeException(ucfirst($label) . ' is missing or unreadable: ' . $path);
        }
        return $resolved;
    }

    private function isAbsolutePath($path)
    {
        return isset($path[0]) && ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path));
    }

    private function resolveRow($moduleRowId = null)
    {
        $row = $moduleRowId ? $this->getModuleRow($moduleRowId) : null;
        if (!$row) {
            $rows = $this->getModuleRows();
            $row = $rows[0] ?? null;
        }
        if (!$row) {
            throw new \RuntimeException('No EPP account is configured.');
        }
        return $row;
    }

    private function truthy($value)
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function normalizeDomain($domain)
    {
        $domain = strtolower(rtrim(trim((string) $domain), '.'));
        if ($domain === '') {
            throw new \InvalidArgumentException(Language::_('Epp.!error.domain', true));
        }

        if (function_exists('idn_to_ascii')) {
            $flags = defined('IDNA_DEFAULT') ? IDNA_DEFAULT : 0;
            $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 1;
            $ascii = @idn_to_ascii($domain, $flags, $variant);
            if ($ascii === false) {
                throw new \InvalidArgumentException(Language::_('Epp.!error.domain', true));
            }
            $domain = strtolower($ascii);
        } elseif (preg_match('/[^\x20-\x7E]/', $domain)) {
            throw new \InvalidArgumentException('The PHP intl extension is required for IDN domains.');
        }

        if (strlen($domain) > 253
            || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new \InvalidArgumentException(Language::_('Epp.!error.domain', true));
        }
        return $domain;
    }

    private function normalizeTld($tld)
    {
        $tld = strtolower(trim((string) $tld));
        if ($tld === '') {
            return '';
        }
        $tld = '.' . ltrim($tld, '.');
        try {
            $normalized = $this->normalizeDomain('example' . $tld);
            return substr($normalized, strlen('example'));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function parseTlds($value)
    {
        $values = is_array($value) ? $value : preg_split('/[,\s]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        $tlds = [];
        foreach ($values as $tld) {
            $normalized = $this->normalizeTld($tld);
            if ($normalized !== '') {
                $tlds[$normalized] = $normalized;
            }
        }
        return array_values($tlds);
    }

    private function parseUriList($value)
    {
        return array_values(array_unique(array_filter(array_map(
            'trim',
            preg_split('/[,\s]+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY)
        ))));
    }

    private function getDomainTld($domain, $row)
    {
        $best = '';
        foreach ($this->parseTlds($row->meta->tlds ?? '') as $tld) {
            if ($this->domainHasTld($domain, $tld) && strlen($tld) > strlen($best)) {
                $best = $tld;
            }
        }
        if ($best !== '') {
            return $best;
        }
        $parts = explode('.', $domain, 2);
        return isset($parts[1]) ? '.' . $parts[1] : '';
    }

    private function domainHasTld($domain, $tld)
    {
        $tld = $this->normalizeTld($tld);
        return $tld !== '' && strlen($domain) > strlen($tld)
            && substr($domain, -strlen($tld)) === $tld;
    }

    private function nameserversFromVars(array $vars)
    {
        $values = [];
        if (isset($vars['ns']) && is_array($vars['ns'])) {
            $values = array_values($vars['ns']);
        } else {
            $hasNamedNameservers = false;
            for ($i = 1; $i <= self::MAX_NAMESERVERS; $i++) {
                if (array_key_exists('ns' . $i, $vars)) {
                    $hasNamedNameservers = true;
                    $values[] = $vars['ns' . $i];
                }
            }
            if (!$hasNamedNameservers) {
                $values = array_values($vars);
            }
        }

        $nameservers = [];
        foreach ($values as $value) {
            if (trim((string) $value) === '') {
                continue;
            }
            $nameserver = $this->normalizeDomain($value);
            $nameservers[$nameserver] = $nameserver;
        }
        return array_values($nameservers);
    }

    private function termFromPackage($package, $pricingId, $default)
    {
        foreach ((array) ($package->pricing ?? []) as $pricing) {
            if ($pricingId !== null && (string) ($pricing->id ?? '') !== (string) $pricingId) {
                continue;
            }
            $period = strtolower((string) ($pricing->period ?? 'year'));
            if (in_array($period, ['year', 'years'], true)) {
                return max(1, (int) ($pricing->term ?? $default));
            }
        }
        return (int) $default;
    }

    private function isTransfer(array $vars)
    {
        return $this->truthy($vars['transfer'] ?? false) || trim((string) ($vars['auth'] ?? '')) !== '';
    }

    private function idProtectionRequested(array $vars)
    {
        if (isset($vars['private'])) {
            return $this->truthy($vars['private']);
        }
        $options = $vars['configoptions'] ?? [];
        if (is_object($options)) {
            $options = (array) $options;
        }
        return $this->truthy($options['id_protection'] ?? false);
    }

    private function featureServiceEnabled($feature, $service)
    {
        foreach ((array) ($service->options ?? []) as $option) {
            $name = is_object($option)
                ? (string) ($option->option_name ?? '')
                : (string) ($option['option_name'] ?? '');
            if ($name === (string) $feature) {
                return true;
            }
        }
        return false;
    }

    private function storeServiceField($serviceId, $key, $value, $encrypted = 0)
    {
        $field = [
            'service_id' => (int) $serviceId,
            'key' => (string) $key,
            'value' => (string) $value,
            'encrypted' => (int) $encrypted
        ];
        $this->Record->duplicate('value', '=', $field['value'])
            ->duplicate('encrypted', '=', $field['encrypted'])
            ->insert('service_fields', $field);
    }

    private function normalizeStatuses($statuses)
    {
        if (!is_array($statuses)) {
            $statuses = [$statuses];
        }
        $result = [];
        foreach ($statuses as $status) {
            $status = trim((string) $status);
            if ($status !== '') {
                $result[] = $status;
            }
        }
        return array_values(array_unique($result));
    }

    private function formatRegistryDate($value, $format)
    {
        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            return false;
        }
        return gmdate($format, $timestamp);
    }

    private function normalizeUtcDate($value)
    {
        try {
            $date = new \DateTimeImmutable(trim((string) $value));
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Enter a valid ISO 8601 date/time.');
        }
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private function randomContactId($length = 10)
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $result;
    }

    private function randomPassword($length = 16)
    {
        $sets = [
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'abcdefghijklmnopqrstuvwxyz',
            '0123456789',
            '!=+-'
        ];
        $characters = '';
        foreach ($sets as $set) {
            $characters .= $set[random_int(0, strlen($set) - 1)];
        }
        $all = implode('', $sets);
        while (strlen($characters) < $length) {
            $characters .= $all[random_int(0, strlen($all) - 1)];
        }

        $array = str_split($characters);
        for ($i = count($array) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$array[$i], $array[$j]] = [$array[$j], $array[$i]];
        }
        return implode('', $array);
    }

    private function splitName($name)
    {
        $parts = preg_split('/\s+/', trim((string) $name), 2);
        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    private function formatPhone($number, $country)
    {
        Loader::loadModels($this, ['Contacts']);
        $formatted = $this->Contacts->intlNumber((string) $number, (string) $country, '.');
        return trim((string) $formatted) !== '' ? (string) $formatted : (string) $number;
    }

    private function assertIp($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('Enter a valid IPv4 or IPv6 address.');
        }
    }

    private function isChildHost($hostname, $domain)
    {
        $hostname = strtolower((string) $hostname);
        $domain = strtolower($this->normalizeDomain($domain));
        return strlen($hostname) > strlen($domain) + 1
            && substr($hostname, -strlen('.' . $domain)) === '.' . $domain;
    }

    private function transactionId($action)
    {
        return 'blesta-' . preg_replace('/[^a-z0-9-]/i', '-', (string) $action)
            . '-' . str_replace('.', '', sprintf('%.6F', microtime(true)));
    }

    private function setOperationError($operation, \Throwable $error)
    {
        $this->Input->setErrors([
            'api' => [
                'response' => Language::_('Epp.!error.operation', true, $operation, $error->getMessage())
            ]
        ]);
    }
}