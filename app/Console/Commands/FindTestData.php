<?php

namespace App\Console\Commands;

use App\Models\Platform;
use App\Models\Service;
use App\Models\ServiceVariant;
use Illuminate\Console\Command;

/**
 * Report Faker placeholder rows sitting in the real database.
 *
 * The test suite runs in memory and cannot see this data, so a stray row
 * created by an ad-hoc tinker call stays invisible until someone notices it
 * in the admin. This command reads only — removal stays a deliberate act.
 */
class FindTestData extends Command
{
    protected $signature = 'iglf:find-test-data';

    protected $description = '找出資料庫裡疑似 Faker 假資料的平台、服務分類與服務項目';

    /** @var list<string> */
    private const PLACEHOLDERS = [
        'aliquid', 'ducimus', 'lorem', 'ipsum', 'dolor', 'consequatur',
        'voluptas', 'quia', 'nemo', 'eaque', 'accusantium', 'perferendis',
        'necessitatibus', 'exercitationem', 'reprehenderit',
    ];

    public function handle(): int
    {
        $rows = [];

        foreach ([
            '平台' => [Platform::class, ['name', 'slug', 'tagline']],
            '服務分類' => [Service::class, ['name', 'slug', 'summary']],
            '服務項目' => [ServiceVariant::class, ['label', 'description']],
        ] as $label => [$model, $columns]) {
            foreach ($model::withTrashed()->get() as $record) {
                $haystack = mb_strtolower(collect($columns)
                    ->map(fn ($c) => (string) $record->{$c})
                    ->implode(' '));

                foreach (self::PLACEHOLDERS as $word) {
                    if (str_contains($haystack, $word)) {
                        $rows[] = [
                            $label,
                            $record->getKey(),
                            mb_substr((string) ($record->name ?? $record->label), 0, 30),
                            $record->status ?? '-',
                            $word,
                        ];
                        break;
                    }
                }
            }
        }

        if ($rows === []) {
            $this->info('沒有找到假資料。');

            return self::SUCCESS;
        }

        $this->warn('找到疑似 Faker 假資料：');
        $this->table(['類型', 'ID', '名稱', '狀態', '命中字'], $rows);
        $this->line('確認無誤後可在後台刪除，⛔ 刪除前請先備份資料庫。');

        return self::FAILURE;
    }
}
