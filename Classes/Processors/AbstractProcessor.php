<?php
namespace Vinou\SiteBuilder\Processors;

use \Vinou\ApiConnector\Api;

/**
 * Base class for processors that require Vinou API access.
 *
 * Implements ApiAwareInterface so that Render::dataProcessing() automatically
 * injects the API instance before the processor's method is called.
 * Processors that do not need the API should implement ProcessorInterface directly.
 */
class AbstractProcessor implements ApiAwareInterface {

    /** @var Api|null Vinou API instance injected after construction. */
    public ?Api $api = null;

    public function __construct() {}

    /**
     * Injects the active Vinou API instance into this processor.
     *
     * Called automatically by Render::dataProcessing() when the processor
     * implements ApiAwareInterface.
     *
     * @param Api $api  Reference to the active API instance.
     * @throws \Exception If the provided API reference is null.
     */
    public function loadApi(Api &$api): void {
        $this->api = $api;
    }
}
