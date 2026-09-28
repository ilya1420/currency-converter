<?php

namespace App\Currency\Exceptions;

use Throwable;

final class ProviderTimeoutException extends ProviderException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
