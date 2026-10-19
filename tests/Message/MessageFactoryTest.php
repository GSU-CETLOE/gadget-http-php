<?php

declare(strict_types=1);

namespace Gadget\Http\Tests\Message;

use Gadget\Http\Message\MessageFactory;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Runs the factory against Guzzle's PSR-17 implementation.
 */
final class MessageFactoryTest extends TestCase
{
    private MessageFactory $factory;


    protected function setUp(): void
    {
        $guzzle = new HttpFactory();
        $this->factory = new MessageFactory($guzzle, $guzzle, $guzzle, $guzzle, $guzzle, $guzzle);
    }


    public function testToServerRequestReturnsAServerRequestUnchanged(): void
    {
        $request = new ServerRequest('GET', 'https://example.com/');

        self::assertSame($request, $this->factory->toServerRequest($request));
    }


    public function testToServerRequestCopiesEveryPartOfAPlainRequest(): void
    {
        $request = (new Request(
            'POST',
            'https://example.com/things?page=2',
            ['X-One' => ['1', '2'], 'Content-Type' => 'text/plain'],
            'hello',
            '1.0'
        ))->withRequestTarget('/custom-target');

        $serverRequest = $this->factory->toServerRequest($request);

        self::assertInstanceOf(ServerRequestInterface::class, $serverRequest);
        self::assertSame('POST', $serverRequest->getMethod());
        self::assertSame('https://example.com/things?page=2', (string) $serverRequest->getUri());
        self::assertSame('1.0', $serverRequest->getProtocolVersion());
        self::assertSame('/custom-target', $serverRequest->getRequestTarget());
        self::assertSame(['1', '2'], $serverRequest->getHeader('X-One'));
        self::assertSame('text/plain', $serverRequest->getHeaderLine('Content-Type'));
        self::assertSame('hello', (string) $serverRequest->getBody());
    }


    public function testCreateRequestDelegatesToTheRequestFactory(): void
    {
        $request = $this->factory->createRequest('DELETE', 'https://example.com/things/1');

        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('https://example.com/things/1', (string) $request->getUri());
    }


    public function testCreateResponseDelegatesToTheResponseFactory(): void
    {
        $response = $this->factory->createResponse(404, 'Nothing Here');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Nothing Here', $response->getReasonPhrase());
        self::assertSame(200, $this->factory->createResponse()->getStatusCode());
    }


    public function testCreateServerRequestDelegatesAndKeepsServerParams(): void
    {
        $request = $this->factory->createServerRequest('GET', 'https://example.com/', ['REMOTE_ADDR' => '127.0.0.1']);

        self::assertSame('GET', $request->getMethod());
        self::assertSame(['REMOTE_ADDR' => '127.0.0.1'], $request->getServerParams());
    }


    public function testCreateStreamDelegatesToTheStreamFactory(): void
    {
        self::assertSame('payload', (string) $this->factory->createStream('payload'));
        self::assertSame('', (string) $this->factory->createStream());
    }


    public function testCreateStreamFromFileReadsTheFile(): void
    {
        $stream = $this->factory->createStreamFromFile(__FILE__);

        self::assertStringStartsWith('<?php', $stream->read(5));
    }


    public function testCreateStreamFromResourceWrapsTheResource(): void
    {
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);
        fwrite($resource, 'from resource');
        rewind($resource);

        self::assertSame('from resource', (string) $this->factory->createStreamFromResource($resource));
    }


    public function testCreateUploadedFileDelegatesToTheUploadedFileFactory(): void
    {
        $stream = $this->factory->createStream('file body');

        $uploaded = $this->factory->createUploadedFile($stream, 9, \UPLOAD_ERR_OK, 'notes.txt', 'text/plain');

        self::assertSame(9, $uploaded->getSize());
        self::assertSame(\UPLOAD_ERR_OK, $uploaded->getError());
        self::assertSame('notes.txt', $uploaded->getClientFilename());
        self::assertSame('text/plain', $uploaded->getClientMediaType());
        self::assertSame('file body', (string) $uploaded->getStream());
    }


    public function testCreateUriDelegatesToTheUriFactory(): void
    {
        $uri = $this->factory->createUri('https://example.com/things?page=2');

        self::assertSame('https', $uri->getScheme());
        self::assertSame('example.com', $uri->getHost());
        self::assertSame('/things', $uri->getPath());
        self::assertSame('page=2', $uri->getQuery());
        self::assertSame('', (string) $this->factory->createUri());
    }
}
