{{--
    訂單列表的 SMM 欄。

    ⛔⛔ Owner 的要求有兩半，兩半都必須成立：

     1. 平常**不露文字**——列表上不得直接出現 `Partial`／`Canceled` 或藥丸；
     2. 但這兩種狀態**必須看得見**，且不得被「其他商品尚未送出」的叉蓋掉。

    ⭐ 因此每一種指示各是一個獨立的圖示，可以同時出現。

    ⛔⛔ R1 修正一：改用安裝版 Filament 既有的 `<x-filament::icon-button>`。

    ⭐ 初版是我手寫的 `<button title="...">`，只有瀏覽器原生 title：
    ⛔ 沒有專案其他地方都在用的 `x-tooltip` 互動，樣式與行為都自成一格。
    Filament 的 component 會自己輸出 `x-tooltip`（內容經 `@js()` 編碼）
    與經過 `e()` 逸出的 `aria-label`，⛔ 也就不需要我自己拼字串。

    ⛔ 注意：該 component 在有 `tooltip` 時會**刻意把 `title` 設為 null**
    （`icon-button.blade.php:94`），避免原生 title 與 tooltip 疊加。
    ⭐ 所以 exact token 出現在 `x-tooltip` 與 `aria-label`，⛔ 不再有 `title`。

    ⛔⛔ R1 修正二：阻止事件冒泡。

    ⭐ 整列是可以點進訂單的連結。初版的 button 沒有隔離事件，
    ⛔ 於是點擊／觸控警示圖示很可能直接觸發整列跳轉——
    使用者根本沒機會看到提示。`x-on:click.stop.prevent` 讓這顆圖示
    只做自己的事，⛔ 不觸發列導航、⛔ 也不 submit 任何表單。

    ⭐ 鍵盤仍可 Tab focus（它就是一個真正的 `<button>`），
    ⛔ 不是只靠 CSS `:hover` 的裝飾元素。
--}}
@php
    $indicators = \App\Support\OrderOperationsIndicators::smm($getRecord());
@endphp

<div class="flex items-center gap-1.5">
    @if ($indicators['partial'])
        {{-- ⛔ warning：部分完成。⛔ 顏色不得與下面的 danger 對調。 --}}
        <x-filament::icon-button
            icon="heroicon-o-exclamation-triangle"
            color="warning"
            :label="\App\Support\OrderOperationsIndicators::partialToken()"
            :tooltip="\App\Support\OrderOperationsIndicators::partialToken()"
            x-on:click.stop.prevent=""
        />
    @endif

    @if ($indicators['canceled'])
        {{-- ⛔ danger：已取消。 --}}
        <x-filament::icon-button
            icon="heroicon-o-exclamation-triangle"
            color="danger"
            :label="\App\Support\OrderOperationsIndicators::canceledToken()"
            :tooltip="\App\Support\OrderOperationsIndicators::canceledToken()"
            x-on:click.stop.prevent=""
        />
    @endif

    @if ($indicators['pending'])
        {{--
            ⛔ 另有商品的**最新**批次還沒拿到供應商單號。
            ⭐ 這是固定的本地中文說明，⛔ 不是 provider 原文，
            所以不受「平常不露文字」那條限制——它本來就不是 SMM 的 token。
        --}}
        <x-filament::icon-button
            icon="heroicon-o-x-mark"
            color="danger"
            label="尚未全部送出"
            tooltip="尚未全部送出"
            x-on:click.stop.prevent=""
        />
    @endif

    @if ($indicators['allSubmitted'])
        <x-filament::icon-button
            icon="heroicon-o-check"
            color="success"
            label="全部已送出"
            tooltip="全部已送出"
            x-on:click.stop.prevent=""
        />
    @endif
</div>
