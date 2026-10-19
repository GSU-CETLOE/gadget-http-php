<?php

declare(strict_types=1);

namespace Gadget\Http\Tests\Support;

use Gadget\Http\Client\Client;
use Gadget\Http\Message\MessageHandler;
use Gadget\Http\Message\RequestBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A MessageHandler whose abstract steps are supplied as closures, so a test can
 * describe one request/response exchange without declaring a class for it.
 *
 * @template TResponse
 * @extends MessageHandler<TResponse>
 */
final class CallbackMessageHandler extends MessageHandler
{
    /**
     * @param \Closure(RequestBuilder):ServerRequestInterface $buildRequest
     * @param \Closure(ResponseInterface,ServerRequestInterface):TResponse $onResponse
     * @param (\Closure(\Throwable):TResponse)|null $onError
     */
    public function __construct(
        private \Closure $buildRequest,
        private \Closure $onResponse,
        private \Closure|null $onError = null
    ) {
    }


    /**
     * Exposes the protected client accessor for tests.
     *
     * @return Client
     */
    public function exposeClient(): Client
    {
        return $this->getClient();
    }


    /**
     * Exposes the protected JSON helper for tests.
     *
     * @param ResponseInterface $response
     * @return mixed[]
     */
    public function exposeJsonToArray(ResponseInterface $response): array
    {
        return $this->jsonToArray($response);
    }


    /**
     * @param RequestBuilder $requestBuilder
     * @return ServerRequestInterface
     */
    protected function createRequest(RequestBuilder $requestBuilder): ServerRequestInterface
    {
        return ($this->buildRequest)($requestBuilder);
    }


    /**
     * @param ResponseInterface $response
     * @param ServerRequestInterface $request
     * @return TResponse
     */
    protected function handleResponse(
        ResponseInterface $response,
        ServerRequestInterface $request
    ): mixed {
        return ($this->onResponse)($response, $request);
    }


    /**
     * @param \Throwable $t
     * @return TResponse
     */
    protected function handleError(\Throwable $t): mixed
    {
        return $this->onError !== null
            ? ($this->onError)($t)
            : parent::handleError($t);
    }
}
