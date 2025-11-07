<?php

declare(strict_types=1);

namespace Shel\Crawler\Service;

/*                                                                        *
 * This script belongs to the Neos CMS plugin Shel.Crawler                *
 *                                                                        */

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Exception\InvalidLocaleIdentifierException;
use Neos\Flow\I18n\Locale;
use Neos\Flow\I18n\Service;
use Neos\Flow\Security\Exception as SecurityException;
use Neos\Fusion\Core\Runtime;
use Neos\Fusion\Exception as FusionException;
use Neos\Neos\Domain\Exception as DomainException;
use Neos\Neos\Domain\Service\FusionService;
use Neos\Neos\Exception as NeosException;
use Neos\Neos\Service\LinkingService;
use Shel\Crawler\CrawlerException;

/**
 * @Flow\Scope("singleton")
 */
class FusionRenderingService
{
    /**
     * @Flow\Inject
     * @var Service
     */
    protected $i18nService;

    protected ?Runtime $fusionRuntime = null;

    protected string $currentRuntimeContext = '';

    protected string $dimensionsHash = '';

    /**
     * @Flow\Inject
     * @var FusionService
     */
    protected $fusionService;

    /**
     * @Flow\Inject
     * @var ContextBuilder
     */
    protected $contextBuilder;

    /**
     * @Flow\Inject
     * @var LinkingService
     */
    protected $linkingService;

    protected array $options = ['enableContentCache' => true];
    #[\Neos\Flow\Annotations\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * @throws FusionException
     * @throws NeosException
     * @throws SecurityException
     */
    public function render(
        \Neos\ContentRepository\Core\Projection\ContentGraph\Node $siteNode,
        \Neos\ContentRepository\Core\Projection\ContentGraph\Node $node,
        string $fusionPath,
        string $urlSchemeAndHost,
        array $contextData = []
    ): string {
        // TODO 9.0 migration: Try to remove the toLegacyDimensionArray() call and make your codebase more typesafe.

        $dimensions = $node->originDimensionSpacePoint->toLegacyDimensionArray();
        $fusionRuntime = $this->getFusionRuntime($siteNode, $urlSchemeAndHost);

        $dimensionsHash = md5(json_encode($dimensions));
        if ($dimensionsHash !== $this->dimensionsHash && array_key_exists('language', $dimensions) && $dimensions['language'] !== []) {
            $this->dimensionsHash = $dimensionsHash;
            try {
                $currentLocale = new Locale($dimensions['language'][0]);
                $this->i18nService->getConfiguration()->setCurrentLocale($currentLocale);
                $this->i18nService->getConfiguration()->setFallbackRule([
                    'strict' => false,
                    'order' => array_reverse($dimensions['language'])
                ]);
            } catch (InvalidLocaleIdentifierException $e) {
                // TODO: Add logging
            }
        }

        $fusionRuntime->pushContextArray(array_merge([
            'node' => $node,
            'documentNode' => $node,
            'site' => $siteNode,
            'editPreviewMode' => null,
        ], $contextData));

        $output = $fusionRuntime->render($fusionPath);
        $fusionRuntime->popContext();
        return $output;
    }

    /**
     * @throws CrawlerException
     */
    public function getNodeUri(
        \Neos\ContentRepository\Core\Projection\ContentGraph\Node $siteNode,
        \Neos\ContentRepository\Core\Projection\ContentGraph\Node $node,
        string $urlSchemeAndHost,
        string $format = 'html'
    ): string {
        try {
            return $this->linkingService->createNodeUri(
                $this->getFusionRuntime($siteNode, $urlSchemeAndHost)->getControllerContext(),
                $node,
                $siteNode,
                $format,
                false,
                [],
                '',
                false,
                []
            );
        } catch (\Exception $e) {
            $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);
            // TODO 9.0 migration: Try to remove the (string) cast and make your code more type-safe.

            throw new CrawlerException(sprintf('Could not create node URI for node "%s"', (string) $subgraph->findNodePath($node->aggregateId)), 1524098982,
                $e);
        }
    }

    /**
     * @throws FusionException|DomainException
     */
    protected function getFusionRuntime(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $currentSiteNode, string $urlSchemeAndHost): Runtime
    {
        // TODO 9.0 migration: Check if you could change your code to work with the NodeAggregateId value object instead.

        // Create a new runtime if the current site node is different from the last one
        $runtimeContext = $currentSiteNode->aggregateId->value . '-' . $urlSchemeAndHost;
        if ($this->fusionRuntime === null || $this->currentRuntimeContext !== $runtimeContext) {
            $this->currentRuntimeContext = $runtimeContext;
            $this->fusionRuntime = $this->fusionService->createRuntime(
                $currentSiteNode,
                $this->contextBuilder->buildControllerContext($urlSchemeAndHost)
            );

            if (isset($this->options['enableContentCache']) && $this->options['enableContentCache'] !== null) {
                $this->fusionRuntime->setEnableContentCache($this->options['enableContentCache']);
            }
        }

        return $this->fusionRuntime;
    }
}
