<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['accomplishment_report_id', 'dtr_entry_id', 'accomplishment_date', 'quantity', 'task_accomplished', 'original_task_accomplished', 'ai_suggested_task_accomplished', 'ai_enhanced', 'sort_order'])]
class AccomplishmentEntry extends Model
{
    protected function casts(): array
    {
        return ['accomplishment_date' => 'date:Y-m-d', 'ai_enhanced' => 'boolean'];
    }

    /** @return BelongsTo<AccomplishmentReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(AccomplishmentReport::class, 'accomplishment_report_id');
    }

    /** @return BelongsTo<DtrEntry, $this> */
    public function dtrEntry(): BelongsTo
    {
        return $this->belongsTo(DtrEntry::class);
    }
}
