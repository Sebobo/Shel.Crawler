<?php

namespace Shel\Crawler\Command;

/*                                                                        *
 * This script belongs to the Flow plugin Shel.Crawler                    *
 *                                                                        */

use RollingCurl\Request;
use Shel\Crawler\Service\SitemapService;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Utility\Now;
use Neos\Neos\Controller\Frontend\NodeController;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\Context;

/**
 *
 * @Flow\Scope("singleton")
 */
class CrawlerCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var \Neos\Flow\Log\SystemLoggerInterface
     */
    protected $systemLogger;

    /**
     * @Flow\Inject
     * @var ContentContextFactory
     */
    protected $contentContextFactory;

    /**
     * @Flow\Inject
     * @var \Neos\ContentRepository\Domain\Repository\NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @Flow\Inject
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @param string $site
     */
    public function crawlNodesCommand($site = '')
    {
        /** @var Workspace $liveWorkspace */
        $liveWorkspace = $this->workspaceRepository->findByName('live')->getFirst();
        $rootNode = $liveWorkspace->getRootNodeData();

        /** @var Context $context */
        $context = $this->contentContextFactory->create([
            'workspaceName' => 'live',
            'currentDateTime' => new Now(),
            'dimensions' => [],
            'targetDimensions' => [],
            'invisibleContentShown' => false,
            'removedContentShown' => false,
            'inaccessibleContentShown' => false
        ]);

        /** @var array<\Neos\ContentRepository\Domain\Model\NodeInterface> $nodes */
        $nodes = $this->nodeDataRepository->findByParentAndNodeTypeInContext(
            $rootNode->getPath(),
            'Neos.Neos:Document',
            $context,
            true
        );

        $nodeController = $this->objectManager->get(NodeController::class);

        /** @var NodeInterface $node */
        foreach ($nodes as $node) {
            $result = $nodeController->showAction($node);

            print_r($result);
            return;
        }
    }

    /**
     * @param string $url of sitemap which should be crawled
     * @return bool
     */
    public function crawlSitemapCommand($url)
    {
        $sitemapService = $this->objectManager->get(SitemapService::class);

        $start = microtime(true);
        $this->outputLine('Fetching sitemap...');
        $urls = $sitemapService->retrieveSitemap($url);

        if ($urls === false) {
            $this->outputFormatted('Failed fetching sitemap at %s', [$url]);
            return false;
        }
        $this->outputFormatted('...done in %f', [microtime(true) - $start]);

        // Start crawling the urls from the sitemap
        $urlCount = count($urls);
        $start = microtime(true);
        $this->outputFormatted('Fetching %d urls...', [count($urls)]);
        $sitemapService->crawlUrls($urls, function ($completed, Request $request) use ($urlCount) {
            preg_match_all("#.*<title>(.*)</title>.*#iU", $request->getResponseText(), $matches);
            $pageTitle = isset($matches[1][0]) ? $matches[1][0] : 'No page title';
            $this->outputFormatted('(%d/%d) Fetch complete for (%s) - %s', [$completed, $urlCount, $request->getUrl(), $pageTitle]);
        });
        $this->outputFormatted('...done in %f', [microtime(true) - $start]);

        return true;
    }
}
