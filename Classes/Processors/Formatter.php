<?php
namespace Vinou\SiteBuilder\Processors;

use \Vinou\ApiConnector\Tools\Helper;

/**
 * Data formatting processor for SiteBuilder dataProcessing steps.
 *
 * Provides utility functions to reshape and combine data arrays before
 * they are passed to Twig templates. Registered under the key 'formatter'
 * by default in Site::loadDefaultProcessors().
 */
class Formatter {

    /** @var array<string, mixed> Shared data storage for multi-step formatting. */
    public array $data = [];

    public function __construct() {}

    /**
     * Flattens multiple named data arrays into a single indexed list.
     *
     * Each entry from the input sub-arrays is unwrapped from any 'data'
     * key and appended to the result. The originating array key is added
     * as 'object_type' on each entry so templates can distinguish types
     * (e.g. 'wines', 'bundles').
     *
     * @param array<string, mixed> $data  Associative array of named datasets,
     *                                    typically from prior dataProcessing steps
     *                                    referenced via useData.
     * @return array<int, mixed>          Flat list of all entries across all input arrays.
     */
    public function mergeData(array $data): array {
        $returnArr = [];

        foreach ($data as $key => $subdata) {
            switch (gettype($subdata)) {
                case 'array':
                    if (array_key_exists('data', $subdata))
                        $subdata = $subdata['data'];

                    if (empty($subdata))
                        continue 2;

                    foreach ($subdata as $entry) {
                        if (is_array($entry) && array_key_exists('data', $entry))
                            $entry = $entry['data'];

                        if (empty($entry))
                            continue;

                        if (is_string($key) && is_array($entry))
                            $entry['object_type'] = $key;

                        $returnArr[] = $entry;
                    }
                    break;

                case 'string':
                    $returnArr[] = $subdata;
                    break;

                default:
                    break;
            }
        }

        return $returnArr;
    }
}
