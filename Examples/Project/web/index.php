<?php
    require_once __DIR__ . '/../vendor/autoload.php';

    define('VINOU_ROOT', realpath('./'));
    define('VINOU_MODE', 'Shop');
    define('VINOU_CONFIG_DIR', '../config/');

    // INIT SESSION BEFORE ALL THE OTHER STUFF STARTS
    $session = new \Vinou\ApiConnector\Session\Session ();
    $session::setValue('language','de');

    $site = new \Vinou\SiteBuilder\Site ();
    $site->setRouteFile('routes.yml');
    $site->setSettingsFile('settings.yml');
    $site->run();