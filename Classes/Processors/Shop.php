<?php
namespace Vinou\SiteBuilder\Processors;

use Exception;
use \Vinou\ApiConnector\Api;
use \Vinou\ApiConnector\Session\Session;
use \Vinou\ApiConnector\Services\ServiceLocator;
use \Vinou\ApiConnector\Tools\Helper;
use \Vinou\ApiConnector\Tools\Redirect;
use \Vinou\SiteBuilder\Processors\Mailer;

/**
 * Shop
 */
class Shop {

	protected $api;
	protected $settingsService = null;
	protected $settings = false;
	protected $formalSpeech = false;
	protected $client = false;
	protected $emailDelivery = true;

	public function __construct($api = null) {
		if (is_null($api))
			throw new \Exception('no api was initialized');
		else
			$this->api = $api;

		$this->settingsService = ServiceLocator::get('Settings');
		$this->settings = $this->settingsService->get('settings');

		$this->formalSpeech = array_key_exists('speechStyle', $this->settings) && $this->settings['speechStyle'] == 'formal';

		// Check if email delivery through the site builder is disabled. E-mails are sent via the API.
		$this->emailDelivery = !(array_key_exists('emailDelivery', $this->settings) && !$this->settings['emailDelivery']);

		$this->client = Session::getValue('client');
	}

	public function loadCampaign() {
		return Session::getValue('campaign');
	}

	public function calcSum($data = null) {

		$sum = [
			'net' => 0,
			'tax' => 0,
			'gross' => 0,
			'quantity' => 0
		];

		if (!is_array($data))
			return $sum;

		$items = isset($data['basketItems']) ? $data['basketItems'] : $data;

		foreach ($items as $item) {

			$quantity = $item['quantity'];
			$priceObject = $item['item'];
			if ($this->client && isset($item['item']['prices'][0]))
				$priceObject = $item['item']['prices'][0];

			$sum['net'] += $priceObject['net'] * $quantity;
			$sum['tax'] += $priceObject['tax'] * $quantity;
			$sum['gross'] += $priceObject['gross'] * $quantity;
			$sum['quantity'] += $quantity;
		}

		return $sum;
	}

	public function arrangePositions($data = null) {

		if (is_null($data))
			return false;

		$items = [];

		if (isset($data['basket']) && count($data['basket']['basketItems']) > 0)
			$items = array_merge($items, $data['basket']['basketItems']);

		if (isset($data['package']) && !empty($data['package']))
			array_push($items, [
				'item_type' => 'package',
				'item_id' => $data['package']['id'],
				'quantity' => 1,
				'item' => $data['package']
			]);

		return $items;
	}

	public function campaignDiscount($data = null) {
		$items = [];

		if (!isset($data['campaign']))
			return false;

		if (isset($data['basket']) && count($data['basket']['basketItems']) > 0) {
			foreach ($data['basket']['basketItems'] as &$item) {
				$position = [
					'item_type' => $item['item_type'],
					'item_id' => $item['item_id'],
					'quantity' => $item['quantity'],
					'gross' => $item['quantity'] * $item['item']['gross'],
					'net' => $item['quantity'] * $item['item']['net'],
					'taxrate' => $item['item']['taxrate']
				];
				$position['tax'] = $position['gross'] - $position['net'];
				array_push($items, $position);
			}
		}
		if (isset($data['package'])) {
			$package = $data['package'];
			$position = [
				'item_type' => 'package',
				'item_id' => $package['id'],
				'quantity' => 1,
				'gross' => $package['gross'],
				'tax' => $package['tax'],
				'net' => $package['net'],
				'taxrate' => $package['taxrate']
			];
			array_push($items, $position);
		}

		$result = $this->api->findCampaign([
			'hash' => $data['campaign']['hash'],
			'items' => $items
		]);

		if (!isset($result['summary']))
			return false;

		Session::setValue('campaignDiscount', $result['summary']);
		return $result['summary'];
	}

	public function loadBilling() {
		$billing = Session::getValue('billing');

		if (!$billing)
			$billing = [];

		if (empty($_POST))
			return $billing;

		if (isset($_POST['newsletter']) && $_POST['newsletter'] == 1)
			$billing['newsletter'] = 1;

		if (isset($_POST['billing']))
			$billing = array_merge($billing, $_POST['billing']);

		Session::setValue('billing',$billing);

		if (isset($_POST['enabledelivery']) && (bool)$_POST['enabledelivery']) {
			if (isset($_POST['redirect']['delivery']))
				Redirect::internal($_POST['redirect']['delivery']);
			else
				throw new \Exception('no url was set for delivery as hidden input');
		}

		$this->checkSubmitRedirect('billing');

		return $billing;

	}

	public function loadDeliveryType() {
		$type = Session::getValue('delivery_type');
		if (!$type)
			$type = 'address';

		if (empty($_POST))
			return $type;

		if (isset($_POST['delivery_type']))
			$type = $_POST['delivery_type'];

		Session::setValue('delivery_type', $type);
		return $type;
	}

	public function loadDelivery() {
		$type = Session::getValue('delivery_type');
		if ($type == 'none')
			return null;

		$delivery = Session::getValue('delivery');
		if (!$delivery)
			$delivery = [];

		if (empty($_POST))
			return $delivery;

		if (isset($_POST['delivery']))
			$delivery = array_merge($delivery, $_POST['delivery']);

		Session::setValue('delivery',$delivery);

		$this->checkSubmitRedirect('delivery');

		return $delivery;
	}

	public function loadPayment() {
		if (!empty($_POST) && isset($_POST['payment'])) {
			Session::setValue('payment',$_POST['payment']);

			$this->checkSubmitRedirect();
		}
		return Session::getValue('payment');
	}

	public function initPaymentByPage() {
		$paymentType = Session::getValue('payment');
		$pages = $this->settings['pages'];
		if (array_key_exists($paymentType, $pages) && array_key_exists('init', $pages[$paymentType]))
			Redirect::internal($pages[$paymentType]['init']);
		return true;
	}

	public function loadStripeData() {
		return Session::getValue('stripe');
	}

	public function initPaypalPayment() {
		$paypal = Session::getValue('paypal');
		if($paypal)
		Redirect::external($paypal);
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

		$campaign = Session::getValue('campaign');
		if ($campaign && $campaign['id'] > 0) {
			$this->api->addItemToBasket(['data' => [
				'quantity' => 1,
				'item_type' => 'campaign',
				'item_id' => $campaign['id']
			]]);
		}

		$order = [
			'source' => 'shop',
			'payment_type' => Session::getValue('payment'),
			'basket' => Session::getValue('basket'),
			'billing_type' => 'client',
			'billing' => Session::getValue('billing'),
			'delivery_type' => Session::getValue('delivery_type'),
			'invoice_type' => isset($this->settings['checkout']['invoice_type']) ? $this->settings['checkout']['invoice_type'] : 'gross',
			'payment_period' => isset($this->settings['checkout']['payment_period']) ? (int)$this->settings['checkout']['payment_period'] : 14
		];

		if ($order['delivery_type'] != 'none')
			$order['delivery'] = Session::getValue('delivery') ?? Session::getValue('billing');

		// Specific order settings dependending on payment_type
		switch ($order['payment_type']) {
			case 'prepaid':
				$order['payment_period'] = 1;
				break;

			case 'paypal':
				$order['return_url'] = Helper::getCurrentHost() . $this->settings['pages']['paypal']['finish'];
				$order['cancel_url'] = Helper::getCurrentHost() . $this->settings['pages']['paypal']['cancel'];
				break;

			case 'card':
				$order['return_url'] = Helper::getCurrentHost() . $this->settings['pages']['card']['finish'];
				$order['cancel_url'] = Helper::getCurrentHost() . $this->settings['pages']['card']['cancel'];
				break;

			case 'debit':
				$order['return_url'] = Helper::getCurrentHost() . $this->settings['pages']['debit']['finish'];
				$order['cancel_url'] = Helper::getCurrentHost() . $this->settings['pages']['debit']['cancel'];
				break;

			default:
				break;
		}

		$note = Session::getValue('note');
		if ($note) $order['note'] = $note;

		Session::setValue('order',$order);
		return $order;
	}

	public function removeSessionData($status) {
		if ($status) {
			Session::deleteValue('paypal');
			Session::deleteValue('payment');
			Session::deleteValue('basket');
			Session::deleteValue('card');
			Session::deleteValue('billing');
			Session::deleteValue('delivery');
			Session::deleteValue('delivery_type');
			Session::deleteValue('campaign');
			Session::deleteValue('note');
			Session::deleteValue('order');
		}
		return $status;
	}

	public function saveOrderJSON() {
		$this->checkFolders();

		$folder = 'Orders/' . date('Y/m/d');
		$this->checkFolders($folder);

		$filename = date('h-i-') . Session::getValue('basket').'.json';
		$formattedOrder = $this->prepareOrderToSend();
		file_put_contents(Helper::getNormDocRoot().$folder.'/'.$filename, json_encode($formattedOrder));
		return $formattedOrder;
	}

	public function prepareOrderToSend() {
		$order = Session::getValue('order');
		$client = Session::getValue('client');

		$order['session'] = [
			'billing' => $order['billing'],
			'delivery' => $order['delivery'],
			'basket' => Session::getValue('basket'),
			'card' => Session::getValue('card'),
			'payment' => Session::getValue('payment'),
			'campaign' => Session::getValue('campaign'),
			'note' => Session::getValue('note')
		];

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

			if ($order['delivery_type'] != 'none') {
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
			}
		} else {
			$check = $this->api->checkClientMail($order['billing']);
			if ($check) {
				$order['client_id'] = $check;
				$order['billing_type'] = 'address';
			} else {
				unset($order['billing_type']);
			}

			if ($order['delivery_type'] != 'none') {
				unset($order['delivery_type']);
				if (!$order['delivery'])
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

	public function checkSubmitRedirect($checkPostField = false) {

		if (empty($_POST))
			return false;

		if ($checkPostField && !isset($_POST[$checkPostField]))
			return false;

		if (isset($_POST['submitted']) && (bool)$_POST['submitted'] && isset($_POST['redirect']['standard']))
			Redirect::internal($_POST['redirect']['standard']);

		return false;
	}

	public function validateBasket() {
		$card = Session::getValue('card');
		if (!$card)
			Redirect::internal($this->settings['pages']['basket']);

		if (isset($this->settings['basketPerWinery']) && $this->settings['basketPerWinery'] !== false) {
			if (!self::quantityByWineryIsAllowed($card))
			Redirect::internal($this->settings['pages']['basket']);
		} else {
			$quantity = self::calcCardQuantity($card);
			if (!self::quantityIsAllowed($quantity))
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
	public function getPreviousOrderInProgress(){
		$order = $this->api->getSessionOrder();
		if ($order
		&& $order['status'] != 'cancelled'
		&& $order['status'] != 'need_package'
		&& $this->isTemporaryPaymentMethod($order['payment_type'])
		)
		return $order;
		return false;
	}
	private function isTemporaryPaymentMethod($method){
		$temporaryPaymentMethods = ['card', 'debit', 'paypal'];
		if (in_array($method, $temporaryPaymentMethods))
			return true;
		return false;
	}
	public function sendClientNotification($addedOrder) {

		if (!$addedOrder || !isset($addedOrder['number']))
			return false;

		if ($this->isTemporaryPaymentMethod($addedOrder['payment_type']) && $addedOrder['status'] == "new")
			return false;

		if (!$this->emailDelivery)
			return true;

		// @deprecated
		// use email notification from vinou api instead

		$order = $this->api->getOrder($addedOrder['id']);

		$customer = $this->api->getCustomer();
		$client = $this->api->getClient();


		$sessionOrder = Session::getValue('order');
		$data = [
			'order' => $order,
			'client' => $client,
			'customer' => $customer,
			'settings' => $this->settings,
			'session' => [
				'billing' => $sessionOrder['billing'],
				'delivery' => $sessionOrder['delivery'],
			]
		];
		if ($customer['type'] == 'merchant')
			$data['wineries'] = $this->api->getWineriesAll();

		$subject = $this->formalSpeech ? 'Ihre Bestellung ' : 'Deine Bestellung ';
		$subject .= $order['number'];
		$subjectSuffix = $order['delivery_type'] == 'none' ? ' (Click & Collect)' : ' (Lieferung)';

		$mail = new Mailer($this->api);
		$mail->setTemplate('OrderCreateClient.twig');
		$mail->setReceiver($order['client']['mail']);
		$mail->setSubject($subject . $subjectSuffix);
		$mail->setData($data);
		$mail->loadShopAttachments();
		$send = $mail->send();

		$adminmail = new Mailer($this->api);
		$adminmail->setTemplate('OrderCreateClientNotification.twig');
		$adminmail->setSubject('Bestellung '. $order['number'] . $subjectSuffix);
		$adminmail->setData($data);
		$send = $adminmail->send();
		// // 2020-10-19 Deactivated due to gdpr reasons
		// if (!$client) {
		//	 $client = $order['client'];
		//	 $pwreset = $this->api->getPasswordHash(['mail' => $order['client']['mail']]);

		//	 if (isset($pwreset['hash']))
		//		 $client['lostpassword_hash'] = $pwreset['hash'];

		//	 if (isset($pwreset['expire']))
		//		 $client['lostpassword_expire'] = $pwreset['expire'];

		//	 $accountmail = new Mailer();
		//	 $accountmail->setTemplate('NewAccountByOrder.twig');
		//	 $accountmail->setReceiver($order['client']['mail']);
		//	 $accountmail->setSubject('Dein Account auf '.$_SERVER['SERVER_NAME']);
		//	 $accountmail->setData([
		//		 'client' => $client,
		//		 'customer' => $customer,
		//		 'settings' => $this->settings
		//	 ]);
		//	 $accountsend = $accountmail->send();
		// }

		return $send;
	}

	public function sendClientRegisterMail($data = NULL) {

		if (isset($data['lostpassword_hash']) && isset($data['mail'])) {

			$subject = $this->formalSpeech ? 'Bestätigen Sie Ihre Registrierung auf ' : 'Bestätige Deine Registrierung auf ';
			$subject .= $_SERVER['SERVER_NAME'];

			$mail = new Mailer();
			$mail->setTemplate('ClientRegistration.twig');
			$mail->setReceiver($data['mail']);
			$mail->setSubject($subject);
			$mail->setData([
				'client' => $data,
				'customer' => $this->api->getCustomer(),
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
				'settings' => $this->settings
			]);

			$subject = $this->formalSpeech ? 'Ihr Account auf' : 'Dein Account auf ';
			$subject .= $_SERVER['SERVER_NAME'];

			$clientMail = new Mailer();
			$clientMail->setTemplate('ClientApprovementNotification.twig');
			$clientMail->setReceiver($data['mail']);
			$clientMail->setSubject($subject);
			$clientMail->setData([
				'client' => $data,
				'customer' => $this->api->getCustomer(),
				'settings' => $this->settings
			]);

			return $adminMail->send() && $clientMail->send();
		}

		return false;
	}

	public function sendClientActivationNotification($data = NULL) {

		if (!isset($data['mail']) || isset($data['error']))
			return false;

		$mail = new Mailer();
		$mail->setTemplate('ClientActivationNotification.twig');
		$mail->setReceiver($data['mail']);
		$mail->setSubject('Account aktiviert auf '.$_SERVER['SERVER_NAME']);
		$mail->setData([
			'customer' => $this->api->getCustomer(),
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
			'settings' => $this->settings
		]);

		return $mail->send();
	}

	public function sendPasswordResetMail($data = NULL) {

		if (isset($data['hash']) && isset($data['mail'])) {
			$subject = $this->formalSpeech ? 'Ihr Passwort wurde zurückgesetzt ' : 'Dein Passwort wurde zurückgesetzt ';
			$subject .= $_SERVER['SERVER_NAME'];

			$mail = new Mailer();
			$mail->setTemplate('PasswordReset.twig');
			$mail->setReceiver($data['mail']);
			$mail->setSubject($subject);
			$mail->setData([
				'client' => $data,
				'customer' => $this->api->getCustomer(),
				'settings' => $this->settings
			]);
			return $mail->send();
		}

		return false;
	}

	public static function calcCardQuantity($card) {
		$quantity = 0;
		foreach ($card as $item) {
            switch ($item['item_type']) {
                case 'bundle':
                case 'product':
				    $quantity = $quantity + $item['quantity'] * $item['item']['package_quantity'];
                    break;
                default:
				    $quantity = $quantity + $item['quantity'];
                    break;
            }
		}
		return $quantity;
	}

	public static function quantityIsAllowed($quantity, $retString = false) {

		$settings = (ServiceLocator::get('Settings'))->get('settings');

		if (array_key_exists('minBasketSize', $settings) && $quantity < $settings['minBasketSize'])
			return $retString ? 'minBasketSize' : false;

		if (array_key_exists('packageSteps', $settings)) {
			$steps = $settings['packageSteps'];
			if (is_string($steps))
				$steps = array_map('trim', explode(',', $steps));

			if (array_key_exists('factor', $steps)) {
				$factor = (int)$steps['factor'];
				if ($quantity % $factor != 0)
					return $retString ? 'packageSteps' : false;

				unset($steps['factor']);
			}

			if (count($steps) > 0 && !in_array($quantity, array_values($steps)))
				return $retString ? 'packageSteps' : false;

		}

		return $retString ? 'valid' : true;
	}

	public static function quantityByWineryIsAllowed($items, $retString = false) {

		$settings = (ServiceLocator::get('Settings'))->get('settings');

		$minBasketSize = $settings['minBasketSize'];
		$minBasketSizePerWinery = $settings['minBasketSize'];
		$includeBundles = $settings['basketPerWinery']['includeBundles'];

		if (is_array($settings['basketPerWinery'])) {
			if ($settings['basketPerWinery']['size'])
				$minBasketSizePerWinery = $settings['basketPerWinery']['size'];
		} else {
			if (is_numeric($settings['basketPerWinery']))
				$minBasketSizePerWinery = $settings['basketPerWinery'];
		}

		$itemsByWinery = [];
		$wineInBasket = false;
		$bundleInBasket = false;
		$onlyBundleInBasket = false;
		foreach ($items as $item) {
			if ($includeBundles == false) {
				if ($item['item_type'] == 'wine') {
					$wineInBasket = true;
					if (!isset($item['item']['winery_id']))
						continue;
				} elseif ($item['item_type'] == 'bundle') {
					$bundleInBasket = true;
					continue;
				}
			}

			$wineryId = $item['item']['winery_id'];

			if(isset($itemsByWinery[$wineryId]))
				$itemsByWinery[$wineryId] += $item['quantity'];
			else
				$itemsByWinery[$wineryId] = $item['quantity'];
		}

		if ($bundleInBasket == true and $wineInBasket == false)
			$onlyBundleInBasket = true;

		$quantity = count($itemsByWinery) ? min($itemsByWinery) : 0;
		if ($quantity < $minBasketSizePerWinery) {
			$quantityInBundles = 0;
			foreach ($items as $item) {
				if ($item['item_type'] == 'bundle') {
					$quantityInBundles += $item['quantity'] * $item['item']['package_quantity'];
				}
			}
			if ($onlyBundleInBasket == true and $quantityInBundles >= $minBasketSize)
				return $retString ? 'valid' : true;

			return $retString ? 'minBasketSize' : false;
		} else {
			return $retString ? 'valid' : true;
		}
	}

	public function showAllSettings () {
		return $this->settingsService->getAll();
	}
}