<?php
namespace Vinou\Utilities\General\Tools;

class Redirect {

	public static function external($url) {
		header("Location: ".$url);
		exit;
	}

	public static function internal($route) {
		header("Location: ".self::detectProtocol().$_SERVER['HTTP_HOST'].$route);
		exit;
	}

	public static function detectProtocol() {
		isset($_SERVER['HTTPS']) ? $protocol = 'https://' : $protocol = 'http://';
		return $protocol;
	}

}