<?php

namespace Tests\Unit\Currency;

use App\Currency\Enums\Currency;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\ProviderResponseException;
use App\Currency\Providers\NbrbMarketDataProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('malformedHistory')]
    public function test_it_rejects_malformed_history_as_a_provider_response(mixed $record): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.nbrb.by/exrates/rates?periodicity=0' => Http::response([[
                'Cur_Abbreviation' => 'USD', 'Cur_ID' => 431, 'Cur_Scale' => 1,
                'Cur_OfficialRate' => 3.12, 'Date' => '2026-10-03T00:00:00',
            ]]),
            'https://api.nbrb.by/exrates/rates/dynamics/431*' => Http::response([$record]),
        ]);
        $this->expectException(ProviderResponseException::class);

        try {
            app(NbrbMarketDataProvider::class)->dailyChanges([Currency::fiat('USD')]);
        } finally {
            Http::assertSentCount(2);
        }
    }

    /** @return array<string, array{mixed}> */
    public static function malformedHistory(): array
    {
        return [
            'scalar record' => ['invalid'], 'missing fields' => [[]],
            'non-scalar rate' => [['Date' => '2026-10-02', 'Cur_OfficialRate' => [3.1]]],
            'relative date' => [['Date' => 'tomorrow', 'Cur_OfficialRate' => 3.1]],
            'impossible date' => [['Date' => '2026-02-30', 'Cur_OfficialRate' => 3.1]],
            'null byte date' => [['Date' => "2026-10-02\0", 'Cur_OfficialRate' => 3.1]],
        ];
    }

    public function test_it_calculates_daily_change_from_history_and_the_latest_official_observation(): void
    {
        $this->travelTo('2026-10-03 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://api.nbrb.by/exrates/rates?periodicity=0' => Http::response([[
                'Cur_Abbreviation' => 'USD', 'Cur_ID' => 431, 'Cur_Scale' => 100,
                'Cur_OfficialRate' => 312, 'Date' => '2026-10-03T00:00:00',
            ]]),
            'https://api.nbrb.by/exrates/rates/dynamics/431*' => Http::response([
                ['Date' => '2026-10-02T00:00:00', 'Cur_OfficialRate' => 300],
            ]),
        ]);

        $changes = app(NbrbMarketDataProvider::class)->dailyChanges([Currency::fiat('USD')]);

        $this->assertSame(4.0, $changes['USD']);
        Http::assertSentCount(2);
    }
}
