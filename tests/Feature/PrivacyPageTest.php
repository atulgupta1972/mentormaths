<?php

namespace Tests\Feature;

use Tests\TestCase;

class PrivacyPageTest extends TestCase
{
    public function test_privacy_page_is_publicly_accessible(): void
    {
        $this->withoutVite()
            ->get(route('privacy'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Privacy'));
    }
}
