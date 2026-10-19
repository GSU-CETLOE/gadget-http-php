<?php

declare(strict_types=1);

namespace Gadget\Http\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Answers every request with a fixed response and never calls the next handler.
 */
final class ShortCircuitMiddleware implements MiddlewareInterface
{
    /**
     * @param ResponseInterface $response
     */
    public function __construct(private ResponseInterface $response)
    {
    }


    /**
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        return $this->response;
    }
}
