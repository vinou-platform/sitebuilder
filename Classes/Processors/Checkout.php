<?php
namespace Vinou\Utilities\General\Processors;

use \Vinou\ApiConnector\Api;
use \Vinou\ApiConnector\Session\Session;
use \Vinou\Utilities\General\Processors\Mailer;
use \Vinou\Utilities\General\Tools\Redirect;
use \Vinou\Utilities\General\Tools\Helper;

/**
 * Shop
 */
class Checkout {

    public static function loadBilling() {
        if (!empty($_POST) && isset($_POST['billing'])) {
            Session::setValue('billing',$_POST['billing']);

            if (isset($_POST['enabledelivery']) && (bool)$_POST['enabledelivery']) {
                if (isset($_POST['redirect']['delivery']))
                    Redirect::internal($_POST['redirect']['delivery']);
                else
                    throw new \Exception('no url was set for delivery as hidden input');
            }

            self::checkSubmitRedirect();
        }
        return Session::getValue('billing');
    }

    public static function loadDelivery() {
        if (!empty($_POST) && isset($_POST['delivery'])) {
            Session::setValue('delivery',$_POST['delivery']);

            self::checkSubmitRedirect();
        }
        return Session::getValue('delivery');
    }

    public static function loadPayment() {
        if (!empty($_POST) && isset($_POST['payment'])) {
            Session::setValue('payment',$_POST['payment']);

            self::checkSubmitRedirect();
        }
        return Session::getValue('payment');
    }

    public static function check() {
        if (!empty($_POST)) {
            if (!isset($_POST['cop']) || !isset($_POST['disclaimer']))
                exit();

            if (isset($_POST['note']))
                Session::setValue('note',$_POST['note']);

            self::checkSubmitRedirect();
        }
        return false;
    }

    public static function prepareSessionOrder() {
        $order = [
            'source' => 'shop',
            'payment_type' => Session::getValue('payment'),
            'basket' => Session::getValue('basket'),
            'billing_type' => 'client',
            'billing' => Session::getValue('billing'),
            'delivery_type' => 'address',
            'delivery' => Session::getValue('delivery') ?? Session::getValue('billing')
        ];

        $note = Session::getValue('note');
        if ($note) $order['note'] = $note;

        Session::setValue('order',$order);
        return $order;
    }

    public static function removeSessionData() {
        Session::deleteValue('payment');
        Session::deleteValue('basket');
        Session::deleteValue('billing');
        Session::deleteValue('delivery');
        Session::deleteValue('note');
        Session::deleteValue('order');
    }

    public static function saveOrderJSON() {
        self::checkFolders();

        $folder = strftime('Orders/%Y/%m/%d');
        self::checkFolders($folder);

        $filename = strftime('%H-%M-').Session::getValue('basket').'.json';
        file_put_contents(Helper::getNormDocRoot().$folder.'/'.$filename, json_encode(Session::getValue('order')));
    }

    public static function checkFolders($folder = 'Orders') {

        $orderDir = Helper::getNormDocRoot() . $folder;

        if (!is_dir($orderDir))
            mkdir($orderDir, 0777, true);

        $htaccess = $orderDir .'/.htaccess';
        if (!is_file($htaccess)) {
            $content = 'Deny from all';
            file_put_contents($htaccess, $content);
        }
    }

    public static function checkSubmitRedirect() {
        if (isset($_POST['submitted']) && (bool)$_POST['submitted'] && isset($_POST['redirect']['standard']))
            Redirect::internal($_POST['redirect']['standard']);
    }

    public static function clientMail() {
        $mail = new Mailer();
        $mail->setTemplate('OrderCreateClient.twig');
        $mail->setFrom('noreply@vinou.de','Vinou - Connected Winebusiness');
        $mail->setReceiver('christian@christianhaendel.de');
        $mail->setSubject('Ihre Bestellung');
        $mail->setData([
            'order' => Session::getValue('order'),
            'card' => Session::getValue('card')
        ]);
        return $mail->send();
    }

    public static function testmail() {
        $mail = new Mailer();
        $mail->setTemplate('OrderCreateClient.twig');
        $mail->setFrom('noreply@vinou.de','Vinou - Connected Winebusiness');
        $mail->setReceiver('christian@christianhaendel.de');
        $mail->setSubject('Testmail');
        $mail->setData(Session::getValue('order'));
        return $mail->send();
    }
}