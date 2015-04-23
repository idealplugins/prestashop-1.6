<?php
/**
 * @file	Provides support for TargetPay iDEAL, Mister Cash and Sofort Banking
 * @author	Yellow Melon B.V.
 * @url		http://www.idealplugins.nl
 * 
 * 11-09-2014 -> Removed checkReportValidity 
 * 14-01-2015 -> Secure key added 
 */

class ps_targetpaynotifyUrlModuleFrontController extends ModuleFrontController
{
	public $ssl = true;

	/**
	 * @see FrontController::initContent()
	 */
	
	public function initContent() 
	{
		$file = realpath(dirname(__FILE__) . '/../../targetpay.class.php');
		require_once($file);
		
		$file = realpath(dirname(__FILE__) . '/../../ps_targetpay.php');
		require_once($file);
		$ps_targetpay = new ps_targetpay();
		
		$trxid = Tools::getValue('trxid');
		$status = (int)Tools::getValue('status');
		
		$transactionInfoArr = $ps_targetpay->selectTransaction($trxid);
		$targetpayObj = new TargetPayCore($transactionInfoArr["paymethod"],$transactionInfoArr["rtlo"]);
		
		$targetpayObj->checkPayment($trxid);

		if ($targetpayObj->getPaidStatus() || Configuration::get('TEST')) {
			$state = Configuration::get('PS_OS_PAYMENT');
			$cart = new Cart($transactionInfoArr["cart_id"]);
			$ps_targetpay->validateOrder(
				intval($cart->id), 
				$state, $transactionInfoArr["amount"], 
				$ps_targetpay->displayName."(".$transactionInfoArr["paymethod"].")",
				NULL,
				array("transaction_id" => $transactionInfoArr["transaction_id"]),
				false, 
				false, 
				$cart->secure_key
			);

			$order = new Order(intval($ps_targetpay->currentOrder));
			$updateArr = $targetpayObj->getConsumerInfo();
			$updateArr["order_id"] = $order->id;
			$updateArr["status"] = 1;
			$ps_targetpay->updateTransaction($updateArr,$trxid, 'notify');
			echo "Paid... ";
		} else {
			echo "Not paid... ";
		}
		echo "(Prestashop-1.6, 23-04-2015)";
		die();
	}

	
	
}
