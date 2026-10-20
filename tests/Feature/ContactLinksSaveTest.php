<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactLinksSaveTest extends TestCase
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

    public function test_the_settings_form_saves_both_links_and_the_buttons_appear(): void
    {
        $response = $this->actingAs($this->superAdmin())->post(route('admin.settings.update'), $this->payload([
            'telegram_url' => 'https://t.me/Reach_topup',
            'facebook_url' => 'https://www.facebook.com/2734587234345',
        ]));

        $response->assertSessionHasNoErrors()->assertRedirect(route('admin.settings.edit'));

        $settings = app(SiteSettings::class);
        $settings->refresh();
        $this->assertSame('https://t.me/Reach_topup', $settings->telegramUrl());
        $this->assertSame('https://www.facebook.com/2734587234345', $settings->facebookUrl());

        $this->get('/contact')
            ->assertOk()
            ->assertSee('href="https://t.me/Reach_topup"', false)
            ->assertSee('href="https://www.facebook.com/2734587234345"', false);
    }

    public function test_the_saved_links_are_filled_in_when_the_settings_page_is_opened_again(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post(route('admin.settings.update'), $this->payload(['telegram_url' => 'https://t.me/Reach_topup']));

        $this->actingAs($admin)->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('value="https://t.me/Reach_topup"', false);
    }

    public function test_an_empty_box_removes_the_link(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post(route('admin.settings.update'), $this->payload(['telegram_url' => 'https://t.me/Reach_topup']));
        $this->actingAs($admin)->post(route('admin.settings.update'), $this->payload(['telegram_url' => '']));

        $settings = app(SiteSettings::class);
        $settings->refresh();
        $this->assertNull($settings->telegramUrl());
    }

    public function test_a_wrong_link_is_refused_with_a_clear_message_and_nothing_is_saved(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.settings.update'), $this->payload([
            'telegram_url' => 'https://example.com/me',
        ]))->assertSessionHasErrors('telegram_url');

        $settings = app(SiteSettings::class);
        $settings->refresh();
        $this->assertNull($settings->telegramUrl());
    }
}
