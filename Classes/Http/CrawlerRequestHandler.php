<?php

declare(strict_types=1);

namespace Shel\Crawler\Http;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\Http\HttpRequestHandlerInterface;
use Neos\Http\Factories\ResponseFactory;
use Psr\Http\Message\ServerRequestInterface;

class CrawlerRequestHandler implements HttpRequestHandlerInterface
{
    private ServerRequestInterface $request;

    /**
     * @deprecated and only required for Neos 5.3 compatibility
     * @var \Neos\Flow\Http\Component\ComponentContext
     */
    private $componentContext;

    public function __construct(ServerRequestInterface $request)
    {
        $this->request = $request;
    }

    public function getHttpRequest(): ServerRequestInterface
    {
        return $this->request;
    }

    public function handleRequest(): void
    {
    }

    /**
     * @deprecated and only required for Neos 5.3 compatibility
     */
    public function getHttpResponse()
    {

    }

    /**
     * @deprecated and only required for Neos 5.3 compatibility
     */
    public function getComponentContext(): \Neos\Flow\Http\Component\ComponentContext
    {
        if ($this->componentContext === null) {
            $responseFactory = new ResponseFactory();
            $this->componentContext = new \Neos\Flow\Http\Component\ComponentContext(ServerRequest::fromGlobals(), $responseFactory->createResponse());
        }
        return $this->componentContext;
    }

    public function canHandleRequest(): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        return 100;
    }
}
