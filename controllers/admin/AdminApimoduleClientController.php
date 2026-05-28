<?php
declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminApimoduleClientController extends ModuleAdminController
{
    public function __construct()
    {
        $this->table       = 'apimodule_client';
        $this->className   = 'ApimoduleClient';
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

        if ($this->object && $this->object->id) {
            // Update — only rehash secret if a new one is provided
            $data = [
                'client_id'   => pSQL($clientId),
                'client_name' => pSQL($clientName),
                'scopes'      => pSQL((string) json_encode($selectedScopes)),
                'active'      => $active,
                'date_upd'    => date('Y-m-d H:i:s'),
            ];

            $raw = \Tools::getValue('client_secret');
            if ($raw !== '' && $raw !== false) {
                $data['client_secret'] = pSQL(password_hash((string) $raw, PASSWORD_BCRYPT));
            }

            // Issue 3: check return value to catch UNIQUE KEY violations on client_id
            if (!\Db::getInstance()->update('apimodule_client', $data, 'id = ' . (int) $this->object->id)) {
                $this->errors[] = 'Failed to update client. The Client ID may already be in use.';
                return;
            }
        } else {
            // Create — auto-generate secret
            $rawSecret = bin2hex(random_bytes(32));

            \Db::getInstance()->insert('apimodule_client', [
                'client_id'     => pSQL($clientId),
                'client_secret' => pSQL(password_hash($rawSecret, PASSWORD_BCRYPT)),
                'client_name'   => pSQL($clientName),
                'scopes'        => pSQL((string) json_encode($selectedScopes)),
                'active'        => $active,
                'date_add'      => date('Y-m-d H:i:s'),
                'date_upd'      => date('Y-m-d H:i:s'),
            ]);

            $this->confirmations[] = sprintf(
                'Client created. Client ID: <strong>%s</strong><br>'
                . 'Secret (shown once only): <code>%s</code>',
                htmlspecialchars($clientId),
                htmlspecialchars($rawSecret)
            );
        }

        $this->redirect_after = self::$currentIndex . '&token=' . $this->token;
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
