<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReconciliationCase extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['resolution' => 'array', 'version' => 'integer'];
    }

    public function events(): HasMany
    {
        return $this->hasMany(CheckoutEvent::class, 'case_id');
    }
}
