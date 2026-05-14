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
 * Processor handling the full shop checkout pipeline.
 *
 * Manages basket validation, session state for billing/delivery/payment,
 * order creation, and transactional email dispatch. Also contains static
 * helpers for basket quantity and winery-based minimum-order enforcement.
 *
 * Registered under the key 'shop' by default in Site::loadDefaultProcessors().
 */
class Shop implements ProcessorInterface {

    /** @var Api Vinou API instance. */
    protected Api $api;

    /** @var object Settings service from the service locator. */
    protected object $settingsService;

    /** @var array<string, mixed>|false Merged shop settings block. */
    protected array|false $settings = false;

    /** @var bool True when settings.speechStyle === 'formal'. */
    protected bool $formalSpeech = false;

    /** @var array<string, mixed>|false Logged-in client data from session, or false. */
    protected array|false $client = false;

    /** @var bool False when settings.emailDelivery === false (emails delegated to the API). */
    protected bool $emailDelivery = true;

    /**
     * @param Api|null $api  Initialized Vinou API instance.
     * @throws \Exception If $api is null.
     */
    public function __construct(?Api $api = null) {
        if (is_null($api))
            throw new \Exception('no api was initialized');

        $this->api             = $api;
        $this->settingsService = ServiceLocator::get('Settings');
        $this->settings        = $this->settingsService->get('settings');

        $this->formalSpeech = array_key_exists('speechStyle', $this->settings)
            && $this->settings['speechStyle'] == 'formal';

        $this->emailDelivery = !(array_key_exists('emailDelivery', $this->settings)
            && !$this->settings['emailDelivery']);

        $this->client = Session::getValue('client');
    }

    /**
     * Returns the active campaign from the session.
     *
     * @return mixed  Campaign data array, or null/false if none is active.
     */
    public function loadCampaign(): mixed {
        return Session::getValue('campaign');
    }

    /**
     * Calculates the net/tax/gross/quantity totals for a basket.
     *
     * Accepts either a raw basket items array or an array with a 'basketItems'
     * key. When a client is logged in and a B2B price is available, the B2B
     * price object is used instead of the default price.
     *
     * @param array<string, mixed>|null $data  Basket items or basket wrapper array.
     * @return array{net: float, tax: float, gross: float, quantity: int}
     */
    public function calcSum(?array $data = null): array {
        $sum = ['net' => 0, 'tax' => 0, 'gross' => 0, 'quantity' => 0];

        if (!is_array($data))
            return $sum;

        $items = isset($data['basketItems']) ? $data['basketItems'] : $data;

        foreach ($items as $item) {
            $quantity    = $item['quantity'];
            $priceObject = $item['item'];
            if ($this->client && isset($item['item']['prices'][0]))
                $priceObject = $item['item']['prices'][0];

            $sum['net']      += $priceObject['net'] * $quantity;
            $sum['tax']      += $priceObject['tax'] * $quantity;
            $sum['gross']    += $priceObject['gross'] * $quantity;
            $sum['quantity'] += $quantity;
        }

        return $sum;
    }

    /**
     * Merges basket items and an optional package into a flat positions list.
     *
     * @param array<string, mixed>|null $data  Array with optional 'basket' and 'package' keys.
     * @return list<array<string, mixed>>|false  Merged item list, or false if $data is null.
     */
    public function arrangePositions(?array $data = null): array|false {
        if (is_null($data))
            return false;

        $items = [];

        if (isset($data['basket']) && count($data['basket']['basketItems']) > 0)
            $items = array_merge($items, $data['basket']['basketItems']);

        if (isset($data['package']) && !empty($data['package']))
            array_push($items, [
                'item_type' => 'package',
                'item_id'   => $data['package']['id'],
                'quantity'  => 1,
                'item'      => $data['package']
            ]);

        return $items;
    }

    /**
     * Applies a campaign discount to the current basket via the API.
     *
     * Builds a positions payload from basket items and package, calls
     * findCampaign on the API, stores the discount summary in the session,
     * and returns it.
     *
     * @param array<string, mixed>|null $data  Must contain 'campaign', optionally 'basket' and 'package'.
     * @return array<string, mixed>|false  Discount summary array, or false if no campaign or no summary.
     */
    public function campaignDiscount(?array $data = null): array|false {
        $items = [];

        if (!isset($data['campaign']))
            return false;

        if (isset($data['basket']) && count($data['basket']['basketItems']) > 0) {
            foreach ($data['basket']['basketItems'] as &$item) {
                $position = [
                    'item_type' => $item['item_type'],
                    'item_id'   => $item['item_id'],
                    'quantity'  => $item['quantity'],
                    'gross'     => $item['quantity'] * $item['item']['gross'],
                    'net'       => $item['quantity'] * $item['item']['net'],
                    'taxrate'   => $item['item']['taxrate']
                ];
                $position['tax'] = $position['gross'] - $position['net'];
                array_push($items, $position);
            }
        }

        if (isset($data['package'])) {
            $package  = $data['package'];
            $position = [
                'item_type' => 'package',
                'item_id'   => $package['id'],
                'quantity'  => 1,
                'gross'     => $package['gross'],
                'tax'       => $package['tax'],
                'net'       => $package['net'],
                'taxrate'   => $package['taxrate']
            ];
            array_push($items, $position);
        }

        $result = $this->api->findCampaign([
            'hash'  => $data['campaign']['hash'],
            'items' => $items
        ]);

        if (!isset($result['summary']))
            return false;

        Session::setValue('campaignDiscount', $result['summary']);
        return $result['summary'];
    }

    /**
     * Reads billing data from the session, merges any POST submission, and persists it.
     *
     * Triggers a redirect to the delivery step when 'enabledelivery' is posted.
     * Calls checkSubmitRedirect() after storing data.
     *
     * @return array<string, mixed>  Current billing data.
     * @throws \Exception If enabledelivery is posted but no redirect URL is configured.
     */
    public function loadBilling(): array {
        $billing = Session::getValue('billing');

        if (!$billing)
            $billing = [];

        if (empty($_POST))
            return $billing;

        if (isset($_POST['newsletter']) && $_POST['newsletter'] == 1)
            $billing['newsletter'] = 1;

        if (isset($_POST['billing']))
            $billing = array_merge($billing, $_POST['billing']);

        Session::setValue('billing', $billing);

        if (isset($_POST['enabledelivery']) && (bool)$_POST['enabledelivery']) {
            if (isset($_POST['redirect']['delivery']))
                Redirect::internal($_POST['redirect']['delivery']);
            else
                throw new \Exception('no url was set for delivery as hidden input');
        }

        $this->checkSubmitRedirect('billing');

        return $billing;
    }

    /**
     * Reads and optionally updates the delivery type from session/POST.
     *
     * @return string  Delivery type identifier, defaults to 'address'.
     */
    public function loadDeliveryType(): string {
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

    /**
     * Reads delivery address from session, merges POST data, and persists it.
     *
     * Returns null when delivery_type is 'none' (Click & Collect orders).
     *
     * @return array<string, mixed>|null  Delivery data, or null if delivery type is 'none'.
     */
    public function loadDelivery(): ?array {
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

        Session::setValue('delivery', $delivery);

        $this->checkSubmitRedirect('delivery');

        return $delivery;
    }

    /**
     * Reads and optionally stores the selected payment type from session/POST.
     *
     * @return mixed  Payment type string, or null/false if not yet selected.
     */
    public function loadPayment(): mixed {
        if (!empty($_POST) && isset($_POST['payment'])) {
            Session::setValue('payment', $_POST['payment']);
            $this->checkSubmitRedirect();
        }
        return Session::getValue('payment');
    }

    /**
     * Redirects to the payment-type-specific init page if one is configured.
     *
     * Reads the payment type from the session and looks up pages.{type}.init
     * in the shop settings. Redirects if found; returns true otherwise.
     *
     * @return true
     */
    public function initPaymentByPage(): true {
        $paymentType = Session::getValue('payment');
        $pages       = $this->settings['pages'];
        if (array_key_exists($paymentType, $pages) && array_key_exists('init', $pages[$paymentType]))
            Redirect::internal($pages[$paymentType]['init']);
        return true;
    }

    /**
     * Returns Stripe payment data from the session.
     *
     * @return mixed  Stripe session data, or null/false if not set.
     */
    public function loadStripeData(): mixed {
        return Session::getValue('stripe');
    }

    /**
     * Redirects to the stored PayPal approval URL if one is in the session.
     *
     * @return void
     */
    public function initPaypalPayment(): void {
        $paypal = Session::getValue('paypal');
        if ($paypal)
            Redirect::external($paypal);
    }

    /**
     * Validates the checkout confirmation form submission.
     *
     * Terminates immediately (exit) when the required cop and disclaimer
     * fields are missing. Stores an optional order note, then calls
     * checkSubmitRedirect().
     *
     * @return false
     */
    public function check(): false {
        if (!empty($_POST)) {
            if (!isset($_POST['cop']) || !isset($_POST['disclaimer']))
                exit();

            if (isset($_POST['note']))
                Session::setValue('note', $_POST['note']);

            $this->checkSubmitRedirect();
        }
        return false;
    }

    /**
     * Builds the full order array from session data and stores it back into the session.
     *
     * Sets payment-type-specific return/cancel URLs for PayPal, card, and debit.
     * Attaches the optional order note if present.
     *
     * @return array<string, mixed>  The assembled order array.
     */
    public function prepareSessionOrder(): array {
        $campaign = Session::getValue('campaign');
        if ($campaign && $campaign['id'] > 0) {
            $this->api->addItemToBasket(['data' => [
                'quantity'  => 1,
                'item_type' => 'campaign',
                'item_id'   => $campaign['id']
            ]]);
        }

        $order = [
            'source'         => 'shop',
            'payment_type'   => Session::getValue('payment'),
            'basket'         => Session::getValue('basket'),
            'billing_type'   => 'client',
            'billing'        => Session::getValue('billing'),
            'delivery_type'  => Session::getValue('delivery_type'),
            'invoice_type'   => $this->settings['checkout']['invoice_type'] ?? 'gross',
            'payment_period' => isset($this->settings['checkout']['payment_period'])
                ? (int)$this->settings['checkout']['payment_period']
                : 14,
        ];

        if ($order['delivery_type'] != 'none')
            $order['delivery'] = Session::getValue('delivery') ?? Session::getValue('billing');

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
        }

        $note = Session::getValue('note');
        if ($note)
            $order['note'] = $note;

        Session::setValue('order', $order);
        return $order;
    }

    /**
     * Clears all order-related session values when an order was successfully placed.
     *
     * @param mixed $status  Truthy to clear session; the value is returned unchanged.
     * @return mixed  The original $status value.
     */
    public function removeSessionData(mixed $status): mixed {
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

    /**
     * Prepares the order and saves it as a JSON file on disk.
     *
     * Folder: {webroot}/Orders/YYYY/MM/DD/
     * Filename: HH-MM-{basket_id}.json
     * Each date-level folder is protected with a .htaccess deny-all.
     *
     * @return array<string, mixed>  The order data that was written.
     */
    public function saveOrderJSON(): array {
        $this->checkFolders();

        $folder = 'Orders/' . date('Y/m/d');
        $this->checkFolders($folder);

        $filename       = date('h-i-') . Session::getValue('basket') . '.json';
        $formattedOrder = $this->prepareOrderToSend();
        file_put_contents(Helper::getNormDocRoot() . $folder . '/' . $filename, json_encode($formattedOrder));
        return $formattedOrder;
    }

    /**
     * Resolves the correct billing/delivery type based on whether the client
     * address matches the stored session values.
     *
     * For logged-in clients: compares billing/delivery fields against the client
     * record and sets billing_type/delivery_type to 'client' if they match, or
     * 'address' if they differ. For guest orders: checks if an existing client
     * account exists via email and links it.
     *
     * @return array<string, mixed>  Normalized order array ready to send to the API.
     */
    public function prepareOrderToSend(): array {
        $order  = Session::getValue('order');
        $client = Session::getValue('client');

        $order['session'] = [
            'billing'  => $order['billing'],
            'delivery' => $order['delivery'],
            'basket'   => Session::getValue('basket'),
            'card'     => Session::getValue('card'),
            'payment'  => Session::getValue('payment'),
            'campaign' => Session::getValue('campaign'),
            'note'     => Session::getValue('note')
        ];

        if ($client) {
            $clientId           = $client['id'];
            $order['client_id'] = $clientId;

            $compareFields = [
                'first_name' => 'firstname',
                'last_name'  => 'lastname',
                'company'    => 'company',
                'address'    => 'address',
                'zip'        => 'zip',
                'city'       => 'city'
            ];

            $billingMatch = true;
            foreach ($compareFields as $source => $target) {
                if (isset($order['billing'][$target]) && strcmp($client[$source], $order['billing'][$target]) !== 0) {
                    $billingMatch          = false;
                    $order['billing_type'] = 'address';
                    break;
                }
            }
            if ($billingMatch) {
                $order['billing_type'] = 'client';
                $order['billing_id']   = $clientId;
                unset($order['billing']);
            }

            if ($order['delivery_type'] != 'none') {
                $deliveryMatch = true;
                foreach ($compareFields as $source => $target) {
                    if (isset($order['delivery'][$target]) && strcmp($client[$source], $order['delivery'][$target]) !== 0) {
                        $deliveryMatch          = false;
                        $order['delivery_type'] = 'address';
                        break;
                    }
                }

                if ($deliveryMatch) {
                    $order['delivery_type'] = 'client';
                    $order['delivery_id']   = $clientId;
                    unset($order['delivery']);
                }
            }
        } else {
            $check = $this->api->checkClientMail($order['billing']);
            if ($check) {
                $order['client_id']    = $check;
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

    /**
     * Ensures the given folder exists under the webroot and is protected by .htaccess.
     *
     * Creates the directory recursively if missing. Writes 'Deny from all' to
     * .htaccess if the file does not yet exist.
     *
     * @param string $folder  Webroot-relative folder path (default: 'Orders').
     * @return void
     */
    public function checkFolders(string $folder = 'Orders'): void {
        $orderDir = Helper::getNormDocRoot() . $folder;

        if (!is_dir($orderDir))
            mkdir($orderDir, 0777, true);

        $htaccess = $orderDir . '/.htaccess';
        if (!is_file($htaccess))
            file_put_contents($htaccess, 'Deny from all');
    }

    /**
     * Triggers an internal redirect when a form step has been submitted.
     *
     * Returns false immediately if no POST data is present, or if $checkPostField
     * is set but not found in $_POST. Redirects to $_POST['redirect']['standard']
     * when the 'submitted' flag is truthy.
     *
     * @param string|false $checkPostField  Optional POST field name that must be present.
     * @return false
     */
    public function checkSubmitRedirect(string|false $checkPostField = false): false {
        if (empty($_POST))
            return false;

        if ($checkPostField && !isset($_POST[$checkPostField]))
            return false;

        if (isset($_POST['submitted']) && (bool)$_POST['submitted'] && isset($_POST['redirect']['standard']))
            Redirect::internal($_POST['redirect']['standard']);

        return false;
    }

    /**
     * Validates that the basket meets the minimum quantity requirements.
     *
     * Redirects to the basket page when validation fails.
     *
     * @return true
     */
    public function validateBasket(): true {
        $card = Session::getValue('card');
        if (!$card)
            Redirect::internal($this->settings['pages']['basket']);

        if (isset($this->settings['basketPerWinery']) && $this->settings['basketPerWinery'] !== false) {
            if (!self::quantityByWineryIsAllowed($card, false, $this->settings))
                Redirect::internal($this->settings['pages']['basket']);
        } else {
            $quantity = self::calcCardQuantity($card);
            if (!self::quantityIsAllowed($quantity, false, $this->settings))
                Redirect::internal($this->settings['pages']['basket']);
        }

        return true;
    }

    /**
     * Validates that billing data is present in the session.
     *
     * Redirects to the billing page when none is stored.
     *
     * @return true
     */
    public function validateBilling(): true {
        $billing = Session::getValue('billing');
        if (!$billing)
            Redirect::internal($this->settings['pages']['billing']);
        return true;
    }

    /**
     * Validates that the selected payment type is among the allowed options.
     *
     * Uses clientPayments when a client is logged in, otherwise allowedPayments.
     * Redirects to the payment page when validation fails.
     *
     * @return true
     */
    public function validatePayment(): true {
        $payment = Session::getValue('payment');

        $allowedPayments = $this->client && isset($this->settings['clientPayments'])
            ? $this->settings['clientPayments']
            : $this->settings['allowedPayments'];

        if (is_string($allowedPayments));
            $allowedPayments = explode(',', preg_replace('/\s/', '', $allowedPayments));

        if (!in_array($payment, $allowedPayments))
            Redirect::internal($this->settings['pages']['payment']);

        return true;
    }

    /**
     * Runs all three checkout validation steps in sequence.
     *
     * Calls validateBasket(), validateBilling(), and validatePayment().
     * Each validator redirects on failure; this method only returns true.
     *
     * @return true
     */
    public function validateOrder(): true {
        $this->validateBasket();
        $this->validateBilling();
        $this->validatePayment();
        return true;
    }

    /**
     * Returns the most recent in-progress order for temporary payment methods.
     *
     * Fetches the API session order and returns it only when it is neither
     * cancelled/need_package nor placed via a standard (non-temporary) payment method.
     *
     * @return array<string, mixed>|false  Order array, or false if none qualifies.
     */
    public function getPreviousOrderInProgress(): array|false {
        $order = $this->api->getSessionOrder();
        if ($order
            && $order['status'] != 'cancelled'
            && $order['status'] != 'need_package'
            && $this->isTemporaryPaymentMethod($order['payment_type'])
        )
            return $order;
        return false;
    }

    /**
     * Returns true for payment methods that require a pending-order flow.
     *
     * @param string $method  Payment type identifier.
     * @return bool
     */
    private function isTemporaryPaymentMethod(string $method): bool {
        return in_array($method, ['card', 'debit', 'paypal']);
    }

    /**
     * Sends the order confirmation email to the client and a notification to the admin.
     *
     * Skipped for temporary payment methods in 'new' status (payment not yet confirmed).
     * Skipped entirely when emailDelivery is disabled (emails delegated to the API).
     *
     * @deprecated Use email notifications from the Vinou API instead.
     *
     * @param array<string, mixed>|false|null $addedOrder  API response from addOrder.
     * @return bool|string  Result of the last Mailer::send() call, or false/true on early exit.
     */
    public function sendClientNotification(array|false|null $addedOrder): bool|string {
        if (!$addedOrder || !isset($addedOrder['number']))
            return false;

        if ($this->isTemporaryPaymentMethod($addedOrder['payment_type']) && $addedOrder['status'] == 'new')
            return false;

        if (!$this->emailDelivery)
            return true;

        $order    = $this->api->getOrder($addedOrder['id']);
        $customer = $this->api->getCustomer();
        $client   = $this->api->getClient();

        $sessionOrder = Session::getValue('order');
        $data = [
            'order'    => $order,
            'client'   => $client,
            'customer' => $customer,
            'settings' => $this->settings,
            'session'  => [
                'billing'  => $sessionOrder['billing'],
                'delivery' => $sessionOrder['delivery'],
            ]
        ];
        if ($customer['type'] == 'merchant')
            $data['wineries'] = $this->api->getWineriesAll();

        $subject       = $this->formalSpeech ? 'Ihre Bestellung ' : 'Deine Bestellung ';
        $subject      .= $order['number'];
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
        $adminmail->setSubject('Bestellung ' . $order['number'] . $subjectSuffix);
        $adminmail->setData($data);
        $send = $adminmail->send();

        return $send;
    }

    /**
     * Sends the registration confirmation email to a newly registered client.
     *
     * @param array<string, mixed>|null $data  Must contain 'lostpassword_hash' and 'mail'.
     * @return bool|string  Result of Mailer::send(), or false if required data is missing.
     */
    public function sendClientRegisterMail(?array $data = null): bool|string {
        if (isset($data['lostpassword_hash']) && isset($data['mail'])) {
            $subject  = $this->formalSpeech ? 'Bestätigen Sie Ihre Registrierung auf ' : 'Bestätige Deine Registrierung auf ';
            $subject .= $_SERVER['SERVER_NAME'];

            $mail = new Mailer();
            $mail->setTemplate('ClientRegistration.twig');
            $mail->setReceiver($data['mail']);
            $mail->setSubject($subject);
            $mail->setData([
                'client'   => $data,
                'customer' => $this->api->getCustomer(),
                'settings' => $this->settings
            ]);
            return $mail->send();
        }

        return false;
    }

    /**
     * Sends an approval-pending email to the admin and a waiting notification to the client.
     *
     * @param array<string, mixed>|null $data  Must contain 'lostpassword_hash' and 'mail'.
     * @return bool|string  True if both mails were sent, false if data is missing.
     * @throws Exception If 'approvementEmail' is not configured in settings.registration.
     */
    public function sendClientApprovementMail(?array $data = null): bool|string {
        if (!isset($this->settings['registration']['approvementEmail']))
            throw new Exception('no approvementEmail defined in settings', 1);

        if (isset($data['lostpassword_hash']) && isset($data['mail'])) {
            $adminMail = new Mailer();
            $adminMail->setTemplate('ClientApprovement.twig');
            $adminMail->setReceiver($this->settings['registration']['approvementEmail']);
            $adminMail->setSubject('Neue Registrierung auf ' . $_SERVER['SERVER_NAME']);
            $adminMail->setData([
                'client'   => $data,
                'customer' => $this->api->getCustomer(),
                'settings' => $this->settings
            ]);

            $subject  = $this->formalSpeech ? 'Ihr Account auf ' : 'Dein Account auf ';
            $subject .= $_SERVER['SERVER_NAME'];

            $clientMail = new Mailer();
            $clientMail->setTemplate('ClientApprovementNotification.twig');
            $clientMail->setReceiver($data['mail']);
            $clientMail->setSubject($subject);
            $clientMail->setData([
                'client'   => $data,
                'customer' => $this->api->getCustomer(),
                'settings' => $this->settings
            ]);

            return $adminMail->send() && $clientMail->send();
        }

        return false;
    }

    /**
     * Sends an account-activation notification to the client.
     *
     * @param array<string, mixed>|null $data  Must contain 'mail' and must not contain 'error'.
     * @return bool|string  Result of Mailer::send(), or false if data is missing or contains an error.
     */
    public function sendClientActivationNotification(?array $data = null): bool|string {
        if (!isset($data['mail']) || isset($data['error']))
            return false;

        $mail = new Mailer();
        $mail->setTemplate('ClientActivationNotification.twig');
        $mail->setReceiver($data['mail']);
        $mail->setSubject('Account aktiviert auf ' . $_SERVER['SERVER_NAME']);
        $mail->setData([
            'customer' => $this->api->getCustomer(),
            'settings' => $this->settings
        ]);

        return $mail->send();
    }

    /**
     * Sends an account-declination notification to the client.
     *
     * @param array<string, mixed>|null $data  Must contain 'mail'.
     * @return bool|string  Result of Mailer::send(), or false if 'mail' is missing.
     */
    public function sendClientDeclinationNotification(?array $data = null): bool|string {
        if (!isset($data['mail']))
            return false;

        $mail = new Mailer();
        $mail->setTemplate('ClientDeclinationNotification.twig');
        $mail->setReceiver($data['mail']);
        $mail->setSubject('Account abgelehnt auf ' . $_SERVER['SERVER_NAME']);
        $mail->setData([
            'customer' => $this->api->getCustomer(),
            'settings' => $this->settings
        ]);

        return $mail->send();
    }

    /**
     * Sends a password-reset email to the client.
     *
     * @param array<string, mixed>|null $data  Must contain 'hash' and 'mail'.
     * @return bool|string  Result of Mailer::send(), or false if required data is missing.
     */
    public function sendPasswordResetMail(?array $data = null): bool|string {
        if (isset($data['hash']) && isset($data['mail'])) {
            $subject  = $this->formalSpeech ? 'Ihr Passwort wurde zurückgesetzt ' : 'Dein Passwort wurde zurückgesetzt ';
            $subject .= $_SERVER['SERVER_NAME'];

            $mail = new Mailer();
            $mail->setTemplate('PasswordReset.twig');
            $mail->setReceiver($data['mail']);
            $mail->setSubject($subject);
            $mail->setData([
                'client'   => $data,
                'customer' => $this->api->getCustomer(),
                'settings' => $this->settings
            ]);
            return $mail->send();
        }

        return false;
    }

    /**
     * Calculates the total bottle quantity of all items in a basket card.
     *
     * For products and bundles the item's package_quantity is applied as a
     * multiplier. Other item types count as single units.
     *
     * @param list<array<string, mixed>> $card  Basket card items array.
     * @return int  Total bottle quantity.
     */
    public static function calcCardQuantity(array $card): int {
        $quantity = 0;
        foreach ($card as $item) {
            switch ($item['item_type']) {
                case 'bundle':
                case 'product':
                    $quantity += $item['quantity'] * $item['item']['package_quantity'];
                    break;
                default:
                    $quantity += $item['quantity'];
                    break;
            }
        }
        return $quantity;
    }

    /**
     * Validates a basket total quantity against the configured minimum and step constraints.
     *
     * Checks settings.minBasketSize and settings.packageSteps. When $retString is true,
     * returns 'valid', 'minBasketSize', or 'packageSteps' as a diagnostic string.
     *
     * @param int  $quantity   Total basket quantity (bottles).
     * @param bool $retString  When true, return a diagnostic string instead of a bool.
     * @return bool|string  Validation result.
     */
    public static function quantityIsAllowed(int $quantity, bool $retString = false, array $settings = []): bool|string {
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

    /**
     * Validates per-winery minimum quantity for each winery in the basket.
     *
     * When settings.basketPerWinery is enabled, each winery's item total must
     * meet the configured minimum. Bundle-only baskets are exempt when
     * includeBundles is false and the total bundle quantity meets minBasketSize.
     *
     * @param list<array<string, mixed>> $items      Basket items array.
     * @param bool                       $retString  When true, return a diagnostic string instead of a bool.
     * @return bool|string  Validation result.
     */
    public static function quantityByWineryIsAllowed(array $items, bool $retString = false, array $settings = []): bool|string {
        $minBasketSize          = $settings['minBasketSize'];
        $minBasketSizePerWinery = $settings['minBasketSize'];
        $includeBundles         = $settings['basketPerWinery']['includeBundles'];

        if (is_array($settings['basketPerWinery'])) {
            if ($settings['basketPerWinery']['size'])
                $minBasketSizePerWinery = $settings['basketPerWinery']['size'];
        } else {
            if (is_numeric($settings['basketPerWinery']))
                $minBasketSizePerWinery = $settings['basketPerWinery'];
        }

        $itemsByWinery      = [];
        $wineInBasket       = false;
        $bundleInBasket     = false;
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

            if (isset($itemsByWinery[$wineryId]))
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
                if ($item['item_type'] == 'bundle')
                    $quantityInBundles += $item['quantity'] * $item['item']['package_quantity'];
            }
            if ($onlyBundleInBasket == true and $quantityInBundles >= $minBasketSize)
                return $retString ? 'valid' : true;

            return $retString ? 'minBasketSize' : false;
        }

        return $retString ? 'valid' : true;
    }

    /**
     * Returns all values from the settings service.
     *
     * @return array<string, mixed>
     */
    public function showAllSettings(): array {
        return $this->settingsService->getAll();
    }
}
