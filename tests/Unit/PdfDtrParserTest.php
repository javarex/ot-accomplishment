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

    public function test_wrapped_mixed_remarks_stay_with_their_day_and_preserve_overtime(): void
    {
        $text = <<<'DTR'
Name: EMPLOYEE SAMPLE
September 2026
23W07:59 12:3109:18    W 23    12:3107:59 09:18*    *
OT 3h 18m    OT 3h 18m
12:0012:00
28M    M 28
WORK SUSPENSION,CTO    WORK SUSPENSION,CTO
29T07:16    10:15    T 2907:16    10:15
OB, OT 4h 15m OB, OT 4h 15m
30W07:40 12:4411:11    W 30    12:4407:40 11:11
OB, OT 5h 11m OB, OT 5h 11m
I hereby certify on my honor that the above is true and correct
OT 33h 33m    OT 33h 33m
DTR;

        $result = (new PdfDtrParser)->parseText($text);

        $this->assertCount(4, $result['entries']);
        $this->assertSame(198, $result['entries'][0]['overtime_minutes']);
        $this->assertSame('12:00', $result['entries'][0]['am_out']);
        $this->assertSame('WORK SUSPENSION,CTO', $result['entries'][1]['remarks']);
        $this->assertNull($result['entries'][1]['overtime_minutes']);
        $this->assertSame(255, $result['entries'][2]['overtime_minutes']);
        $this->assertSame('OB, OT 4h 15m', $result['entries'][2]['remarks']);
        $this->assertNull($result['entries'][2]['pm_in']);
        $this->assertSame(311, $result['entries'][3]['overtime_minutes']);
        $this->assertSame('OB, OT 5h 11m', $result['entries'][3]['remarks']);
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
