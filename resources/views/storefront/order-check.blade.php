{{--
    獨立訂單查詢工具頁 `/order-check`。

    ⭐ 同一個 URL 承擔兩件事：`GET` 顯示空表單，`POST` 在原地 render 結果。
    ⛔ 表單**永遠**顯示，查詢後也是——不然客人要改一個字就得回上一頁重打。

    ⛔ 這一頁只顯示 `PublicOrderPresenter` 的 allowlist 欄位。它拿到的是一個
    純陣列，不是 Order model——Blade 因此**沒有辦法**取用 Email、手機、
    交付目標或任何 provider 資料，即使有人日後在這裡加一行也取不到。

    ⛔ noindex 有三層：route 的 `NeverIndex` middleware、controller 的
    `X-Robots-Tag` header，以及下面 `isPreview` 產生的 meta robots。這一頁是
    「輸入 Email 與手機」的入口，不該出現在搜尋結果裡。

    ⛔ 不設可索引 canonical、不進 sitemap。
--}}
@extends('layouts.app', [
    'title' => '訂單查詢｜IGLIKEFOLLOW',
    'description' => '查詢您在 IGLIKEFOLLOW 的訂單進度。',
    {{-- ⛔ `isPreview` 讓 layout 輸出 `noindex, nofollow` 的 meta robots。 --}}
    'isPreview' => true,
])

@section('content')
<main>
    <section class="mx-auto w-full max-w-3xl px-4 py-12 sm:px-6 sm:py-16">
        {{-- ⛔ 單一 H1。 --}}
        <h1 class="text-3xl font-bold tracking-[-0.03em] sm:text-4xl">訂單查詢</h1>

        <p class="mt-3 text-base leading-7 text-black/70">
            輸入訂單編號、Email、手機號碼其中兩項，即可查看目前處理進度。
        </p>

        {{--
            ⛔ POST 回同一個 URL。Email 與手機絕不能進 URL、query string 或
            referrer——所以不是 GET，也不是 redirect 後再顯示。

            ⛔ 不用 `old()` 回填：那需要 session flash，而 flash 會把 Email
            與手機寫進 session 儲存體。客人重打一次的成本，遠低於把 PII 落地。
        --}}
        <form method="POST" action="{{ route('order-check.lookup') }}" class="mt-8 space-y-5">
            @csrf

            <div>
                <label for="lookup-reference" class="block text-sm font-medium text-black">訂單編號</label>
                <input type="text" id="lookup-reference" name="reference" autocomplete="off"
                       placeholder="IGL-XXXXXXXXXXXX"
                       class="mt-2 block min-h-11 w-full rounded-lg border border-black/15 px-3 py-2 text-base">
            </div>

            <div>
                <label for="lookup-email" class="block text-sm font-medium text-black">Email</label>
                <input type="email" id="lookup-email" name="email" autocomplete="email"
                       class="mt-2 block min-h-11 w-full rounded-lg border border-black/15 px-3 py-2 text-base">
            </div>

            <div>
                <label for="lookup-phone" class="block text-sm font-medium text-black">手機號碼</label>
                <input type="tel" id="lookup-phone" name="phone" autocomplete="tel"
                       class="mt-2 block min-h-11 w-full rounded-lg border border-black/15 px-3 py-2 text-base">
                {{--
                    ⛔ 舊句是「請與下單時填寫的格式一致」——它與實際行為矛盾。

                    `ContactLookupHash::normalizePhone()` 已把同一支台灣手機的
                    `09XXXXXXXX`／`+8869XXXXXXXX`／`008869XXXXXXXX` 封閉正規化
                    為同一個值，客人**不需要**打出跟下單時一模一樣的格式。

                    ⛔ 錯誤的提示比沒有提示更糟：它會讓查不到的人以為原因是
                    格式不同，於是反覆換格式重試，而真正的原因（例如少打一碼、
                    用了另一支門號）完全沒被指出來。
                --}}
                <p class="mt-2 text-sm text-black/55">請輸入完整手機號碼；台灣手機可使用 09、+886 或 00886 格式。</p>
            </div>

            <button type="submit"
                    class="inline-flex min-h-11 items-center rounded-lg bg-black px-5 py-2.5 text-base font-semibold text-white">
                查詢訂單
            </button>
        </form>

        @if ($submitted)
            <div class="mt-12 border-t border-black/10 pt-10">
                @if ($notFound)
                    {{--
                        ⛔ 通用訊息：查無、條件不符、少於兩項、格式錯誤——全部同一句。
                        分開的訊息可以被拿來逐一確認「這個 Email 有沒有在本站下過單」。
                    --}}
                    <p class="rounded-lg bg-black/5 p-4 text-sm leading-6 text-black/70">
                        查不到符合的訂單。請確認填寫的訂單編號、Email 與手機號碼是否與下單時一致，並至少填寫其中兩項。
                    </p>
                @else
                    <h2 class="text-xl font-bold text-black">查詢結果</h2>
                    <p class="mt-2 text-sm text-black/60">共 {{ count($results) }} 筆訂單。</p>

                    <div class="mt-6 space-y-6">
                        @foreach ($results as $order)
                            <article class="rounded-xl border border-black/10 p-5">
                                <header>
                                    <h3 class="text-base font-bold break-words text-black">
                                        訂單編號 {{ $order['reference'] }}
                                    </h3>
                                </header>

                                {{--
                                    ⛔ 390px 不得依賴橫向捲動閱讀：手機堆疊卡片、
                                    `sm` 以上才展開多欄。⛔ 不用固定最小寬度的表格。
                                --}}
                                <ul class="mt-4 divide-y divide-black/5">
                                    @foreach ($order['items'] as $item)
                                        <li class="py-4">
                                            <p class="font-medium break-words text-black">
                                                {{ $item['platform'] }}｜{{ $item['service'] }}
                                            </p>
                                            <p class="mt-0.5 text-sm break-words text-black/55">{{ $item['variant'] }}</p>

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
            </div>
        @endif
    </section>
</main>
@endsection
