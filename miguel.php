<?php
/**
* 2007-2022 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2022 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

// 237c579a9eeb4d1b8835b890fe9f1a0c


if (!defined('_PS_VERSION_')) {
    exit;
}


class Miguel extends Module
{   
    const HOOKS = [
        'header',
        'actionFrontControllerSetMedia', // js a css na frontendu v uživatelském účtu
        'backOfficeHeader', 
        'actionOrderStatusUpdate', // volá se při updatu objednávky
        'displayCustomerAccount', // volá se při najetí do účtu
        //'displayMyAccountBlock', // volá se na stránce se seznamem knih
    ];

    public function __construct()
    {
        $this->name = 'miguel';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Servantes';
        $this->need_instance = 1;

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Miguel', [], 'Modules.Miguel.Admin');
        $this->description = $this->trans('Umožňuje prodávat knihy přes službu Miguel.', [], 'Modules.Miguel.Admin');
        $this->confirmUninstall = $this->trans('Opravdu chcete odebrat doplněk?', [], 'Modules.Miguel.Admin'); 
        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);

        // debug
        $this->logger = new FileLogger(0); //0 == debug level, logDebug() won’t work without this.
        $this->logger->setFilename(_PS_ROOT_DIR_."/modules/miguel/debug.log");

        //$this->logger->logDebug("__construct");
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        Configuration::updateValue('API_TOKEN_PRODUCTION', '');
        Configuration::updateValue('API_TOKEN_STAGING', '');
        Configuration::updateValue('API_TOKEN_TEST', '');
        Configuration::updateValue('API_TOKEN_OWN', '');
        Configuration::updateValue('API_SERVER', 'API_TOKEN_PRODUCTION'); // výchozí volba serveru po instalaci modulu
        Configuration::updateValue('API_SERVER_OWN', '');
        Configuration::updateValue('API_ENABLE', false);

        $pageName = [];
        foreach (Language::getLanguages() as $lang) {
            $pageName[$lang['id_lang']] = $this->trans('Zakoupené e-knihy', [], 'Modules.Miguel.Customer', $lang['locale']);
        }
        Configuration::updateValue('miguel_PurchasedPageName', $pageName);

        include(dirname(__FILE__).'/sql/install.php');

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
        Configuration::deleteByName('API_ENABLE');

        include(dirname(__FILE__).'/sql/uninstall.php');

        return parent::uninstall();
    }

    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {   
        /**
         * If values have been submitted in the form, process.
         */
        if (((bool)Tools::isSubmit('submitMiguelModule')) == true) {
            $this->postProcess();
        }

        // kontrola api
        $alert_state = 'info_setup_module';
        $api_configuration = $this->getCurrentApiConfiguration();
        if($api_configuration == false) { // není vložena url nebo token, tak nemohu aktivovat
            if($this->getApiEnable()) {
                Configuration::updateValue('API_ENABLE', false);
                $alert_state = 'info_setup_module_first';
            }
            else {
                $alert_state = 'info_setup_module';
            }
        }
        else if($api_configuration['api_enable']){ // je povoleno api, validuji token
            //$test_key = $this->curlPostTest($api_configuration['url']."/v1/prestashop/connect",$this->getPrestashopDetails(),$api_configuration['token']); 
            $test_key = $this->curlPost("/v1/prestashop/connect",$this->getPrestashopDetails()); 
            if($test_key == false) { 
                $alert_state = 'warning_api_fail';
                Configuration::updateValue('API_ENABLE', false); // pokud se nepodaří přihlásit, tak deaktivuji API
            }
            else {
                $alert_state = 'success_api_ok';
            }            
        } 
        else {
             $alert_state = 'info_setup_module_activate';
        }

        $this->context->smarty->assign('alert_state', $alert_state);

        $this->context->smarty->assign('module_dir', $this->_path);

        $output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');

        return $output.$this->renderForm();
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {   

        $this->context->controller->addJS($this->_path.'views/js/back.js');
        $this->context->controller->addCSS($this->_path.'views/css/back.css');

        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMiguelModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                'title' => $this->trans('Nastavení', [], 'Modules.Miguel.Admin'),
                'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'select',
                        'label' => $this->trans('Prostředí Miguel serveru', [], 'Modules.Miguel.Admin'),
                        'name' => 'API_SERVER',
                        'desc' => $this->trans('Pro běžný provoz zvolte produkční prostředí.', [], 'Modules.Miguel.Admin'),
                        'default_value' => 'API_TOKEN_PRODUCTION',
                        'options' => [ 
                            'query' => [
                                ['id' => 'API_TOKEN_PRODUCTION', 'name' => $this->trans('Produkce', [], 'Modules.Miguel.Admin')],
                                ['id' => 'API_TOKEN_STAGING', 'name' => $this->trans('Staging', [], 'Modules.Miguel.Admin')],
                                ['id' => 'API_TOKEN_TEST', 'name' => $this->trans('Test', [], 'Modules.Miguel.Admin')],
                                ['id' => 'API_TOKEN_OWN', 'name' => $this->trans('Vlastní', [], 'Modules.Miguel.Admin')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ]
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->trans('Vlastní adresa Miguel serveru', [], 'Modules.Miguel.Admin'),
                        'name' => 'API_SERVER_OWN',
                        'desc' => $this->trans('Adresa bude použita, pokud jste si zvolili vlastní produkční prostředí.', [], 'Modules.Miguel.Admin'),
                        'class' => 'input_server',//((Configuration::get('API_SERVER', true) != 'own')?('input_server_own'):('')),
                        'default_value' => "",
                        'hint' => $this->trans('Pro získání adresy nás kontaktujte.', [], 'Modules.Miguel.Admin'),
                        'visible'=>false
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->trans('API klíč pro server - Production', [], 'Modules.Miguel.Admin'),
                        'name' => 'API_TOKEN_PRODUCTION',
                        'hint' => $this->trans('Pro získání API klíče použijte odkaz z Dokumentace.', [], 'Modules.Miguel.Admin'),
                        'desc' => $this->trans('Pomocí API klíče bude váš e-shop bezpečně komunikovat s naším serverem.', [], 'Modules.Miguel.Admin'),
                        'class' => 'input_server',//((Configuration::get('API_SERVER', true) != 'production')?('input_server_production'):('')),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->trans('API klíč pro server - Staging', [], 'Modules.Miguel.Admin'),
                        'name' => 'API_TOKEN_STAGING',
                        'hint' => $this->trans('Pro získání API klíče použijte odkaz z Dokumentace.', [], 'Modules.Miguel.Admin'),
                        'desc' => $this->trans('Pomocí API klíče bude váš e-shop bezpečně komunikovat s naším serverem.', [], 'Modules.Miguel.Admin'),
                        'class' => 'input_server',//((Configuration::get('API_SERVER', true) != 'staging')?('input_server_staging'):('')),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->trans('API klíč pro server - Test', [], 'Modules.Miguel.Admin'),
                        'name' => 'API_TOKEN_TEST',
                        'hint' => $this->trans('Pro získání API klíče použijte odkaz z Dokumentace.', [], 'Modules.Miguel.Admin'),
                        'desc' => $this->trans('Pomocí API klíče bude váš e-shop bezpečně komunikovat s naším serverem.', [], 'Modules.Miguel.Admin'),
                        'class' => 'input_server',//((Configuration::get('API_SERVER', true) != 'test')?('input_server_test'):('')),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->trans('API klíč pro server - Vlastní', [], 'Modules.Miguel.Admin'),
                        'name' => 'API_TOKEN_OWN',
                        'hint' => $this->trans('Pro získání API klíče použijte odkaz z Dokumentace.', [], 'Modules.Miguel.Admin'),
                        'desc' => $this->trans('Pomocí API klíče bude váš e-shop bezpečně komunikovat s naším serverem.', [], 'Modules.Miguel.Admin'),
                        'class' => 'input_server',//((Configuration::get('API_SERVER', true) != 'own')?('input_server_own'):('')),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->trans('Povolit Miguela', [], 'Modules.Miguel.Admin'),
                        'name' => 'API_ENABLE',
                        'desc' => $this->trans('', [], 'Modules.Miguel.Admin'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                               /*'label' => $this->trans('Ano', [], 'Modules.Miguel.Admin'),*/
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                /*'label' => $this->trans('Ne', [], 'Modules.Miguel.Admin'),*/
                            ],
                        ]
                    ),
                ),
                'submit' => array(
                    'title' => $this->trans('Save', [], 'Modules.Miguel.Admin'),
                ),
            ),
        );
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {   
        return array(
            'API_TOKEN_PRODUCTION' => Configuration::get('API_TOKEN_PRODUCTION', true),
            'API_TOKEN_STAGING' => Configuration::get('API_TOKEN_STAGING', true),
            'API_TOKEN_TEST' => Configuration::get('API_TOKEN_TEST', true),
            'API_TOKEN_OWN' => Configuration::get('API_TOKEN_OWN', true),
            'API_SERVER' => Configuration::get('API_SERVER', true),
            'API_SERVER_OWN' => Configuration::get('API_SERVER_OWN', true),
            'API_ENABLE' => Configuration::get('API_ENABLE', true),
        );
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
            //$this->context->controller->addJS($this->_path.'views/js/back.js');
            //$this->context->controller->addCSS($this->_path.'views/css/back.css');
        }
    }

    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookHeader()
    {
        $this->context->controller->addJS($this->_path.'/views/js/front.js');
        $this->context->controller->addCSS($this->_path.'/views/css/front.css');
    }

    public function createOrderDetailArray($params){

        if(isset($params['id_order']) == false) {
            $this->logger->logDebug("hookActionOrderStatusUpdate: Params not set");
            return;
        }
        $order = new Order((int) $params['id_order']);
        if (Validate::isLoadedObject($order) == false) {
            $this->logger->logDebug("hookActionOrderStatusUpdate: Cannot create new Order: ".$params['id_order']);
            return;
        }
        $customer = new Customer($order->id_customer);
        if (Validate::isLoadedObject($customer) == false) {
            $this->logger->logDebug("hookActionOrderStatusUpdate: Cannot create new Customer: ".$order->id_customer.", id_order: ".$params['id_order']);
            return;
        }
        $currency = new Currency($order->id_currency);
        if (Validate::isLoadedObject($currency) == false) {
            $this->logger->logDebug("hookActionOrderStatusUpdate: Cannot create new Currency: ".$order->id_currency.", id_order: ".$params['id_order']);
            return;
        }
        $address_invoice = new Address($order->id_address_invoice);
        if (Validate::isLoadedObject($address_invoice) == false) {
            $this->logger->logDebug("hookActionOrderStatusUpdate: Cannot create new Address: ".$order->id_address_invoice.", id_order: ".$params['id_order']);
            return;
        }
        $language = new Language($customer->id_lang);
        if (Validate::isLoadedObject($customer) == false) {
            $this->logger->logDebug("hookActionOrderStatusUpdate: Cannot create new Language: ".$customer->id_lang.", id_order: ".$params['id_order']);
            return;
        }
        $order_detail = OrderDetail::getList($params['id_order']);

        $address_str  = '';
        $address_str .= ((strlen($address_invoice->company) > 0)?($address_invoice->company.', '):(''));
        $address_str .= ((strlen($address_invoice->firstname) > 0 && strlen($address_invoice->lastname) > 0)?($address_invoice->firstname.' '.$address_invoice->lastname.', '):(''));
        $address_str .= ((strlen($address_invoice->address1) > 0)?($address_invoice->address1.', '):(''));
        $address_str .= ((strlen($address_invoice->address2) > 0)?($address_invoice->address2.', '):(''));
        $address_str .= ((strlen($address_invoice->postcode) > 0)?($address_invoice->postcode.', '):(''));
        $address_str .= ((strlen($address_invoice->city) > 0)?($address_invoice->city.', '):(''));
        $address_str .= ((strlen($address_invoice->country) > 0)?($address_invoice->country.', '):(''));
        $address_str = substr($address_str, 0, -2);

        $body_orders = array();
        $body_orders['code'] = $order->reference;
        $body_orders['user'] = array(
            'id' => $order->id_customer,
            'full_name' => $customer->firstname . ' ' . $customer->lastname,
            'email' => $customer->email,
            'address' => $address_str,
            'lang' => $language->iso_code,
        );
        $body_orders['purchase_date'] = date(DATE_ISO8601, strtotime($order->date_add));
        if(isset($params['getUpdatedOrders'])) {
            $body_orders['update_date'] = date(DATE_ISO8601, strtotime($order->date_upd)); // aktuální datum tam je až po provedení funkce
            $body_orders['paid'] = (($order->total_paid_real == $order->total_paid)?(true):(false));
        }
        else {
            $body_orders['paid'] = (($params['newOrderStatus']->paid)?(true):(false));
        }
        $body_orders['currency_code'] = $currency->iso_code;
        $body_orders['products'] = array();

        foreach ($order_detail as $key => $product) {
            $body_orders['products'][] = array(
                'code' => $product['product_reference'],
                'price' => array(
                    'regular_without_vat' => $product['original_product_price'],
                    'sold_without_vat' => $product['unit_price_tax_excl'],
                ),
            );
        }

        return $body_orders;

    }

    public function hookActionOrderStatusUpdate($params)
    {
        if(Configuration::get('API_ENABLE', true) == false) return; // ověření, že je api povoleno


        $body_orders = $this->createOrderDetailArray($params);
        //$this->logger->logDebug($order);

        //$this->logger->logDebug(json_encode($body_orders));
        //return;
        //$this->logger->logDebug($body_orders);

        $res = $this->curlPost('/v1/orders',$body_orders);

        //$this->logger->logDebug($res);



    }

    /*
    public function curlGetTest($url, $token = null){

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $url,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CUSTOMREQUEST => 'GET',
        ));

        $headers = [];
        $headers[] = 'Content-Type:application/json';
        $headers[] = "Authorization: Bearer ".$token;
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // set return type json

        $response = curl_exec($curl);

        $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if($http_status == 200) return $response;
        
        return false;
    }*/

    public function curlGet($uri){

        $configuration = $this->getCurrentApiConfiguration();
        if($configuration == false) {
            $this->logger->logDebug("configuration not set");
            return false;
        }
        if($configuration['api_enable'] == false) {
            $this->logger->logDebug("configuration api_enable not enable");
            return false;
        }

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $configuration['url'].$uri,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CUSTOMREQUEST => 'GET',
        ));

        $headers = [];
        $headers[] = 'Content-Type:application/json';
        $headers[] = "Authorization: Bearer ".$configuration['token'];
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // set return type json

        $response = curl_exec($curl);

        $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if($http_status == 200) return $response;
        
        return false;
    }

    /*
    public function curlPostTest($url, array $params, $token = null){

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $url,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => json_encode($params),
        ));

        $headers = [];
        $headers[] = 'Content-Type:application/json';
        $headers[] = "Authorization: Bearer ".$token;
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // set return type json

        $response = curl_exec($curl);

        $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);


        $this->logger->logDebug($url);
        $this->logger->logDebug(json_encode($params));
        $this->logger->logDebug($response);

        switch ($http_status){
            case 200:
                return ((strlen($response) < 1)?(true):($response));
            case 401: // nevalidní API klíč
            default:
                $this->logger->logDebug($response);
                return false;
        }

        return false;
        
    }*/

    public function curlPost($uri, array $params){

        $configuration = $this->getCurrentApiConfiguration();
        if($configuration == false) {
            $this->logger->logDebug("configuration not set");
            return false;
        }
        if($configuration['api_enable'] == false) {
            $this->logger->logDebug("configuration api_enable not enable");
            return false;
        }

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $configuration['url'].$uri,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => json_encode($params),
        ));

        $headers = [];
        $headers[] = 'Content-Type:application/json';
        $headers[] = "Authorization: Bearer ".$configuration['token'];
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // set return type json

        $response = curl_exec($curl);

        $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        //$this->logger->logDebug($response);
        switch ($http_status){
            case 200:
                //$this->logger->logDebug("200");
                return ((strlen($response) < 1)?(true):($response));
            case 401: // nevalidní API klíč
            default:
                //$this->logger->logDebug("def");
                //$this->logger->logDebug($response);
                return false;
        }

        return false;
        
    }

    public function getCurrentApiConfiguration() {

        $api_server = Configuration::get('API_SERVER', true);
        $url = "";
        $token = "";
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

        $configuration = array(
            'url' => $url,
            'token' => $token,
            'api_enable' => Configuration::get('API_ENABLE', true)
        );

        if($url == '' || $token == '') return false;
        
        return $configuration;
    }

    public function getPrestashopDetails() {

        $ps = array();
        $ps['ps_version'] = _PS_VERSION_;
        $ps['module_version'] = $this->version;
        $ps['base_url'] = _PS_BASE_URL_;
        $ps['base_uri'] = __PS_BASE_URI__;

        return $ps;
    }

    private static function getApiEnable() {
        return Configuration::get('API_ENABLE', true);
    }

    public function getAllProducts() {
        $id_lang=(int)Context::getContext()->language->id;
        $start=0;
        $limit=100000;
        $order_by='id_product';
        $order_way='DESC';
        $id_category = false; 
        $only_active =false;
        $context = null;

        $all_products=Product::getProducts($id_lang, $start, $limit, $order_by, $order_way, $id_category, $only_active ,  $context);

        $all_products_ret = array();

        foreach ($all_products as $key => $product) {
            $all_products_ret[] = array(
                'id_product' => $product['id_product'],
                'reference' => $product['reference'],
                'name' => $product['name'],
                'active' => (($product['active'])?(true):(false)),
            );
        }

        return $all_products_ret;
    }

    public function getUpdatedOrders($updated_since) {

        $date_upd = date('Y-m-d H:i:s', strtotime($updated_since));

        $request = 'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `date_upd` >= "'.$date_upd.'"';
        $db = Db::getInstance(_PS_USE_SQL_SLAVE_);
        $result = $db->executeS($request);

        $updated_orders = array();
        foreach ($result as $key => $order) {
            $params = array('id_order' => $order['id_order'], 'getUpdatedOrders' => true);
            $updated_orders[] = $this->createOrderDetailArray($params);
        }
        return $updated_orders;
    }

    public function getAuthorizationHeader(){
        $headers = null;
        if (isset($_SERVER['Authorization'])) {
            $headers = trim($_SERVER["Authorization"]);
        }
        else if (isset($_SERVER['HTTP_AUTHORIZATION'])) { //Nginx or fast CGI
            $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
        } elseif (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            // Server-side fix for bug in old Android versions (a nice side-effect of this fix means we don't care about capitalization for Authorization)
            $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
            //print_r($requestHeaders);
            if (isset($requestHeaders['Authorization'])) {
                $headers = trim($requestHeaders['Authorization']);
            }
        }
        return $headers;
    }

    public function getBearerToken() {
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
    public function hookDisplayCustomerAccount(array $params) {

        $api_configuration = $this->getCurrentApiConfiguration();
        if($api_configuration == false) return;
        if($api_configuration['api_enable'] == 0) return;

        $this->smarty->assign([
            'url' => $this->context->link->getModuleLink('miguel', 'purchased'),
            'miguelTitlePage' => Configuration::get('miguel_PurchasedPageName', $this->context->language->id),
        ]);
        return $this->fetch('module:miguel/views/templates/hook/displayCustomerAccount.tpl');
    }

    // stránka se zakoupenými knihami
    /*public function hookDisplayMyAccountBlock(array $params)
    {       
        
        $this->logger->logDebug("hookDisplayMyAccountBlock");

        $this->smarty->assign([
            'blockwishlist' => $this->displayName,
            'url' => $this->context->link->getModuleLink('miguel', 'purchased'),
        ]);

        return $this->fetch('module:miguel/views/templates/hook/account/myaccount-block.tpl');
    }*/

    public function hookActionFrontControllerSetMedia(array $params)
    {   
        //$this->logger->logDebug("hookActionFrontControllerSetMedia");

    }


    public function getOrderedBooks() {
        //$this->logger->logDebug("getPurchasedBooks");

        // načtu všechny objednávky přihlášenoho uživatele
        $orders_prestashop = Order::getCustomerOrders((int)$this->context->customer->id);
        if(count($orders_prestashop) < 1) return array('result' => false, 'debug' => 'no_orders_prestashop'); // žádné objednávky od uživatele v prestashopu

        $user_email = $this->context->customer->email;

        $uri = "/v1/orders?user_email=".$user_email;
        $orders_servantes_json = $this->curlGet($uri);

        if($orders_servantes_json == false) return array('result' => false, 'debug' => 'get_err'); // žádné objednávky od uživatele v miguelovi

        $orders_servantes = json_decode($orders_servantes_json, true);
        if(count($orders_servantes) < 1) return array('result' => false, 'debug' => 'no_orders_servantes'); // žádné objednávky od uživatele v servantes

        //$this->logger->logDebug($orders_prestashop);
        //$this->logger->logDebug($orders_servantes);

        function arrayWithCode($arr, $code) {
            
            foreach ($arr['orders'] as $key => $item) {
                if($item['code'] == $code) return $item;
            }
            return false;
        }

        $orders = array();
        foreach ($orders_prestashop as $key => $order) {
            $arr = arrayWithCode($orders_servantes , $order['reference']);
            if($arr){
                foreach ($arr['products'] as $key1 => $product) {
                    if(isset($product['book'])){ // produkt je kniha
                        $orders[] = array(
                            'id_order' => $order['id_order'],
                            'reference' => $order['reference'],
                            'date_add' => Tools::displayDate($order['date_add'], $this->context->language->id, false, $separator='-'),
                            'order_state' => $order['order_state'],
                            'paid' => (($arr['paid'])?('1'):('0')),
                            'product' => $product,
                            /*'product' => array(
                                'code' => 'elon-musk',
                                'book' => array(
                                    'identifier' => 'elon-musk',
                                    'title' => 'Elon Musk',
                                ),
                                'formats' => array(
                                    array(
                                        'format' => 'epub',
                                        'download_url' => 'https://miguel-test.servantes.cz/v1/download/Kod%20zivota.pdf?order=2022000010&book=kod-zivota&format=pdf&key=GARwcHQk0HrKq12SgJb0KZcjnu1ycJ2HX5xnn5MHkjiQrUvybgVvpvW0Por8',
                                    ),
                                    array(
                                        'format' => 'pdf',
                                        'download_url' => 'https://miguel-test.servantes.cz/v1/download/Kod%20zivota.pdf?order=2022000010&book=kod-zivota&format=pdf&key=GARwcHQk0HrKq12SgJb0KZcjnu1ycJ2HX5xnn5MHkjiQrUvybgVvpvW0Por8',
                                    ),
                                    array(
                                        'format' => 'mobi',
                                        'download_url' => 'https://miguel-test.servantes.cz/v1/download/Kod%20zivota.pdf?order=2022000010&book=kod-zivota&format=pdf&key=GARwcHQk0HrKq12SgJb0KZcjnu1ycJ2HX5xnn5MHkjiQrUvybgVvpvW0Por8',
                                    ),
                                ),
                            ),*/

                        );
                    }
                }
            }
        }



        return $orders;

    

        /*
        // nadpisy sloupců
        $headings = array(
            'name' => 'Název knihy',
            'reference' => 'Označení objednávky',
            'date' => 'Datum zakoupení',
            'paid' => 'Zaplaceno',
        );

        $content = array();

        $book = array(
            'name' => 'Elon',
            'reference' => 'FF11FFFA',
            'date' => '13.3.2022',
            'paid' => true,
        );

        $content[] = $book;

        $products_count = count($content);

        $table = array(
            'products_count' => $products_count,
            'headings' => $headings,
            'content' => $content,
        );/


        return $table;*/
    }





}













