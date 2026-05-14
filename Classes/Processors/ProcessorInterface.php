<?php
namespace Vinou\SiteBuilder\Processors;

/**
 * Marker interface for all data processors registered with the renderer.
 *
 * Enforces type safety in Render::loadProcessor() so only objects that
 * explicitly declare themselves as processors can be registered. Individual
 * processor methods are dispatched by name at runtime from route YAML
 * (dynamic dispatch via Render::dataProcessing()).
 *
 * Implement this interface directly for processors that do not require API
 * access. For processors that need the Vinou API, implement ApiAwareInterface
 * instead — it extends this interface and adds the loadApi() contract.
 */
interface ProcessorInterface {}
