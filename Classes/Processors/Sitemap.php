<?php
namespace Vinou\SiteBuilder\Processors;

use \Vinou\ApiConnector\Tools\Helper;
use \Thepixeldeveloper\Sitemap\Urlset;
use \Thepixeldeveloper\Sitemap\Url;
use \Thepixeldeveloper\Sitemap\Drivers\XmlWriterDriver;
use \Thepixeldeveloper\Sitemap\Extensions\Image;

class Sitemap {

	public $data = [];
	public $routes = [];
	public $routeConfig = [];
	public $api = NULL;

	public function __construct(&$routeConfig = NULL, &$api = NULL) {

		if (is_null($api))
            throw new \Exception('no api was initialized');
        else
            $this->api = $api;

		if (is_null($routeConfig))
			throw new \Exception("No route config given", 1);
		else
			$this->routeConfig = $routeConfig;
	}

	public function arrayToMap($array, $delimiter, $object) {
        $split = explode($delimiter, $array, 2);
        if (isset($split[1]))
            return [
            	$split[0] => [
            		'subpages' => $this->arrayToMap($split[1], $delimiter, $object)
            	]
            ];
        else
            return [$split[0] => $object];
	}

	public function renderSitemap() {

		$entries = [];

		foreach ($this->routeConfig->configuration as $url => $routeConfig) {
            if (!isset($routeConfig['sitemap']) || $url == '/')
                continue;

            $config = $routeConfig['sitemap'];

            preg_match_all("/{(.+?)}/", $url, $matches);
            if (count($matches[1]) > 0) {
                if (isset($config['function'])) {
                    $function = $config['function'];
                    $dataKey = isset($config['dataKey']) ? $config['dataKey'] : 'data';
                    $postData = isset($config['params']) ? $config['params'] : [];
                    $result = $this->api->{$function}($postData);
                    $data = isset($result[$dataKey]) ? $result[$dataKey] : $result;

                    if (isset($result['pagination'])) {
                        $start = $result['pagination']['current'] + 1;
                        $end = $result['pagination']['total'];

                        for ($i = $start; $i <= $end ; $i++) {
                            $postData['page'] = $i;
                            $pageResult = $this->api->{$function}($postData);
                            $page = isset($pageResult[$dataKey]) ? $pageResult[$dataKey] : $pageResult;
                            $data = array_merge($data, $page);
                        }
                    }

                    $titleField = isset($config['titleField']) ? $config['titleField'] : 'name';

                    if (is_array($data) && count($data) > 0) {
                        foreach ($data as $entry) {
                            foreach ($matches[1] as $fieldName) {
                                if (isset($entry[$fieldName])) {

                                	$suburl = str_replace('{'.$fieldName.'}', $entry[$fieldName], $url);

                                    $dataPage = [
                                    	'title' => isset($entry[$titleField]) ? $entry[$titleField] : false,
                                    	'url' => '/' . $suburl
                                    ];

                                    $entries = array_merge_recursive($entries, $this->arrayToMap($suburl, '/', $dataPage));

                                }
                            }
                        }
                    }
                }
            } else {

	            $page = [
					"title" => $routeConfig['pageTitle'],
					"url" => '/' . $url
				];

				$entries = array_merge_recursive($entries, $this->arrayToMap($url, '/', $page));
			}

        }

        return $entries;
	}

	public function renderSitemapXML($routes) {

        header("Content-type: text/xml");

        $date = new \DateTime();

        $urlset = new Urlset();

        foreach ($routes as $url => $routeConfig) {
            if (!isset($routeConfig['sitemap']))
                continue;

            $url = $url == '/' ? '/' : '/' . $url;
            $url = Helper::getCurrentHost() . $url;
            $config = $routeConfig['sitemap'];

            preg_match_all("/{(.+?)}/", $url, $matches);
            if (count($matches[1]) > 0) {
                if (isset($config['function'])) {
                    $function = $config['function'];
                    $dataKey = isset($config['dataKey']) ? $config['dataKey'] : 'data';
                    $postData = isset($config['params']) ? $config['params'] : [];
                    $result = $this->api->{$function}($postData);
                    $data = isset($result[$dataKey]) ? $result[$dataKey] : $result;

                    if (isset($result['pagination'])) {
                        $start = $result['pagination']['current'] + 1;
                        $end = $result['pagination']['total'];

                        for ($i = $start; $i <= $end ; $i++) {
                            $postData['page'] = $i;
                            $pageResult = $this->api->{$function}($postData);
                            $page = isset($pageResult[$dataKey]) ? $pageResult[$dataKey] : $pageResult;
                            $data = array_merge($data, $page);
                        }
                    }

                    if (is_iterable($data)) {
                        foreach ($data as $entry) {
                            $createUrl = true;
                            $entryUrl = $url;
                            foreach ($matches[1] as $fieldName) {
                                if (isset($entry[$fieldName]))
                                    $entryUrl = str_replace('{'.$fieldName.'}', $entry[$fieldName], $entryUrl);
                                else
                                    $createUrl = false;
                            }

                            if ($createUrl) {

                                $entryDate = isset($entry['chstamp']) ? new \DateTime($entry['chstamp']) : $date;
                                $siteMapUrl = new Url($entryUrl);
                                $siteMapUrl->setLastMod($entryDate);
                                $siteMapUrl->setChangeFreq('weekly');
                                $siteMapUrl->setPriority('0.8');

                                if ($entry['image'] != '') {
                                    $image = new Image('https://api.vinou.de'.$entry['image']);
                                    $siteMapUrl->addExtension($image);
                                }

                                $urlset->add($siteMapUrl);
                            }
                        }
                    }
                }
            } else {
                $priority = $url == '/' ? '1.0' : '0.6';
                $changeFreq = $url == '/' ? 'daily' : 'monthly';
                $siteMapUrl = new Url($url);
                $siteMapUrl->setLastMod($date);
                $siteMapUrl->setChangeFreq($changeFreq);
                $siteMapUrl->setPriority($priority);

                $urlset->add($siteMapUrl);
            }
        }

        $driver = new XmlWriterDriver();
        $urlset->accept($driver);

        echo $driver->output();
        exit();
    }

}