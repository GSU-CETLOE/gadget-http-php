<?php

declare(strict_types=1);

namespace Gadget\Http\Exception;

use Psr\Http\Client\ClientExceptionInterface;

class ClientException extends HttpException implements ClientExceptionInterface
{
}
