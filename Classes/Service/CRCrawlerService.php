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
    #[\Neos\Flow\Annotations\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;
    #[\Neos\Flow\Annotations\Inject]
    protected \Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface $nodeLabelGenerator;

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

        /** @var \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub $contentContext */
        $contentContext = new \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub([
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

        // TODO: Clean out old cached files

        $start = microtime(true);

        $this->output('Crawling node: <b>"%s"</b> - ', [$siteNode->getLabel()], false);
        $this->crawlNode($siteNode, $siteNode, $fusionPath, $urlSchemeAndHost, $format, $outputPath);

        while ($node = $documentNodesIteration->current()) {
            try {
                $this->output('Crawling node: <b>"%s"</b> - ', [$this->nodeLabelGenerator->getLabel($node)], false);
                $this->crawlNode($node, $siteNode, $fusionPath, $urlSchemeAndHost, $format, $outputPath);
            } catch (CrawlerException $e) {
                $this->output('<error>Error: %s</error>', [$e->getMessage()]);
            }
            $documentNodesIteration->next();
        }

        $duration = (int)((microtime(true) - $start) * 1000);
        $this->output(sprintf('<info>Crawling site finished in: %d ms</info>', $duration));
    }

    /**
     * @throws CrawlerException
     */
    protected function crawlNode(
        \Neos\ContentRepository\Core\Projection\ContentGraph\Node $node,
        \Neos\ContentRepository\Core\Projection\ContentGraph\Node $siteNode,
        string $fusionPath = '',
        string $urlSchemeAndHost = '',
        string $format = 'html',
        string $outputPath = ''
    ): void {
        $timerStart = microtime(true);

        // Run some checks whether we should render this node
        if (!$node->isAccessible() || !$node->isVisible()) {
            $this->output('<error>Node hidden oder inaccessible, skipping</error>');
            return;
        }
        $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);

        $parent = $subgraph->findParentNode($node->aggregateId);

        if (!$parent) {
            $this->output('<error>Parent node disabled, skipping</error>');
            return;
        }

        while ($parent && $parent !== $siteNode) {
            // TODO 9.0 migration: Check if you could change your code to work with the NodeAggregateId value object instead.

            if (array_key_exists($parent->aggregateId->value, $this->nodeAccessCache)) {
                // TODO 9.0 migration: Check if you could change your code to work with the NodeAggregateId value object instead.

                if (!$this->nodeAccessCache[$parent->aggregateId->value]) {
                    // TODO 9.0 migration: Check if you could change your code to work with the NodeAggregateId value object instead.

                    $this->nodeAccessCache[$node->aggregateId->value] = false;
                    $this->output('<error>Parent node hidden or inaccessible, skipping</error>');
                    return;
                }
                break;
            }
            if (!$parent->isAccessible() || !$parent->isVisible()) {
                // TODO 9.0 migration: Check if you could change your code to work with the NodeAggregateId value object instead.

                $this->nodeAccessCache[$parent->aggregateId->value] = false;
                // TODO 9.0 migration: Check if you could change your code to work with the NodeAggregateId value object instead.

                $this->nodeAccessCache[$node->aggregateId->value] = false;
                $this->output('<error>Parent of node hidden oder inaccessible, skipping</error>');
                return;
            }
            // TODO 9.0 migration: Check if you could change your code to work with the NodeAggregateId value object instead.

            $this->nodeAccessCache[$parent->aggregateId->value] = true;
            $subgraph = $this->contentRepositoryRegistry->subgraphForNode($parent);
            $parent = $subgraph->findParentNode($parent->aggregateId);
        }
        // TODO 9.0 migration: Check if you could change your code to work with the NodeAggregateId value object instead.


        $this->nodeAccessCache[$node->aggregateId->value] = true;

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
                    // Make sure that the file path has a .html extension
                    if (!str_ends_with($filePath, '.html')) {
                        $filePath .= '.html';
                    }
                    $this->writeRenderingResultToFile($outputPath . $filePath, $result);
                    $this->output('Wrote result to cache: <i>%s</i> - ', [$filePath], false);
                }
            }

            $httpResponse = strtok($result, "\n");
            $duration = (int)round((microtime(true) - $timerStart) * 1000, 1);
            $this->output(sprintf('<info>%d ms</info> - <success>%s</success>', $duration, $httpResponse));
        } catch (FusionException $e) {
            throw new CrawlerException(sprintf('Fusion error when rendering node: %s', $this->nodeLabelGenerator->getLabel($node)), 1670316158,
                $e);
        } catch (DomainException $e) {
            throw new CrawlerException(sprintf('Domain error when rendering node: %s', $this->nodeLabelGenerator->getLabel($node)), 1670316223,
                $e);
        } catch (SecurityException $e) {
            throw new CrawlerException(sprintf('Security error when rendering node: %s', $this->nodeLabelGenerator->getLabel($node)), 1670316226,
                $e);
        } catch (NeosException $e) {
            throw new CrawlerException(sprintf('Neos error when rendering node: %s', $this->nodeLabelGenerator->getLabel($node)), 1670316229,
                $e);
        }
    }

    protected function writeRenderingResultToFile(
        string $filePath,
        string $result
    ): void {
        $fileDirectory = dirname($filePath);
        if (!is_dir($fileDirectory) && !mkdir($fileDirectory, 0777, true)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $fileDirectory));
        }

        // Remove HTTP headers - look for the first occurrence of <!doctype or <html
        if (preg_match('/(<!doctype|<html)/i', $result, $matches, PREG_OFFSET_CAPTURE)) {
            $htmlStart = (int)$matches[0][1];
            $result = substr($result, $htmlStart);
        } else {
            // Alternative approach: remove everything until the first blank line
            $parts = explode("\r\n\r\n", $result, 2);
            if (count($parts) > 1) {
                $result = $parts[1];
            } else {
                $parts = explode("\n\n", $result, 2);
                if (count($parts) > 1) {
                    $result = $parts[1];
                }
            }
        }

        file_put_contents($filePath, $result);
    }

    protected function output(string $message, array $arguments = [], bool $lineBreak = true): void
    {
        if (is_callable($this->messageCallback)) {
            call_user_func($this->messageCallback, $message . ($lineBreak ? PHP_EOL : ''), $arguments);
        }
    }
}
