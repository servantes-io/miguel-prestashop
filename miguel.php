<?php

/**
 * 2023 Servantes
 *
 * This file is licenced under the Software License Agreement.
 * With the purchase or the installation of the software in your application
 * you accept the licence agreement.
 *
 * You must not modify, adapt or create derivative works of this source code
 *
 *  @author Pavel Vejnar <vejnar.p@gmail.com>
 *  @copyright  2022 - 2025 Servantes
 *  @license LICENSE.txt
 */
require_once 'src/utils/miguel-settings.php';
require_once 'src/utils/miguel-api-response.php';
require_once 'src/utils/miguel-api-create-order-item.php';
require_once 'src/utils/miguel-api-create-order-request.php';
require_once 'src/utils/miguel-api-error.php';
require_once 'src/utils/polyfill-getallheaders.php';

use Miguel\Utils\MiguelApiCreateOrderRequest;
use Miguel\Utils\MiguelApiError;
use Miguel\Utils\MiguelApiResponse;
use Miguel\Utils\MiguelSettings;

// uncomment this line for debugging (look for debug.log in the module directory)
// define('_LOGGER_', 1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class Miguel extends Module
{
    public const HOOKS = [
        'header',
        'actionOrderStatusUpdate', // called when the order status is changed
        'displayCustomerAccount', // called when the customer account is displayed
    ];

    /**
     * @var Miguel|null
     */
    private static $sharedInstance;

    private $_logger;

    public function __construct()
    {
        $this->name = 'miguel';
        $this->tab = 'administration';
        $this->version = '1.1.1';
        $this->author = 'Servantes';
        $this->need_instance = 1;
        $this->bootstrap = true;
        $this->module_key = '713c24e9747f0c1fdb078e247f53cc14';

        parent::__construct();

        $this->displayName = $this->l('Miguel');
        $this->description = $this->l('Sell your e-books and audiobooks directly on your e-shop.');
        $this->confirmUninstall = $this->l('Are you sure you want to remove the add-on?');
        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => '8.99.99'];

        if (defined('_LOGGER_')) {
            $this->_logger = new FileLogger(0); // 0 == debug level, logDebug() won’t work without this.
            $this->_logger->setFilename(_PS_ROOT_DIR_ . '/modules/miguel/debug.log');
        }
    }

    public static function createInstance()
    {
        if (null != self::$sharedInstance) {
            return self::$sharedInstance;
        }

        return new Miguel();
    }

    public static function setSharedInstance($instance)
    {
        self::$sharedInstance = $instance;
    }

    public function install()
    {
        MiguelSettings::reset();

        include dirname(__FILE__) . '/src/sql/install.php';

        return parent::install()
            && $this->registerHook(static::HOOKS);
    }

    public function uninstall()
    {
        MiguelSettings::deleteAll();

        // here is normal uninstall process, because of comments and other lint issues I have deleted the file
        // include dirname(__FILE__) . '/src/sql/uninstall.php';

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
        $saved = false;

        /*
         * If values have been submitted in the form, process.
         */
        if (((bool) Tools::isSubmit('submitMiguelModule')) == true) {
            $this->postProcess();
            $saved = true;
        }

        // kontrola api
        $module_state = 'info_setup_module';
        $api_configuration = $this->getCurrentApiConfiguration();
        if (false == $api_configuration) { // není vložena url nebo token, tak nemohu aktivovat
            if (MiguelSettings::getEnabled()) {
                MiguelSettings::setEnabled(false);
                $module_state = 'info_setup_module_first';
            } else {
                $module_state = 'info_setup_module';
            }
        } elseif ($api_configuration['api_enable']) { // je povoleno api, validuji token
            $test_key = $this->curlPost('/v1/prestashop/connect', $this->getPrestashopDetails());
            if (false == $test_key) {
                $module_state = 'warning_api_fail';
                // pokud se nepodaří přihlásit, tak deaktivuji API
                MiguelSettings::setEnabled(false);
            } else {
                $module_state = 'success_api_ok';
            }
        } else {
            $module_state = 'info_setup_module_activate';
        }

        $module_state_color = 'info';
        if ($module_state === 'info_setup_module') {
            $module_state_color = 'info';
        } elseif ($module_state === 'info_setup_module_first') {
            $module_state_color = 'warning';
        } elseif ($module_state === 'info_setup_module_activate') {
            $module_state_color = 'warning';
        } elseif ($module_state === 'warning_api_fail') {
            $module_state_color = 'danger';
        } elseif ($module_state === 'success_api_ok') {
            $module_state_color = 'success';
        } else {
            $module_state_color = 'danger';
        }

        $this->context->smarty->assign('saved', $saved);
        $this->context->smarty->assign('module_state', $module_state);
        $this->context->smarty->assign('module_state_color', $module_state_color);

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
                'input' => array_filter([
                    [
                        'type' => 'select',
                        'label' => $this->l('Miguel server environment'),
                        'name' => MiguelSettings::API_SERVER_KEY,
                        'desc' => $this->l('For normal operation, choose the production.'),
                        'default_value' => MiguelSettings::ENV_PROD,
                        'options' => [
                            'query' => [
                                ['id' => MiguelSettings::ENV_PROD, 'name' => $this->l('Production')],
                                ['id' => MiguelSettings::ENV_STAGING, 'name' => $this->l('Staging')],
                                ['id' => MiguelSettings::ENV_TEST, 'name' => $this->l('Test')],
                                ['id' => MiguelSettings::ENV_OWN, 'name' => $this->l('Custom')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Miguel server\'s custom address'),
                        'name' => MiguelSettings::API_SERVER_OWN_KEY,
                        'desc' => $this->l('The address will be used if you have chosen a custom production environment.'),
                        'class' => 'input_server',
                        'default_value' => '',
                        'hint' => $this->l('Contact us to get the address.'),
                        'visible' => false,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('API key'),
                        'name' => MiguelSettings::API_TOKEN_PRODUCTION_KEY,
                        'hint' => $this->l('To obtain an API key, use the link from the Documentation.'),
                        'desc' => $this->l('Using the API key, your e-shop will securely communicate with our server.'),
                        'class' => 'input_server',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('API key - Staging'),
                        'name' => MiguelSettings::API_TOKEN_STAGING_KEY,
                        'hint' => $this->l('To obtain an API key, use the link from the Documentation.'),
                        'desc' => $this->l('Using the API key, your e-shop will securely communicate with our server.'),
                        'class' => 'input_server',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('API key - Test'),
                        'name' => MiguelSettings::API_TOKEN_TEST_KEY,
                        'hint' => $this->l('To obtain an API key, use the link from the Documentation.'),
                        'desc' => $this->l('Using the API key, your e-shop will securely communicate with our server.'),
                        'class' => 'input_server',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('API key - Custom'),
                        'name' => MiguelSettings::API_TOKEN_OWN_KEY,
                        'hint' => $this->l('To obtain an API key, use the link from the Documentation.'),
                        'desc' => $this->l('Using the API key, your e-shop will securely communicate with our server.'),
                        'class' => 'input_server',
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Automatic order status change') . '<br>' . $this->l('The user has only purchased books from Miguel'),
                        'name' => MiguelSettings::NEW_STATE_AUTO_CHANGE_MIGUEL_ONLY_KEY,
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
                        'name' => MiguelSettings::NEW_STATE_AUTO_CHANGE_MIGUEL_OTHERS_KEY,
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
                        'name' => MiguelSettings::API_ENABLE_KEY,
                        'desc' => '',
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                            ],
                        ],
                    ],
                ]),
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
        return MiguelSettings::getAll();
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            MiguelSettings::save($key, Tools::getValue($key));
        }
    }

    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     *
     * @return void
     */
    public function hookHeader()
    {
        $this->context->controller->addJS($this->_path . '/views/js/front.js');
        $this->context->controller->addCSS($this->_path . '/views/css/front.css');
    }

    /**
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>|false
     */
    public function createOrderDetailArray($params)
    {
        if (false == isset($params['id_order'])) {
            if (defined('_LOGGER_')) {
                $this->_logger->logDebug('hookActionOrderStatusUpdate: Params not set');
            }

            return false;
        }
        $order = new Order((int) $params['id_order']);
        if (false == Validate::isLoadedObject($order)) {
            if (defined('_LOGGER_')) {
                $this->_logger->logDebug('hookActionOrderStatusUpdate: Cannot create new Order: ' . $params['id_order']);
            }

            return false;
        }
        $customer = new Customer($order->id_customer);
        if (false == Validate::isLoadedObject($customer)) {
            if (defined('_LOGGER_')) {
                $this->_logger->logDebug('hookActionOrderStatusUpdate: Cannot create new Customer: ' . $order->id_customer . ', id_order: ' . $params['id_order']);
            }

            return false;
        }
        $currency = new Currency($order->id_currency);
        if (false == Validate::isLoadedObject($currency)) {
            if (defined('_LOGGER_')) {
                $this->_logger->logDebug('hookActionOrderStatusUpdate: Cannot create new Currency: ' . $order->id_currency . ', id_order: ' . $params['id_order']);
            }

            return false;
        }
        $address_invoice = new Address($order->id_address_invoice);
        if (false == Validate::isLoadedObject($address_invoice)) {
            if (defined('_LOGGER_')) {
                $this->_logger->logDebug('hookActionOrderStatusUpdate: Cannot create new Address: ' . $order->id_address_invoice . ', id_order: ' . $params['id_order']);
            }

            return false;
        }
        $language = new Language($customer->id_lang);
        if (false == Validate::isLoadedObject($language)) {
            if (defined('_LOGGER_')) {
                $this->_logger->logDebug('hookActionOrderStatusUpdate: Cannot create new Language: ' . $customer->id_lang . ', id_order: ' . $params['id_order']);
            }

            return false;
        }
        $order_detail = OrderDetail::getList($params['id_order']);

        if (defined('_LOGGER_')) {
            $this->_logger->logDebug('Order reference: ' . $order->reference);
        }

        $body_orders = [];
        $body_orders['code'] = $order->reference;
        $body_orders['user'] = [
            'id' => (string) $order->id_customer,
            'full_name' => $customer->firstname . ' ' . $customer->lastname,
            'email' => $customer->email,
            'address' => MiguelApiCreateOrderRequest::composeAddress($address_invoice),
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
        $body_orders['products'] = MiguelApiCreateOrderRequest::createProductsArray($order, $order_detail);

        if (defined('_LOGGER_')) {
            $this->_logger->logDebug('Order result: ' . json_encode($body_orders));
        }

        if (count($body_orders['products']) < 1) {
            // there are no products in the order (or products with reference)
            return false;
        }

        return $body_orders;
    }

    public function hookActionOrderStatusUpdate($params)
    {
        if (false == MiguelSettings::getEnabled()) {
            return;
        } // ověření, že je api povoleno

        $body_orders = $this->createOrderDetailArray($params);
        if (false == $body_orders) {
            // ignore when the order is not found or there are no products
            return;
        }

        $res = $this->curlPost('/v1/orders', $body_orders);
    }

    /**
     * @param string $uri
     */
    public function curlGet($uri)
    {
        $configuration = $this->getCurrentApiConfiguration();
        if (false == $configuration) {
            if (defined('_LOGGER_')) {
                $this->_logger->logDebug('configuration not set');
            }

            return false;
        }
        if (false == $configuration['api_enable']) {
            if (defined('_LOGGER_')) {
                $this->_logger->logDebug('configuration api_enable not enable');
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
        $headers[] = 'User-Agent: ' . $this->getUserAgent();
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
     * @param string $uri
     * @param array<string, string> $params
     */
    public function curlPost($uri, array $params)
    {
        $configuration = $this->getCurrentApiConfiguration();
        if (false == $configuration) {
            if (defined('_LOGGER_')) {
                $this->_logger->logDebug('configuration not set');
            }

            return false;
        }
        if (false == $configuration['api_enable']) {
            if (defined('_LOGGER_')) {
                $this->_logger->logDebug('configuration api_enable not enable');
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
        $headers[] = 'User-Agent: ' . $this->getUserAgent();
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // set return type json

        $response = curl_exec($curl);

        $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($http_status >= 200 && $http_status < 300) {
            return (strlen($response) < 1) ? (true) : ($response);
        }

        return false;
    }

    public function getCurrentApiConfiguration()
    {
        $api_server = MiguelSettings::getServer();
        $url = MiguelSettings::getServerUrl($api_server);
        $token = MiguelSettings::getServerToken($api_server);

        $configuration = [
            'url' => $url,
            'token' => $token,
            'api_enable' => MiguelSettings::getEnabled(),
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
        $ps['base_url'] = Tools::getShopDomainSsl(true);
        $ps['base_uri'] = __PS_BASE_URI__;

        return $ps;
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

        $request = 'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `date_upd` >= "' . pSQL($date_upd) . '"';
        $db = Db::getInstance(false);
        $result = $db->executeS($request);

        $updated_orders = [];
        foreach ($result as $key => $order) {
            $params = ['id_order' => $order['id_order'], 'getUpdatedOrders' => true];

            $order_data = $this->createOrderDetailArray($params);
            if ($order_data) {
                // add only real orders (not false values)
                $updated_orders[] = $order_data;
            }
        }

        return $updated_orders;
    }

    public function logData($data)
    {
        $this->_logger->logDebug($data);
    }

    /* funkce pro změnu stavu objednávky v callbacku */
    public function setOrderStates($callback)
    {
        // $this->_logger->logDebug($callback);

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

        $new_state_id = MiguelSettings::getNewStateAutoChange($miguel_only);
        if (false == $new_state_id) {
            return 'auto change not set';
        }

        $request = 'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `reference` = "' . pSQL($order_code) . '"';
        $db = Db::getInstance(false);
        $result = $db->executeS($request);

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
            if (preg_match('/Bearer\s(\S+)/i', $headers, $matches)) {
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
     * @return true|MiguelApiResponse
     */
    public function validateApiAccess()
    {
        $configuration = $this->getCurrentApiConfiguration();
        $token = $this->getBearerToken();

        if (false == $token) {
            return MiguelApiResponse::error(MiguelApiError::apiKeyNotSet(['headers' => getallheaders()]));
        } elseif (false == $configuration) {
            return MiguelApiResponse::error(MiguelApiError::configurationNotSet());
        } elseif (0 == $configuration['api_enable']) {
            return MiguelApiResponse::error(MiguelApiError::moduleDisabled());
        } elseif (1 == $configuration['api_enable']) {
            if ($configuration['token'] == $token) {
                return true;
            } else {
                return MiguelApiResponse::error(MiguelApiError::apiKeyInvalid());
            }
        } else {
            return MiguelApiResponse::error(MiguelApiError::unknownError());
        }
    }

    /**
     * Alias to Tools::file_get_contents (for easier testing)
     *
     * @param string $url
     *
     * @return string|false
     */
    public function readFileContent($url)
    {
        return Tools::file_get_contents($url);
    }

    /**
     * Get the user agent for the request
     *
     * @return string
     */
    public function getUserAgent()
    {
        return 'MiguelForPrestashop/' . $this->version . '; Prestashop/' . _PS_VERSION_ . '; PHP/' . phpversion();
    }

    /**
     * @param int|false $lang_id
     *
     * @return string|false
     */
    private function getLanguageCode($lang_id = false)
    {
        if ($lang_id == false) {
            $lang_id = $this->context->language->id;
        }

        $language = new Language($lang_id);
        if (false == Validate::isLoadedObject($language)) {
            if (defined('_LOGGER_')) {
                $this->_logger->logDebug('getLanguageCode: Cannot create new Language: ' . $lang_id);
            }

            return false;
        }

        return $language->iso_code;
    }
}
