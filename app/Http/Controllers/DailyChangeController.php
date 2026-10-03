<?php

namespace App\Http\Controllers;

use App\Currency\Services\DailyChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DailyChangeController
{
    public function __invoke(Request $request, DailyChangeService $changes): JsonResponse
    {
        $data = $request->validate([
            'currencies' => ['required', 'array', 'max:20'],
            'currencies.*' => ['regex:/^[A-Z0-9]{2,10}$/'],
        ]);

        return response()->json($changes->snapshot($data['currencies']));
    }
}
