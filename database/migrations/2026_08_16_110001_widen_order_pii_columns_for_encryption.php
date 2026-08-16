<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Encrypt the columns that hold personal data, including rows already there.
     *
     * Email, phone, carrier, love code, tax id, buyer name and the fulfilment
     * target are all required to take payment, issue an invoice and deliver the
     * service, so none can be dropped — but they should not sit in the database
     * as plain text.
     *
     * Widening the columns is not enough on its own: any row written before this
     * migration is still plaintext, and the encrypted cast added alongside it
     * would fail to decrypt those rows. So up() widens *and* encrypts in place.
     *
     * Laravel's encrypted cast produces a base64 envelope several times longer
     * than the input and of unpredictable length, so these become TEXT.
     * ⛔ No index is added to any of them: an index over ciphertext cannot answer
     * a search anyway, and would only leak equality information.
     *
     * ⛔ Every row is rewritten with raw queries and Crypt directly. Using the
     * Eloquent models would be circular — their casts assume the work this
     * migration is doing has already happened.
     */
    /** 選填欄位維持 nullable；⛔ customer_email 仍為必填，不得放寬。 */
    private const NULLABLE = [
        'customer_phone',
        'carrier_number',
        'love_code',
        'buyer_tax_id',
        'buyer_name',
    ];

    /** @var array<string, list<string>> 每張表要加密的欄位。 */
    private const ENCRYPTED = [
        'orders' => [
            'customer_email',
            'customer_phone',
            'carrier_number',
            'love_code',
            'buyer_tax_id',
            'buyer_name',
        ],
        'order_items' => ['target_value'],
    ];

    public function up(): void
    {
        $this->changePiiColumnsTo('text');
        $this->transform(fn (string $value) => Crypt::encryptString($value), encrypting: true);
    }

    /**
     * Change the PII columns to $type, without losing the child rows.
     *
     * ⛔ SQLite has no real ALTER COLUMN. The driver rebuilds the whole table:
     * it copies the rows out, DROPs the original and recreates it — and
     * dropping `orders` fires order_items' ON DELETE CASCADE, so simply
     * changing a column on `orders` deletes every order item. That is silent
     * and total data loss, and it is why this method exists.
     *
     * PRAGMA foreign_keys is not a reliable defence on its own: SQLite ignores
     * it inside a transaction, and migrations may or may not be wrapped in one.
     * So the child rows are read out first and restored afterwards, and the
     * count is checked. Correctness does not depend on the pragma taking effect.
     *
     * @param  'text'|'string'  $type
     */
    private function changePiiColumnsTo(string $type): void
    {
        $items = DB::table('order_items')->orderBy('id')->get();
        $expected = $items->count();

        Schema::withoutForeignKeyConstraints(function () use ($type) {
            Schema::table('orders', function (Blueprint $table) use ($type) {
                $table->{$type}('customer_email')->change();

                foreach (self::NULLABLE as $column) {
                    $table->{$type}($column)->nullable()->change();
                }
            });

            Schema::table('order_items', function (Blueprint $table) use ($type) {
                $table->{$type}('target_value')->change();
            });
        });

        // 被 cascade 掃掉就原樣寫回；沒掉就什麼都不做。
        if ($expected > 0 && DB::table('order_items')->count() === 0) {
            foreach ($items->chunk(200) as $chunk) {
                DB::table('order_items')->insert(
                    $chunk->map(fn ($row) => (array) $row)->all()
                );
            }
        }

        $actual = DB::table('order_items')->count();

        if ($actual !== $expected) {
            throw new RuntimeException(
                "訂單商品在欄位轉換後由 {$expected} 筆變成 {$actual} 筆，migration 已中止。"
            );
        }
    }

    /**
     * ⛔ 先解密回明文，再縮回 VARCHAR。
     *
     * 順序不能顛倒：先縮欄會把密文截斷，那筆個資就永久救不回來了。
     * 任何一列解不開就整個中止，⛔ 保留資料，不做部分轉換後回報成功。
     */
    public function down(): void
    {
        $this->transform(fn (string $value) => Crypt::decryptString($value), encrypting: false);

        // ⛔ 同樣不能讓 cascade 掃掉子表：見 changePiiColumnsTo() 的說明。
        $this->changePiiColumnsTo('string');
    }

    /**
     * Rewrite every PII column through $convert, row by row, in one transaction.
     *
     * @param  Closure(string): string  $convert
     */
    private function transform(Closure $convert, bool $encrypting): void
    {
        DB::transaction(function () use ($convert, $encrypting) {
            foreach (self::ENCRYPTED as $table => $columns) {
                foreach (DB::table($table)->select(['id', ...$columns])->cursor() as $row) {
                    $updates = [];

                    foreach ($columns as $column) {
                        $value = $row->{$column};

                        if ($value === null || $value === '') {
                            continue;
                        }

                        // 已經是密文就跳過：migration 可能在中途失敗後重跑。
                        if ($encrypting && $this->looksEncrypted($value)) {
                            continue;
                        }

                        if (! $encrypting && ! $this->looksEncrypted($value)) {
                            continue;
                        }

                        try {
                            $updates[$column] = $convert($value);
                        } catch (Throwable $e) {
                            // ⛔ 訊息只帶表名、列 id 與欄位名，不含任何個資或密文。
                            throw new RuntimeException(
                                "{$table}#{$row->id} 的 {$column} 無法轉換，migration 已中止，資料未變更。"
                                .'請確認 APP_KEY 與備份後再試。',
                                previous: $e,
                            );
                        }
                    }

                    if ($updates !== []) {
                        DB::table($table)->where('id', $row->id)->update($updates);
                    }
                }
            }
        });
    }

    /** Laravel 的加密封包是 base64 過的 JSON，含 iv／value／mac 三個鍵。 */
    private function looksEncrypted(string $value): bool
    {
        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload)
            && isset($payload['iv'], $payload['value'], $payload['mac']);
    }
};
