<?php
declare(strict_types=1);

namespace Shel\Crawler\Service;

/*                                                                        *
 * This script belongs to the Neos CMS plugin Shel.Crawler                *
 *                                                                        */

use GuzzleHttp\Psr7\Uri;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc;
use Neos\Flow\Annotations as Flow;
use Neos\Http\Factories\ServerRequestFactory;

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

    public function initializeObject()
    {
        putenv('FLOW_REWRITEURLS=1');
    }

    /**
     * @param string $urlSchemeAndHost
     * @return ControllerContext
     */
    public function buildControllerContext(string $urlSchemeAndHost): ControllerContext
    {
        if(!($this->controllerContext instanceof ControllerContext)) {
            try {
                $actionRequest = $this->actionRequestFactory->createActionRequest(
                    $this->serverRequestFactory->createServerRequest('GET', new Uri($urlSchemeAndHost))
                );
                $actionRequest->setFormat('html');

                $this->controllerContext = new ControllerContext(
                    $actionRequest,
                    new Mvc\ActionResponse(),
                    new Mvc\Controller\Arguments(),
                    new Mvc\Routing\UriBuilder()
                );
            } catch (Mvc\Exception\InvalidActionNameException $e) {
            } catch (Mvc\Exception\InvalidArgumentNameException $e) {
            } catch (Mvc\Exception\InvalidArgumentTypeException $e) {
            } catch (Mvc\Exception\InvalidControllerNameException $e) {
            }
        }

        return $this->controllerContext;
    }
}
