<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoredExchangeRate extends Model
{
    protected $table = 'exchange_rates';

    protected $fillable = ['provider', 'from_currency', 'to_currency', 'rate', 'fetched_at', 'published_at'];

    protected function casts(): array
    {
        return ['fetched_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime'];
    }
}
