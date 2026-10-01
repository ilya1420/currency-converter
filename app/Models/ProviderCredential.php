<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderCredential extends Model
{
    protected $fillable = ['provider_id', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'encrypted:array'];
    }
}
