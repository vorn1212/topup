<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactAndBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_page_says_coming_soon_when_no_links_are_set(): void
    {
        $this->get('/contact')
            ->assertOk()
            ->assertSee('Contact details are coming soon.')
            ->assertDontSee('t.me/');
    }

    public function test_saved_links_become_buttons_on_contact_page_and_footer(): void
    {
        app(SiteSettings::class)->set([
            'telegram_url' => 'https://t.me/keotopup',
            'facebook_url' => 'https://www.facebook.com/keotopup',
        ]);

        $this->get('/contact')
            ->assertOk()
            ->assertSee('href="https://t.me/keotopup"', false)
            ->assertSee('href="https://www.facebook.com/keotopup"', false);

        $this->get('/')
            ->assertOk()
            ->assertSee('href="https://t.me/keotopup"', false);
    }

    public function test_example_links_stay_hidden_until_real_ones_are_saved(): void
    {
        app(SiteSettings::class)->set([
            'telegram_url' => SiteSettings::EXAMPLE_TELEGRAM,
            'facebook_url' => SiteSettings::EXAMPLE_FACEBOOK,
        ]);

        $this->assertFalse(app(SiteSettings::class)->hasContact());
        $this->get('/contact')->assertSee('Contact details are coming soon.');
    }

    public function test_a_tampered_link_is_never_shown(): void
    {
        app(SiteSettings::class)->set([
            'telegram_url' => 'javascript:alert(1)',
            'facebook_url' => 'https://evil.example/www.facebook.com/x',
        ]);

        $this->assertNull(app(SiteSettings::class)->telegramUrl());
        $this->assertNull(app(SiteSettings::class)->facebookUrl());
        $this->get('/contact')->assertDontSee('javascript:alert', false);
    }

    public function test_only_the_link_that_is_set_is_shown(): void
    {
        app(SiteSettings::class)->set(['facebook_url' => 'https://www.facebook.com/keotopup']);

        $this->get('/contact')
            ->assertSee('href="https://www.facebook.com/keotopup"', false)
            ->assertDontSee('t.me/');
    }

    public function test_home_page_shows_the_built_in_character_by_default(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('gg-character', false)
            ->assertSee('gaming-bg', false);
    }

    public function test_home_page_uses_the_uploaded_banner_picture(): void
    {
        $path = 'branding/'.str_repeat('a', 40).'.png';
        app(SiteSettings::class)->set(['banner_path' => $path]);

        $this->get('/')
            ->assertOk()
            ->assertSee($path, false)
            ->assertDontSee('gg-character', false);
    }

    public function test_a_tampered_banner_path_falls_back_to_the_built_in_character(): void
    {
        app(SiteSettings::class)->set(['banner_path' => '../../.env']);

        $this->get('/')->assertOk()->assertSee('gg-character', false);
    }

    public function test_signed_in_pages_have_the_animated_background_too(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('gaming-bg', false)
            ->assertDontSee('<body class="gaming-bg gaming-calm', false);
    }
}
