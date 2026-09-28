<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class BatchConvertCurrenciesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'regex:/^[A-Z0-9]{2,10}$/'],
            'fromType' => ['nullable', 'in:fiat,crypto'],
            'targets' => ['required', 'array', 'min:1', 'max:20'],
            'targets.*' => ['regex:/^[A-Z0-9]{2,10}$/'],
            'refresh' => ['sometimes', 'boolean'],
        ];
    }
}
