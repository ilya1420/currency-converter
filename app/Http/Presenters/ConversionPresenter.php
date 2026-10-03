<?php

namespace App\Http\Presenters;

use App\Currency\DTO\ConversionResult;
use App\Currency\Services\AmountFormatter;
use App\Currency\Services\DecimalCalculator;

final readonly class ConversionPresenter
{
    public function __construct(
        private DecimalCalculator $calculator,
        private AmountFormatter $formatter,
    ) {}

    /** @return array{sourceAmount: string, targetAmount: string, targetDisplay: string, factor: string, factorDisplay: string, sources: list<string>, isStale: bool, updatedAt: string} */
    public function detail(ConversionResult $result): array
    {
        $factor = $result->sourceAmount === '0'
            ? '0'
            : $this->calculator->divide($result->targetAmount, $result->sourceAmount);

        return [
            'sourceAmount' => $result->sourceAmount,
            'targetAmount' => $result->targetAmount,
            'targetDisplay' => $this->formatter->format($result->targetAmount, $result->to),
            'factor' => $factor,
            'factorDisplay' => $this->formatter->format($factor, $result->to),
            ...$this->rateMetadata($result),
        ];
    }

    /** @return array{factor: string, sources: list<string>, isStale: bool, updatedAt: string} */
    public function factor(ConversionResult $result): array
    {
        return ['factor' => $result->targetAmount, ...$this->rateMetadata($result)];
    }

    /** @return array{sources: list<string>, isStale: bool, updatedAt: string} */
    private function rateMetadata(ConversionResult $result): array
    {
        return [
            'sources' => array_values(array_unique(array_map(static fn ($rate): string => $rate->source->value, $result->ratesUsed))),
            'isStale' => $result->isStale,
            'isFallback' => (bool) array_filter($result->ratesUsed, static fn ($rate): bool => $rate->fallbackReason !== null),
            'fallbackReasons' => array_values(array_unique(array_filter(array_map(static fn ($rate): ?string => $rate->fallbackReason, $result->ratesUsed)))),
            'rateDates' => array_values(array_unique(array_filter(array_map(static fn ($rate): ?string => $rate->publishedAt?->format('Y-m-d'), $result->ratesUsed)))),
            'updatedAt' => $result->rateUpdatedAt->format(DATE_ATOM),
        ];
    }
}
