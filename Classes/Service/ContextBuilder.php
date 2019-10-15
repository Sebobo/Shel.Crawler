<?php
declare(strict_types=1);

namespace Shel\Crawler\Service;

/*                                                                        *
 * This script belongs to the Neos CMS plugin Shel.Crawler                *
 *                                                                        */

use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Http;
use Neos\Flow\Mvc;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class ContextBuilder
{
    /**
     * @var ControllerContext
     */
    protected $controllerContext;

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
            $httpRequest = Http\Request::create(new Http\Uri($urlSchemeAndHost));
            $httpRequest->setBaseUri(new Http\Uri($urlSchemeAndHost));
            $actionRequest = new Mvc\ActionRequest($httpRequest);
            $actionRequest->setFormat('html');

            $this->controllerContext = new ControllerContext(
                $actionRequest,
                new Http\Response(),
                new Mvc\Controller\Arguments(),
                new Mvc\Routing\UriBuilder()
            );
        }

        return $this->controllerContext;
    }
}
