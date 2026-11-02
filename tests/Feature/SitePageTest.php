<?php

namespace Tests\Feature;

use Tests\TestCase;

class SitePageTest extends TestCase
{
    public function test_landing_page_renders_with_key_sections(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('Know where the workday actually happened.', false)
            ->assertSee('Vehicle meter readings', false)
            ->assertSee('One shared directory of shops and dealers', false)
            ->assertSee('Privacy', false)
            ->assertSee('Terms', false);

        $this->assertStringContainsString('css/site.css', $response->getContent());
    }

    public function test_privacy_policy_page_renders_with_required_disclosures(): void
    {
        $response = $this->get('/privacy');

        $response->assertOk()
            ->assertSee('Privacy policy', false)
            ->assertSee("vehicle's meter", false)
            ->assertSee('Location tracking', false)
            ->assertSee('How long we keep it', false)
            ->assertSee('Who can see it', false);
    }

    public function test_terms_page_renders_with_required_sections(): void
    {
        $response = $this->get('/terms');

        $response->assertOk()
            ->assertSee('Terms and conditions', false)
            ->assertSee('Acceptable use', false)
            ->assertSee('shop and dealer directory', false)
            ->assertSee('Liability', false);
    }

    public function test_public_pages_do_not_require_authentication_or_redirect(): void
    {
        // No session, no sanctum guard: these must answer directly with 200.
        foreach (['/', '/privacy', '/terms'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertDontSee('login', false);
        }
    }

    public function test_site_links_between_the_three_pages(): void
    {
        $landing = $this->get('/')->getContent();

        $this->assertStringContainsString('/privacy', $landing);
        $this->assertStringContainsString('/terms', $landing);

        $privacy = $this->get('/privacy')->getContent();
        $this->assertStringContainsString('/terms', $privacy);
    }
}
