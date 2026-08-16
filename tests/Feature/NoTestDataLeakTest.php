<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\Service;
use App\Models\ServiceVariant;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The catalogue must never contain Faker placeholder text.
 *
 * Factories generate Latin filler ("Aliquid", "Ex ducimus"). The test suite
 * runs against an in-memory database so it cannot reach real data, but an
 * ad-hoc `php artisan tinker` call does write to the development database —
 * which is how a placeholder platform once appeared in the admin. This
 * asserts the seeded catalogue is clean, and gives the same check a name so
 * anyone can run it against a real database when something looks wrong.
 */
class NoTestDataLeakTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Common Faker Latin words. Deliberately short and lowercase-compared:
     * these never occur in genuine Traditional Chinese catalogue copy.
     *
     * @var list<string>
     */
    private const PLACEHOLDERS = [
        'aliquid', 'ducimus', 'lorem', 'ipsum', 'dolor', 'consequatur',
        'voluptas', 'quia', 'nemo', 'eaque', 'accusantium', 'perferendis',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    public function test_the_seeded_catalogue_contains_no_faker_placeholders(): void
    {
        $text = collect([
            Platform::query()->pluck('name'),
            Platform::query()->pluck('slug'),
            Platform::query()->pluck('tagline'),
            Service::query()->pluck('name'),
            Service::query()->pluck('slug'),
            Service::query()->pluck('summary'),
            ServiceVariant::query()->pluck('label'),
        ])->flatten()->filter()->implode(' ');

        foreach (self::PLACEHOLDERS as $word) {
            $this->assertStringNotContainsStringIgnoringCase(
                $word,
                $text,
                "目錄內出現 Faker 假資料「{$word}」——很可能是測試或 tinker 寫進開發資料庫的殘留。"
            );
        }
    }

    public function test_the_seeder_produces_exactly_the_expected_catalogue(): void
    {
        // 數量固定，⛔ 多出來的列代表有非種子資料混入。
        $this->assertSame(3, Platform::query()->count());
        $this->assertSame(9, Service::query()->count());
    }

    public function test_every_platform_slug_is_one_the_project_actually_uses(): void
    {
        $this->assertEqualsCanonicalizing(
            ['instagram', 'facebook', 'threads'],
            Platform::query()->pluck('slug')->all()
        );
    }
}
