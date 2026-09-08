<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class);
    }
}
