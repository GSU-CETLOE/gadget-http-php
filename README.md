# gadget-http-php

A small layer on top of any [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client. You give `Client` a real client (Guzzle, Symfony's PSR-18 adapter, whatever you have), a set of PSR-17 factories, and a cache pool. In return you get a cookie jar that follows the responses and survives between runs, a PSR-15 middleware chain around every request, a fluent `RequestBuilder`, and a `MessageHandler` base class that turns "build request, send, parse response" into one method call with the errors sorted into request, transport, and response failures. A Symfony bundle registers everything for autowiring.

CETLOE at Georgia State University maintains this package. It started as `mjfk23/gadget-http`, written by Melody Kimball.

## Requirements

- PHP 8.1
- A PSR-18 client and PSR-17 factories. The package already pulls in `guzzlehttp/guzzle` and `guzzlehttp/psr7`, which cover both, but any implementation works.
- A PSR-6 cache pool for the cookie jar, wrapped in `gsu-cetloe/gadget-cache-php`'s `CacheItemPool`. The examples below use [symfony/cache](https://symfony.com/doc/current/components/cache.html). It is only a dev dependency here, so add it to your own project if you want to use it.

## Setup

Install [Composer](https://getcomposer.org/) if you don't have it, then add the package to your project:

```sh
composer require gsu-cetloe/gadget-http-php
```

To work on the library itself, clone the repository and install the development dependencies:

```sh
git clone https://github.com/GSU-CETLOE/gadget-http-php.git
cd gadget-http-php
composer install
```

Your machine does not have to run PHP 8.1. Composer is told to resolve dependencies as if it were PHP 8.1.0 no matter what version runs it, so the lock file always reflects what the library targets. Before a release, run the suite on an actual 8.1 build.

## Development commands

| Command | What it does |
| --- | --- |
| `composer test` | Runs the PHPUnit suite in `tests/`. |
| `composer phpstan` | Runs PHPStan at level 10 over `src/`, `bundle/`, and `tests/`. |
| `composer phpcs` | Checks `src/`, `bundle/`, and `tests/` against PSR-12. |
| `composer check` | Runs all three in order. Run this before you commit. |

Anything generated, such as the PHPStan and PHPUnit caches, lands in `working/`. Git ignores that directory.

## Building a client

`Client` needs three things: the PSR-18 client that does the actual network work, a `MessageFactory`, and a `CacheItemPool`. `MessageFactory` is one object that implements all six PSR-17 factory interfaces by delegating to whatever you hand it. Guzzle's `HttpFactory` implements all six, so you can pass the same instance six times.

```php
use Gadget\Cache\CacheItemPool;
use Gadget\Http\Client\Client;
use Gadget\Http\Message\MessageFactory;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

$factory = new HttpFactory();

$client = new Client(
    new GuzzleClient(),
    new MessageFactory($factory, $factory, $factory, $factory, $factory, $factory),
    new CacheItemPool(new FilesystemAdapter())
);

$response = $client->sendRequest(
    $factory->createRequest('GET', 'https://example.com/api/things')
);
```

`Client` is itself a PSR-18 client, so anything that accepts `Psr\Http\Client\ClientInterface` can take it. It does not throw on 4xx or 5xx responses; that is up to the client you wrap and to your own response handling.

## Cookies

Every `Set-Cookie` header on a response goes into the client's `CookieJar`. On the next request to a matching scheme, host, and path, the stored cookies go out in a `Cookie` header as plain `name=value` pairs. Matching follows the usual rules: `Secure` cookies only travel over HTTPS, a `Domain` of `example.com` also covers `api.example.com`, and a `Path` of `/app` covers `/app/login` but not `/application`.

The rules the client applies:

- A cookie whose `Domain` does not cover the host that set it is dropped. A cookie with no `Domain` is stored under the request host.
- A cookie with a `Path` that does not start with `/` is stored under the request's directory (`/app` for a request to `/app/login`). A cookie with no `Path` at all is stored under `/`. That second case differs from what a browser does; browsers would use the request directory there too.
- Cookies that arrive without `Expires` or `Max-Age` are given a `Max-Age` of 600 seconds by the jar. Change it by constructing your own `CookieJar` with a different default, or `null` to leave session cookies without an expiry.

The jar lives in the cache under the `Gadget\Http\Client\Client` namespace and the key `cookieJar`. The client loads it when a jar is first needed and writes it back, minus expired cookies, when the client object is destroyed. With a persistent adapter such as `FilesystemAdapter`, a login cookie from one PHP process is still there for the next one. With `ArrayAdapter` it lasts for the process only.

You can reach the jar directly through `$client->getCookieJar()` to inspect, add, or clear cookies.

## Middleware

The fourth constructor argument, or `setMiddleware()`, takes a list of PSR-15 `MiddlewareInterface` objects. Each request passes through them before it reaches the wrapped client, and each response passes back through them in reverse. The last middleware in the list is the outermost one, so it sees the request first and the response last.

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class BearerToken implements MiddlewareInterface
{
    public function __construct(private string $token)
    {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        return $handler->handle(
            $request->withHeader('Authorization', "Bearer {$this->token}")
        );
    }
}

$client->setMiddleware([new BearerToken($token)]);
```

A middleware can also return a response without calling the handler at all, which is handy for caching or for short-circuiting in tests.

Middleware receives a `ServerRequestInterface`. If you hand `sendRequest()` a plain `RequestInterface`, the client converts it first and copies the method, URI, protocol version, request target, headers, and body across.

## Building requests

`RequestBuilder` wraps a server request and the client's cookie jar behind setters that return the builder, so a request reads top to bottom:

```php
use Gadget\Http\Message\RequestBuilder;

$request = $builder
    ->setMethod('POST')
    ->setUri('https://example.com/api/things')
    ->setQueryParams(['page' => 2, 'q' => 'two words'])   // ?page=2&q=two%20words
    ->setHeader('Accept', 'application/json')
    ->setBody(RequestBuilder::JSON, ['name' => 'Ada'])
    ->getRequest();
```

`setBody()` takes a content type and a value. With `RequestBuilder::JSON` the value is JSON encoded. With `RequestBuilder::FORM` it must be an array and is URL encoded. For any other content type a string is used as is, scalars and `Stringable` objects are cast to string, and anything else throws a `RequestException`. An empty body leaves the request without `Content-Type` or `Content-Length` headers. Query strings are encoded per RFC 3986, so spaces become `%20` and not `+`.

You rarely construct a `RequestBuilder` yourself. `MessageHandler` builds one for you with the client's factory and cookie jar.

## Message handlers

`MessageHandler` is the piece you will subclass most. It describes one exchange: how to build the request and what to do with the response. `invoke()` runs the exchange through a client and returns whatever your `handleResponse()` returns. The class is generic over that return type so PHPStan knows what comes back.

```php
use Gadget\Http\Message\MessageHandler;
use Gadget\Http\Message\RequestBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** @extends MessageHandler<mixed[]> */
final class GetThing extends MessageHandler
{
    public function __construct(private int $id)
    {
    }

    protected function createRequest(RequestBuilder $requestBuilder): ServerRequestInterface
    {
        return $requestBuilder
            ->setMethod('GET')
            ->setUri("https://example.com/api/things/{$this->id}")
            ->setHeader('Accept', 'application/json')
            ->getRequest();
    }

    protected function handleResponse(
        ResponseInterface $response,
        ServerRequestInterface $request
    ): array {
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException("Unexpected status {$response->getStatusCode()}");
        }
        return $this->jsonToArray($response);
    }
}

$thing = (new GetThing(42))->invoke($client);
```

`jsonToArray()` decodes a JSON body into an array and throws `JsonException` on malformed input. `getClient()` returns the client the handler is running under, in case a step needs it.

### Errors

`invoke()` wraps whatever goes wrong so the caller can tell the stages apart. All three extend `Gadget\Http\Exception\HttpException`, which extends `Gadget\Exception\Exception` from gadget-core, and all three carry the original exception as `getPrevious()`.

| Stage | Exception | Message |
| --- | --- | --- |
| `createRequest()` threw | `RequestException` | `Error building request` |
| The client threw while sending | `ClientException` | `Error sending request: GET https://...` |
| `handleResponse()` threw | `ResponseException` | `Error handling response: GET https://... => 503` |

`ClientException` also implements PSR-18's `ClientExceptionInterface`.

Override `handleError(\Throwable $t)` to turn a failure into a value instead of letting it propagate. The default rethrows.

## API clients

`ApiClient` is a thin abstract base for grouping handlers behind named methods. It holds a `Client` and exposes `invoke()` to subclasses:

```php
use Gadget\Http\Client\ApiClient;

final class ThingsApi extends ApiClient
{
    /** @return mixed[] */
    public function getThing(int $id): array
    {
        return $this->invoke(new GetThing($id));
    }
}

$api = new ThingsApi($client);
$thing = $api->getThing(42);
```

## Symfony bundle

Add the bundle to `config/bundles.php`, along with the cache bundle that provides `CacheItemPool`:

```php
return [
    // ...
    Gadget\Cache\GadgetCacheBundle::class => ['all' => true],
    Gadget\Http\GadgetHttpBundle::class => ['all' => true],
];
```

The bundle registers every concrete class under `Gadget\Http\` as an autowired, autoconfigured service. The abstract `ApiClient` and `MessageHandler` are skipped; you register your own subclasses in your app.

What the bundle does not do is pick a PSR-18 client or PSR-17 factories for you. `Client` and `MessageFactory` ask for those by interface, so the container has to know which implementation to use. If your app does not already provide them (check with `bin/console debug:autowiring Psr\Http`), point the interfaces at Guzzle in your own `services.yaml`:

```yaml
services:
    GuzzleHttp\Client: ~
    Psr\Http\Client\ClientInterface: '@GuzzleHttp\Client'

    GuzzleHttp\Psr7\HttpFactory: ~
    Psr\Http\Message\RequestFactoryInterface: '@GuzzleHttp\Psr7\HttpFactory'
    Psr\Http\Message\ResponseFactoryInterface: '@GuzzleHttp\Psr7\HttpFactory'
    Psr\Http\Message\ServerRequestFactoryInterface: '@GuzzleHttp\Psr7\HttpFactory'
    Psr\Http\Message\StreamFactoryInterface: '@GuzzleHttp\Psr7\HttpFactory'
    Psr\Http\Message\UploadedFileFactoryInterface: '@GuzzleHttp\Psr7\HttpFactory'
    Psr\Http\Message\UriFactoryInterface: '@GuzzleHttp\Psr7\HttpFactory'
```

With that in place, a service that type-hints `Gadget\Http\Client\Client` gets a working client backed by `cache.app`.

## Testing

`composer test` runs the suite. The client tests go through a real Guzzle client whose transport is a `MockHandler` queue, so they check the exact requests that would have gone over the wire, and the cookie jar is persisted through a real `symfony/cache` `ArrayAdapter`. The extension test compiles a real `ContainerBuilder` with Guzzle registered under the PSR interfaces. [TESTPLAN.md](TESTPLAN.md) has the manual walkthrough.

## License

MIT. See [LICENSE](LICENSE).
