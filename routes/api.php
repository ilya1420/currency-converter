<?php

use App\Http\Controllers\BatchConversionController;
use App\Http\Controllers\ConversionController;
use App\Http\Controllers\CurrencyCatalogController;
use App\Http\Controllers\DailyChangeController;
use App\Http\Controllers\MarketChartController;
use App\Http\Controllers\ProviderSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:60,1')->group(function (): void {
    Route::post('/conversion', ConversionController::class)->name('conversion');
    Route::post('/conversions', BatchConversionController::class)->name('conversions');
    Route::get('/currencies', CurrencyCatalogController::class)->name('currencies');
    Route::get('/daily-changes', DailyChangeController::class)->name('daily-changes');
    Route::get('/market/{currency}', MarketChartController::class)->name('market.chart');
    Route::get('/provider-settings', [ProviderSettingsController::class, 'index'])->name('provider-settings.index');
    Route::patch('/provider-settings/{capability}', [ProviderSettingsController::class, 'update'])->name('provider-settings.update');
    Route::delete('/provider-settings/{capability}', [ProviderSettingsController::class, 'destroy'])->name('provider-settings.destroy');
});
