<?php
declare(strict_types=1);

namespace Shel\Crawler\Service;

/*                                                                        *
 * This script belongs to the Neos CMS plugin Shel.Crawler                *
 *                                                                        */

use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\I18n\Exception\InvalidLocaleIdentifierException;
use Neos\Flow\Security\Exception as SecurityException;
use Neos\Fusion\Core\Runtime;
use Neos\Fusion\Exception as FusionException;
use Neos\Neos\Controller\CreateContentContextTrait;
use Neos\Flow\I18n\Service;
use Neos\Flow\I18n\Locale;
use Neos\Neos\Domain\Exception as NeosException;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\FusionService;

class FusionRenderingService
{
    use CreateContentContextTrait;

    /**
     * @Flow\Inject
     * @var Service
     */
    protected $i18nService;

    /**
     * @var Runtime
     */
    protected $fusionRuntime;

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
     * @var array
     */
    protected $options = ['enableContentCache' => true];

    /**
     * @param ContentContext $context
     * @param NodeInterface $siteNode
     * @param NodeInterface $node
     * @param string $fusionPath
     * @param string $urlSchemeAndHost
     * @param array $contextData
     * @return string
     * @throws FusionException
     * @throws NeosException
     * @throws SecurityException
     */
    public function render(ContentContext $context, NodeInterface $siteNode, NodeInterface $node, string $fusionPath, string $urlSchemeAndHost, array $contextData = []): string
    {
        if (!$node instanceof NodeInterface || $node === null) {
            return '';
        }

        $dimensions = $node->getDimensions();
        $fusionRuntime = $this->getFusionRuntime($siteNode, $urlSchemeAndHost);

        if (array_key_exists('language', $dimensions) && $dimensions['language'] !== []) {
            try {
                $currentLocale = new Locale($dimensions['language'][0]);
                $this->i18nService->getConfiguration()->setCurrentLocale($currentLocale);
                $this->i18nService->getConfiguration()->setFallbackRule([
                    'strict' => false,
                    'order' => array_reverse($dimensions['language'])
                ]);
            } catch (InvalidLocaleIdentifierException $e) {
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
     * @param string $nodeIdentifier
     * @param string $fusionPath
     * @param string $urlSchemeAndHost
     * @param string $workspace
     * @param array $contextData
     * @return string
     * @throws FusionException
     * @throws NeosException
     * @throws SecurityException
     */
    public function renderByIdentifier(
        string $nodeIdentifier,
        string $fusionPath,
        string $urlSchemeAndHost,
        string $workspace = 'live',
        array $contextData = []
    ): string {
        $context = $this->createContentContext($workspace);
        $node = $context->getNodeByIdentifier($nodeIdentifier);
        if ($node !== null) {
            return $this->render($node, $fusionPath, $urlSchemeAndHost, $contextData);
        }
        return '';
    }

    /**
     * @param NodeInterface $currentSiteNode
     * @param string $urlSchemeAndHost
     * @return Runtime
     * @throws FusionException
     * @throws NeosException
     */
    protected function getFusionRuntime(NodeInterface $currentSiteNode, string $urlSchemeAndHost): Runtime
    {
        if ($this->fusionRuntime === null) {
            $this->fusionRuntime = $this->fusionService->createRuntime($currentSiteNode,
                $this->contextBuilder->buildControllerContext($urlSchemeAndHost));

            if (isset($this->options['enableContentCache']) && $this->options['enableContentCache'] !== null) {
                $this->fusionRuntime->setEnableContentCache($this->options['enableContentCache']);
            }
        }

        return $this->fusionRuntime;
    }
}
