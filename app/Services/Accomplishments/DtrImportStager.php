<?php

namespace App\Services\Accomplishments;

use App\Contracts\DtrParser;
use App\Models\AccomplishmentReport;
use App\Models\DtrImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class DtrImportStager
{
    public function __construct(private DtrParser $parser) {}

    /** @return array{employee_name:string,employee_id:?string,month:int,year:int,entries:array<int,array<string,mixed>>} */
    public function parse(UploadedFile $file): array
    {
        return $this->parser->parse($file->getRealPath());
    }

    /** @param array{employee_name:string,employee_id:?string,month:int,year:int,entries:array<int,array<string,mixed>>}|null $parsed */
    public function stage(AccomplishmentReport $report, int $userId, UploadedFile $file, ?array $parsed = null): DtrImport
    {
        $hash = hash_file('sha256', $file->getRealPath());
        $existing = $report->dtrImports()->where('file_hash', $hash)->first();

        if ($existing) {
            return $existing;
        }

        $parsed ??= $this->parse($file);

        if ($parsed['month'] !== $report->report_month || $parsed['year'] !== $report->report_year) {
            throw ValidationException::withMessages(['dtr' => 'The DTR period does not match the report period.']);
        }

        $path = $file->store('dtr-imports');

        if (! $path) {
            throw ValidationException::withMessages(['dtr' => 'The DTR file could not be stored.']);
        }

        try {
            return DB::transaction(function () use ($report, $userId, $file, $path, $hash, $parsed): DtrImport {
                $import = $report->dtrImports()->create([
                    'user_id' => $userId,
                    'employee_name' => $parsed['employee_name'],
                    'employee_id' => $parsed['employee_id'],
                    'month' => $parsed['month'],
                    'year' => $parsed['year'],
                    'original_filename' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'file_hash' => $hash,
                    'import_status' => 'review',
                    'parsed_data' => $parsed,
                ]);

                foreach ($parsed['entries'] as $entry) {
                    $import->entries()->create([
                        'work_date' => $entry['date'],
                        'am_in' => $entry['am_in'],
                        'am_out' => $entry['am_out'],
                        'pm_in' => $entry['pm_in'],
                        'pm_out' => $entry['pm_out'],
                        'overtime_minutes' => $entry['overtime_minutes'],
                        'remarks' => $entry['remarks'],
                    ]);
                }

                return $import;
            });
        } catch (Throwable $exception) {
            Storage::delete($path);
            throw $exception;
        }
    }
}
