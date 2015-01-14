<?php
/**
 * @file	Provides support for TargetPay iDEAL, Mister Cash and Sofort Banking
 * @author	Yellow Melon B.V.
 * @url		http://www.idealplugins.nl
 */

class Ps_targetpayPaymentModuleFrontController extends ModuleFrontController
{
	public $ssl = true;

	/**
	 * @see FrontController::initContent()
	 */
	public function initContent()
	{
		$file = realpath(dirname(__FILE__) . '/../../targetpay.class.php');
		require_once($file);
		
		parent::initContent();
		
		$cart = $this->context->cart;
		$bankID = Tools::getValue('bankID');
		$cartID = $cart->id;
		$rtlo = Configuration::get('RTLO');
		
		$appId = '863dcf87fc7cf24696ac1446633c0da0';
		$targetpayObj = new TargetPayCore("AUTO",$rtlo,$appId);
		$targetpayObj->setBankId($bankID);
		$targetpayObj->setAmount(($cart->getOrderTotal()*100));
		$targetpayObj->setDescription('Cart id: '.$cart->id);

		$returnUrl = Context::getContext()->link->getModuleLink('ps_targetpay', 'returnUrl', array('cartid'=>$cart->id));
		$targetpayObj->setReturnUrl($returnUrl);
		$reportUrl = Context::getContext()->link->getModuleLink('ps_targetpay', 'notifyUrl', array('cartid'=>$cart->id));
		$targetpayObj->setReportUrl($reportUrl);
		$result = @$targetpayObj->startPayment();

		if($result !== false) {

			$sql = sprintf("INSERT INTO `"._DB_PREFIX_."targetpay_ideal`
					SET
					`cart_id` = %d,
					`paymethod` = '%s',
					`rtlo` = %d,
					`transaction_id` = '%s',
					`bank_id` = '%s',
					`description` = '%s',
					`amount` = '%s',
					`status` = %d,
					`via` = '%s'
					",
					$cartID,
					$targetpayObj->getPayMethod(),
					$rtlo,
					$targetpayObj->getTransactionId(),
					$targetpayObj->getBankId(),
					$targetpayObj->getDescription(),
					($targetpayObj->getAmount()/100),
					0,
					'payment'
					);
					
			Db::getInstance()->Execute($sql);

			Tools::redirectLink($result);
		} else {
			return $this->handleError($targetpayObj->getErrorMessage());
		}

	}
	
	function handleError($frontErrorMessage) {
		$cart = $this->context->cart;
		$this->context->smarty->assign(
			array(
				'message' => 'Error',
				'logs' => array(
					$frontErrorMessage
				),
				'order' => array('id_order' => $cart->id),
				'price' => Tools::displayPrice($cart->getOrderTotal(), $this->context->currency),
			)
		);
		$this->setTemplate('error.tpl');
		return;
	}
}
