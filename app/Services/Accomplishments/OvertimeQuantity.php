<?php

namespace App\Services\Accomplishments;

use InvalidArgumentException;

class OvertimeQuantity
{
    public static function parse(string $value): int
    {
        $value = trim(strtolower($value));

        if (preg_match('/^(?:(\d+)\s*h(?:ours?|rs?)?)?(?:\s*(\d+)\s*m(?:in(?:utes?)?)?)?$/', $value, $parts) && (($parts[1] ?? '') !== '' || ($parts[2] ?? '') !== '')) {
            $minutes = ((int) ($parts[1] ?? 0) * 60) + (int) ($parts[2] ?? 0);
        } elseif (preg_match('/^(\d+):(\d{2})$/', $value, $matches)) {
            $minutes = ((int) $matches[1] * 60) + (int) $matches[2];
        } elseif (preg_match('/^\d+$/', $value)) {
            $minutes = (int) $value;
        } else {
            throw new InvalidArgumentException('Enter a quantity such as 2h 15m, 3h, or 135 minutes.');
        }

        if ($minutes <= 0 || $minutes > 1440) {
            throw new InvalidArgumentException('Quantity must be between 1 minute and 24 hours.');
        }

        return $minutes;
    }

    public static function format(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        if ($hours === 0) {
            return $remaining.'m';
        }

        return $hours.'h'.($remaining > 0 ? ' '.$remaining.'m' : '');
    }

    public static function hours(int $minutes): string
    {
        return rtrim(rtrim(number_format($minutes / 60, 4, '.', ''), '0'), '.').' hours';
    }
}
