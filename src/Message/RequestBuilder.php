<?php

declare(strict_types=1);

namespace Gadget\Http\Message;

use Gadget\Http\Cookie\CookieJar;
use Gadget\Http\Exception\RequestException;
use Gadget\Io\JSON;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

class RequestBuilder
{
    /** @var string */
    public const FORM = 'application/x-www-form-urlencoded';

    /** @var string */
    public const JSON = 'application/json';


    /**
     * @param mixed[] $queryParams
     * @return string
     */
    public static function createQuery(array $queryParams): string
    {
        return http_build_query(
            $queryParams,
            '',
            null,
            PHP_QUERY_RFC3986
        );
    }


    /** @var ServerRequestInterface $request */
    private ServerRequestInterface $request;

    /** @var array<string,int|float|string|bool|null> $queryParams */
    private array $queryParams = [];


    /**
     * @param MessageFactory $messageFactory
     * @param CookieJar $cookieJar
     * @param string $method
     * @param string $uri
     */
    public function __construct(
        private MessageFactory $messageFactory,
        private CookieJar $cookieJar,
        string $method = 'GET',
        string $uri = ''
    ) {
        $this->request = $this->getMessageFactory()->createServerRequest($method, $uri);
    }


    /**
     * @return MessageFactory
     */
    protected function getMessageFactory(): MessageFactory
    {
        return $this->messageFactory;
    }


    /**
     * @param MessageFactory $messageFactory
     * @return static
     */
    protected function setMessageFactory(MessageFactory $messageFactory): static
    {
        $this->messageFactory = $messageFactory;
        return $this;
    }


    /**
     * @return ServerRequestInterface
     */
    public function getRequest(): ServerRequestInterface
    {
        return $this->request;
    }


    /**
     * @param ServerRequestInterface $request
     * @return static
     */
    protected function setRequest(ServerRequestInterface $request): static
    {
        $this->request = $request;
        return $this;
    }


    /**
     * @return string
     */
    public function getMethod(): string
    {
        return $this->getRequest()->getMethod();
    }


    /**
     * @param string $method
     * @return static
     */
    public function setMethod(string $method): static
    {
        return $this->setRequest(
            $this->getRequest()->withMethod($method)
        );
    }


    /**
     * @return UriInterface
     */
    public function getUri(): UriInterface
    {
        return $this->getRequest()->getUri();
    }


    /**
     * @param UriInterface|string $uri
     * @return static
     */
    public function setUri(UriInterface|string $uri): static
    {
        $uri = is_string($uri) ? $this->getMessageFactory()->createUri($uri) : $uri;
        return $this->setRequest(
            $this->getRequest()->withUri($uri)
        );
    }


    /**
     * @return string
     */
    public function getScheme(): string
    {
        return $this->getUri()->getScheme();
    }


    /**
     * @param string $scheme
     * @return static
     */
    public function setScheme(string $scheme): static
    {
        return $this->setUri($this->getUri()->withScheme($scheme));
    }


    /**
     * @return string
     */
    public function getHost(): string
    {
        return $this->getUri()->getHost();
    }


    /**
     * @param string $host
     * @return static
     */
    public function setHost(string $host): static
    {
        return $this->setUri($this->getUri()->withHost($host));
    }


    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->getUri()->getPath();
    }


    /**
     * @param string $path
     * @return static
     */
    public function setPath(string $path): static
    {
        return $this->setUri($this->getUri()->withPath($path));
    }


    /**
     * @return array<string,int|float|string|bool|null>
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }


    /**
     * @param array<string,int|float|string|bool|null> $queryParams
     * @return static
     */
    public function setQueryParams(array $queryParams): static
    {
        $this->queryParams = $queryParams;
        $query = self::createQuery($this->getQueryParams());
        $this->setUri($this->getUri()->withQuery($query));
        return $this;
    }


    /**
     * @return string
     */
    public function getBody(): string
    {
        $this->getRequest()->getBody()->rewind();
        return $this->getRequest()->getBody()->getContents();
    }


    /**
     * @param string $contentType
     * @param mixed $body
     * @return static
     */
    public function setBody(
        string $contentType,
        mixed $body
    ): static {
        if (!is_string($body)) {
            $body = match ($contentType) {
                self::FORM => is_array($body)
                    ? self::createQuery($body)
                    : throw new RequestException(["Body is not an array: %s", [$contentType]]),
                self::JSON => JSON::encode($body),
                default => is_scalar($body) || (is_object($body) && $body instanceof \Stringable) || $body === null
                    ? strval($body ?? '')
                    : throw new RequestException(["Unable to serialize body: %s", [$contentType]]),
            };
        }

        if ($body === '') {
            return $this;
        }

        $request = $this
            ->getRequest()
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Length', (string) strlen($body));
        $request->getBody()->write($body);
        return $this->setRequest($request);
    }


    /**
     * @param string $header
     * @return string[]
     */
    public function getHeader(string $header): array
    {
        return $this->getRequest()->getHeader($header);
    }


    /**
     * @param string $header
     * @return string|null
     */
    public function getSingleHeader(string $header): string|null
    {
        $header = $this->getHeader($header);
        return array_shift($header);
    }


    /**
     * @param string $name
     * @param string|string[] $value
     * @return static
     */
    public function setHeader(
        string $name,
        string|array $value
    ): static {
        return $this->setRequest($this->getRequest()->withHeader($name, $value));
    }


    /**
     * @return CookieJar
     */
    public function getCookieJar(): CookieJar
    {
        return $this->cookieJar;
    }
}
