<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderSelection extends Model
{
    protected $fillable = ['capability', 'provider_id'];
}
