<?php

namespace Shel\Crawler\Command;

/*                                                                        *
 * This script belongs to the Flow plugin Shel.Crawler                    *
 *                                                                        */

use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Http\Client\InfiniteRedirectionException;
use Neos\Flow\Log\SystemLoggerInterface;
use RollingCurl\Request;
use Shel\Crawler\Service\SitemapService;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Neos\Controller\Frontend\NodeController;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\Neos\Controller\CreateContentContextTrait;

/**
 *
 * @Flow\Scope("singleton")
 */
class CrawlerCommandController extends CommandController
{
    use CreateContentContextTrait;

    /**
     * @Flow\Inject
     * @var SystemLoggerInterface
     */
    protected $systemLogger;

    /**
     * @Flow\Inject
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var SitemapService
     */
    protected $sitemapService;

    /**
     * @param string $siteNodeName
     * @param string $domain
     * @param string $dimensions
     * @throws \Exception
     */
    public function crawlNodesCommand($siteNodeName, $domain = '', $dimensions = ''): void
    {
        $dimensions = array_filter(explode(',', $dimensions));
        $contentContext = $this->createContentContext('live', $dimensions);
        $siteNode = $contentContext->getNode('/sites/' . $siteNodeName);

        if (!$siteNode) {
            $this->output('Could not find sitenode %s', [$siteNodeName]);
            return;
        }

        $documentNodeQuery = new FlowQuery([$siteNode]);
        $documentNodeQuery->pushOperation('find', ['[instanceof Neos.Neos:Document][!instanceof Neos.Neos:Shortcut]']);
        $documentNodes = $documentNodeQuery->get();

        $this->outputLine('Found %d document nodes', [count($documentNodes)]);

        $nodeController = $this->objectManager->get(NodeController::class);

        /** @var NodeInterface $node */
        foreach ($documentNodes as $node) {
            $this->outputLine('Rendering node: "%s"', [$node->getLabel()]);
            $nodeController->showAction($node);
        }
    }

    /**
     * @param string $url of sitemap which should be crawled
     * @param int $simultaneousLimit number of parallel requests
     * @param int $delay microseconds to wait between requests
     * @return bool
     * @throws InfiniteRedirectionException
     */
    public function crawlSitemapCommand(string $url, int $simultaneousLimit = 10, $delay = 0): bool
    {
        $start = microtime(true);
        $this->outputLine('Fetching sitemap with %d concurrent requests and a %d microsecond delay...', [$simultaneousLimit, $delay]);
        $urls = $this->sitemapService->retrieveSitemap($url);

        if ($urls === false) {
            $this->outputFormatted('Failed fetching sitemap at %s', [$url]);
            return false;
        }
        $this->outputFormatted('...done in %f', [microtime(true) - $start]);

        // Start crawling the urls from the sitemap
        $urlCount = count($urls);
        $start = microtime(true);
        $this->outputFormatted('Fetching %d urls...', [count($urls)]);
        $this->sitemapService->crawlUrls($urls, function ($completed, Request $request) use ($urlCount) {
            preg_match_all("#.*<title>(.*)</title>.*#iU", $request->getResponseText(), $matches);
            $pageTitle = isset($matches[1][0]) ? $matches[1][0] : 'No page title';
            $this->outputFormatted('(%d/%d) Fetch complete for (%s) - %s', [$completed, $urlCount, $request->getUrl(), $pageTitle]);
        }, [], $simultaneousLimit, $delay);
        $this->outputFormatted('...done in %f', [microtime(true) - $start]);

        return true;
    }

    /**
     * @param string $url
     * @param int $simultaneousLimit
     * @param int $delay
     * @return bool
     * @throws InfiniteRedirectionException
     */
    public function crawlRobotsTxtCommand(string $url, int $simultaneousLimit = 10, $delay = 0): bool
    {
        $urls = $this->sitemapService->retrieveSitemapsFromRobotsTxt($url)[1];

        foreach ($urls as $url) {
            $this->crawlSitemapCommand($url, $simultaneousLimit, $delay);
        }
    }
}
