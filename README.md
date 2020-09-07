# Vinou Site-Builder

The Vinou Site-Builder is a PHP library that easily combine a basic php routing configured in a routes.yml file with Twig template rendering. The library also provides the possible to call libraries as data processors and pipe the result directly within the twig template.

### Table of contents

## Typical project structure

|File            |Description       |
|:---------------|:-----------------|
|composer.json|Main composer configuration to combine the whole site into one package|
|config/settings.yml|All settings regarding SiteBuilder and ApiConnector|
|config/routes.yml|All routes that are dynamically generated and rendered via sitebuilder|
|config/mail.yml|Mail settings including smtp credentials|
|web/index.php|Main instatiation|
|web/.htaccess|Htaccess configuration mainly to link all requests to index.php|
|web/Resources/Layouts|Layout folder|
|web/Resources/Layouts/Default.twig|Default Twig-Layout|
|web/Resources/Partials|_optional_ Partials Folder|
|web/Resources/Templates/start.twig|Template for start page|

**This example project structur can be found inside the repository in the Example/Project folder**

## Installation (via typical project structure)

This installation guide is based on our preferred project structure. This structure is developed over years and adopted from many OSS projects. Special thanks are going to the great TYPO3 community that we are participating over years and delivers a great knowhow and share ideas over years.

**1. Setup project structure**

```bash
composer require vinou/site-builder
cp -R vendor/vinou/site-builder/Examples/Project/config ./
cp -R vendor/vinou/site-builder/Examples/Project/web ./
```

**2. Modify your installation**

Required modifications!
- [ ] Fill in your Vinou AuthId and token in config/settings.yml you can find them in your [Vinou-Office](https://app.vinou.de)
- [ ] Fill in your mail server credentials in config/mail.yml

Optional but typical modifications
- [ ] Configure Vinou API-Connector via Vinou constants
- [ ] Add basic but needed routes in config/routes.yml

## Route configuration

### 1. General route parameters

|Parameter             |Default                 |Options                 |Description                |
|:---------------------|:-----------------------|:-----------------------|:--------------------------|
|type|page|`page`|generate page from twig template|
|||`redirect`|redirect to external or internal page|
|method|get|`get`|page is only callable via GET requests|
|||`post`|page is only callable via POST requests|
|||`all`|page is callable by each type of http requests|
|template|not set|`Path/to/template.twig`|template file to be used for this page|
|redirect|not set|`/route/to/local/page`|local page for redirect|
|||`http://www.google.de`|you can also use URLs for redirect|
|pageTitle|not set|`Start page`|Title of page shown in title tag and used as variable in template|
|public|true|`true`|Page always callable|
|||`false`|Page only callable with Vinou client login|
|sitemap|false|`true`|Page listed in sitemap.xml|
|||`false`|Page not listed in sitemap.xml|
|||_Array_|Array of options to generate sitemap entries see sitemap paramaters|
|twig|not set|_Array_|Array of options that modify twig behaviour|
|dataProcessing|not set|_Array_|Array of keys that are filled with the result of called functions|

### 2. Load sitemap configuration for route

**PLEASE NOTICE: to use sitemap parameters your route must contain a variable placeholder**
```yaml
wines/{path_segment}:
  template: 'Wines/detail.twig'
  pageTitle: 'Wine detail page'
  public: true
  sitemap:
    function: getWinesAll
    params:
      lazy: false
      pageSize: 500
    dataKey: 'wines'
  dataProcessing:
    wine: getWine
```

|Parameter             |Value (Example)         |Description                |
|:---------------------|:-----------------------|:--------------------------|
|function|`getWinesAll`|function name in Vinou API-Connector that is called to fetch items|
|params|_Array_|Array of params that are piped into the sitemap renderer|
|params/lazy|_Boolean_|load entries recurring, can be useful if huge data is loaded|
|params/pageSize|_Integer_|number of entries loaded in one recurring process|
|dataKey|`wines`|Key in API result that contains the entries to generate different pages|

### 3. Use dataProcessing

The main principle is that you can define a key that is filled by the result of the function that is called within a specific processor. If no processor is set the Vinou API-Connector is used by default.

**SHORTHAND NOTICE: If you don't need any additional function configuration and want to call an API-Connector function you can define a key directly with the function name**

Example shorthand calls for wines and wineries
```yaml
wines/{path_segment}:
  template: 'Wines/Detail.twig'
  pageTitle: 'wine detail page'
  public: true
  dataProcessing:
    wines: getWinesAll
    wineries: getWineriesAll
```

Example advanced config to combine wines and bundles into one items array
```yaml
wines/{path_segment}:
  template: 'Wines/Detail.twig'
  pageTitle: 'wine detail page'
  public: true
  dataProcessing:
    wines: getWinesAll
    bundles: getBundlesAll
    items:
      processor: 'formatter'
      function: 'mergeData'
      useRouteData: FALSE
      useData:
        - wines
        - bundles
    wineries: getWineriesAll
```

|Parameter             |Value (Example)         |Description                |
|:---------------------|:-----------------------|:--------------------------|
|processor|`formatter`|identifier of processor where class is registered (see processor list)|
|class|`\Vendor\Namespace\Class`|use namespace call to load a class as a processor|
|function|`getWinesAll`|function name in Vinou API-Connector that is called to fetch items|
|params|_Array_|Array of params that are piped into the function|
|useRouteData|_Boolean_|Set to false is wildcard variables from route should not be piped into the function|
|useData|_Array_|Array of keys that are processed in the same dataProcessing before and should be piped into the function|
|dataKey|`wines`|Key in API result that contains the result|
|getParams|`firstname,lastname`|comma separated names of GET variables that should be piped into the function|
|postParams|`firstname,lastname`|comma separated names of POST variables that should be piped into the function|

### 4. Registered processors

|identifier            |Class                   |API available in Processor |Description                |
|:---------------------|:-----------------------|:--------------------------|:--------------------------|
|_default_|\Vinou\ApiConnector\Api|no|Basic Vinou API calls|
|`shop`|\Vinou\SiteBuilder\Processors\Shop|yes|Basic Shop functions combined with Vinou API|
|`mailer`|\Vinou\SiteBuilder\Processors\Mailer|yes|Send mails from template forms|
|`files`|\Vinou\SiteBuilder\Processors\Files|no|Read local files with meta data|
|`external`|\Vinou\SiteBuilder\Processors\External|no|Load URLs, external files e.g. JSON files|
|`sitemap`|\Vinou\SiteBuilder\Processors\Sitemap|no|Generate sitemaps|
|`formatter`|\Vinou\SiteBuilder\Processors\Formatter|no|Combine and format loaded data|

### 5. Register your own processor

Create your own processor
```php
<?php
namespace YourVendor\YourNamespace\Processors;

class YourProcessor extends \Vinou\SiteBuilder\Processors\AbstractProcessor {

  public function dataMagic ($data = NULL) {
    $formattedData = $data;
    // transform your data here or do other stuff
    return $formattedData;
  }

}
```

Go to your index.php and load the processor **before SiteBuilder instantiation**
```php
require_once __DIR__ . '/Path/to/YourProcessor.php';
```

Than use your processor directly in your route config
```yaml
your-route-with-processor:
  template: 'Pages/template.twig'
  pageTitle: 'Page title'
  public: true
  dataProcessing:
    templatekey:
      class: '\YourVendor\YourNamespace\Processors\YourProcessor'
      funtion: 'dataMagic'
```

## Classlist

|Class                |Description          |
|:--------------------|:--------------------|
|\Vinou\SiteBuilder\Site|Main class of Sitebuilder that combines router and renderer|
|\Vinou\SiteBuilder\Tools\Render|Renderer that inits twig, delivers some twig filter and do the variable fill in stuff including data processing|
|\Vinou\SiteBuilder\Router\DynamicRoutes|Read yaml files and configure bramus router with this yaml and do rendering|
|\Vinou\SiteBuilder\Processors\AbstractProcessor|Abstract processor to easily use api in processor|

## Provider

This Library is developed by the Vinou GmbH.

![](http://static.vinou.io/brand/logo/red.svg)

Vinou GmbH<br> 
Mombacher Straße 68<br>
55122 Mainz<br>
E-Mail: [kontakt@vinou.de](mailto:kontakt@vinou.de)<br>
Phone: [+49 6131 6245390](tel:+4961316245390)
