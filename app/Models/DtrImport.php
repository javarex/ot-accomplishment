<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'accomplishment_report_id', 'employee_name', 'employee_id', 'month', 'year', 'original_filename', 'file_path', 'file_hash', 'import_status', 'parsed_data'])]
class DtrImport extends Model
{
    protected function casts(): array
    {
        return ['parsed_data' => 'array'];
    }

    /** @return BelongsTo<AccomplishmentReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(AccomplishmentReport::class, 'accomplishment_report_id');
    }

    /** @return HasMany<DtrEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(DtrEntry::class)->orderBy('work_date');
    }
}
