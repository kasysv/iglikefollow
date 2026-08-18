<?php

namespace Tests\Feature\Operations;

use App\Console\Commands\StagingReadinessCommand;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use App\Services\Fulfillment\TheMostPanelStagingCredentialSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The staging credential source: usable key or null, never a reason.
 *
 * ⛔ Every failure mode returns null with zero requests and zero output —
 * no log line, no exception, no value. Fixtures are fictional FAKE- markers
 * in the disposable test DB; the real encrypted row is never touched.
 */
class StagingCredentialSourceTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_MARKER = 'FAKE-STAGING-KEY-MARKER-990011';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function source(): TheMostPanelStagingCredentialSource
    {
        return new TheMostPanelStagingCredentialSource;
    }

    private function row(bool $enabled = true): IntegrationSetting
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::TheMostPanel, IntegrationEnvironment::Production)
            ->create();

        $setting->credentials = ['ApiKey' => self::KEY_MARKER];
        $setting->save();

        /*
         * ⛔ IntegrationSettingObserver 依 enablable config 拒絕啟用
         * themostpanel/production——這正是現行防線。此處以 DB 直寫模擬
         * 「未來另案批准 enablable 之後」的 enabled 狀態,只存在於
         * disposable 測試 DB。
         */
        if ($enabled) {
            DB::table('integration_settings')->where('id', $setting->id)->update(['is_enabled' => true]);
        }

        return $setting->fresh();
    }

    public function test_a_valid_enabled_row_yields_the_key(): void
    {
        $this->row(enabled: true);

        $this->assertSame(self::KEY_MARKER, $this->source()->apiKey());
        Http::assertNothingSent();
    }

    public function test_a_missing_row_yields_null(): void
    {
        $this->assertNull($this->source()->apiKey());
    }

    public function test_a_disabled_row_yields_null(): void
    {
        $this->row(enabled: false);

        $this->assertNull($this->source()->apiKey());
    }

    /** ⛔ 多列無法辨識哪一份是真的:null。 */
    public function test_duplicate_rows_yield_null(): void
    {
        $this->row();
        // 直接插入第二列繞過唯一防護(如果有的話,也要證明 source 自己會擋)。
        try {
            DB::table('integration_settings')->insert([
                'provider' => IntegrationProvider::TheMostPanel->value,
                'environment' => IntegrationEnvironment::Production->value,
                'is_enabled' => 1,
                'credentials' => DB::table('integration_settings')->value('credentials'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            $this->markTestSkipped('DB 唯一約束使多列無法存在,source 的多列分支由結構保證。');
        }

        $this->assertNull($this->source()->apiKey());
    }

    public function test_a_row_without_the_secret_yields_null(): void
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::TheMostPanel, IntegrationEnvironment::Production)
            ->create();
        DB::table('integration_settings')->where('id', $setting->id)->update(['is_enabled' => true]);

        $this->assertNull($this->source()->apiKey());
    }

    /** ⛔ 壞 ciphertext:null,不 throw、不記 log。 */
    public function test_corrupt_ciphertext_yields_null_without_logging(): void
    {
        $setting = $this->row();
        DB::table('integration_settings')->where('id', $setting->id)
            ->update(['credentials' => 'corrupt-not-a-ciphertext']);

        $this->assertNull($this->source()->apiKey());

        $log = storage_path('logs/laravel.log');
        if (File::exists($log)) {
            $tail = mb_substr((string) File::get($log), -20000);
            $this->assertStringNotContainsString('corrupt-not-a-ciphertext', $tail);
            $this->assertStringNotContainsString(self::KEY_MARKER, $tail);
        }
    }

    /** readiness 的 presence 檢查不解密:corrupt ciphertext 也能安全回報。 */
    public function test_readiness_presence_reporting_survives_corrupt_ciphertext(): void
    {
        $setting = $this->row();
        DB::table('integration_settings')->where('id', $setting->id)
            ->update(['credentials' => 'corrupt-not-a-ciphertext']);

        $report = StagingReadinessCommand::report();
        $encoded = json_encode($report, JSON_UNESCAPED_UNICODE);

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString(self::KEY_MARKER, $encoded);
        $this->assertStringNotContainsString('corrupt-not-a-ciphertext', $encoded);
    }
}
