<?php
namespace Vinou\SiteBuilder\Processors;

use \Vinou\ApiConnector\Api;
use \Vinou\ApiConnector\Session\Session;
use \Vinou\ApiConnector\Tools\Helper;
use \Vinou\ApiConnector\Tools\Redirect;
use \Vinou\SiteBuilder\Processors\Mailer;

/**
 * Shop
 */
class Shop {

    protected $api;
    protected $settings = false;
    protected $client = false;

    public function __construct($api = null) {
        if (is_null($api))
            throw new \Exception('no api was initialized');
        else
            $this->api = $api;

        $this->loadSettings();
        $this->loadClient();
    }

    public function loadSettings() {
        $this->settings = Session::getValue('settings');
    }

    public function loadClient() {
        $this->client = Session::getValue('client');
    }

    public function loadBilling() {
        if (!empty($_POST) && isset($_POST['billing'])) {
            Session::setValue('billing',$_POST['billing']);

            if (isset($_POST['enabledelivery']) && (bool)$_POST['enabledelivery']) {
                if (isset($_POST['redirect']['delivery']))
                    Redirect::internal($_POST['redirect']['delivery']);
                else
                    throw new \Exception('no url was set for delivery as hidden input');
            }

            $this->checkSubmitRedirect();
        }
        return Session::getValue('billing');
    }

    public function loadDelivery() {
        if (!empty($_POST) && isset($_POST['delivery'])) {
            Session::setValue('delivery',$_POST['delivery']);

            $this->checkSubmitRedirect();
        }
        return Session::getValue('delivery');
    }

    public function loadPayment() {
        if (!empty($_POST) && isset($_POST['payment'])) {
            Session::setValue('payment',$_POST['payment']);

            $this->checkSubmitRedirect();
        }
        return Session::getValue('payment');
    }

    public function check() {
        if (!empty($_POST)) {
            if (!isset($_POST['cop']) || !isset($_POST['disclaimer']))
                exit();

            if (isset($_POST['note']))
                Session::setValue('note',$_POST['note']);

            $this->checkSubmitRedirect();
        }
        return false;
    }

    public function prepareSessionOrder() {
        $order = [
            'source' => 'shop',
            'payment_type' => Session::getValue('payment'),
            'basket' => Session::getValue('basket'),
            'billing_type' => 'client',
            'billing' => Session::getValue('billing'),
            'delivery_type' => 'address',
            'delivery' => Session::getValue('delivery') ?? Session::getValue('billing'),
            'invoice_type' => isset($this->settings['checkout']['invoice_type']) ? $this->settings['checkout']['invoice_type'] : 'gross',
            'payment_period' => isset($this->settings['checkout']['payment_period']) ? (int)$this->settings['checkout']['payment_period'] : 14
        ];

        if ($order['payment_type'] == 'paypal') {
            $order['return_url'] = Helper::getCurrentHost() . '/checkout/paypal/finish';
            $order['cancel_url'] = Helper::getCurrentHost() . '/checkout/paypal/cancel';
        }

        $note = Session::getValue('note');
        if ($note) $order['note'] = $note;

        Session::setValue('order',$order);
        return $order;
    }

    public function removeSessionData($status) {

        if ($status) {
            Session::deleteValue('payment');
            Session::deleteValue('basket');
            Session::deleteValue('card');
            Session::deleteValue('billing');
            Session::deleteValue('delivery');
            Session::deleteValue('note');
            Session::deleteValue('order');
        }
        return $status;
    }

    public function saveOrderJSON() {
        $this->checkFolders();

        $folder = strftime('Orders/%Y/%m/%d');
        $this->checkFolders($folder);

        $filename = strftime('%H-%M-').Session::getValue('basket').'.json';
        $formattedOrder = $this->prepareOrderToSend();
        file_put_contents(Helper::getNormDocRoot().$folder.'/'.$filename, json_encode($formattedOrder));
        return $formattedOrder;
    }

    public function prepareOrderToSend() {
        $order = Session::getValue('order');
        $client = Session::getValue('client');
        if ($client) {
            $clientId = $client['id'];
            $order['client_id'] = $clientId;

            $compareFields = [
                'first_name' => 'firstname',
                'last_name' => 'lastname',
                'company' => 'company',
                'address' => 'address',
                'zip' => 'zip',
                'city' => 'city'
            ];

            //compare billing;
            $billingMatch = true;
            foreach ($compareFields as $source => $target) {
                if (isset($order['billing'][$target]) && strcmp($client[$source],$order['billing'][$target]) !== 0) {
                    $billingMatch = false;
                    $order['billing_type'] = 'address';
                    break;
                }
            }
            if ($billingMatch) {
                $order['billing_type'] = 'client';
                $order['billing_id'] = $clientId;
                unset($order['billing']);
            }

            $deliveryMatch = true;
            foreach ($compareFields as $source => $target) {
                if (isset($order['delivery'][$target]) && strcmp($client[$source],$order['delivery'][$target]) !== 0) {
                    $deliveryMatch = false;
                    $order['delivery_type'] = 'address';
                    break;
                }
            }

            if ($deliveryMatch) {
                $order['delivery_type'] = 'client';
                $order['delivery_id'] = $clientId;
                unset($order['delivery']);
            }
        } else {
            $check = $this->api->checkClientMail($order['billing']);
            if ($check) {
                $order['client_id'] = $check;
                $order['billing_type'] = 'address';
            } else {
                unset($order['billing_type']);
            }

            unset($order['delivery_type']);
            if (!$order['delivery']) {
                $order['delivery'] = $order['billing'];
            }
        }
        return $order;
    }

    public function checkFolders($folder = 'Orders') {

        $orderDir = Helper::getNormDocRoot() . $folder;

        if (!is_dir($orderDir))
            mkdir($orderDir, 0777, true);

        $htaccess = $orderDir .'/.htaccess';
        if (!is_file($htaccess)) {
            $content = 'Deny from all';
            file_put_contents($htaccess, $content);
        }
    }

    public function checkSubmitRedirect() {
        if (isset($_POST['submitted']) && (bool)$_POST['submitted'] && isset($_POST['redirect']['standard']))
            Redirect::internal($_POST['redirect']['standard']);
    }

    public function validateBasket() {
        if (isset($this->settings['minBasketSize'])) {
            $card = Session::getValue('card');
            if (!$card)
                Redirect::internal($this->settings['pages']['basket']);

            $quantity = 0;
            foreach ($card as $item) {
                $quantity = $quantity + $item['quantity'];
            }

            if ($quantity < $this->settings['minBasketSize'])
                Redirect::internal($this->settings['pages']['basket']);
        }
        return true;
    }

    public function validateBilling() {
        $billing = Session::getValue('billing');
        if (!$billing)
            Redirect::internal($this->settings['pages']['billing']);

        return true;
    }

    public function validatePayment() {
        $payment = Session::getValue('payment');

        $allowedPayments = $this->client && isset($this->settings['clientPayments']) ? $this->settings['clientPayments'] : $this->settings['allowedPayments'];

        if (is_string($allowedPayments));
            $allowedPayments = explode(',', preg_replace('/\s/', '', $allowedPayments));

        if (!in_array($payment, $allowedPayments))
            Redirect::internal($this->settings['pages']['payment']);

        return true;
    }

    public function validateOrder() {

        // Validate Basket
        $this->validateBasket();

        // Validate Billing
        $this->validateBilling();

        // Check if payment is allowed
        $this->validatePayment();

        return true;
    }

    public function sendClientNotification($addedOrder) {

        if ($addedOrder && $addedOrder['number'])
            $order = $this->api->getOrder($addedOrder['id']);
        else
            return false;

        $customer = $this->api->getCustomer();
        $client = $this->api->getClient();

        $mail = new Mailer();
        $mail->setTemplate('OrderCreateClient.twig');
        $mail->setReceiver($order['client']['mail']);
        $mail->setSubject('Ihre Bestellung '.$order['number']);
        $mail->setData([
            'order' => $order,
            'client' => $client,            
            'customer' => $customer,
            'settings' => $this->settings
        ]);
        $mail->loadShopAttachments();
        $send = $mail->send();

        $adminmail = new Mailer();
        $adminmail->setTemplate('OrderCreateClientNotification.twig');
        $adminmail->setSubject('Vinou-Bestellung '.$order['number']);
        $adminmail->setData([
            'order' => $order,
            'client' => $client,
            'customer' => $customer,
            'settings' => $this->settings
        ]);
        $send = $adminmail->send();
        
        if (!$client) {
            $client = $order['client'];
            $pwreset = $this->api->getPasswordHash(['mail' => $order['client']['mail']]);

            if (isset($pwreset['hash']))
                $client['lostpassword_hash'] = $pwreset['hash'];

            if (isset($pwreset['expire']))
                $client['lostpassword_expire'] = $pwreset['expire'];

            $accountmail = new Mailer();
            $accountmail->setTemplate('NewAccountByOrder.twig');
            $accountmail->setReceiver($order['client']['mail']);
            $accountmail->setSubject('Dein Account auf '.$_SERVER['SERVER_NAME']);
            $accountmail->setData([
                'client' => $client,
                'domain' => $_SERVER['SERVER_NAME'],
                'customer' => $customer,
                'settings' => $this->settings
            ]);
            $accountsend = $accountmail->send();
        }

        return $send;
    }

    public function sendClientRegisterMail($data = NULL) {

        if (isset($data['lostpassword_hash']) && isset($data['mail'])) {
            $mail = new Mailer();
            $mail->setTemplate('ClientRegistration.twig');
            $mail->setReceiver($data['mail']);
            $mail->setSubject('Bestätige Deine Registrierung auf '.$_SERVER['SERVER_NAME']);
            $mail->setData([
                'client' => $data,
                'customer' => $this->api->getCustomer(),
                'domain' => $_SERVER['SERVER_NAME'],
                'settings' => $this->settings
            ]);
            return $mail->send();
        }

        return false;
    }

    public function sendClientApprovementMail($data = NULL) {

        if (!isset($this->settings['registration']['approvementEmail']))
            throw new Exception("no approvementEmail defined in settings", 1);

        if (isset($data['lostpassword_hash']) && isset($data['mail'])) {
            $adminMail = new Mailer();
            $adminMail->setTemplate('ClientApprovement.twig');
            $adminMail->setReceiver($this->settings['registration']['approvementEmail']);
            $adminMail->setSubject('Neue Registrierung auf '.$_SERVER['SERVER_NAME']);
            $adminMail->setData([
                'client' => $data,
                'customer' => $this->api->getCustomer(),
                'domain' => $_SERVER['SERVER_NAME'],
                'settings' => $this->settings
            ]);

            $clientMail = new Mailer();
            $clientMail->setTemplate('ClientApprovementNotification.twig');
            $clientMail->setReceiver($data['mail']);
            $clientMail->setSubject('Dein Account auf '.$_SERVER['SERVER_NAME']);
            $clientMail->setData([
                'client' => $data,
                'customer' => $this->api->getCustomer(),
                'domain' => $_SERVER['SERVER_NAME'],
                'settings' => $this->settings
            ]);

            return $adminMail->send() && $clientMail->send();
        }

        return false;
    }

    public function sendClientActivationNotification($data = NULL) {

        if (!isset($data['mail']))
            return false;

        $mail = new Mailer();
        $mail->setTemplate('ClientActivationNotification.twig');
        $mail->setReceiver($data['mail']);
        $mail->setSubject('Account aktiviert auf '.$_SERVER['SERVER_NAME']);
        $mail->setData([
            'customer' => $this->api->getCustomer(),
            'domain' => $_SERVER['SERVER_NAME'],
            'settings' => $this->settings
        ]);

        return $mail->send();

    }

    public function sendClientDeclinationNotification($data = NULL) {

        if (!isset($data['mail']))
            return false;

        $mail = new Mailer();
        $mail->setTemplate('ClientDeclinationNotification.twig');
        $mail->setReceiver($data['mail']);
        $mail->setSubject('Account abgelehnt auf '.$_SERVER['SERVER_NAME']);
        $mail->setData([
            'customer' => $this->api->getCustomer(),
            'domain' => $_SERVER['SERVER_NAME'],
            'settings' => $this->settings
        ]);

        return $mail->send();

    }

    public function sendPasswordResetMail($data = NULL) {

        if (isset($data['hash']) && isset($data['mail'])) {
            $mail = new Mailer();
            $mail->setTemplate('PasswordReset.twig');
            $mail->setReceiver($data['mail']);
            $mail->setSubject('Dein Passwort wurde zurückgesetzt '.$_SERVER['SERVER_NAME']);
            $mail->setData([
                'client' => $data,
                'customer' => $this->api->getCustomer(),
                'domain' => $_SERVER['SERVER_NAME'],
                'settings' => $this->settings
            ]);
            return $mail->send();
        }

        return false;
    }
}