<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckoutEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'occurred_at' => 'datetime'];
    }
}
