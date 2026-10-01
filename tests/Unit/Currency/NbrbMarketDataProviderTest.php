<?php

namespace Tests\Unit\Currency;

use App\Currency\Enums\Currency;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Providers\NbrbMarketDataProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class NbrbMarketDataProviderTest extends TestCase
{
    public function test_it_converts_chart_connection_failures_to_provider_exceptions(): void
    {
        Http::preventStrayRequests();
        Http::fake(static fn (): never => throw new ConnectionException('offline'));

        $provider = app(NbrbMarketDataProvider::class);

        $this->expectException(ProviderException::class);

        $provider->chart(Currency::fiat('USD'), 30);
    }
}
