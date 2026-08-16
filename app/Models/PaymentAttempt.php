<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PaymentAttempt extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'status' => PaymentStatus::class,
        'completed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public static function newReference(): string
    {
        return 'PAY-'.strtoupper(Str::random(12));
    }

    public function isSuccessful(): bool
    {
        return $this->status === PaymentStatus::Succeeded;
    }
}
