<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['dtr_import_id', 'work_date', 'am_in', 'am_out', 'pm_in', 'pm_out', 'overtime_minutes', 'remarks', 'selected_for_import'])]
class DtrEntry extends Model
{
    protected function casts(): array
    {
        return ['work_date' => 'date:Y-m-d', 'selected_for_import' => 'boolean'];
    }

    /** @return BelongsTo<DtrImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(DtrImport::class, 'dtr_import_id');
    }
}
