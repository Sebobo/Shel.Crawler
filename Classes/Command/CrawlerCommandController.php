<?php

namespace Shel\Crawler\Command;

/*                                                                        *
 * This script belongs to the Flow plugin Shel.Crawler                    *
 *                                                                        */

use RollingCurl\Request;
use Shel\Crawler\Service\SitemapService;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Cli\CommandController;
use TYPO3\Flow\Utility\Now;
use TYPO3\Neos\Controller\Frontend\NodeController;
use TYPO3\Neos\Domain\Service\ContentContextFactory;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;
use TYPO3\TYPO3CR\Domain\Model\Workspace;
use TYPO3\TYPO3CR\Domain\Repository\WorkspaceRepository;
use TYPO3\TYPO3CR\Domain\Service\Context;

/**
 *
 * @Flow\Scope("singleton")
 */
class CrawlerCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var \TYPO3\Flow\Log\SystemLoggerInterface
     */
    protected $systemLogger;

    /**
     * @Flow\Inject
     * @var ContentContextFactory
     */
    protected $contentContextFactory;

    /**
     * @Flow\Inject
     * @var \TYPO3\TYPO3CR\Domain\Repository\NodeDataRepository
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

        /** @var array<\TYPO3\TYPO3CR\Domain\Model\NodeInterface> $nodes */
        $nodes = $this->nodeDataRepository->findByParentAndNodeTypeInContext(
            $rootNode->getPath(),
            'TYPO3.Neos:Document',
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
