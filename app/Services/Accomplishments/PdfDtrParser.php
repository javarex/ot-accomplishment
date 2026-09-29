<?php

namespace App\Services\Accomplishments;

use App\Contracts\DtrParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Smalot\PdfParser\Parser;
use Throwable;

class PdfDtrParser implements DtrParser
{
    public function parse(string $filePath): array
    {
        try {
            $text = (new Parser)->parseFile($filePath)->getText();
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['dtr' => 'The PDF could not be read. Upload a valid, text-based DTR PDF.']);
        }

        try {
            return $this->parseText($text);
        } catch (ValidationException $exception) {
            Log::warning('DTR parsing failed', ['file' => basename($filePath), 'errors' => $exception->errors()]);
            throw $exception;
        }
    }

    /** @return array{employee_name:string,employee_id:?string,month:int,year:int,entries:array<int,array<string,mixed>>} */
    public function parseText(string $text): array
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);

        if (! preg_match('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s*,?\s*(20\d{2})\b/i', $text, $period)) {
            throw ValidationException::withMessages(['dtr' => 'The DTR month and year could not be found.']);
        }

        $month = CarbonImmutable::parse($period[1].' 1')->month;
        $year = (int) $period[2];

        if (! preg_match('/\b(?:Employee\s+)?Name\s*:?\s*([^\n\t|]{3,100})/i', $text, $nameMatch)) {
            throw ValidationException::withMessages(['dtr' => 'The employee name could not be found.']);
        }

        $employeeName = trim(preg_replace('/\s+\./', '.', preg_replace('/\s+/', ' ', trim($nameMatch[1]))));
        $employeeId = preg_match('/\b(?:Employee\s+)?ID(?:\s*No\.?)?\s*[:.]?\s*([A-Za-z0-9-]+)/i', $text, $idMatch) ? $idMatch[1] : null;
        $entries = [];
        $lines = preg_split('/\n+/', $text) ?: [];

        foreach ($lines as $lineIndex => $line) {
            if (preg_match('/^\s*(0?[1-9]|[12]\d|3[01])\s*[MTWFS](?=\d|\s|$)(.*)$/i', $line, $row)) {
                $content = trim($row[2]);
            } elseif (preg_match('/^\s*(0?[1-9]|[12]\d|3[01])\s+(.+)$/', $line, $row)) {
                $content = trim($row[2]);
            } else {
                continue;
            }

            $day = (int) $row[1];

            try {
                $date = CarbonImmutable::createSafe($year, $month, $day)->toDateString();
            } catch (Throwable) {
                continue;
            }

            $firstCopy = preg_split('/\s+[MTWFS]\s+0?'.$day.'\b/i', $content, 2)[0] ?? $content;
            preg_match_all('/(\d{1,2}:\d{2})/', $firstCopy, $firstCopyMatches);
            preg_match_all('/(\d{1,2}:\d{2})/', $content, $allTimeMatches);
            $times = $firstCopyMatches[1];
            $amIn = null;
            $amOut = null;
            $pmIn = null;
            $pmOut = null;

            if (count($times) >= 4) {
                [$amIn, $amOut, $pmIn, $pmOut] = array_slice($times, 0, 4);
            } elseif (count($times) === 3) {
                [$amIn, $pmIn, $pmOut] = $times;
            } elseif (count($times) === 2 && (int) explode(':', $times[0])[0] >= 12) {
                [$pmIn, $pmOut] = $times;
            } elseif (count($times) === 2) {
                [$amIn, $amOut] = $times;
            } elseif (count($times) === 1) {
                $amIn = $times[0];
            }

            if ($amOut === null && $pmIn !== null) {
                foreach (array_reverse(array_slice($allTimeMatches[1], count($times))) as $candidate) {
                    $candidateHour = (int) explode(':', $candidate)[0];
                    if ($candidateHour >= 11 && $candidateHour <= 12 && $candidate !== $pmIn) {
                        $amOut = $candidate;
                        break;
                    }
                }
            }

            if (preg_match('/^\s*(\d{1,2}:\d{2})/', $lines[$lineIndex + 1] ?? '', $continuation)) {
                $amOut = $continuation[1];
            }

            $overtime = null;
            $remarks = null;

            if (preg_match('/\bOT\s*[:=-]?\s*((?:\d+\s*h(?:ours?|rs?)?(?:\s*\d+\s*m(?:in(?:utes?)?)?)?)|(?:\d+\s*m(?:in(?:utes?)?)?)|(?:\d+:\d{2}))/i', $content, $otMatch)) {
                try {
                    $overtime = OvertimeQuantity::parse($otMatch[1]);
                    $remarks = trim($otMatch[0]);
                } catch (\InvalidArgumentException) {
                    $overtime = null;
                }
            }

            if ($remarks === null && preg_match('/\bLEAVE\b/i', $content)) {
                $remarks = 'LEAVE';
            }

            $record = [
                'day' => $day,
                'date' => $date,
                'am_in' => $amIn,
                'am_out' => $amOut,
                'pm_in' => $pmIn !== null ? $this->normalizePm($pmIn) : null,
                'pm_out' => $pmOut !== null ? $this->normalizePm($pmOut) : null,
                'overtime_minutes' => $overtime,
                'remarks' => $remarks,
            ];

            if (isset($entries[$date])) {
                if ($entries[$date]['overtime_minutes'] && $overtime && $entries[$date]['overtime_minutes'] !== $overtime) {
                    throw ValidationException::withMessages(['dtr' => 'Conflicting overtime values were found for '.$date.'.']);
                }

                if ($overtime && ! $entries[$date]['overtime_minutes']) {
                    $entries[$date] = $record;
                }

                continue;
            }

            $entries[$date] = $record;
        }

        if ($entries === []) {
            throw ValidationException::withMessages(['dtr' => 'No daily attendance rows were found. This PDF may be scanned or use an unsupported layout.']);
        }

        ksort($entries);

        return [
            'employee_name' => $employeeName,
            'employee_id' => $employeeId,
            'month' => $month,
            'year' => $year,
            'entries' => array_values($entries),
        ];
    }

    private function normalizePm(string $time): string
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return sprintf('%02d:%02d', $hour < 12 ? $hour + 12 : $hour, $minute);
    }
}
