<?php

namespace Tests\Feature\Operations;

use App\Console\Commands\StagingReadinessCommand;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use App\Services\Fulfillment\TheMostPanelStagingCredentialSource;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\UniqueConstraintViolationException;
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

    /**
     * ⛔ R1(3.5):DB unique 約束使多列結構性不可存在——這裡直接驗證
     * schema invariant(插入第二列必被拒絕),取代原本的 skip。
     */
    public function test_the_schema_rejects_duplicate_provider_rows(): void
    {
        $this->row();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('integration_settings')->insert([
            'provider' => IntegrationProvider::TheMostPanel->value,
            'environment' => IntegrationEnvironment::Production->value,
            'is_enabled' => 1,
            'credentials' => DB::table('integration_settings')->value('credentials'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    /**
     * ⛔ R1(P1-1):readiness presence 路徑真正 0 decrypt。corrupt
     * ciphertext 的列必須回報 `encrypted_payload=stored`(不是
     * unavailable),而且以「一 decrypt 就 fail 測試」的 encrypter spy
     * 證明整條 report 路徑沒碰過解密。
     */
    public function test_readiness_presence_reports_stored_without_ever_decrypting(): void
    {
        $setting = $this->row();
        DB::table('integration_settings')->where('id', $setting->id)
            ->update(['credentials' => 'corrupt-not-a-ciphertext']);

        $real = app('encrypter');
        $spy = new class($real, $this) implements Encrypter
        {
            public function __construct(private $real, private $test) {}

            public function encrypt($value, $serialize = true)
            {
                return $this->real->encrypt($value, $serialize);
            }

            public function decrypt($payload, $unserialize = true)
            {
                $this->test->fail('⛔ readiness 路徑呼叫了 decrypt');
            }

            public function getKey()
            {
                return $this->real->getKey();
            }

            public function getAllKeys()
            {
                return $this->real->getAllKeys();
            }

            public function getPreviousKeys()
            {
                return $this->real->getPreviousKeys();
            }
        };
        $this->app->instance('encrypter', $spy);

        $report = StagingReadinessCommand::report();

        $this->app->instance('encrypter', $real);

        $check = collect($report['checks'])->firstWhere('key', 'credential_themostpanel_production');
        // ⛔ stored,不是 unavailable:presence 與可解密是兩回事。
        $this->assertSame('present;enabled=yes;encrypted_payload=stored;identifier=not-required', $check['value']);

        $encoded = json_encode($report, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(self::KEY_MARKER, $encoded);
        $this->assertStringNotContainsString('corrupt-not-a-ciphertext', $encoded);
    }
}
