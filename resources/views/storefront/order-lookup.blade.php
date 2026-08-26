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
                        <header class="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 class="text-base font-bold text-black">
                                訂單編號 {{ $order['reference'] }}
                            </h2>
                            @if ($order['placed_at'])
                                <p class="text-sm text-black/55">下單時間 {{ $order['placed_at'] }}</p>
                            @endif
                        </header>

                        <div class="mt-4 overflow-x-auto">
                            <table class="w-full min-w-[32rem] text-left text-sm">
                                <thead class="text-black/55">
                                    <tr>
                                        <th scope="col" class="py-2 pr-4 font-medium">服務</th>
                                        <th scope="col" class="py-2 pr-4 font-medium">數量</th>
                                        <th scope="col" class="py-2 pr-4 font-medium">狀態</th>
                                        <th scope="col" class="py-2 font-medium">剩餘</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-black/5">
                                    @foreach ($order['items'] as $item)
                                        <tr>
                                            <td class="py-3 pr-4 text-black">
                                                {{ $item['platform'] }}｜{{ $item['service'] }}
                                                <span class="block text-black/55">{{ $item['variant'] }}</span>
                                            </td>
                                            <td class="py-3 pr-4 text-black">{{ number_format($item['quantity']) }}</td>
                                            <td class="py-3 pr-4 text-black">{{ $item['status'] }}</td>
                                            <td class="py-3 text-black">{{ $item['remains'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
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
