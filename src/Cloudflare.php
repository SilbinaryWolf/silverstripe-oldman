<?php

namespace Symbiote\Cloudflare;

use Exception;
use Symbiote\Multisites\Model\Site;
use Cloudflare\Api;
use Cloudflare\Zone\Cache;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Assets\File;
use SilverStripe\Control\Director;
use SilverStripe\Control\Controller;
use SilverStripe\View\Requirements;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Config\Configurable;

class Cloudflare
{
    use Extensible;
    use Injectable;
    use Configurable;

    /**
     * Cloudflare can only purge 500 files per request.
     */
    const MAX_PURGE_FILES_PER_REQUEST = 500;

    /**
     * String representation of this class.
     * NOTE: Using this as PHP 5.4 does not support `Cloudflare::class`
     *
     * @var string
     */
    const CLOUDFLARE_CLASS = 'Symbiote\Cloudflare\Cloudflare';

    /**
     * String representation of the "Filesystem" class.
     * NOTE: Using this as PHP 5.4 does not support `Filesystem::class`
     *
     * @var string
     */
    const FILESYSTEM_CLASS = 'Symbiote\Cloudflare\Filesystem';

    /**
     * String representation of a Multisite "Site" DataObject class.
     * NOTE: Using this as PHP 5.4 does not support `Site::class`
     *
     * @var string
     */
    const SITE_CLASS = 'Symbiote\Multisites\Model\Site';

    /**
     * @var boolean
     * @config
     */
    private static $enabled = false;

    /**
     * @var string
     * @config
     */
    private static $email = '';

    /**
     * Authentication Key
     *
     * eg. 24ca61e15fb2aa62a31212a90f2674f3451f8
     *
     * @var    string
     * @config
     */
    private static $auth_key = '';

    /**
     * Zone ID
     *
     * eg. 73a40b2c0c10f468cb658f67b9d46fff
     *
     * @var    string
     * @config
     */
    private static $zone_id = '';

    /**
     * This is used as the base url when clearing the cache for pages, CSS and JavaScript.
     * Fallback is Director::absoluteURL().
     *
     * eg. https://silverstripe.org/
     *
     * TODO(Jake): Perhaps change this to a array/list for Multisite support?
     *
     * @var    string
     * @config
     */
    private static $base_url = '';

    /**
     * Files with these extensions to purge when clearing images.
     * The other file extensions are read from File::app_categories['image'].
     *
     * @var array
     */
    private static $image_file_extensions = array(
        'svg',
        'webp',
    );

    /**
     * @var \Cloudflare\Api
     */
    protected $client;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    public function __construct()
    {
        $this->filesystem = Injector::inst()->get(self::FILESYSTEM_CLASS);
        if ($this->config()->enabled) {
            $this->client = new Api($this->config()->email, $this->config()->auth_key);
        }
    }

    /**
     * @return CloudflareResult|null
     */
    public function purgePage(SiteTree $page)
    {
        if (!$this->client) {
            return null;
        }
        $files = $this->getLinksToPurgeByPage($page);
        return $this->purgeFiles($files);
    }

    /**
     * @return CloudflareResult|null
     */
    public function purgeAll()
    {
        if (!$this->client) {
            return null;
        }
        $cache = new Cache($this->client);
        $response = $cache->purge($this->getZoneIdentifier(), true);
        $result = new CloudflareResult(array(), $response->errors);
        return $result;
    }

    /**
     * @return CloudflareResult|null
     */
    public function purgeImages()
    {
        $appCategories = File::config()->app_categories;
        if (!isset($appCategories['image'])) {
            user_error('Missing "image" category on File::app_categories.', E_USER_WARNING);
            return null;
        }
        $fileExtensions = $appCategories['image'];
        $additionalFileExtensions = $this->config()->image_file_extensions;
        if ($additionalFileExtensions) {
            $fileExtensions = array_merge($fileExtensions, $additionalFileExtensions);
        }
        $fileExtensions = array_unique($fileExtensions);
        return $this->purgeFilesByExtensions($fileExtensions);
    }

    /**
     * @return CloudflareResult|null
     */
    public function purgeCSSAndJavascript()
    {
        return $this->purgeFilesByExtensions(
            array(
                'css',
                'js',
                'json',
            )
        );
    }

    /**
     * Purge list of URL strings
     *
     * @return CloudflareResult
     */
    public function purgeURLs(array $absoluteOrRelativeURLList)
    {
        // Get base URL (for conversion of relative URL to absolute URL)
        $baseURL = $this->config()->base_url;
        if (!$baseURL) {
            $baseURL = Director::absoluteBaseURL();
        }

        // Process list of relative/absolute URLs
        $urlsToPurge = array();
        foreach ($absoluteOrRelativeURLList as $absoluteOrRelativeURL) {
            $isAbsoluteURL = strpos($absoluteOrRelativeURL, 'http://') !== false ||
                            strpos($absoluteOrRelativeURL, 'https://') !== false;

            // Convert to absolute URL
            if (!$isAbsoluteURL) {
                $urlsToPurge[] = Controller::join_links($baseURL, $absoluteOrRelativeURL);
                continue;
            }
            $urlsToPurge[] = $absoluteOrRelativeURL;
        }

        return $this->purgeFiles($urlsToPurge);
    }

    /**
     * @return string
     */
    public function getZoneIdentifier()
    {
        return $this->config()->zone_id;
    }

    /**
     * @return CloudflareResult|null
     */
    protected function purgeFilesByExtensions(array $fileExtensions)
    {
        if (!$this->client) {
            return null;
        }
        $files = $this->getFilesToPurgeByExtensions($fileExtensions, false);

        // Purge files
        $cache = new Cache($this->client);
        $zoneIdentifier = $this->getZoneIdentifier();
        $errors = array();
        foreach (array_chunk($files, self::MAX_PURGE_FILES_PER_REQUEST) as $filesChunk) {
            $response = $cache->purge_files($zoneIdentifier, $filesChunk);
            if (!$response->success) {
                $errors = array_merge($errors, $response->errors);
            }
        }

        //
        $result = new CloudflareResult($files, $errors);
        return $result;
    }

    /**
     * Check if page is the home page.
     * Supports Multisites. (ie. "Site" record exists at top of tree)
     *
     * @return boolean
     */
    protected function isHomePage(SiteTree $page)
    {
        $parent = $page->Parent();
        return $page->URLSegment === 'home' && ((class_exists(self::SITE_CLASS) && $parent instanceof Site) || !$parent->exists());
    }

    /**
     * @return array
     */
    private function getLinksToPurgeByPage(SiteTree $page)
    {
        $files = array();
        // Use alternate base url if defined for cache clearing
        $baseURL = $this->config()->base_url;
        $pageLink = '';
        if ($baseURL) {
            $pageLink = Controller::join_links($baseURL, $page->Link());
        } else {
            if (class_exists(self::SITE_CLASS)) {
                // NOTE(Jake): 2018-04-26
                //
                // We do this as the URL returned will not use 'Host' if you are on this
                // current site, but rather default to your local URL.
                //
                // This solves a problem where you might have a frontend server and a backend server
                // with two different URLs.
                //
                $pageLink = Controller::join_links($page->Site()->AbsoluteLink(), $page->Link());
            } else {
                $pageLink = $page->AbsoluteLink();
            }
        }

        // CloudFlare requires both one with and without a forward-slash.
        $pageLink = rtrim($pageLink, '/');
        $files[] = $pageLink;
        $files[] = $pageLink.'/';

        // If /home/ for HomePage, also add "/" to be cleared.
        if ($this->isHomePage($page)) {
            $files[] = substr($pageLink, 0, (strrpos($pageLink, '/home')));
        }
        return array_filter($files);
    }

    /**
     * @return array
     */
    private function getFilesToPurgeByExtensions(array $fileExtensions, $ignoreDatabaseRecords)
    {
        // Scan files in the project directory to purge
        $folderList = array(
            // Get all files built by `Requirements` system (*.css, *.js)
            Director::baseFolder().'/'.ASSETS_DIR.'/'.Requirements::backend()->getCombinedFilesFolder(),
            // Get all module / theme files
            Director::baseFolder()
        );
        $files = array();
        foreach ($folderList as $folder) {
            $files = array_merge($files, $this->filesystem->getFilesWithExtensionsRecursively($folder, $fileExtensions));
        }

        // Get all files in database and purge (not using local scan for /assets/ so we can support remotely hosted files in S3/etc)
        if (!$ignoreDatabaseRecords) {
            $fileExtensionsPrefixedWithDot = array();
            foreach ($fileExtensions as $fileExtension) {
                $fileExtensionsPrefixedWithDot[] = '.'.$fileExtension;
            }
            $fileRecordList = File::get()->filter(
                array(
                'Filename:EndsWith' => $fileExtensionsPrefixedWithDot
                )
            );
            $files = array_merge($files, $fileRecordList->map('ID', 'Link')->toArray());
        }
        return $files;
    }

    /**
     * @param string[] $filesToPurge
     * @var CloudflareResult
     */
    private function purgeFiles(array $filesToPurge)
    {
        $cache = new Cache($this->client);
        $response = $cache->purge_files($this->getZoneIdentifier(), $filesToPurge);
        $errors = [];
        if (!$response->success) {
            if (isset($response->errors)) {
                $errors = $response->errors;
            } else {
                throw new \Exception($response->error);
                //if (isset($response->error)) {
                //    $error = new \stdClass;
                //    $error->message = $response->error;
                //    $errors[] = $error;
                //}
            }
        }
        $result = new CloudflareResult($filesToPurge, $errors);
        return $result;
    }
}
