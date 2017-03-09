<?php

/**
 * @file    Provides support for TargetPay iDEAL, Mister Cash and Sofort Banking ...
 * @author  Yellow Melon B.V.
 * @url     http://www.idealplugins.nl
 */
class Ps_TargetpayPaymentModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /**
     *
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        $ps_targetpay = $this->module;
        $bankID = Tools::getValue('bankID');
        $test = Configuration::get('TARGETPAY_TESTMODE');
        $rtlo = Configuration::get('TARGETPAY_RTLO');
        $cart = $this->context->cart;
        $cartId = $cart->id;
        $amount = $cart->getOrderTotal();
        $targetpayObj = new TargetPayCore("AUTO", $rtlo, $ps_targetpay->appId, "nl", $test);
        
        $targetpayObj->setBankId($bankID);
        $targetpayObj->setAmount($amount * 100);
        $targetpayObj->setDescription('Cart id: ' . $cartId);
        
        $returnUrl = Context::getContext()->link->getModuleLink('ps_targetpay', 'returnUrl');
        $targetpayObj->setReturnUrl($returnUrl);
        $reportUrl = Context::getContext()->link->getModuleLink('ps_targetpay', 'notifyUrl');
        $targetpayObj->setReportUrl($reportUrl);
        $result = @$targetpayObj->startPayment();
        
        if ($result !== false) {
            $state = Configuration::get('PS_OS_CHEQUE');
            $ps_targetpay->validateOrder($cartId, $state, $amount, $ps_targetpay->displayName . "(" . $ps_targetpay->listMethods[$targetpayObj->getPayMethod()]['name'] . ")", null, array(
                "transaction_id" => $targetpayObj->getTransactionId()
            ), false, false, $cart->secure_key);
            $sql = sprintf("INSERT INTO `" . _DB_PREFIX_ . "targetpay_ideal`
                    (`order_id`, `cart_id`, `paymethod`, `rtlo`, `transaction_id`, `bank_id`, `description`, `amount`, `status`, `via`)
                    VALUES (%d, %d, '%s', %d, '%s', '%s', '%s', '%s', %d, '%s')", $ps_targetpay->currentOrder, $cartId, $targetpayObj->getPayMethod(), $rtlo, $targetpayObj->getTransactionId(), $targetpayObj->getBankId(), $targetpayObj->getDescription(), $amount, 0, 'payment');
            
            Db::getInstance()->Execute($sql);
            Tools::redirectLink($result);
        } else {
            Tools::redirectLink('index.php?controller=order&step=3&targetpayerror=' . urldecode($targetpayObj->getErrorMessage()));
        }
    }
}
