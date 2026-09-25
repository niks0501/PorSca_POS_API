<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasFactory;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    public const PAID_UNFULFILLED = 'paid_unfulfilled';

    protected $guarded = [];

    public static function terminalStatuses(): array
    {
        return [self::PAID, self::PAID_UNFULFILLED, self::FAILED, self::CANCELLED, self::EXPIRED];
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'provider_metadata' => 'array',
            'paid_at' => 'datetime',
            'reservation_expires_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PaymentItem::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
