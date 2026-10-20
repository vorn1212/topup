<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSiteSettingsRequest;
use App\Services\AuditService;
use App\Services\SiteSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Website name, logo, favicon, home banner picture, contact links and colours.
 * Super admin only (settings.manage); every change is audited.
 */
class SiteSettingsController extends Controller
{
    private const EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'ico'];

    /** Pictures that can be uploaded or removed here. */
    private const PICTURES = ['logo', 'favicon', 'banner'];

    public function edit(SiteSettings $settings): View
    {
        return view('admin.settings.edit', ['settings' => $settings]);
    }

    public function update(UpdateSiteSettingsRequest $request, SiteSettings $settings, AuditService $audit): RedirectResponse
    {
        $data = $request->validated();
        $old = $settings->snapshot();

        $values = ['site_name' => trim((string) $data['site_name'])];
        foreach (SiteSettings::COLOR_KEYS as $key) {
            $values['color_'.$key] = strtolower((string) $data['color_'.$key]);
        }

        // An empty box removes the link (null), so that button disappears from the website.
        foreach (['telegram_url', 'facebook_url'] as $key) {
            $link = trim((string) ($data[$key] ?? ''));
            $values[$key] = $link !== '' ? $link : null;
        }

        $staleFiles = [];
        $newFiles = [];

        foreach (self::PICTURES as $which) {
            $current = $settings->path($which);

            if ($request->hasFile($which)) {
                $stored = $this->store($request->file($which), $which);
                $values[$which.'_path'] = $stored;
                $newFiles[] = $stored;
                if ($current !== null) {
                    $staleFiles[] = $current;
                }
            } elseif ($request->boolean('remove_'.$which)) {
                $values[$which.'_path'] = null;
                if ($current !== null) {
                    $staleFiles[] = $current;
                }
            }
        }

        try {
            $settings->set($values);
        } catch (\Throwable $e) {
            // Do not leave orphan files behind if the database write failed.
            foreach ($newFiles as $path) {
                Storage::disk('public')->delete($path);
            }
            throw $e;
        }

        // Old files are deleted only after the new settings are safely saved.
        foreach ($staleFiles as $path) {
            Storage::disk('public')->delete($path);
        }

        $audit->log('settings.branding_updated', null, $old, $settings->snapshot());

        return redirect()->route('admin.settings.edit')->with('status', 'Site settings saved.');
    }

    public function reset(SiteSettings $settings, AuditService $audit): RedirectResponse
    {
        $old = $settings->snapshot();

        foreach (self::PICTURES as $which) {
            $path = $settings->path($which);
            if ($path !== null) {
                Storage::disk('public')->delete($path);
            }
        }

        $settings->clear();

        $audit->log('settings.branding_reset', null, $old, $settings->snapshot());

        return redirect()->route('admin.settings.edit')->with('status', 'Site settings were reset to the defaults.');
    }

    private function store(UploadedFile $file, string $field): string
    {
        // The extension comes from the file's real content, never from the name the browser sent.
        $extension = strtolower((string) $file->guessExtension());

        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw ValidationException::withMessages([$field => 'This image type is not allowed.']);
        }

        $path = $file->storeAs('branding', Str::random(40).'.'.$extension, 'public');

        if (! is_string($path)) {
            throw ValidationException::withMessages([$field => 'The image could not be saved. Please try again.']);
        }

        return $path;
    }
}
