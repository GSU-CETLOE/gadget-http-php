<?php

declare(strict_types=1);

namespace Gadget\Http\Client;

use Gadget\Http\Message\MessageHandler;

abstract class ApiClient
{
    /**
     * @param Client $client
     */
    public function __construct(private Client $client)
    {
    }


    /**
     * @return Client
     */
    protected function getClient(): Client
    {
        return $this->client;
    }


    /**
     * @param Client $client
     * @return static
     */
    protected function setClient(Client $client): static
    {
        $this->client = $client;
        return $this;
    }


    /**
     * @template TResponse
     * @param MessageHandler<TResponse> $handler
     * @return TResponse
     */
    protected function invoke(MessageHandler $handler): mixed
    {
        return $handler->invoke($this->getClient());
    }
}
