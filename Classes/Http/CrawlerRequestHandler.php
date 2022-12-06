<?php

declare(strict_types=1);

namespace Shel\Crawler\Http;

use Neos\Flow\Http\HttpRequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;

class CrawlerRequestHandler implements HttpRequestHandlerInterface
{
    private ServerRequestInterface $request;

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

    public function canHandleRequest(): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        return 100;
    }
}
