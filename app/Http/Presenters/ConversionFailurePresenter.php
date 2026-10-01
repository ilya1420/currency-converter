<?php

namespace App\Http\Presenters;

use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\ProviderRateLimitException;
use App\Currency\Exceptions\ProviderTimeoutException;
use App\Currency\Exceptions\RateUnavailableException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use Throwable;

final class ConversionFailurePresenter
{
    /** @return array{code: string, message: string, provider: ?string, retryAfter: ?int, status: int} */
    public function present(Throwable $exception): array
    {
        $cause = $exception instanceof RateUnavailableException ? $exception->getPrevious() : $exception;
        $providerId = $exception instanceof RateUnavailableException ? $exception->providerId : null;
        $provider = match ($providerId) {
            'nbrb' => 'НБРБ',
            'kraken' => 'Kraken',
            'coingecko' => 'CoinGecko',
            default => null,
        };

        if ($cause instanceof ProviderRateLimitException) {
            return ['code' => 'provider_rate_limited', 'message' => ($provider ?? 'Провайдер').' временно ограничил частоту запросов. Попробуйте позже.', 'provider' => $providerId, 'retryAfter' => $cause->retryAfter, 'status' => 429];
        }

        if ($cause instanceof ProviderTimeoutException) {
            return ['code' => 'provider_timeout', 'message' => ($provider ?? 'Провайдер').' не ответил вовремя. Попробуйте позже.', 'provider' => $providerId, 'retryAfter' => null, 'status' => 503];
        }

        if ($exception instanceof UnsupportedCurrencyPairException || ($exception instanceof RateUnavailableException && $exception->failureCode === 'unsupported_pair')) {
            return ['code' => 'unsupported_pair', 'message' => 'Этот провайдер не поддерживает выбранную валютную пару.', 'provider' => $providerId, 'retryAfter' => null, 'status' => 422];
        }

        $code = $cause instanceof ProviderException ? 'provider_unavailable' : 'rate_unavailable';
        $message = $code === 'provider_unavailable'
            ? ($provider ?? 'Провайдер').' временно недоступен. Попробуйте позже.'
            : 'Не удалось получить курс для выбранной пары.';

        return ['code' => $code, 'message' => $message, 'provider' => $providerId, 'retryAfter' => null, 'status' => 503];
    }
}
