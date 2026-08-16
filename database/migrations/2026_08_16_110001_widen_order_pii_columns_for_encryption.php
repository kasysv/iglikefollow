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
     * than the input and of unpredictable length, so on databases that enforce
     * column lengths these become TEXT. ⛔ SQLite does not enforce them and is
     * deliberately left alone — see changePiiColumnsTo().
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
     * Change the PII columns to $type, where the database actually needs it.
     *
     * ⛔ On SQLite this does nothing at all, and that is the correct behaviour.
     * SQLite does not enforce VARCHAR lengths — a VARCHAR(10) stores a
     * 500-character string unchanged — so widening buys nothing there. What it
     * costs is severe: SQLite has no real ALTER COLUMN, so the driver rebuilds
     * the table by copying the rows out, DROPping the original and recreating
     * it. Dropping `orders` fires the ON DELETE CASCADE on every child table,
     * silently deleting order items, payment attempts and order events.
     *
     * Backing the children up and restoring them was the previous approach, but
     * it has to enumerate every child table correctly and stay correct as new
     * ones are added — one forgotten table is silent data loss. Not rebuilding
     * the table in the first place removes the hazard rather than managing it.
     *
     * MySQL and Postgres do enforce the length, so the widening still runs
     * there, where ALTER COLUMN is a real operation and no rebuild occurs.
     *
     * @param  'text'|'string'  $type
     */
    private function changePiiColumnsTo(string $type): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('orders', function (Blueprint $table) use ($type) {
            $table->{$type}('customer_email')->change();

            foreach (self::NULLABLE as $column) {
                $table->{$type}($column)->nullable()->change();
            }
        });

        Schema::table('order_items', function (Blueprint $table) use ($type) {
            $table->{$type}('target_value')->change();
        });
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
