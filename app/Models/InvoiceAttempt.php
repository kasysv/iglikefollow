<?php

namespace App\Models;

use App\Enums\InvoiceAttemptStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call to a provider to issue an invoice.
 *
 * ⛔ The raw request and response are not stored. They carry the buyer's
 * details and enough material to replay the call; the fingerprint records
 * *that* a particular request was sent without keeping its contents.
 */
class InvoiceAttempt extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'status' => InvoiceAttemptStatus::class,
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * A one-way fingerprint of what was sent.
     *
     * ⛔ A hash, not an encryption: nothing here should ever be turned back
     * into a request. It answers "was this the same call as last time".
     */
    public static function fingerprint(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
}
