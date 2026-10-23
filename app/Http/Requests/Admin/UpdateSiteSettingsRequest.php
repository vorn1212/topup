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
            'banner' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=2400,max_height=2400'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_favicon' => ['nullable', 'boolean'],
            'remove_banner' => ['nullable', 'boolean'],
            // Empty = no button. Only real https Telegram / Facebook addresses are allowed.
            'telegram_url' => ['nullable', 'string', 'max:200', 'regex:'.SiteSettings::TELEGRAM_PATTERN],
            'facebook_url' => ['nullable', 'string', 'max:250', 'regex:'.SiteSettings::FACEBOOK_PATTERN],
        ];

        // Banner slider: up to SLIDE_COUNT wide pictures, each with an optional link.
        for ($slot = 1; $slot <= SiteSettings::SLIDE_COUNT; $slot++) {
            $rules['slide'.$slot] = ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:3072', 'dimensions:max_width=3000,max_height=3000'];
            $rules['remove_slide'.$slot] = ['nullable', 'boolean'];
            $rules['slide'.$slot.'_link'] = ['nullable', 'string', 'max:300', 'regex:'.SiteSettings::SLIDE_LINK_PATTERN];
        }

        foreach (SiteSettings::COLOR_KEYS as $key) {
            $rules['color_'.$key] = ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $slides = [];
        for ($slot = 1; $slot <= SiteSettings::SLIDE_COUNT; $slot++) {
            $slides['slide'.$slot] = 'slide '.$slot.' picture';
            $slides['slide'.$slot.'_link'] = 'slide '.$slot.' link';
        }

        return $slides + [
            'site_name' => 'website name',
            'banner' => 'banner picture',
            'telegram_url' => 'Telegram link',
            'facebook_url' => 'Facebook page link',
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

    /** @return array<string, string> */
    public function messages(): array
    {
        $slides = [];
        for ($slot = 1; $slot <= SiteSettings::SLIDE_COUNT; $slot++) {
            $slides['slide'.$slot.'_link.regex'] = 'The slide '.$slot.' link must start with https:// or be a path on this site such as /games.';
        }

        return $slides + [
            'telegram_url.regex' => 'The Telegram link must start with https://t.me/ (for example https://t.me/yourname).',
            'facebook_url.regex' => 'The Facebook page link must start with https://www.facebook.com/ (for example https://www.facebook.com/yourpage).',
        ];
    }
}
