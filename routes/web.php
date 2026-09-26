<?php

use App\Http\Controllers\ConversionController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'converter');
Route::post('/conversion', ConversionController::class)->name('conversion');
