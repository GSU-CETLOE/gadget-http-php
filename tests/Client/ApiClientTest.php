<?php

declare(strict_types=1);

namespace Gadget\Http\Tests\Client;

use Gadget\Http\Message\RequestBuilder;
use Gadget\Http\Tests\Support\CallbackMessageHandler;
use Gadget\Http\Tests\Support\ExposedApiClient;
use Gadget\Http\Tests\Support\HttpTestCase;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ApiClientTest extends HttpTestCase
{
    public function testInvokeRunsTheHandlerThroughTheClient(): void
    {
        $this->transport->append(new Response(200, [], 'pong'));
        $api = new ExposedApiClient($this->createClient());
        $handler = new CallbackMessageHandler(
            fn(RequestBuilder $builder): ServerRequestInterface => $builder
                ->setUri('https://example.com/ping')
                ->getRequest(),
            fn(ResponseInterface $response, ServerRequestInterface $request): string => (string) $response->getBody()
        );

        self::assertSame('pong', $api->exposeInvoke($handler));
        self::assertSame('https://example.com/ping', (string) $this->sentRequest()->getUri());
    }


    public function testTheClientCanBeReadAndReplaced(): void
    {
        $first = $this->createClient();
        $second = $this->createClient();
        $api = new ExposedApiClient($first);

        self::assertSame($first, $api->exposeClient());
        self::assertSame($api, $api->exposeSetClient($second));
        self::assertSame($second, $api->exposeClient());
    }
}
