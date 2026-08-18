<?php

namespace App\Services\Fulfillment;

use App\Data\Fulfillment\ProviderServiceCatalogSyncResult;
use RuntimeException;

/**
 * A durable, non-overwritable record that one execution ID was consumed —
 * written BEFORE anything else happens.
 *
 * ⛔ B3's lesson: a ledger written after the fact proves nothing. The B3
 * result file's creation time postdated the backup and the drill, so no
 * artifact could show the single-request authorization was consumed *before*
 * the command ran. This class makes that ordering physical: the file is
 * created with exclusive-create semantics and the initial record is flushed
 * to disk before the caller is allowed to touch the source, the credential
 * or HTTP. A crash after that point leaves the marker behind, and the same
 * execution ID can never run again.
 *
 * ⛔ Append-only, one handle. The final record goes through the same handle
 * that wrote the initial one — never a replace, rename or reopen that could
 * quietly rebuild the file.
 *
 * ⛔ Contents are safe fields only: execution ID, UTC timestamp, a fixed
 * state, and the sync result's safe array. The final record accepts only the
 * typed result object — no array, string or other caller-shaped entrance —
 * so no exception, raw body, credential or provider business data can reach
 * the file even from a future buggy caller.
 *
 * ⛔ The location is not a parameter. The ledger lives in exactly one place
 * under the application's private storage; no caller, CLI option, env value
 * or config key can point it anywhere else. Tests isolate themselves by
 * swapping the storage root, never by handing this class a path.
 */
class TheMostPanelCatalogSyncExecutionLedger
{
    /** ⛔ 唯一的 ledger 位置,寫死在 class 內;不存在任何目錄參數。 */
    private const RELATIVE_DIRECTORY = 'app/private/themostpanel/catalog-sync-attempts';

    /** 8–64 位大寫英數與連字號,首字元須為英數;⛔ 非秘密識別碼。 */
    public const ID_PATTERN = '/\A[A-Z0-9][A-Z0-9-]{7,63}\z/';

    public const CREATE_FAILED_MESSAGE =
        '⛔ execution ledger 無法以 exclusive-create 建立：同 ID 已存在或目錄不可寫；未執行任何動作。';

    public const INITIAL_WRITE_FAILED_MESSAGE =
        '⛔ execution ledger initial record 寫入失敗；未執行任何動作。';

    /** @var resource */
    private $handle;

    private function __construct(
        $handle,
        public readonly string $executionId,
        public readonly string $path,
    ) {
        $this->handle = $handle;
    }

    public static function isValidExecutionId(string $executionId): bool
    {
        return preg_match(self::ID_PATTERN, $executionId) === 1;
    }

    /**
     * Create the ledger file exclusively and durably write the initial marker.
     *
     * ⛔ Fails closed on every path: invalid ID, unwritable directory,
     * already-existing ID (never overwritten, truncated, deleted or retried),
     * or an initial write that cannot be flushed. On failure the caller has
     * nothing to call the source with.
     */
    public static function open(string $executionId): self
    {
        if (! self::isValidExecutionId($executionId)) {
            throw new RuntimeException(self::CREATE_FAILED_MESSAGE);
        }

        // ⛔ 固定位置:目錄由 class 自行解析,caller 沒有任何指定入口。
        $directory = storage_path(self::RELATIVE_DIRECTORY);

        if (! is_dir($directory) && ! @mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException(self::CREATE_FAILED_MESSAGE);
        }

        // 檔名只由驗證後的 ID 組成;pattern 已排除任何路徑字元。
        $path = rtrim($directory, '\\/').DIRECTORY_SEPARATOR.$executionId.'.ndjson';

        /*
         * ⛔ 'x' = exclusive create:已存在就失敗。這一行就是「同 ID 永不可
         * 重用」的全部機制——沒有 if-exists-then-delete,沒有重試。
         */
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            throw new RuntimeException(self::CREATE_FAILED_MESSAGE);
        }

        $ledger = new self($handle, $executionId, $path);

        $written = $ledger->append([
            'execution_id' => $executionId,
            'state' => 'consumed-before-command',
            'recorded_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);

        if (! $written) {
            /*
             * ⛔ initial record 寫不進去:關閉 handle 但不刪檔——一個空的
             * exclusive-created 檔案仍然永久佔住這個 ID,寧可多耗一個 ID
             * 也不要出現「以為沒消耗、其實送過了」的反向錯誤。
             */
            fclose($handle);

            throw new RuntimeException(self::INITIAL_WRITE_FAILED_MESSAGE);
        }

        return $ledger;
    }

    /**
     * Append the final safe record through the same handle.
     *
     * ⛔ Returns false instead of throwing: by the time this runs, the action
     * has already happened, so the only correct reaction to a failure is
     * "keep the initial marker, tell a human" — never a rerun.
     *
     * ⛔ Typed entrance only. The parameter type is the boundary: the result
     * class is the allowlist of everything a ledger line may contain, and its
     * own `toArray()` is called here, inside. An arbitrary array — the shape
     * GPT's counter-probe used to persist fake raw-body and credential
     * markers — no longer has any way in.
     */
    public function recordFinal(ProviderServiceCatalogSyncResult $result): bool
    {
        return $this->append([
            'execution_id' => $this->executionId,
            'state' => 'completed',
            'recorded_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        ] + $result->toArray());
    }

    /** @param array<string, mixed> $record */
    private function append(array $record): bool
    {
        if (! is_resource($this->handle)) {
            return false;
        }

        $line = json_encode($record, JSON_UNESCAPED_SLASHES);

        if ($line === false) {
            return false;
        }

        $bytes = @fwrite($this->handle, $line."\n");

        if ($bytes === false || $bytes < strlen($line) + 1) {
            return false;
        }

        if (@fflush($this->handle) === false) {
            return false;
        }

        /*
         * durable 的最後一哩:能 fsync 就 fsync。PHP 8.1+ 提供 fsync();
         * 失敗時回報失敗——「大概有寫進去」不是 ledger 的標準。
         */
        if (function_exists('fsync') && @fsync($this->handle) === false) {
            return false;
        }

        return true;
    }
}
