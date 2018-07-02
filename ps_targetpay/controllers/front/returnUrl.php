<?php

/**
 * @file    Provides support for TargetPay iDEAL, Mister Cash and Sofort Banking ...
 * @author  Yellow Melon B.V.
 * @url     http://www.idealplugins.nl
 */
class Ps_TargetpayreturnUrlModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    
    /**
     *
     * @see FrontController::initContent()
     */
    public function initContent()
    {        
        $retMsg = null;
        $ps_targetpay = $this->module;
        $trxid = Tools::getValue('trxid');
        if(empty($trxid)) { //paypal use paypalid instead of trxid
            $trxid = Tools::getValue('paypalid');
        }
        if(empty($trxid)) { //afterpay use invoiceID instead of trxid
            $trxid = Tools::getValue('invoiceID');
        }
        $transactionInfoArr = $ps_targetpay->selectTransaction($trxid);
        if ($transactionInfoArr === false) {
            Tools::redirect(_PS_BASE_URL_);
            exit();
        }
        
        if ($transactionInfoArr) {
            $retMsg = $ps_targetpay->updateOrderAfterCheck($transactionInfoArr);
        }
        
        $order = new Order((int) $transactionInfoArr['order_id']);
        if ($order->current_state == Configuration::get('PS_OS_ERROR')) {
            $opc = (bool) Configuration::get('PS_ORDER_PROCESS_TYPE');
            if ($opc) {
                $link = 'index.php?controller=order-opc&targetpayerror=' . urldecode($retMsg);
            } else {
                $link = 'index.php?controller=order&step=3&targetpayerror=' . urldecode($retMsg);
            }
            Tools::redirect($link);
        } else {
            //clear cart
            $ps_targetpay->removeCart();
            // redirect to confirm page to show the result
            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $order->id_cart . '&id_module=' . $ps_targetpay->id . '&id_order=' . $order->id . '&key=' . $order->secure_key);
        }
    }
}
