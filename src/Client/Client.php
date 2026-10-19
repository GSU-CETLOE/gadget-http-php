<?php

declare(strict_types=1);

namespace Gadget\Http\Client;

use Gadget\Cache\CacheItemPool;
use Gadget\Http\Cookie\Cookie;
use Gadget\Http\Cookie\CookieJar;
use Gadget\Http\Message\MessageFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;

class Client implements ClientInterface
{
    private CookieJar|null $cookieJar = null;


    /**
     * @param ClientInterface $client
     * @param MessageFactory $messageFactory
     * @param CacheItemPool $cache
     * @param MiddlewareInterface[] $middleware
     */
    public function __construct(
        private ClientInterface $client,
        private MessageFactory $messageFactory,
        private CacheItemPool $cache,
        private array $middleware = []
    ) {
        $this->cache = $cache->withNamespace(self::class);
    }


    public function __destruct()
    {
        if ($this->cookieJar !== null) {
            $this->cache->set('cookieJar', $this->cookieJar->clearExpired());
        }
    }


    /**
     * @param RequestInterface $request
     * @return ResponseInterface
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $request = $this->getMessageFactory()->toServerRequest($request);

        $request = $this->addCookies($request);

        $response = (new MiddlewareHandler($this->client, $this->middleware))
            ->handle($request);

        $this->saveCookies($request, $response);

        return $response;
    }


    /**
     * @param ServerRequestInterface $request
     * @return ServerRequestInterface
     */
    protected function addCookies(ServerRequestInterface $request): ServerRequestInterface
    {
        $cookies = $this->getCookieJar()->getMatches(
            $request->getUri()->getScheme(),
            $request->getUri()->getHost(),
            $request->getUri()->getPath()
        );

        if (count($cookies) > 0) {
            $request = $request->withHeader('Cookie', implode('; ', $cookies));
        }

        return $request;
    }


    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return void
     */
    protected function saveCookies(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): void {
        $cookies = $response->getHeader('Set-Cookie');

        foreach ($cookies as $c) {
            $cookie = Cookie::fromString($c);
            if ($cookie->getDomain() === null) {
                $cookie->setDomain($request->getUri()->getHost());
            }
            if (strpos($cookie->getPath(), '/') !== 0) {
                $cookie->setPath(Cookie::getCookiePath($request->getUri()->getPath()));
            }
            if (!$cookie->matchesDomain($request->getUri()->getHost())) {
                continue;
            }
            $this->getCookieJar()->setCookie($cookie);
        }
    }


    /**
     * @return MessageFactory
     */
    public function getMessageFactory(): MessageFactory
    {
        return $this->messageFactory;
    }


    /**
     * @return CacheItemPool
     */
    public function getCache(): CacheItemPool
    {
        return $this->cache;
    }


    /**
     * @return CookieJar
     */
    public function getCookieJar(): CookieJar
    {
        if ($this->cookieJar === null) {
            $cookieJar = $this->cache->getObject('cookieJar', CookieJar::class);
            if ($cookieJar === null) {
                $cookieJar = new CookieJar();
                $this->cache->set('cookieJar', $cookieJar);
            }
            $this->cookieJar = $cookieJar;
        }

        return $this->cookieJar;
    }


    /**
     * @return MiddlewareInterface[]
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }


    /**
     * @param MiddlewareInterface[] $middleware
     * @return static
     */
    public function setMiddleware(array $middleware): static
    {
        $this->middleware = $middleware;
        return $this;
    }
}
