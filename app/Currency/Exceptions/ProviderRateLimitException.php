<?php

namespace App\Currency\Exceptions;

final class ProviderRateLimitException extends ProviderException
{
    public function __construct(string $message, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}
