{{--
    訂單列表的 SMM 欄。

    ⛔⛔ Owner 的要求有兩半，兩半都必須成立：

     1. 平常**不露文字**——列表上不得直接出現 `Partial`／`Canceled` 或藥丸；
     2. 但這兩種狀態**必須看得見**，且不得被「其他商品尚未送出」的叉蓋掉。

    ⭐ 因此每一種指示各是一個獨立的圖示，可以同時出現。

    ⛔⛔ 無障礙：tooltip 不得只靠 CSS `:hover`。
    ⭐ 這裡用 `<button type="button">`：
      - 鍵盤可 focus（Tab 走得到）；
      - 觸控可 tap；
      - `title` 提供原生 tooltip，`aria-label` 提供螢幕閱讀器的等價文字。
    ⛔ 不用 `<div>` ＋ hover-only：那對鍵盤與手機使用者等於不存在。

    ⛔ `type="button"` 是必要的：這個表格的列本身是連結／可點擊，
    ⛔ 沒有 type 的 button 在某些情境會觸發預設送出行為。
--}}
@php
    $indicators = \App\Support\OrderOperationsIndicators::smm($getRecord());
@endphp

<div class="flex items-center gap-1.5">
    @if ($indicators['partial'])
        {{-- ⛔ 黃色 warning：部分完成。 --}}
        <button
            type="button"
            class="fi-color-warning text-warning-600 dark:text-warning-400 cursor-help"
            title="{{ \App\Support\OrderOperationsIndicators::partialToken() }}"
            aria-label="{{ \App\Support\OrderOperationsIndicators::partialToken() }}"
        >
            <x-filament::icon
                icon="heroicon-o-exclamation-triangle"
                class="h-5 w-5"
            />
        </button>
    @endif

    @if ($indicators['canceled'])
        {{-- ⛔ 紅色 danger：已取消。⛔ 與上面的顏色不得對調。 --}}
        <button
            type="button"
            class="fi-color-danger text-danger-600 dark:text-danger-400 cursor-help"
            title="{{ \App\Support\OrderOperationsIndicators::canceledToken() }}"
            aria-label="{{ \App\Support\OrderOperationsIndicators::canceledToken() }}"
        >
            <x-filament::icon
                icon="heroicon-o-exclamation-triangle"
                class="h-5 w-5"
            />
        </button>
    @endif

    @if ($indicators['pending'])
        {{-- ⛔ 另有商品的**最新**批次還沒拿到供應商單號。 --}}
        <span aria-label="尚未全部送出" title="尚未全部送出">
            <x-filament::icon
                icon="heroicon-o-x-mark"
                class="text-danger-600 dark:text-danger-400 h-5 w-5"
            />
        </span>
    @endif

    @if ($indicators['allSubmitted'])
        <span aria-label="全部已送出" title="全部已送出">
            <x-filament::icon
                icon="heroicon-o-check"
                class="text-success-600 dark:text-success-400 h-5 w-5"
            />
        </span>
    @endif
</div>
