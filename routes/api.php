<?php

use App\Http\Controllers\BatchConversionController;
use App\Http\Controllers\ConversionController;
use App\Http\Controllers\CurrencyCatalogController;
use App\Http\Controllers\DailyChangeController;
use App\Http\Controllers\MarketChartController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:60,1')->group(function (): void {
    Route::post('/conversion', ConversionController::class)->name('conversion');
    Route::post('/conversions', BatchConversionController::class)->name('conversions');
    Route::get('/currencies', CurrencyCatalogController::class)->name('currencies');
    Route::get('/daily-changes', DailyChangeController::class)->name('daily-changes');
    Route::get('/market/{currency}', MarketChartController::class)->name('market.chart');
});
