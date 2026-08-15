@extends('layouts.app', [
    'title' => '社群成長服務｜Instagram、Facebook｜IGLIKEFOLLOW',
    'description' => 'IGLIKEFOLLOW 提供 Instagram 與 Facebook 的粉絲、讚、留言與影片觀看服務。選擇平台與服務後即可快速結帳。本頁為本機開發預覽。',
])

@section('content')
<main>
    <section class="mx-auto max-w-[1220px] px-5 py-12 sm:px-8 lg:py-20">
        <div class="max-w-4xl">
            <p class="eyebrow">Social growth services</p>
            <h1 class="mt-5 text-[clamp(2.6rem,6vw,5.4rem)] font-bold leading-[1.02] tracking-[-0.05em]">
                多平台社群服務，<br>一次選好。
            </h1>
            <p class="mt-6 max-w-2xl text-base leading-8 text-black/60 sm:text-lg">
                IGLIKEFOLLOW 提供 Instagram 與 Facebook 的粉絲、讚、留言與影片觀看服務。
                先選擇平台，再選擇需要的服務類型，最後挑選數量方案並免會員結帳。
            </p>
            <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                <a href="#platforms"
                   class="inline-flex min-h-14 items-center justify-center rounded-full bg-ink px-7 text-base font-bold text-white transition-colors duration-200 hover:bg-black">
                    選擇平台服務
                </a>
                <a href="#process"
                   class="inline-flex min-h-14 items-center justify-center rounded-full border border-black/15 bg-white px-7 text-base font-bold transition-colors duration-200 hover:border-ink">
                    了解購買流程
                </a>
            </div>
            <div class="mt-10 grid max-w-3xl gap-3 border-y border-black/10 py-5 text-sm sm:grid-cols-3">
                <div><strong class="block text-base">免會員結帳</strong><span class="text-black/55">不需註冊即可下單</span></div>
                <div><strong class="block text-base">後端重新驗價</strong><span class="text-black/55">不信任前端送出的價格</span></div>
                <div><strong class="block text-base">服務分類清楚</strong><span class="text-black/55">依平台與服務類型選擇</span></div>
            </div>
        </div>
    </section>

    <section id="platforms" class="border-t border-black/10 bg-white scroll-mt-24">
        <div class="mx-auto max-w-[1220px] px-5 py-16 sm:px-8 lg:py-20">
            <p class="eyebrow">Step 1</p>
            <h2 class="mt-4 text-4xl font-bold tracking-[-0.045em] sm:text-5xl">選擇平台</h2>
            <p class="mt-4 max-w-2xl leading-8 text-black/60">
                每個平台的服務內容與交付方式不同，請先選擇要成長的平台。
            </p>

            <div class="mt-10 grid gap-5 lg:grid-cols-3">
                @foreach ($platforms as $platform)
                    <article class="surface flex flex-col p-6 transition-shadow duration-200 hover:shadow-[0_28px_70px_rgba(16,17,15,0.11)] sm:p-7">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-2xl font-bold tracking-[-0.03em]">{{ $platform['name'] }}</h3>
                            @unless ($platform['available'])
                                <span class="shrink-0 rounded-full bg-mist px-3 py-1 text-xs font-bold text-black/55">準備中</span>
                            @endunless
                        </div>
                        <p class="mt-3 leading-7 text-black/60">{{ $platform['tagline'] }}</p>

                        @if ($platform['available'])
                            <ul class="mt-6 flex-1 space-y-2.5 border-t border-black/10 pt-5 text-sm text-black/70">
                                @foreach ($platform['services'] as $service)
                                    <li class="flex gap-2.5">
                                        <span aria-hidden="true" class="mt-2 h-1 w-1 shrink-0 rounded-full bg-black/25"></span>
                                        <span>{{ $service['name'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            <a href="{{ route('platform', $platform['slug']) }}"
                               class="mt-7 inline-flex min-h-14 items-center justify-center rounded-full bg-ink px-6 text-base font-bold text-white transition-colors duration-200 hover:bg-black">
                                查看 {{ $platform['name'] }} 服務
                            </a>
                        @else
                            <div class="mt-6 flex-1 rounded-2xl border border-dashed border-black/15 bg-paper p-5">
                                <p class="text-sm leading-6 text-black/55">{{ $platform['unavailable_note'] }}</p>
                            </div>
                            <a href="{{ route('platform', $platform['slug']) }}"
                               class="mt-7 inline-flex min-h-14 items-center justify-center rounded-full border border-black/15 bg-white px-6 text-base font-bold transition-colors duration-200 hover:border-ink">
                                查看說明
                            </a>
                        @endif
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section id="process" class="mx-auto max-w-[1220px] scroll-mt-24 px-5 py-16 sm:px-8 lg:py-20">
        <p class="eyebrow">How it works</p>
        <h2 class="mt-4 max-w-3xl text-4xl font-bold tracking-[-0.045em] sm:text-5xl">四個步驟，完成訂單。</h2>
        <div class="mt-10 grid gap-px overflow-hidden rounded-[1.75rem] bg-black/10 sm:grid-cols-2 lg:grid-cols-4">
            <article class="bg-white p-7">
                <span class="text-sm text-black/45">01</span>
                <h3 class="mt-10 text-xl font-bold">選擇平台</h3>
                <p class="mt-3 leading-7 text-black/55">先決定要成長的社群平台。</p>
            </article>
            <article class="bg-white p-7">
                <span class="text-sm text-black/45">02</span>
                <h3 class="mt-10 text-xl font-bold">選擇服務</h3>
                <p class="mt-3 leading-7 text-black/55">粉絲、讚、留言或影片觀看。</p>
            </article>
            <article class="bg-white p-7">
                <span class="text-sm text-black/45">03</span>
                <h3 class="mt-10 text-xl font-bold">選擇方案</h3>
                <p class="mt-3 leading-7 text-black/55">在同一頁比較數量與價格。</p>
            </article>
            <article class="bg-white p-7">
                <span class="text-sm text-black/45">04</span>
                <h3 class="mt-10 text-xl font-bold">快速結帳</h3>
                <p class="mt-3 leading-7 text-black/55">免會員填寫目標並完成付款。</p>
            </article>
        </div>
    </section>

    <section id="faq" class="border-t border-black/10 bg-white">
        <div class="mx-auto max-w-3xl px-5 py-16 sm:px-8 lg:py-20">
            <p class="eyebrow">FAQ</p>
            <h2 class="mt-4 text-4xl font-bold tracking-[-0.04em]">購買前常見問題</h2>
            <div class="mt-8 divide-y divide-black/10 border-y border-black/10">
                <details class="group py-5">
                    <summary class="min-h-11 cursor-pointer list-none text-lg font-bold">需要註冊會員嗎？</summary>
                    <p class="mt-3 leading-7 text-black/60">不需要。正式流程會以免會員快速結帳為基線。</p>
                </details>
                <details class="group py-5">
                    <summary class="min-h-11 cursor-pointer list-none text-lg font-bold">單篇貼文讚與自動貼文讚差在哪裡？</summary>
                    <p class="mt-3 leading-7 text-black/60">
                        單篇貼文讚是輸入一條貼文網址，一次性交付該篇的讚數。
                        自動貼文讚是輸入公開帳號並預付篇數，之後發布的新貼文依序自動交付，用完為止。
                    </p>
                </details>
                <details class="group py-5">
                    <summary class="min-h-11 cursor-pointer list-none text-lg font-bold">付款後會立即建立訂單嗎？</summary>
                    <p class="mt-3 leading-7 text-black/60">正式版必須由後端驗證綠界或 LINE Pay 付款成功後才建立履約流程，不能只相信前端成功頁。</p>
                </details>
                <details class="group py-5">
                    <summary class="min-h-11 cursor-pointer list-none text-lg font-bold">現在可以真的付款嗎？</summary>
                    <p class="mt-3 leading-7 text-black/60">不可以。目前是本機 mock 骨架，沒有連接任何正式金流或下單平台。</p>
                </details>
            </div>
        </div>
    </section>
</main>
@endsection
