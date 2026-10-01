<?php

namespace Tests\Unit;

use App\Services\Accomplishments\FooterHtml;
use PHPUnit\Framework\TestCase;

class FooterHtmlTest extends TestCase
{
    public function test_preserves_supported_footer_formatting(): void
    {
        $html = FooterHtml::sanitize('<p style="text-align:right"><font face="Arial" size="4"><b>Office</b></font><br><i>Address</i></p>');

        $this->assertStringContainsString('text-align:right;', $html);
        $this->assertStringContainsString('font-size:14pt;', $html);
        $this->assertStringContainsString('font-family:Arial;', $html);
        $this->assertStringContainsString('<b>Office</b>', $html);
        $this->assertStringContainsString('<i>Address</i>', $html);
    }

    public function test_removes_executable_markup_and_unsafe_styles(): void
    {
        $html = FooterHtml::sanitize('<script>alert(1)</script><p onclick="alert(2)" style="text-align:center;position:fixed;background:url(https://example.com);font-size:999pt">Office<img src="x" onerror="alert(3)"><a href="javascript:alert(4)">Email</a></p>');

        $this->assertSame('<p style="text-align:center;">OfficeEmail</p>', $html);
    }

    public function test_preserves_plain_text_line_breaks_and_escapes_special_characters(): void
    {
        $html = FooterHtml::sanitize("Office & staff\n✉ office@example.com");

        $this->assertStringContainsString('Office &amp; staff<br', $html);
        $this->assertStringContainsString('✉ office@example.com', $html);
        $this->assertSame("Office & staff\n✉ office@example.com", FooterHtml::text($html));
    }

    public function test_word_export_receives_text_instead_of_html_tags(): void
    {
        $this->assertSame("Office\nAddress", FooterHtml::text('<p><b>Office</b></p><p>Address</p>'));
        $this->assertSame('', FooterHtml::sanitize(null));
    }
}
