<?php

declare(strict_types=1);

namespace Gadget\Http\Tests\Support;

use Gadget\Http\Client\ApiClient;
use Gadget\Http\Client\Client;
use Gadget\Http\Message\MessageHandler;

/**
 * Makes the protected ApiClient methods callable from a test.
 */
final class ExposedApiClient extends ApiClient
{
    /**
     * @return Client
     */
    public function exposeClient(): Client
    {
        return $this->getClient();
    }


    /**
     * @param Client $client
     * @return static
     */
    public function exposeSetClient(Client $client): static
    {
        return $this->setClient($client);
    }


    /**
     * @template TResponse
     * @param MessageHandler<TResponse> $handler
     * @return TResponse
     */
    public function exposeInvoke(MessageHandler $handler): mixed
    {
        return $this->invoke($handler);
    }
}
