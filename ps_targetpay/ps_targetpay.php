<?php
/**
 * @file	Provides support for TargetPay iDEAL, Mister Cash and Sofort Banking
 * @author	Yellow Melon B.V.
 * @url		http://www.idealplugins.nl
 */
 
class ps_targetpay extends PaymentModule {
	
	private $_html = '';
    private $_postErrors = array();
	
	function __construct()    {
		$this->name = 'ps_targetpay';
		$this->tab = 'payments_gateways';
		$this->author = 'idealplugins.nl';
		$this->version = 1;
		
		//$this->ps_versions_compliancy = array('min' => '1.5', 'max' => '1.5'); 
		
		parent::__construct();
		
		/* The parent construct is required for translations */
		$this->page = basename(__FILE__, '.php');
		$this->displayName = $this->l('TargetPay Bank Payments');
		$this->description = $this->l('Let the customer pay with popular payment services such as iDEAL (The Netherlands), MrCash (Belgium), SOFORT Banking (Germany)');

		$this->currencies = true;
		$this->currencies_mode = 'radio';

	}
	
	/* Install / uninstall stuff */
	function install() {
		if (!parent::install() || !$this->createTargetpayIdealTable() || !$this->registerHook('invoice') || !$this->registerHook('payment') || !$this->registerHook('paymentReturn') || Currency::refreshCurrencies()) {
			return false;
		}
		
		return true;
	}
	
	
	function uninstall() {
		
		
		if (!$this->removeTables() || !parent::uninstall()) {
			return false;
		}
		return true;
	}
	
	function removeTables () {
		/*
		 * In the beginning while developing this code, this was a test.
		 * If you want to drop the table, if it exists, uncomment below for a cleanup of the database table
		 * NOTE: All historical data will be dropped!
		 */
		//$db = Db::getInstance(); 
		//$query = "DROP TABLE IF EXISTS `"._DB_PREFIX_."targetpay_ideal`";
		//$db->Execute($query);
		return true;
	}
	
	function createTargetpayIdealTable() {
		/**Function called by install - 
		* creates the "order_paymentcard" table required for storing payment card details
		 * Column Descriptions:
		 * id_payment the primary key. 
		 * order_id : Stores the order number associated with iDEAL
		 * paymethod: Stores the paymethod like iDEAL (IDE) Mister Cash (MRC) Sofort Banking (DEB)
		 * transaction_id: The transaction_id which is retrieved from the API
		 * bank_id: The bank identifier
		 * description: Description of the payment
		 * amount: Decimal of the amount. 1 euro and 10 cents is "1.10"
		 * status: 
		 */
		 
		$db = Db::getInstance(); 
		$query = "CREATE TABLE IF NOT EXISTS `"._DB_PREFIX_."targetpay_ideal` (
		`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
		`order_id` int(11) NULL DEFAULT '0',
		`cart_id` int(11) NOT NULL DEFAULT '0',
		`rtlo` int(11) NOT NULL,
		`paymethod` varchar(8) NOT NULL DEFAULT 'IDE',
		`transaction_id` varchar(255) NOT NULL,
		`bank_id` varchar(8) NOT NULL,
		`description` int(64) NOT NULL,
		`amount` decimal(11,2) NOT NULL,
		`bankaccount` varchar(25) NULL,
		`name` varchar(35) NULL,
		`city` varchar(25) NULL,
		`status` int(5) NOT NULL,
		`via` varchar(10) NULL
		) ENGINE = MYISAM ";
		 
		$db->Execute($query);
		Configuration::updateValue('RTLO', 93929); // Default TargetPay
		return true;
	}
	
	/* admin configuration settings */
	
	public function getContent() {
		$output = null;
		
		if (Tools::isSubmit('submit'.$this->name)) {
			$RTLO = strval(Tools::getValue('RTLO'));
			if (!$RTLO  || empty($RTLO) || !Validate::isGenericName($RTLO) || !Validate::isUnsignedInt($RTLO)) {
				$output .= $this->displayError( $this->l('Invalid RTLO. Only numbers allowed.') );
			} else {
				Configuration::updateValue('RTLO', $RTLO); 
				$output .= $this->displayConfirmation($this->l('RTLO updated'));
			}

			$TEST = strval(Tools::getValue('TEST'));
			Configuration::updateValue('TEST', ($TEST == 1) ? '1' : '0'); 
			$output .= $this->displayConfirmation($this->l('Testmode updated'));
		}

		return $output.$this->displayForm();
	}

	public function displayForm() {
		// Get default Language
		$default_lang = (int)Configuration::get('PS_LANG_DEFAULT');
		
		// Init Fields form array
		$fields_form[0]['form'] = array(
			'legend' => array(
								'title' => $this->l('Settings'),
							),
			'input' => array(
								array(
									'type' => 'text',
									'label' => $this->l('RTLO'),
									'name' => 'RTLO',
									'size' => 20,
									'required' => true
									),

								array(
									'type'      => 'radio',               
									'label'     => $this->l('Testmode'),  
									'name'      => 'TEST', 
									'required'  => true, 
									'is_bool'   => true, 

								  	'values'    => array(                   
								    	array(
								      		'id'    => 'active_off',
								      		'value' => 0,
								      		'label' => $this->l('Disabled: only actual payments will be accepted.')
								    	),
									    array(
								      		'id'    => 'active_on',         
								      		'value' => 1,                   
								      		'label' => $this->l('Enabled: canceled payments will be accepted as well. Turn off after testing!')	
								    	)
								  	),
								),						
							),
			'submit' => array(
								'title' => $this->l('Save'),
								'class' => 'button'
						)
		);

		$helper = new HelperForm();

		// Module, token and currentIndex
		$helper->module = $this;
		$helper->name_controller = $this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;

		// Language
		$helper->default_form_language = $default_lang;
		$helper->allow_employee_form_lang = $default_lang;

		// Title and toolbar
		$helper->title = $this->displayName;
		$helper->show_toolbar = true;        // false -> remove toolbar
		$helper->toolbar_scroll = true;      // yes - > Toolbar is always visible on the top of the screen.
		$helper->submit_action = 'submit'.$this->name;
		$helper->toolbar_btn = array(
										'save' =>
													array(
															'desc' => $this->l('Save'),
															'href' => AdminController::$currentIndex.'&configure='.$this->name.'&save'.$this->name.
															'&token='.Tools::getAdminTokenLite('AdminModules'),
													),
										'back' => array(
															'href' => AdminController::$currentIndex.'&token='.Tools::getAdminTokenLite('AdminModules'),
															'desc' => $this->l('Back to list')
														)
								);

		// Load current value
		$helper->fields_value['RTLO'] = Configuration::get('RTLO');
		$helper->fields_value['TEST'] = Configuration::get('TEST');
		return $helper->generateForm($fields_form);
	}

	
	
	/* hooks */
	/**
	* hookPayment($params)
	* Called in Front Office at Payment Screen - displays user this module as payment option
	*/
	function hookPayment($params) {
		
		global $smarty;
		global $currency;
		if(($currency->iso_code == 'EUR' || $currency->iso_code_num == '978') && $this->active) {
		
			$rtlo = Configuration::get('RTLO');
			require_once('targetpay.class.php');
			
			$idealOBJ = new TargetPayCore("IDE",$rtlo);
			$mrCashOBJ = new TargetPayCore("MRC",$rtlo);
			$directEBankingOBJ = new TargetPayCore("DEB",$rtlo);
			
			$idealBankListArr = $this->setPaymethodInKey("IDE",$idealOBJ->getBankList());
			$mrCashOBJBankListArr = $this->setPaymethodInKey("",$mrCashOBJ->getBankList());
			$directEBankingBankListArr = $this->setPaymethodInKey("",$directEBankingOBJ->getBankList());

			$smarty->assign(array(
					'error' => false,
					'rtlo' => $rtlo,
					'idealBankListArr' => $idealBankListArr,
					'mrCashOBJBankListArr' => $mrCashOBJBankListArr,
					'directEBankingBankListArr' => $directEBankingBankListArr,
					'this_path' => $this->_path,
					'this_path_ssl' => Configuration::get('PS_FO_PROTOCOL').$_SERVER['HTTP_HOST'].__PS_BASE_URI__."modules/{$this->name}/"));
			return $this->display(__FILE__, 'payment_options_selection.tpl');
		} else {
			$smarty->assign(array(
					'error' => 'At the moment it is not possible to pay with iDEAL, Mr.Cash or Sofort Banking because the currency is to \''.$currency->iso_code.'\' instead of \'Euro\'. Please change the currency or contact the owner of the shop.',
					));
			return $this->display(__FILE__, 'currency_issue.tpl');
		}
		
	}
	
	function hookInvoice($params)
	{
		//~ $id_order = $params['id_order'];
		//~ 
			//~ global $smarty;
			//~ $paymentDetails = $this->readPaymentcarddetails($id_order);
			//~ echo $id_order;
			//~ $smarty->assign(array(
			    //~ 'cardHoldername'  	        => $paymentDetails['cardholdername'],
				//~ 'cardNumber' 		        => $paymentDetails['cardnumber'],
				//~ 'id_order'					=> $orderId,
				//~ 'this_page'					=> $_SERVER['REQUEST_URI'],
				//~ 'this_path' 				=> $this->_path,
				//~ 'this_path_ssl' 			=> Configuration::get('PS_FO_PROTOCOL').$_SERVER['HTTP_HOST'].__PS_BASE_URI__."modules/{$this->name}/"));
			//~ return $this->display(__FILE__, 'invoice_block.tpl');
		//~ 
	}
	
	/* support functions */
	
	function setPaymethodInKey($paymethod,$BankListArray){
		$newArr = array();
		foreach($BankListArray AS $key => $value) {
			$newArr[strtoupper($paymethod).$key] = $value;
		}
		return $newArr;
	}
	
	/* test in order to check if the object is available in the script you're requesting this function */
	function objectAvailable(){
		return true;
	}
	
	function selectTransaction($trxid) {
		$sql = sprintf("SELECT `id`, `cart_id`, `rtlo`,`order_id`, `paymethod`, `transaction_id`, `bank_id`, `description`, `amount`, `status`
						FROM `"._DB_PREFIX_."targetpay_ideal`
						WHERE `transaction_id`= '%s'",
						$trxid);
		$result = Db::getInstance()->getRow($sql);
		return $result;
	}
	
	function updateTransaction($updateArr,$trxid, $via) {
		$fields = '';
		foreach($updateArr AS $key => $value) {
			$fields .= "`".$key."` = '".$value."',";
		}
		
		$sql = sprintf("UPDATE `"._DB_PREFIX_."targetpay_ideal` SET
						".$fields."
						`via` = '".$via."'
						WHERE `transaction_id`= '%s'",
						$trxid);
		Db::getInstance()->execute($sql);
		return;
	}
	
}
?>
