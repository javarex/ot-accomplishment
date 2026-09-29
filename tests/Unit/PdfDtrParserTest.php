<?php

namespace Tests\Unit;

use App\Services\Accomplishments\OvertimeQuantity;
use App\Services\Accomplishments\PdfDtrParser;
use PHPUnit\Framework\TestCase;

class PdfDtrParserTest extends TestCase
{
    public function test_repeated_printable_sections_produce_one_normalized_overtime_entry(): void
    {
        $text = "Name: RAYMART N. ITANONG\nEmployee ID: 9548\nSeptember 2026\n4 07:44 12:01 12:53 07:15 OT 2h 15m\n4 07:44 12:01 12:53 07:15 OT 2h 15m\n5 08:00 12:00 13:00 17:00 Regular";

        $result = (new PdfDtrParser)->parseText($text);

        $this->assertSame('RAYMART N. ITANONG', $result['employee_name']);
        $this->assertSame('9548', $result['employee_id']);
        $this->assertSame(9, $result['month']);
        $this->assertSame(2026, $result['year']);
        $this->assertCount(2, $result['entries']);
        $this->assertSame('2026-09-04', $result['entries'][0]['date']);
        $this->assertSame(135, $result['entries'][0]['overtime_minutes']);
        $this->assertSame('19:15', $result['entries'][0]['pm_out']);
        $this->assertNull($result['entries'][1]['overtime_minutes']);
    }

    public function test_minutes_have_stable_human_readable_format(): void
    {
        $this->assertSame(135, OvertimeQuantity::parse('2h 15m'));
        $this->assertSame(180, OvertimeQuantity::parse('3h'));
        $this->assertSame(400, OvertimeQuantity::parse('6h 40m'));
        $this->assertSame('2h 15m', OvertimeQuantity::format(135));
        $this->assertSame('3h', OvertimeQuantity::format(180));
    }

    public function test_mirrored_civil_service_form_rows_are_normalized_once(): void
    {
        $text = <<<'DTR'
Name  EMPLOYEE N . SAMPLE
ID No.1234
For the Month of SEPTEMBER 2026    For the Month of SEPTEMBER 2026
DayAM-inAM-outPM-inPM-outUndertimeRemarks    RemarksUndertimePM-outPM-inAM-outAM-inDay
 4F07:44 12:5307:15    F 4    12:5307:44 07:15OT 2h 15m    OT 2h 15m
12:0112:01
 5S    12:3203:08    S 5    12:3203:08OT 2h 8m    OT 2h 8m
 6S11:38 12:3103:36    S 6    12:3111:38 03:36OT 2h 58m    OT 2h 58m
12:0212:02
 9W07:49 12:3408:34    W 9    12:3407:49 08:34OT 3h    OT 3h
 10T07:44 12:4208:19    T 10    12:4207:44 08:19OT 3h    OT 3h
 12S08:50 12:3204:30    S 12    12:3208:50 04:30OT 6h 40m    OT 6h 40m
 17T07:55 12:3207:21    T 17    12:3207:55 07:21OT 2h 21m    OT 2h 21m
 19S09:21 12:4405:02    S 19    12:4409:21 05:02OT 6h 39m    OT 6h 39m
 20S08:03 12:3104:15    S 20    12:3108:03 04:15OT 7h 12m    OT 7h 12m
DTR;

        $result = (new PdfDtrParser)->parseText($text);

        $this->assertSame('EMPLOYEE N. SAMPLE', $result['employee_name']);
        $this->assertSame('1234', $result['employee_id']);
        $this->assertCount(9, $result['entries']);
        $this->assertSame(2173, array_sum(array_column($result['entries'], 'overtime_minutes')));
        $this->assertSame('12:01', $result['entries'][0]['am_out']);
        $this->assertSame('19:15', $result['entries'][0]['pm_out']);
    }
}
