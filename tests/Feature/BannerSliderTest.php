<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BannerSliderTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::SuperAdmin->value])->save();

        return $user;
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

    private function file(string $letter, string $ext = 'png'): string
    {
        return 'branding/'.str_repeat($letter, 40).'.'.$ext;
    }

    public function test_there_is_no_slider_until_a_picture_is_uploaded(): void
    {
        $this->get('/')->assertOk()->assertDontSee('gg-slider', false);
    }

    public function test_saved_slides_are_shown_in_order_with_their_links(): void
    {
        app(SiteSettings::class)->set([
            'slide1_path' => $this->file('a'),
            'slide2_path' => $this->file('b', 'jpg'),
            'slide2_link' => 'https://t.me/keotopup',
            'slide4_path' => $this->file('d', 'webp'),
            'slide4_link' => '/games/mobile-legends-global',
        ]);

        $html = $this->get('/')->assertOk()->assertSee('gg-slider', false)->getContent();

        $this->assertStringContainsString(str_repeat('a', 40), $html);
        $this->assertStringContainsString('href="https://t.me/keotopup"', $html);
        $this->assertStringContainsString('href="/games/mobile-legends-global"', $html);
        $this->assertStringNotContainsString(str_repeat('c', 40), $html);
        $this->assertLessThan(strpos($html, str_repeat('b', 40)), strpos($html, str_repeat('a', 40)));
        $this->assertLessThan(strpos($html, str_repeat('d', 40)), strpos($html, str_repeat('b', 40)));
        $this->assertSame(3, substr_count($html, 'class="gg-dot"'));
    }

    public function test_one_picture_stays_still(): void
    {
        app(SiteSettings::class)->set(['slide1_path' => $this->file('a')]);

        $this->get('/')
            ->assertOk()
            ->assertSee('gg-static', false)
            ->assertDontSee('class="gg-dot"', false)
            ->assertDontSee('@keyframes ggslide', false);
    }

    public function test_a_tampered_slide_link_or_path_is_never_used(): void
    {
        app(SiteSettings::class)->set([
            'slide1_path' => $this->file('a'),
            'slide1_link' => 'javascript:alert(1)',
            'slide2_path' => '../../.env',
            'slide3_path' => $this->file('c'),
            'slide3_link' => '//evil.example/x',
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('javascript:alert', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringNotContainsString('.env', $html);
        $this->assertSame(2, substr_count($html, 'class="gg-dot"'));
    }

    public function test_an_admin_can_upload_slides_with_links_and_remove_them(): void
    {
        Storage::fake('public');
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.settings.update'), $this->payload([
            'slide1' => UploadedFile::fake()->image('one.png', 1600, 600),
            'slide1_link' => '/games',
            'slide3' => UploadedFile::fake()->image('three.jpg', 1600, 600),
        ]))->assertSessionHasNoErrors()->assertRedirect(route('admin.settings.edit'));

        $settings = app(SiteSettings::class);
        $settings->refresh();

        $this->assertNotNull($settings->slideUrl(1));
        $this->assertNull($settings->slideUrl(2));
        $this->assertNotNull($settings->slideUrl(3));
        $this->assertSame('/games', $settings->slideLink(1));
        $this->assertCount(2, $settings->slides());

        $path = $settings->path('slide1');
        Storage::disk('public')->assertExists($path);

        // The settings page shows the saved link again.
        $this->actingAs($admin)->get(route('admin.settings.edit'))->assertOk()->assertSee('value="/games"', false);

        // Removing a picture deletes the file too.
        $this->actingAs($admin)->post(route('admin.settings.update'), $this->payload([
            'slide1_link' => '/games',
            'remove_slide1' => '1',
        ]))->assertSessionHasNoErrors();

        $settings->refresh();
        $this->assertNull($settings->slideUrl(1));
        $this->assertNotNull($settings->slideUrl(3));
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_wrong_slide_link_or_file_is_refused(): void
    {
        Storage::fake('public');
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.settings.update'), $this->payload([
            'slide2_link' => 'http://insecure.example/page',
        ]))->assertSessionHasErrors('slide2_link');

        $this->actingAs($admin)->post(route('admin.settings.update'), $this->payload([
            'slide1' => UploadedFile::fake()->create('virus.svg', 10, 'image/svg+xml'),
        ]))->assertSessionHasErrors('slide1');

        $this->assertSame([], app(SiteSettings::class)->slides());
    }

    public function test_game_lists_use_two_columns_on_phones_and_three_on_pc(): void
    {
        $this->get('/')->assertOk()->assertSee('grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4', false);
        $this->get(route('games.index'))->assertOk()->assertSee('grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4', false);
    }
}
