<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    /** A real 1 x 1 pixel PNG, so no image library is needed. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role->value])->save();

        return $user;
    }

    private function superAdmin(): User
    {
        return $this->userWithRole(UserRole::SuperAdmin);
    }

    /** @param array<string, mixed> $override */
    private function payload(array $override = []): array
    {
        return array_merge([
            'site_name' => 'Neon Games',
            'color_primary' => '#e11d48',
            'color_page' => '#f3f4f6',
            'color_nav' => '#ffffff',
            'color_header' => '#ffffff',
            'color_panel' => '#ffffff',
            'color_footer' => '#ffffff',
            'color_card' => '#ffffff',
            'color_accent' => '#ffe4e6',
        ], $override);
    }

    private function png(string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    private function settings(): SiteSettings
    {
        $settings = app(SiteSettings::class);
        $settings->refresh();

        return $settings;
    }

    // ---- wiring ----

    public function test_the_provider_is_registered_and_the_settings_are_shared(): void
    {
        $this->assertNotEmpty(
            app()->getProviders(\App\Providers\SiteSettingsServiceProvider::class),
            'Add App\\Providers\\SiteSettingsServiceProvider::class to bootstrap/providers.php (see SITE-SETTINGS-README.md).'
        );
        $this->assertSame(app(SiteSettings::class), app(SiteSettings::class));
    }

    // ---- who may open and change it ----

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get(route('admin.settings.edit'))->assertRedirect(route('login'));
        $this->post(route('admin.settings.update'), $this->payload())->assertRedirect(route('login'));
        $this->post(route('admin.settings.reset'))->assertRedirect(route('login'));
    }

    public function test_customers_cannot_open_or_change_it(): void
    {
        $customer = $this->userWithRole(UserRole::User);

        $this->actingAs($customer)->get(route('admin.settings.edit'))->assertForbidden();
        $this->actingAs($customer)->post(route('admin.settings.update'), $this->payload())->assertForbidden();
        $this->actingAs($customer)->post(route('admin.settings.reset'))->assertForbidden();
    }

    public function test_only_the_super_admin_may_change_it(): void
    {
        foreach ([UserRole::Support, UserRole::Admin] as $role) {
            $staff = $this->userWithRole($role);

            $this->actingAs($staff)->get(route('admin.settings.edit'))->assertForbidden();
            $this->actingAs($staff)->post(route('admin.settings.update'), $this->payload())->assertForbidden();
        }

        $this->assertNotSame('Neon Games', $this->settings()->siteName());

        $this->actingAs($this->superAdmin())->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('Site settings');
    }

    public function test_the_settings_link_is_shown_only_to_the_super_admin(): void
    {
        $this->actingAs($this->superAdmin())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.settings.edit'), false);

        $this->actingAs($this->userWithRole(UserRole::Admin))->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee(route('admin.settings.edit'), false);
    }

    // ---- name and colours ----

    public function test_a_new_name_and_colours_appear_on_the_website(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.update'), $this->payload())
            ->assertRedirect(route('admin.settings.edit'))
            ->assertSessionHasNoErrors();

        $this->get('/')
            ->assertOk()
            ->assertSee('Neon Games')
            ->assertSee('--c-primary:#e11d48;', false)
            ->assertSee('--c-accent:#ffe4e6', false);
    }

    public function test_panel_and_title_bar_colours_are_applied_with_readable_text(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.update'), $this->payload([
                'color_page' => '#2f2f2f',
                'color_panel' => '#1f2937',
                'color_header' => '#111827',
            ]))
            ->assertSessionHasNoErrors();

        $this->get('/')
            ->assertOk()
            ->assertSee('--c-page-ink:#ffffff;', false)
            ->assertSee('--c-panel:#1f2937;', false)
            ->assertSee('--c-panel-ink:#ffffff;', false)
            ->assertSee('--c-header:#111827;', false)
            ->assertSee('--c-header-ink:#ffffff;', false);
    }

    public function test_the_soft_shade_moves_toward_the_text_colour(): void
    {
        $this->assertSame('#f1f1f2', $this->settings()->mix('#ffffff', '#111827', 0.06));
        $this->assertSame('#ff0000', $this->settings()->mix('#ff0000', 'nope', 0.5));
    }

    public function test_the_name_is_trimmed_and_escaped_on_the_page(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.update'), $this->payload(['site_name' => '  Keo & Sons  ']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Keo & Sons', $this->settings()->siteName());
        $this->get('/')->assertSee('Keo &amp; Sons', false);
    }

    public function test_every_change_is_written_to_the_audit_log(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.update'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertTrue(AuditLog::query()->where('action', 'settings.branding_updated')->exists());
    }

    #[DataProvider('badColours')]
    public function test_a_bad_colour_is_rejected_and_nothing_is_saved(string $bad): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.update'), $this->payload(['color_primary' => $bad]))
            ->assertSessionHasErrors('color_primary');

        $this->assertSame(0, SiteSetting::query()->count());
    }

    /** @return array<string, array<int, string>> */
    public static function badColours(): array
    {
        return [
            'colour word' => ['red'],
            'too short' => ['#12345'],
            'not hex' => ['#GGGGGG'],
            'css function' => ['url(x)'],
            'css break out' => ['#ff0000;}body{display:none'],
            'script' => ['<script>alert(1)</script>'],
            'empty' => [''],
        ];
    }

    #[DataProvider('badNames')]
    public function test_a_bad_name_is_rejected(string $bad): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.update'), $this->payload(['site_name' => $bad]))
            ->assertSessionHasErrors('site_name');

        $this->assertSame(0, SiteSetting::query()->count());
    }

    /** @return array<string, array<int, string>> */
    public static function badNames(): array
    {
        return [
            'empty' => [''],
            'one letter' => ['A'],
            'too long' => [str_repeat('a', 61)],
            'html' => ['<script>alert(1)</script>'],
            'angle bracket' => ['Shop >'],
        ];
    }

    public function test_a_tampered_colour_in_the_database_is_never_printed(): void
    {
        SiteSetting::query()->create(['key' => 'color_primary', 'value' => 'red;}</style><script>alert(1)</script>']);
        $this->settings();

        $this->get('/')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('--c-primary:'.SiteSettings::DEFAULT_COLORS['primary'].';', false);
    }

    public function test_text_colour_is_chosen_for_contrast(): void
    {
        $settings = $this->settings();

        $this->assertSame('#111827', $settings->readableOn('#ffffff'));
        $this->assertSame('#111827', $settings->readableOn('#ffff00'));
        $this->assertSame('#ffffff', $settings->readableOn('#000000'));
        $this->assertSame('#ffffff', $settings->readableOn('#1e1b4b'));
    }

    // ---- logo and favicon ----

    public function test_a_logo_can_be_uploaded_and_is_shown(): void
    {
        Storage::fake('public');

        $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.update'), $this->payload(['logo' => $this->png()]))
            ->assertSessionHasNoErrors();

        $path = $this->settings()->path('logo');
        $this->assertNotNull($path);
        $this->assertMatchesRegularExpression(SiteSettings::FILE_PATTERN, $path);
        Storage::disk('public')->assertExists($path);

        $this->get('/')->assertOk()->assertSee(Storage::disk('public')->url($path), false);
    }

    public function test_the_logo_is_also_shown_inside_the_customer_menu(): void
    {
        Storage::fake('public');

        $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.update'), $this->payload(['logo' => $this->png()]))
            ->assertSessionHasNoErrors();

        $url = Storage::disk('public')->url((string) $this->settings()->path('logo'));

        $this->actingAs($this->userWithRole(UserRole::User))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($url, false);
    }

    public function test_a_favicon_can_be_uploaded_and_is_linked(): void
    {
        Storage::fake('public');

        $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.update'), $this->payload(['favicon' => $this->png('icon.png')]))
            ->assertSessionHasNoErrors();

        $path = $this->settings()->path('favicon');
        $this->assertNotNull($path);

        $this->get('/')->assertSee('<link rel="icon" href="'.Storage::disk('public')->url($path).'">', false);
    }

    public function test_replacing_a_logo_deletes_the_old_file(): void
    {
        Storage::fake('public');
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.settings.update'), $this->payload(['logo' => $this->png()]));
        $first = $this->settings()->path('logo');

        $this->actingAs($admin)->post(route('admin.settings.update'), $this->payload(['logo' => $this->png('second.png')]));
        $second = $this->settings()->path('logo');

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_saving_without_a_file_keeps_the_current_logo(): void
    {
        Storage::fake('public');
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.settings.update'), $this->payload(['logo' => $this->png()]));
        $path = $this->settings()->path('logo');

        $this->actingAs($admin)->post(route('admin.settings.update'), $this->payload(['site_name' => 'Other Name']));

        $this->assertSame($path, $this->settings()->path('logo'));
        Storage::disk('public')->assertExists($path);
    }

    public function test_a_logo_can_be_removed(): void
    {
        Storage::fake('public');
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.settings.update'), $this->payload(['logo' => $this->png()]));
        $path = $this->settings()->path('logo');

        $this->actingAs($admin)->post(route('admin.settings.update'), $this->payload(['remove_logo' => '1']));

        $this->assertNull($this->settings()->path('logo'));
        Storage::disk('public')->assertMissing($path);
    }

    public function test_svg_scripts_and_non_images_are_refused(): void
    {
        Storage::fake('public');
        $admin = $this->superAdmin();

        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>');
        $php = UploadedFile::fake()->createWithContent('logo.png', '<?php echo "pwned";');
        $text = UploadedFile::fake()->createWithContent('logo.jpg', 'just some text');

        foreach ([$svg, $php, $text] as $file) {
            $this->actingAs($admin)
                ->post(route('admin.settings.update'), $this->payload(['logo' => $file]))
                ->assertSessionHasErrors('logo');
        }

        $this->assertNull($this->settings()->path('logo'));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_tampered_image_path_in_the_database_is_ignored(): void
    {
        SiteSetting::query()->create(['key' => 'logo_path', 'value' => '../../.env']);
        SiteSetting::query()->create(['key' => 'favicon_path', 'value' => 'branding/../../secret.png']);
        $settings = $this->settings();

        $this->assertNull($settings->path('logo'));
        $this->assertNull($settings->logoUrl());
        $this->assertNull($settings->faviconUrl());
    }

    // ---- reset ----

    public function test_reset_brings_back_the_defaults_and_deletes_the_images(): void
    {
        Storage::fake('public');
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.settings.update'), $this->payload(['logo' => $this->png()]));
        $path = $this->settings()->path('logo');
        $this->assertNotNull($path);

        $this->actingAs($admin)
            ->post(route('admin.settings.reset'))
            ->assertRedirect(route('admin.settings.edit'));

        $settings = $this->settings();
        $this->assertSame(0, SiteSetting::query()->count());
        $this->assertNull($settings->path('logo'));
        $this->assertSame(SiteSettings::DEFAULT_COLORS, $settings->colors());
        $this->assertNotSame('Neon Games', $settings->siteName());
        Storage::disk('public')->assertMissing($path);
        $this->assertTrue(AuditLog::query()->where('action', 'settings.branding_reset')->exists());
    }

    // ---- safety net ----

    public function test_unknown_setting_names_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(SiteSettings::class)->set(['app_key' => 'x']);
    }
}