<?php
declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminAdminapiClientController extends ModuleAdminController
{
    public function __construct()
    {
        $this->table       = 'adminapi_client';
        $this->className   = 'AdminapiClient';
        $this->identifier  = 'id';
        $this->lang        = false;
        $this->addRowAction('edit');
        $this->addRowAction('delete');
        $this->bootstrap   = true;

        parent::__construct();

        $this->fields_list = [
            'id'          => ['title' => 'ID',       'align' => 'center', 'class' => 'fixed-width-xs'],
            'client_id'   => ['title' => 'Client ID'],
            'client_name' => ['title' => 'Name'],
            'active'      => ['title' => 'Active', 'active' => 'status', 'type' => 'bool', 'align' => 'center'],
            'date_add'    => ['title' => 'Created', 'type' => 'datetime'],
        ];
    }

    /**
     * Surface the one-time client secret stashed by processSave() across the
     * Post/Redirect/Get redirect, then clear it so it is only ever shown once.
     */
    public function init()
    {
        parent::init();

        $flash = $this->context->cookie->adminapi_new_secret;
        if ($flash) {
            unset($this->context->cookie->adminapi_new_secret);
            $this->context->cookie->write();

            $data = json_decode((string) $flash, true);
            if (is_array($data) && isset($data['client_id'], $data['secret'])) {
                $headline = (($data['action'] ?? '') === 'regenerated')
                    ? 'New secret generated for this API client.'
                    : 'API client created.';
                $this->confirmations[] = sprintf(
                    '%s Client ID: <strong>%s</strong><br>'
                    . 'Secret (shown once only — copy it now): <code>%s</code>',
                    $headline,
                    htmlspecialchars((string) $data['client_id']),
                    htmlspecialchars((string) $data['secret'])
                );
            }
        }
    }

    /**
     * Intercept the "regenerate secret" action before PrestaShop's normal
     * postProcess flow so we can issue our own PRG redirect.
     */
    public function postProcess()
    {
        if (\Tools::isSubmit('regenerateSecret')) {
            $this->processRegenerateSecret();

            return; // redirect_after is set; skip the default add/update flow
        }

        return parent::postProcess();
    }

    /**
     * Generate a fresh secret for an existing client, store only its bcrypt
     * hash, and stash the plaintext in the flash cookie so init() can show it
     * once on the next request.
     */
    public function processRegenerateSecret(): void
    {
        $id = (int) \Tools::getValue('id');
        if ($id <= 0) {
            $this->errors[] = 'Invalid client.';

            return;
        }

        $client = new \AdminapiClient($id);
        if (!\Validate::isLoadedObject($client)) {
            $this->errors[] = 'Client not found.';

            return;
        }

        $rawSecret = bin2hex(random_bytes(32));
        $client->client_secret = password_hash($rawSecret, PASSWORD_BCRYPT);

        if (!$client->update()) {
            $this->errors[] = 'Failed to regenerate the secret.';

            return;
        }

        $this->stashSecretFlash($client->client_id, $rawSecret, 'regenerated');

        $this->redirect_after = self::$currentIndex . '&conf=4&token=' . $this->token;
    }

    /**
     * Stash a freshly generated plaintext secret in the employee cookie so
     * init() can surface it once on the next request. The secret cannot be
     * passed through $this->confirmations because PrestaShop issues the PRG
     * redirect before the template that would render it.
     */
    private function stashSecretFlash(string $clientId, string $rawSecret, string $action): void
    {
        $this->context->cookie->adminapi_new_secret = json_encode([
            'client_id' => $clientId,
            'secret'    => $rawSecret,
            'action'    => $action,
        ]);
        $this->context->cookie->write();
    }

    /**
     * Add a "Regenerate secret" button to the toolbar when editing a client.
     */
    public function initPageHeaderToolbar()
    {
        parent::initPageHeaderToolbar();

        $id = (int) \Tools::getValue('id');
        if ($this->display === 'edit' && $id > 0) {
            $this->page_header_toolbar_btn['regenerate_secret'] = [
                'href' => self::$currentIndex . '&id=' . $id . '&regenerateSecret&token=' . $this->token,
                'desc' => 'Regenerate secret',
                'icon' => 'process-icon-refresh',
                'js'   => "return confirm('Generate a new secret? The current secret will stop working immediately.');",
            ];
        }
    }

    public function renderForm(): string
    {
        $allScopes    = $this->getAllScopes();
        $clientScopes = [];

        if ($this->object && $this->object->id) {
            $clientScopes = json_decode((string) $this->object->scopes, true) ?? [];
        }

        $this->fields_form = [
            'legend' => ['title' => 'API Client', 'icon' => 'icon-key'],
            'input'  => [
                [
                    'type'     => 'text',
                    'label'    => 'Client Name',
                    'name'     => 'client_name',
                    'required' => true,
                ],
                [
                    'type'  => 'text',
                    'label' => 'Client ID',
                    'name'  => 'client_id',
                    'hint'  => 'Leave empty to auto-generate',
                ],
                [
                    'type'         => 'html',
                    'name'         => 'scopes_html',
                    'html_content' => $this->renderScopesCheckboxes($allScopes, $clientScopes),
                    'label'        => 'Scopes',
                ],
                [
                    'type'   => 'switch',
                    'label'  => 'Active',
                    'name'   => 'active',
                    'values' => [
                        ['id' => 'active_on',  'value' => 1, 'label' => 'Yes'],
                        ['id' => 'active_off', 'value' => 0, 'label' => 'No'],
                    ],
                ],
            ],
            'submit' => ['title' => 'Save'],
        ];

        return parent::renderForm();
    }

    public function processSave(): void
    {
        $clientId   = \Tools::getValue('client_id') ?: bin2hex(random_bytes(16));
        $clientName = \Tools::getValue('client_name');
        $active     = (int) \Tools::getValue('active');

        // Issue 2: validate that client_name is non-empty
        if (empty($clientName)) {
            $this->errors[] = 'Client Name is required.';
            return;
        }

        // Collect selected scopes (Issue 1: assign once, iterate once)
        $selectedScopes = [];
        $allScopesMap   = $this->getAllScopes();
        foreach ($allScopesMap as $domain => $scopes) {
            foreach ($scopes as $scope) {
                if (\Tools::getValue('scope_' . md5($scope))) {
                    $selectedScopes[] = $scope;
                }
            }
        }

        $isUpdate = ($this->object && $this->object->id);

        /** @var \AdminapiClient $client */
        $client = $isUpdate ? new \AdminapiClient((int) $this->object->id) : new \AdminapiClient();

        $client->client_id   = $clientId;
        $client->client_name = $clientName;
        $client->scopes      = (string) json_encode($selectedScopes);
        $client->active      = $active;

        // On create, auto-generate a secret shown once. On update, keep the
        // stored hash (loaded with the object) unless a new plaintext secret
        // was explicitly provided.
        $rawSecret = null;
        if ($isUpdate) {
            $raw = \Tools::getValue('client_secret');
            if ($raw !== '' && $raw !== false) {
                $client->client_secret = password_hash((string) $raw, PASSWORD_BCRYPT);
            }
        } else {
            $rawSecret = bin2hex(random_bytes(32));
            $client->client_secret = password_hash($rawSecret, PASSWORD_BCRYPT);
        }

        // Validate size/format against the model definition without letting
        // add()/update() die() inside getFields() on invalid input.
        $validation = $client->validateFields(false, true);
        if ($validation !== true) {
            $this->errors[] = $validation;
            return;
        }

        // ObjectModel sets date_add/date_upd automatically; a false return
        // catches the UNIQUE KEY violation on a duplicate client_id.
        if (!($isUpdate ? $client->update() : $client->add())) {
            $this->errors[] = $isUpdate
                ? 'Failed to update client. The Client ID may already be in use.'
                : 'Failed to create client. The Client ID may already be in use.';
            return;
        }

        if ($rawSecret !== null) {
            $this->stashSecretFlash($clientId, $rawSecret, 'created');
        }

        $this->redirect_after = self::$currentIndex . '&conf=' . ($isUpdate ? 4 : 3) . '&token=' . $this->token;
    }

    private function renderScopesCheckboxes(array $allScopes, array $selectedScopes): string
    {
        $html = '<div class="row">';
        foreach ($allScopes as $domain => $scopes) {
            $html .= '<div class="col-md-3"><strong>' . htmlspecialchars($domain) . '</strong>'
                   . '<ul class="list-unstyled">';
            foreach ($scopes as $scope) {
                $checkboxId = 'scope_' . md5($scope);
                $checked    = in_array($scope, $selectedScopes, true) ? ' checked' : '';
                $html      .= sprintf(
                    '<li><label><input type="checkbox" name="%s" value="1"%s> %s</label></li>',
                    htmlspecialchars($checkboxId),
                    $checked,
                    htmlspecialchars($scope)
                );
            }
            $html .= '</ul></div>';
        }
        return $html . '</div>';
    }

    /**
     * All 29 scope domains from ps_apiresources.
     *
     * @return array<string, string[]>
     */
    private function getAllScopes(): array
    {
        return [
            'Address'        => ['address_read',        'address_write'],
            'ApiClient'      => ['api_client_read',      'api_client_write'],
            'Attribute'      => ['attribute_read',       'attribute_write'],
            'AttributeGroup' => ['attribute_group_read', 'attribute_group_write'],
            'CartRule'       => ['cart_rule_read',       'cart_rule_write'],
            'Category'       => ['category_read',        'category_write'],
            'Contact'        => ['contact_read',         'contact_write'],
            'Country'        => ['country_read',         'country_write'],
            'Customer'       => ['customer_read',        'customer_write'],
            'CustomerGroup'  => ['customer_group_read',  'customer_group_write'],
            'Discount'       => ['discount_read',        'discount_write'],
            'Feature'        => ['feature_read',         'feature_write'],
            'FeatureValue'   => ['feature_value_read',   'feature_value_write'],
            'Hook'           => ['hook_read',            'hook_write'],
            'Manufacturer'   => ['manufacturer_read',    'manufacturer_write'],
            'Module'         => ['module_read',          'module_write'],
            'Product'        => ['product_read',         'product_write'],
            'Profile'        => ['profile_read',         'profile_write'],
            'SearchAlias'    => ['search_alias_read',    'search_alias_write'],
            'SearchEngine'   => ['search_engine_read',   'search_engine_write'],
            'ShowcaseCard'   => ['showcase_card_read',   'showcase_card_write'],
            'Store'          => ['store_read',           'store_write'],
            'Supplier'       => ['supplier_read',        'supplier_write'],
            'Tab'            => ['tab_read',             'tab_write'],
            'Tax'            => ['tax_read',             'tax_write'],
            'TaxRulesGroup'  => ['tax_rules_group_read', 'tax_rules_group_write'],
            'Title'          => ['title_read',           'title_write'],
            'WebserviceKey'  => ['webservice_key_read',  'webservice_key_write'],
            'Zone'           => ['zone_read',            'zone_write'],
        ];
    }
}
