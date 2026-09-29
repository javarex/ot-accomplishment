<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'province', 'office_name', 'office_address', 'left_logo_path', 'right_logo_path', 'report_title', 'certification_statement', 'footer_text'])]
class ReportTemplate extends Model
{
    public const DEFAULT_FOOTER = "Provincial Information and Communications Technology Office, 3rd Floor, Executive Building, Provincial Capitol,\nCabidianan, Nabunturan, Davao de Oro\n✉ PICTO@davaodeoro.gov.ph";

    protected $attributes = [
        'office_address' => '3rd Floor, Capitol, Cabidianan, Nabunturan, Davao de Oro Province',
        'certification_statement' => 'I hereby certify that the overtime services shall be considered as time worked calculating, actual hours for above specified period.',
        'footer_text' => self::DEFAULT_FOOTER,
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
