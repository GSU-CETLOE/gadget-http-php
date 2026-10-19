<?php

declare(strict_types=1);

namespace Gadget\Http\Message;

use Gadget\Http\Client\Client;
use Gadget\Http\Exception\ClientException;
use Gadget\Http\Exception\HttpException;
use Gadget\Http\Exception\RequestException;
use Gadget\Http\Exception\ResponseException;
use Gadget\Io\Cast;
use Gadget\Io\JSON;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** @template TResponse */
abstract class MessageHandler
{
    private Client|null $client = null;


    /**
     * @param Client $client
     * @return TResponse
     */
    public function invoke(Client $client): mixed
    {
        try {
            $this->client = $client;

            try {
                $requestBuilder = $this->createRequestBuilder($client);
                $request = $this->createRequest($requestBuilder);
            } catch (\Throwable $t) {
                throw new RequestException("Error building request", $t);
            }

            try {
                $response = $this->sendRequest($client, $request);
            } catch (\Throwable $t) {
                throw new ClientException([
                    "Error sending request: %s %s",
                    [
                        $request->getMethod(),
                        $request->getUri()
                    ]
                ], $t);
            }

            try {
                return $this->handleResponse($response, $request);
            } catch (\Throwable $t) {
                throw new ResponseException([
                    "Error handling response: %s %s => %s",
                    [
                        $request->getMethod(),
                        $request->getUri(),
                        $response->getStatusCode()
                    ]
                ], $t);
            }
        } catch (\Throwable $t) {
            return $this->handleError($t);
        }
    }


    /**
     * @return Client
     */
    protected function getClient(): Client
    {
        return $this->client ?? throw new HttpException('Client not set');
    }


    /**
     * @param Client $client
     * @return RequestBuilder
     */
    protected function createRequestBuilder(Client $client): RequestBuilder
    {
        return new RequestBuilder(
            $client->getMessageFactory(),
            $client->getCookieJar()
        );
    }


    /**
     * @param RequestBuilder $requestBuilder
     * @return ServerRequestInterface
     */
    abstract protected function createRequest(RequestBuilder $requestBuilder): ServerRequestInterface;



    /**
     * @param Client $client
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    protected function sendRequest(
        Client $client,
        ServerRequestInterface $request
    ): ResponseInterface {
        return $client->sendRequest($request);
    }


    /**
     * @param ResponseInterface $response
     * @param ServerRequestInterface $request
     * @return TResponse
     */
    abstract protected function handleResponse(
        ResponseInterface $response,
        ServerRequestInterface $request
    ): mixed;


    /** @return mixed[] */
    protected function jsonToArray(ResponseInterface $response): array
    {
        return Cast::toArray(JSON::decode($response->getBody()->getContents()));
    }


    /**
     * @param \Throwable $t
     * @return TResponse
     */
    protected function handleError(\Throwable $t): mixed
    {
        throw $t;
    }
}
