<?php

/**
 * @file    Provides support for TargetPay iDEAL, Mister Cash and Sofort Banking ...
 * @author  Yellow Melon B.V.
 * @url     http://www.idealplugins.nl
 *
 * 11-09-2014 -> Removed checkReportValidity
 * 14-01-2015 -> Secure key added
 * 11-01-2017 -> Apply new logic
 */
class Ps_TargetpaynotifyUrlModuleFrontController extends ModuleFrontController
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
        if(empty($trxid)) { //paypal use paypalid instead of trxid
            $trxid = Tools::getValue('acquirerID');
        }
        if(empty($trxid)) { //afterpay use invoiceID instead of trxid
            $trxid = Tools::getValue('invoiceID');
        }
        
        $transactionInfoArr = $ps_targetpay->selectTransaction($trxid);
        if ($transactionInfoArr) {
            $return = $ps_targetpay->updateOrderAfterCheck($transactionInfoArr);
            echo $return . "<br />";
            die('Done version 1.6.xx');
        }
        die("Transaction is not found");
    }
}
