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
        $ps_targetpay = $this->module;
        $trxid = Tools::getValue('trxid');
        $transactionInfoArr = $ps_targetpay->selectTransaction($trxid);
        if ($transactionInfoArr === false) {
            Tools::redirect(_PS_BASE_URL_);
            exit();
        } else {
            $ps_targetpay->updateOrderAfterCheck($transactionInfoArr, 'return');
        }
        
        $order = new Order((int) $transactionInfoArr['order_id']);
        // redirect to confirm page to show the result
        Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $order->id_cart . '&id_module=' . $ps_targetpay->id . '&id_order=' . $order->id . '&key=' . $order->secure_key);
    }
}
