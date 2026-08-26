{{--
    合併訂單時間線（order events ＋ fulfillment events）。

    ⛔ 唯讀呈現。資料由 `OrderActivityTimeline` 一次算好——它只讀兩張
    append-only 表，⛔ 不寫入、不呼叫 provider。

    ⛔ 每列的 `wire:key` 用 presenter 給的穩定唯一 key（`order:{id}` /
    `fulfillment:{id}`）：不能用陣列索引（新事件插入時整批位移），也不能單用
    `id`（兩張來源表的自增 id 會撞號）。

    ⛔ 時間線本身不含任何 provider 原文——presenter 只用封閉 enum 組出固定
    中文句子。
--}}
<x-filament::section>
    <x-slot name="heading">訂單時間線</x-slot>
    <x-slot name="description">依時間合併顯示訂單事件與履約進度。</x-slot>

    @php($entries = $this->getTimelineEntries())

    @if (empty($entries))
        <p class="fi-color-gray text-sm">尚無事件。</p>
    @else
        <ol class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($entries as $entry)
                <li wire:key="{{ $entry['key'] }}"
                    class="grid grid-cols-1 gap-1 py-3 sm:grid-cols-12 sm:gap-4">
                    <time class="text-sm text-gray-500 sm:col-span-3 dark:text-gray-400">
                        {{ $entry['created_at']?->format('Y-m-d H:i:s') }}
                    </time>

                    <span class="text-sm font-semibold text-gray-950 sm:col-span-5 dark:text-white">
                        {{ $entry['label'] }}
                    </span>

                    <span class="text-sm text-gray-500 sm:col-span-4 dark:text-gray-400">
                        {{ $entry['smm_service_name'] ?? '—' }}
                    </span>
                </li>
            @endforeach
        </ol>
    @endif
</x-filament::section>
