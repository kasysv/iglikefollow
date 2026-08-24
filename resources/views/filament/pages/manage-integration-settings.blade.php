<x-filament-panels::page>
    {{-- ⛔ 沒有「測試連線」按鈕：那會產生一次真實對外請求，而「我看看能不能連」
         不該是一個會送出憑證的動作。也沒有端點欄位：一個可以在後台輸入的網址，
         等於這台伺服器會帶著我們的金鑰去連任何有人填進去的主機。 --}}

    @if (! $this->outboundAllowed())
        <div class="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900
                    dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
            <p class="font-bold">這是本機／測試環境，不會對外送出任何請求。</p>
            <p class="mt-1">
                下方的開關可以照常操作並保存，但實際收款、開立發票只會在正式站上發生。
            </p>
        </div>
    @endif

    {{-- 通道狀態與開關。⛔ 「已設定」「Owner 已啟用」「現在真的會動」是
         三件不同的事，分開顯示，才不會出現畫面說在收款/派單、實際上沒有的情況。

         ⛔ R1：TheMostPanel 的「自動派單總開關」也在這裡，與付款／發票同一套
         按鈕、同一套後端規則；狀態只用一般管理者看得懂的字句，不顯示 raw
         exception、config 值或 provider 技術細節。 --}}
    <div class="mb-6 space-y-3">
        @foreach ($this->channelStates() as $state)
            @php($isDispatch = $state['provider'] === \App\Enums\IntegrationProvider::TheMostPanel)

            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border
                        border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <div class="min-w-0">
                    <p class="font-bold text-gray-950 dark:text-white">
                        {{ $state['label'] }}@if ($isDispatch)　<span class="font-normal text-gray-500 dark:text-gray-400">自動派單總開關</span>@endif
                    </p>

                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        @if (! $state['configured'])
                            {{-- ⛔ 只列欄位名稱，不列值、不列長度、不列末幾碼。 --}}
                            缺少 {{ implode('、', $state['missing']) }}，尚未填寫。
                        @elseif ($state['blockers'] !== [])
                            {{-- 技術條件未達：Owner 開不了，但要說清楚為什麼。 --}}
                            {{ implode('；', $state['blockers']) }}。
                        @elseif ($state['live'])
                            @if ($isDispatch)
                                設定完整，目前<span class="font-bold text-green-700 dark:text-green-400">可自動派單</span>。
                            @else
                                設定完整，目前<span class="font-bold text-green-700 dark:text-green-400">已啟用</span>。
                            @endif
                        @elseif ($state['enabled'])
                            設定完整、已啟用，但此環境不會對外送出請求。
                        @else
                            @if ($isDispatch)
                                設定完整，自動派單目前<span class="font-bold">尚未開放</span>。
                            @else
                                設定完整，目前<span class="font-bold">停用</span>中。
                            @endif
                        @endif
                    </p>

                    @if ($isDispatch && $state['enabled'])
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            只派開啟後新付款成功的訂單；商品仍須各自啟用 SMM 對應，歷史訂單不會補派。
                        </p>
                    @endif
                </div>

                @if ($state['enabled'])
                    {{-- ⛔ 停用永遠可按，不因設定不完整而被鎖住：最需要能關掉的
                         時候，通常正是出了什麼事的時候。 --}}
                    <x-filament::button
                        color="danger"
                        wire:click="toggleChannel('{{ $state['provider']->value }}', false)"
                        wire:loading.attr="disabled">
                        停用
                    </x-filament::button>
                @else
                    {{-- 設定不完整或技術條件未達時不給按，但這只是提示：真正的
                         規則在後端，一份手寫的 Livewire payload 從來不經過畫面。 --}}
                    <x-filament::button
                        color="primary"
                        :disabled="! $state['configured'] || $state['blockers'] !== []"
                        wire:click="toggleChannel('{{ $state['provider']->value }}', true)"
                        wire:loading.attr="disabled">
                        啟用
                    </x-filament::button>
                @endif
            </div>
        @endforeach
    </div>

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit">儲存</x-filament::button>
        </div>

        <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
            金鑰以加密方式保存，儲存後只顯示 {{ \App\Filament\Pages\ManageIntegrationSettings::MASK }}，
            不會再顯示真值。欄位留空代表沿用原本的金鑰；要更換時才填入新值。
        </p>
    </form>
</x-filament-panels::page>
