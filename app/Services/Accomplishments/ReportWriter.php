<?php

namespace App\Services\Accomplishments;

use App\Models\AccomplishmentReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ReportWriter
{
    /** @param array<string, mixed> $input */
    public function save(?AccomplishmentReport $report, int $ownerId, array $input): AccomplishmentReport
    {
        $finalize = (bool) ($input['finalize'] ?? false);
        $rules = [
            'report_month' => ['required', 'integer', 'between:1,12'],
            'report_year' => ['required', 'integer', 'between:2000,2100'],
            'quantity_mode' => ['sometimes', Rule::in(['custom', 'time'])],
            'prepared_by_id' => ['required', 'integer', Rule::exists('signatories', 'id')->where('signatory_type', 'prepared_by')],
            'certified_by_id' => ['required', 'integer', Rule::exists('signatories', 'id')->where('signatory_type', 'certified_correct')],
            'approved_by_id' => ['required', 'integer', Rule::exists('signatories', 'id')->where('signatory_type', 'approved')],
            'prepared_name' => ['nullable', 'string', 'max:255'],
            'prepared_position' => ['nullable', 'string', 'max:255'],
            'certified_name' => ['nullable', 'string', 'max:255'],
            'certified_position' => ['nullable', 'string', 'max:255'],
            'approved_name' => ['nullable', 'string', 'max:255'],
            'approved_position' => ['nullable', 'string', 'max:255'],
            'entries' => ['array'],
            'entries.*.id' => ['nullable', 'integer'],
            'entries.*.accomplishment_date' => ['required', 'date_format:Y-m-d', 'distinct'],
            'entries.*.quantity' => ['nullable', 'string', 'max:255'],
            'hourly_rate' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,99999999.99'],
            'is_jo' => ['sometimes', 'boolean'],
            'daily_rate' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,99999999.99'],
            'jo_tax_percent' => ['sometimes', 'numeric', 'decimal:0,2', 'between:0,100'],
            'entries.*.time_minutes' => ['nullable', 'integer', 'between:1,59999'],
            'entries.*.task_accomplished' => [$finalize ? 'required' : 'nullable', 'string', 'max:5000'],
            'entries.*.original_task_accomplished' => ['nullable', 'string', 'max:5000'],
            'entries.*.ai_suggested_task_accomplished' => ['nullable', 'string', 'max:5000'],
            'entries.*.ai_enhanced' => ['boolean'],
        ];

        $data = Validator::make($input, $rules)->validate();
        $data['quantity_mode'] = $data['quantity_mode'] ?? ($report ? $report->quantity_mode : 'time');
        $data['hourly_rate'] = array_key_exists('hourly_rate', $data) ? $data['hourly_rate'] : $report?->hourly_rate;
        $data['is_jo'] = $data['is_jo'] ?? ($report?->is_jo ?? false);
        $data['daily_rate'] = array_key_exists('daily_rate', $data) ? $data['daily_rate'] : $report?->daily_rate;
        $data['jo_tax_percent'] = $data['jo_tax_percent'] ?? ($report?->jo_tax_percent ?? 0);
        if ($finalize && $data['quantity_mode'] === 'time') {
            if ($data['is_jo'] && $data['daily_rate'] === null) {
                throw ValidationException::withMessages(['daily_rate' => 'Enter a daily rate before finalizing.']);
            }
        }
        $entries = $data['entries'] ?? [];

        if ($finalize && $entries === [] && (! $report || ! $report->entries()->exists())) {
            throw ValidationException::withMessages(['entries' => 'Add at least one accomplishment before finalizing.']);
        }

        foreach ($entries as $index => &$entry) {
            $date = $entry['accomplishment_date'];

            if ((int) substr($date, 0, 4) !== (int) $data['report_year'] || (int) substr($date, 5, 2) !== (int) $data['report_month']) {
                throw ValidationException::withMessages(["entries.$index.accomplishment_date" => 'The date must be within the reporting period.']);
            }

            if ($data['quantity_mode'] === 'time') {
                if (! isset($entry['time_minutes'])) {
                    try {
                        $entry['time_minutes'] = OvertimeQuantity::parse((string) ($entry['quantity'] ?? ''));
                    } catch (InvalidArgumentException) {
                        throw ValidationException::withMessages(["entries.$index.time_minutes" => 'Enter hours and minutes.']);
                    }
                }

                $entry['quantity'] = OvertimeQuantity::hours($entry['time_minutes']);
            } else {
                $entry['quantity'] = trim($entry['quantity'] ?? '');
                $entry['time_minutes'] = null;
                if ($entry['quantity'] === '') {
                    throw ValidationException::withMessages(["entries.$index.quantity" => 'Enter a quantity.']);
                }
            }
        }
        unset($entry);

        return DB::transaction(function () use ($report, $ownerId, $data, $entries, $finalize): AccomplishmentReport {
            $report ??= new AccomplishmentReport(['user_id' => $ownerId]);
            $report->fill(collect($data)->only(['report_month', 'report_year', 'quantity_mode', 'hourly_rate', 'is_jo', 'daily_rate', 'jo_tax_percent', 'prepared_by_id', 'certified_by_id', 'approved_by_id', 'prepared_name', 'prepared_position', 'certified_name', 'certified_position', 'approved_name', 'approved_position'])->all());
            $report->status = $finalize ? AccomplishmentReport::FINALIZED : AccomplishmentReport::DRAFT;
            $report->generated_at = null;
            $report->save();

            if (array_key_exists('entries', $data)) {
                $retainedIds = array_filter(array_column($entries, 'id'));
                $report->entries()->whereNotIn('id', $retainedIds)->delete();
            }

            foreach ($entries as $index => $entry) {
                $item = isset($entry['id'])
                    ? $report->entries()->whereKey($entry['id'])->firstOrFail()
                    : $report->entries()->make();
                $item->fill([
                    'accomplishment_date' => $entry['accomplishment_date'],
                    'quantity' => $entry['quantity'],
                    'quantity_mode' => $data['quantity_mode'],
                    'time_minutes' => $entry['time_minutes'],
                    'task_accomplished' => $entry['task_accomplished'] ?? null,
                    'original_task_accomplished' => $entry['original_task_accomplished'] ?? null,
                    'ai_suggested_task_accomplished' => $entry['ai_suggested_task_accomplished'] ?? null,
                    'ai_enhanced' => (bool) ($entry['ai_enhanced'] ?? false),
                    'sort_order' => $index,
                ]);
                $item->save();
            }

            if ($finalize && $report->entries()->doesntExist()) {
                throw ValidationException::withMessages(['entries' => 'Add at least one accomplishment before finalizing.']);
            }

            if ($finalize && $report->entries()->get()->contains(fn ($entry) => trim((string) $entry->task_accomplished) === '')) {
                throw ValidationException::withMessages(['entries' => 'Every accomplishment needs a task before finalizing.']);
            }

            return $report;
        });
    }
}
