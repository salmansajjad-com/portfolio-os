<?php

namespace App\Models;

use App\Enums\DistributionStatus;
use App\Exceptions\LockedDistributionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DistributionRun extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'period_month',
        'status',
        'holdback_bps',
        'total_revenue_paisa',
        'total_direct_expense_paisa',
        'total_shared_expense_paisa',
        'total_net_profit_paisa',
        'total_holdback_paisa',
        'total_credited_paisa',
        'ownership_snapshot',
        'notes',
        'created_by',
        'approved_by',
        'approved_at',
        'voided_by',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'status' => DistributionStatus::class,
            'holdback_bps' => 'integer',
            'total_revenue_paisa' => 'integer',
            'total_direct_expense_paisa' => 'integer',
            'total_shared_expense_paisa' => 'integer',
            'total_net_profit_paisa' => 'integer',
            'total_holdback_paisa' => 'integer',
            'total_credited_paisa' => 'integer',
            'ownership_snapshot' => 'array',
            'approved_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A locked run is the record of what was actually paid, so it must not
        // drift afterwards. The service guards the normal path, but any stray
        // update(), bulk edit or console tweak has to fail too. Voiding is the
        // one permitted transition, and it only writes its own bookkeeping.
        static::updating(function (self $run): void {
            $wasLocked = in_array($run->getRawOriginal('status'), [
                DistributionStatus::Approved->value,
                DistributionStatus::Voided->value,
            ], true);

            if (! $wasLocked) {
                return;
            }

            $permitted = ['status', 'voided_by', 'voided_at', 'void_reason', 'updated_at', 'deleted_at'];
            $blocked = array_diff(array_keys($run->getDirty()), $permitted);

            if ($blocked !== []) {
                throw new LockedDistributionException(
                    'Distribution run #'.$run->id.' is '.$run->getRawOriginal('status')
                    .' and cannot be changed ('.implode(', ', $blocked).'). Void it and create a new run instead.'
                );
            }
        });

        static::forceDeleting(function (self $run): void {
            throw new LockedDistributionException('Distribution runs are financial records and are never hard-deleted.');
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DistributionLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isEditable(): bool
    {
        return $this->status === DistributionStatus::Draft;
    }

    public function isLocked(): bool
    {
        return $this->status?->isLocked() ?? false;
    }
}
