<?php

declare(strict_types=1);

namespace Gadget\Http\Tests\Client;

use Gadget\Cache\CacheItemPool;
use Gadget\Http\Client\Client;
use Gadget\Http\Cookie\Cookie;
use Gadget\Http\Cookie\CookieJar;
use Gadget\Http\Message\MessageFactory;
use Gadget\Http\Tests\Support\HeaderStampMiddleware;
use Gadget\Http\Tests\Support\HttpTestCase;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;

final class ClientTest extends HttpTestCase
{
    public function testSendRequestReturnsTheResponseFromTheUnderlyingClient(): void
    {
        $this->transport->append(new Response(202, ['X-Id' => '7'], 'accepted'));

        $response = $this->createClient()->sendRequest(new Request('POST', 'https://example.com/things'));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('7', $response->getHeaderLine('X-Id'));
        self::assertSame('accepted', (string) $response->getBody());
        self::assertSame('https://example.com/things', (string) $this->sentRequest()->getUri());
    }


    public function testSendRequestAcceptsAServerRequestToo(): void
    {
        $this->transport->append(new Response(200));

        $this->createClient()->sendRequest(new ServerRequest('GET', 'https://example.com/'));

        self::assertSame('GET', $this->sentRequest()->getMethod());
    }


    public function testSetCookieHeadersAreStoredInTheJarUnderTheRequestHost(): void
    {
        $this->transport->append(new Response(200, ['Set-Cookie' => 'sid=abc; Path=/']));
        $client = $this->createClient();

        $client->sendRequest(new Request('GET', 'https://example.com/login'));

        $cookie = $client->getCookieJar()->getCookie('example.com', '/', 'sid');
        self::assertNotNull($cookie);
        self::assertSame('abc', $cookie->getValue());
        self::assertSame('example.com', $cookie->getDomain());
    }


    public function testARelativeCookiePathIsReplacedByTheRequestDirectory(): void
    {
        $this->transport->append(new Response(200, ['Set-Cookie' => 'rel=2; Path=relative']));
        $client = $this->createClient();

        $client->sendRequest(new Request('GET', 'https://example.com/app/login'));

        self::assertSame('2', $client->getCookieJar()->getCookie('example.com', '/app', 'rel')?->getValue());
    }


    public function testACookieWithNoPathAttributeIsStoredUnderRoot(): void
    {
        // Cookie::fromString() fills in '/' when Path is absent, so the client never sees
        // the missing attribute and does not apply the request directory the way a browser would.
        $this->transport->append(new Response(200, ['Set-Cookie' => 'bare=1']));
        $client = $this->createClient();

        $client->sendRequest(new Request('GET', 'https://example.com/app/login'));

        self::assertSame('1', $client->getCookieJar()->getCookie('example.com', '/', 'bare')?->getValue());
        self::assertNull($client->getCookieJar()->getCookie('example.com', '/app', 'bare'));
    }


    public function testACookieForAnotherDomainIsIgnored(): void
    {
        $this->transport->append(new Response(200, ['Set-Cookie' => 'sid=abc; Domain=evil.test; Path=/']));
        $client = $this->createClient();

        $client->sendRequest(new Request('GET', 'https://example.com/'));

        self::assertSame([], $client->getCookieJar()->getCookies());
    }


    public function testACookieForAParentDomainIsAccepted(): void
    {
        $this->transport->append(new Response(200, ['Set-Cookie' => 'sid=abc; Domain=.example.com; Path=/']));
        $client = $this->createClient();

        $client->sendRequest(new Request('GET', 'https://api.example.com/'));

        self::assertSame('abc', $client->getCookieJar()->getCookie('.example.com', '/', 'sid')?->getValue());
    }


    public function testStoredCookiesAreSentBackAsNameValuePairs(): void
    {
        $this->transport->append(
            new Response(200, ['Set-Cookie' => ['sid=abc; Path=/; HttpOnly', 'theme=dark; Path=/']]),
            new Response(200)
        );
        $client = $this->createClient();

        $client->sendRequest(new Request('GET', 'https://example.com/login'));
        $client->sendRequest(new Request('GET', 'https://example.com/home'));

        self::assertFalse($this->sentRequest(0)->hasHeader('Cookie'));
        self::assertSame('sid=abc; theme=dark', $this->sentRequest(1)->getHeaderLine('Cookie'));
    }


    public function testOnlyCookiesThatMatchTheRequestAreSent(): void
    {
        $this->transport->append(new Response(200), new Response(200), new Response(200));
        $client = $this->createClient();
        $client->getCookieJar()->setCookies([
            'everywhere=1; Domain=example.com; Path=/',
            'scoped=1; Domain=example.com; Path=/admin',
            'locked=1; Domain=example.com; Path=/; Secure',
            'foreign=1; Domain=example.org; Path=/',
        ]);

        $client->sendRequest(new Request('GET', 'http://example.com/'));
        $client->sendRequest(new Request('GET', 'https://example.com/admin/users'));
        $client->sendRequest(new Request('GET', 'https://nothing.test/'));

        self::assertSame('everywhere=1', $this->sentRequest(0)->getHeaderLine('Cookie'));
        self::assertSame('everywhere=1; scoped=1; locked=1', $this->sentRequest(1)->getHeaderLine('Cookie'));
        self::assertFalse($this->sentRequest(2)->hasHeader('Cookie'));
    }


    public function testMiddlewareWrapsEveryRequest(): void
    {
        $this->transport->append(new Response(200));
        $client = $this->createClient([new HeaderStampMiddleware('outer'), new HeaderStampMiddleware('inner')]);

        $response = $client->sendRequest(new Request('GET', 'https://example.com/'));

        self::assertSame(['inner', 'outer'], $this->sentRequest()->getHeader('X-Stamp'));
        self::assertSame(['outer', 'inner'], $response->getHeader('X-Stamp'));
    }


    public function testMiddlewareCanBeReplacedAfterConstruction(): void
    {
        $this->transport->append(new Response(200));
        $client = $this->createClient();
        $middleware = [new HeaderStampMiddleware('late')];

        self::assertSame([], $client->getMiddleware());
        self::assertSame($client, $client->setMiddleware($middleware));
        self::assertSame($middleware, $client->getMiddleware());

        $client->sendRequest(new Request('GET', 'https://example.com/'));

        self::assertSame(['late'], $this->sentRequest()->getHeader('X-Stamp'));
    }


    public function testTheCacheIsNamespacedByTheClientClass(): void
    {
        $client = $this->createClient();

        self::assertSame(hash('SHA256', Client::class . '::cookieJar'), $client->getCache()->key('cookieJar'));
    }


    public function testGetMessageFactoryReturnsTheFactoryItWasGiven(): void
    {
        $factory = self::createMessageFactory();
        $client = new Client($this->guzzle, $factory, new CacheItemPool($this->adapter));

        self::assertInstanceOf(MessageFactory::class, $client->getMessageFactory());
        self::assertSame($factory, $client->getMessageFactory());
    }


    public function testAFreshClientStartsWithAnEmptyJarAndStoresItInTheCache(): void
    {
        $client = $this->createClient();

        $jar = $client->getCookieJar();

        self::assertSame([], $jar->getCookies());
        self::assertSame($jar, $client->getCookieJar());
        self::assertInstanceOf(CookieJar::class, $client->getCache()->getObject('cookieJar', CookieJar::class));
    }


    public function testAJarAlreadyInTheCacheIsLoadedInsteadOfReplaced(): void
    {
        $stored = new CookieJar(['sid=abc; Domain=example.com; Path=/']);
        (new CacheItemPool($this->adapter, [Client::class]))->set('cookieJar', $stored);

        $jar = $this->createClient()->getCookieJar();

        self::assertSame('abc', $jar->getCookie('example.com', '/', 'sid')?->getValue());
    }


    public function testTheJarIsWrittenBackToTheCacheWhenTheClientIsDestroyed(): void
    {
        $this->transport->append(new Response(200, ['Set-Cookie' => 'sid=abc; Path=/']));
        $client = $this->createClient();
        $client->sendRequest(new Request('GET', 'https://example.com/'));

        unset($client);

        $reloaded = $this->createClient()->getCookieJar();
        self::assertSame('abc', $reloaded->getCookie('example.com', '/', 'sid')?->getValue());
    }


    public function testExpiredCookiesAreDroppedWhenTheJarIsWrittenBack(): void
    {
        $client = $this->createClient();
        $client->getCookieJar()->setCookies([
            new Cookie(['Name' => 'old', 'Domain' => 'example.com', 'Expires' => time() - 1]),
            new Cookie(['Name' => 'fresh', 'Domain' => 'example.com', 'Expires' => time() + 60]),
        ]);

        unset($client);

        $reloaded = $this->createClient()->getCookieJar();
        self::assertNull($reloaded->getCookie('example.com', '/', 'old'));
        self::assertNotNull($reloaded->getCookie('example.com', '/', 'fresh'));
    }
}
