<?php
namespace Vinou\SiteBuilder\Processors;

use \Vinou\ApiConnector\Api;

/**
 * Base class for all SiteBuilder data processors.
 *
 * Provides a consistent hook for injecting the Vinou API instance into
 * processor classes that are registered via Site::loadProcessor() and
 * referenced in route dataProcessing steps.
 */
class AbstractProcessor {

    /** @var Api|null Vinou API instance injected after construction. */
    public ?Api $api = null;

    public function __construct() {}

    /**
     * Injects the active Vinou API instance into this processor.
     *
     * Called automatically by Render::dataProcessing() when the processor
     * extends AbstractProcessor.
     *
     * @param Api $api  Reference to the active API instance.
     * @throws \Exception If the provided API reference is null.
     */
    public function loadApi(Api &$api): void {
        $this->api = $api;
    }
}
