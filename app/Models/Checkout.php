<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Checkout extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array', 'amount_centavos' => 'integer', 'revision' => 'integer', 'abandoned_at' => 'datetime'];
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function cases(): HasMany
    {
        return $this->hasMany(ReconciliationCase::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CheckoutEvent::class);
    }
}
