<?php

/**
 * @file    Provides support for TargetPay iDEAL, Bancontact and Sofort Banking
 * @author  Yellow Melon B.V.
 * @url     http://www.idealplugins.nl
 */


if (! defined('_PS_VERSION_')) {
    exit();
}

require_once ('core/targetpay.class.php');

class Ps_Targetpay extends PaymentModule
{
    
    const DEFAULT_RTLO = 93929;
    // you can obtain your api key in your organization dashboard on https://digiwallet.nl
    const DEFAULT_TOKEN = '';
    const DIGIWALLET_BANKWIRE_PARTIAL = 'digiwallet_bankwire_partial';
    const DIGIWALLET_PENDING = 'digiwallet_pending';
    
    public function __construct()
    {
        $this->name = 'ps_targetpay';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.1';
        $this->ps_versions_compliancy = array(
            'min' => '1.6',
            'max' => '1.6.99.99'
        );
        $this->author = 'DigiWallet';
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('DigiWallet Bank Payments');
        $this->description = $this->l('Let the customer pay with popular payment services such as iDEAL (The Netherlands), Bancontact (Belgium), SOFORT Banking (Germany)');
        if (! count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->l('No currency has been set for this module.');
        }
    }
    
    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        if (extension_loaded('curl') == false) {
            $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');
            return false;
        }
        
        Configuration::updateValue('TARGETPAY_RTLO', self::DEFAULT_RTLO); // Default TargetPay
        Configuration::updateValue('TARGETPAY_TOKEN', self::DEFAULT_TOKEN); // Default TargetPay
        $listMethods = $this->getListMethods();
        foreach ($listMethods as $id => $method) {
            Configuration::updateValue('ENABLE_METHOD_' . $id, $method['enabled']);
            Configuration::updateValue('ORDER_METHOD_' . $id, $method['order']);
        }
        if (! parent::install()
            || ! $this->createTargetpayIdealTable()
            || ! $this->updateTargetpayIdealTable()
            || ! $this->createDigiwalletStatus()
            || ! $this->registerHook('header')
            || ! $this->registerHook('backOfficeHeader')
            || ! $this->registerHook('payment')
            || ! $this->registerHook('paymentReturn')
            || ! $this->registerHook('displayPaymentTop')
            || ! $this->registerHook('displayBeforeShoppingCartBlock')
            || ! $this->registerHook('actionOrderSlipAdd') // for refund
            || Currency::refreshCurrencies()) {
                return false;
            }
            
            return true;
    }
    
    /**
     * Delete config when uninstall
     *
     * @return unknown
     */
    public function uninstall()
    {
        Configuration::deleteByName('TARGETPAY_RTLO');
        Configuration::deleteByName('BANK_LIST_MODE');
        Configuration::deleteByName('COUNTRY_LIST_MODE');
        $listMethods = $this->getListMethods();
        foreach ($listMethods as $id => $method) {
            Configuration::deleteByName('ENABLE_METHOD_' . $id);
            Configuration::deleteByName('ORDER_METHOD_' . $id);
        }
        
        return parent::uninstall();
    }
    
    /**
     * Function called by install
     * Column Descriptions:
     * id_payment the primary key.
     * order_id : Stores the order number associated with iDEAL
     * paymethod: Stores the paymethod like iDEAL (IDE) Bancontact (MRC) Sofort Banking (DEB)
     * transaction_id: The transaction_id which is retrieved from the API
     * bank_id: The bank identifier
     * description: Description of the payment
     * amount: Decimal of the amount. 1 euro and 10 cents is "1.10"
     */
    public function createTargetpayIdealTable()
    {
        $db = Db::getInstance();
        $query = "CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . "targetpay_ideal` (
            `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
            `order_id` int(11) NULL DEFAULT '0',
            `cart_id` int(11) NOT NULL DEFAULT '0',
            `rtlo` int(11) NOT NULL,
            `paymethod` varchar(8) NOT NULL DEFAULT 'IDE',
            `transaction_id` varchar(255) NOT NULL,
            `description` varchar(64) NOT NULL,
            `amount` decimal(11,2) NOT NULL,
            INDEX `IX_tp_transaction_id` (`transaction_id`)
            ) ENGINE = InnoDB ";
        
        $db->Execute($query);
        
        return true;
    }
    /**
     * add field
     * @return boolean
     */
    public function updateTargetpayIdealTable()
    {
        $db = Db::getInstance();
        $sql = "SHOW COLUMNS FROM `"._DB_PREFIX_."targetpay_ideal` LIKE 'paid_amount'";
        $results = $db->ExecuteS($sql);
        if (empty($results))
        {
            $db->Execute( "ALTER TABLE `" . _DB_PREFIX_ . "targetpay_ideal` ADD `paid_amount` decimal(11,2) NOT NULL DEFAULT '0' AFTER `paymethod`;");
        }
        return true;
    }
    
    /**
     * @return bool
     */
    public function createDigiwalletStatus()
    {
        $statuses = [
            [
                'module_name' => self::DIGIWALLET_BANKWIRE_PARTIAL,
                'invoice' => 1,
                'send_email' => 0,
                'color' => 'blue',
                'unremovable' => 1,
                'logable' => 1,
                'paid' => 1,
                'title' => 'Digiwallet Partial Payment Received',
                'template' => 'bankwire'
            ],
            [
                'module_name' => self::DIGIWALLET_PENDING,
                'invoice' => 0,
                'send_email' => 0,
                'color' => '#4169E1',
                'unremovable' => 1,
                'logable' => 0,
                'paid' => 0,
                'title' => 'Digiwallet Pending',
                'template' => ''
            ],
        ];
        foreach ($statuses as $status) {
            $db = Db::getInstance();
            $query = '
                SELECT count(*)
                FROM `' . _DB_PREFIX_ . 'order_state`
                WHERE `module_name` = "' . $status['module_name'] . '"
            ';
            $result = Db::getInstance()->getValue($query);
            if (!$result) {
                $query = '
                INSERT INTO `' . _DB_PREFIX_ . 'order_state`
                SET
                    `invoice` = "' . $status['invoice'] . '",
                    `send_email` = "' . $status['send_email'] . '",
                    `module_name` = "' . $status['module_name'] . '",
                    `color` = "' . $status['color'] . '",
                    `unremovable` = "' . $status['unremovable'] . '",
                    `logable` = "' . $status['logable'] . '",
                    `paid` = "' . $status['paid'] . '"
                ';
                $db->Execute($query);
                $statusID = $db->Insert_ID();
                foreach (Language::getLanguages() as $language) {
                    $query = sprintf('
                    INSERT INTO `' . _DB_PREFIX_ . 'order_state_lang`
                    SET
                        `id_order_state` = %d,
                        `id_lang` = %d,
                        `name` = "' . $status['title'] . '",
                        `template` = "' . $status['template'] . '"
                    ',
                        $statusID,
                        $language['id_lang']
                        );
                    $db->Execute($query);
                }
                $digiwalletIcon = dirname(__FILE__).'/logo.gif';
                $newStateIcon = dirname(__FILE__).'/../../img/os/'.(int)$statusID.'.gif';
                copy($digiwalletIcon, $newStateIcon);
            }
        }
        
        return true;
    }
    
    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookHeader()
    {
        $this->context->controller->addJS($this->_path . '/views/js/front.js');
        $this->context->controller->addCSS($this->_path . '/views/css/front.css');
    }
    
    public function hookBackOfficeHeader()
    {
        $this->context->controller->addCSS($this->_path . '/views/css/admin.css');
    }
    
    /* admin configuration settings */
    /**
     * Admin configuration settings
     *
     * @return string
     */
    public function getContent()
    {
        $output = null;
        
        if (Tools::isSubmit('submit' . $this->name)) {
            $RTLO = strval(Tools::getValue('TARGETPAY_RTLO'));
            $Token = strval(Tools::getValue('TARGETPAY_TOKEN'));
            if (! $RTLO || empty($RTLO) || ! Validate::isGenericName($RTLO) || ! Validate::isUnsignedInt($RTLO)) {
                $output .= $this->displayError($this->l('Invalid RTLO. Only numbers allowed.'));
            } else {
                Configuration::updateValue('TARGETPAY_RTLO', $RTLO);
                Configuration::updateValue('TARGETPAY_TOKEN', $Token);
                $listMethods = $this->getListMethods();
                foreach ($listMethods as $id => $method) {
                    $enabled = strval(Tools::getValue('ENABLE_METHOD_' . $id));
                    Configuration::updateValue('ENABLE_METHOD_' . $id, $enabled ? 'yes' : 'no');
                    Configuration::updateValue('ORDER_METHOD_' . $id, strval(Tools::getValue('ORDER_METHOD_' . $id)));
                }
                $bankListMode = strval(Tools::getValue('BANK_LIST_MODE'));
                $countryListMode = strval(Tools::getValue('COUNTRY_LIST_MODE'));
                Configuration::updateValue('BANK_LIST_MODE', ($bankListMode == 1) ? '1' : '0');
                Configuration::updateValue('COUNTRY_LIST_MODE', ($countryListMode == 1) ? '1' : '0');
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }
        
        return $output . $this->displayForm();
    }
    
    /**
     * Build config form
     *
     * @return string
     */
    public function displayForm()
    {
        // Get default Language
        $default_lang = (int) Configuration::get('PS_LANG_DEFAULT');
        
        $helper = new HelperForm();
        
        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        
        // Language
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;
        
        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = true; // false -> remove toolbar
        $helper->toolbar_scroll = true; // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action = 'submit' . $this->name;
        $helper->toolbar_btn = array(
            'save' => array(
                'desc' => $this->l('Save'),
                'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&save' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules')
            ),
            'back' => array(
                'href' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            )
        );
        
        // Load current value
        $helper->fields_value['TARGETPAY_RTLO'] = Configuration::get('TARGETPAY_RTLO');
        $helper->fields_value['TARGETPAY_TOKEN'] = Configuration::get('TARGETPAY_TOKEN');
        $helper->fields_value['BANK_LIST_MODE'] = Configuration::get('BANK_LIST_MODE');
        $helper->fields_value['COUNTRY_LIST_MODE'] = Configuration::get('COUNTRY_LIST_MODE');
        $listMethods = $this->getListMethods();
        foreach ($listMethods as $id => $method) {
            $helper->fields_value['ENABLE_METHOD_' . $id] = $method['enabled'] == 'yes' ? 1 : 0;
            $helper->fields_value['ORDER_METHOD_' . $id] = $method['order'];
        }
        return $helper->generateForm(array(
            $this->getConfigForm()
        ));
    }
    
    /**
     * Set config element to array
     *
     * @return array
     */
    protected function getConfigForm()
    {
        $arrInputs = array(
            array(
                'type' => 'html',
                'html_content' => '<div class="inline description"><p><strong>You can enable test-mode for your outlet from your DigiWallet Organization Dashboard to test your payments through the DigiWallet Test Panel.</strong></p></div>'
            ),
            array(
                'col' => 3,
                'type' => 'text',
                'desc' => $this->l('Enter a valid Digiwallet Outlet Identifier'),
                'name' => 'TARGETPAY_RTLO',
                'required' => true,
                'label' => $this->l('Digiwallet Outlet Identifier')
            ),
            array(
                'col' => 3,
                'type' => 'text',
                'desc' => $this->l('Enter Digiwallet token, register one at digiwallet.nl'),
                'name' => 'TARGETPAY_TOKEN',
                'required' => false,
                'label' => $this->l('Digiwallet Token')
            )
        );
        $listMethods = $this->getListMethods();
        foreach ($listMethods as $id => $method) {
            $arrInputs[] = array(
                'type' => 'switch',
                'label' => $method['name'],
                'name' => 'ENABLE_METHOD_' . $id,
                'is_bool' => true,
                'desc' => $this->l($method['extra_text']),
                'values' => array(
                    array(
                        'id' => 'active_on_' . $id,
                        'value' => true,
                        'label' => $this->l('Enabled')
                    ),
                    array(
                        'id' => 'active_off_' . $id,
                        'value' => false,
                        'label' => $this->l('Disabled')
                    )
                )
            );
            $arrInputs[] = array(
                'required' => true,
                'desc' => 'Order ' . $method['name'],
                'type' => 'html',
                'html_content' => '<input class="tp-sort-order" min="1"  type="number" value="' . $method['order'] . '" name="' . 'ORDER_METHOD_' . $id . '">'
            );
        }
        
        $arrInputs[] = array(
            'type' => 'select',
            'label' => $this->l('iDEAL bank list mode'),
            'name' => 'BANK_LIST_MODE',
            'required' => true,
            'options' => array(
                'query' => array(
                    array(
                        'id_option' => 0,
                        'name' => $this->l('Show in checkout process'),
                        'default' => true
                    ),
                    array(
                        'id_option' => 1,
                        'name' => $this->l('Hide from checkout process, show after confirmation')
                    ),
                ),
                'id' => 'id_option',
                'name' => 'name',
            )
        );
        
        $arrInputs[] = array(
            'type' => 'select',
            'label' => $this->l('Sofort country list mode'),
            'name' => 'COUNTRY_LIST_MODE',
            'required' => true,
            'options' => array(
                'query' => array(
                    array(
                        'id_option' => 0,
                        'name' => $this->l('Show in checkout process')
                    ),
                    array(
                        'id_option' => 1,
                        'name' => $this->l('Hide from checkout process, show after confirmation')
                    ),
                ),
                'id' => 'id_option',
                'name' => 'name'
            )
        );
        
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs'
                ),
                'input' => $arrInputs,
                'submit' => array(
                    'title' => $this->l('Save')
                )
            )
        );
    }
    
    /**
     * hookPayment
     * Called in Front Office at Payment Screen - displays user this module as payment option
     *
     * @param unknown $params
     * @return string
     */
    public function hookPayment($params)
    {
        global $smarty;
        global $currency;
        $output = '';
        if (($currency->iso_code == 'EUR' && $currency->iso_code_num == '978') && $this->active) {
            $rtlo = Configuration::get('TARGETPAY_RTLO');
            
            /* remove unwanted paymethods */
            $listMethods = $this->getListMethods();
            foreach ($listMethods as $id => $method) {
                if ($method['enabled'] == 'yes') {
                    $output .= $this->paymentOptionsSelection($id, $rtlo);
                }
            }
            return $output;
        }
    }
    
    /**
     * This hook is used to display the order confirmation page.
     *
     * @param unknown $params
     * @return void|unknown
     */
    public function hookPaymentReturn($params)
    {
        if ($this->active == false) {
            return;
        }
        
        $order = $params['objOrder'];
        if ($order->getCurrentOrderState()->id == Configuration::get('PS_OS_PAYMENT')) {
            $this->smarty->assign('status', 'ok');
        } else
            if ($order->getCurrentOrderState()->id == Configuration::get('PS_OS_CHEQUE')) {
                $this->smarty->assign('status', 'processing');
            } else {
                $this->smarty->assign('status', 'error');
            }
            $this->smarty->assign(array(
                'id_order' => $order->id,
                'total' => Tools::displayPrice($params['total_to_pay'], $params['currencyObj'], false)
            ));
            return $this->display(__FILE__, 'views/templates/hook/payment_return.tpl');
    }
    
    /**
     * Display error in top of payment section
     *
     * @return string
     */
    public function hookdisplayPaymentTop()
    {
        if (! $this->active || ! Tools::getValue('targetpayerror') || Configuration::get('PS_ORDER_PROCESS_TYPE')) {
            return;
        }
        
        $errorMessage = Tools::getValue('targetpayerror');
        
        $this->context->smarty->assign(array(
            'errorMessage' => $errorMessage,
            'module' => "targetpay"
        ));
        
        return $this->display(__FILE__, 'error.tpl');
    }
    
    /**
     * Display error in top of payment section for order opc
     *
     * @return string
     */
    public function hookdisplayBeforeShoppingCartBlock()
    {
        if (! $this->active || ! Tools::getValue('targetpayerror') || !Configuration::get('PS_ORDER_PROCESS_TYPE')) {
            return;
        }
        
        $errorMessage = Tools::getValue('targetpayerror');
        
        $this->context->smarty->assign(array(
            'errorMessage' => $errorMessage,
            'module' => "targetpay"
        ));
        
        return $this->display(__FILE__, 'error.tpl');
    }
    
    /**
     * Get template of method
     *
     * @param string $method
     * @param string $rtlo
     * @return string
     */
    public function paymentOptionsSelection($method, $rtlo)
    {
        global $smarty;
        
        switch ($method) {
            case "IDE":
                $idealOBJ = new TargetPayCore($method, $rtlo);
                $idealBankListArr = $idealOBJ->getBankList();
                $smarty->assign(array(
                    'method' => $method,
                    'this_path' => $this->_path,
                    'idealBankListArr' => $idealBankListArr,
                    'this_path_ssl' => Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'modules/{$this->name}/',
                    'listMode' => Configuration::get('BANK_LIST_MODE')
                ));
                return $this->display(__FILE__, 'options_selection_ideal.tpl');
                break;
            case "MRC":
                $smarty->assign(array(
                'method' => $method,
                'this_path' => $this->_path,
                'this_path_ssl' => Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'modules/{$this->name}/'
                    ));
                return $this->display(__FILE__, 'options_selection_mrc.tpl');
                break;
            case "DEB":
                $directEBankingOBJ = new TargetPayCore($method, $rtlo);
                $directEBankingBankListArr = $directEBankingOBJ->getCountryList();
                $smarty->assign(array(
                    'method' => $method,
                    'this_path' => $this->_path,
                    'directEBankingBankListArr' => $directEBankingBankListArr,
                    'this_path_ssl' => Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'modules/{$this->name}/',
                    'listMode' => Configuration::get('COUNTRY_LIST_MODE')
                ));
                return $this->display(__FILE__, 'options_selection_deb.tpl');
                break;
            case "WAL":
                $smarty->assign(array(
                'method' => $method,
                'this_path' => $this->_path,
                'this_path_ssl' => Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'modules/{$this->name}/'
                    ));
                return $this->display(__FILE__, 'options_selection_wal.tpl');
                break;
            case "CC":
                $smarty->assign(array(
                'method' => $method,
                'this_path' => $this->_path,
                'this_path_ssl' => Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'modules/{$this->name}/'
                    ));
                return $this->display(__FILE__, 'options_selection_cc.tpl');
                break;
            case "AFP":
                $smarty->assign(array(
                'method' => $method,
                'this_path' => $this->_path,
                'this_path_ssl' => Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'modules/{$this->name}/'
                    ));
                return $this->display(__FILE__, 'options_selection_afp.tpl');
                break;
            case "BW":
                $smarty->assign(array(
                'method' => $method,
                'this_path' => $this->_path,
                'this_path_ssl' => Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'modules/{$this->name}/'
                    ));
                return $this->display(__FILE__, 'options_selection_bw.tpl');
                break;
            case "PYP":
                $smarty->assign(array(
                'method' => $method,
                'this_path' => $this->_path,
                'this_path_ssl' => Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'modules/{$this->name}/'
                    ));
                return $this->display(__FILE__, 'options_selection_pyp.tpl');
                break;
            default:
        }
    }
    
    /**
     * Get transaction info in targetpay_ideal table
     *
     * @param string $trxid
     * @return boolean|object|NULL
     */
    public function selectTransaction($trxid)
    {
        $sql = sprintf("SELECT `id`, `cart_id`, `rtlo`,`order_id`, `paymethod`, `transaction_id`, `description`, `amount`
            FROM `" . _DB_PREFIX_ . "targetpay_ideal`
            WHERE `transaction_id` = '%s'
            ORDER BY `id` DESC", $trxid); // Choose most recent to minimize collision risk because we lack a paymethod field here!
        $result = Db::getInstance()->getRow($sql);
        return $result;
    }
    
    /**
     * @param $statusName
     * @return mixed
     */
    public function getDigiwalletStatusID($statusName)
    {
        $query = '
            SELECT `id_order_state`
            FROM `' . _DB_PREFIX_ . 'order_state`
            WHERE `module_name` = "' . $statusName . '"
        ';
        $result = Db::getInstance()->getRow($query);
        
        return $result['id_order_state'];
    }
    
    /**
     * Update order, order history, transaction info after payment
     *
     * @param array $transactionInfoArr
     */
    public function updateOrderAfterCheck($transactionInfoArr)
    {
        $orderId = (int) $transactionInfoArr['order_id'];
        $order = new Order($orderId);
        if (! $order)
            return ("Order is not found");
            
        if ($order->current_state == Configuration::get('PS_OS_PAYMENT'))
            return ("order $orderId had been done");
        
        $trxid = $transactionInfoArr['transaction_id'];
        
        $targetpayObj = new TargetPayCore($transactionInfoArr["paymethod"], $transactionInfoArr["rtlo"], "nl");
        
        $targetpayObj->checkPayment($trxid);
        
        $updateArr = [];
        
        $paymentIsPartial = false;
        $amountPaid = null;
        if ($targetpayObj->getPaidStatus()) {
            $amountPaid = $transactionInfoArr['amount'];
            if($transactionInfoArr["paymethod"] == 'BW') {
                $consumber_info = $targetpayObj->getConsumerInfo();
                if (!empty($consumber_info) && $consumber_info['bw_paid_amount'] > 0) {
                    $amountPaid = number_format($consumber_info['bw_paid_amount'] / 100, 5);
                    if ($consumber_info['bw_paid_amount'] < $consumber_info['bw_due_amount']) {
                        $paymentIsPartial = true;
                    }
                }
                if ($paymentIsPartial) {
                    $state = $this->getDigiwalletStatusID(self::DIGIWALLET_BANKWIRE_PARTIAL); // Configuration::get('PS_OS_BANKWIRE');
                    $retMsg = $updateArr["description"] = 'Paid partial';
                    $updateArr['paid_amount'] = $amountPaid;
                } else {
                    $state = Configuration::get('PS_OS_PAYMENT');
                    $retMsg = $updateArr["description"] = 'Paid';
                    $updateArr['paid_amount'] = $amountPaid;
                }
            } else {
                $state = Configuration::get('PS_OS_PAYMENT');
                $retMsg = $updateArr["description"] = 'Paid';
                $updateArr['paid_amount'] = $amountPaid;
            }
        } else {
            $state = Configuration::get('PS_OS_ERROR');
            $retMsg = $updateArr["description"] = 'Error:' . $targetpayObj->getErrorMessage();
        }
        
        $history = new OrderHistory();
        $history->id_order = $orderId;
        $history->changeIdOrderState($state, $orderId);
        $history->save();
        $this->updateTransaction($updateArr, $trxid);
        if ($paymentIsPartial) {
            list($payment) = $order->getOrderPaymentCollection(); // Should be one single payment
            $payment->amount = $amountPaid;
            $payment->save();
        }
        
        $order = new Order($orderId);
        $this->sendEmailConfirm($order);
        
        return $retMsg;
    }
    
    /**
     * Update transaction info in targetpay_ideal table
     *
     * @param array $updateArr
     * @param string $trxid
     */
    public function updateTransaction($updateArr, $trxid)
    {
        $fields = '';
        foreach ($updateArr as $key => $value) {
            $fields .= "`" . $key . "` = '" . $value . "',";
        }
        $fields = rtrim($fields, ", ");
        
        $sql = sprintf("UPDATE `" . _DB_PREFIX_ . "targetpay_ideal` SET
            " . $fields . "
            WHERE `transaction_id`= '%s'", $trxid);
        Db::getInstance()->execute($sql);
        return;
    }
    
    public function getListMethods()
    {
        $listMethods = array(
            'AFP' => array(
                'name' => 'Afterpay',
                'enabled' => Configuration::get('ENABLE_METHOD_AFP') ? Configuration::get('ENABLE_METHOD_AFP'): 'no',
                'extra_text' => $this->l('Enable Afterpay method'),
                'order' => Configuration::get('ORDER_METHOD_AFP') ? Configuration::get('ORDER_METHOD_AFP'): 1
            ),
            "MRC" => array(
                'name' => 'Bancontact',
                'enabled' => Configuration::get('ENABLE_METHOD_MRC') ? Configuration::get('ENABLE_METHOD_MRC'): 'yes',
                'extra_text' => $this->l('Enable Bancontact method'),
                'order' => Configuration::get('ORDER_METHOD_MRC') ? Configuration::get('ORDER_METHOD_MRC'): 1
            ),
            'BW' => array(
                'name' => 'Bankwire',
                'enabled' => Configuration::get('ENABLE_METHOD_BW') ? Configuration::get('ENABLE_METHOD_BW'): 'no',
                'extra_text' => $this->l('Enable Bankwire method'),
                'order' => Configuration::get('ORDER_METHOD_BW') ? Configuration::get('ORDER_METHOD_BW'): 1
            ),
            'CC' => array(
                'name' => 'Creditcard',
                'enabled' => Configuration::get('ENABLE_METHOD_CC') ? Configuration::get('ENABLE_METHOD_CC'): 'no',
                'extra_text' => $this->l('Enable Creditcard method (only possible when creditcard is activated on your targetpay account)'),
                'order' => Configuration::get('ORDER_METHOD_CC') ? Configuration::get('ORDER_METHOD_CC'): 1
            ),
            "IDE" => array(
                'name' => 'iDEAL',
                'enabled' => Configuration::get('ENABLE_METHOD_IDE') ? Configuration::get('ENABLE_METHOD_IDE'): 'yes',
                'extra_text' => $this->l('Enable iDEAL method'),
                'order' => Configuration::get('ORDER_METHOD_IDE') ? Configuration::get('ORDER_METHOD_IDE'): 1
            ),
            'PYP' => array(
                'name' => 'Paypal',
                'enabled' => Configuration::get('ENABLE_METHOD_PYP') ? Configuration::get('ENABLE_METHOD_PYP'): 'no',
                'extra_text' => $this->l('Enable Paypal method'),
                'order' => Configuration::get('ORDER_METHOD_PYP') ? Configuration::get('ORDER_METHOD_PYP'): 1
            ),
            'WAL' => array(
                'name' => 'Paysafecard',
                'enabled' => Configuration::get('ENABLE_METHOD_WAL') ? Configuration::get('ENABLE_METHOD_WAL'): 'yes',
                'extra_text' => $this->l('Enable Paysafecard method'),
                'order' => Configuration::get('ORDER_METHOD_WAL') ? Configuration::get('ORDER_METHOD_WAL'): 1
            ),
            'DEB' => array(
                'name' => 'Sofort Banking',
                'enabled' => Configuration::get('ENABLE_METHOD_DEB') ? Configuration::get('ENABLE_METHOD_DEB'): 'yes',
                'extra_text' => $this->l('Enable Sofort Banking method'),
                'order' => Configuration::get('ORDER_METHOD_DEB') ? Configuration::get('ORDER_METHOD_DEB'): 1
            )
        );
        uasort($listMethods, function($a, $b) {
            $retval = $a['order'] - $b['order'];
            if ($retval == 0) {
                $retval = strcmp($a['name'], $b['name']);
            }
            return $retval;
        });
        
        return $listMethods;
    }
    
    
    /**
     *
     * @param unknown $params
     */
    public function hookActionOrderSlipAdd($params)
    {
        $this->refund($params);
    }
    
    /**
     *
     * @param unknown $params
     * @return boolean
     */
    public function refund($params)
    {
        
        if (empty($params['productList']))
            return false;
            
            $order = $params['order'];
            $orderId = $order->id;
            $customer = new Customer($order->id_customer);
            
            $sql = sprintf("SELECT `rtlo`,`paymethod`, `transaction_id`
            FROM `" . _DB_PREFIX_ . "targetpay_ideal`
            WHERE `order_id`= '%s'", $orderId);
            $result = Db::getInstance()->getRow($sql);
            
            $refundAmount = 0;
            foreach ($params['productList'] as $product) {
                $refundAmount += $product['quantity'] * $product['amount'];
            }
            
            if ($refundAmount == 0)
                return false;
                
                $dataRefund = array(
                    'paymethodID' => $result['paymethod'],
                    'transactionID' => $result['transaction_id'],
                    'amount' => intval(floatval($refundAmount) * 100),
                    'description' => 'OrderId: ' . $orderId . ', Amount: ' . $refundAmount,
                    'internalNote' => 'Internal note - OrderId: ' . $orderId . ', Amount: ' . $refundAmount . ', Customer Email: ' . $customer->email,
                    'consumerName' => $customer->firstname . ' ' . $customer->lastname
                );
                
                $targetPay = new TargetPayCore($result['paymethod'], $result['rtlo']);
                
                if (! $targetPay->refund(Configuration::get('TARGETPAY_TOKEN'), $dataRefund)) {
                    PrestaShopLogger::addLog($targetPay->getErrorMessage(), 3);
                    $this->context->controller->errors[] = ($targetPay->getErrorMessage());
                }
    }
    public function validateOrder($id_cart, $id_order_state, $amount_paid, $payment_method = 'Unknown',
        $message = null, $extra_vars = array(), $currency_special = null, $dont_touch_amount = false,
        $secure_key = false, Shop $shop = null)
    {
        if (self::DEBUG_MODE) {
            PrestaShopLogger::addLog('PaymentModule::validateOrder - Function called', 1, null, 'Cart', (int)$id_cart, true);
        }
        
        if (!isset($this->context)) {
            $this->context = Context::getContext();
        }
        $this->context->cart = new Cart((int)$id_cart);
        $this->context->customer = new Customer((int)$this->context->cart->id_customer);
        // The tax cart is loaded before the customer so re-cache the tax calculation method
        $this->context->cart->setTaxCalculationMethod();
        
        $this->context->language = new Language((int)$this->context->cart->id_lang);
        $this->context->shop = ($shop ? $shop : new Shop((int)$this->context->cart->id_shop));
        ShopUrl::resetMainDomainCache();
        $id_currency = $currency_special ? (int)$currency_special : (int)$this->context->cart->id_currency;
        $this->context->currency = new Currency((int)$id_currency, null, (int)$this->context->shop->id);
        if (Configuration::get('PS_TAX_ADDRESS_TYPE') == 'id_address_delivery') {
            $context_country = $this->context->country;
        }
        
        $order_status = new OrderState((int)$id_order_state, (int)$this->context->language->id);
        if (!Validate::isLoadedObject($order_status)) {
            PrestaShopLogger::addLog('PaymentModule::validateOrder - Order Status cannot be loaded', 3, null, 'Cart', (int)$id_cart, true);
            throw new PrestaShopException('Can\'t load Order status');
        }
        
        if (!$this->active) {
            PrestaShopLogger::addLog('PaymentModule::validateOrder - Module is not active', 3, null, 'Cart', (int)$id_cart, true);
            die(Tools::displayError());
        }
        
        // Does order already exists ?
        if (Validate::isLoadedObject($this->context->cart) && $this->context->cart->OrderExists() == false) {
            if ($secure_key !== false && $secure_key != $this->context->cart->secure_key) {
                PrestaShopLogger::addLog('PaymentModule::validateOrder - Secure key does not match', 3, null, 'Cart', (int)$id_cart, true);
                die(Tools::displayError());
            }
            
            // For each package, generate an order
            $delivery_option_list = $this->context->cart->getDeliveryOptionList();
            $package_list = $this->context->cart->getPackageList();
            $cart_delivery_option = $this->context->cart->getDeliveryOption();
            
            // If some delivery options are not defined, or not valid, use the first valid option
            foreach ($delivery_option_list as $id_address => $package) {
                if (!isset($cart_delivery_option[$id_address]) || !array_key_exists($cart_delivery_option[$id_address], $package)) {
                    foreach ($package as $key => $val) {
                        $cart_delivery_option[$id_address] = $key;
                        break;
                    }
                }
            }
            
            $order_list = array();
            $order_detail_list = array();
            
            do {
                $reference = Order::generateReference();
            } while (Order::getByReference($reference)->count());
            
            $this->currentOrderReference = $reference;
            
            $order_creation_failed = false;
            $cart_total_paid = (float)Tools::ps_round((float)$this->context->cart->getOrderTotal(true, Cart::BOTH), 2);
            
            foreach ($cart_delivery_option as $id_address => $key_carriers) {
                foreach ($delivery_option_list[$id_address][$key_carriers]['carrier_list'] as $id_carrier => $data) {
                    foreach ($data['package_list'] as $id_package) {
                        // Rewrite the id_warehouse
                        $package_list[$id_address][$id_package]['id_warehouse'] = (int)$this->context->cart->getPackageIdWarehouse($package_list[$id_address][$id_package], (int)$id_carrier);
                        $package_list[$id_address][$id_package]['id_carrier'] = $id_carrier;
                    }
                }
            }
            // Make sure CartRule caches are empty
            CartRule::cleanCache();
            $cart_rules = $this->context->cart->getCartRules();
            foreach ($cart_rules as $cart_rule) {
                if (($rule = new CartRule((int)$cart_rule['obj']->id)) && Validate::isLoadedObject($rule)) {
                    if ($error = $rule->checkValidity($this->context, true, true)) {
                        $this->context->cart->removeCartRule((int)$rule->id);
                        if (isset($this->context->cookie) && isset($this->context->cookie->id_customer) && $this->context->cookie->id_customer && !empty($rule->code)) {
                            if (Configuration::get('PS_ORDER_PROCESS_TYPE') == 1) {
                                Tools::redirect('index.php?controller=order-opc&submitAddDiscount=1&discount_name='.urlencode($rule->code));
                            }
                            Tools::redirect('index.php?controller=order&submitAddDiscount=1&discount_name='.urlencode($rule->code));
                        } else {
                            $rule_name = isset($rule->name[(int)$this->context->cart->id_lang]) ? $rule->name[(int)$this->context->cart->id_lang] : $rule->code;
                            $error = sprintf(Tools::displayError('CartRule ID %1s (%2s) used in this cart is not valid and has been withdrawn from cart'), (int)$rule->id, $rule_name);
                            PrestaShopLogger::addLog($error, 3, '0000002', 'Cart', (int)$this->context->cart->id);
                        }
                    }
                }
            }
            
            foreach ($package_list as $id_address => $packageByAddress) {
                foreach ($packageByAddress as $id_package => $package) {
                    /** @var Order $order */
                    $order = new Order();
                    $order->product_list = $package['product_list'];
                    
                    if (Configuration::get('PS_TAX_ADDRESS_TYPE') == 'id_address_delivery') {
                        $address = new Address((int)$id_address);
                        $this->context->country = new Country((int)$address->id_country, (int)$this->context->cart->id_lang);
                        if (!$this->context->country->active) {
                            throw new PrestaShopException('The delivery address country is not active.');
                        }
                    }
                    
                    $carrier = null;
                    if (!$this->context->cart->isVirtualCart() && isset($package['id_carrier'])) {
                        $carrier = new Carrier((int)$package['id_carrier'], (int)$this->context->cart->id_lang);
                        $order->id_carrier = (int)$carrier->id;
                        $id_carrier = (int)$carrier->id;
                    } else {
                        $order->id_carrier = 0;
                        $id_carrier = 0;
                    }
                    
                    $order->id_customer = (int)$this->context->cart->id_customer;
                    $order->id_address_invoice = (int)$this->context->cart->id_address_invoice;
                    $order->id_address_delivery = (int)$id_address;
                    $order->id_currency = $this->context->currency->id;
                    $order->id_lang = (int)$this->context->cart->id_lang;
                    $order->id_cart = (int)$this->context->cart->id;
                    $order->reference = $reference;
                    $order->id_shop = (int)$this->context->shop->id;
                    $order->id_shop_group = (int)$this->context->shop->id_shop_group;
                    
                    $order->secure_key = ($secure_key ? pSQL($secure_key) : pSQL($this->context->customer->secure_key));
                    $order->payment = $payment_method;
                    if (isset($this->name)) {
                        $order->module = $this->name;
                    }
                    $order->recyclable = $this->context->cart->recyclable;
                    $order->gift = (int)$this->context->cart->gift;
                    $order->gift_message = $this->context->cart->gift_message;
                    $order->mobile_theme = $this->context->cart->mobile_theme;
                    $order->conversion_rate = $this->context->currency->conversion_rate;
                    $amount_paid = !$dont_touch_amount ? Tools::ps_round((float)$amount_paid, 2) : $amount_paid;
                    $order->total_paid_real = 0;
                    
                    $order->total_products = (float)$this->context->cart->getOrderTotal(false, Cart::ONLY_PRODUCTS, $order->product_list, $id_carrier);
                    $order->total_products_wt = (float)$this->context->cart->getOrderTotal(true, Cart::ONLY_PRODUCTS, $order->product_list, $id_carrier);
                    $order->total_discounts_tax_excl = (float)abs($this->context->cart->getOrderTotal(false, Cart::ONLY_DISCOUNTS, $order->product_list, $id_carrier));
                    $order->total_discounts_tax_incl = (float)abs($this->context->cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS, $order->product_list, $id_carrier));
                    $order->total_discounts = $order->total_discounts_tax_incl;
                    
                    $order->total_shipping_tax_excl = (float)$this->context->cart->getPackageShippingCost((int)$id_carrier, false, null, $order->product_list);
                    $order->total_shipping_tax_incl = (float)$this->context->cart->getPackageShippingCost((int)$id_carrier, true, null, $order->product_list);
                    $order->total_shipping = $order->total_shipping_tax_incl;
                    
                    if (!is_null($carrier) && Validate::isLoadedObject($carrier)) {
                        $order->carrier_tax_rate = $carrier->getTaxesRate(new Address((int)$this->context->cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')}));
                    }
                    
                    $order->total_wrapping_tax_excl = (float)abs($this->context->cart->getOrderTotal(false, Cart::ONLY_WRAPPING, $order->product_list, $id_carrier));
                    $order->total_wrapping_tax_incl = (float)abs($this->context->cart->getOrderTotal(true, Cart::ONLY_WRAPPING, $order->product_list, $id_carrier));
                    $order->total_wrapping = $order->total_wrapping_tax_incl;
                    
                    $order->total_paid_tax_excl = (float)Tools::ps_round((float)$this->context->cart->getOrderTotal(false, Cart::BOTH, $order->product_list, $id_carrier), _PS_PRICE_COMPUTE_PRECISION_);
                    $order->total_paid_tax_incl = (float)Tools::ps_round((float)$this->context->cart->getOrderTotal(true, Cart::BOTH, $order->product_list, $id_carrier), _PS_PRICE_COMPUTE_PRECISION_);
                    $order->total_paid = $order->total_paid_tax_incl;
                    $order->round_mode = Configuration::get('PS_PRICE_ROUND_MODE');
                    $order->round_type = Configuration::get('PS_ROUND_TYPE');
                    
                    $order->invoice_date = '0000-00-00 00:00:00';
                    $order->delivery_date = '0000-00-00 00:00:00';
                    
                    if (self::DEBUG_MODE) {
                        PrestaShopLogger::addLog('PaymentModule::validateOrder - Order is about to be added', 1, null, 'Cart', (int)$id_cart, true);
                    }
                    
                    // Creating order
                    $result = $order->add();
                    
                    if (!$result) {
                        PrestaShopLogger::addLog('PaymentModule::validateOrder - Order cannot be created', 3, null, 'Cart', (int)$id_cart, true);
                        throw new PrestaShopException('Can\'t save Order');
                    }
                    
                    // Amount paid by customer is not the right one -> Status = payment error
                    // We don't use the following condition to avoid the float precision issues : http://www.php.net/manual/en/language.types.float.php
                    // if ($order->total_paid != $order->total_paid_real)
                    // We use number_format in order to compare two string
                    if ($order_status->logable && number_format($cart_total_paid, _PS_PRICE_COMPUTE_PRECISION_) != number_format($amount_paid, _PS_PRICE_COMPUTE_PRECISION_)) {
                        $id_order_state = Configuration::get('PS_OS_ERROR');
                    }
                    
                    $order_list[] = $order;
                    
                    if (self::DEBUG_MODE) {
                        PrestaShopLogger::addLog('PaymentModule::validateOrder - OrderDetail is about to be added', 1, null, 'Cart', (int)$id_cart, true);
                    }
                    
                    // Insert new Order detail list using cart for the current order
                    $order_detail = new OrderDetail(null, null, $this->context);
                    $order_detail->createList($order, $this->context->cart, $id_order_state, $order->product_list, 0, true, $package_list[$id_address][$id_package]['id_warehouse']);
                    $order_detail_list[] = $order_detail;
                    
                    if (self::DEBUG_MODE) {
                        PrestaShopLogger::addLog('PaymentModule::validateOrder - OrderCarrier is about to be added', 1, null, 'Cart', (int)$id_cart, true);
                    }
                    
                    // Adding an entry in order_carrier table
                    if (!is_null($carrier)) {
                        $order_carrier = new OrderCarrier();
                        $order_carrier->id_order = (int)$order->id;
                        $order_carrier->id_carrier = (int)$id_carrier;
                        $order_carrier->weight = (float)$order->getTotalWeight();
                        $order_carrier->shipping_cost_tax_excl = (float)$order->total_shipping_tax_excl;
                        $order_carrier->shipping_cost_tax_incl = (float)$order->total_shipping_tax_incl;
                        $order_carrier->add();
                    }
                }
            }
            
            // The country can only change if the address used for the calculation is the delivery address, and if multi-shipping is activated
            if (Configuration::get('PS_TAX_ADDRESS_TYPE') == 'id_address_delivery') {
                $this->context->country = $context_country;
            }
            
            if (!$this->context->country->active) {
                PrestaShopLogger::addLog('PaymentModule::validateOrder - Country is not active', 3, null, 'Cart', (int)$id_cart, true);
                throw new PrestaShopException('The order address country is not active.');
            }
            
            if (self::DEBUG_MODE) {
                PrestaShopLogger::addLog('PaymentModule::validateOrder - Payment is about to be added', 1, null, 'Cart', (int)$id_cart, true);
            }
            
            // Register Payment only if the order status validate the order
            if ($order_status->logable) {
                // $order is the last order loop in the foreach
                // The method addOrderPayment of the class Order make a create a paymentOrder
                // linked to the order reference and not to the order id
                if (isset($extra_vars['transaction_id'])) {
                    $transaction_id = $extra_vars['transaction_id'];
                } else {
                    $transaction_id = null;
                }
                
                if (!isset($order) || !Validate::isLoadedObject($order) || !$order->addOrderPayment($amount_paid, null, $transaction_id)) {
                    PrestaShopLogger::addLog('PaymentModule::validateOrder - Cannot save Order Payment', 3, null, 'Cart', (int)$id_cart, true);
                    throw new PrestaShopException('Can\'t save Order Payment');
                }
            }
            
            // Next !
            $only_one_gift = false;
            $cart_rule_used = array();
            $products = $this->context->cart->getProducts();
            
            // Make sure CartRule caches are empty
            CartRule::cleanCache();
            foreach ($order_detail_list as $key => $order_detail) {
                /** @var OrderDetail $order_detail */
                
                $order = $order_list[$key];
                if (!$order_creation_failed && isset($order->id)) {
                    if (!$secure_key) {
                        $message .= '<br />'.Tools::displayError('Warning: the secure key is empty, check your payment account before validation');
                    }
                    // Optional message to attach to this order
                    if (isset($message) & !empty($message)) {
                        $msg = new Message();
                        $message = strip_tags($message, '<br>');
                        if (Validate::isCleanHtml($message)) {
                            if (self::DEBUG_MODE) {
                                PrestaShopLogger::addLog('PaymentModule::validateOrder - Message is about to be added', 1, null, 'Cart', (int)$id_cart, true);
                            }
                            $msg->message = $message;
                            $msg->id_cart = (int)$id_cart;
                            $msg->id_customer = (int)($order->id_customer);
                            $msg->id_order = (int)$order->id;
                            $msg->private = 1;
                            $msg->add();
                        }
                    }
                    
                    // Insert new Order detail list using cart for the current order
                    //$orderDetail = new OrderDetail(null, null, $this->context);
                    //$orderDetail->createList($order, $this->context->cart, $id_order_state);
                    
                    // Construct order detail table for the email
                    $products_list = '';
                    $virtual_product = true;
                    
                    $product_var_tpl_list = array();
                    foreach ($order->product_list as $product) {
                        $price = Product::getPriceStatic((int)$product['id_product'], false, ($product['id_product_attribute'] ? (int)$product['id_product_attribute'] : null), 6, null, false, true, $product['cart_quantity'], false, (int)$order->id_customer, (int)$order->id_cart, (int)$order->{Configuration::get('PS_TAX_ADDRESS_TYPE')});
                        $price_wt = Product::getPriceStatic((int)$product['id_product'], true, ($product['id_product_attribute'] ? (int)$product['id_product_attribute'] : null), 2, null, false, true, $product['cart_quantity'], false, (int)$order->id_customer, (int)$order->id_cart, (int)$order->{Configuration::get('PS_TAX_ADDRESS_TYPE')});
                        
                        $product_price = Product::getTaxCalculationMethod() == PS_TAX_EXC ? Tools::ps_round($price, 2) : $price_wt;
                        
                        $product_var_tpl = array(
                            'reference' => $product['reference'],
                            'name' => $product['name'].(isset($product['attributes']) ? ' - '.$product['attributes'] : ''),
                            'unit_price' => Tools::displayPrice($product_price, $this->context->currency, false),
                            'price' => Tools::displayPrice($product_price * $product['quantity'], $this->context->currency, false),
                            'quantity' => $product['quantity'],
                            'customization' => array()
                        );
                        
                        $customized_datas = Product::getAllCustomizedDatas((int)$order->id_cart);
                        if (isset($customized_datas[$product['id_product']][$product['id_product_attribute']])) {
                            $product_var_tpl['customization'] = array();
                            foreach ($customized_datas[$product['id_product']][$product['id_product_attribute']][$order->id_address_delivery] as $customization) {
                                $customization_text = '';
                                if (isset($customization['datas'][Product::CUSTOMIZE_TEXTFIELD])) {
                                    foreach ($customization['datas'][Product::CUSTOMIZE_TEXTFIELD] as $text) {
                                        $customization_text .= $text['name'].': '.$text['value'].'<br />';
                                    }
                                }
                                
                                if (isset($customization['datas'][Product::CUSTOMIZE_FILE])) {
                                    $customization_text .= sprintf(Tools::displayError('%d image(s)'), count($customization['datas'][Product::CUSTOMIZE_FILE])).'<br />';
                                }
                                
                                $customization_quantity = (int)$product['customization_quantity'];
                                
                                $product_var_tpl['customization'][] = array(
                                    'customization_text' => $customization_text,
                                    'customization_quantity' => $customization_quantity,
                                    'quantity' => Tools::displayPrice($customization_quantity * $product_price, $this->context->currency, false)
                                );
                            }
                        }
                        
                        $product_var_tpl_list[] = $product_var_tpl;
                        // Check if is not a virutal product for the displaying of shipping
                        if (!$product['is_virtual']) {
                            $virtual_product &= false;
                        }
                    } // end foreach ($products)
                    
                    $cart_rules_list = array();
                    $total_reduction_value_ti = 0;
                    $total_reduction_value_tex = 0;
                    foreach ($cart_rules as $cart_rule) {
                        $package = array('id_carrier' => $order->id_carrier, 'id_address' => $order->id_address_delivery, 'products' => $order->product_list);
                        $values = array(
                            'tax_incl' => $cart_rule['obj']->getContextualValue(true, $this->context, CartRule::FILTER_ACTION_ALL_NOCAP, $package),
                            'tax_excl' => $cart_rule['obj']->getContextualValue(false, $this->context, CartRule::FILTER_ACTION_ALL_NOCAP, $package)
                        );
                        
                        // If the reduction is not applicable to this order, then continue with the next one
                        if (!$values['tax_excl']) {
                            continue;
                        }
                        
                        // IF
                        //	This is not multi-shipping
                        //	The value of the voucher is greater than the total of the order
                        //	Partial use is allowed
                        //	This is an "amount" reduction, not a reduction in % or a gift
                        // THEN
                        //	The voucher is cloned with a new value corresponding to the remainder
                        if (count($order_list) == 1 && $values['tax_incl'] > ($order->total_products_wt - $total_reduction_value_ti) && $cart_rule['obj']->partial_use == 1 && $cart_rule['obj']->reduction_amount > 0) {
                            // Create a new voucher from the original
                            $voucher = new CartRule((int)$cart_rule['obj']->id); // We need to instantiate the CartRule without lang parameter to allow saving it
                            unset($voucher->id);
                            
                            // Set a new voucher code
                            $voucher->code = empty($voucher->code) ? substr(md5($order->id.'-'.$order->id_customer.'-'.$cart_rule['obj']->id), 0, 16) : $voucher->code.'-2';
                            if (preg_match('/\-([0-9]{1,2})\-([0-9]{1,2})$/', $voucher->code, $matches) && $matches[1] == $matches[2]) {
                                $voucher->code = preg_replace('/'.$matches[0].'$/', '-'.(intval($matches[1]) + 1), $voucher->code);
                            }
                            
                            // Set the new voucher value
                            if ($voucher->reduction_tax) {
                                $voucher->reduction_amount = ($total_reduction_value_ti + $values['tax_incl']) - $order->total_products_wt;
                                
                                // Add total shipping amout only if reduction amount > total shipping
                                if ($voucher->free_shipping == 1 && $voucher->reduction_amount >= $order->total_shipping_tax_incl) {
                                    $voucher->reduction_amount -= $order->total_shipping_tax_incl;
                                }
                            } else {
                                $voucher->reduction_amount = ($total_reduction_value_tex + $values['tax_excl']) - $order->total_products;
                                
                                // Add total shipping amout only if reduction amount > total shipping
                                if ($voucher->free_shipping == 1 && $voucher->reduction_amount >= $order->total_shipping_tax_excl) {
                                    $voucher->reduction_amount -= $order->total_shipping_tax_excl;
                                }
                            }
                            if ($voucher->reduction_amount <= 0) {
                                continue;
                            }
                            
                            if ($this->context->customer->isGuest()) {
                                $voucher->id_customer = 0;
                            } else {
                                $voucher->id_customer = $order->id_customer;
                            }
                            
                            $voucher->quantity = 1;
                            $voucher->reduction_currency = $order->id_currency;
                            $voucher->quantity_per_user = 1;
                            $voucher->free_shipping = 0;
                            if ($voucher->add()) {
                                // If the voucher has conditions, they are now copied to the new voucher
                                CartRule::copyConditions($cart_rule['obj']->id, $voucher->id);
                                
                                $params = array(
                                    '{voucher_amount}' => Tools::displayPrice($voucher->reduction_amount, $this->context->currency, false),
                                    '{voucher_num}' => $voucher->code,
                                    '{firstname}' => $this->context->customer->firstname,
                                    '{lastname}' => $this->context->customer->lastname,
                                    '{id_order}' => $order->reference,
                                    '{order_name}' => $order->getUniqReference()
                                );
                                Mail::Send(
                                    (int)$order->id_lang,
                                    'voucher',
                                    sprintf(Mail::l('New voucher for your order %s', (int)$order->id_lang), $order->reference),
                                    $params,
                                    $this->context->customer->email,
                                    $this->context->customer->firstname.' '.$this->context->customer->lastname,
                                    null, null, null, null, _PS_MAIL_DIR_, false, (int)$order->id_shop
                                    );
                            }
                            
                            $values['tax_incl'] = $order->total_products_wt - $total_reduction_value_ti;
                            $values['tax_excl'] = $order->total_products - $total_reduction_value_tex;
                        }
                        $total_reduction_value_ti += $values['tax_incl'];
                        $total_reduction_value_tex += $values['tax_excl'];
                        
                        $order->addCartRule($cart_rule['obj']->id, $cart_rule['obj']->name, $values, 0, $cart_rule['obj']->free_shipping);
                        
                        if ($id_order_state != Configuration::get('PS_OS_ERROR') && $id_order_state != Configuration::get('PS_OS_CANCELED') && !in_array($cart_rule['obj']->id, $cart_rule_used)) {
                            $cart_rule_used[] = $cart_rule['obj']->id;
                            
                            // Create a new instance of Cart Rule without id_lang, in order to update its quantity
                            $cart_rule_to_update = new CartRule((int)$cart_rule['obj']->id);
                            $cart_rule_to_update->quantity = max(0, $cart_rule_to_update->quantity - 1);
                            $cart_rule_to_update->update();
                        }
                        
                        $cart_rules_list[] = array(
                            'voucher_name' => $cart_rule['obj']->name,
                            'voucher_reduction' => ($values['tax_incl'] != 0.00 ? '-' : '').Tools::displayPrice($values['tax_incl'], $this->context->currency, false)
                        );
                    }
                    
                    // Specify order id for message
                    $old_message = Message::getMessageByCartId((int)$this->context->cart->id);
                    if ($old_message && !$old_message['private']) {
                        $update_message = new Message((int)$old_message['id_message']);
                        $update_message->id_order = (int)$order->id;
                        $update_message->update();
                        
                        // Add this message in the customer thread
                        $customer_thread = new CustomerThread();
                        $customer_thread->id_contact = 0;
                        $customer_thread->id_customer = (int)$order->id_customer;
                        $customer_thread->id_shop = (int)$this->context->shop->id;
                        $customer_thread->id_order = (int)$order->id;
                        $customer_thread->id_lang = (int)$this->context->language->id;
                        $customer_thread->email = $this->context->customer->email;
                        $customer_thread->status = 'open';
                        $customer_thread->token = Tools::passwdGen(12);
                        $customer_thread->add();
                        
                        $customer_message = new CustomerMessage();
                        $customer_message->id_customer_thread = $customer_thread->id;
                        $customer_message->id_employee = 0;
                        $customer_message->message = $update_message->message;
                        $customer_message->private = 0;
                        
                        if (!$customer_message->add()) {
                            $this->errors[] = Tools::displayError('An error occurred while saving message');
                        }
                    }
                    
                    if (self::DEBUG_MODE) {
                        PrestaShopLogger::addLog('PaymentModule::validateOrder - Hook validateOrder is about to be called', 1, null, 'Cart', (int)$id_cart, true);
                    }
                    
                    // Hook validate order
                    Hook::exec('actionValidateOrder', array(
                        'cart' => $this->context->cart,
                        'order' => $order,
                        'customer' => $this->context->customer,
                        'currency' => $this->context->currency,
                        'orderStatus' => $order_status
                    ));
                    
                    foreach ($this->context->cart->getProducts() as $product) {
                        if ($order_status->logable) {
                            ProductSale::addProductSale((int)$product['id_product'], (int)$product['cart_quantity']);
                        }
                    }
                    
                    if (self::DEBUG_MODE) {
                        PrestaShopLogger::addLog('PaymentModule::validateOrder - Order Status is about to be added', 1, null, 'Cart', (int)$id_cart, true);
                    }
                    
                    // Set the order status
                    $new_history = new OrderHistory();
                    $new_history->id_order = (int)$order->id;
                    $new_history->changeIdOrderState((int)$id_order_state, $order, true);
                    $new_history->addWithemail(true, $extra_vars);
                    
                    // Switch to back order if needed
                    if (Configuration::get('PS_STOCK_MANAGEMENT') && ($order_detail->getStockState() || $order_detail->product_quantity_in_stock <= 0)) {
                        $history = new OrderHistory();
                        $history->id_order = (int)$order->id;
                        $history->changeIdOrderState(Configuration::get($order->valid ? 'PS_OS_OUTOFSTOCK_PAID' : 'PS_OS_OUTOFSTOCK_UNPAID'), $order, true);
                        $history->addWithemail();
                    }
                    
                    unset($order_detail);
                    
                    // Order is reloaded because the status just changed
                    $order = new Order((int)$order->id);
                    
                    // updates stock in shops
                    if (Configuration::get('PS_ADVANCED_STOCK_MANAGEMENT')) {
                        $product_list = $order->getProducts();
                        foreach ($product_list as $product) {
                            // if the available quantities depends on the physical stock
                            if (StockAvailable::dependsOnStock($product['product_id'])) {
                                // synchronizes
                                StockAvailable::synchronize($product['product_id'], $order->id_shop);
                            }
                        }
                    }
                    
                    $order->updateOrderDetailTax();
                } else {
                    $error = Tools::displayError('Order creation failed');
                    PrestaShopLogger::addLog($error, 4, '0000002', 'Cart', intval($order->id_cart));
                    die($error);
                }
            } // End foreach $order_detail_list
            
            // Use the last order as currentOrder
            if (isset($order) && $order->id) {
                $this->currentOrder = (int)$order->id;
            }
            
            if (self::DEBUG_MODE) {
                PrestaShopLogger::addLog('PaymentModule::validateOrder - End of validateOrder', 1, null, 'Cart', (int)$id_cart, true);
            }
            
            return true;
        } else {
            $error = Tools::displayError('Cart cannot be loaded or an order has already been placed using this cart');
            PrestaShopLogger::addLog($error, 4, '0000001', 'Cart', intval($this->context->cart->id));
            die($error);
        }
    }
    
    
    public function sendEmailConfirm($order, $extra_vars = null)
    {
        if ($order->current_state != Configuration::get('PS_OS_ERROR') && $order->current_state != Configuration::get('PS_OS_CANCELED') && $this->context->customer->id) {
            $products_list = '';
            $virtual_product = true;
            $order_status = new OrderState((int)$order->current_state, (int)$this->context->language->id);
            $carrier = new Carrier($order->id_carrier);
            $product_var_tpl_list = array();
            foreach ($order->getProducts() as $product) {
                $price = Product::getPriceStatic((int)$product['id_product'], false, ($product['product_attribute_id'] ? (int)$product['product_attribute_id'] : null), 6, null, false, true, $product['product_quantity'], false, (int)$order->id_customer, (int)$order->id_cart, (int)$order->{Configuration::get('PS_TAX_ADDRESS_TYPE')});
                $price_wt = Product::getPriceStatic((int)$product['id_product'], true, ($product['product_attribute_id'] ? (int)$product['product_attribute_id'] : null), 2, null, false, true, $product['product_quantity'], false, (int)$order->id_customer, (int)$order->id_cart, (int)$order->{Configuration::get('PS_TAX_ADDRESS_TYPE')});
                
                $product_price = Product::getTaxCalculationMethod() == PS_TAX_EXC ? Tools::ps_round($price, 2) : $price_wt;
                
                $product_var_tpl = array(
                    'reference' => $product['reference'],
                    'name' => $product['product_name'].(isset($product['attributes']) ? ' - '.$product['attributes'] : ''),
                    'unit_price' => Tools::displayPrice($product_price, $this->context->currency, false),
                    'price' => Tools::displayPrice($product_price * $product['product_quantity'], $this->context->currency, false),
                    'quantity' => $product['product_quantity'],
                    'customization' => array()
                );
                
                $customized_datas = Product::getAllCustomizedDatas((int)$order->id_cart);
                if (isset($customized_datas[$product['product_id']][$product['product_attribute_id']])) {
                    $product_var_tpl['customization'] = array();
                    foreach ($customized_datas[$product['product_id']][$product['product_attribute_id']][$order->id_address_delivery] as $customization) {
                        $customization_text = '';
                        if (isset($customization['datas'][Product::CUSTOMIZE_TEXTFIELD])) {
                            foreach ($customization['datas'][Product::CUSTOMIZE_TEXTFIELD] as $text) {
                                $customization_text .= $text['name'].': '.$text['value'].'<br />';
                            }
                        }
                        
                        if (isset($customization['datas'][Product::CUSTOMIZE_FILE])) {
                            $customization_text .= sprintf(Tools::displayError('%d image(s)'), count($customization['datas'][Product::CUSTOMIZE_FILE])).'<br />';
                        }
                        
                        $customization_quantity = (int)$product['customization_quantity'];
                        
                        $product_var_tpl['customization'][] = array(
                            'customization_text' => $customization_text,
                            'customization_quantity' => $customization_quantity,
                            'quantity' => Tools::displayPrice($customization_quantity * $product_price, $this->context->currency, false)
                        );
                    }
                }
                
                $product_var_tpl_list[] = $product_var_tpl;
                // Check if is not a virutal product for the displaying of shipping
                if (!$product['is_virtual']) {
                    $virtual_product &= false;
                }
            } // end foreach ($products)
    
            $product_list_txt = '';
            $product_list_html = '';
            if (count($product_var_tpl_list) > 0) {
                $product_list_txt = $this->getEmailTemplateContent('order_conf_product_list.txt', Mail::TYPE_TEXT, $product_var_tpl_list);
                $product_list_html = $this->getEmailTemplateContent('order_conf_product_list.tpl', Mail::TYPE_HTML, $product_var_tpl_list);
            }
            
            $cart_rules_list = array();
            $total_reduction_value_ti = 0;
            $total_reduction_value_tex = 0;
            $cart_rules = $order->getCartRules();
            foreach ($cart_rules as $cart_rule) {
                $package = array('id_carrier' => $order->id_carrier, 'id_address' => $order->id_address_delivery, 'products' => $order->product_list);
                $values = array(
                    'tax_incl' => $cart_rule['obj']->getContextualValue(true, $this->context, CartRule::FILTER_ACTION_ALL_NOCAP, $package),
                    'tax_excl' => $cart_rule['obj']->getContextualValue(false, $this->context, CartRule::FILTER_ACTION_ALL_NOCAP, $package)
                );
                
                // If the reduction is not applicable to this order, then continue with the next one
                if (!$values['tax_excl']) {
                    continue;
                }
                
                // IF
                //	This is not multi-shipping
                //	The value of the voucher is greater than the total of the order
                //	Partial use is allowed
                //	This is an "amount" reduction, not a reduction in % or a gift
                // THEN
                //	The voucher is cloned with a new value corresponding to the remainder
                if (count($order_list) == 1 && $values['tax_incl'] > ($order->total_products_wt - $total_reduction_value_ti) && $cart_rule['obj']->partial_use == 1 && $cart_rule['obj']->reduction_amount > 0) {
                    // Create a new voucher from the original
                    $voucher = new CartRule((int)$cart_rule['obj']->id); // We need to instantiate the CartRule without lang parameter to allow saving it
                    unset($voucher->id);
                    
                    // Set a new voucher code
                    $voucher->code = empty($voucher->code) ? substr(md5($order->id.'-'.$order->id_customer.'-'.$cart_rule['obj']->id), 0, 16) : $voucher->code.'-2';
                    if (preg_match('/\-([0-9]{1,2})\-([0-9]{1,2})$/', $voucher->code, $matches) && $matches[1] == $matches[2]) {
                        $voucher->code = preg_replace('/'.$matches[0].'$/', '-'.(intval($matches[1]) + 1), $voucher->code);
                    }
                    
                    // Set the new voucher value
                    if ($voucher->reduction_tax) {
                        $voucher->reduction_amount = ($total_reduction_value_ti + $values['tax_incl']) - $order->total_products_wt;
                        
                        // Add total shipping amout only if reduction amount > total shipping
                        if ($voucher->free_shipping == 1 && $voucher->reduction_amount >= $order->total_shipping_tax_incl) {
                            $voucher->reduction_amount -= $order->total_shipping_tax_incl;
                        }
                    } else {
                        $voucher->reduction_amount = ($total_reduction_value_tex + $values['tax_excl']) - $order->total_products;
                        
                        // Add total shipping amout only if reduction amount > total shipping
                        if ($voucher->free_shipping == 1 && $voucher->reduction_amount >= $order->total_shipping_tax_excl) {
                            $voucher->reduction_amount -= $order->total_shipping_tax_excl;
                        }
                    }
                    if ($voucher->reduction_amount <= 0) {
                        continue;
                    }
                    
                    $values['tax_incl'] = $order->total_products_wt - $total_reduction_value_ti;
                    $values['tax_excl'] = $order->total_products - $total_reduction_value_tex;
                }
                $total_reduction_value_ti += $values['tax_incl'];
                $total_reduction_value_tex += $values['tax_excl'];
                
                $cart_rules_list[] = array(
                    'voucher_name' => $cart_rule['obj']->name,
                    'voucher_reduction' => ($values['tax_incl'] != 0.00 ? '-' : '').Tools::displayPrice($values['tax_incl'], $this->context->currency, false)
                );
            }
            
            $cart_rules_list_txt = '';
            $cart_rules_list_html = '';
            if (count($cart_rules_list) > 0) {
                $cart_rules_list_txt = $this->getEmailTemplateContent('order_conf_cart_rules.txt', Mail::TYPE_TEXT, $cart_rules_list);
                $cart_rules_list_html = $this->getEmailTemplateContent('order_conf_cart_rules.tpl', Mail::TYPE_HTML, $cart_rules_list);
            }
            
            // Order is reloaded because the status just changed
            $order = new Order((int)$order->id);
            
            $invoice = new Address((int)$order->id_address_invoice);
            $delivery = new Address((int)$order->id_address_delivery);
            $delivery_state = $delivery->id_state ? new State((int)$delivery->id_state) : false;
            $invoice_state = $invoice->id_state ? new State((int)$invoice->id_state) : false;
            
            $data = array(
                '{firstname}' => $this->context->customer->firstname,
                '{lastname}' => $this->context->customer->lastname,
                '{email}' => $this->context->customer->email,
                '{delivery_block_txt}' => $this->_getFormatedAddress($delivery, "\n"),
                '{invoice_block_txt}' => $this->_getFormatedAddress($invoice, "\n"),
                '{delivery_block_html}' => $this->_getFormatedAddress($delivery, '<br />', array(
                    'firstname'    => '<span style="font-weight:bold;">%s</span>',
                    'lastname'    => '<span style="font-weight:bold;">%s</span>'
                )),
                '{invoice_block_html}' => $this->_getFormatedAddress($invoice, '<br />', array(
                    'firstname'    => '<span style="font-weight:bold;">%s</span>',
                    'lastname'    => '<span style="font-weight:bold;">%s</span>'
                )),
                '{delivery_company}' => $delivery->company,
                '{delivery_firstname}' => $delivery->firstname,
                '{delivery_lastname}' => $delivery->lastname,
                '{delivery_address1}' => $delivery->address1,
                '{delivery_address2}' => $delivery->address2,
                '{delivery_city}' => $delivery->city,
                '{delivery_postal_code}' => $delivery->postcode,
                '{delivery_country}' => $delivery->country,
                '{delivery_state}' => $delivery->id_state ? $delivery_state->name : '',
                '{delivery_phone}' => ($delivery->phone) ? $delivery->phone : $delivery->phone_mobile,
                '{delivery_other}' => $delivery->other,
                '{invoice_company}' => $invoice->company,
                '{invoice_vat_number}' => $invoice->vat_number,
                '{invoice_firstname}' => $invoice->firstname,
                '{invoice_lastname}' => $invoice->lastname,
                '{invoice_address2}' => $invoice->address2,
                '{invoice_address1}' => $invoice->address1,
                '{invoice_city}' => $invoice->city,
                '{invoice_postal_code}' => $invoice->postcode,
                '{invoice_country}' => $invoice->country,
                '{invoice_state}' => $invoice->id_state ? $invoice_state->name : '',
                '{invoice_phone}' => ($invoice->phone) ? $invoice->phone : $invoice->phone_mobile,
                '{invoice_other}' => $invoice->other,
                '{order_name}' => $order->getUniqReference(),
                '{date}' => Tools::displayDate(date('Y-m-d H:i:s'), null, 1),
                '{carrier}' => ($virtual_product || !isset($carrier->name)) ? Tools::displayError('No carrier') : $carrier->name,
                '{payment}' => Tools::substr($order->payment, 0, 32),
                '{products}' => $product_list_html,
                '{products_txt}' => $product_list_txt,
                '{discounts}' => $cart_rules_list_html,
                '{discounts_txt}' => $cart_rules_list_txt,
                '{total_paid}' => Tools::displayPrice($order->total_paid, $this->context->currency, false),
                '{total_products}' => Tools::displayPrice(Product::getTaxCalculationMethod() == PS_TAX_EXC ? $order->total_products : $order->total_products_wt, $this->context->currency, false),
                '{total_discounts}' => Tools::displayPrice($order->total_discounts, $this->context->currency, false),
                '{total_shipping}' => Tools::displayPrice($order->total_shipping, $this->context->currency, false),
                '{total_wrapping}' => Tools::displayPrice($order->total_wrapping, $this->context->currency, false),
                '{total_tax_paid}' => Tools::displayPrice(($order->total_products_wt - $order->total_products) + ($order->total_shipping_tax_incl - $order->total_shipping_tax_excl), $this->context->currency, false));
                
                if (is_array($extra_vars)) {
                    $data = array_merge($data, $extra_vars);
                }
                
                // Join PDF invoice
                if ((int)Configuration::get('PS_INVOICE') && $order_status->invoice && $order->invoice_number) {
                    $order_invoice_list = $order->getInvoicesCollection();
                    Hook::exec('actionPDFInvoiceRender', array('order_invoice_list' => $order_invoice_list));
                    $pdf = new PDF($order_invoice_list, PDF::TEMPLATE_INVOICE, $this->context->smarty);
                    $file_attachement['content'] = $pdf->render(false);
                    $file_attachement['name'] = Configuration::get('PS_INVOICE_PREFIX', (int)$order->id_lang, null, $order->id_shop).sprintf('%06d', $order->invoice_number).'.pdf';
                    $file_attachement['mime'] = 'application/pdf';
                } else {
                    $file_attachement = null;
                }
                
                if (self::DEBUG_MODE) {
                    PrestaShopLogger::addLog('PaymentModule::validateOrder - Mail is about to be sent', 1, null, 'Cart', (int)$id_cart, true);
                }
                
                if (Validate::isEmail($this->context->customer->email)) {
                    Mail::Send(
                        (int)$order->id_lang,
                        'order_conf',
                        Mail::l('Order confirmation', (int)$order->id_lang),
                        $data,
                        $this->context->customer->email,
                        $this->context->customer->firstname.' '.$this->context->customer->lastname,
                        null,
                        null,
                        $file_attachement,
                        null, _PS_MAIL_DIR_, false, (int)$order->id_shop
                        );
                }
        }
    }

    public function rebuildCart($id_order)
    {
        $oldCart = new Cart(Order::getCartIdStatic($id_order, $this->context->customer->id));
        $duplication = $oldCart->duplicate();
        if (!$duplication || !Validate::isLoadedObject($duplication['cart'])) {
            $this->errors[] = Tools::displayError('Sorry. We cannot renew your order.');
        } elseif (!$duplication['success']) {
            $this->errors[] = Tools::displayError('Some items are no longer available, and we are unable to renew your order.');
        } else {
            $this->context->cookie->id_cart = $duplication['cart']->id;
            $this->context->cookie->check_cgv = true;
            $context = $this->context;
            $context->cart = $duplication['cart'];
            CartRule::autoAddToCart($context);
            $this->context->cookie->write();
        }
        return true;
    }
    
    public function removeCart()
    {
        $products = $this->context->cart->getProducts();
        foreach ($products as $product) {
            $this->context->cart->deleteProduct($product["id_product"]);
        }
        return true;
    }
}