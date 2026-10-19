<?php

declare(strict_types=1);

namespace Gadget\Http\Tests\Message;

use Gadget\Http\Client\Client;
use Gadget\Http\Cookie\CookieJar;
use Gadget\Http\Exception\ClientException;
use Gadget\Http\Exception\HttpException;
use Gadget\Http\Exception\RequestException;
use Gadget\Http\Exception\ResponseException;
use Gadget\Http\Message\RequestBuilder;
use Gadget\Http\Tests\Support\CallbackMessageHandler;
use Gadget\Http\Tests\Support\HttpTestCase;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class MessageHandlerTest extends HttpTestCase
{
    private const URI = 'https://example.com/things';


    /**
     * @return \Closure(RequestBuilder):ServerRequestInterface
     */
    private static function getThings(): \Closure
    {
        return fn(RequestBuilder $builder): ServerRequestInterface => $builder
            ->setMethod('GET')
            ->setUri(self::URI)
            ->getRequest();
    }


    /**
     * @return CallbackMessageHandler<mixed[]>
     */
    private static function jsonHandler(): CallbackMessageHandler
    {
        /** @var CallbackMessageHandler<mixed[]> $handler */
        $handler = new CallbackMessageHandler(
            self::getThings(),
            fn(ResponseInterface $response, ServerRequestInterface $request): array
                => (array) json_decode((string) $response->getBody(), true)
        );
        return $handler;
    }


    public function testInvokeBuildsSendsAndHandlesTheExchange(): void
    {
        $this->transport->append(new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'));

        $result = self::jsonHandler()->invoke($this->createClient());

        self::assertSame(['ok' => true], $result);
        self::assertSame('GET', $this->sentRequest()->getMethod());
        self::assertSame(self::URI, (string) $this->sentRequest()->getUri());
    }


    public function testTheRequestBuilderSharesTheClientsCookieJar(): void
    {
        $this->transport->append(new Response(204));
        $client = $this->createClient();
        $seenJar = null;

        $handler = new CallbackMessageHandler(
            function (RequestBuilder $builder) use (&$seenJar): ServerRequestInterface {
                $seenJar = $builder->getCookieJar();
                return $builder->setUri(self::URI)->getRequest();
            },
            fn(ResponseInterface $response, ServerRequestInterface $request): int => $response->getStatusCode()
        );

        self::assertSame(204, $handler->invoke($client));
        self::assertInstanceOf(CookieJar::class, $seenJar);
        self::assertSame($client->getCookieJar(), $seenJar);
    }


    public function testAFailureWhileBuildingTheRequestBecomesARequestException(): void
    {
        $cause = new \LogicException('bad builder');
        $handler = new CallbackMessageHandler(
            fn(RequestBuilder $builder): ServerRequestInterface => throw $cause,
            fn(ResponseInterface $response, ServerRequestInterface $request): int => $response->getStatusCode()
        );

        try {
            $handler->invoke($this->createClient());
            self::fail('Expected a RequestException');
        } catch (RequestException $e) {
            self::assertSame('Error building request', $e->getMessage());
            self::assertSame($cause, $e->getPrevious());
        }

        self::assertSame([], $this->sentRequests);
    }


    public function testAFailureWhileSendingBecomesAClientException(): void
    {
        $cause = new ConnectException('Connection refused', new Request('GET', self::URI));
        $this->transport->append($cause);

        try {
            self::jsonHandler()->invoke($this->createClient());
            self::fail('Expected a ClientException');
        } catch (ClientException $e) {
            self::assertInstanceOf(ClientExceptionInterface::class, $e);
            self::assertSame('Error sending request: GET ' . self::URI, $e->getMessage());
            self::assertSame($cause, $e->getPrevious());
        }
    }


    public function testAFailureWhileHandlingTheResponseBecomesAResponseException(): void
    {
        $this->transport->append(new Response(503));
        $cause = new \RuntimeException('cannot parse');
        $handler = new CallbackMessageHandler(
            self::getThings(),
            function (ResponseInterface $response, ServerRequestInterface $request) use ($cause): int {
                if ($response->getStatusCode() >= 500) {
                    throw $cause;
                }
                return $response->getStatusCode();
            }
        );

        try {
            $handler->invoke($this->createClient());
            self::fail('Expected a ResponseException');
        } catch (ResponseException $e) {
            self::assertSame('Error handling response: GET ' . self::URI . ' => 503', $e->getMessage());
            self::assertSame($cause, $e->getPrevious());
        }
    }


    public function testHandleErrorCanTurnAFailureIntoAFallbackValue(): void
    {
        $this->transport->append(new ConnectException('down', new Request('GET', self::URI)));
        $seen = null;
        $handler = new CallbackMessageHandler(
            self::getThings(),
            fn(ResponseInterface $response, ServerRequestInterface $request): string => 'never',
            function (\Throwable $t) use (&$seen): string {
                $seen = $t;
                return 'fallback';
            }
        );

        self::assertSame('fallback', $handler->invoke($this->createClient()));
        self::assertInstanceOf(ClientException::class, $seen);
    }


    public function testGetClientThrowsUntilInvokeHasRun(): void
    {
        $handler = self::jsonHandler();

        try {
            $handler->exposeClient();
            self::fail('Expected an HttpException');
        } catch (HttpException $e) {
            self::assertSame('Client not set', $e->getMessage());
        }

        $this->transport->append(new Response(200, [], '{}'));
        $client = $this->createClient();
        $handler->invoke($client);

        self::assertSame($client, $handler->exposeClient());
    }


    public function testJsonToArrayDecodesTheResponseBody(): void
    {
        $response = new Response(200, [], '{"items":[1,2],"total":2}');

        self::assertSame(['items' => [1, 2], 'total' => 2], self::jsonHandler()->exposeJsonToArray($response));
    }


    public function testJsonToArrayRejectsMalformedJson(): void
    {
        $this->expectException(\JsonException::class);

        self::jsonHandler()->exposeJsonToArray(new Response(200, [], '{not json'));
    }


    public function testInvokeGoesThroughTheClientSoMiddlewareRuns(): void
    {
        $this->transport->append(new Response(200, [], '{}'));
        $client = $this->createClient([new \Gadget\Http\Tests\Support\HeaderStampMiddleware('mw')]);

        self::jsonHandler()->invoke($client);

        self::assertInstanceOf(Client::class, $client);
        self::assertSame(['mw'], $this->sentRequest()->getHeader('X-Stamp'));
    }
}
