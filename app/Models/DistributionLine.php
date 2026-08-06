<?php

namespace App\Models;

use App\Exceptions\LockedDistributionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributionLine extends Model
{
    protected $fillable = [
        'distribution_run_id',
        'project_id',
        'user_id',
        'revenue_paisa',
        'direct_expense_paisa',
        'shared_expense_paisa',
        'net_profit_paisa',
        'share_bps',
        'gross_share_paisa',
        'holdback_paisa',
        'credited_paisa',
    ];

    protected function casts(): array
    {
        return [
            'revenue_paisa' => 'integer',
            'direct_expense_paisa' => 'integer',
            'shared_expense_paisa' => 'integer',
            'net_profit_paisa' => 'integer',
            'share_bps' => 'integer',
            'gross_share_paisa' => 'integer',
            'holdback_paisa' => 'integer',
            'credited_paisa' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Lines are the arithmetic behind a payout, so they follow the run: once
        // it is approved or voided they can no longer be edited or removed.
        $guard = function (self $line): void {
            if ($line->run?->isLocked()) {
                throw new LockedDistributionException(
                    'Distribution run #'.$line->distribution_run_id.' is locked, so its lines cannot be changed.'
                );
            }
        };

        static::updating($guard);
        static::deleting($guard);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DistributionRun::class, 'distribution_run_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
