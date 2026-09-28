<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConvertCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'regex:/^\d+(?:[.,]\d+)?$/'],
            'from' => ['required', 'regex:/^[A-Z0-9]{2,10}$/'],
            'to' => ['required', 'regex:/^[A-Z0-9]{2,10}$/'],
            'fromType' => ['nullable', 'in:fiat,crypto'],
            'toType' => ['nullable', 'in:fiat,crypto'],
            'refresh' => ['sometimes', 'boolean'],
        ];
    }
}
