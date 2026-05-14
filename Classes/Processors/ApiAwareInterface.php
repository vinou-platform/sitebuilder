<?php
namespace Vinou\SiteBuilder\Processors;

use \Vinou\ApiConnector\Api;

/**
 * Contract for processors that require access to the Vinou API.
 *
 * Render::dataProcessing() detects this interface via instanceof and
 * injects the active API instance before the processor's method is called.
 * Replaces the previous is_subclass_of(AbstractProcessor) string check.
 *
 * Extends ProcessorInterface so that API-aware processors satisfy both
 * the marker type and the injection contract with a single declaration.
 */
interface ApiAwareInterface extends ProcessorInterface {

    /**
     * Injects the active Vinou API instance into the processor.
     *
     * @param Api $api  Reference to the active API instance.
     * @return void
     */
    public function loadApi(Api &$api): void;
}
