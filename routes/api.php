<?php

use App\Http\Controllers\ConversionController;
use App\Http\Controllers\MarketChartController;
use Illuminate\Support\Facades\Route;

Route::post('/conversion', ConversionController::class)->name('conversion');
Route::get('/market/{currency}', MarketChartController::class)->name('market.chart');
