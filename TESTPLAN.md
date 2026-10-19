# Test Plan

Walk through this list before tagging a release or merging a change that touches `composer.json`, the analyzers, or the test suite. Run every command from the repository root.

## Setup

- [ ] `composer install` finishes without errors and without asking to change the lock file.
- [ ] `composer validate` reports the manifest is valid.
- [ ] `working/` exists after the first run and is not shown in `git status`.

## Automated checks

- [ ] `composer phpcs` reports no errors and no warnings for `src/`, `bundle/`, and `tests/`.
- [ ] `composer phpstan` reports `No errors` at level 10 for `src/`, `bundle/`, and `tests/`.
- [ ] `composer test` passes with no warnings, deprecations, or notices in the output.
- [ ] `composer check` runs all three in order and exits 0.

## Functional coverage

Confirm each area has passing tests under `tests/` that cover the listed behaviors.

- [ ] `Cookie::getCookiePath()`: `''`, a relative path, `/`, and `/foo` give `/`; `/foo/bar` gives `/foo`; `/foo/bar/` gives `/foo/bar`.
- [ ] `Cookie::fromString()`: parses name, value, `Domain`, `Path`, `Max-Age`, `Expires`, `Secure`, `HttpOnly`, and keeps unknown attributes such as `SameSite`; attribute names are read case-insensitively; an `=` inside the value is kept; a bare `name=value` gets path `/`, no domain, no expiry, and both flags off.
- [ ] `Cookie` expiry: `Max-Age` alone sets `Expires` relative to now; `Max-Age` does not override an explicit `Expires`; `setExpires()` accepts an int, a numeric string, or a date string and turns anything else into `null`; `isExpired()` is `false` with no expiry.
- [ ] `Cookie` matching: `Secure` requires `https`; expired cookies never match; a domain covers itself and its subdomains, case-insensitively, with or without a leading dot; IP addresses must match exactly; no domain matches any host; paths match on segment boundaries; `/` matches everything.
- [ ] `Cookie::__toString()`: renders the `Set-Cookie` form with `Expires` as an HTTP date, flags as bare words, and `null` or `false` attributes left out.
- [ ] `CookieJar`: accepts strings, arrays, and `Cookie` objects; gives cookies without an expiry the default `Max-Age` (600, configurable, `null` to skip); keys cookies by domain, path, and name; replaces on a later expiry or a changed value and otherwise returns `false`; iterates as key to cookie; `getMatches()` filters by scheme, host, path, and expiry; `clearExpired()` drops expired cookies and cookies with no expiry.
- [ ] `MessageFactory`: returns a `ServerRequestInterface` unchanged; converts a plain request and copies method, URI, protocol version, request target, headers, and body; delegates all six PSR-17 factory methods to Guzzle's `HttpFactory`.
- [ ] `RequestBuilder`: RFC 3986 query encoding with `null` values skipped; defaults to an empty GET; setters for method, URI, scheme, host, path, query, headers return the builder; `JSON` bodies are encoded with `Content-Type` and `Content-Length`; `FORM` arrays are URL encoded and non-arrays throw `RequestException`; other content types accept strings, scalars, and `Stringable` and reject anything else; an empty body sets no headers; `getBody()` rewinds; `getSingleHeader()` returns the first value or `null`.
- [ ] `MessageHandler`: `invoke()` builds, sends, and handles; the builder shares the client's cookie jar; a build failure becomes `RequestException`, a transport failure becomes `ClientException` (which implements the PSR-18 interface), a handling failure becomes `ResponseException` with the status code in the message, each with the cause as `getPrevious()`; `handleError()` can return a fallback; `getClient()` throws `HttpException` before `invoke()` and returns the client after; `jsonToArray()` decodes and rejects malformed JSON; middleware runs when invoked through the client.
- [ ] `MiddlewareHandler`: forwards straight to the client with no middleware; runs middleware from the end of the list to the start on the way in and in reverse on the way out; a middleware can answer without reaching the client; the handler can be reused.
- [ ] `Client`: returns the wrapped client's response; accepts plain and server requests; stores `Set-Cookie` under the request host; a relative `Path` becomes the request directory and a missing `Path` becomes `/`; cookies for another domain are dropped and parent-domain cookies are kept; stored cookies go out as `name=value` pairs only; only matching cookies are sent; middleware wraps every request and can be replaced; the cache is namespaced by the `Client` class; a fresh client stores an empty jar; a jar already in the cache is loaded; the jar is written back on destruct with expired cookies removed.
- [ ] `ApiClient`: `invoke()` runs a handler through the client; the client can be read and replaced.
- [ ] Exceptions: `ClientException`, `RequestException`, and `ResponseException` extend `HttpException` and `Gadget\Exception\Exception`; `ClientException` implements `ClientExceptionInterface`; the sprintf-style message form works and `getPrevious()` is kept.
- [ ] `GadgetHttpBundle`: extends `Bundle` and exposes `GadgetHttpExtension` under the alias `gadget_http`.
- [ ] `GadgetHttpExtension`: registers `Client`, `MiddlewareHandler`, `MessageFactory`, `RequestBuilder`, and `CookieJar` as autowired, autoconfigured services; skips the abstract `ApiClient` and `MessageHandler`; a compiled container with Guzzle under the PSR interfaces and a PSR-6 pool builds a working `Client`.

## Consuming the package

- [ ] In a scratch project, `composer require gsu-cetloe/gadget-http-php` installs `gsu-cetloe/gadget-core-php`, `gsu-cetloe/gadget-cache-php`, `gsu-cetloe/symfony-bundle`, Guzzle, and the PSR interface packages.
- [ ] In a scratch Symfony app with `GadgetCacheBundle` and `GadgetHttpBundle` enabled and the PSR interfaces aliased to Guzzle as the README shows, a service that type-hints `Gadget\Http\Client\Client` autowires.
- [ ] In that app, a request to an endpoint that sets a cookie followed by a second request to the same host sends a `Cookie` header containing only `name=value` pairs.
- [ ] In that app, with `cache.app` on a persistent adapter, the cookie is still sent after restarting the process.

## Known gaps

- The transport in the suite is Guzzle's `MockHandler`. The requests Guzzle would send are checked exactly, but nothing goes over a socket. The manual Symfony steps above cover the real wire.
- A `Set-Cookie` with no `Path` attribute is stored under `/` rather than the request directory, because `Cookie::fromString()` fills in `/` before the client can tell the attribute was missing. The suite pins the current behavior; changing it means teaching `Cookie` to keep a missing path as `null`.
- `Cookie::fromString()` keeps lowercase attribute names (`domain=`, `path=`) as extra values alongside the normalized ones, so `__toString()` renders them twice. Parsing is unaffected and the suite does not assert on that rendering.
- The FrameworkBundle wiring (`cache.app`, any PSR-18 or PSR-17 aliases it may already provide) is not exercised here. The extension test registers its own implementations. The manual Symfony steps above cover the real wiring.
- Tests run on whatever PHP is installed locally. Composer resolves dependencies as if PHP were 8.1.0, but the behavior checked here is that of the local interpreter.
