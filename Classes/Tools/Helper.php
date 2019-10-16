<?php
namespace Vinou\Utilities\General\Tools;

use \Composer\Autoload\ClassLoader;

/**
 * Api
 */

class Helper {

    const APILIVE = 'https://api.vinou.de';
    const APISANDBOX = 'https://api.sandbox.vinou.de';
    const APIDEV = 'http://api.vinou.frog';

    private static $normDocRoot = NULL;

    public static function getNormDocRoot(){
        if(self::$normDocRoot == NULL){
            $str = defined('VINOU_ROOT') ? VINOU_ROOT : $_SERVER['DOCUMENT_ROOT'];
            $strLength = strlen($str);
            if($strLength > 0){
                if($str[0] != '/'){
                    $str = '/'.$str;
                    $strLength++;
                }
                if($str[$strLength-1] != '/') {
                    $str = $str.'/';
                }
            }
            self::$normDocRoot = $str;
        }
        return self::$normDocRoot;
    }

    public static function getApiUrl() {
        switch ($_SERVER['HTTP_HOST']) {
            case "shop.vinou.frog":
                $apiurl = self::APIDEV;
                break;
            case "shop.sandbox.vinou.de":
                $apiurl = self::APISANDBOX;
                break;
            default:
                $apiurl = self::APILIVE;
                break;
        }
        return $apiurl;
    }

    public static function getCurrentHost() {
    	return $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'];
    }

    public static function getClassPath($class = "\Composer\Autoload\ClassLoader")
    {
        $reflector = new \ReflectionClass($class);
        $ClassPath = $reflector->getFileName();
        if($ClassPath && is_file($ClassPath)) {
            $segments = explode('/',$ClassPath);
            array_pop($segments);
            return implode('/',$segments);
        }
        throw new \RuntimeException('Unable to detect vendor path.');
    }

    public static function findKeyInArray($keyArray,$array) {
        $searchArray = $array;
        foreach ($keyArray as $key) {
            if (isset($searchArray[$key])) {
                $searchArray = $searchArray[$key];
            } else {
                return false;
            }
        }
        return $searchArray;
    }
}
