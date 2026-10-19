<?php

declare(strict_types=1);

namespace Gadget\Http\Tests\Exception;

use Gadget\Exception\Exception as GadgetException;
use Gadget\Http\Exception\ClientException;
use Gadget\Http\Exception\HttpException;
use Gadget\Http\Exception\RequestException;
use Gadget\Http\Exception\ResponseException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

final class HttpExceptionTest extends TestCase
{
    public function testEveryExceptionDescendsFromHttpExceptionAndTheGadgetBase(): void
    {
        foreach ([new ClientException(), new RequestException(), new ResponseException()] as $exception) {
            self::assertInstanceOf(HttpException::class, $exception);
            self::assertInstanceOf(GadgetException::class, $exception);
        }
    }


    public function testClientExceptionSatisfiesThePsr18Contract(): void
    {
        self::assertInstanceOf(ClientExceptionInterface::class, new ClientException());
    }


    public function testTheMessageCanBeASprintfTemplateWithArguments(): void
    {
        $previous = new \RuntimeException('root cause');

        $exception = new ResponseException(['%s %s => %d', ['GET', 'https://example.com/', 500]], $previous);

        self::assertSame('GET https://example.com/ => 500', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
    }
}
