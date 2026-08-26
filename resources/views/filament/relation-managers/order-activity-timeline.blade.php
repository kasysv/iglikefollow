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
        {{--
            ⭐ 事件標籤改用 Filament 原生 `<x-filament::badge>`。

            ⛔ 顏色只能來自 presenter 的封閉 token（`gray／primary／info／
            success／warning／danger`），⛔ 不得把 DB 值、provider 原文或任意
            CSS class 送進來——那是一條把資料庫內容變成 HTML 屬性的路。

            ⛔ 全部使用 Filament／Tailwind 既有 token，⛔ 不加 hex 色碼、
            不另造一套設計系統：後台其他頁面改版時，這裡要跟著一起變。

            ⛔ 手機單欄、`sm` 以上三欄；長服務名稱 `break-words`，
            ⛔ 不設固定 min-width（那會產生頁面級橫向捲動）。
        --}}
        <ol class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($entries as $entry)
                <li wire:key="{{ $entry['key'] }}"
                    class="grid grid-cols-1 gap-2 py-3 sm:grid-cols-12 sm:items-center sm:gap-4">
                    <time class="text-sm text-gray-500 tabular-nums sm:col-span-3 dark:text-gray-400">
                        {{ $entry['created_at']?->format('Y-m-d H:i:s') }}
                    </time>

                    <div class="sm:col-span-5">
                        <x-filament::badge :color="$entry['color']">
                            {{ $entry['label'] }}
                        </x-filament::badge>
                    </div>

                    <span class="text-sm break-words text-gray-500 sm:col-span-4 dark:text-gray-400">
                        {{ $entry['smm_service_name'] ?? '—' }}
                    </span>
                </li>
            @endforeach
        </ol>
    @endif
</x-filament::section>
