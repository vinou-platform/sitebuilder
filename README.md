# Vinou Site-Builder

The Vinou Site-Builder is a PHP library that combines PHP routing configured in YAML files with Twig template rendering. It provides a data processing pipeline to call registered processors and pipe results directly into Twig templates.

### Table of contents

- [Typical project structure](#typical-project-structure)
- [Installation](#installation)
- [Route configuration](#route-configuration)
    1. [General route parameters](#1-general-route-parameters)
    2. [Sitemap configuration](#2-sitemap-configuration-for-a-route)
    3. [dataProcessing](#3-use-dataprocessing)
    4. [Extend a parent route](#4-extend-a-parent-route)
    5. [Global content (additionalContent)](#5-global-content-additionalcontent)
    6. [Registered processors](#6-registered-processors)
    7. [Register your own processor](#7-register-your-own-processor)
- [Settings](#settings)
- [Template override hierarchy](#template-override-hierarchy)
- [Twig filters](#twig-filters)
- [Classlist](#classlist)
- [Provider](#provider)

---

## Typical project structure

| File | Description |
|:-----|:------------|
| `composer.json` | Composer configuration |
| `config/settings.yml` | SiteBuilder and ApiConnector settings |
| `config/routes.yml` | Project-specific routes |
| `config/mail.yml` | SMTP credentials and form definitions |
| `public/index.php` | Application entry point |
| `public/.htaccess` | Routes all requests to index.php |
| `public/Resources/Layouts/` | Twig layout overrides |
| `public/Resources/Partials/` | Twig partial overrides |
| `public/Resources/Templates/` | Page templates |
| `public/Resources/Sass/` | SCSS source files |

The complete example project is available in `Examples/Project/`.

---

## Installation

```bash
composer require vinou/site-builder
cp -R vendor/vinou/site-builder/Examples/Project/config ./
cp -R vendor/vinou/site-builder/Examples/Project/web ./
```

**Required after installation:**
- [ ] Set Vinou `authid` and `token` in `config/settings.yml` — find them in [Vinou Office](https://app.vinou.de)
- [ ] Set SMTP credentials in `config/mail.yml`

**Typical optional setup:**
- [ ] Add project routes to `config/routes.yml`
- [ ] Configure Vinou constants in `public/index.php`

**Minimal `public/index.php`:**
```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

define('VINOU_ROOT', realpath('./'));
define('VINOU_MODE', 'Shop');
define('VINOU_CONFIG_DIR', '../config/');

$session = new \Vinou\ApiConnector\Session\Session();
$session::setValue('language', 'de');

$site = new \Vinou\SiteBuilder\Site();
$site->setRouteFile('routes.yml');
$site->loadTheme('my-theme', 'Theme/MyTheme/');
$site->run();
```

---

## Route configuration

### 1. General route parameters

| Parameter | Default | Options | Description |
|:----------|:--------|:--------|:------------|
| `type` | `page` | `page` | Render a Twig template |
| | | `redirect` | Redirect to internal or external URL |
| | | `namespace` | Group sub-routes under a URL prefix |
| | | `sitemap` | Render a sitemap.xml |
| `method` | `get` | `get` \| `post` \| `all` | Accepted HTTP method |
| `template` | — | `Pages/start.twig` | Twig template to render |
| `redirect` | — | `/other/page` or `https://…` | Redirect target (requires `type: redirect`) |
| `pageTitle` | — | `string` | Page title, available as `{{ pageTitle }}` in templates |
| `public` | `true` | `true` \| `false` | `false` requires a Vinou client login |
| `sitemap` | `false` | `true` \| `false` \| _Array_ | Include in sitemap; array for dynamic entry generation |
| `twig` | — | `cache: false` | Override Twig settings for this route |
| `urlKeys` | — | `[slug, id]` | Named aliases for URL wildcard segments |
| `dataProcessing` | — | _Array_ | Data loading steps, results available in template |
| `postProcessing` | — | _Array_ | Runs after `dataProcessing`, same syntax |
| `extend` | `false` | `true` | Deep-merge with parent route instead of replacing it |
| `excludeContent` | — | `[key1, key2]` | Skip specific `additionalContent` items for this route |

### 2. Sitemap configuration for a route

Use `sitemap` as an array on routes with a URL wildcard to generate one sitemap entry per item:

```yaml
weine/{path_segment}:
  template: 'Wines/Detail.twig'
  pageTitle: 'Wein-Details'
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

| Parameter | Description |
|:----------|:------------|
| `function` | API-Connector function to fetch the list of items |
| `params` | Parameters passed to the function |
| `params/lazy` | Load items in recurring pages (recommended for large datasets) |
| `params/pageSize` | Items per page when using lazy loading |
| `dataKey` | Key in the API response containing the items array |

### 3. Use dataProcessing

Each entry in `dataProcessing` calls a function in a processor and stores the result under the given key in the Twig context.

**Shorthand** — calls an API-Connector function directly:
```yaml
dataProcessing:
  wines: getWinesAll
  wineries: getWineriesAll
```

**Full syntax:**
```yaml
dataProcessing:
  wines:
    function: getWinesAll
    params:
      pageSize: 50
      orderBy: topseller DESC
      cluster:
        - type
        - taste_id
        - vintage
```

**Combining data with `formatter/mergeData`:**
```yaml
dataProcessing:
  wines:
    function: getWinesAll
  bundles:
    function: getBundlesAll
  items:
    processor: formatter
    function: mergeData
    useRouteData: false
    useData:
      - wines
      - bundles
```

`mergeData` flattens sub-arrays into one list and sets `object_type` per entry (e.g. `wines`, `bundles`).

**Full parameter reference:**

| Parameter | Example | Description |
|:----------|:--------|:------------|
| `processor` | `formatter` | Registered processor identifier (default: Vinou API) |
| `class` | `\Vendor\Ns\Processor` | Instantiate a class directly as processor |
| `function` | `getWinesAll` | Function name to call on the processor |
| `params` | _Array_ | Static parameters passed to the function |
| `useRouteData` | `false` | Set to `false` to exclude URL wildcard segments from function arguments |
| `useData` | `[wines, bundles]` | Keys already in `renderArr` to pass as input |
| `key` | `wines` | Key to extract from the function's return value |
| `forceLoadAll` | `true` | Use the complete return value instead of extracting by key |
| `loadOnlyFirst` | `true` | Use only the first element of the result array |
| `postParams` | `query,page` | Comma-separated POST field names to forward (whitelist) |
| `getParams` | `query,page` | Comma-separated GET parameter names to forward (whitelist) |
| `stopProcessing` | `true` | Abort remaining `dataProcessing` steps if result is empty |

### 4. Extend a parent route

Setting `extend: true` on a project route causes it to deep-merge (`array_replace_recursive`) with the existing route definition from the theme instead of replacing it entirely. Only the keys you define are overridden; all other settings are inherited.

```yaml
# config/routes.yml
weine:
  extend: true
  pageTitle: 'Unser Weinshop'      # overrides theme value
  dataProcessing:
    text:                           # added on top of theme dataProcessing
      function: getText
      params:
        identifier: 'weine'
```

Without `extend: true`, the project route replaces the theme route completely.

### 5. Global content (additionalContent)

`additionalContent` is defined in `settings.yml` (typically in the theme) and runs on every page before route-specific `dataProcessing`. Results are available in all templates.

```yaml
# Theme/MyTheme/Configuration/settings.yml
additionalContent:
  categories:
    function: getCategoriesAll
    params:
      orderBy: sorting ASC
  client: getClient
  paymentMethods:
    function: getAvailablePayments
    key: payments
```

To skip specific items on a route, use `excludeContent`:

```yaml
# config/routes.yml
kontakt:
  template: 'Pages/kontakt.twig'
  excludeContent: [categories]
  dataProcessing:
    captcha:
      processor: mailer
      function: loadCaptcha
```

### 6. Registered processors

| Identifier | Class | Description |
|:-----------|:------|:------------|
| _(default)_ | `\Vinou\ApiConnector\Api` | All Vinou API calls (`get*`, `search*`, etc.) |
| `shop` | `\Vinou\SiteBuilder\Processors\Shop` | Basket, billing, delivery, payments, campaigns |
| `mailer` | `\Vinou\SiteBuilder\Processors\Mailer` | Form handling, captcha, email dispatch |
| `files` | `\Vinou\SiteBuilder\Processors\Files` | Read local files with metadata |
| `external` | `\Vinou\SiteBuilder\Processors\External` | Fetch external URLs / JSON |
| `sitemap` | `\Vinou\SiteBuilder\Processors\Sitemap` | Sitemap XML generation |
| `formatter` | `\Vinou\SiteBuilder\Processors\Formatter` | Merge and format loaded data |

### 7. Register your own processor

Create a processor class:
```php
<?php
namespace YourVendor\YourNamespace\Processors;

class YourProcessor extends \Vinou\SiteBuilder\Processors\AbstractProcessor {

    public function dataMagic($data = null) {
        // transform data here
        return $data;
    }

}
```

Register it in `index.php` before calling `$site->run()`:
```php
$site = new \Vinou\SiteBuilder\Site();
$site->render->loadProcessor('myprocessor', new \YourVendor\YourNamespace\Processors\YourProcessor());
$site->run();
```

Use it in a route:
```yaml
my-route:
  template: 'Pages/template.twig'
  dataProcessing:
    result:
      processor: myprocessor
      function: dataMagic
      params:
        foo: bar
```

---

## Settings

Settings are loaded from YAML files and merged in this order (later wins):

1. Theme: `YourTheme/Configuration/settings.yml`
2. Project: `config/settings.yml`

The theme controls whether SiteBuilder built-in defaults are loaded:
```yaml
system:
  load:
    defaultRoutes: false    # disable SiteBuilder's bundled routes
    defaultSettings: false  # disable SiteBuilder's bundled settings
```

**Global settings reference (`settings` key):**

| Key | Description |
|:----|:------------|
| `defaults.payment` | Default payment method |
| `allowedPayments` | Comma-separated list of accepted payment methods |
| `minBasketSize` | Minimum number of bottles required to check out |
| `packageSteps` | Allowed basket sizes as array (e.g. `[6,12,18]`) |
| `maxItemQuantity` | Maximum quantity per basket item |
| `enableClickAndCollect` | Enable click & collect option |
| `useStockDistribution` | Distribute stock across positions |
| `deliveryCountries` | Array of ISO country codes for delivery |
| `defaultCountry` | Pre-selected country code |
| `speechStyle` | `formal` or `informal` — controls salutation in templates |
| `seo.titleAdd` | String appended to every page title |
| `permissionRedirect` | URL to redirect unauthenticated users to |
| `pages.*` | Internal URLs for basket, checkout, login, etc. |
| `images.*` | Image dimensions for list and detail views |

---

## Template override hierarchy

SiteBuilder uses Twig's `FilesystemLoader` with an ordered list of directories. The **first matching template wins**.

Default search order:
1. `public/Resources/Templates/` — project
2. `public/Resources/Partials/` — project
3. `public/Resources/Layouts/` — project
4. `Theme/Resources/Layouts/` — theme
5. `Theme/Resources/Partials/` — theme
6. `Theme/Resources/Templates/` — theme

A template in `public/Resources/Templates/Wines/List.twig` will silently override `Theme/Resources/Templates/Wines/List.twig`.

The same priority applies to route loading:
1. SiteBuilder built-in routes (lowest priority, loaded as base)
2. Theme routes (override SiteBuilder)
3. Project `config/routes.yml` (highest priority)

---

## Twig filters

All filters are registered by `\Vinou\SiteBuilder\Tools\Render`.

| Filter | Signature | Description |
|:-------|:----------|:------------|
| `image` | `src\|image(chstamp, dimension)` | Download and cache API image; optionally resize |
| `pdf` | `src\|pdf(chstamp)` | Download and cache API PDF |
| `src` | `path\|src` | Append file modification timestamp for cache busting |
| `region` | `id\|region` | Resolve wine region ID to name |
| `taste` | `id\|taste` | Resolve taste ID to label |
| `grapetypes` | `array\|grapetypes` | Resolve grape type IDs to name map |
| `link` | `label\|link(url, params)` | Render an `<a>` tag; adds `active` class on current URL |
| `language` | `value\|language(translations, key, current)` | Render language switch link |
| `currency` | `decimal\|currency` | Format number as price: `1234.5` → `1.234,50` |
| `netto` | `decimal\|netto` | Convert gross to net (÷1.19) |
| `basePrice` | `price\|basePrice(unit)` | Format base price with unit label |
| `price` | `items\|price` | Sum `price × quantity` across a basket items array |
| `nl2p` | `string\|nl2p` | Convert newlines to `<p>` tags |
| `pageTitle` | `object\|pageTitle` | Build a page title from `name`, `articlenumber`, `vintage` |
| `groupBy` | `array\|groupBy(key)` | Group array by a property value |
| `sortBy` | `array\|sortBy(prop, dir)` | Sort array by property (`ASC`\|`DESC`) |
| `ksort` | `array\|ksort` | Sort array by key |
| `withAttribute` | `array\|withAttribute(attr, val)` | Filter: keep entries where `attr == val` |
| `withoutAttribute` | `array\|withoutAttribute(attr, val)` | Filter: exclude entries where `attr == val` |
| `subArray` | `array\|subArray(index)` | Extract one property from each entry |
| `addProperty` | `array\|addProperty(key, val)` | Add a fixed property to every entry |
| `sum` | `array\|sum` | Sum an array of numbers |
| `wines` | `items\|wines` | Filter basket items to type `wine` |
| `packages` | `items\|packages` | Filter basket items to type `package` |
| `getBundle` | `id\|getBundle` | Fetch a single bundle from the API |
| `getWinery` | `id\|getWinery` | Fetch a single winery from the API |
| `quantityIsAllowed` | `qty\|quantityIsAllowed` | Check if quantity meets basket rules |
| `cast_to_array` | `obj\|cast_to_array` | Cast stdClass to associative array |
| `arraytocsv` | `array\|arraytocsv` | Join array to comma-separated string |
| `filesize` | `file\|filesize` | Human-readable file size (KB, MB, …) |
| `bytes` | `bytes\|bytes(precision)` | Format raw byte count |
| `cleanup` | `string\|cleanup` | Strip leading space, commas, `@`, quotes |
| `http` | `url\|http` | Prepend `http://` if no protocol present |
| `base64image` | `url\|base64image` | Encode image as base64 data URI |

---

## Classlist

| Class | Description |
|:------|:------------|
| `\Vinou\SiteBuilder\Site` | Main class; combines router, renderer, and settings loader |
| `\Vinou\SiteBuilder\Loader\Settings` | Collects and merges settings YAML files |
| `\Vinou\SiteBuilder\Tools\Render` | Initialises Twig, registers filters, executes dataProcessing |
| `\Vinou\SiteBuilder\Router\DynamicRoutes` | Reads YAML route files and configures the Bramus router |
| `\Vinou\SiteBuilder\Processors\AbstractProcessor` | Base class for custom processors; provides `loadApi()` |
| `\Vinou\SiteBuilder\Processors\Shop` | Shop-specific processing (basket, checkout, campaigns) |
| `\Vinou\SiteBuilder\Processors\Mailer` | Form dispatch and captcha |
| `\Vinou\SiteBuilder\Processors\Formatter` | Data combination utilities (`mergeData`) |
| `\Vinou\SiteBuilder\Processors\Files` | Local file reading |
| `\Vinou\SiteBuilder\Processors\External` | External HTTP requests |
| `\Vinou\SiteBuilder\Processors\Sitemap` | Sitemap XML generation |

---

## Provider

This library is developed by Vinou GmbH.

![](http://static.vinou.io/brand/logo/red.svg)

Vinou GmbH<br>
Justus-von-Liebig-Straße 9e<br>
55232 Alzey<br>
E-Mail: [kontakt@vinou.de](mailto:kontakt@vinou.de)<br>
Phone: [+49 6131 6245390](tel:+4961316245390)
