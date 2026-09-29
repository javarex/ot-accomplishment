<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $template->report_title }}</title>
    <style>
        @page { margin: 37mm 25mm 29mm; }
        body { font-family: "Times New Roman", Times, serif; color: #111; font-size: 12pt; }
        .toolbar { background: #f2f4f7; padding: 12px; margin: -10px -10px 22px; font: 13px Arial, sans-serif; }
        .toolbar a, .toolbar button { margin-right: 10px; padding: 6px 10px; }
        .header { position: fixed; top: -31mm; width: 100%; }
        .header td { text-align: center; vertical-align: middle; }
        .logo { width: 65px; max-height: 65px; object-fit: contain; }
        .province { font: bold 12pt Arial, sans-serif; margin: 1px 0 3px; }
        .office { font: bold 12pt Arial, sans-serif; margin: 2px 0 4px; }
        .address { font: bold 10pt Arial, sans-serif; line-height: 1.2; white-space: pre-line; }
        h1 { text-align: center; font-size: 12pt; margin: 17px 0 8px; }
        .period { text-align: center; font-size: 12pt; margin-bottom: 15px; }
        .report-table { border-collapse: collapse; width: 100%; table-layout: fixed; }
        .report-table th, .report-table td { border: .5pt solid #222; padding: 5pt 5.4pt 3pt; vertical-align: top; line-height: 1; overflow-wrap: break-word; }
        .report-table th { text-align: center; font-size: 12pt; font-weight: normal; }
        .report-table thead { display: table-header-group; }
        .report-table tr { page-break-inside: avoid; }
        .report-table tbody td { border-top: 0; border-bottom: 0; }
        .report-table tbody tr:last-child td { border-bottom: .5pt solid #222; }
        .date-column { width: 18.2%; }
        .quantity-column { width: 16.4%; text-align: center; }
        .total { margin-top: 9px; text-align: right; font-weight: bold; }
        .certification { margin: 22px 0 28px; text-align: justify; line-height: 1.55; page-break-inside: avoid; }
        .signatories { width: 100%; border-collapse: collapse; page-break-inside: avoid; }
        .signatories td { width: 50%; vertical-align: top; padding: 0 7px 10px; }
        .approved-block { width: 45%; margin: 22px auto 0; text-align: center; page-break-inside: avoid; }
        .signatory-label { margin-bottom: 34px; }
        .signatory-name { font-weight: bold; text-transform: uppercase; border-bottom: 1px solid #333; padding-bottom: 4px; }
        .signatory-position { margin-top: 4px; font-size: 12pt; }
        .footer { position: fixed; bottom: -21mm; left: 0; right: 0; font-family: Ovo, Times, serif; font-size: 8pt; line-height: 1.2; color: #e5b807; white-space: pre-line; }
        .footer img { float: right; width: 150px; height: auto; }
        @media screen { .header { position: static; margin-bottom: 15px; } .footer { position: static; margin-top: 24px; } }
        @media print { .toolbar { display: none; } }
    </style>
</head>
<body>
@if ($preview)
    <div class="toolbar"><a href="{{ route('reports.show', $report) }}">Back to report</a><button onclick="window.print()">Print preview</button>@can('generate', $report)<form method="post" action="{{ route('reports.generate-docx', $report) }}" style="display: inline">@csrf<button type="submit">Export DOCX</button></form>@endcan</div>
@endif
<table class="header"><tr>
    <td style="width: 75px">@if ($leftLogo)<img class="logo" src="{{ $leftLogo }}" alt="Left logo">@endif</td>
    <td><div class="province">{{ $template->province }}</div><div class="office">{{ $template->office_name }}</div><div class="address">{{ $template->office_address }}</div></td>
    <td style="width: 75px">@if ($rightLogo)<img class="logo" src="{{ $rightLogo }}" alt="Right logo">@endif</td>
</tr></table>
<h1>{{ $template->report_title }}</h1>
<div class="period">{{ \Carbon\CarbonImmutable::create($report->report_year, $report->report_month, 1)->format('F Y') }}</div>
<table class="report-table">
    <thead><tr><th class="date-column">DATE</th><th class="quantity-column">QUANTITY</th><th>TASK ACCOMPLISHED</th></tr></thead>
    <tbody>
    @foreach ($report->entries as $entry)
        <tr><td>{{ $entry->accomplishment_date->format('F j, Y') }}</td><td class="quantity-column">{{ $entry->quantity }}</td><td>{{ $entry->task_accomplished ?: '—' }}</td></tr>
    @endforeach
    </tbody>
</table>
<div class="certification">{{ $template->certification_statement }}</div>
<table class="signatories"><tr>
    <td><div class="signatory-label">Prepared by:</div><div class="signatory-name">{{ $report->prepared_name ?: $report->preparedBy?->name }}</div><div class="signatory-position">{{ $report->prepared_position ?: $report->preparedBy?->position }}</div></td>
    <td><div class="signatory-label">Certified Correct:</div><div class="signatory-name">{{ $report->certified_name ?: $report->certifiedBy?->name }}</div><div class="signatory-position">{{ $report->certified_position ?: $report->certifiedBy?->position }}</div></td>
</tr></table>
<div class="approved-block"><div class="signatory-label">Approved by:</div><div class="signatory-name">{{ $report->approved_name ?: $report->approvedBy?->name }}</div><div class="signatory-position">{{ $report->approved_position ?: $report->approvedBy?->position }}</div></div>
@if ($template->footer_text || $footerLogo)<div class="footer">@if ($footerLogo)<img src="{{ $footerLogo }}" alt="Provincial Capitol illustration">@endif{{ $template->footer_text }}</div>@endif
</body>
</html>
