<?php

namespace Tests\Concerns;

/**
 * 把測試期間的 snapshot 寫入導向拋棄式 storage 目錄。
 *
 * ⛔ 真實的 `storage/app/private/m2c-snapshots` 是 Owner 的還原資產:
 * R3／R4／R5 的正式 rollback 快照都在裡面。測試每跑一次 apply 就會產生
 * 一個快照,若指向真實目錄,一輪 full suite 就會塞進數十個測試垃圾檔,
 * 稀釋掉真正可用的快照(R5 首次交付即發生,由 GPT 人工清除 40 個檔案)。
 *
 * 作法:改寫 application 的 storage path 指向 per-test 暫存目錄。command
 * 內所有路徑都經 `storage_path()`,因此連 realpath 目錄防護也一併搬過去,
 * ⛔ 不需要為了測試在正式程式碼開後門。
 *
 * tearDown 只刪除本測試自己建立的暫存目錄;即使測試中途 assertion 失敗或
 * 丟例外,PHPUnit 仍會呼叫 tearDown,所以不會殘留。
 */
trait IsolatesSnapshotStorage
{
    private ?string $isolatedStoragePath = null;

    protected function isolateSnapshotStorage(): void
    {
        $base = sys_get_temp_dir().'/iglf-test-storage-'.bin2hex(random_bytes(8));

        foreach (['app/private/m2c-snapshots', 'framework/views', 'framework/cache', 'logs'] as $sub) {
            if (! is_dir($base.'/'.$sub)) {
                mkdir($base.'/'.$sub, 0775, true);
            }
        }

        $this->isolatedStoragePath = $base;
        $this->app->useStoragePath($base);
    }

    /** 本測試建立的快照目錄(已隔離)。 */
    protected function snapshotDirectory(): string
    {
        return storage_path('app/private/m2c-snapshots');
    }

    protected function tearDownIsolatedSnapshotStorage(): void
    {
        if ($this->isolatedStoragePath === null) {
            return;
        }

        $this->deleteDirectory($this->isolatedStoragePath);
        $this->isolatedStoragePath = null;
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path.DIRECTORY_SEPARATOR.$entry;

            is_dir($full) ? $this->deleteDirectory($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
