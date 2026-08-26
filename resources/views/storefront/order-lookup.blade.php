{{--
    訂單查詢結果。

    ⛔ 這一頁只顯示 `PublicOrderPresenter` 的 allowlist 欄位。它拿到的是一個
    純陣列，不是 Order model——Blade 因此**沒有辦法**取用 Email、手機、
    交付目標或任何 provider 資料，即使有人日後在這裡加一行也取不到。

    ⛔ noindex／nofollow 由 route 的 NeverIndex middleware 與 controller 的
    header 負責；這裡另外放 meta 作為第二道，因為分享出去的連結可能繞過
    header（雖然這一頁是 POST，理論上不會被分享）。
--}}
@extends('layouts.app', [
    'title' => '訂單查詢結果｜IGLIKEFOLLOW',
    'description' => '查詢您在 IGLIKEFOLLOW 的訂單進度。',
    {{--
        ⛔ `isPreview` 讓 layout 輸出 `noindex, nofollow` 的 meta robots。
        這是第二道：route 的 `NeverIndex` middleware 與 controller 的
        `X-Robots-Tag` header 才是主要防線，但這一頁含客人的訂單內容，
        值得同時具備 header 與 meta 兩層。
    --}}
    'isPreview' => true,
])

@section('content')
    <section class="mx-auto w-full max-w-3xl px-4 py-12 sm:px-6">
        <h1 class="text-2xl font-bold text-black sm:text-3xl">訂單查詢結果</h1>

        @if ($notFound)
            {{--
                ⛔ 通用訊息：查無、條件不符、少於兩項——全部同一句。
                分開的訊息可以被拿來逐一確認「這個 Email 有沒有在本站下過單」。
            --}}
            <p class="mt-6 rounded-lg bg-black/5 p-4 text-sm leading-6 text-black/70">
                查不到符合的訂單。請確認填寫的訂單編號、Email 與手機號碼是否與下單時一致，並至少填寫其中兩項。
            </p>
        @else
            <p class="mt-2 text-sm text-black/60">共 {{ count($results) }} 筆訂單。</p>

            <div class="mt-6 space-y-6">
                @foreach ($results as $order)
                    <article class="rounded-xl border border-black/10 p-5">
                        <header>
                            <h2 class="text-base font-bold break-words text-black">
                                訂單編號 {{ $order['reference'] }}
                            </h2>
                        </header>

                        {{--
                            ⛔ R1：390px 不得依賴橫向捲動閱讀。

                            初版用 `min-w-[32rem]` 的表格，在 390px 一定要左右
                            捲才看得完——那是「能看到」而不是「好讀」。改為
                            手機堆疊卡片、`sm` 以上才用表格式欄位。
                        --}}
                        <ul class="mt-4 divide-y divide-black/5">
                            @foreach ($order['items'] as $item)
                                <li class="py-4">
                                    <p class="font-medium break-words text-black">
                                        {{ $item['platform'] }}｜{{ $item['service'] }}
                                    </p>
                                    <p class="mt-0.5 text-sm break-words text-black/55">{{ $item['variant'] }}</p>

                                    {{-- 手機：兩欄標籤／值；桌面：一列四欄。 --}}
                                    <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
                                        <div>
                                            <dt class="text-black/55">購買數量</dt>
                                            <dd class="text-black">{{ number_format($item['quantity']) }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-black/55">狀態</dt>
                                            <dd class="text-black">{{ $item['status'] }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-black/55">剩餘</dt>
                                            <dd class="text-black">{{ $item['remains'] }}</dd>
                                        </div>
                                    </dl>
                                </li>
                            @endforeach
                        </ul>
                    </article>
                @endforeach
            </div>
        @endif

        <p class="mt-8 text-sm leading-6 text-black/60">
            狀態顯示「請聯絡客服」時，代表這筆需要人工確認，請與我們聯繫。
        </p>

        <a href="{{ route('home') }}#order-lookup"
           class="mt-6 inline-flex items-center rounded-lg border border-black/15 px-4 py-2 text-sm font-medium text-black">
            再查一筆
        </a>
    </section>
@endsection
