<?php

namespace App\Http\Requests\Admin;

use App\Services\SiteSettings;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSiteSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $rules = [
            // No < or > and no control characters, so the name can never carry markup into an e-mail or page.
            'site_name' => ['required', 'string', 'min:2', 'max:60', 'not_regex:/[<>\x00-\x1F]/'],
            // Real images only. SVG is refused on purpose: an SVG file can carry scripts.
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:max_width=2000,max_height=2000'],
            'favicon' => ['nullable', 'file', 'mimes:png,ico', 'max:256'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_favicon' => ['nullable', 'boolean'],
        ];

        foreach (SiteSettings::COLOR_KEYS as $key) {
            $rules['color_'.$key] = ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'site_name' => 'website name',
            'color_primary' => 'button colour',
            'color_page' => 'page background colour',
            'color_nav' => 'menu bar colour',
            'color_header' => 'page title bar colour',
            'color_panel' => 'boxes and panels colour',
            'color_footer' => 'footer colour',
            'color_card' => 'game card colour',
            'color_accent' => 'game card picture colour',
        ];
    }
}