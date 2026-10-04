<?php

namespace App\Http\Controllers;

use App\Models\AccomplishmentReport;
use App\Models\ReportTemplate;
use App\Services\Accomplishments\DocxReportWriter;
use App\Services\Accomplishments\OvertimePayCalculator;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\Frame;
use Dompdf\Options;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ReportGenerationController extends Controller
{
    public function __construct(private OvertimePayCalculator $calculator) {}

    public function preview(AccomplishmentReport $report): View
    {
        Gate::authorize('view', $report);

        return view('reports.print', $this->viewData($report, true));
    }

    public function generate(AccomplishmentReport $report): Response
    {
        Gate::authorize('generate', $report);
        $this->ensureComplete($report, requireComputation: $report->is_jo);
        $html = view('reports.print', $this->viewData($report, false))->render();
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->setDefaultMediaType('print');
        $pdf = new Dompdf($options);
        $pdf->setPaper([0, 0, 612, 936], 'portrait');
        $pdf->setCallbacks([[
            'event' => 'end_frame',
            'f' => static function (Frame $frame, Canvas $canvas): void {
                $node = $frame->get_node();
                if (! $node instanceof \DOMElement || $node->getAttribute('class') !== 'report-table') {
                    return;
                }
                [$x, $y, $width, $height] = $frame->get_border_box();
                $canvas->line($x, $y + $height, $x + $width, $y + $height, [0.133, 0.133, 0.133], 0.5);
            },
        ]]);
        $pdf->loadHtml($html);
        $pdf->render();
        $pdf->getCanvas()->page_text(280, 920, 'Page {PAGE_NUM} of {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 8, [0.35, 0.35, 0.35]);
        $report->update(['status' => AccomplishmentReport::GENERATED, 'generated_at' => now()]);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="OT-accoplishment-'.$report->generated_at->format('Y-m-d_H-i-s').'.pdf"',
        ]);
    }

    public function generateDocx(AccomplishmentReport $report, DocxReportWriter $writer): Response
    {
        Gate::authorize('generate', $report);
        $this->ensureComplete($report);
        $contents = $writer->write($this->viewData($report, false));
        $report->update(['status' => AccomplishmentReport::GENERATED, 'generated_at' => now()]);

        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="overtime-accomplishment-'.$report->report_year.'-'.str_pad((string) $report->report_month, 2, '0', STR_PAD_LEFT).'.docx"',
        ]);
    }

    /** @return array<string, mixed> */
    private function viewData(AccomplishmentReport $report, bool $preview): array
    {
        $report->load(['entries', 'preparedBy', 'certifiedBy', 'approvedBy']);
        $template = ReportTemplate::firstOrCreate(['user_id' => $report->user_id], ['footer_text' => ReportTemplate::DEFAULT_FOOTER])->refresh();
        $isDefaultOffice = $template->office_name === 'Provincial Information and Communications Technology Office';

        return [
            'canViewComputation' => Gate::allows('viewComputation', $report),
            'report' => $report,
            'overtimePay' => $this->calculator->calculate($report),
            'template' => $template,
            'preview' => $preview,
            'leftLogo' => $this->logoData($template->left_logo_path, $isDefaultOffice ? 'province-seal.png' : null),
            'rightLogo' => $this->logoData($template->right_logo_path, $isDefaultOffice ? 'bagong-pilipinas.png' : null),
            'footerLogo' => $isDefaultOffice ? $this->logoData(null, 'footer.jpg') : null,
        ];
    }

    private function logoData(?string $path, ?string $defaultAsset = null): ?string
    {
        if ($path && Storage::exists($path)) {
            return 'data:'.Storage::mimeType($path).';base64,'.base64_encode(Storage::get($path));
        }

        if ($defaultAsset) {
            $defaultPath = public_path('report-assets/'.$defaultAsset);

            if (is_file($defaultPath)) {
                $contents = file_get_contents($defaultPath);

                if ($contents !== false) {
                    return 'data:'.(mime_content_type($defaultPath) ?: 'image/png').';base64,'.base64_encode($contents);
                }
            }
        }

        return null;
    }

    private function ensureComplete(AccomplishmentReport $report, bool $requireComputation = true): void
    {
        $report->load(['entries', 'preparedBy', 'certifiedBy', 'approvedBy']);

        if ($report->entries->isEmpty() || $report->entries->contains(fn ($entry) => trim((string) $entry->task_accomplished) === '' || trim((string) $entry->quantity) === '')) {
            throw ValidationException::withMessages(['entries' => 'All accomplishments need a task and quantity before generating a report.']);
        }

        if ($report->quantity_mode === 'time' && $report->entries->contains(fn ($entry) => ($entry->time_minutes ?? 0) <= 0)) {
            throw ValidationException::withMessages(['entries' => 'Enter hours and minutes for every accomplishment before generating.']);
        }

        if ($requireComputation && ! $this->calculator->calculate($report)['complete']) {
            throw ValidationException::withMessages(['entries' => $report->is_jo
                ? 'Enter hours, minutes, and the report daily rate before generating.'
                : 'Enter hours, minutes, and the report hourly rate before generating.']);
        }

        foreach (['prepared', 'certified', 'approved'] as $role) {
            if (! $report->{$role.'_name'} && ! $report->{$role === 'prepared' ? 'preparedBy' : ($role === 'certified' ? 'certifiedBy' : 'approvedBy')}) {
                throw ValidationException::withMessages(['signatories' => 'All three signatories must be available before generating.']);
            }
        }
    }
}
