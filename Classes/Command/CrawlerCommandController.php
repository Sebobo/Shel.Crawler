<?php
declare(strict_types=1);

namespace Shel\Crawler\Command;

/*                                                                        *
 * This script belongs to the Flow plugin Shel.Crawler                    *
 *                                                                        */

use Neos\Eel\Exception as EelException;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Http\Client\InfiniteRedirectionException;
use Neos\Flow\Http\Exception as HttpException;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Property\Exception as PropertyException;
use Neos\Flow\Security\Exception as SecurityException;
use Neos\Fusion\Exception as FusionException;
use Neos\Neos\Domain\Exception as DomainException;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Exception as NeosException;
use Shel\Crawler\Service\FusionRenderingService;
use RollingCurl\Request;
use Shel\Crawler\Service\SitemapService;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
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
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var SitemapService
     */
    protected $sitemapService;

    /**
     * @Flow\Inject
     * @var FusionRenderingService
     */
    protected $fusionRenderingService;

    /**
     * @Flow\Inject
     * @var ContentContextFactory
     */
    protected $contentContextFactory;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @param string $siteNodeName
     * @param string $urlSchemeAndHost
     * @param string $dimensions
     * @param string $fusionPath
     * @param string $outputPath
     * @param string $format
     * @throws EelException
     * @throws MissingActionNameException
     * @throws PropertyException
     * @throws NeosException
     * @throws HttpException
     */
    public function crawlNodesCommand(
      string $siteNodeName,
      string $urlSchemeAndHost,
      string $dimensions = '',
      string $fusionPath = 'root',
      string $outputPath = '',
      string $format = 'html'
    ): void {
        $dimensions = array_filter(explode(',', $dimensions));
        $urlSchemeAndHost = $urlSchemeAndHost ? $urlSchemeAndHost : getenv('CRAWLER_BASE_URI');

        if (!$urlSchemeAndHost) {
            $this->outputLine('Please define the urlSchemeAndHost parameter');
            return;
        }

        /** @var Site $site */
        /** @noinspection PhpUndefinedMethodInspection */
        $site = $this->siteRepository->findOneByNodeName($siteNodeName);

        if (!$site) {
            $this->output('Could not find site %s', [$siteNodeName]);
            return;
        }

        /** @var ContentContext $contentContext */
        $contentContext = $this->contentContextFactory->create([
          'workspaceName' => 'live',
          'dimensions' => $dimensions,
          'currentSite' => $site,
          'currentDomain' => $site->getFirstActiveDomain(),
        ]);

        $siteNode = $contentContext->getNode('/sites/' . $siteNodeName);

        if (!$siteNode) {
            $this->output('Could not find node for site %s', [$siteNodeName]);
            return;
        }

        // TODO: Handle shortcut nodes differently when storing results
        $documentNodeQuery = new FlowQuery([$siteNode]);
        $documentNodeQuery->pushOperation('find', ['[instanceof Neos.Neos:Document][!instanceof Neos.Neos:Shortcut]']);
        /** @noinspection PhpUndefinedMethodInspection */
        $documentNodes = $documentNodeQuery->get();

        $this->outputLine('Found %d document nodes', [count($documentNodes)]);

        // TODO: Clean out old cached files
        $this->crawlNode($siteNode, $siteNode, $fusionPath, $urlSchemeAndHost, $format, $outputPath);

        /** @var NodeInterface $node */
        foreach ($documentNodes as $node) {
            $this->crawlNode($siteNode, $node, $fusionPath, $urlSchemeAndHost, $format, $outputPath);
        }
    }

    /**
     * @param NodeInterface $siteNode
     * @param NodeInterface $node
     * @param string $fusionPath
     * @param $urlSchemeAndHost
     * @param $format
     * @param $outputPath
     * @throws MissingActionNameException
     * @throws NeosException
     * @throws PropertyException
     * @throws HttpException
     */
    protected function crawlNode(NodeInterface $siteNode, NodeInterface $node, string $fusionPath, $urlSchemeAndHost, $format, $outputPath): void
    {
        $this->outputLine('Crawling node: "%s"', [$node->getLabel()]);

        try {
            $result = $this->fusionRenderingService->render($siteNode, $node, $fusionPath, $urlSchemeAndHost);
            if ($result) {
                $httpResponse = strtok($result, "\n");
                $this->outputLine('Result: %s', [$httpResponse]);

                if ($outputPath) {
                    if ($node === $siteNode) {
                        $filePath = '/index';
                    } else {
                        $filePath = $this->fusionRenderingService->getNodeUri($siteNode, $node, $urlSchemeAndHost, $format);
                    }
                    $this->writeRenderingResultToFile($outputPath . $filePath, $result);
                }
            } else {
                $this->outputLine('Empty output when rendering node');
            }
        } catch (FusionException $e) {
            $this->outputLine('Error when rendering node: %s', [$e]);
        } catch (DomainException $e) {
            $this->outputLine('Error when rendering node: %s', [$e]);
        } catch (SecurityException $e) {
            $this->outputLine('Error when rendering node: %s', [$e]);
        }
    }

    /**
     * @param string $filePath
     * @param string $result
     */
    protected function writeRenderingResultToFile(
      string $filePath,
      string $result
    ): void {
        $fileDirectory = dirname($filePath);
        if (!is_dir($fileDirectory)) {
            mkdir($fileDirectory, 0777, true);
        }

        // Remove http header
        $result = str_replace(strtok($result, "\n"), '', $result);

        file_put_contents($filePath, $result);
        $this->outputLine('Wrote result to cache: %s', [$filePath]);
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
        $this->outputLine('Fetching sitemap with %d concurrent requests and a %d microsecond delay...',
          [$simultaneousLimit, $delay]);
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
            $this->outputFormatted('(%d/%d) Fetch complete for (%s) - %s',
              [$completed, $urlCount, $request->getUrl(), $pageTitle]);
        }, [], $simultaneousLimit, $delay);
        $this->outputFormatted('...done in %f', [microtime(true) - $start]);

        return true;
    }

    /**
     * @param string $url
     * @param int $simultaneousLimit
     * @param int $delay
     * @throws InfiniteRedirectionException
     */
    public function crawlRobotsTxtCommand(string $url, int $simultaneousLimit = 10, $delay = 0): void
    {
        $urls = $this->sitemapService->retrieveSitemapsFromRobotsTxt($url)[1];

        if ($urls) {
            foreach ($urls as $url) {
                $this->crawlSitemapCommand($url, $simultaneousLimit, $delay);
            }
        } else {
            $this->outputLine('No sitemaps found in robots.txt');
        }
    }
}
