<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M2-E-B:`/admin` 側邊導航精簡為日常工作流程。
 *
 * ⛔ 驗證的是 Filament 實際建構出來的導航樹(getNavigation()),不是 grep
 * 原始碼——原始碼有字串不代表畫面真的長這樣。
 *
 * 核心不變式:
 * - 儀表板維持最上方且不屬於任何群組;含它共 7 個可見入口;
 * - 9 個技術頁只從導航隱藏,route／授權／noindex 全部照舊;
 * - 隱藏不得讓 Editor 取得任何新權限。
 */
class AdminNavigationGroupingTest extends TestCase
{
    use RefreshDatabase;

    /** 期望的群組順序,以及每個群組內的項目順序與顯示名稱。 */
    private const EXPECTED = [
        '訂單管理' => ['訂單'],
        '商品管理' => ['商品'],
        '網站內容' => ['首頁設定', '常見問題'],
        '系統設定' => ['串接設定', '後台帳號'],
    ];

    /** 6 個日常入口的 URL;⛔ 改名不得改變網址。 */
    private const EXPECTED_PATHS = [
        '訂單' => '/admin/orders',
        '商品' => '/admin/services',
        '首頁設定' => '/admin/manage-site-settings',
        '常見問題' => '/admin/faqs',
        '串接設定' => '/admin/manage-integration-settings',
        '後台帳號' => '/admin/users',
    ];

    /**
     * 只從導航隱藏、但 route 必須仍然可用的技術頁。
     *
     * ⛔ 這是本輪的核心安全性質:隱藏 ≠ 下架。直接輸入網址仍可進入,
     * 因此隨時可回滾,既有履約與稽核資料也仍看得到。
     */
    private const HIDDEN_BUT_REACHABLE = [
        '/admin/invoices',
        '/admin/fulfillment-orders',
        '/admin/service-variants',
        '/admin/platforms',
        '/admin/service-content-sections',
        '/admin/fulfillment-mappings',
        '/admin/provider-services',
        '/admin/admin-audit-logs',
        '/admin/staging-readiness',
    ];

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    private function editor(): User
    {
        return User::factory()->create(['role' => 'editor', 'is_active' => true]);
    }

    /** @return array<int, NavigationGroup> */
    private function navigationFor(User $user): array
    {
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);

        $this->actingAs($user);

        return array_values(Filament::getNavigation());
    }

    /** @return array<int, NavigationGroup> */
    private function namedGroups(?User $user = null): array
    {
        return array_values(array_filter(
            $this->navigationFor($user ?? $this->owner()),
            fn (NavigationGroup $g) => filled($g->getLabel()),
        ));
    }

    public function test_the_four_groups_appear_in_the_specified_order(): void
    {
        $labels = array_map(fn (NavigationGroup $g) => $g->getLabel(), $this->namedGroups());

        $this->assertSame(array_keys(self::EXPECTED), $labels);
    }

    public function test_each_group_holds_exactly_its_items_in_order(): void
    {
        foreach ($this->namedGroups() as $group) {
            $expected = self::EXPECTED[$group->getLabel()] ?? null;

            $this->assertNotNull($expected, "未預期的群組:{$group->getLabel()}");

            $actual = array_map(
                fn (NavigationItem $i) => $i->getLabel(),
                collect($group->getItems())->values()->all(),
            );

            $this->assertSame($expected, $actual, "群組「{$group->getLabel()}」的項目順序不符");
        }
    }

    public function test_exactly_seven_entries_are_visible_including_the_dashboard(): void
    {
        $total = 0;

        foreach ($this->navigationFor($this->owner()) as $group) {
            $total += count(collect($group->getItems())->all());
        }

        // Dashboard + 6 個日常入口。
        $this->assertSame(7, $total);
    }

    public function test_the_dashboard_stays_on_top_and_outside_every_group(): void
    {
        $navigation = $this->navigationFor($this->owner());

        $first = $navigation[0];
        $this->assertSame('', (string) $first->getLabel());
        $this->assertNotEmpty(collect($first->getItems())->all());

        foreach ($this->namedGroups() as $group) {
            foreach ($group->getItems() as $item) {
                $this->assertNotContains($item->getUrl(), [url('/admin')], '儀表板不得被放進群組');
            }
        }
    }

    public function test_the_six_daily_entries_keep_their_urls(): void
    {
        $found = [];

        foreach ($this->namedGroups() as $group) {
            foreach ($group->getItems() as $item) {
                $found[$item->getLabel()] = $item->getUrl();
            }
        }

        $this->assertCount(6, $found);

        foreach (self::EXPECTED_PATHS as $label => $path) {
            $this->assertArrayHasKey($label, $found, "缺少入口:{$label}");
            // ⛔ 只改顯示名稱,URL 不變。
            $this->assertSame(url($path), $found[$label], "入口「{$label}」的 URL 不得改變");
        }
    }

    public function test_the_nine_technical_pages_are_hidden_from_navigation(): void
    {
        $urls = [];

        foreach ($this->navigationFor($this->owner()) as $group) {
            foreach ($group->getItems() as $item) {
                $urls[] = $item->getUrl();
            }
        }

        foreach (self::HIDDEN_BUT_REACHABLE as $path) {
            $this->assertNotContains(url($path), $urls, "{$path} 不應出現在導航");
        }
    }

    public function test_hidden_pages_are_still_reachable_by_direct_url_for_an_owner(): void
    {
        $this->actingAs($this->owner());

        // ⛔ 隱藏 ≠ 下架:route 與授權照舊,因此可回滾也查得到歷史資料。
        foreach (self::HIDDEN_BUT_REACHABLE as $path) {
            $this->get($path)->assertSuccessful();
        }
    }

    public function test_hiding_pages_grants_an_editor_no_new_access(): void
    {
        $editor = $this->editor();

        // Editor 的導航同樣看不到那 9 頁。
        $urls = [];

        foreach ($this->navigationFor($editor) as $group) {
            foreach ($group->getItems() as $item) {
                $urls[] = $item->getUrl();
            }
        }

        foreach (self::HIDDEN_BUT_REACHABLE as $path) {
            $this->assertNotContains(url($path), $urls);
        }

        // ⛔ Owner-only 的技術頁對 Editor 仍然 403,不因為「被隱藏」而放寬。
        $this->actingAs($editor);

        foreach (['/admin/provider-services', '/admin/fulfillment-mappings', '/admin/admin-audit-logs'] as $ownerOnly) {
            $this->get($ownerOnly)->assertForbidden();
        }
    }

    public function test_guests_still_cannot_reach_the_panel_and_admin_stays_noindex(): void
    {
        $this->get('/admin')->assertRedirect();

        $this->get('/admin/login')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_hidden_pages_keep_sending_noindex(): void
    {
        $this->actingAs($this->owner());

        foreach (self::HIDDEN_BUT_REACHABLE as $path) {
            $this->get($path)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
    }
}
