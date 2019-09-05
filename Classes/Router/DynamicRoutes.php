<?php
namespace Vinou\Utilities\General\Router;

use Vinou\Utilities\General\Tools\Helper;
use Vinou\Utilities\General\Tools\Render;

/**
 * Dynamic Routes
 * @see https://github.com/bramus/router
 */

class DynamicRoutes {
	private $router;
	private $render;
	private $configuration;
	public $routeFile = null;

	function __construct($router, $render) {

		$this->router = $router;
		$this->render = $render;

	}

	public function setRouteFile($file) {
		$this->routeFile = $file;
	}

	public function init() {

		$this->loadConfiguration();
		$this->generateRoutes();

	}

	private function loadConfiguration() {

		if (!is_file($this->routeFile) && !is_file(Helper::getNormDocRoot().VINOU_CONFIG_DIR.$this->routeFile))
			throw new \Exception('Route configuration file could not be solved');

		$absRouteFile = substr($this->routeFile, 0, 1) == '/' ? $this->routeFile : Helper::getNormDocRoot().VINOU_CONFIG_DIR.$this->routeFile;

		$this->configuration = spyc_load_file($absRouteFile);
	}

	private function generateRoutes($configuration = NULL) {

		if (is_null($configuration))
			$configuration = $this->configuration;

		foreach ($configuration as $pattern => $options) {
			if ($pattern[0] != '/') {
				$pattern = '/'.$pattern;
			}
			$options['pattern'] = $pattern;

			if (!isset($options['type']))
				$options['type'] = 'page';

			switch ($options['type']) {
				case 'redirect':
					$this->render->redirect($options['redirect']);
					break;

				case 'namespace':
					$this->router->mount($pattern, function() use ($options) {
						// Merge defaults.
						if (isset($options['defaults'])) {
							$defaults = $options['defaults'];
							foreach ($options['routes'] as &$routeOptions) {
								foreach ($defaults as $key => $value) {
									if (!isset($routeOptions[$key]))
										$routeOptions[$key] = $value;
									//elseif (is_array($value))
									//	$routeOptions[$key] = array_merge($value, $routeOptions[$key]);
								}
							}
						}
						$this->generateRoutes($options['routes']);
					});
					break;
				default:
					// Page
					$method = isset($options['method']) ? $options['method'] : 'get';
					$this->router->{$method}($pattern, function() use ($options) {
						$template = $this->detectForTemplate($options);
						if (isset($options['contentFunc']))
							$this->render->{$options['contentFunc']}($options);
						if (isset($options['dataProcessing']))
							$this->render->dataProcessing($options['dataProcessing'],func_get_args());
						if (isset($options['postProcessing']))
							$this->render->postProcessing($options['postProcessing'],func_get_args());
						$this->render->renderPage($template,$options);
					});
					break;
			}
		}

	}

	private function detectForTemplate($options) {
		if (isset($options['template']))
			return $options['template'];

		if (strpos($_SERVER['REQUEST_URI'],'.html'))
			return str_replace('.html','.twig',$_SERVER['REQUEST_URI']);
	}
}