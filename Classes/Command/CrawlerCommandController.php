<?php
declare(strict_types=1);

namespace Shel\Crawler\Command;

/*                                                                        *
 * This script belongs to the Neos CMS plugin Shel.Crawler                *
 *                                                                        */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Cli\Exception\StopCommandException;
use Neos\Flow\Http\Client\InfiniteRedirectionException;
use Neos\Neos\Controller\CreateContentContextTrait;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use RollingCurl\Request;
use Shel\Crawler\Service\CRCrawlerService;
use Shel\Crawler\Service\SitemapService;

/**
 *
 * @Flow\Scope("singleton")
 */
class CrawlerCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var SitemapService
     */
    protected $sitemapService;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var DomainRepository
     */
    protected $domainRepository;

    /**
     * @Flow\Inject
     * @var CRCrawlerService
     */
    protected $crCrawlerService;

    /**
     * @Flow\InjectConfiguration(path="forceUrlScheme")
     * @var string|null
     */
    protected $forceUrlScheme;

    /**
     * Crawl all sites
     *
     * This command will crawl all sites based on their primary active domain.
     *
     * @param string|null $method method to be used for Crawling (`nodes` or `robotstxt`)
     *
     */
    public function crawlSitesCommand(string $method = 'nodes'): void {
        /** @var Site[] $sites */
        $sites = $this->siteRepository->findAll();
        $this->outputLine('<info>Found %d sites</info>', [count($sites)]);

        foreach ($sites as $site) {
            $siteNodeName = $site->getNodeName();

            /** @var Domain[] $domains */
            $domains = $this->domainRepository->findBySite($site, true)->toArray();
            /** @var Domain[] $activeDomains */
            $activeDomains = array_values(array_filter($domains, static fn (Domain $domain) => $domain->getActive()));
            $domain = $activeDomains[0] ?? null;

            // Skip sites without domain
            if ($domain === null || !$domain->getActive()) {
                $this->outputLine('<error>Skip site <b>"%s"</b> because no (active) Domain was found.</error>', [$siteNodeName]);
                continue;
            }

            if ($this->forceUrlScheme !== null) {
                $domain->setScheme($this->forceUrlScheme);
            }
            $urlSchemeAndHost = (string)$domain;

            if($method === 'robotstxt'){
                $fallbackScheme = strpos($urlSchemeAndHost, 'http') === 0 ? '' : 'http://';
                $robotsTextUrl = $fallbackScheme . $urlSchemeAndHost . '/robots.txt';
                $this->outputLine('Crawling site <b>"%s"</b> robots.txt at <i>"%s"</i>', [$siteNodeName, $robotsTextUrl]);
                $this->crawlRobotsTxtCommand($robotsTextUrl);
            }else {
                $this->crawlNodesCommand($siteNodeName, $urlSchemeAndHost);
            }
        }
    }

    /**
     * Crawls all nodes of a site
     */
    public function crawlNodesCommand(
        string $siteNodeName,
        string $urlSchemeAndHost = '',
        string $dimensions = '',
        string $fusionPath = 'root',
        string $outputPath = '',
        string $format = 'html'
    ): void {
        $dimensions = array_filter(explode(',', $dimensions));
        $crawlerBaseUri = getenv('CRAWLER_BASE_URI') ?: '';
        $urlSchemeAndHost = $urlSchemeAndHost ?? (string)$crawlerBaseUri;

        $site = $this->siteRepository->findOneByNodeName($siteNodeName);

        if (!$site) {
            $this->outputLine('<error>Site "%s" not found</error>', [$siteNodeName]);
            exit(0);
        }

        if (!$urlSchemeAndHost) {
            /** @var Domain[] $domains */
            $domains = $this->domainRepository->findBySite($site, true)->toArray();
            /** @var Domain[] $activeDomains */
            $activeDomains = array_values(array_filter($domains, static fn ($domain) => $domain->getActive()));
            $domain = $activeDomains[0] ?? null;
            if ($domain) {
                $urlSchemeAndHost = (string)$domain;
            }

            if (!$urlSchemeAndHost) {
                $this->outputLine(sprintf('<error>No active domain for site "%s" found. Please define the urlSchemeAndHost parameter</error>', $siteNodeName));
                exit(0);
            }
        }

        $this->outputLine('Crawling site <b>"%s"</b> with urlSchemeAndHost <i>"%s"</i>', [$siteNodeName, $urlSchemeAndHost]);

        $this->crCrawlerService->crawlNodes(
            $siteNodeName,
            $fusionPath,
            $dimensions,
            $urlSchemeAndHost,
            $format,
            $outputPath,
            fn (string $message, ?array $args) => $this->output($message, $args)
        );
    }

    /**
     * @param string $url of sitemap which should be crawled
     * @param int $simultaneousLimit number of parallel requests
     * @param int $delay microseconds to wait between requests
     * @throws InfiniteRedirectionException|StopCommandException
     */
    public function crawlSitemapCommand(string $url, int $simultaneousLimit = 10, int $delay = 0): void
    {
        $start = microtime(true);
        $this->outputLine('Fetching sitemap with %d concurrent requests and a %d microsecond delay...',
            [$simultaneousLimit, $delay]);
        $urls = $this->sitemapService->retrieveSitemap($url);

        if (!$urls) {
            $this->outputFormatted('<error>Failed fetching sitemap at %s</error>', [$url]);
            $this->quit(1);
        }
        $this->outputFormatted('<info>...done in %ds</info>', [round(microtime(true) - $start)]);

        // Start crawling the urls from the sitemap
        $progressSection = $this->output->getOutput()->section('Result');
        $errorSection = $this->output->getOutput()->section('Errors');
        $urlCount = count($urls);
        $start = microtime(true);
        $this->outputFormatted('Fetching %d urls...', [count($urls)]);
        $this->sitemapService->crawlUrls($urls, function ($completed, Request $request) use ($urlCount, $progressSection, $errorSection) {
            preg_match_all("#.*<title>(.*)</title>.*#iU", $request->getResponseText(), $matches);
            $pageTitle = $matches[1][0] ?? 'No page title';
            $statusCode = $request->getResponseInfo()['http_code'] ?? 0;

            $progressSection->overwrite(sprintf('<info>(%d/%d)</info>', $completed, $urlCount));

            if ($statusCode !== 200) {
                $errorSection->writeln(sprintf('<error>%s - %s - %s</error>', $request->getUrl(), $pageTitle, $request->getResponseError()));
            }
        }, [], $simultaneousLimit, $delay);
        $this->outputFormatted('<info>...done in %ds</info>', [round(microtime(true) - $start)]);
    }

    /**
     * @throws InfiniteRedirectionException|StopCommandException
     */
    public function crawlRobotsTxtCommand(string $url, int $simultaneousLimit = 10, int $delay = 0): void
    {
        $urls = $this->sitemapService->retrieveSitemapsFromRobotsTxt($url)[1];

        if ($urls) {
            foreach ($urls as $sitemapUrl) {
                $this->crawlSitemapCommand($sitemapUrl, $simultaneousLimit, $delay);
            }
        } else {
            $this->outputLine('<error>No sitemaps found in robots.txt</error>');
        }
    }
}
