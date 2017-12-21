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
        $option = Tools::getValue('option');
        $method = Tools::getValue('method');
        $test = Configuration::get('TARGETPAY_TESTMODE');
        $rtlo = Configuration::get('TARGETPAY_RTLO');
        $cart = $this->context->cart;
        $cartId = $cart->id;

        $amount = $cart->getOrderTotal();

        $targetpayObj = new TargetPayCore($method, $rtlo, "nl", $test);
        
        if ($option) {
            $targetpayObj->setBankId($option);
            $targetpayObj->setCountryId($option);
        }
        $targetpayObj->setAmount($amount * 100);
        $targetpayObj->setDescription('Cart id: ' . $cartId);
        $targetpayObj->setReturnUrl(Context::getContext()->link->getModuleLink('ps_targetpay', 'returnUrl'));
        $targetpayObj->setReportUrl(Context::getContext()->link->getModuleLink('ps_targetpay', 'notifyUrl'));
        
         if ($targetpayObj->getPayMethod() == 'AFP') {
            $this->additionalParametersAFP($cart, $targetpayObj); // add addtitional params for afterpay and bankwire
         }
         if ($targetpayObj->getPayMethod() == 'BW') {
            $this->additionalParametersBW($cart, $targetpayObj); // add addtitional params for afterpay and bankwire
         }

        $result = $targetpayObj->startPayment();
        if ($result) {
            $state = Configuration::get('PS_OS_CHEQUE');
            $ps_targetpay->validateOrder($cartId, $state, $amount, $ps_targetpay->displayName . "(" . $ps_targetpay->listMethods[$targetpayObj->getPayMethod()]['name'] . ")", null, array(
                "transaction_id" => $targetpayObj->getTransactionId()
            ), false, false, $cart->secure_key);
            
            if((int)$ps_targetpay->currentOrder > 0) {
                $sql = sprintf("INSERT INTO `" . _DB_PREFIX_ . "targetpay_ideal`
                        (`order_id`, `cart_id`, `paymethod`, `rtlo`, `transaction_id`, `description`, `amount`)
                        VALUES (%d, %d, '%s', %d, '%s', '%s', '%s')", $ps_targetpay->currentOrder, $cartId, $targetpayObj->getPayMethod(), $rtlo, $targetpayObj->getTransactionId(), $targetpayObj->getDescription(), $amount);

                Db::getInstance()->Execute($sql);
            }
            
            if($targetpayObj->getPayMethod() == 'BW') { //open an instruction page
                if(session_status() == PHP_SESSION_NONE) {
                    session_start();
                }
                
                $_SESSION['bw_info'] = [
                    'bw_data' => $targetpayObj->getMoreInformation(),
                    'order_total' => $amount,
                    'customer_email' => (new Customer(intval($cart->id_customer)))->email
                ];
                
                Tools::redirectLink('index.php?fc=module&module=ps_targetpay&controller=bwIntro');
            }
            Tools::redirectLink($result);
        } else {
            Tools::redirectLink('index.php?controller=order&step=3&targetpayerror=' . urldecode($targetpayObj->getErrorMessage()));
        }
    }

    /**
     * 
     * @param unknown $country
     * @param unknown $phone
     * @return unknown
     */
    private static function format_phone($country, $phone) {
        $function = 'format_phone_' . strtolower($country);
        if(method_exists('Ps_TargetpayPaymentModuleFrontController', $function)) {
            return self::$function($phone);
        }
        else {
            echo "unknown phone formatter for country: ". $function;
            exit;
        }
        return $phone;
    }
    
    /**
     * 
     * @param unknown $phone
     * @return string|mixed
     */
    private static function format_phone_nld($phone) {
        // note: making sure we have something
        if(!isset($phone{3})) { return ''; }
        // note: strip out everything but numbers
        $phone = preg_replace("/[^0-9]/", "", $phone);
        $length = strlen($phone);
        switch($length) {
            case 9:
                return "+31".$phone;
                break;
            case 10:
                return "+31".substr($phone, 1);
                break;
            case 11:
            case 12:
                return "+".$phone;
                break;
            default:
                return $phone;
                break;
        }
    }
    
    /**
     * 
     * @param unknown $phone
     * @return string|mixed
     */
    private static function format_phone_bel($phone) {
        // note: making sure we have something
        if(!isset($phone{3})) { return ''; }
        // note: strip out everything but numbers
        $phone = preg_replace("/[^0-9]/", "", $phone);
        $length = strlen($phone);
        switch($length) {
            case 9:
                return "+32".$phone;
                break;
            case 10:
                return "+32".substr($phone, 1);
                break;
            case 11:
            case 12:
                return "+".$phone;
                break;
            default:
                return $phone;
                break;
        }
    }
    
    /**
     * 
     * @param unknown $street
     * @return NULL[]|string[]|unknown[]
     */
    private static function breakDownStreet($street)
    {
        $out = [];
        $addressResult = null;
        preg_match("/(?P<address>\D+) (?P<number>\d+) (?P<numberAdd>.*)/", $street, $addressResult);
        if(!$addressResult) {
            preg_match("/(?P<address>\D+) (?P<number>\d+)/", $street, $addressResult);
        }
        $out['street'] = array_key_exists('address', $addressResult) ? $addressResult['address'] : null;
        $out['houseNumber'] = array_key_exists('number', $addressResult) ? $addressResult['number'] : null;
        $out['houseNumberAdd'] = array_key_exists('numberAdd', $addressResult) ? trim(strtoupper($addressResult['numberAdd'])) : null;
        return $out;
    }
    
    /**
     *
     * @param unknown $order            
     * @param TargetPayCore $targetPay            
     */
    function additionalParametersAFP($cart, TargetPayCore $targetPay)
    {
        $addr_delivery = new Address(intval($cart->id_address_delivery));
        $addr_invoice  = new Address(intval($cart->id_address_invoice));
        $customer = new Customer(intval($cart->id_customer));
        
        // Supported countries are: Netherlands (NLD) and in Belgium (BEL). Belgium = 3 | Netherlands = 13
        $invoiceCountry = ($addr_invoice->id_country) == 3 ? 'BEL' : 'NLD';
        $deliveryCountry = ($addr_delivery->id_country) == 3 ? 'BEL' : 'NLD';

        $streetParts = self::breakDownStreet($addr_invoice->address1);
        
        $targetPay->bindParam('billingstreet', $streetParts['street']);
        $targetPay->bindParam('billinghousenumber', $streetParts['houseNumber'].$streetParts['houseNumberAdd']);
        $targetPay->bindParam('billingpostalcode', $addr_invoice->postcode);
        $targetPay->bindParam('billingcity', $addr_invoice->city);
        $targetPay->bindParam('billingpersonemail', $customer->email);
        $targetPay->bindParam('billingpersoninitials', "");
        $targetPay->bindParam('billingpersongender', "");
        $targetPay->bindParam('billingpersonsurname', $addr_invoice->lastname);
        $targetPay->bindParam('billingcountrycode', $invoiceCountry);
        $targetPay->bindParam('billingpersonlanguagecode', $invoiceCountry);
        $targetPay->bindParam('billingpersonbirthdate', "");
        $targetPay->bindParam('billingpersonphonenumber', self::format_phone($invoiceCountry, $addr_invoice->phone));
        
        $streetParts = self::breakDownStreet($addr_delivery->address1);
        
        $targetPay->bindParam('shippingstreet', $streetParts['street']);
        $targetPay->bindParam('shippinghousenumber', $streetParts['houseNumber'].$streetParts['houseNumberAdd']);
        $targetPay->bindParam('shippingpostalcode', $addr_delivery->postcode);
        $targetPay->bindParam('shippingcity', $addr_delivery->city);
        $targetPay->bindParam('shippingpersonemail', $customer->email);
        $targetPay->bindParam('shippingpersoninitials', "");
        $targetPay->bindParam('shippingpersongender', "");
        $targetPay->bindParam('shippingpersonsurname', $addr_delivery->lastname);
        $targetPay->bindParam('shippingcountrycode', $deliveryCountry);
        $targetPay->bindParam('shippingpersonlanguagecode', $deliveryCountry);
        $targetPay->bindParam('shippingpersonbirthdate', "");
        $targetPay->bindParam('shippingpersonphonenumber', self::format_phone($deliveryCountry, $addr_delivery->phone));
        
        // Getting the items in the order
        $invoicelines = [];
        $total_amount_by_products = 0;
        
        // Iterating through each item in the order
        foreach ($cart->getProducts() as $product) {
            $total_amount_by_products += $product['total'];
            $invoicelines[] = [
                'productCode' => $product['id_product'],
                'productDescription' => $product['description_short'],
                'quantity' => $product['quantity'],
                'price' => $product['total'],   //Price without tax
                'taxCategory' => $targetPay->getTax($product['rate'])
            ];
        }
        $invoicelines[] = [
            'productCode' => '000000',
            'productDescription' => "Other fees (shipping, additional fees)",
            'quantity' => 1,
            'price' =>  $cart->getOrderTotal() - $total_amount_by_products,
            'taxCategory' => 3
        ];
        
        $targetPay->bindParam('invoicelines', json_encode($invoicelines));
        $targetPay->bindParam('userip', $_SERVER["REMOTE_ADDR"]);
    }

    /**
     *
     * @param unknown $order            
     * @param TargetPayCore $targetPay            
     */
    function additionalParametersBW($cart, TargetPayCore $targetPay)
    {
        $targetPay->bindParam('salt', $targetPay->bwSalt);
        $targetPay->bindParam('email', (new Customer(intval($cart->id_customer)))->email);
        $targetPay->bindParam('userip', $_SERVER["REMOTE_ADDR"]);
    }
}
