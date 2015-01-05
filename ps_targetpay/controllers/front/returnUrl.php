<?php
/**
 * @file	Provides support for TargetPay iDEAL, Mister Cash and Sofort Banking
 * @author	Yellow Melon B.V.
 * @url		http://www.idealplugins.nl
 */

class ps_targetpayreturnUrlModuleFrontController extends ModuleFrontController
{
	public $ssl = true;

	/**
	 * @see FrontController::initContent()
	 */
	public function initContent() {
		$file = realpath(dirname(__FILE__) . '/../../targetpay.class.php');
		require_once($file);
		
		$file = realpath(dirname(__FILE__) . '/../../ps_targetpay.php');
		require_once($file);
		$ps_targetpay = new ps_targetpay();
				
		$cart = $this->context->cart;
		
		$trxid = Tools::getValue('trxid');
		parent::initContent();
		
		$paymentInfo = array('order_id' => '-','transaction_id' => '-','amount' => '-');
		$paymentInfo = $ps_targetpay->selectTransaction($trxid);

		if($paymentInfo === false) {
			return $this->handleError('Transaction not found',$paymentInfo);
		}

		$targetpayObj = new TargetPayCore($paymentInfo["paymethod"],$paymentInfo["rtlo"]);
		$targetpayObj->checkPayment($trxid);
		
		$result = $targetpayObj->getPaidStatus();
		if($targetpayObj->getPaidStatus() == false) {
			return $this->handleError($targetpayObj->getErrorMessage(),$paymentInfo);
		}
		return $this->handleSuccess('The payment is paid successfully.',$paymentInfo);
	}
	
	function handleSuccess($message,$paymentInfo) {
		$this->context->smarty->assign(
			array(
				'message' => 'Success',
				'logs' => array(
					$message
				),
				'order' => array('id_order' => $paymentInfo["order_id"],
								'transaction_id' => $paymentInfo["transaction_id"]),
				'price' => Tools::displayPrice($paymentInfo["amount"], $this->context->currency),
			)
		);
		$this->setTemplate('success.tpl');
		return;
	}
	
	function handleError($message,$paymentInfo) {
		$this->context->smarty->assign(
			array(
				'message' => 'Error',
				'logs' => array(
					$message
				),
				'order' => array('id_order' => $paymentInfo["order_id"],
								'transaction_id' => $paymentInfo["transaction_id"]),
				'price' => Tools::displayPrice($paymentInfo["amount"], $this->context->currency),
			)
		);
		$this->setTemplate('error.tpl');
		return;
	}
}
