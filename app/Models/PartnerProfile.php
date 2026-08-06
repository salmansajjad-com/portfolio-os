<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerProfile extends Model
{
    protected $fillable = [
        'user_id',
        'payout_method',
        'payout_details',
        'notes',
        'is_active',
    ];

    /**
     * Payout details hold bank and wallet identifiers, so they get the same
     * encryption as the credential vault rather than sitting readable in a
     * database backup.
     */
    protected $hidden = [
        'payout_details',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'payout_details' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
