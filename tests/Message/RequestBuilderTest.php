<?php

declare(strict_types=1);

namespace Gadget\Http\Tests\Message;

use Gadget\Http\Cookie\CookieJar;
use Gadget\Http\Exception\RequestException;
use Gadget\Http\Message\MessageFactory;
use Gadget\Http\Message\RequestBuilder;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\TestCase;

final class RequestBuilderTest extends TestCase
{
    private MessageFactory $messageFactory;

    private CookieJar $cookieJar;


    protected function setUp(): void
    {
        $guzzle = new HttpFactory();
        $this->messageFactory = new MessageFactory($guzzle, $guzzle, $guzzle, $guzzle, $guzzle, $guzzle);
        $this->cookieJar = new CookieJar();
    }


    private function builder(string $method = 'GET', string $uri = ''): RequestBuilder
    {
        return new RequestBuilder($this->messageFactory, $this->cookieJar, $method, $uri);
    }


    public function testCreateQueryEncodesPerRfc3986(): void
    {
        self::assertSame(
            'q=two%20words&page=2&flag=1',
            RequestBuilder::createQuery(['q' => 'two words', 'page' => 2, 'flag' => true])
        );
    }


    public function testCreateQuerySkipsNullValues(): void
    {
        self::assertSame('a=1', RequestBuilder::createQuery(['a' => 1, 'b' => null]));
    }


    public function testDefaultsToAnEmptyGetRequest(): void
    {
        $builder = $this->builder();

        self::assertSame('GET', $builder->getMethod());
        self::assertSame('', (string) $builder->getUri());
        self::assertSame([], $builder->getQueryParams());
        self::assertSame('', $builder->getBody());
    }


    public function testConstructorAcceptsAMethodAndUri(): void
    {
        $builder = $this->builder('POST', 'https://example.com/things');

        self::assertSame('POST', $builder->getMethod());
        self::assertSame('https://example.com/things', (string) $builder->getUri());
        self::assertSame('https', $builder->getScheme());
        self::assertSame('example.com', $builder->getHost());
        self::assertSame('/things', $builder->getPath());
    }


    public function testSettersRebuildTheRequestAndReturnTheBuilder(): void
    {
        $builder = $this->builder();

        $result = $builder
            ->setMethod('PUT')
            ->setScheme('https')
            ->setHost('example.com')
            ->setPath('/things/7');

        self::assertSame($builder, $result);
        self::assertSame('PUT', $builder->getRequest()->getMethod());
        self::assertSame('https://example.com/things/7', (string) $builder->getRequest()->getUri());
    }


    public function testSetUriAcceptsAStringOrAUriObject(): void
    {
        $builder = $this->builder();

        self::assertSame('https://a.test/x', (string) $builder->setUri('https://a.test/x')->getUri());
        self::assertSame('https://b.test/y', (string) $builder->setUri(new Uri('https://b.test/y'))->getUri());
    }


    public function testSetQueryParamsWritesTheQueryString(): void
    {
        $builder = $this->builder('GET', 'https://example.com/search');

        $builder->setQueryParams(['q' => 'a b', 'page' => 3]);

        self::assertSame(['q' => 'a b', 'page' => 3], $builder->getQueryParams());
        self::assertSame('q=a%20b&page=3', $builder->getUri()->getQuery());
        self::assertSame('https://example.com/search?q=a%20b&page=3', (string) $builder->getRequest()->getUri());
    }


    public function testSetBodyEncodesJson(): void
    {
        $builder = $this->builder('POST', 'https://example.com/things');

        $builder->setBody(RequestBuilder::JSON, ['name' => 'Ada', 'tags' => ['a', 'b']]);

        self::assertSame('{"name":"Ada","tags":["a","b"]}', $builder->getBody());
        self::assertSame('application/json', $builder->getSingleHeader('Content-Type'));
        self::assertSame('31', $builder->getSingleHeader('Content-Length'));
    }


    public function testSetBodyEncodesAFormArray(): void
    {
        $builder = $this->builder('POST', 'https://example.com/login');

        $builder->setBody(RequestBuilder::FORM, ['user' => 'ada', 'note' => 'two words']);

        self::assertSame('user=ada&note=two%20words', $builder->getBody());
        self::assertSame('application/x-www-form-urlencoded', $builder->getSingleHeader('Content-Type'));
    }


    public function testSetBodyRejectsANonArrayFormBody(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Body is not an array: application/x-www-form-urlencoded');

        $this->builder()->setBody(RequestBuilder::FORM, 42);
    }


    public function testSetBodyUsesAStringAsIsForAnyContentType(): void
    {
        $builder = $this->builder('POST', 'https://example.com/upload');

        $builder->setBody('text/plain', 'raw text');

        self::assertSame('raw text', $builder->getBody());
        self::assertSame('text/plain', $builder->getSingleHeader('Content-Type'));
        self::assertSame('8', $builder->getSingleHeader('Content-Length'));
    }


    public function testSetBodyCastsScalarsAndStringablesForOtherContentTypes(): void
    {
        self::assertSame('42', $this->builder()->setBody('text/plain', 42)->getBody());
        self::assertSame('1.5', $this->builder()->setBody('text/plain', 1.5)->getBody());
        self::assertSame(
            'https://x.test/',
            $this->builder()->setBody('text/plain', new Uri('https://x.test/'))->getBody()
        );
    }


    public function testSetBodyRejectsAnUnserializableBodyForOtherContentTypes(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Unable to serialize body: text/plain');

        $this->builder()->setBody('text/plain', ['not' => 'a string']);
    }


    public function testAnEmptyBodyLeavesTheRequestAlone(): void
    {
        $builder = $this->builder('POST', 'https://example.com/things');

        $builder->setBody('text/plain', '');
        $builder->setBody('text/plain', null);

        self::assertSame('', $builder->getBody());
        self::assertNull($builder->getSingleHeader('Content-Type'));
        self::assertNull($builder->getSingleHeader('Content-Length'));
    }


    public function testGetBodyRewindsSoItCanBeReadMoreThanOnce(): void
    {
        $builder = $this->builder('POST', 'https://example.com/things')->setBody('text/plain', 'again');

        self::assertSame('again', $builder->getBody());
        self::assertSame('', $builder->getRequest()->getBody()->getContents(), 'the stream is left at its end');
        self::assertSame('again', $builder->getBody(), 'getBody() rewinds before reading');
    }


    public function testHeadersCanBeSetAndRead(): void
    {
        $builder = $this->builder();

        $builder->setHeader('Accept', 'application/json')->setHeader('X-Many', ['one', 'two']);

        self::assertSame(['application/json'], $builder->getHeader('Accept'));
        self::assertSame(['one', 'two'], $builder->getHeader('X-Many'));
        self::assertSame('one', $builder->getSingleHeader('X-Many'));
        self::assertSame([], $builder->getHeader('Missing'));
        self::assertNull($builder->getSingleHeader('Missing'));
    }


    public function testGetCookieJarReturnsTheJarItWasGiven(): void
    {
        self::assertSame($this->cookieJar, $this->builder()->getCookieJar());
    }
}
