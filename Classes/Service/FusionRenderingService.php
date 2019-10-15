<?php
declare(strict_types=1);

namespace Shel\Crawler\Service;

/*                                                                        *
 * This script belongs to the Neos CMS plugin Shel.Crawler                *
 *                                                                        */

use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\I18n\Exception\InvalidLocaleIdentifierException;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Property\Exception as PropertyException;
use Neos\Flow\Security\Exception as SecurityException;
use Neos\Fusion\Core\Runtime;
use Neos\Fusion\Exception as FusionException;
use Neos\Flow\I18n\Service;
use Neos\Flow\I18n\Locale;
use Neos\Neos\Domain\Exception as DomainException;
use Neos\Neos\Domain\Service\FusionService;
use Neos\Neos\Exception as NeosException;
use Neos\Neos\Service\LinkingService;

class FusionRenderingService
{
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
     * @Flow\Inject
     * @var LinkingService
     */
    protected $linkingService;

    /**
     * @var array
     */
    protected $options = ['enableContentCache' => true];

    /**
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
    public function render(
      NodeInterface $siteNode,
      NodeInterface $node,
      string $fusionPath,
      string $urlSchemeAndHost,
      array $contextData = []
    ): string {
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
     * @param NodeInterface $siteNode
     * @param NodeInterface $node
     * @param string $urlSchemeAndHost
     * @param string $format
     * @return string
     * @throws FusionException
     * @throws MissingActionNameException
     * @throws PropertyException
     * @throws SecurityException
     * @throws DomainException
     * @throws NeosException
     */
    public function getNodeUri(
      NodeInterface $siteNode,
      NodeInterface $node,
      string $urlSchemeAndHost,
      string $format = 'html'
    ): string {
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
    }

    /**
     * @param NodeInterface $currentSiteNode
     * @param string $urlSchemeAndHost
     * @return Runtime
     * @throws FusionException
     * @throws DomainException
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
