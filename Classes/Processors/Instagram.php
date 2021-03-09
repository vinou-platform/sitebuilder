<?php
namespace Vinou\SiteBuilder\Processors;

use \Vinou\ApiConnector\Tools\Helper;
use \GuzzleHttp\Client;
use \GuzzleHttp\Psr7;
use \GuzzleHttp\Psr7\Request;
use \GuzzleHttp\Exception\ClientException;
use \GuzzleHttp\Exception\RequestException;
use \Monolog\Logger;
use \Monolog\Handler\RotatingFileHandler;

class Instagram {

	public $data = [];
	public $cacheDir = 'Cache/Instagram';

	/**
	 * @var boolean $enableLogging enable logging into log array
	 */
	public $enableLogging;

	/**
	 * @var array $log array to log processes
	 */
	public $log = [];

	/**
	 * @var object $instagram instagram scraper object for client
	 */
	public $instagram = null;

	/**
	 * @var boolean $crawlerDetect shows if a crawler was detected
	 */
	public $crawler = false;

	/**
	 * @var object $logger monolog object for logs
	 */
	public $logger = null;

	public function __construct() {
		$this->initCacheDir();
		$this->instagram = new \InstagramScraper\Instagram(new \GuzzleHttp\Client());

		$this->initLogging();
	}

	private function initCacheDir() {
		$this->cacheDir = Helper::getNormDocRoot().$this->cacheDir;
		if (!is_dir($this->cacheDir))
			mkdir($this->cacheDir, 0777, true);
	}

	private function initLogging() {

		$logDirName = defined('VINOU_LOG_DIR') ? VINOU_LOG_DIR : 'logs/';

		$logDir = Helper::getNormDocRoot() . $logDirName;

        if (!is_dir($logDir))
            mkdir($logDir, 0777, true);

        $htaccess = $logDir .'/.htaccess';
        if (!is_file($htaccess)) {
            $content = 'Deny from all';
            file_put_contents($htaccess, $content);
        }

		$loglevel = defined('VINOU_LOG_LEVEL') ? Logger::VINOU_LOG_LEVEL : Logger::ERROR;

		if (defined('VINOU_DEBUG') && VINOU_DEBUG)
			$loglevel = Logger::DEBUG;

		$this->logger = new Logger('instagram');
		$this->logger->pushHandler(new RotatingFileHandler($logDir.'instagram.log', 30, $loglevel));
	}

	public function loadTimelinePosts($params, $force = false) {

		if (!isset($params['username']) && !defined('IG_USER'))
			return false;

		$user = isset($params['username']) ? $params['username'] : user;
		$cacheFile = $this->cacheDir . '/' . $user . '.json';
		$cacheData = file_exists($cacheFile) ? json_decode(file_get_contents($cacheFile), true) : false;

		// return cacheData if file lifetime is short enough
		// IMPORTANT do this alway before instagram request to prevent Instagram API timeouts
		if ($cacheData && time() - filemtime($cacheFile) < 900 && !$force)
			return $cacheData;

		$response = $this->instagram->getPaginateMedias($user);

		// return cache data if no medias are found;
		if (!isset($response['medias']))
			return $cacheData;

		$posts = [];
		foreach ($response['medias'] as $post) {
			array_push($posts, [
				'id' => $post->getId(),
				'shortCode' => $post->getShortCode(),
				'taken' => $post->getCreatedTime(),
				'type' => $post->getType(),
				'link' => $post->getLink(),
				'imageLowResolutionUrl' => $post->getImageLowResolutionUrl(),
				'imageThumbnailUrl' => $post->getImageThumbnailUrl(),
				'imageStandardResolutionUrl' => $post->getImageStandardResolutionUrl(),
				'imageHighResolutionUrl' => $post->getImageHighResolutionUrl(),
				'squareImages' => $post->getSquareImages(),
				'caption' => $post->getCaption()
			]);
		}

		// write new cache file if everything is okay
		file_put_contents($cacheFile,json_encode($posts, true));
		return $posts;
	}

}