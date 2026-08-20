<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Support\ProductSlugMap;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsThreadsCatalog;
use Tests\TestCase;

/**
 * M2-C R4:成交與信心文案。
 *
 * ⛔ 逐字比對 R4 spec 核准文案;公開禁句 13 頁+checkout/result 0 命中;
 * R3 SEO Title/Description/H1 byte-equivalent;結果頁只依真實儲存狀態
 * 顯示,未知/錯誤不得顯示成功。
 */
class M2cR4ConversionCopyTest extends TestCase
{
    use RefreshDatabase;
    use SeedsThreadsCatalog;

    private const R4_HOME_INTRO = 'IG、Facebook、Threads 人氣方案一次選齊。提供台灣普通、頂級、真人粉絲與貼文讚，部分 IG 方案可指定男性或女性，另有影片觀看、Threads 瀏覽、轉發與分享服務。免會員下單，只需提供公開帳號或內容網址，不需登入密碼。付款成功後自動處理，並開立電子發票。';

    private const R4_HIGHLIGHTS = [
        ['多種台灣方案', '普通、頂級、真人，部分方案可指定男女'],
        ['不需帳號密碼', '只需公開帳號、貼文或影片網址'],
        ['自動處理與電子發票', '付款成功後自動處理，可填個人或公司電子發票'],
    ];

    private const R4_HUB_INTROS = [
        'instagram' => '選擇 Instagram 粉絲、貼文讚或影片觀看服務。粉絲與貼文讚提供台灣普通、頂級、真人及部分男女指定方案，可依帳號門面、貼文互動或影片觀看需求選購。',
        'facebook' => '選擇 Facebook 粉絲、貼文讚或影片觀看服務。可為粉專、個人檔案、社團、指定貼文或影片增加對應數字，依照想加強的社群內容直接選購。',
        'threads' => '選擇 Threads 粉絲、貼文讚、瀏覽、轉發或分享服務。粉絲提供普通、頂級與真人方案；貼文讚另有慢速、快速、頂級與真人款式。',
    ];

    private const R4_PRODUCT_INTROS = [
        'ig買粉絲' => '想買 IG 粉絲，可選擇台灣普通、頂級、真人或港澳台粉絲，部分方案還能指定男性或女性。適合想增加粉絲規模、強化帳號門面，或重視粉絲名單外觀的帳號。下單不需提供 Instagram 密碼。',
        'ig買like' => 'IG買讚可為指定單篇貼文增加按讚數，提供台灣普通、頂級、真人與部分男女指定方案。適合新品、活動、作品及重要貼文增加互動數字。',
        'ig影片觀看' => '為指定 Instagram Reel 或影片增加觀看次數，讓新品、活動及主打影片的觀看數字更完整。只需提供影片網址，不需登入帳號。',
        'fb買粉絲' => 'FB買粉絲可為粉專、個人檔案或社團增加粉絲數，適合強化品牌門面、社群規模及活動前的人氣數字。',
        'fb買like' => 'FB買讚可為指定 Facebook 貼文增加按讚數，適合商品貼文、活動公告、作品及重要內容增加互動數字。',
        'fb影片觀看' => '為指定 Facebook Reel 或影片增加觀看次數，讓活動影片、商品介紹及主打內容的觀看數字更完整。',
        'threads買粉絲' => 'Threads買粉絲提供台灣普通、頂級與真人方案，適合新帳號、品牌及創作者增加粉絲規模，讓帳號門面更完整。',
        'threads買讚' => 'Threads買讚提供慢速、快速、頂級及真人貼文讚，可依預算、速度與貼文需求選擇，為指定內容增加按讚數。',
        'threads貼文瀏覽' => '為指定 Threads 貼文增加瀏覽次數，也可依需求分別選購轉發或分享次數，讓主打貼文的瀏覽與互動數字更有份量。',
    ];

    private const R4_BANNED = [
        '預覽版本', '本機 MOCK', '本機 MORK', '尚未開放正式下單', '測試前往付款',
        'Mock 訂單結果', '本頁承接', '本頁只處理', '不與其他商品混用',
        '集中在同一頁避免近似網址', '不把三者視為同一個交付項目', '建立訂單紀錄',
        '後端驗證', '付款回呼', '進入處理流程', '不會扣款、不會建立真實訂單',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->seed(CatalogSeeder::class);
        $this->seedThreadsCatalog();
        Artisan::call('m2c:apply-copy');
        Artisan::call('m2c:apply-r3');
    }

    /** @return array<string, string> */
    private function publicPages(): array
    {
        $pages = ['/' => null, '/services/instagram' => null, '/services/facebook' => null, '/services/threads' => null];

        foreach (array_values(ProductSlugMap::MAP) as $slug) {
            $pages['/product/'.$slug.'/'] = null;
        }

        foreach (array_keys($pages) as $url) {
            $pages[$url] = $this->get($url)->assertOk()->getContent();
        }

        return $pages;
    }

    private function checkoutHtml(): string
    {
        $variant = Service::query()->where('product_slug', 'ig買粉絲')->firstOrFail()
            ->variants()->published()->firstOrFail();

        $this->post('/checkout/start', ['variant' => $variant->id, 'quantity' => (int) $variant->default_quantity]);

        return $this->get('/checkout')->assertOk()->getContent();
    }

    public function test_r4_copy_is_applied_verbatim_from_the_spec(): void
    {
        $fixture = json_decode((string) file_get_contents(database_path('fixtures/m2c-r3-content.json')), true);

        // fixture 逐字=spec。
        $this->assertSame(self::R4_HOME_INTRO, $fixture['site']['home_intro']);

        foreach (self::R4_HIGHLIGHTS as $i => [$title, $body]) {
            $this->assertSame($title, $fixture['site']['highlights'][$i]['title']);
            $this->assertSame($body, $fixture['site']['highlights'][$i]['body']);
        }

        foreach (self::R4_HUB_INTROS as $slug => $intro) {
            $this->assertSame($intro, $fixture['platforms'][$slug]['intro'], $slug);
        }

        foreach (self::R4_PRODUCT_INTROS as $slug => $intro) {
            $this->assertSame($intro, $fixture['services'][$slug]['intro'], $slug);
        }

        // 頁面初始 HTML 逐字含核准文案。
        $pages = $this->publicPages();
        $home = $pages['/'];

        $this->assertStringContainsString(self::R4_HOME_INTRO, $home);

        foreach (self::R4_HIGHLIGHTS as [$title, $body]) {
            $this->assertStringContainsString($title, $home);
            $this->assertStringContainsString($body, $home);
        }

        foreach (self::R4_HUB_INTROS as $slug => $intro) {
            $this->assertStringContainsString($intro, $pages['/services/'.$slug], $slug);
        }

        foreach (self::R4_PRODUCT_INTROS as $slug => $intro) {
            $this->assertStringContainsString($intro, $pages['/product/'.$slug.'/'], $slug);
        }
    }

    public function test_public_banned_phrases_are_zero_on_all_customer_pages(): void
    {
        foreach ($this->publicPages() as $url => $html) {
            foreach (self::R4_BANNED as $phrase) {
                $this->assertStringNotContainsString($phrase, $html, "{$url} 含禁句 {$phrase}");
            }
        }

        // checkout 與 result 頁。
        $checkout = $this->checkoutHtml();

        foreach (self::R4_BANNED as $phrase) {
            $this->assertStringNotContainsString($phrase, $checkout, "checkout 含禁句 {$phrase}");
        }

        $this->assertStringContainsString('前往付款', $checkout);
        $this->assertStringContainsString('支援 LINE Pay、綠界付款', $checkout);

        $result = $this->post('/checkout/mock', [
            'target' => 'example_account',
            'payment' => 'line-pay',
            'customer_email' => 'buyer@example.com',
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'email',
        ])->assertOk()->getContent();

        foreach (self::R4_BANNED as $phrase) {
            $this->assertStringNotContainsString($phrase, $result, "result 含禁句 {$phrase}");
        }

        $this->assertStringContainsString('訂單結果', $result);

        // 後台授權 preview 提示可保留(有授權測試)。
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->actingAs($owner)
            ->get('/services/instagram/comments?preview=1')
            ->assertOk()
            ->assertSee('草稿預覽模式');
    }

    public function test_seo_surface_is_byte_equivalent_to_r3(): void
    {
        $fixture = json_decode((string) file_get_contents(database_path('fixtures/m2c-r3-content.json')), true);
        $pages = $this->publicPages();

        // 首頁:R3 Title/Description/H1 不變。
        $this->assertStringContainsString('<title>'.$fixture['site']['seo_title'].'</title>', $pages['/']);
        $this->assertStringContainsString($fixture['site']['meta_description'], $pages['/']);
        $this->assertStringContainsString($fixture['site']['home_h1'], $pages['/']);
        $this->assertSame('選購 IG、Facebook、Threads 買讚、粉絲與觀看方案', $fixture['site']['home_h1']);
        $this->assertSame('買讚、粉絲與觀看｜IG、FB、Threads｜IGLIKEFOLLOW', $fixture['site']['seo_title']);

        $titles = [];

        foreach (self::R4_HUB_INTROS as $slug => $intro) {
            $html = $pages['/services/'.$slug];
            $this->assertStringContainsString('<title>'.$fixture['platforms'][$slug]['seo_title'].'</title>', $html, $slug);
            $this->assertStringContainsString($fixture['platforms'][$slug]['h1'], $html, $slug);
        }

        foreach (ProductSlugMap::MAP as $key => $slug) {
            $service = Service::query()->where('product_slug', $slug)->firstOrFail();
            $html = $pages['/product/'.$slug.'/'];
            $this->assertStringContainsString('<title>'.$fixture['services'][$slug]['seo_title'].'</title>', $html, $slug);
            $this->assertStringContainsString($fixture['services'][$slug]['h1'], $html, $slug);
            $this->assertStringContainsString('<link rel="canonical" href="'.$service->primaryUrl().'">', $html, $slug);
        }

        // 13 頁:單一 H1、Title 唯一、商品級 /services 內鏈 0。
        foreach ($pages as $url => $html) {
            $this->assertSame(1, substr_count($html, '<h1'), $url);
            preg_match('/<title>([^<]*)<\/title>/u', $html, $m);
            $titles[$url] = $m[1] ?? '';
            $this->assertDoesNotMatchRegularExpression('#href="[^"]*/services/[a-z]+/[a-z][^"]*"#', $html, $url);
        }

        $this->assertSame(13, count(array_unique($titles)));
    }

    public function test_payment_result_page_shows_only_the_stored_status(): void
    {
        // 先經真實 mock 流程建立訂單(初始=待付款)。
        $variant = Service::query()->where('product_slug', 'ig買粉絲')->firstOrFail()
            ->variants()->published()->firstOrFail();
        $this->post('/checkout/start', ['variant' => $variant->id, 'quantity' => (int) $variant->default_quantity]);
        $html = $this->post('/checkout/mock', [
            'target' => 'example_account',
            'payment' => 'line-pay',
            'customer_email' => 'buyer@example.com',
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'email',
        ])->assertOk()->getContent();

        /*
         * mock 流程預設模擬成功並「落庫」——結果頁如實顯示儲存值,
         * 這正是要驗的行為:顯示=stored state,不是 route 名稱。
         */
        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertStringContainsString($order->payment_status->label(), $html);

        // 逐一狀態:結果頁顯示=儲存值;⛔ 只有 Succeeded 顯示付款成功。
        foreach (PaymentStatus::cases() as $status) {
            $attempt = $order->paymentAttempts()->latest('id')->first();
            $attempt->forceFill(['status' => $status])->saveQuietly();
            // 同步 order 層儲存狀態:頁面顯示的是 order.payment_status。
            $order->forceFill(['payment_status' => $status])->saveQuietly();

            $rendered = view('storefront.mock-success', [
                'order' => $order->fresh(['items', 'paymentAttempts', 'events']),
            ])->render();

            $this->assertStringContainsString($status->label(), $rendered, $status->value);

            if ($status !== PaymentStatus::Succeeded) {
                /*
                 * 非成功狀態不得出現「付款成功」——先剔除 footer 的核准句
                 * 「付款成功後自動處理…」(該句含同字串,屬 R4 核准文案)。
                 */
                $withoutApproved = str_replace('付款成功後自動處理，並開立電子發票。', '', $rendered);
                $this->assertStringNotContainsString('付款成功', $withoutApproved, $status->value);
            }
        }
    }

    public function test_safety_flags_remain_off_and_no_external_calls(): void
    {
        // 文案改成顧客語氣後,安全仍由 flags 保證。
        $this->assertFalse((bool) config('fulfillment.dispatch_enabled', false));
        $this->assertFalse((bool) config('services.line_pay.live', false));
        $this->get('/api/health')->assertOk()->assertJsonPath('indexing', false);
        // Http::preventStrayRequests 已在 setUp 全程生效=外部 HTTP 0。
    }
}
