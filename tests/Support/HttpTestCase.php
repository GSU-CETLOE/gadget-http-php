<?php

declare(strict_types=1);

namespace Gadget\Http\Tests\Support;

use Gadget\Cache\CacheItemPool;
use Gadget\Http\Client\Client;
use Gadget\Http\Message\MessageFactory;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Builds the library's Client on a real Guzzle client whose transport is a MockHandler
 * queue. Every request Guzzle would have put on the wire is recorded in $sentRequests,
 * so tests can inspect exactly what the server would have received.
 */
abstract class HttpTestCase extends TestCase
{
    protected MockHandler $transport;

    /** @var RequestInterface[] */
    protected array $sentRequests = [];

    protected GuzzleClient $guzzle;

    protected ArrayAdapter $adapter;


    protected function setUp(): void
    {
        $this->transport = new MockHandler();
        $this->sentRequests = [];

        $stack = HandlerStack::create($this->transport);
        $stack->push(fn(callable $next): callable => function (RequestInterface $request, array $options) use ($next) {
            $this->sentRequests[] = $request;
            return $next($request, $options);
        });

        $this->guzzle = new GuzzleClient(['handler' => $stack]);
        $this->adapter = new ArrayAdapter();
    }


    /**
     * Builds a MessageFactory backed by Guzzle's PSR-17 factory for every role.
     *
     * @return MessageFactory
     */
    protected static function createMessageFactory(): MessageFactory
    {
        $factory = new HttpFactory();
        return new MessageFactory($factory, $factory, $factory, $factory, $factory, $factory);
    }


    /**
     * Builds a Client over the test transport and the shared in-memory cache adapter.
     *
     * @param MiddlewareInterface[] $middleware
     * @return Client
     */
    protected function createClient(array $middleware = []): Client
    {
        return new Client(
            $this->guzzle,
            self::createMessageFactory(),
            new CacheItemPool($this->adapter),
            $middleware
        );
    }


    /**
     * Returns the request that reached the transport at the given position.
     *
     * @param int $index
     * @return RequestInterface
     */
    protected function sentRequest(int $index = 0): RequestInterface
    {
        self::assertArrayHasKey($index, $this->sentRequests, "No request reached the transport at position {$index}");
        return $this->sentRequests[$index];
    }
}
