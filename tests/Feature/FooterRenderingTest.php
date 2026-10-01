<?php

namespace Tests\Feature;

use App\Models\AccomplishmentReport;
use App\Models\ReportTemplate;
use Dompdf\Dompdf;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class FooterRenderingTest extends TestCase
{
    public function test_pdf_renders_formatted_footer_text_below_the_image(): void
    {
        $report = new AccomplishmentReport(['report_year' => 2026, 'report_month' => 10, 'quantity_mode' => 'custom']);
        $report->setRelation('entries', collect());
        foreach (['preparedBy', 'certifiedBy', 'approvedBy'] as $relation) {
            $report->setRelation($relation, null);
        }
        $template = new ReportTemplate([
            'footer_text' => '<p style="text-align:center;font-size:12pt;font-family:Arial"><b>Custom office footer</b></p><p><i>Custom address</i></p>',
        ]);
        $footerLogo = 'data:image/jpeg;base64,'.base64_encode(file_get_contents(public_path('report-assets/footer.jpg')));

        $html = view('reports.print', [
            'report' => $report, 'template' => $template, 'preview' => false,
            'canViewComputation' => false, 'overtimePay' => [],
            'leftLogo' => null, 'rightLogo' => null, 'footerLogo' => $footerLogo,
        ])->render();
        $pdf = new Dompdf;
        $pdf->setPaper([0, 0, 612, 936]);
        $pdf->loadHtml($html);
        $pdf->render();
        $text = (new Parser)->parseContent($pdf->output())->getText();

        $this->assertStringContainsString('Custom office footer', $text);
        $this->assertStringContainsString('Custom address', $text);
        $this->assertStringContainsString('text-align:center;font-size:12pt;font-family:Arial;', $html);
        $this->assertStringContainsString('alt="Provincial Capitol illustration"><div class="footer-text">', $html);
        $this->assertSame(1, $pdf->getCanvas()->get_page_count());
    }
}
