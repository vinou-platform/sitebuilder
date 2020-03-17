<?php
namespace Vinou\SiteBuilder\Tools;

use \Vinou\ApiConnector\Api;
use \Vinou\ApiConnector\PublicApi;
use \Vinou\ApiConnector\Session\Session;
use \Vinou\ApiConnector\FileHandler\Images;
use \Vinou\ApiConnector\FileHandler\Pdf;
use \Vinou\ApiConnector\Tools\Helper;
use \Vinou\ApiConnector\Tools\Redirect;
use \Gumlet\ImageResize;
use \Gumlet\ImageResizeException;

class Render {

	protected $templateRootPath = 'Resources/';
	protected $templateDirectories = ['Templates/','Partials/','Layouts/'];
	protected $layout = 'default.twig';
	protected $languages = ['en','de'];
	protected $defaultlang = 'en';
	protected $pathsegments;
    protected $options;
    protected $config;
    public $processors = [];
    public $templateStorages = [];
	public $renderArr = [];
	public $translation = NULL;
    public $regions = [];
    public $countries = [];
	public $api;

	public function __construct($options = NULL) {
		is_null($options) ?: $this->options = $options;

		if (defined('PAGE_LANGUAGES'))
			$this->languages = unserialize(PAGE_LANGUAGES);

		if (defined('PAGE_DEFAULTLANG'))
			$this->defaultlang = PAGE_DEFAULTLANG;

		$this->defaultPageData();
	}

    public function setConfig($settings = NULL) {
        $this->config = $settings;
    }

	private function defaultPageData(){
		$this->renderArr['path'] = explode('?', $_SERVER['REQUEST_URI'])[0];
        $this->renderArr['request_uri'] = $_SERVER['REQUEST_URI'];
        $this->renderArr['backlink'] = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : false;
		$this->renderArr['host'] = $_SERVER['HTTP_HOST'];
        $this->renderArr['domain'] = Helper::getCurrentHost();
        $this->renderArr['date'] = strftime('%d.%m.%Y',time());
        $this->renderArr['year'] = strftime('%Y',time());
        $this->renderArr['time'] = strftime('%H:%M',time());
        $this->renderArr['basketuuid'] = Session::getValue('basket');
        $items = Session::getValue('card');
        if ($items && !empty($items)) {
            $this->renderArr['card'] = [];
            foreach ($items as $item) {
                $object = $item['item'];
                $object['quantity'] = $item['quantity'];
                $object['basket_item_id'] = $item['id'];
                $this->renderArr['card'][$item['item_type'].'s'][$item['item_id']] = $object;
            }
        }

        foreach ($_POST as $postKey => $postValue) {
            $this->renderArr['postParams'][$postKey] = $postValue;
        }

        foreach ($_GET as $getKey => $getValue) {
            $this->renderArr['getParams'][$getKey] = $getValue;
        }

		$this->renderArr['languages'] = $this->languages;
		strpos($_SERVER['SERVER_PROTOCOL'],'https') ? $this->renderArr['protocol'] = 'https://' : $this->renderArr['protocol'] = 'http://';

		if ($this->pathsegments && count($this->pathsegments)>1 && in_array($this->pathsegments[0],$this->languages)) {
			$this->renderArr['language'] = $this->pathsegments[0];
		} else {
			$this->renderArr['language'] = $this->defaultlang;
		}
	}

    public function connect() {

        if (defined('VINOU_MODE') && VINOU_MODE === 'Public') {
            $this->api = new PublicApi ();
        } else {
            $this->api = new Api ();

            if (!$this->api->connected) {
                $this->renderPage('error.twig', [
                    'pageTitle' => 'No Connection'
                ]);
                die();
            }

            $this->api->initBasket();

            $this->translation = $this->api->loadLocalization();
            foreach ($this->translation['wineregions'] as $countryregions) {
                $this->regions = array_replace($this->regions, $countryregions);
            }
            $this->countries = $this->translation['countries'];

            $this->renderArr['regions'] = $this->regions;
            $this->renderArr['countries'] = $this->countries;
        }

        return true;
    }

    public function loadProcessor($processor, $object) {
        $this->processors[$processor] = $object;
    }

    public function dataProcessing($options = NULL, $data = []) {
        if (!is_array($options))
            return false;

        foreach ($options as $key => $option) {
            if (is_string($option)) {
                $function = $option;
                $result = call_user_func_array([$this->api, $function], $data);
            }
            else if (is_array($option) && isset($option['function'])) {
                $function = $option['function'];
                unset($option['function']);

                if (isset($option['params'])) $data = array_merge($data,$option['params']);

                if (isset($option['postParams']) && !empty($_POST)) {
                    $allowedKeys = explode(',', $option['postParams']);
                    foreach ($_POST as $postKey => $postValue) {
                        if (in_array($postKey, $allowedKeys)) {
                            if (isset($data[$postKey]) && is_array($data[$postKey]))
                                $data[$postKey] = array_merge($data[$postKey],$postValue);
                            else
                                $data[$postKey] = $postValue;
                        }
                    }
                }

                if (isset($option['getParams']) && !empty($_GET)) {
                    $allowedKeys = explode(',', $option['getParams']);
                    foreach ($_GET as $getKey => $getValue) {
                        if (in_array($getKey, $allowedKeys)) {
                            if (isset($data[$getKey]) && is_array($data[$getKey]))
                                $data[$getKey] = array_merge($data[$getKey],$getValue);
                            else
                                $data[$getKey] = $getValue;
                        }
                    }
                }

                if (isset($option['useData'])) {
                    $dataToUse = explode(',', $option['useData']);
                    foreach ($dataToUse as $dataKey) {
                        if (isset($this->renderArr[$dataKey]) && $this->renderArr[$dataKey]) {
                            if (is_array($this->renderArr[$dataKey]))
                                $data = array_merge($data,$this->renderArr[$dataKey]);
                            else
                                array_push($data,$this->renderArr[$dataKey]);
                        }
                    }
                }

                if (isset($option['class']))
                    $result = call_user_func_array([$option['class'], $function], $data);
                elseif (isset($option['processor'])) {

                    if (!isset($this->processors[$option['processor']]))
                        throw new \Exception('data processor does not exists');

                    $result = $this->processors[$option['processor']]->{$function}($data);
                }
                else
                    $result = $this->api->{$function}($data);
            }
            else
                throw new \Exception('dataProcessing for this route could not be solved');

            $selector = isset($option['key']) ? $option['key'] : $key;

            if (isset($result[$selector])) {
                $this->renderArr[$key] = $result[$selector];
            }
            else
                $this->renderArr[$key] = $result;

            if (isset($result['clusters'])) {
                $this->renderArr['clusters'] = $result['clusters'];
            }

            if (isset($option['stopProcessing']) && $option['stopProcessing'] && !$result)
                break;
        }
    }

	private function initTwig($config) {
		$options = $this->options;

        // load template from page package if nothing is initiated
        if (empty($this->templateStorages)) {

            $packageResourcePath = str_replace('Classes/Tools', 'Resources', Helper::getClassPath(get_class($this)));

            foreach ($this->templateDirectories as $dir) {
                $packageDir = $packageResourcePath.'/'.$dir;
                if (is_dir($packageDir))
                    array_push($this->templateStorages, $packageDir);
            }
        }

		$loader = new \Twig_Loader_Filesystem($this->templateStorages);

        $settings = [
            'cache' => defined('VINOU_CACHE') ? VINOU_CACHE : Helper::getNormDocRoot().'Cache',
            'debug' => defined('VINOU_DEBUG') ? VINOU_DEBUG : false
        ];

        if (isset($config['cache']))
            $settings['cache'] = $config['cache'];

        if (isset($config['debug']))
            $settings['debug'] = $config['debug'];

		$twig = new \Twig_Environment($loader, $settings);

		// This line enables debugging and is included to activate dump()
		$twig->addExtension(new \Twig_Extension_Debug());
        $twig->addExtension(new \Vinou\Translations\TwigExtension(isset($options['language']) ? $options['language'] : 'de'));

		$twig->addFilter( new \Twig_SimpleFilter('cast_to_array', function ($stdClassObject) {
            $response = array();
            foreach ($stdClassObject as $key => $value) {
                $response[$key] = (array)$value;
            }
            return $response;
        }));

        $twig->addFilter( new \Twig_SimpleFilter('image', function ($imagesrc, $chstamp, $dimension = NULL) use($options) {
            $image = Images::storeApiImage($imagesrc, $chstamp);
            $extension = pathinfo($image['src'], PATHINFO_EXTENSION);
            if ($extension != 'svg' && !is_null($dimension)) {
                $prefix = $dimension;
                if (is_array($dimension)) {
                    list($width, $height) = $dimension;
                    $prefix = $width . 'x' . $height;
                }
                $targetFile = dirname($image['absolute']) . '/' . $prefix . '-' . basename($image['absolute']);

                if (!is_file($targetFile) || $image['recreate']) {
                    $resize = new ImageResize($image['absolute']);

                    if (is_integer($dimension))
                        $resize->resizeToWidth($dimension);
                    else if (is_array($dimension))
                        $resize->resizeToBestFit($width, $height);

                    $resize->save($targetFile);
                }
                $image['src'] = str_replace(Helper::getNormDocRoot(), '/', $targetFile);
            }
            return $image;
        }));

        $twig->addFilter( new \Twig_SimpleFilter('region', function ($region_id) {
            if (!is_numeric($region_id))
                return false;

            return isset($this->regions[$region_id]) ? $this->regions[$region_id] : $region_id;
        }));

        $twig->addFilter( new \Twig_SimpleFilter('taste', function ($taste_id) {
        	if (is_string($taste_id) && strlen($taste_id)>0) {
            	return $this->translation['tastes'][$taste_id];
        	} else {
        		return false;
        	}
        }));

        $twig->addFilter( new \Twig_SimpleFilter('groupBy', function ($array, $groupKey) {
            $return = [];
            foreach ($array as $item) {
                if (isset($item[$groupKey])) {
                    if (!isset($return[$item[$groupKey]]))
                        $return[$item[$groupKey]] = [];

                    if (isset($item['id']))
                        $return[$item[$groupKey]][$item['id']] = $item;
                    else
                        $return[$item[$groupKey]][] = $item;
                }
            }
            return $return;
        }));

        $twig->addFilter( new \Twig_SimpleFilter('sortBy', function ($array, $property, $direction = 'ASC') {
            usort($array, function($a, $b) use ($property, $direction) {
                $x = $a[$property];
                $y = $b[$property];

                if (is_numeric($x))
                    return $direction === 'ASC' ? $x > $y : $x < $y;
                else
                    return $direction === 'ASC' ? strcmp($x, $y) : strcmp($y, $x);

            });
            return $array;
        }));

        $twig->addFilter( new \Twig_SimpleFilter('ksort', function ($array) {
            ksort($array);
            return $array;
        }));

        $twig->addFilter( new \Twig_SimpleFilter('gettype', function ($var) {
            return gettype($var);
        }));

        $twig->addFilter( new \Twig_SimpleFilter('http', function ($src) {
            if (strpos($src,'://'))
                return $src;

            return 'http://'.$src;
        }));

        $twig->addFilter( new \Twig_SimpleFilter('filesize', function ($file) {
            $file = Helper::getNormDocRoot().$file;
            $bytes = floatval(filesize($file));
            $arBytes = array(
                0 => array(
                    "UNIT" => "TB",
                    "VALUE" => pow(1024, 4)
                ),
                1 => array(
                    "UNIT" => "GB",
                    "VALUE" => pow(1024, 3)
                ),
                2 => array(
                    "UNIT" => "MB",
                    "VALUE" => pow(1024, 2)
                ),
                3 => array(
                    "UNIT" => "KB",
                    "VALUE" => 1024
                ),
                4 => array(
                    "UNIT" => "B",
                    "VALUE" => 1
                ),
            );

            foreach($arBytes as $arItem)
            {
                if($bytes >= $arItem["VALUE"])
                {
                    $result = $bytes / $arItem["VALUE"];
                    $result = str_replace(".", "," , strval(round($result, 2)))." ".$arItem["UNIT"];
                    break;
                }
            }
            return $result;
        }));

        $twig->addFilter( new \Twig_SimpleFilter('bytes', function ($bytes, $precision = 2) { 
            $units = array('B', 'KB', 'MB', 'GB', 'TB'); 

            $bytes = max($bytes, 0); 
            $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 
            $pow = min($pow, count($units) - 1); 

            // Uncomment one of the following alternatives
            $bytes /= pow(1024, $pow);
            // $bytes /= (1 << (10 * $pow)); 

            return round($bytes, $precision) . ' ' . $units[$pow]; 
        }));

        //filter for decimal->brutto conversion 123.456 => 123.46
        $twig->addFilter( new \Twig_SimpleFilter('brutto', function($decimal) {
            return $decimal;
        }));

        //filter for decimal->netto conversion 123.456 => 146.92
        $twig->addFilter( new \Twig_SimpleFilter('netto', function($decimal) {
            return ceil($decimal * 10000 /119 ) / 100;
        }));

        //filter for formatting prices to currency: 123456.78 => 123.456,78
        $twig->addFilter( new \Twig_SimpleFilter('currency', function($decimal) {
            return number_format($decimal,2,',','.');
        }));

        $twig->addFilter( new \Twig_SimpleFilter('cleanup', function($string) {
        	$string = substr($string, 0, 1) == ' ' ? substr($string, 1, strlen($string)) : $string;
        	$string = str_replace(',', '', $string);
        	$string = str_replace('@', '', $string);
        	$string = str_replace('"', '', $string);
            return $string;
        }));

        $twig->addFilter( new \Twig_SimpleFilter('src', function($file) {

            if (!is_file(Helper::getNormDocRoot().'/'.$file))
                return false;

        	$change_date = @filemtime(Helper::getNormDocRoot().'/'.$file);
	        if (!$change_date) {
	            //Fallback if mtime could not be found:
	            $change_date = mktime(0, 0, 0, date('m'), date('d'), date('Y'));
	        }
            return $file.'?'.$change_date;
        }));

        //filter for formatting prices to currency: 123456.78 => 123.456,78
        $twig->addFilter( new \Twig_SimpleFilter('arraytocsv', function($array) {
        	if (is_array($array)) {
            	return implode(',',$array);
            } else {
            	return false;
            }
        }));

        //sum of an array of numbers
        $twig->addFilter( new \Twig_SimpleFilter('sum', function($arr) {
            return array_reduce($arr, function($carry,$item){ return $carry+$item; },0);
        }));

        //return a subarray from an array of array by index
        $twig->addFilter( new \Twig_SimpleFilter('subArray', function($arr,$index) {
            return array_map(function($item) use ($index) { return $item[$index]; }, $arr);
        }));

        $twig->addFilter( new \Twig_SimpleFilter('withAttribute', function($arr,$attr, $value) {
            return array_filter($arr,
                                function($item) use ($attr, $value) {
                                    if (is_array($item[$attr]))
                                        return isset($item[$attr][$value]);
                                    else
                                        return $item[$attr] == $value;
                                });
        }));

        $twig->addFilter( new \Twig_SimpleFilter('withoutAttribute', function($arr,$attr, $value) {
            return array_filter($arr,
                                function($item) use ($attr, $value) {
                                    return $item[$attr] != $value;
                                });
        }));

        $twig->addFilter( new \Twig_SimpleFilter('price', function($items) {
            return array_reduce($items,
                                function($carry, $item){
                                    return $carry + ($item['price'] * $item['quantity']);
                                },
                                0);
        }));

        $twig->addFilter( new \Twig_SimpleFilter('wines', function($items) {
            return array_filter($items,
                                function($item){
                                    return $item['type'] == 'wine';
                                });
        }));

        $twig->addFilter( new \Twig_SimpleFilter('packages', function($items) {
            return array_filter($items,
                                function($item){
                                    return $item['type'] == 'package';
                                });
        }));

        $twig->addFilter( new \Twig_SimpleFilter('base64image', function($url) {
            return Helper::imageToBase64($url);
        }));

        $twig->addFilter( new \Twig_SimpleFilter('grapetypes', function($array) {
            $return = [];
            foreach ($array as $entry) {
                if (is_array($entry)) {
                    foreach ($entry as $id) {
                        $return[$id] = $this->translation['grapetypes'][$id]['name'];
                    }
                } else {
                    $return[$entry] = $this->translation['grapetypes'][$entry]['name'];
                }
            }
            asort($return);
            return $return;

        }));

        $twig->addFilter( new \Twig_SimpleFilter('link', function($label,$url,$additionalParams = null, $options = null) {
        	$classSuffix = $_SERVER['REQUEST_URI'] == $url ? ' active' : false;

            $link = '<a href="'.$url.'"';

            if (!is_array($additionalParams))
                $additionalParams = [
                    'class' => is_string($additionalParams) ? $additionalParams : ''
                ];

            foreach ($additionalParams as $attribute => $value) {
                //attribute processing
                switch ($attribute) {
                    case 'class':
                        if ($classSuffix) {
                            $value .= $classSuffix;
                        }

                        break;

                    default:
                        break;
                }

                //attribute rendering
                $link .= ' ' . $attribute .'="' . $value . '"';
            }

            return $link . '>'. $label . '</a>';

        }, array('is_safe' => array('html'))));

        $twig->addFilter( new \Twig_SimpleFilter('language', function($value,$translations,$key,$current) {
        	$class = $current == $key ? 'active' : '';
        	if (isset($translations[$key])) {
            	$link = '<a href="'.$translations[$key].'" class="'.$class.'">'.$value.'</a>';
        	} else {
        		$link = '<a class="disabled '.$class.'">'.$value.'</a>';
        	}
            return $link;
        }, array('pre_escape' => 'html', 'is_safe' => array('html'))));

		return $twig;
	}

	private function addMDContent($mdfile) {
		$Parsedown = new \Parsedown();
		$absFile = Helper::getNormDocRoot() . $mdfile;
		$content = file_get_contents($absFile);
		if (strpos($absFile,'.md')) {
			$this->renderArr['content'] = $Parsedown->text($content);
		} else {
			$this->renderArr['content'] = $content;
		}
	}

	public function renderPage($template = 'Default.twig',$options = NULL){
		$view = $this->initTwig(isset($options['twig']) ? $options['twig'] : NULL);
		$template = $view->loadTemplate($template);
		if (!is_null($options)) {
            if (isset($options['public']) && !$options['public'] && !Session::getValue('client'))
                $this->forbidden();
			$this->renderOptions($options);
        }
		echo $template->render($this->renderArr);
		exit();
	}

	public function renderOptions($options) {
		if (is_array($options)) {
			foreach ($options as $name => $value) {
				switch ($name) {
					case 'content':
						$this->renderArr[$name] = $this->addMDContent($value);
					default:
						$this->renderArr[$name] = $value;
						break;
				}
			}
		} else if (is_string($options)) {
			$this->renderArr['pageTitle'] = $options;
		}
	}

	public function redirect($target) {
        if (strncmp($target,'http',4) === 0)
            Redirect::external($target);
        else
            Redirect::internal($target);
	}

    public function forbidden() {
        if (isset($this->config['permissionRedirect']))
            Redirect::internal($this->config['permissionRedirect']);
    }

	public function setTemplateRootPath($path) {
		$this->templateRootPath = $path;
	}

	public function setTemplateDirectories($directories) {
		$this->templateDirectories = $directories;
	}

    public function loadDefaultStorages() {
        // load web root storages every time
        foreach ($this->templateDirectories as $dir) {
            $resourceDir = Helper::getNormDocRoot().$this->templateRootPath.$dir;
            if (is_dir($resourceDir) && !in_array($resourceDir, $this->templateStorages))
                array_push($this->templateStorages, $resourceDir);
        }
    }

    public function addTemplateStorages($rootDir, $folders = []) {
        $rootDir = substr($rootDir, 0, 1) == '/' ? $rootDir : Helper::getNormDocRoot().$rootDir;
        if (empty($folders) && is_dir($rootDir)) {
            array_push($this->templateStorages, $rootDir);
        } else {
            foreach ($folders as $dir) {
                if (is_dir($rootDir.$dir))
                    array_push($this->templateStorages, $rootDir.$dir);
            }
        }
    }

    public function setLanguages($keys) {
    	$this->languages = $keys;
    }

    public function setDefaultLanguage($key) {
    	$this->defaultlang = $key;
    }

}


?>