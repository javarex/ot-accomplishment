<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'report_month', 'report_year', 'quantity_mode', 'hourly_rate', 'prepared_by_id', 'certified_by_id', 'approved_by_id', 'prepared_name', 'prepared_position', 'certified_name', 'certified_position', 'approved_name', 'approved_position', 'status', 'generated_at'])]
class AccomplishmentReport extends Model
{
    protected $attributes = ['quantity_mode' => 'time'];

    public const DRAFT = 'draft';

    public const FINALIZED = 'finalized';

    public const GENERATED = 'generated';

    protected function casts(): array
    {
        return ['generated_at' => 'datetime', 'hourly_rate' => 'decimal:2'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<Signatory, $this> */
    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(Signatory::class, 'prepared_by_id');
    }

    /** @return BelongsTo<Signatory, $this> */
    public function certifiedBy(): BelongsTo
    {
        return $this->belongsTo(Signatory::class, 'certified_by_id');
    }

    /** @return BelongsTo<Signatory, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Signatory::class, 'approved_by_id');
    }

    /** @return HasMany<AccomplishmentEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(AccomplishmentEntry::class)->orderBy('sort_order')->orderBy('accomplishment_date');
    }

    /** @return HasMany<DtrImport, $this> */
    public function dtrImports(): HasMany
    {
        return $this->hasMany(DtrImport::class);
    }
}
