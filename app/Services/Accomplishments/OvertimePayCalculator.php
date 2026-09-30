<?php

namespace App\Services\Accomplishments;

use App\Models\AccomplishmentReport;
use Carbon\CarbonImmutable;

class OvertimePayCalculator
{
    /**
     * @return array{weekday: array{hours: int, minutes: int, total_minutes: int, gross_cents: int}, weekend: array{hours: int, minutes: int, total_minutes: int, gross_cents: int}, complete: bool, gross_cents: int, deduction_cents: int, net_cents: int}
     */
    public function calculate(AccomplishmentReport $report): array
    {
        $groups = [
            'weekday' => ['hours' => 0, 'minutes' => 0, 'total_minutes' => 0, 'gross_cents' => 0],
            'weekend' => ['hours' => 0, 'minutes' => 0, 'total_minutes' => 0, 'gross_cents' => 0],
        ];
        $numerators = ['weekday' => 0, 'weekend' => 0];
        $complete = $report->quantity_mode !== 'time' || $report->hourly_rate !== null;

        foreach ($report->entries as $entry) {
            if ($entry->quantity_mode !== 'time') {
                continue;
            }

            $weekend = CarbonImmutable::parse($entry->accomplishment_date)->isWeekend();
            $group = $weekend ? 'weekend' : 'weekday';
            $minutes = $entry->time_minutes ?? 0;
            $groups[$group]['hours'] += intdiv($minutes, 60);
            $groups[$group]['minutes'] += $minutes % 60;
            $groups[$group]['total_minutes'] += $minutes;

            if ($report->hourly_rate === null || $minutes <= 0) {
                $complete = false;

                continue;
            }

            $rateCents = (int) round($report->hourly_rate * 100);
            $numerators[$group] += $minutes * $rateCents * ($weekend ? 150 : 125);
        }

        foreach ($groups as $group => &$totals) {
            $totals['gross_cents'] = (int) round($numerators[$group] / 6000);
        }
        unset($totals);

        $gross = $groups['weekday']['gross_cents'] + $groups['weekend']['gross_cents'];
        $deduction = (int) round($gross * 20 / 100);

        return [...$groups, 'complete' => $complete, 'gross_cents' => $gross, 'deduction_cents' => $deduction, 'net_cents' => $gross - $deduction];
    }
}
