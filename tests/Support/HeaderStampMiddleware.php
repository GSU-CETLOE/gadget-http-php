<?php

declare(strict_types=1);

namespace Gadget\Http\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Appends its stamp to the X-Stamp header on the way in and on the way out, so a test
 * can read the order in which a chain of middleware ran.
 */
final class HeaderStampMiddleware implements MiddlewareInterface
{
    /**
     * @param string $stamp
     */
    public function __construct(private string $stamp)
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
        return $handler
            ->handle($request->withAddedHeader('X-Stamp', $this->stamp))
            ->withAddedHeader('X-Stamp', $this->stamp);
    }
}
