<?php

namespace Shel\Crawler\Service;

/*                                                                        *
 * This script belongs to the Flow plugin Shel.Crawler                    *
 *                                                                        */

use Neos\Flow\Http\Client\InfiniteRedirectionException;
use RollingCurl\Request;
use RollingCurl\RollingCurl;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Client\Browser;
use Neos\Flow\Http\Client\CurlEngine;

/**
 * @Flow\Scope("singleton")
 */
class SitemapService
{
    /**
     * @var array options for sitemap curl requests
     */
    protected $requestEngineOptions = [
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_SSL_VERIFYPEER => 0,
    ];

    /**
     * @var array options for crawler curl requests
     */
    protected $crawlRequestOptions = [
        CURLOPT_AUTOREFERER => 1,
        CURLOPT_FORBID_REUSE => 0,
        CURLOPT_FRESH_CONNECT => 0,
        CURLOPT_USERAGENT => 'ShelCrawlerBot',
        CURLOPT_NOBODY => 1,
        CURLOPT_HEADER => 0,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_SSL_VERIFYPEER => 0,
    ];

    /**
     * Add a sitemap URL to our crawl stack. Sitemap index files works too.
     *
     * @param string $url URL to a XML sitemap
     * @param array $options Additional options for the curl engine
     * @return array|bool False if sitemap cannot be fetched or array with locs
     * @throws InfiniteRedirectionException
     */
    public function retrieveSitemap(string $url, array $options = []): ?array
    {
        $browser = $this->getBrowser($options);
        $response = $browser->request($url);

        if ('200' != $response->getStatusCode()) {
            return false;
        }

        $xml = new \SimpleXMLElement($response->getContent(), LIBXML_NOBLANKS);

        if (!$xml) {
            return false;
        }

        $locs = [];

        switch ($xml->getName()) {
            case 'sitemapindex':
                // Retrieve sitemap for each item in index
                foreach ($xml->sitemap as $sitemap) {
                    $result = $this->retrieveSitemap(reset($sitemap->loc));
                    if ($result === false) {
                        return false;
                    }
                    $locs = array_merge($locs, $result);
                }
                break;
            case 'urlset':
                foreach ($xml->url as $entry) {
                    $loc = reset($entry->loc) . '';
                    $locs[$loc] = true;
                }
                break;
        }

        return array_keys($locs);
    }

    /**
     * @param array $urls
     * @param callable $callback will be called for each completed request
     * @param array $options additional curl options to be set
     * @param int $simultaneousLimit number of parallel curl requests
     * @param int $delay
     * @return bool
     * @throws \Exception
     */
    public function crawlUrls(array $urls, callable $callback, array $options = [], int $simultaneousLimit = 10, int $delay = 0): bool
    {
        $rollingCurl = new RollingCurl();
        foreach ($urls as $url) {
            $rollingCurl->get($url);
        }
        $rollingCurl
            ->addOptions(array_merge($this->crawlRequestOptions, $options))
            ->setCallback(function(Request $request, RollingCurl $rollingCurl) use ($callback, $delay) {
                if ($rollingCurl->countPending() % 100 == 0) {
                    $rollingCurl->clearCompleted();
                }
                $callback($rollingCurl->countCompleted(), $request);
                if ($delay > 0) {
                    usleep($delay);
                }
            })
            ->setSimultaneousLimit($simultaneousLimit)
            ->execute();
        ;
        return true;
    }

    /**
     * @param array $options Additional options for the curl engine
     * @return Browser
     */
    protected function getBrowser(array $options = []): Browser
    {
        $browser = new Browser();
        $curlEngine = new CurlEngine();
        $options = array_merge($this->requestEngineOptions, $options);
        foreach ($options as $option => $value) {
            $curlEngine->setOption($option, $value);
        }
        $browser->setRequestEngine($curlEngine);
        return $browser;
    }
}
