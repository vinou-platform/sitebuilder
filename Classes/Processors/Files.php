<?php
namespace Vinou\SiteBuilder\Processors;

use \Vinou\ApiConnector\Tools\Helper;

/**
 * Processor for reading local file system directories in dataProcessing steps.
 *
 * Builds a structured file listing from a webroot-relative directory path.
 * Registered under the key 'files' by default in Site::loadDefaultProcessors().
 */
class Files {

    /** @var array<string, mixed> Shared data storage. */
    public array $data = [];

    /** @var list<string> File extensions that are included in directory listings. */
    protected array $allowedExt = ['jpg', 'png', 'gif', 'pdf', 'zip'];

    /** @var string|null Webroot-relative base path of the current listing. */
    protected ?string $source = null;

    public function __construct() {}

    /**
     * Builds a structured file listing for a webroot-relative directory.
     *
     * Expects $data['source'] to contain the base directory path relative to
     * the webroot. An optional first positional URL parameter ($data[0]) is
     * appended as a sub-directory, and a 'back' link to the parent is provided.
     *
     * @param array<string, mixed>|null $data  Processing parameters including 'source'
     *                                         and optionally 'allowedExt'.
     * @return array<string, mixed>  Listing with keys 'source', 'back', and 'files',
     *                               or an array with key 'error' on failure.
     */
    public function createFilelist(?array $data = null): array {
        if (!isset($data['source']))
            return ['error' => 'no source given'];

        $this->source = $data['source'];
        $dir = Helper::getNormDocRoot() . $data['source'];
        unset($data['source']);

        if (isset($data[0])) {
            $dir .= $data[0];
            $subDirParts = explode('/', $_SERVER['REQUEST_URI']);
            array_pop($subDirParts);
            $back = implode('/', $subDirParts);
        } else {
            $back = false;
        }

        if (!is_dir($dir))
            return ['error' => 'source folder doesnt exists in webroot'];

        if (isset($data['allowedExt']))
            $this->allowedExt = explode(',', $data['allowedExt']);

        return [
            'source' => $this->source,
            'back'   => $back,
            'files'  => $this->readdir($dir)
        ];
    }

    /**
     * Recursively reads a directory and returns a structured file list.
     *
     * Hidden files (names starting with '.') are skipped. Directories are
     * listed with a nested 'files' key. Files are filtered by $allowedExt.
     * Each entry carries metadata: name, timestamps, type, size, extension,
     * and an optional description read from a sibling .txt file.
     *
     * @param string $dir  Absolute path to the directory to read.
     * @return list<array<string, mixed>>  Sorted list of file and directory entries.
     */
    public function readdir(string $dir): array {
        $iterator = new \FilesystemIterator($dir);
        $filelist = [];

        foreach ($iterator as $entry) {
            $name = $entry->getFilename();
            $abs  = $entry->getPathName();

            if (substr($name, 0, 1) === '.')
                continue;

            $add = [
                'name'        => $name,
                'chstamp'     => filemtime($abs),
                'crstamp'     => filectime($abs),
                'type'        => filetype($abs),
                'description' => is_file($abs . '.txt') ? file_get_contents($abs . '.txt') : false
            ];

            switch ($add['type']) {
                case 'dir':
                    $basePath      = Helper::getNormDocRoot() . $this->source;
                    $add['path']   = str_replace($basePath, '', $abs);
                    $add['linkpath'] = $_SERVER['REQUEST_URI'] . $name;
                    $add['files']  = $this->readdir($abs);
                    $filelist[]    = $add;
                    break;

                case 'file':
                    $add['src']       = str_replace(Helper::getNormDocRoot(), '', $abs);
                    $add['size']      = filesize($abs);
                    $add['extension'] = pathinfo($abs)['extension'] ?? '';
                    if (in_array($add['extension'], $this->allowedExt))
                        $filelist[] = $add;
                    break;
            }
        }

        sort($filelist);
        return $filelist;
    }
}
