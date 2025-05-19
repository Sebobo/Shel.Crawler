<?php

declare(strict_types=1);

namespace Shel\Crawler\Service;

/*                                                                        *
 * This script belongs to the Neos CMS plugin Shel.Crawler                *
 *                                                                        */

use GuzzleHttp\Psr7\Uri;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Mvc;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Exception\InvalidActionNameException;
use Neos\Flow\Mvc\Exception\InvalidArgumentNameException;
use Neos\Flow\Mvc\Exception\InvalidArgumentTypeException;
use Neos\Flow\Mvc\Exception\InvalidControllerNameException;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Http\Factories\ServerRequestFactory;
use Shel\Crawler\Http\CrawlerRequestHandler;

/**
 * @Flow\Scope("singleton")
 */
class ContextBuilder
{
    /**
     * @var ControllerContext
     */
    protected $controllerContext;

    /**
     * @Flow\Inject
     * @var Mvc\ActionRequestFactory
     */
    protected $actionRequestFactory;

    /**
     * @Flow\Inject
     * @var ServerRequestFactory
     */
    protected $serverRequestFactory;

    /**
     * @Flow\Inject
     * @var Bootstrap
     */
    protected $bootstrap;

    public function initializeObject(): void
    {
        putenv('FLOW_REWRITEURLS=1');
    }

    public function buildControllerContext(string $urlSchemeAndHost): ControllerContext
    {
        if (!($this->controllerContext instanceof ControllerContext)) {
            try {
                $serverRequest = $this->serverRequestFactory->createServerRequest(
                    'GET',
                    new Uri($urlSchemeAndHost)
                );
                $serverRequest = $serverRequest->withAttribute(
                    'routingParameters',
                    RouteParameters::createEmpty()->withParameter(
                        'requestUriHost',
                        $serverRequest->getUri()->getHost()
                    )
                );

                // Set our own request handler to prevent the CLI request handler from being used
                $requestHandler = new CrawlerRequestHandler($serverRequest);
                $this->bootstrap->setActiveRequestHandler($requestHandler);

                // Create a new action request to build a custom controller context
                $actionRequest = $this->actionRequestFactory->createActionRequest($serverRequest);
                $actionRequest->setFormat('html');

                $this->controllerContext = new ControllerContext(
                    $actionRequest,
                    new Mvc\ActionResponse(),
                    new Mvc\Controller\Arguments(),
                    new Mvc\Routing\UriBuilder()
                );
            } catch (InvalidActionNameException|InvalidArgumentNameException|InvalidArgumentTypeException|InvalidControllerNameException $e) {
            }
        }

        return $this->controllerContext;
    }
}
