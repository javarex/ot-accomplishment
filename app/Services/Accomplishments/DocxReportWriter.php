<?php

namespace App\Services\Accomplishments;

use App\Models\AccomplishmentReport;
use App\Models\ReportTemplate;
use Carbon\CarbonImmutable;
use RuntimeException;
use ZipArchive;

class DocxReportWriter
{
    /** @param array<string, mixed> $data */
    public function write(array $data): string
    {
        /** @var AccomplishmentReport $report */
        $report = $data['report'];
        /** @var ReportTemplate $template */
        $template = $data['template'];
        $left = $this->image($data['leftLogo']);
        $right = $this->image($data['rightLogo']);
        $footerLogo = $this->image($data['footerLogo']);

        $header = $this->xmlHeader().'<w:hdr '.$this->namespaces().'>'.$this->table([1200, 6960, 1200], false)
            .$this->row([
                $this->imageCell($left, 'rId3', 1200),
                $this->cell($this->paragraph((string) $template->province, 'center', 24, true, font: 'Arial')
                    .$this->paragraph((string) $template->office_name, 'center', 24, true, font: 'Arial')
                    .$this->paragraph((string) $template->office_address, 'center', 20, true, font: 'Arial'), 6960),
                $this->imageCell($right, 'rId4', 1200),
            ]).'</w:tbl></w:hdr>';
        $body = $this->paragraph((string) $template->report_title, 'center', 24, true, 220)
            .$this->paragraph(CarbonImmutable::create($report->report_year, $report->report_month, 1)->format('F Y'), 'center', 24, false, 180);

        $canViewComputation = $data['canViewComputation'];
        $body .= $this->table($canViewComputation ? [1705, 1530, 1300, 4815] : [1705, 1530, 6115]);
        $body .= $this->row([
            $this->cell($this->paragraph('DATE', 'center'), 1705, true),
            $this->cell($this->paragraph('QUANTITY', 'center'), 1530, true),
            ...($canViewComputation ? [$this->cell($this->paragraph('HOURLY RATE', 'center'), 1300, true)] : []),
            $this->cell($this->paragraph('TASK ACCOMPLISHED', 'center'), $canViewComputation ? 4815 : 6115, true),
        ], true);
        foreach ($report->entries as $entry) {
            $body .= $this->row([
                $this->cell($this->paragraph(CarbonImmutable::parse($entry->accomplishment_date)->format('F j, Y')), 1705),
                $this->cell($this->paragraph((string) $entry->quantity, 'center'), 1530),
                ...($canViewComputation ? [$this->cell($this->paragraph((string) ($report->hourly_rate ?? '—'), 'center'), 1300)] : []),
                $this->cell($this->paragraph((string) $entry->task_accomplished), $canViewComputation ? 4815 : 6115),
            ]);
        }
        $body .= '</w:tbl>';
        if ($canViewComputation && $report->quantity_mode === 'time') {
            $pay = $data['overtimePay'];
            $body .= $this->paragraph('Gross OT pay: '.number_format($pay['gross_cents'] / 100, 2), 'right')
                .$this->paragraph('Deduction (20%): '.number_format($pay['deduction_cents'] / 100, 2), 'right')
                .$this->paragraph('Net OT pay: '.number_format($pay['net_cents'] / 100, 2), 'right');
        }
        $body .= $this->paragraph((string) $template->certification_statement, 'both', 24, false, 260);

        $body .= $this->table([4680, 4680], false)
            .$this->row([
                $this->signatory('Prepared by:', (string) ($report->prepared_name ?: $report->preparedBy?->name), (string) ($report->prepared_position ?: $report->preparedBy?->position), 4680),
                $this->signatory('Certified Correct:', (string) ($report->certified_name ?: $report->certifiedBy?->name), (string) ($report->certified_position ?: $report->certifiedBy?->position), 4680),
            ]).'</w:tbl>';
        $body .= $this->table([9360], false)
            .$this->row([$this->signatory('Approved by:', (string) ($report->approved_name ?: $report->approvedBy?->name), (string) ($report->approved_position ?: $report->approvedBy?->position), 9360, true)])
            .'</w:tbl>';

        $document = $this->xmlHeader().'<w:document '.$this->namespaces().'><w:body>'.$body
            .'<w:sectPr><w:headerReference w:type="default" r:id="rId5"/><w:footerReference w:type="default" r:id="rId2"/><w:pgSz w:w="12240" w:h="18720"/><w:pgMar w:top="1800" w:right="1440" w:bottom="1500" w:left="1440" w:header="500" w:footer="350"/></w:sectPr></w:body></w:document>';
        $footer = $this->xmlHeader().'<w:ftr '.$this->namespaces().'>'
            .$this->table([7200, 2160], false)
            .$this->row([
                $this->cell($this->paragraph((string) $template->footer_text, size: 16, font: 'Ovo', color: 'E5B807'), 7200),
                $this->imageCell($footerLogo, 'rId1', 2160, 185),
            ]).'</w:tbl>'
            .'<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:t>Page </w:t></w:r><w:fldSimple w:instr="PAGE"/></w:p></w:ftr>';

        $path = tempnam(sys_get_temp_dir(), 'accomplishment-docx-');
        if ($path === false) {
            throw new RuntimeException('Could not create the DOCX file.');
        }

        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not open the DOCX file.');
            }
            $zip->addFromString('[Content_Types].xml', $this->contentTypes());
            $zip->addFromString('_rels/.rels', $this->relationships(['rId1' => ['officeDocument', 'word/document.xml']]));
            $zip->addFromString('word/document.xml', $document);
            $zip->addFromString('word/header1.xml', $header);
            $zip->addFromString('word/footer1.xml', $footer);
            $zip->addFromString('word/styles.xml', $this->xmlHeader().'<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman"/><w:sz w:val="24"/></w:rPr></w:rPrDefault></w:docDefaults></w:styles>');
            $zip->addFromString('word/_rels/document.xml.rels', $this->relationships([
                'rId1' => ['styles', 'styles.xml'],
                'rId2' => ['footer', 'footer1.xml'],
                'rId5' => ['header', 'header1.xml'],
            ]));
            $zip->addFromString('word/_rels/header1.xml.rels', $this->relationships([
                ...($left ? ['rId3' => ['image', 'media/left.'.$left['extension']]] : []),
                ...($right ? ['rId4' => ['image', 'media/right.'.$right['extension']]] : []),
            ]));
            foreach (['left' => $left, 'right' => $right, 'footer' => $footerLogo] as $name => $image) {
                if ($image) {
                    $zip->addFromString('word/media/'.$name.'.'.$image['extension'], $image['bytes']);
                }
            }
            if ($footerLogo) {
                $zip->addFromString('word/_rels/footer1.xml.rels', $this->relationships(['rId1' => ['image', 'media/footer.'.$footerLogo['extension']]]));
            }
            if (! $zip->close()) {
                throw new RuntimeException('Could not finish the DOCX file.');
            }

            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('Could not read the DOCX file.');
            }

            return $contents;
        } finally {
            @unlink($path);
        }
    }

    private function paragraph(string $text, string $align = 'left', int $size = 24, bool $bold = false, int $spacing = 0, string $font = 'Times New Roman', string $color = '000000'): string
    {
        $lines = preg_split('/\R/u', $text) ?: [''];
        $content = implode('<w:br/>', array_map(fn (string $line): string => '<w:t xml:space="preserve">'.$this->escape($line).'</w:t>', $lines));

        return '<w:p><w:pPr><w:jc w:val="'.$align.'"/><w:spacing w:after="'.$spacing.'" w:line="240" w:lineRule="auto"/></w:pPr><w:r><w:rPr><w:rFonts w:ascii="'.$font.'" w:hAnsi="'.$font.'"/>'.($bold ? '<w:b/>' : '').'<w:color w:val="'.$color.'"/><w:sz w:val="'.$size.'"/></w:rPr>'.$content.'</w:r></w:p>';
    }

    /** @param array<int, int> $widths */
    private function table(array $widths, bool $bordered = true): string
    {
        $border = $bordered ? '<w:tblBorders><w:top w:val="single" w:sz="4"/><w:left w:val="single" w:sz="4"/><w:bottom w:val="single" w:sz="4"/><w:right w:val="single" w:sz="4"/><w:insideH w:val="nil"/><w:insideV w:val="single" w:sz="4"/></w:tblBorders>' : '';
        $grid = implode('', array_map(fn (int $width): string => '<w:gridCol w:w="'.$width.'"/>', $widths));

        $cellMargins = $bordered ? '<w:tblCellMar><w:top w:w="100" w:type="dxa"/><w:left w:w="108" w:type="dxa"/><w:bottom w:w="60" w:type="dxa"/><w:right w:w="108" w:type="dxa"/></w:tblCellMar>' : '';

        return '<w:tbl><w:tblPr><w:tblW w:w="'.array_sum($widths).'" w:type="dxa"/><w:tblLayout w:type="fixed"/>'.$border.$cellMargins.'</w:tblPr><w:tblGrid>'.$grid.'</w:tblGrid>';
    }

    /** @param array<int, string> $cells */
    private function row(array $cells, bool $header = false): string
    {
        return '<w:tr><w:trPr><w:cantSplit/>'.($header ? '<w:tblHeader/>' : '').'</w:trPr>'.implode('', $cells).'</w:tr>';
    }

    private function cell(string $content, int $width, bool $header = false): string
    {
        return '<w:tc><w:tcPr><w:tcW w:w="'.$width.'" w:type="dxa"/><w:vAlign w:val="center"/>'.($header ? '<w:tcBorders><w:bottom w:val="single" w:sz="4"/></w:tcBorders>' : '').'</w:tcPr>'.$content.'</w:tc>';
    }

    private function signatory(string $label, string $name, string $position, int $width, bool $center = false): string
    {
        $align = $center ? 'center' : 'left';

        return $this->cell($this->paragraph($label, $align, spacing: 420)
            .$this->paragraph(mb_strtoupper($name), $align, 24, true, 25)
            .$this->paragraph($position, $align), $width);
    }

    /** @param array{bytes:string,extension:string,width:int,height:int}|null $image */
    private function imageCell(?array $image, string $relationId, int $width, int $maxPixels = 65): string
    {
        if ($image === null) {
            return $this->cell($this->paragraph(''), $width);
        }
        $scale = min($maxPixels / max($image['width'], $image['height']), 1);
        $cx = (int) round($image['width'] * $scale * 9525);
        $cy = (int) round($image['height'] * $scale * 9525);
        $drawing = '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:drawing><wp:inline><wp:extent cx="'.$cx.'" cy="'.$cy.'"/><wp:docPr id="'.(int) substr($relationId, 3).'" name="Report logo"/><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="Logo"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="'.$relationId.'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$cx.'" cy="'.$cy.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';

        return $this->cell($drawing, $width);
    }

    /** @return array{bytes:string,extension:string,width:int,height:int}|null */
    private function image(?string $dataUri): ?array
    {
        if (! $dataUri || ! str_contains($dataUri, ',')) {
            return null;
        }
        $bytes = base64_decode(substr($dataUri, strpos($dataUri, ',') + 1), true);
        $size = $bytes === false ? false : @getimagesizefromstring($bytes);
        if ($size === false) {
            return null;
        }
        $extension = match ($size['mime']) {
            'image/png' => 'png',
            'image/jpeg' => 'jpeg',
            default => null,
        };
        if ($extension === null) {
            $image = @imagecreatefromstring($bytes);
            if ($image === false) {
                return null;
            }
            ob_start();
            imagepng($image);
            $bytes = ob_get_clean();
            imagedestroy($image);
            if ($bytes === false) {
                return null;
            }
            $extension = 'png';
        }

        return ['bytes' => $bytes, 'extension' => $extension, 'width' => $size[0], 'height' => $size[1]];
    }

    private function contentTypes(): string
    {
        return $this->xmlHeader().'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Default Extension="jpeg" ContentType="image/jpeg"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>';
    }

    /** @param array<string, array{string, string}> $items */
    private function relationships(array $items): string
    {
        $xml = $this->xmlHeader().'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($items as $id => [$type, $target]) {
            $xml .= '<Relationship Id="'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/'.$type.'" Target="'.$target.'"/>';
        }

        return $xml.'</Relationships>';
    }

    private function xmlHeader(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    }

    private function namespaces(): string
    {
        return 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"';
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
