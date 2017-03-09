<?php
/**
 * @file    Provides support for TargetPay iDEAL, Bancontact and Sofort Banking
 * @author  Yellow Melon B.V.
 * @url     http://www.idealplugins.nl
 */
if (! defined('_PS_VERSION_')) {
    exit();
}

require_once('core/targetpay.class.php');

class Ps_Targetpay extends PaymentModule
{
    const DEFAULT_RTLO = 93929;

    public $listMethods = array(
        "IDE" => array(
            'name' => 'iDEAL',
            'enabled' => 1,
            'extra_text' => 'Enable iDEAL method'
        ),
        "MRC" => array(
            'name' => 'Bancontact',
            'enabled' => 1,
            'extra_text' => 'Enable Bancontact method'
        ),
        "DEB" => array(
            'name' => 'Sofort Banking',
            'enabled' => 1,
            'extra_text' => 'Enable Sofort Banking method'
        ),
        'WAL' => array(
            'name' => 'Paysafecard',
            'enabled' => 1,
            'extra_text' => 'Enable Paysafecard method'
        ),
        'CC' => array(
            'name' => 'Creditcard',
            'enabled' => 0,
            'extra_text' => 'Enable Creditcard method (only possible when creditcard is activated on your targetpay account)'
        )
    );

    public $appId = '863dcf87fc7cf24696ac1446633c0da0';

    public function __construct()
    {
        $this->name = 'ps_targetpay';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.1';
        $this->ps_versions_compliancy = array(
            'min' => '1.6',
            'max' => '1.6.99.99'
        );
        $this->author = 'Harry';
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('TargetPay Bank Payments');
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
        foreach ($this->listMethods as $id => $method) {
            Configuration::updateValue('ENABLE_METHOD_' . $id, $method['enabled']);
        }
        if (! parent::install() || ! $this->createTargetpayIdealTable() || ! $this->registerHook('header') || ! $this->registerHook('payment') || ! $this->registerHook('paymentReturn') || ! $this->registerHook('displayPaymentTop') || Currency::refreshCurrencies()) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Delete config when uninstall
     * @return unknown
     */
    public function uninstall()
    {
        Configuration::deleteByName('TARGETPAY_RTLO');
        Configuration::deleteByName('TARGETPAY_TESTMODE');
        foreach ($this->listMethods as $id => $method) {
            Configuration::deleteByName('ENABLE_METHOD_' . $id);
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
     * status: init:0, success:1, fail:2
     * via
     *
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
            `bank_id` varchar(8) NOT NULL,
            `description` varchar(64) NOT NULL,
            `amount` decimal(11,2) NOT NULL,
            `bankaccount` varchar(25) NULL,
            `name` varchar(35) NULL,
            `city` varchar(25) NULL,
            `status` int(5) NOT NULL,
            `via` varchar(25) NULL,
            INDEX `IX_tp_transaction_id` (`transaction_id`)
            ) ENGINE = InnoDB ";
        
        $db->Execute($query);
        
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

    /* admin configuration settings */
    /**
     * Admin configuration settings
     * @return string
     */
    public function getContent()
    {
        $output = null;
        
        if (Tools::isSubmit('submit' . $this->name)) {
            $RTLO = strval(Tools::getValue('TARGETPAY_RTLO'));
            if (! $RTLO || empty($RTLO) || ! Validate::isGenericName($RTLO) || ! Validate::isUnsignedInt($RTLO)) {
                $output .= $this->displayError($this->l('Invalid RTLO. Only numbers allowed.'));
            } else {
                Configuration::updateValue('TARGETPAY_RTLO', $RTLO);
                $TEST = strval(Tools::getValue('TARGETPAY_TESTMODE'));
                Configuration::updateValue('TARGETPAY_TESTMODE', ($TEST == 1) ? '1' : '0');
                foreach ($this->listMethods as $id => $method) {
                    $value = strval(Tools::getValue('ENABLE_METHOD_' . $id));
                    Configuration::updateValue('ENABLE_METHOD_' . $id, ($value == 1) ? '1' : '0');
                }
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }
        
        return $output . $this->displayForm();
    }
    
    /**
     * Build config form
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
        $helper->fields_value['TARGETPAY_TESTMODE'] = Configuration::get('TARGETPAY_TESTMODE');
        foreach ($this->listMethods as $id => $name) {
            $helper->fields_value['ENABLE_METHOD_' . $id] = Configuration::get('ENABLE_METHOD_' . $id);
        }
        return $helper->generateForm(array(
            $this->getConfigForm()
        ));
    }
    
    /**
     * Set config element to array
     * @return array
     */
    protected function getConfigForm()
    {
        $arrInputs = array(
            array(
                'col' => 3,
                'type' => 'text',
                'desc' => $this->l('Enter a valid RTLO'),
                'name' => 'TARGETPAY_RTLO',
                'required' => true,
                'label' => $this->l('RTLO')
            ),
            array(
                'type' => 'switch',
                'label' => $this->l('Test mode'),
                'name' => 'TARGETPAY_TESTMODE',
                'is_bool' => true,
                'desc' => $this->l('Use this module in test mode'),
                'values' => array(
                    array(
                        'id' => 'active_on',
                        'value' => true,
                        'label' => $this->l('Enabled')
                    ),
                    array(
                        'id' => 'active_off',
                        'value' => false,
                        'label' => $this->l('Disabled')
                    )
                )
            )
        );
        foreach ($this->listMethods as $id => $method) {
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
        }
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
            foreach ($this->listMethods as $id => $method) {
                if (Configuration::get('ENABLE_METHOD_' . $id) == 1) {
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
     * @return string
     */
    public function hookdisplayPaymentTop()
    {
        if (! $this->active || ! Tools::getValue('targetpayerror')) {
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
                $idealBankListArr = $this->setPaymethodInKey("IDE", $idealOBJ->getBankList());
                $smarty->assign(array(
                    'method' => $method,
                    'this_path' => $this->_path,
                    'idealBankListArr' => $idealBankListArr,
                    'this_path_ssl' => Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'modules/{$this->name}/'
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
                $directEBankingBankListArr = $this->setPaymethodInKey("", $directEBankingOBJ->getBankList());
                $smarty->assign(array(
                    'method' => $method,
                    'this_path' => $this->_path,
                    'directEBankingBankListArr' => $directEBankingBankListArr,
                    'this_path_ssl' => Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'modules/{$this->name}/'
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
            default:
        }
    }
    
    /**
     * Create array options of method
     *
     * @param unknown $paymethod
     * @param unknown $BankListArray
     * @return []
     */
    public function setPaymethodInKey($paymethod, $BankListArray)
    {
        $newArr = array();
        foreach ($BankListArray as $key => $value) {
            $newArr[strtoupper($paymethod) . $key] = $value;
        }
        return $newArr;
    }
    
    /**
     * Get transaction info in targetpay_ideal table
     * @param string $trxid
     * @return boolean|object|NULL
     */
    public function selectTransaction($trxid)
    {
        $sql = sprintf("SELECT `id`, `cart_id`, `rtlo`,`order_id`, `paymethod`, `transaction_id`, `bank_id`, `description`, `amount`, `status`
            FROM `" . _DB_PREFIX_ . "targetpay_ideal`
            WHERE `transaction_id`= '%s'", $trxid);
        $result = Db::getInstance()->getRow($sql);
        return $result;
    }
    
    /**
     * Update order, order history, transaction info after payment
     * @param array $transactionInfoArr
     * @param string $via
     */
    public function updateOrderAfterCheck($transactionInfoArr, $via)
    {
        if ($transactionInfoArr['status'] == 0) {
            $targetpayObj = new TargetPayCore($transactionInfoArr["paymethod"], $transactionInfoArr["rtlo"]);
            $targetpayObj->checkPayment($transactionInfoArr['transaction_id']);
            $updateArr = $targetpayObj->getConsumerInfo();
            if ($targetpayObj->getPaidStatus() || Configuration::get('TARGETPAY_TESTMODE')) {
                $state = Configuration::get('PS_OS_PAYMENT');
                $updateArr["status"] = 1;
            } else {
                $state = Configuration::get('PS_OS_ERROR');
                $updateArr["status"] = 2;
                $updateArr["description"] = 'Error:' . $targetpayObj->getErrorMessage();
            }
            
            $orderId = (int) $transactionInfoArr['order_id'];
            $history = new OrderHistory();
            $history->id_order = $orderId;
            $history->changeIdOrderState($state, $orderId);
            $history->save();
            $this->updateTransaction($updateArr, $transactionInfoArr['transaction_id'], $via);
        }
    }
    
    /**
     * Update transaction info in targetpay_ideal table
     * @param array $updateArr
     * @param string $trxid
     * @param string $via
     */
    public function updateTransaction($updateArr, $trxid, $via)
    {
        $fields = '';
        foreach ($updateArr as $key => $value) {
            $fields .= "`" . $key . "` = '" . $value . "',";
        }
    
        $sql = sprintf("UPDATE `" . _DB_PREFIX_ . "targetpay_ideal` SET
            " . $fields . "
            `via` = '" . $via . "'
            WHERE `transaction_id`= '%s'", $trxid);
        Db::getInstance()->execute($sql);
        return;
    }
}
