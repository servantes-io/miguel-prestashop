<?php
/**
* 2023 Servantes.
*
* This file is licenced under the Software License Agreement.
* With the purchase or the installation of the software in your application
* you accept the licence agreement.
*
* You must not modify, adapt or create derivative works of this source code
*
*  @author Pavel Vejnar <vejnar.p@gmail.com>
*  @copyright  2022 - 2023 Servantes
*  @license LICENSE.txt
*/
define('_LOGGER_', 1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class Miguel extends Module
{
    public const HOOKS = [
        'header',
        'backOfficeHeader',
        'actionOrderStatusUpdate', // volá se při updatu objednávky
        'displayCustomerAccount', // volá se při najetí do účtu
    ];

    private $logger;

    public function __construct()
    {
        $this->name = 'miguel';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Servantes';
        $this->need_instance = 1;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Miguel');
        $this->description = $this->l('Allows you to sell books through the Miguel service.');
        $this->confirmUninstall = $this->l('Are you sure you want to remove the add-on?');
        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_];

        if (defined('_LOGGER_')) {
            $this->logger = new FileLogger(0); // 0 == debug level, logDebug() won’t work without this.
            $this->logger->setFilename(_PS_ROOT_DIR_ . '/modules/miguel/debug.log');
        }
    }

    public function install()
    {
        Configuration::updateValue('API_TOKEN_PRODUCTION', '');
        Configuration::updateValue('API_TOKEN_STAGING', '');
        Configuration::updateValue('API_TOKEN_TEST', '');
        Configuration::updateValue('API_TOKEN_OWN', '');
        Configuration::updateValue('API_SERVER', 'API_TOKEN_PRODUCTION'); // výchozí volba serveru po instalaci modulu
        Configuration::updateValue('API_SERVER_OWN', '');
        Configuration::updateValue('NEW_STATE_AUTO_CHANGE_MIGUEL_ONLY', 0);
        Configuration::updateValue('NEW_STATE_AUTO_CHANGE_MIGUEL_OTHERS', 0);
        Configuration::updateValue('API_ENABLE', false);
        Configuration::updateValue('API_ENABLE', false);

        include dirname(__FILE__) . '/sql/install.php';

        return parent::install()
            && $this->registerHook(static::HOOKS);
    }

    public function uninstall()
    {
        Configuration::deleteByName('API_TOKEN_PRODUCTION');
        Configuration::deleteByName('API_TOKEN_STAGING');
        Configuration::deleteByName('API_TOKEN_TEST');
        Configuration::deleteByName('API_TOKEN_OWN');
        Configuration::deleteByName('API_SERVER');
        Configuration::deleteByName('API_SERVER_OWN');
        Configuration::deleteByName('NEW_STATE_AUTO_CHANGE_MIGUEL_ONLY');
        Configuration::deleteByName('NEW_STATE_AUTO_CHANGE_MIGUEL_OTHERS');
        Configuration::deleteByName('API_ENABLE');

        include dirname(__FILE__) . '/sql/uninstall.php';

        return parent::uninstall();
    }

    public function isUsingNewTranslationSystem()
    {
        return false;
    }

    /**
     * Load the configuration form.
     */
    public function getContent()
    {
        /*
         * If values have been submitted in the form, process.
         */
        if (((bool) Tools::isSubmit('submitMiguelModule')) == true) {
            $this->postProcess();
        }

        // kontrola api
        $alert_state = 'info_setup_module';
        $api_configuration = $this->getCurrentApiConfiguration();
        if (false == $api_configuration) { // není vložena url nebo token, tak nemohu aktivovat
            if ($this->getApiEnable()) {
                Configuration::updateValue('API_ENABLE', false);
                $alert_state = 'info_setup_module_first';
            } else {
                $alert_state = 'info_setup_module';
            }
        } elseif ($api_configuration['api_enable']) { // je povoleno api, validuji token
            $test_key = $this->curlPost('/v1/prestashop/connect', $this->getPrestashopDetails());
            if (false == $test_key) {
                $alert_state = 'warning_api_fail';
                Configuration::updateValue('API_ENABLE', false); // pokud se nepodaří přihlásit, tak deaktivuji API
            } else {
                $alert_state = 'success_api_ok';
            }
        } else {
            $alert_state = 'info_setup_module_activate';
        }

        $this->context->smarty->assign('alert_state', $alert_state);

        $this->context->smarty->assign('module_dir', $this->_path);

        $output = $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');

        return $output . $this->renderForm();
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $this->context->controller->addJS($this->_path . 'views/js/back.js');
        $this->context->controller->addCSS($this->_path . 'views/css/back.css');

        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMiguelModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$this->getConfigForm()]);
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm()
    {
        return [
            'form' => [
                'legend' => [
                'title' => $this->l('Settings'),
                'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->l('Miguel server environment'),
                        'name' => 'API_SERVER',
                        'desc' => $this->l('For normal operation, choose the production.'),
                        'default_value' => 'API_TOKEN_PRODUCTION',
                        'options' => [
                            'query' => [
                                ['id' => 'API_TOKEN_PRODUCTION', 'name' => $this->l('Production')],
                                ['id' => 'API_TOKEN_STAGING', 'name' => $this->l('Staging')],
                                ['id' => 'API_TOKEN_TEST', 'name' => $this->l('Test')],
                                ['id' => 'API_TOKEN_OWN', 'name' => $this->l('Custom')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Miguel server\'s custom address'),
                        'name' => 'API_SERVER_OWN',
                        'desc' => $this->l('The address will be used if you have chosen a custom production environment.'),
                        'class' => 'input_server', // ((Configuration::get('API_SERVER', true) != 'own')?('input_server_own'):('')),
                        'default_value' => '',
                        'hint' => $this->l('Contact us to get the address.'),
                        'visible' => false,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('API key for server - Production'),
                        'name' => 'API_TOKEN_PRODUCTION',
                        'hint' => $this->l('To obtain an API key, use the link from the Documentation.'),
                        'desc' => $this->l('Using the API key, your e-shop will securely communicate with our server.'),
                        'class' => 'input_server', // ((Configuration::get('API_SERVER', true) != 'production')?('input_server_production'):('')),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('API key for server - Staging'),
                        'name' => 'API_TOKEN_STAGING',
                        'hint' => $this->l('To obtain an API key, use the link from the Documentation.'),
                        'desc' => $this->l('Using the API key, your e-shop will securely communicate with our server.'),
                        'class' => 'input_server', // ((Configuration::get('API_SERVER', true) != 'staging')?('input_server_staging'):('')),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('API key for server - Test'),
                        'name' => 'API_TOKEN_TEST',
                        'hint' => $this->l('To obtain an API key, use the link from the Documentation.'),
                        'desc' => $this->l('Using the API key, your e-shop will securely communicate with our server.'),
                        'class' => 'input_server', // ((Configuration::get('API_SERVER', true) != 'test')?('input_server_test'):('')),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('API key for server - Custom'),
                        'name' => 'API_TOKEN_OWN',
                        'hint' => $this->l('To obtain an API key, use the link from the Documentation.'),
                        'desc' => $this->l('Using the API key, your e-shop will securely communicate with our server.'),
                        'class' => 'input_server', // ((Configuration::get('API_SERVER', true) != 'own')?('input_server_own'):('')),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Automatic order status change') . '<br>' . $this->l('The user has only purchased books from Miguel'),
                        'name' => 'NEW_STATE_AUTO_CHANGE_MIGUEL_ONLY',
                        'desc' => $this->l('Set if you want to automatically change the status after the order is completed.'),
                        'default_value' => 'NEW_STATE_AUTO_CHANGE_MIGUEL_ONLY',
                        'options' => [
                            'query' => $this->getOrderStatesQuerySelector(),
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Automatic order status change') . '<br>' . $this->l('User has purchased books from Miguel and other products'),
                        'name' => 'NEW_STATE_AUTO_CHANGE_MIGUEL_OTHERS',
                        'desc' => $this->l('Set if you want to automatically change the status after the order is completed.'),
                        'default_value' => 'NEW_STATE_AUTO_CHANGE_MIGUEL_OTHERS',
                        'options' => [
                            'query' => $this->getOrderStatesQuerySelector(),
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Enable Miguel add-on'),
                        'name' => 'API_ENABLE',
                        'desc' => '',
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                            ],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ];
    }

    public function getOrderStatesQuerySelector()
    {
        $order_state = new OrderState(1); // jen nutné vložit nějaké id
        $order_states = $order_state->getOrderStates($this->context->language->id); // id je id jazyka, 1 je pravděpodobně vždy angličtina?

        $query = [];

        $query[] = [
            'id' => 0,
            'name' => $this->l('Do not change status'),
        ];

        foreach ($order_states as $key => $state) {
            $query[] = [
                'id' => $state['id_order_state'],
                'name' => $state['name'],
            ];
        }

        return $query;
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        return [
            'API_TOKEN_PRODUCTION' => Configuration::get('API_TOKEN_PRODUCTION', true),
            'API_TOKEN_STAGING' => Configuration::get('API_TOKEN_STAGING', true),
            'API_TOKEN_TEST' => Configuration::get('API_TOKEN_TEST', true),
            'API_TOKEN_OWN' => Configuration::get('API_TOKEN_OWN', true),
            'API_SERVER' => Configuration::get('API_SERVER', true),
            'API_SERVER_OWN' => Configuration::get('API_SERVER_OWN', true),
            'NEW_STATE_AUTO_CHANGE_MIGUEL_ONLY' => Configuration::get('NEW_STATE_AUTO_CHANGE_MIGUEL_ONLY', true),
            'NEW_STATE_AUTO_CHANGE_MIGUEL_OTHERS' => Configuration::get('NEW_STATE_AUTO_CHANGE_MIGUEL_OTHERS', true),
            'API_ENABLE' => Configuration::get('API_ENABLE', true),
        ];
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    /**
     * Add the CSS & JavaScript files you want to be loaded in the BO.
     */
    public function hookBackOfficeHeader()
    {
        if (Tools::getValue('module_name') == $this->name) {
            // $this->context->controller->addJS($this->_path.'views/js/back.js');
            // $this->context->controller->addCSS($this->_path.'views/css/back.css');
        }
    }

    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookHeader()
    {
        $this->context->controller->addJS($this->_path . '/views/js/front.js');
        $this->context->controller->addCSS($this->_path . '/views/css/front.css');
    }

    public function createOrderDetailArray($params)
    {
        if (false == isset($params['id_order'])) {
            if (defined('_LOGGER_')) {
                $this->logger->logDebug('hookActionOrderStatusUpdate: Params not set');
            }

            return;
        }
        $order = new Order((int) $params['id_order']);
        if (false == Validate::isLoadedObject($order)) {
            if (defined('_LOGGER_')) {
                $this->logger->logDebug('hookActionOrderStatusUpdate: Cannot create new Order: ' . $params['id_order']);
            }

            return;
        }
        $customer = new Customer($order->id_customer);
        if (false == Validate::isLoadedObject($customer)) {
            if (defined('_LOGGER_')) {
                $this->logger->logDebug('hookActionOrderStatusUpdate: Cannot create new Customer: ' . $order->id_customer . ', id_order: ' . $params['id_order']);
            }

            return;
        }
        $currency = new Currency($order->id_currency);
        if (false == Validate::isLoadedObject($currency)) {
            if (defined('_LOGGER_')) {
                $this->logger->logDebug('hookActionOrderStatusUpdate: Cannot create new Currency: ' . $order->id_currency . ', id_order: ' . $params['id_order']);
            }

            return;
        }
        $address_invoice = new Address($order->id_address_invoice);
        if (false == Validate::isLoadedObject($address_invoice)) {
            if (defined('_LOGGER_')) {
                $this->logger->logDebug('hookActionOrderStatusUpdate: Cannot create new Address: ' . $order->id_address_invoice . ', id_order: ' . $params['id_order']);
            }

            return;
        }
        $language = new Language($customer->id_lang);
        if (false == Validate::isLoadedObject($language)) {
            if (defined('_LOGGER_')) {
                $this->logger->logDebug('hookActionOrderStatusUpdate: Cannot create new Language: ' . $customer->id_lang . ', id_order: ' . $params['id_order']);
            }

            return;
        }
        $order_detail = OrderDetail::getList($params['id_order']);

        $address_str = '';
        $address_str .= ((strlen($address_invoice->company) > 0) ? ($address_invoice->company . ', ') : (''));
        $address_str .= ((strlen($address_invoice->firstname) > 0 && strlen($address_invoice->lastname) > 0) ? ($address_invoice->firstname . ' ' . $address_invoice->lastname . ', ') : (''));
        $address_str .= ((strlen($address_invoice->address1) > 0) ? ($address_invoice->address1 . ', ') : (''));
        $address_str .= ((strlen($address_invoice->address2) > 0) ? ($address_invoice->address2 . ', ') : (''));
        $address_str .= ((strlen($address_invoice->postcode) > 0) ? ($address_invoice->postcode . ', ') : (''));
        $address_str .= ((strlen($address_invoice->city) > 0) ? ($address_invoice->city . ', ') : (''));
        $address_str .= ((strlen($address_invoice->country) > 0) ? ($address_invoice->country . ', ') : (''));
        $address_str = substr($address_str, 0, -2);

        $body_orders = [];
        $body_orders['code'] = $order->reference;
        $body_orders['user'] = [
            'id' => $order->id_customer,
            'full_name' => $customer->firstname . ' ' . $customer->lastname,
            'email' => $customer->email,
            'address' => $address_str,
            'lang' => $language->iso_code,
        ];
        $body_orders['purchase_date'] = date(DATE_ISO8601, strtotime($order->date_add));
        if (isset($params['getUpdatedOrders'])) {
            $body_orders['update_date'] = date(DATE_ISO8601, strtotime($order->date_upd)); // aktuální datum tam je až po provedení funkce
            $body_orders['paid'] = (($order->total_paid_real == $order->total_paid) ? (true) : (false));
        } else {
            $body_orders['paid'] = (($params['newOrderStatus']->paid) ? (true) : (false));
        }
        $body_orders['currency_code'] = $currency->iso_code;
        $body_orders['products'] = [];

        foreach ($order_detail as $key => $product) {
            $body_orders['products'][] = [
                'code' => $product['product_reference'],
                'price' => [
                    'regular_without_vat' => $product['original_product_price'],
                    'sold_without_vat' => $product['unit_price_tax_excl'],
                ],
            ];
        }

        return $body_orders;
    }

    public function hookActionOrderStatusUpdate($params)
    {
        if (false == Configuration::get('API_ENABLE', true)) {
            return;
        } // ověření, že je api povoleno

        $body_orders = $this->createOrderDetailArray($params);
        $res = $this->curlPost('/v1/orders', $body_orders);
    }

    /**
     * @param String $uri
     */
    public function curlGet($uri)
    {
        $configuration = $this->getCurrentApiConfiguration();
        if (false == $configuration) {
            if (defined('_LOGGER_')) {
                $this->logger->logDebug('configuration not set');
            }

            return false;
        }
        if (false == $configuration['api_enable']) {
            if (defined('_LOGGER_')) {
                $this->logger->logDebug('configuration api_enable not enable');
            }

            return false;
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $configuration['url'] . $uri,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CUSTOMREQUEST => 'GET',
        ]);

        $headers = [];
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Authorization: Bearer ' . $configuration['token'];
        $headers[] = 'Accept-Language: ' . $this->getLanguageCode();
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // set return type json

        $response = curl_exec($curl);

        $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (200 == $http_status) {
            return $response;
        }

        return false;
    }

    /**
     * @param String $uri
     * @param Array<string, string> $params
     */
    public function curlPost($uri, array $params)
    {
        $configuration = $this->getCurrentApiConfiguration();
        if (false == $configuration) {
            if (defined('_LOGGER_')) {
                $this->logger->logDebug('configuration not set');
            }

            return false;
        }
        if (false == $configuration['api_enable']) {
            if (defined('_LOGGER_')) {
                $this->logger->logDebug('configuration api_enable not enable');
            }

            return false;
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $configuration['url'] . $uri,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => json_encode($params),
        ]);

        $headers = [];
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Authorization: Bearer ' . $configuration['token'];
        $headers[] = 'Accept-Language: ' . $this->getLanguageCode();
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // set return type json

        $response = curl_exec($curl);

        $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        switch ($http_status) {
            case 200:
                return (strlen($response) < 1) ? (true) : ($response);
            case 401: // nevalidní API klíč
            default:
                return false;
        }

        return false;
    }

    public function getCurrentApiConfiguration()
    {
        $api_server = Configuration::get('API_SERVER', true);
        $url = '';
        $token = '';
        switch ($api_server) {
            case 'API_TOKEN_PRODUCTION':
                $url = 'https://miguel.servantes.cz';
                $token = Configuration::get('API_TOKEN_PRODUCTION', true);
                break;
            case 'API_TOKEN_STAGING':
                $url = 'https://miguel-staging.servantes.cz';
                $token = Configuration::get('API_TOKEN_STAGING', true);
                break;
            case 'API_TOKEN_TEST':
                $url = 'https://miguel-test.servantes.cz';
                $token = Configuration::get('API_TOKEN_TEST', true);
                break;
            case 'API_TOKEN_OWN':
                $url = Configuration::get('API_SERVER_OWN', true);
                $token = Configuration::get('API_TOKEN_OWN', true);
                break;
            default:
                break;
        }

        $configuration = [
            'url' => $url,
            'token' => $token,
            'api_enable' => Configuration::get('API_ENABLE', true),
        ];

        if ('' == $url || '' == $token) {
            return false;
        }

        return $configuration;
    }

    public function getPrestashopDetails()
    {
        $ps = [];
        $ps['ps_version'] = _PS_VERSION_;
        $ps['module_version'] = $this->version;
        $ps['base_url'] = _PS_BASE_URL_SSL_;
        $ps['base_uri'] = __PS_BASE_URI__;

        return $ps;
    }

    private static function getApiEnable()
    {
        return Configuration::get('API_ENABLE', true);
    }

    public function getAllProducts()
    {
        $id_lang = (int) Context::getContext()->language->id;
        $start = 0;
        $limit = 100000;
        $order_by = 'id_product';
        $order_way = 'DESC';
        $id_category = false;
        $only_active = false;
        $context = null;

        $all_products = Product::getProducts($id_lang, $start, $limit, $order_by, $order_way, $id_category, $only_active, $context);
        $all_products_ret = [];

        foreach ($all_products as $key => $product) {
            $all_products_ret[] = [
                'id_product' => $product['id_product'],
                'reference' => $product['reference'],
                'name' => $product['name'],
                'active' => (($product['active']) ? (true) : (false)),
            ];
        }

        return $all_products_ret;
    }

    public function getUpdatedOrders($updated_since)
    {
        $date_upd = date('Y-m-d H:i:s', strtotime($updated_since));

        $request = 'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `date_upd` >= "' . $date_upd . '"';
        $db = Db::getInstance(_PS_USE_SQL_SLAVE_);
        $result = $db->executeS($request);

        $updated_orders = [];
        foreach ($result as $key => $order) {
            $params = ['id_order' => $order['id_order'], 'getUpdatedOrders' => true];
            $updated_orders[] = $this->createOrderDetailArray($params);
        }

        return $updated_orders;
    }

    public function logData($data)
    {
        $this->logger->logDebug($data);
    }

    /* funkce pro změnu stavu objednávky v callbacku */
    public function setOrderStates($callback)
    {
        // $this->logger->logDebug($callback);

        $order_code = htmlspecialchars($callback['code']);
        $miguel_state = $callback['miguel_state'];
        $condition = 'finished'; // jaký má mít objednávka stav, aby došlo ke změně stavu
        $products = $callback['products'];

        // podmínka, jestli je dokončeno, tato podmínka ošetřuje i stav, kdy není žádný produkt od Miguela - potom je totiž stav: noMiguelProducts
        if ($miguel_state != $condition) {
            return 'waiting for status: ' . $condition;
        }

        // podmínka, jestli jsou vráceny, alespoň nějaké produkty
        if (count($products) < 1) {
            return 'no products';
        }

        // podmínka, jestli jsou zde i produkty jiné, než Miguel
        $miguel_only = 1; // rozhodnout, jestli jsou zde jen miguel produkty
        foreach ($products as $key => $product) {
            if (count($product['formats']) < 1) {
                $miguel_only = 0;
            } // našel jsem apoň jednu položku, která není od Miguela
        }

        $new_state_id = 0;
        if ($miguel_only) {
            $new_state_id = Configuration::get('NEW_STATE_AUTO_CHANGE_MIGUEL_ONLY', true);
        } else {
            $new_state_id = Configuration::get('NEW_STATE_AUTO_CHANGE_MIGUEL_OTHERS', true);
        }
        if (0 == $new_state_id) {
            return 'auto change not set';
        }

        $request = 'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `reference` = "' . $order_code . '"';
        $db = Db::getInstance(_PS_USE_SQL_SLAVE_);
        $result = $db->executeS($request);

        // $this->logger->logDebug($request);
        if (count($result) < 1) {
            return 'unknown order id';
        }
        if (count($result) > 1) {
            return 'multiple same order id';
        }
        if (0 == array_key_exists('id_order', $result[0])) {
            return 'error: missing id_order';
        }

        $id_order = $result[0]['id_order'];
        $order = new Order($id_order);
        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->changeIdOrderState($new_state_id, (int) $order->id); // order status=2 Payment Accepted

        return 'state changed';
    }

    public function getAuthorizationHeader()
    {
        $headers = null;
        if (isset($_SERVER['Authorization'])) {
            $headers = trim($_SERVER['Authorization']);
        } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) { // Nginx or fast CGI
            $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
        } elseif (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            // Server-side fix for bug in old Android versions (a nice side-effect of this fix means we don't care about capitalization for Authorization)
            $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
            // print_r($requestHeaders);
            if (isset($requestHeaders['Authorization'])) {
                $headers = trim($requestHeaders['Authorization']);
            }
        }

        return $headers;
    }

    public function getBearerToken()
    {
        $headers = $this->getAuthorizationHeader();
        // HEADER: Get the access token from the header
        if (!empty($headers)) {
            if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
                return $matches[1];
            }
        }

        return false;
    }

    // přidá položku do nastavení účtu
    public function hookDisplayCustomerAccount(array $params)
    {
        $api_configuration = $this->getCurrentApiConfiguration();
        if (false == $api_configuration) {
            return;
        }
        if (0 == $api_configuration['api_enable']) {
            return;
        }

        $this->smarty->assign([
            'url' => $this->context->link->getModuleLink('miguel', 'purchased'),
            'miguelTitlePage' => $this->l('Purchased e-books'),
        ]);

        return $this->fetch('module:miguel/views/templates/hook/displayCustomerAccount.tpl');
    }

    private function arrayWithCode($arr, $code)
    {
        foreach ($arr['orders'] as $key => $item) {
            if ($item['code'] == $code) {
                return $item;
            }
        }

        return false;
    }

    public function getOrderedBooks()
    {
        // načtu všechny objednávky přihlášenoho uživatele
        $orders_prestashop = Order::getCustomerOrders((int) $this->context->customer->id);
        if (count($orders_prestashop) < 1) {
            return ['result' => false, 'debug' => 'no_orders_prestashop'];
        } // žádné objednávky od uživatele v prestashopu

        $user_email = $this->context->customer->email;

        $uri = '/v1/orders?user_email=' . $user_email;
        $orders_servantes_json = $this->curlGet($uri);

        if (false == $orders_servantes_json) {
            return ['result' => false, 'debug' => 'get_err'];
        } // žádné objednávky od uživatele v miguelovi

        $orders_servantes = json_decode($orders_servantes_json, true);
        if (count($orders_servantes) < 1) {
            return ['result' => false, 'debug' => 'no_orders_servantes'];
        } // žádné objednávky od uživatele v servantes

        $orders = [];
        foreach ($orders_prestashop as $key => $order) {
            $arr = $this->arrayWithCode($orders_servantes, $order['reference']);
            if ($arr) {
                foreach ($arr['products'] as $key1 => $product) {
                    if (isset($product['book'])) { // produkt je kniha
                        $orders[] = [
                            'id_order' => $order['id_order'],
                            'reference' => $order['reference'],
                            'date_add' => Tools::displayDate($order['date_add'], $this->context->language->id),
                            'order_state' => $order['order_state'],
                            'paid' => (($arr['paid']) ? ('1') : ('0')),
                            'product' => $product,
                        ];
                    }
                }
            }
        }

        return $orders;
    }

    /**
     * @param Integer|false $lang_id
     * @return String
     */
    private function getLanguageCode($lang_id = false)
    {
        if ($lang_id == false) {
            $lang_id = $this->context->language->id;
        }

        $language = new Language($lang_id);
        if (false == Validate::isLoadedObject($language)) {
            if (defined('_LOGGER_')) {
                $this->logger->logDebug('getLanguageCode: Cannot create new Language: ' . $lang_id);
            }

            return;
        }

        return $language->iso_code;
    }
}
