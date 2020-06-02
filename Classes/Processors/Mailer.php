<?php
namespace Vinou\SiteBuilder\Processors;

use \PHPMailer\PHPMailer\PHPMailer;
use \PHPMailer\PHPMailer\Exception;
use \Vinou\ApiConnector\Tools\Helper;
use \Vinou\ApiConnector\Session\Session;
use \Gregwar\Captcha\CaptchaBuilder;
use \Gregwar\Captcha\PhraseBuilder;

class Mailer {

	protected $api = NULL;

	protected $templateRootPath = '../../Resources/';
	protected $templateDirectories = ['Mail/'];
	protected $template = 'Default.twig';
	public $storage = [];

	protected $renderer = null;
	protected $configFile = 'mail.yml';
	protected $mailer = null;
	protected $config = [];
	protected $formconfig = [];
	protected $useCaptcha = true;
	protected $sendCopyToSender = false;
	protected $mails = [];

	public $fromMail = null;
	public $fromName = null;
	public $receiver = null;
	public $subject = null;
	public $data = [];

	public function __construct($api = null) {
        if (!is_null($api))
            $this->api = $api;

		$this->initMailer();
		$this->loadDefaultTemplateStorage();
		$this->loadConfig();
	}

	public function initMailer() {
		$this->mailer = new PHPMailer();
		$this->mailer->CharSet = 'UTF-8';
		$this->mailer->Encoding = 'base64';
	}

	public function send() {
		$this->initTwig();
		$mailcontent = $this->render();

		$this->mailer->setFrom($this->fromMail, $this->fromName, 0);
		$this->mailer->addReplyTo($this->fromMail);
		$this->mailer->addAddress($this->receiver);
		$this->mailer->Subject = $this->subject;
		$this->mailer->msgHTML($mailcontent);
		if (!$this->mailer->send()) {
			return $this->mailer->ErrorInfo;
		} else {
			Session::deleteValue('captcha');
		    return true;
		}
	}


	public function sendMails() {
		$this->initTwig();

		foreach ($this->mails as $mail) {

			$mailcontent = $this->render($mail['template'], $mail['data']);
			$this->mailer->setFrom($this->fromMail, $this->fromName, 0);
			$this->mailer->addReplyTo($this->fromMail);

			if (isset($mail['subject']))
				$this->setSubject($mail['subject']);

			if (isset($mail['receiver']))
				$this->setReceiver($mail['receiver']);

			$this->mailer->ClearAllRecipients();
			$this->mailer->addAddress($this->receiver);
			$this->mailer->Subject = $this->subject;
			$this->mailer->msgHTML($mailcontent);

			if (!$this->mailer->send()) {
				return $this->mailer->ErrorInfo;
			}
		}

		Session::deleteValue('captcha');
		return true;
	}

	public function setTemplate($template) {
		$this->template = $template;
	}

	public function setData($data) {
		$this->data = $data;
	}

	public function setFrom($email, $name) {
		$this->fromMail = $email;
		$this->fromName = $name;
	}

	public function setReceiver($receiver) {
		$this->receiver = $receiver;
	}

	public function setSubject($subject) {
		$this->subject = $subject;
	}

	public function setConfigFile($file) {
		$this->configFile = $file;
	}

	public function loadShopAttachments() {
		if (isset($this->config['shop']['attachments'])) {
			foreach ($this->config['shop']['attachments'] as $key => $file) {
				$absFile = Helper::getNormDocRoot().$file;
				if (is_file($absFile))
					$this->mailer->addAttachment($absFile);
			}
		}
	}

	public function loadCaptcha($params = NULL) {

		$phrase = Session::getValue('captcha');
		if (!$phrase) {
			$phraseBuilder = new PhraseBuilder(5, '0123456789');
			$captcha = new CaptchaBuilder(null, $phraseBuilder);
		}
		else {
			$captcha = new CaptchaBuilder($phrase);
		}

		if (isset($params['bgcolor']))
			list($r, $g, $b) = explode(',',$params['bgcolor']);
		else
			list($r, $g, $b) = [100, 0, 0];

		$width = isset($params['width']) ? $params['width'] : 120;
		$height = isset($params['height']) ? $params['height'] : 50;

		$captcha->setBackgroundColor($r, $g, $b);
		$captcha->setIgnoreAllEffects(true);
		$captcha->build($width, $height);
		Session::setValue('captcha', $captcha->getPhrase());

		return [
			'phrase' => $captcha->getPhrase(),
			'image' => $captcha->inline(),
		];
	}

	public function validateCaptcha() {
		if (!isset($_POST['captcha']))
			return false;

		$sessionPhrase = Session::getValue('captcha');
		$phrase = $_POST['captcha'];
		return $phrase === (string)$sessionPhrase;
	}

	public function sendPostForm($params) {

		if (empty($_POST) || !isset($_POST['submitted']) || $_POST['submitted'] == 0)
			return false;

		$this->loadFormConfig($params, $_POST);

		if ($this->useCaptcha && !$this->validateCaptcha())
			return [
				'captchaerror' => 'captcha could not be detected or is invalid'
			];

		return $this->sendMails();
	}

	public function sendJSONForm() {
		$inputJSON = file_get_contents('php://input');
  		$data = json_decode($inputJSON, TRUE);

  		if (!isset($data['data']))
  			return false;

  		$this->loadFormConfig($data, $data['data']);

		return $this->sendMails();
  	}

  	public function loadFormConfig($config, $data) {

  		$formconfig = $this->validateFormConfig($config);

  		$mail = [];
  		$maildata = [];

  		if (isset($formconfig['subject'])) {
  			$mail['subject'] = $formconfig['subject'];
			$maildata['title'] = $formconfig['subject'];
		}

  		if (isset($formconfig['receiver']))
  			$mail['receiver'] = $formconfig['receiver'];


  		if (isset($formconfig['template']))
  			$mail['template'] = $formconfig['template'];

  		if (isset($formconfig['disableCaptcha'])) {
  			$this->useCaptcha = !$formconfig['disableCaptcha'];
  		}

		$maildata['formdata'] = [];
		foreach ($data as $field => $value) {
			if (in_array($field, $formconfig['fields']))
				$maildata['formdata'][$field] = $value;
		}

		$maildata['customer'] = $this->api->getCustomer();
		$mail['data'] = $maildata;
		array_push($this->mails, $mail);

		if (isset($formconfig['confirmation'])) {
			$mailconfig = $this->validateMailConfig($formconfig['confirmation']);
			$maildata['title'] = $mailconfig['subject'];

			$confirmationmail = [
				'subject' => $mailconfig['subject'],
				'receiver' => $data[$mailconfig['receiver']],
				'template' => $mailconfig['template'],
				'data' => $maildata
			];
			array_push($this->mails, $confirmationmail);
		}
  	}

  	public function validateFormConfig($config) {
  		if (is_null($this->api))
  			throw new \Exception('no api initialized');

  		if (!isset($this->config['forms']))
  			throw new \Exception('no forms are defined');

  		if (!isset($config['form']))
			throw new \Exception('no form selected');

		$formKey = $config['form'];

  		if (!isset($this->config['forms'][$formKey]))
  			throw new \Exception('a form with this key doesnt exists');

  		$formconfig = $this->config['forms'][$formKey];

  		if (!isset($formconfig['fields']))
			throw new \Exception('no param fields defined in form config');

		return $formconfig;
  	}

  	public function validateMailConfig($config) {
  		$required = ['subject', 'receiver', 'template'];
  		foreach ($required as $field) {
	  		if (!isset($config[$field]))
	  			throw new \Exception('no '.$field.' defined');
  		}
  		return $config;
  	}

	public function loadConfig() {
		if (!is_file($this->configFile) && !is_file(Helper::getNormDocRoot().VINOU_CONFIG_DIR.$this->configFile))
			throw new \Exception('Configuration file '.$this->configFile.' could not be solved');

		$absRouteFile = substr($this->configFile, 0, 1) == '/' ? $this->configFile : Helper::getNormDocRoot().VINOU_CONFIG_DIR.$this->configFile;

		$this->config = spyc_load_file($absRouteFile);

		if (isset($this->config['smtp']))
			$this->loadSMTPConfig();

		if (isset($this->config['defaults'])) {
			$defaults = $this->config['defaults'];
			if (isset($defaults['fromName']) && isset($defaults['fromMail']))
				$this->setFrom($defaults['fromMail'], $defaults['fromName']);

			if (isset($defaults['receiver']))
  				$this->setReceiver($defaults['receiver']);

  			if (isset($defaults['subject']))
  				$this->setSubject($defaults['subject']);
		}

		if (isset($this->config['template'])) {
			$template = $this->config['template'];
			if (isset($template['rootDir']) && isset($template['directories']))
				$this->loadTemplateDirectories($template['rootDir'], $template['directories']);
		}

	}

	public function loadSMTPConfig() {

		$config = $this->config['smtp'];
		$this->mailer->isSMTP();
		$this->mailer->SMTPDebug = 0;
		$this->mailer->Host = $config['host'];
		$this->mailer->Port = $config['port'];

		if (isset($config['encrypt']))
			$this->mailer->SMTPSecure = $config['encrypt'];

		$this->mailer->SMTPAuth = isset($config['auth']) ? $config['auth'] : true;

		if (isset($config['authType']))
			$this->mailer->AuthType = $config['authType'];

		$this->mailer->Username = $config['username'];
		$this->mailer->Password = $config['password'];

	}

	public function testSMTPConfig() {

		$this->mailer->setFrom($this->fromMail, $this->fromName, 0);

		if ($this->mailer->smtpConnect()) {
		    $this->mailer->smtpClose();
		   	return "Connected";
		}
		else {
			return $this->mailer->ErrorInfo;
		}

	}

	private function loadDefaultTemplateStorage() {
		$this->loadTemplateDirectories($this->templateRootPath, $this->templateDirectories, true);
	}


	private function loadTemplateDirectories($rootDir, $subfolders, $internal = false) {
		if (substr($rootDir, 0, 2) == '..' && $internal) {
			$rootDir = __DIR__.'/'.$rootDir;
		} elseif (substr($rootDir, 0, 1) == '/') {
			$rootDir = $rootDir;
		} else {
			$rootDir = Helper::getNormDocRoot().$rootDir;
		}

		foreach ($subfolders as $directory) {
			array_unshift($this->storage,$rootDir.$directory);
		}
	}


	private function render($template = NULL, $data = NULL) {
		if (is_null($template))
			$template = $this->template;

		if (is_null($data))
			$data = $this->data;

		$twig = $this->renderer->loadTemplate($template);
		return $twig->render($data);
	}

	private function initTwig() {

		$loader = new \Twig_Loader_Filesystem($this->storage);

		$this->renderer = new \Twig_Environment($loader, array(
			'cache' => defined('VINOU_CACHE') ? VINOU_CACHE : Helper::getNormDocRoot().'Cache',
			'debug' => defined('VINOU_DEBUG') ? VINOU_DEBUG : false
		));

		// This line enables debugging and is included to activate dump()
		$this->renderer->addExtension(new \Twig_Extension_Debug());
        $this->renderer->addExtension(new \Vinou\Translations\TwigExtension(isset($options['language']) ? $options['language'] : 'de'));

        $this->renderer->addFilter( new \Twig_SimpleFilter('base64image', function($url) {
            return Helper::imageToBase64($url);
        }));

		return $this->renderer;
	}
}
?>