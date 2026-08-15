<?php

namespace Tests\Feature;

use Tests\TestCase;

class IndexingSafetyTest extends TestCase
{
    public function test_home_is_noindex_by_default(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function test_robots_disallows_crawling_by_default(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee("User-agent: *\nDisallow: /", false);
    }

    public function test_indexing_must_be_explicitly_enabled(): void
    {
        config(['seo.indexing_enabled' => true]);

        $response = $this->get('/');

        $response->assertOk()
            ->assertHeaderMissing('X-Robots-Tag')
            ->assertSee('<meta name="robots" content="index, follow">', false);
    }

    public function test_health_endpoint_does_not_require_a_database(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'iglikefollow');
    }
}
