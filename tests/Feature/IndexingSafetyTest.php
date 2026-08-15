<?php

namespace Tests\Feature;

use App\Support\IndexingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class IndexingSafetyTest extends TestCase
{
    // 前台已改讀資料庫，故需要 schema；⛔ 測試使用 :memory:，不動開發資料庫。
    use RefreshDatabase;

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

    public function test_indexing_requires_production_flag_and_exact_host(): void
    {
        config([
            'app.env' => 'production',
            'seo.allow_indexing' => true,
            'seo.indexable_host' => 'www.iglikefollow.com',
        ]);

        $request = Request::create('https://www.iglikefollow.com/');

        $this->assertTrue(app(IndexingPolicy::class)->allows($request));
    }

    public function test_cached_production_flags_still_fail_closed_on_staging_host(): void
    {
        config([
            'app.env' => 'production',
            'seo.allow_indexing' => true,
            'seo.indexable_host' => 'www.iglikefollow.com',
        ]);

        $this->withHeader('Host', 'staging.iglikefollow.com')
            ->get('/')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);

        $this->withHeader('Host', 'staging.iglikefollow.com')
            ->get('/robots.txt')
            ->assertOk()
            ->assertSee("User-agent: *\nDisallow: /", false);
    }

    public function test_flag_alone_cannot_enable_indexing_outside_production(): void
    {
        config([
            'app.env' => 'staging',
            'seo.allow_indexing' => true,
            'seo.indexable_host' => 'www.iglikefollow.com',
        ]);

        $this->withHeader('Host', 'www.iglikefollow.com')
            ->get('/')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_health_endpoint_does_not_require_a_database(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'iglikefollow');
    }
}
