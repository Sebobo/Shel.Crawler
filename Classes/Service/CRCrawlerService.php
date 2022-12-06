<?php

declare(strict_types=1);

namespace Shel\Crawler\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Eel\Exception;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Exception as SecurityException;
use Neos\Fusion\Exception as FusionException;
use Neos\Neos\Domain\Exception as DomainException;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Exception as NeosException;
use Shel\Crawler\CrawlerException;

/**
 * @Flow\Scope("singleton")
 */
class CRCrawlerService
{

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
     * @var null|callable
     */
    protected $messageCallback;

    /**
     * @Flow\InjectConfiguration(path="nodeTypeFilter")
     * @var array
     */
    protected $nodeTypeFilter;

    protected array $nodeAccessCache = [];

    public function crawlNodes(
        string $siteNodeName,
        string $fusionPath,
        array $dimensions,
        string $urlSchemeAndHost,
        string $format,
        string $outputPath,
        callable $messageCallback = null
    ): void {
        $this->messageCallback = $messageCallback;

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

        // Convert array with nodetypes to a nodetype filter for the FlowQuery
        $nodeTypeFilter = implode('', array_map(function (string $item) {
            return sprintf('[%sinstanceof %s]', $this->nodeTypeFilter[$item] ? '' : '!', $item);
        }, array_keys($this->nodeTypeFilter)));

        try {
            /** @var \Iterator<NodeInterface> $documentNodesIteration */
            /** @noinspection PhpUndefinedMethodInspection */
            $documentNodesIteration = (new FlowQuery([$siteNode]))->find($nodeTypeFilter)->getIterator();
        } catch (Exception $e) {
            $this->output('Could not query documents', [$e->getMessage()]);
            return;
        }

        try {
            // TODO: Clean out old cached files
            $this->output('Crawling node: "%s" - ', [$siteNode->getLabel()], false);
            $this->crawlNode($siteNode, $siteNode, $fusionPath, $urlSchemeAndHost, $format, $outputPath);

            while ($node = $documentNodesIteration->current()) {
                $this->output('Crawling node: "%s" - ', [$node->getLabel()], false);
                $this->crawlNode($node, $siteNode, $fusionPath, $urlSchemeAndHost, $format, $outputPath);
                $documentNodesIteration->next();
            }
        } catch (CrawlerException $e) {
            $this->output('Crawling failed: %s', [$e->getMessage()]);
        }
    }

    /**
     * @throws CrawlerException
     */
    protected function crawlNode(
        NodeInterface $node,
        NodeInterface $siteNode,
        string $fusionPath = '',
        string $urlSchemeAndHost = '',
        string $format = 'html',
        string $outputPath = ''
    ): void {
        // Run some checks whether we should render this node
        if (!$node->isAccessible() || !$node->isVisible()) {
            $this->output('Node hidden oder inaccessible, skipping');
            return;
        }

        $parent = $node->getParent();

        if (!$parent) {
            $this->output('Parent node disabled, skipping');
            return;
        }

        while ($parent && $parent !== $siteNode) {
            if (array_key_exists($parent->getIdentifier(), $this->nodeAccessCache)) {
                if (!$this->nodeAccessCache[$parent->getIdentifier()]) {
                    $this->nodeAccessCache[$node->getIdentifier()] = false;
                    $this->output('Parent node hidden or inaccessible, skipping');
                    return;
                }
                break;
            }
            if (!$parent->isAccessible() || !$parent->isVisible()) {
                $this->nodeAccessCache[$parent->getIdentifier()] = false;
                $this->nodeAccessCache[$node->getIdentifier()] = false;
                $this->output('Parent of node hidden oder inaccessible, skipping');
                return;
            }
            $this->nodeAccessCache[$parent->getIdentifier()] = true;
            $parent = $parent->getParent();
        }

        $this->nodeAccessCache[$node->getIdentifier()] = true;

        try {
            // TODO: Handle shortcut nodes differently when storing results
            $result = $this->fusionRenderingService->render($siteNode, $node, $fusionPath, $urlSchemeAndHost);
            if (!$result) {
                throw new CrawlerException('Empty output when rendering node');
            }

            // Store rendered output in file if outputPath is set
            if ($outputPath) {
                $filePath = $node === $siteNode ? '/index' : $this->fusionRenderingService->getNodeUri(
                    $siteNode,
                    $node,
                    $urlSchemeAndHost,
                    $format
                );
                if ($filePath) {
                    $this->writeRenderingResultToFile($outputPath . $filePath, $result);
                    $this->output('Wrote result to cache: %s', [$filePath]);
                }
            }

            $httpResponse = strtok($result, "\n");
            $this->output(sprintf('Result: %s', $httpResponse));
        } catch (FusionException $e) {
            throw new CrawlerException(sprintf('Fusion error when rendering node: %s', $node->getLabel()), 1670316158,
                $e);
        } catch (DomainException $e) {
            throw new CrawlerException(sprintf('Domain error when rendering node: %s', $node->getLabel()), 1670316223,
                $e);
        } catch (SecurityException $e) {
            throw new CrawlerException(sprintf('Security error when rendering node: %s', $node->getLabel()), 1670316226,
                $e);
        } catch (NeosException $e) {
            throw new CrawlerException(sprintf('Neos error when rendering node: %s', $node->getLabel()), 1670316229,
                $e);
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
    }

    protected function output(string $message, array $arguments = [], bool $lineBreak = true): void
    {
        if (is_callable($this->messageCallback)) {
            call_user_func($this->messageCallback, $message . ($lineBreak ? PHP_EOL : ''), $arguments);
        }
    }
}
