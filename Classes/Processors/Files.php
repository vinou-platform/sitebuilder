<?php
namespace Vinou\Page\Processors;

use \Vinou\ApiConnector\Tools\Helper;

class Files {

	public $data = [];
	protected $allowedExt = ['jpg', 'png', 'gif', 'pdf', 'zip'];
	protected $source = NULL;

	public function __construct() {

	}

	public function createFilelist($data = NULL) {
		if (!isset($data['source']))
			return ['error' => 'no source given'];
		else
			$this->source = $data['source'];

		$dir = Helper::getNormDocRoot().$data['source'];
		unset($data['source']);

		if (isset($data[0])) {
			$dir = $dir.$data[0];

			$subDirParts = explode('/', $_SERVER['REQUEST_URI']);
			array_pop($subDirParts);
			$back = implode('/', $subDirParts);
		}

		if (!is_dir($dir))
			return ['error' => 'source folder doesnt exists in webroot'];


		if (isset($data['allowedExt']))
			$this->allowedExt = explode(',', $data['allowedExt']);

		return [
			'source' => $this->source,
			'back' => isset($data[0]) ? $back : false,
			'files' => $this->readdir($dir)
		];
	}

	public function readdir($dir) {
		$iterator = new \FilesystemIterator($dir);
		$filelist = [];
		foreach($iterator as $entry) {
			$name = $entry->getFilename();
			$abs = $entry->getPathName();

			// CONTINUE IF FILE IS HIDDEN
			if (substr($name,0,1) === '.')
				continue;

			$add = [
				'name' => $entry->getFilename(),
				'chstamp' => filemtime($abs),
				'crstamp' => filectime($abs),
				'type' => filetype($abs),
				'description' => is_file($abs.'.txt') ? file_get_contents($abs.'.txt') : false
			];

			switch ($add['type']) {
				case 'dir':
					$add['files'] = $this->readdir($abs);
					$basePath = Helper::getNormDocRoot().$this->source;
					$add['path'] = str_replace($basePath, '', $abs);
					$add['linkpath'] = $_SERVER['REQUEST_URI'].$name;
					$filelist[] = $add;
					break;

				case 'file':
					$add['src'] = str_replace(Helper::getNormDocRoot(),'', $abs);
					$add['size'] = filesize($abs);
					$add['extension'] = pathinfo($entry->getPathName())['extension'];
					if (in_array($add['extension'], $this->allowedExt))
						$filelist[] = $add;
					break;

			}
		}
		sort($filelist);
		return $filelist;
	}

}
?>