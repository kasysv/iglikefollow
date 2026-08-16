<x-filament-panels::page>
    {{-- ⛔ 沒有「測試連線」「建立測試訂單」「開立測試發票」「啟用正式交易」按鈕：
         本輪不允許任何外部呼叫，也不允許從畫面開啟正式交易。 --}}
    <div class="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900
                dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
        <p class="font-bold">目前所有串接都是停用狀態。</p>
        <p class="mt-1">
            這一頁只負責安全保存金鑰。實際連線、測試與正式啟用需要另一次明確批准，
            現在不會有任何對外請求。
        </p>
        <p class="mt-1">
            金鑰以加密方式保存，儲存後不會再顯示。欄位留空代表沿用原本的金鑰；
            要更換時才填入新值。
        </p>
    </div>

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit">儲存</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
