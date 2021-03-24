<?php
namespace Vinou\SiteBuilder\Loader;

use \Vinou\ApiConnector\Tools\Helper;
use \Vinou\ApiConnector\Tools\ServiceLocator;

class Settings {

	protected $files = [];
	protected $settingsService = null;

	function __construct($default = true) {
		$this->settingsService = ServiceLocator::get('Settings');
		$this->loadDefaultFile();
	}

	public function loadDefaultFile() {
		$classPaths = explode('/Classes', Helper::getClassPath(get_class($this)));
		$this->addByDirectory(array_shift($classPaths));
	}

	public function addByDirectory($dir, $subdir = '/Configuration/') {
		if (!self::isAbsolute($dir))
			$dir = Helper::getNormDocRoot().$dir;

		if (!is_dir($dir))
			throw new \Exception("directory $dir doesn't exists", 1);

		$file = $dir . $subdir . 'settings.yml';
		$this->addFile($file);
	}

	public function addFile($file) {
		if (is_null($file))
			throw new \Exception("No file given", 1);

		if (!self::isAbsolute($file))
			$file = realpath($file);

		if (is_file($file))
			array_push($this->files, $file);
		else
			throw new \Exception("file $file doesn't exists", 1);
	}

	public function load() {
		if (defined('VINOU_CONFIG_DIR'))
            $this->addByDirectory(VINOU_CONFIG_DIR, false);

		$settings = [];
		for ($i=0; $i < count($this->files); $i++) {
			$fileSettings = spyc_load_file($this->files[$i]);
			$settings = array_replace_recursive($settings, $fileSettings);
		}

		foreach ($settings as $key => $value) {
			$this->settingsService->set($key, $value);
		}
		return $settings;
	}

	public static function isAbsolute($path) {
		return strcmp(substr($path, 0, 1), '/') == 0;
	}
}