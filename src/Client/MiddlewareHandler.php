<?php

declare(strict_types=1);

namespace Gadget\Http\Client;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class MiddlewareHandler implements RequestHandlerInterface
{
    /**
     * @param ClientInterface $client
     * @param MiddlewareInterface[] $middleware
     */
    public function __construct(
        private ClientInterface $client,
        private array $middleware = []
    ) {
    }


    /**
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $middleware = array_pop($this->middleware);

        try {
            return $middleware?->process($request, $this)
                ?? $this->client->sendRequest($request);
        } finally {
            if ($middleware !== null) {
                array_push($this->middleware, $middleware);
            }
        }
    }
}
