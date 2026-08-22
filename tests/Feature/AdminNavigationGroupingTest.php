<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M2-E-A:`/admin` 左側導航分組。
 *
 * ⛔ 這裡驗證的是 Filament 實際建構出來的導航樹(getNavigation()),
 * 不是 grep 原始碼——原始碼有字串不代表畫面真的分組正確。
 *
 * 核心不變式:
 * - 儀表板維持最上方且不屬於任何群組;
 * - 五個群組的順序、每個項目的群組歸屬、顯示名稱與組內排序固定;
 * - 15 個功能入口全部仍在,URL／授權行為不變;
 * - `/admin` 仍強制 noindex,訪客仍不得進入。
 */
class AdminNavigationGroupingTest extends TestCase
{
    use RefreshDatabase;

    /** 期望的群組順序,以及每個群組內的項目順序與顯示名稱。 */
    private const EXPECTED = [
        '訂單營運' => ['訂單', '電子發票', '派單紀錄'],
        '商品與價格' => ['商品頁面', '商品方案與價格', '平台管理'],
        '網站內容與 SEO' => ['首頁與全站設定', 'SEO 內容段落', '常見問題'],
        '履約與串接' => ['商品派單對照', 'TheMostPanel 服務目錄', '金流／發票／API 設定'],
        '系統管理' => ['後台帳號', '操作紀錄', '上線準備檢查'],
    ];

    /** 施工前既有的 15 個入口 URL;⛔ 本輪不得改變任何一個。 */
    private const EXPECTED_PATHS = [
        '訂單' => '/admin/orders',
        '電子發票' => '/admin/invoices',
        '派單紀錄' => '/admin/fulfillment-orders',
        '商品頁面' => '/admin/services',
        '商品方案與價格' => '/admin/service-variants',
        '平台管理' => '/admin/platforms',
        '首頁與全站設定' => '/admin/manage-site-settings',
        'SEO 內容段落' => '/admin/service-content-sections',
        '常見問題' => '/admin/faqs',
        '商品派單對照' => '/admin/fulfillment-mappings',
        'TheMostPanel 服務目錄' => '/admin/provider-services',
        '金流／發票／API 設定' => '/admin/manage-integration-settings',
        '後台帳號' => '/admin/users',
        '操作紀錄' => '/admin/admin-audit-logs',
        '上線準備檢查' => '/admin/staging-readiness',
    ];

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    /**
     * Filament 實際建構的導航樹。
     *
     * @return array<int, NavigationGroup>
     */
    private function navigation(): array
    {
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);

        $this->actingAs($this->owner());

        return array_values(Filament::getNavigation());
    }

    /** 有名稱的群組(排除 Dashboard 所在的無名頂層群組)。 @return array<int, NavigationGroup> */
    private function namedGroups(): array
    {
        return array_values(array_filter(
            $this->navigation(),
            fn (NavigationGroup $g) => filled($g->getLabel()),
        ));
    }

    public function test_the_five_groups_appear_in_the_specified_order(): void
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

            // 順序與內容都必須完全相符(⛔ 不是「包含」而已)。
            $this->assertSame($expected, $actual, "群組「{$group->getLabel()}」的項目順序不符");
        }
    }

    public function test_the_dashboard_stays_on_top_and_outside_every_group(): void
    {
        $navigation = $this->navigation();

        // 第一個群組是 Dashboard 所在的無名頂層群組。
        $first = $navigation[0];
        $this->assertSame('', (string) $first->getLabel());

        $topLabels = array_map(fn (NavigationItem $i) => $i->getLabel(), collect($first->getItems())->values()->all());
        $this->assertNotEmpty($topLabels);

        // ⛔ Dashboard 不得被塞進五個群組中的任何一個。
        foreach ($this->namedGroups() as $group) {
            foreach ($group->getItems() as $item) {
                $this->assertNotContains($item->getUrl(), [url('/admin')], '儀表板不得被放進群組');
            }
        }
    }

    public function test_every_group_is_collapsible_and_settings_groups_start_collapsed(): void
    {
        $expanded = ['訂單營運', '商品與價格'];
        $collapsed = ['網站內容與 SEO', '履約與串接', '系統管理'];

        foreach ($this->namedGroups() as $group) {
            $this->assertTrue($group->isCollapsible(), "群組「{$group->getLabel()}」必須可收合");

            if (in_array($group->getLabel(), $expanded, true)) {
                $this->assertFalse($group->isCollapsed(), "群組「{$group->getLabel()}」初始應展開");
            }

            if (in_array($group->getLabel(), $collapsed, true)) {
                $this->assertTrue($group->isCollapsed(), "群組「{$group->getLabel()}」初始應收合");
            }
        }
    }

    public function test_all_fifteen_entries_survive_with_unchanged_urls(): void
    {
        $found = [];

        foreach ($this->namedGroups() as $group) {
            foreach ($group->getItems() as $item) {
                $found[$item->getLabel()] = $item->getUrl();
            }
        }

        $this->assertCount(15, $found, '15 個功能入口必須全部保留');

        foreach (self::EXPECTED_PATHS as $label => $path) {
            $this->assertArrayHasKey($label, $found, "缺少入口:{$label}");
            // ⛔ URL 不得因為分組而改變。
            $this->assertSame(url($path), $found[$label], "入口「{$label}」的 URL 不得改變");
        }
    }

    public function test_each_navigation_item_carries_a_distinguishable_icon(): void
    {
        $icons = [];

        foreach ($this->namedGroups() as $group) {
            foreach ($group->getItems() as $item) {
                $icon = $item->getIcon();
                $this->assertNotNull($icon, "入口「{$item->getLabel()}」必須有圖示");
                $icons[$item->getLabel()] = $icon instanceof \BackedEnum ? $icon->value : (string) $icon;
            }
        }

        // ⛔ 不得再讓多數項目共用同一個 RectangleStack。
        $counts = array_count_values($icons);
        arsort($counts);
        $mostReused = reset($counts);

        $this->assertLessThanOrEqual(1, $mostReused, '每個入口都應有可區分的圖示:'.json_encode(
            array_keys($counts, $mostReused, true), JSON_UNESCAPED_UNICODE,
        ));
        $this->assertCount(15, $icons);
    }

    // ------------------------------------------------------------------
    // 授權與 noindex 不因分組而改變
    // ------------------------------------------------------------------

    public function test_guests_still_cannot_reach_the_panel_and_admin_stays_noindex(): void
    {
        $this->get('/admin')->assertRedirect();

        $this->get('/admin/login')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_an_owner_can_still_open_every_entry(): void
    {
        $this->actingAs($this->owner());

        foreach (self::EXPECTED_PATHS as $label => $path) {
            $this->get($path)->assertSuccessful();
        }
    }
}
