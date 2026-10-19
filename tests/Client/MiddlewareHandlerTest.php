<?php

declare(strict_types=1);

namespace Gadget\Http\Tests\Client;

use Gadget\Http\Client\MiddlewareHandler;
use Gadget\Http\Tests\Support\HeaderStampMiddleware;
use Gadget\Http\Tests\Support\HttpTestCase;
use Gadget\Http\Tests\Support\ShortCircuitMiddleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;

final class MiddlewareHandlerTest extends HttpTestCase
{
    public function testWithNoMiddlewareTheRequestGoesStraightToTheClient(): void
    {
        $this->transport->append(new Response(201, [], 'created'));
        $request = new ServerRequest('POST', 'https://example.com/things');

        $response = (new MiddlewareHandler($this->guzzle))->handle($request);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('created', (string) $response->getBody());
        self::assertSame('POST', $this->sentRequest()->getMethod());
    }


    public function testMiddlewareRunsFromTheEndOfTheListToTheStart(): void
    {
        $this->transport->append(new Response(200));
        $handler = new MiddlewareHandler($this->guzzle, [
            new HeaderStampMiddleware('first'),
            new HeaderStampMiddleware('second'),
        ]);

        $response = $handler->handle(new ServerRequest('GET', 'https://example.com/'));

        self::assertSame(['second', 'first'], $this->sentRequest()->getHeader('X-Stamp'));
        self::assertSame(['first', 'second'], $response->getHeader('X-Stamp'));
    }


    public function testMiddlewareCanAnswerWithoutReachingTheClient(): void
    {
        $canned = new Response(418, [], 'teapot');
        $handler = new MiddlewareHandler($this->guzzle, [
            new HeaderStampMiddleware('inner'),
            new ShortCircuitMiddleware($canned),
        ]);

        $response = $handler->handle(new ServerRequest('GET', 'https://example.com/'));

        self::assertSame($canned, $response);
        self::assertSame([], $this->sentRequests);
    }


    public function testTheHandlerCanBeReusedForMoreThanOneRequest(): void
    {
        $this->transport->append(new Response(200), new Response(200));
        $handler = new MiddlewareHandler($this->guzzle, [
            new HeaderStampMiddleware('first'),
            new HeaderStampMiddleware('second'),
        ]);

        $handler->handle(new ServerRequest('GET', 'https://example.com/one'));
        $handler->handle(new ServerRequest('GET', 'https://example.com/two'));

        self::assertSame(['second', 'first'], $this->sentRequest(0)->getHeader('X-Stamp'));
        self::assertSame(['second', 'first'], $this->sentRequest(1)->getHeader('X-Stamp'));
    }
}
