<x-filament-panels::page>
    {{-- ⛔ 沒有任意測試連線或端點欄位。下方同步按鈕只能送一次固定 services
         唯讀查詢；無法輸入其他 action、order 或網址。 --}}

    @if (! $this->outboundAllowed())
        <div class="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900
                    dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
            <p class="font-bold">這是本機／測試環境，不會對外送出任何請求。</p>
            <p class="mt-1">
                下方的開關可以照常操作並保存，但實際收款、開立發票只會在正式站上發生。
            </p>
        </div>
    @endif

    @php($catalog = $this->theMostPanelCatalogState())

    <div class="mb-6 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="font-bold text-gray-950 dark:text-white">SMM 服務清單</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    目前已保存 <span class="font-bold text-gray-950 dark:text-white">{{ $catalog['count'] }}</span> 筆服務
                    ・最後成功同步：{{ $catalog['last_synced_at'] ?? '尚未同步' }}
                </p>
                <a class="mt-2 inline-block text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                   href="{{ $catalog['index_url'] }}">
                    查看服務名稱、上下限
                </a>
            </div>

            <div wire:loading.attr="aria-busy">
                {{ $this->syncTheMostPanelCatalogAction }}
            </div>
        </div>
    </div>

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

                @php($switchDisabled = ! $state['enabled'] && (! $state['configured'] || $state['blockers'] !== []))

                {{--
                    ⛔ D1：左右滑動 switch 取代舊的啟用／停用兩個按鈕。
                    - 真正可鍵盤操作的 button，`role="switch"` 與 `aria-checked`
                      跟著實際狀態走，Space／Enter 原生可觸發（button 預設語意）。
                    - 顏色以外一定有文字「已開啟／已關閉」，不能只靠顏色判斷。
                    - 停用永遠可按（`$switchDisabled` 只在「目前 OFF 且不可開啟」
                      時為 true）；ON 狀態永遠能切回 OFF，最需要關掉的時候不能被鎖住。
                    - `wire:loading.attr="disabled"` 鎖定的是「這一個」switch：
                      用 `wire:target` 限定只有這次 toggleChannel 呼叫在跑的時候
                      才鎖住自己，不影響畫面上其他 switch 或送出中的表單。
                    - `wire:key` 讓 Livewire 每次 render 都能正確對應到同一個
                      DOM 節點，避免快速切換時 diff 到別的 provider 上。
                --}}
                <div class="flex items-center gap-3">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ $state['enabled'] ? '已開啟' : '已關閉' }}
                    </span>

                    <button
                        type="button"
                        role="switch"
                        aria-checked="{{ $state['enabled'] ? 'true' : 'false' }}"
                        aria-label="{{ $state['label'] }}{{ $isDispatch ? '自動派單總開關' : '' }}"
                        wire:key="channel-switch-{{ $state['provider']->value }}"
                        wire:click="toggleChannel('{{ $state['provider']->value }}', {{ $state['enabled'] ? 'false' : 'true' }})"
                        wire:loading.attr="disabled"
                        wire:target="toggleChannel('{{ $state['provider']->value }}', {{ $state['enabled'] ? 'false' : 'true' }})"
                        @disabled($switchDisabled)
                        @class([
                            'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full',
                            'border-2 border-transparent transition-colors duration-200 ease-in-out',
                            'focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2',
                            'disabled:cursor-not-allowed disabled:opacity-50',
                            'bg-primary-600' => $state['enabled'],
                            'bg-gray-300 dark:bg-gray-600' => ! $state['enabled'],
                        ])
                    >
                        <span
                            aria-hidden="true"
                            @class([
                                'pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow',
                                'transform ring-0 transition duration-200 ease-in-out',
                                'translate-x-5' => $state['enabled'],
                                'translate-x-0' => ! $state['enabled'],
                            ])
                        ></span>
                    </button>
                </div>
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
