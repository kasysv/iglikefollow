@extends('layouts.app', [
    'title' => '社群成長服務｜Instagram、Facebook｜IGLIKEFOLLOW',
    'description' => $settings?->home_intro
        ?: 'IGLIKEFOLLOW 提供 Instagram 與 Facebook 的粉絲、讚、留言與影片觀看服務。選擇平台與服務後即可快速結帳。',
])

@section('content')
<main>
    <section class="mx-auto max-w-[1220px] px-5 py-12 sm:px-8 lg:py-20">
        <div class="max-w-4xl">
            <p class="eyebrow">{{ $settings?->home_eyebrow ?: 'Social growth services' }}</p>
            <h1 class="mt-5 text-[clamp(2.6rem,6vw,5.4rem)] font-bold leading-[1.02] tracking-[-0.05em]">
                {{ $settings?->home_h1 ?: '多平台社群服務，一次選好。' }}
            </h1>
            <p class="mt-6 max-w-2xl text-base leading-8 text-black/60 sm:text-lg">
                {{ $settings?->home_intro
                    ?: 'IGLIKEFOLLOW 提供 Instagram 與 Facebook 的粉絲、讚、留言與影片觀看服務。先選擇平台，再選擇需要的服務類型，最後挑選數量方案並免會員結帳。' }}
            </p>
            <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                <a href="#platforms"
                   class="inline-flex min-h-14 items-center justify-center rounded-full bg-ink px-7 text-base font-bold text-white transition-colors duration-200 hover:bg-black">
                    {{ $settings?->primary_cta_label ?: '選擇平台服務' }}
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

    <section id="platforms" class="scroll-mt-24 border-t border-black/10 bg-white">
        <div class="mx-auto max-w-[1220px] px-5 py-16 sm:px-8 lg:py-20">
            <p class="eyebrow">Step 1</p>
            <h2 class="mt-4 text-4xl font-bold tracking-[-0.045em] sm:text-5xl">選擇平台</h2>
            <p class="mt-4 max-w-2xl leading-8 text-black/60">
                每個平台的服務內容與交付方式不同，請先選擇要成長的平台。
            </p>

            @if ($platforms->isEmpty())
                {{-- ⛔ 資料庫沒有內容時顯示誠實空狀態，不回退 config fixture。 --}}
                <div class="surface mt-10 p-7">
                    <p class="font-bold">服務資料準備中</p>
                    <p class="mt-2 leading-7 text-black/60">目前沒有已發布的平台服務。</p>
                </div>
            @else
                <div class="mt-10 grid gap-5 lg:grid-cols-3">
                    @foreach ($platforms as $platform)
                        @php $isOpen = $platform->status === 'published' && $platform->services->isNotEmpty(); @endphp
                        <article class="surface flex flex-col p-6 transition-shadow duration-200 hover:shadow-[0_28px_70px_rgba(16,17,15,0.11)] sm:p-7">
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="text-2xl font-bold tracking-[-0.03em]">{{ $platform->name }}</h3>
                                @unless ($isOpen)
                                    <span class="shrink-0 rounded-full bg-mist px-3 py-1 text-xs font-bold text-black/55">準備中</span>
                                @endunless
                            </div>
                            <p class="mt-3 leading-7 text-black/60">{{ $platform->tagline }}</p>

                            @if ($isOpen)
                                <ul class="mt-6 flex-1 space-y-2.5 border-t border-black/10 pt-5 text-sm text-black/70">
                                    @foreach ($platform->services as $service)
                                        <li class="flex gap-2.5">
                                            <span aria-hidden="true" class="mt-2 h-1 w-1 shrink-0 rounded-full bg-black/25"></span>
                                            <span>{{ $service->name }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                <a href="{{ route('platform', $platform->slug) }}"
                                   class="mt-7 inline-flex min-h-14 items-center justify-center rounded-full bg-ink px-6 text-base font-bold text-white transition-colors duration-200 hover:bg-black">
                                    查看 {{ $platform->name }} 服務
                                </a>
                            @else
                                <div class="mt-6 flex-1 rounded-2xl border border-dashed border-black/15 bg-paper p-5">
                                    <p class="text-sm leading-6 text-black/55">
                                        {{ $platform->intro ?: '服務資料準備中，開放前不會顯示方案或價格。' }}
                                    </p>
                                </div>
                                <a href="{{ route('platform', $platform->slug) }}"
                                   class="mt-7 inline-flex min-h-14 items-center justify-center rounded-full border border-black/15 bg-white px-6 text-base font-bold transition-colors duration-200 hover:border-ink">
                                    查看說明
                                </a>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
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

    @if ($faqs->isNotEmpty())
        <section id="faq" class="border-t border-black/10 bg-white">
            <div class="mx-auto max-w-3xl px-5 py-16 sm:px-8 lg:py-20">
                <p class="eyebrow">FAQ</p>
                <h2 class="mt-4 text-4xl font-bold tracking-[-0.04em]">購買前常見問題</h2>
                <div class="mt-8 divide-y divide-black/10 border-y border-black/10">
                    @foreach ($faqs as $faq)
                        <details class="group py-5">
                            <summary class="min-h-11 cursor-pointer list-none text-lg font-bold">{{ $faq->question }}</summary>
                            <p class="mt-3 leading-7 text-black/60">{{ $faq->answer }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</main>
@endsection
