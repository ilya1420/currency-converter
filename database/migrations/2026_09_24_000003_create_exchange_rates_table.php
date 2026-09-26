<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('from_currency', 8);
            $table->string('to_currency', 8);
            $table->string('rate');
            $table->timestamp('fetched_at');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'from_currency', 'to_currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
