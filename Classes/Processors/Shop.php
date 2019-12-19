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

    public function __construct($api = null) {
        if (is_null($api))
            throw new \Exception('no api was initialized');
        else
            $this->api = $api;
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
            'delivery' => Session::getValue('delivery') ?? Session::getValue('billing')
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

    public function quantityCheck() {
        $settings = Session::getValue('settings');

        if (!isset($settings['minBasketSize']))
            return true;

        $card = Session::getValue('card');
        if (!$card)
            Redirect::internal('/warenkorb');

        $quantity = 0;
        foreach ($card as $item) {
            $quantity = $quantity + $item['quantity'];
        }

        if ($quantity < $settings['minBasketSize'])
            Redirect::internal('/warenkorb');

        return;
    }

    public function sendClientNotification($addedOrder) {

        if ($addedOrder && $addedOrder['number'])
            $order = $this->api->getOrder($addedOrder['id']);
        else
            return false;

        $customer = $this->api->getCustomer();

        $mail = new Mailer();
        $mail->setTemplate('OrderCreateClient.twig');
        $mail->setReceiver($order['client']['mail']);
        $mail->setSubject('Ihre Bestellung '.$order['number']);
        $mail->setData([
            'order' => $order,
            'customer' => $customer
        ]);
        $mail->loadShopAttachments();
        $send = $mail->send();

        $adminmail = new Mailer();
        $adminmail->setTemplate('OrderCreateClientNotification.twig');
        $adminmail->setSubject('Vinou-Bestellung '.$order['number']);
        $adminmail->setData([
            'order' => $order,
            'customer' => $customer
        ]);
        $send = $adminmail->send();

        $client = Session::getValue('client');
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
                'customer' => $customer
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
                'domain' => $_SERVER['SERVER_NAME']
            ]);
            return $mail->send();
        }

        return false;
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
                'domain' => $_SERVER['SERVER_NAME']
            ]);
            return $mail->send();
        }

        return false;
    }
}