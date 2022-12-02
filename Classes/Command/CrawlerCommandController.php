<?php
declare(strict_types=1);

namespace Shel\Crawler\Command;

/*                                                                        *
 * This script belongs to the Neos CMS plugin Shel.Crawler                *
 *                                                                        */

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\Eel\Exception as EelException;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Cli\Exception\StopCommandException;
use Neos\Flow\Http\Client\InfiniteRedirectionException;
use Neos\Flow\Http\Exception as HttpException;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Property\Exception as PropertyException;
use Neos\Flow\Security\Exception as SecurityException;
use Neos\Fusion\Exception as FusionException;
use Neos\Neos\Controller\CreateContentContextTrait;
use Neos\Neos\Domain\Exception as DomainException;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Exception as NeosException;
use RollingCurl\Request;
use Shel\Crawler\Service\FusionRenderingService;
use Shel\Crawler\Service\SitemapService;

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
     * @Flow\Inject
     * @var DomainRepository
     */
    protected $domainRepository;


    /**
     * Crawl all sites
     *
     * @throws EelException
     * @throws MissingActionNameException
     * @throws PropertyException
     * @throws NeosException
     * @throws HttpException
     */
    public function crawlSitesCommand(): void {
        $sites = $this->siteRepository->findAll();
        $this->outputLine('Found %d sites', [count($sites)]);

        foreach ($sites as $site) {
            $siteNodeName = $site->getNodeName();
            $domain = $this->domainRepository->findOneBySite($site, true);

            // Skip sites without domain
            if ($domain === null || !$domain->getActive()) {
                $this->outputLine('Skip site %s because no (active) Domain was found.', [$siteNodeName]);
                continue;
            }

            $urlSchemeAndHost = ($domain->getScheme() ?: 'http') . '://' . $domain->getHostname() . ($domain->getPort() ? ':' . $domain->getPort() : '');
            $this->outputLine('Crawling site %s with urlSchemeAndHost %s', [$siteNodeName, $urlSchemeAndHost]);
            $this->crawlNodesCommand($siteNodeName, $urlSchemeAndHost);
        }
    }


    /**
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
        $crawlerBaseUri = getenv('CRAWLER_BASE_URI') ?: '';
        $urlSchemeAndHost = $urlSchemeAndHost ?? (string)$crawlerBaseUri;

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
     * @throws StopCommandException
     */
    protected function crawlNode(
        NodeInterface $siteNode,
        NodeInterface $node,
        string $fusionPath = '',
        string $urlSchemeAndHost = '',
        string $format = 'html',
        string $outputPath = ''
    ): void
    {
        $this->outputLine('Crawling node: "%s"', [$node->getLabel()]);

        try {
            $result = $this->fusionRenderingService->render($siteNode, $node, $fusionPath, $urlSchemeAndHost);
            if (!$result) {
                $this->outputLine('Empty output when rendering node');
                $this->quit(1);
            }

            $httpResponse = strtok($result, "\n");
            $this->outputLine('Result: %s', [$httpResponse]);

            // Store rendered output in file if outputPath is set
            if ($outputPath) {
                $filePath = '';
                if ($node === $siteNode) {
                    $filePath = '/index';
                } else {
                    try {
                        $filePath = $this->fusionRenderingService->getNodeUri($siteNode, $node, $urlSchemeAndHost, $format);
                    } catch (\CrawlerException $e) {
                        $this->outputLine('An error occurred while generating uri for node: %s', [$e]);
                    }
                }
                if ($filePath) {
                    $this->writeRenderingResultToFile($outputPath . $filePath, $result);
                }
            }
        } catch (FusionException $e) {
            $this->outputLine('Fusion error when rendering node: %s', [$e]);
        } catch (DomainException $e) {
            $this->outputLine('Domain error when rendering node: %s', [$e]);
        } catch (SecurityException $e) {
            $this->outputLine('Security error when rendering node: %s', [$e]);
        } catch (NeosException $e) {
            $this->outputLine('Neos error when rendering node: %s', [$e]);
        }
    }

    protected function writeRenderingResultToFile(
        string $filePath,
        string $result
    ): void {
        $fileDirectory = dirname($filePath);
        if (!mkdir($fileDirectory, 0777, true) && !is_dir($fileDirectory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $fileDirectory));
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
            $this->outputLine('No sitemaps found in robots.txt');
        }
    }
}
