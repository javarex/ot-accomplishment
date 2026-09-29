<?php

namespace App\Http\Controllers;

use App\Models\ReportTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ReportTemplateController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('templates/edit', [
            'template' => ReportTemplate::firstOrCreate(['user_id' => $request->user()->id], ['footer_text' => ReportTemplate::DEFAULT_FOOTER])->refresh(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'province' => ['required', 'string', 'max:255'],
            'office_name' => ['required', 'string', 'max:255'],
            'office_address' => ['required', 'string', 'max:1000'],
            'report_title' => ['required', 'string', 'max:255'],
            'certification_statement' => ['required', 'string', 'max:2000'],
            'footer_text' => ['nullable', 'string', 'max:1000'],
            'left_logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'right_logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $template = ReportTemplate::firstOrCreate(['user_id' => $request->user()->id], ['footer_text' => ReportTemplate::DEFAULT_FOOTER]);
        $attributes = Arr::except($data, ['left_logo', 'right_logo']);

        foreach (['left_logo', 'right_logo'] as $logo) {
            if ($request->hasFile($logo)) {
                $pathColumn = $logo.'_path';
                if ($template->{$pathColumn}) {
                    Storage::delete($template->{$pathColumn});
                }
                $attributes[$pathColumn] = $request->file($logo)->store('report-logos');
            }
        }

        $template->update($attributes);

        return back()->with('status', 'Report template updated.');
    }
}
