<?php

namespace App\Services;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * The shop's look: name, logo, favicon, home banner picture, banner slider, contact links and colours, edited by the super admin.
 *
 * Safety rules:
 *  - every value is checked again when it is READ, so a hand-edited database row can never inject CSS, a file path or a bad link;
 *  - if the table does not exist yet (before "php artisan migrate") the defaults are used and nothing breaks.
 */
class SiteSettings
{
    public const COLOR_KEYS = ['primary', 'page', 'nav', 'header', 'panel', 'footer', 'card', 'accent'];

    public const DEFAULT_COLORS = [
        'primary' => '#4f46e5', // buttons, links, active menu
        'page' => '#f3f4f6',    // page background
        'nav' => '#ffffff',     // top menu bar
        'header' => '#ffffff',  // page title bar under the menu
        'panel' => '#ffffff',   // white boxes: dashboard panels, tables, dropdown menus, buttons
        'footer' => '#ffffff',  // footer
        'card' => '#ffffff',    // game card background
        'accent' => '#eef2ff',  // game card picture area
    ];

    public const FILE_PATTERN = '#^branding/[A-Za-z0-9]{20,64}\.(png|jpg|jpeg|webp|ico)$#';

    /** Only https links to Telegram / Facebook are accepted, so a contact button can never point somewhere else. */
    public const TELEGRAM_PATTERN = '#^https://(t\.me|telegram\.me)/[A-Za-z0-9_+\-./]{2,100}$#';

    public const FACEBOOK_PATTERN = '#^https://((www|m|web)\.)?(facebook\.com|fb\.com|fb\.me|m\.me)/[A-Za-z0-9_.\-/?=&%+]{1,150}$#';

    /** The scrolling line under the banner, until the admin saves their own words. */
    public const DEFAULT_MARQUEE = 'Fast and safe game top-up. Pay by QR and get your diamonds in minutes.';

    /** How many pictures the home page banner slider can hold. */
    public const SLIDE_COUNT = 5;

    /** A slide link is a full https address or a path on this site (never javascript:, data: or //host). */
    public const SLIDE_LINK_PATTERN = '~^(https://[A-Za-z0-9.\-]{3,100}(/[A-Za-z0-9_\-./?=&%+:]{0,200})?|/(?!/)[A-Za-z0-9_\-./?=&%+:]{0,200})$~';

    /** Shown as grey hints in the admin form. Links equal to these are treated as "not set yet" and stay hidden. */
    public const EXAMPLE_TELEGRAM = 'https://t.me/your_telegram';

    public const EXAMPLE_FACEBOOK = 'https://www.facebook.com/your.page';

    private const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    private const ALLOWED = [
        'site_name', 'logo_path', 'favicon_path', 'banner_path', 'telegram_url', 'facebook_url',
        'slide1_path', 'slide2_path', 'slide3_path', 'slide4_path', 'slide5_path',
        'slide1_link', 'slide2_link', 'slide3_link', 'slide4_link', 'slide5_link',
        'marquee_text', 'marquee_text_km',
        'color_primary', 'color_page', 'color_nav', 'color_header', 'color_panel', 'color_footer', 'color_card', 'color_accent',
    ];

    private ?array $loaded = null;

    /**
     * The name from .env, remembered the first time this class is used in a process, before any saved name
     * is put into config('app.name'). Static on purpose: it stays correct even if the class is created more than once.
     */
    private static ?string $originalName = null;

    private readonly string $defaultName;

    public function __construct()
    {
        self::$originalName ??= (string) config('app.name', 'Laravel');

        $this->defaultName = self::$originalName;
    }

    public function siteName(): string
    {
        $name = trim((string) ($this->raw('site_name') ?? ''));

        return $name !== '' ? $name : $this->defaultName;
    }

    public function color(string $key): string
    {
        $value = $this->raw('color_'.$key);

        if (is_string($value) && preg_match(self::COLOR_PATTERN, $value) === 1) {
            return strtolower($value);
        }

        return self::DEFAULT_COLORS[$key] ?? '#000000';
    }

    /** @return array<string, string> */
    public function colors(): array
    {
        $colors = [];
        foreach (self::COLOR_KEYS as $key) {
            $colors[$key] = $this->color($key);
        }

        return $colors;
    }

    /** Black or white text, whichever is easier to read on this background colour. */
    public function readableOn(string $hex): string
    {
        if (preg_match(self::COLOR_PATTERN, $hex) !== 1) {
            return '#111827';
        }

        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));

        return ((0.299 * $r) + (0.587 * $g) + (0.114 * $b)) / 255 > 0.6 ? '#111827' : '#ffffff';
    }

    /** A colour moved part of the way toward another one (used for the soft shade of table headers). */
    public function mix(string $hex, string $with, float $ratio): string
    {
        if (preg_match(self::COLOR_PATTERN, $hex) !== 1 || preg_match(self::COLOR_PATTERN, $with) !== 1) {
            return $hex;
        }

        $out = '#';
        foreach ([1, 3, 5] as $i) {
            $from = hexdec(substr($hex, $i, 2));
            $to = hexdec(substr($with, $i, 2));
            $out .= str_pad(dechex((int) round($from + (($to - $from) * $ratio))), 2, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    /** The stored image path, only if it looks exactly like a file this feature saved. */
    public function path(string $which): ?string
    {
        $value = $this->raw($which.'_path');

        return is_string($value) && preg_match(self::FILE_PATTERN, $value) === 1 ? $value : null;
    }

    public function logoUrl(): ?string
    {
        return $this->url($this->path('logo'));
    }

    public function faviconUrl(): ?string
    {
        return $this->url($this->path('favicon'));
    }

    /** The picture uploaded for the home page banner, or null to show the built-in boy gamer. */
    public function bannerUrl(): ?string
    {
        return $this->url($this->path('banner'));
    }

    /**
     * The home page banner slides that have a valid picture, in order.
     *
     * @return list<array{slot:int,url:string,link:?string}>
     */
    public function slides(): array
    {
        $slides = [];

        for ($slot = 1; $slot <= self::SLIDE_COUNT; $slot++) {
            $url = $this->slideUrl($slot);

            if ($url !== null) {
                $slides[] = ['slot' => $slot, 'url' => $url, 'link' => $this->slideLink($slot)];
            }
        }

        return $slides;
    }

    public function slideUrl(int $slot): ?string
    {
        return ($slot >= 1 && $slot <= self::SLIDE_COUNT) ? $this->url($this->path('slide'.$slot)) : null;
    }

    /** The click-through link of a slide, or null when none is saved or it does not look safe. */
    public function slideLink(int $slot): ?string
    {
        if ($slot < 1 || $slot > self::SLIDE_COUNT) {
            return null;
        }

        $value = trim((string) ($this->raw('slide'.$slot.'_link') ?? ''));

        return ($value !== '' && preg_match(self::SLIDE_LINK_PATTERN, $value) === 1) ? $value : null;
    }

    /**
     * The scrolling text under the banner in the visitor's language, or null when the admin switched it off
     * (saved an empty English box). Khmer visitors get the Khmer box, else the English one.
     */
    public function marquee(): ?string
    {
        $en = $this->raw('marquee_text');

        if ($en !== null && trim($en) === '') {
            return null;
        }

        $text = null;

        if (app()->getLocale() === 'km') {
            $km = trim((string) ($this->raw('marquee_text_km') ?? ''));
            $text = $km !== '' ? $km : null;
        }

        $text ??= ($en === null) ? self::DEFAULT_MARQUEE : trim($en);

        if ($text === self::DEFAULT_MARQUEE) {
            return __(self::DEFAULT_MARQUEE);
        }

        return mb_substr($text, 0, 300);
    }

    /** The Telegram link, or null when none (or only the example) has been saved. */
    public function telegramUrl(): ?string
    {
        return $this->link('telegram_url', self::TELEGRAM_PATTERN, self::EXAMPLE_TELEGRAM);
    }

    /** The Facebook page link, or null when none (or only the example) has been saved. */
    public function facebookUrl(): ?string
    {
        return $this->link('facebook_url', self::FACEBOOK_PATTERN, self::EXAMPLE_FACEBOOK);
    }

    public function hasContact(): bool
    {
        return $this->telegramUrl() !== null || $this->facebookUrl() !== null;
    }

    /** The raw saved text of a setting, for filling the admin form (never used on public pages). */
    public function value(string $key): ?string
    {
        return in_array($key, self::ALLOWED, true) ? $this->raw($key) : null;
    }

    /** Everything stored, for the audit log. @return array<string, string|null> */
    public function snapshot(): array
    {
        $snapshot = [];
        foreach (self::ALLOWED as $key) {
            $snapshot[$key] = $this->raw($key);
        }

        return $snapshot;
    }

    /**
     * Save values. A null value removes that setting (so the default is used again).
     *
     * @param  array<string, string|null>  $values
     */
    public function set(array $values): void
    {
        foreach (array_keys($values) as $key) {
            if (! in_array($key, self::ALLOWED, true)) {
                throw new InvalidArgumentException('Unknown setting: '.$key);
            }
        }

        DB::transaction(function () use ($values) {
            foreach ($values as $key => $value) {
                if ($value === null) {
                    SiteSetting::query()->where('key', $key)->delete();
                } else {
                    SiteSetting::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
                }
            }
        });

        $this->refresh();
    }

    /** Forget everything: the website goes back to its defaults. */
    public function clear(): void
    {
        SiteSetting::query()->delete();

        $this->refresh();
    }

    /** Re-read the database on the next call and re-apply the name. */
    public function refresh(): void
    {
        $this->loaded = null;
        $this->applyToConfig();
    }

    /** Titles, the footer and e-mails read config('app.name'), so the chosen name is put there. */
    public function applyToConfig(): void
    {
        config(['app.name' => $this->siteName()]);
    }

    private function link(string $key, string $pattern, string $example): ?string
    {
        $value = $this->raw($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return ($value !== $example && preg_match($pattern, $value) === 1) ? $value : null;
    }

    private function raw(string $key): ?string
    {
        if ($this->loaded === null) {
            try {
                $this->loaded = SiteSetting::query()->pluck('value', 'key')->all();
            } catch (Throwable) {
                // Table missing (migration not run yet): use defaults and try again next time.
                return null;
            }
        }

        $value = $this->loaded[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    private function url(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        try {
            return Storage::disk('public')->url($path);
        } catch (Throwable) {
            return null;
        }
    }
}
