<?php

/**
 * @file    Provides support for TargetPay iDEAL, Mister Cash and Sofort Banking ...
 * @author  Yellow Melon B.V.
 * @url     http://www.idealplugins.nl
 */
class Ps_TargetpaybwIntroModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    
    /**
     *
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        parent::initContent();
        
        if(session_status() == PHP_SESSION_NONE)
            session_start();
        
        if (empty($_SESSION['bw_info']))
            return false; // TODO return home
        
        $data = $_SESSION['bw_info'];

        $this->context->smarty->assign(array(
            'customer_email' => $data['customer_email'],
            'order_total' => $data['order_total'],
            'bw_info' => explode("|", $data['bw_data']) //list ($trxid, $accountNumber, $iban, $bic, $beneficiary, $bank) = explode("|", $data['bw_data']);
        ));
        
        $this->setTemplate('bwIntro.tpl');
    }
}
