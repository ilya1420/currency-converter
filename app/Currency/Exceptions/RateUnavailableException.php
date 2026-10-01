<?php

namespace App\Currency\Exceptions;

use RuntimeException;
use Throwable;

final class RateUnavailableException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $providerId = null,
        public readonly string $failureCode = 'rate_unavailable',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
