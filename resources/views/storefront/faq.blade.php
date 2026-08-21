@extends('layouts.app', [
    'title' => $title,
    'description' => $description,
])

@section('content')
<main>
    <nav aria-label="麵包屑" class="mx-auto max-w-[1320px] px-5 pt-7 sm:px-8">
        <ol class="flex flex-wrap items-center gap-2 text-sm text-black/55">
            <li><a href="{{ route('home') }}" class="hover:text-ink hover:underline">首頁</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="font-semibold text-ink">常見問題</li>
        </ol>
    </nav>

    <section class="mx-auto max-w-3xl px-5 py-10 sm:px-8 lg:py-14">
        <p class="eyebrow">FAQ</p>
        {{-- ⛔ 固定 H1:不搶 `IG 買粉絲`／`IG 買讚` 等商品頁主要交易詞。 --}}
        <h1 class="mt-4 text-4xl font-bold tracking-[-0.04em] sm:text-5xl">{{ $h1 }}</h1>
        <p class="mt-5 text-lg leading-8 text-black/60">{{ $intro }}</p>
    </section>

    @if ($faqs->isNotEmpty())
        <section class="border-t border-black/10 bg-white">
            <div class="mx-auto max-w-3xl px-5 py-12 sm:px-8 lg:py-16">
                <h2 class="text-2xl font-bold tracking-[-0.035em] sm:text-3xl">購買與訂單共通問題</h2>
                {{-- accordion 收合後文字仍在初始 HTML 的 DOM 內;問題為 h3 可讀 heading。 --}}
                <div class="mt-6 divide-y divide-black/10 border-y border-black/10">
                    @foreach ($faqs as $faq)
                        <details class="group py-5">
                            <summary class="min-h-11 cursor-pointer list-none">
                                <h3 class="text-base font-bold sm:text-lg">{{ $faq->question }}</h3>
                            </summary>
                            <p class="mt-3 text-base leading-7 text-black/70">{{ $faq->answer }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{--
        平台／商品專屬問答只在各自 owner 頁完整顯示,⛔ 這裡不複製全文,
        只提供描述性 anchor,讓讀者一跳到唯一 owner。
    --}}
    <section class="border-t border-black/10 bg-paper">
        <div class="mx-auto max-w-3xl px-5 py-12 sm:px-8 lg:py-16">
            <h2 class="text-2xl font-bold tracking-[-0.035em] sm:text-3xl">各平台與商品的專屬問題</h2>
            <p class="mt-4 text-base leading-7 text-black/70">
                想知道各平台服務怎麼選，或某項商品要提供哪一種網址，請前往對應頁面查看該項服務的說明與問答。
            </p>

            <div class="mt-8 space-y-8">
                @foreach ($platforms as $navPlatform)
                    <div>
                        <h3 class="text-lg font-bold">
                            <a class="hover:underline" href="{{ route('platform', $navPlatform->slug) }}">
                                {{ $navPlatform->name }} 服務怎麼選？
                            </a>
                        </h3>
                        @if ($navPlatform->services->isNotEmpty())
                            <ul class="mt-3 space-y-2 text-sm leading-6 text-black/70">
                                @foreach ($navPlatform->services as $navService)
                                    <li>
                                        {{-- D-103:一律直達 /product/ canonical owner。 --}}
                                        <a class="hover:text-ink hover:underline" href="{{ $navService->primaryUrl() }}">
                                            {{ $navService->card_title ?: $navService->name }}的下單說明與常見問題
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-3 text-sm text-black/60">服務資料準備中。</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="border-t border-black/10 bg-white">
        <div class="mx-auto max-w-3xl px-5 py-12 sm:px-8">
            <h2 class="text-2xl font-bold tracking-[-0.035em]">還沒找到答案？</h2>
            <p class="mt-4 text-base leading-7 text-black/70">
                訂單相關問題請保留訂單編號並聯絡客服，客服會依當時處理進度確認可以協助的內容。
            </p>
            <a href="{{ route('home') }}#platforms" class="primary-button mt-6 inline-flex">查看全部服務</a>
        </div>
    </section>
</main>
@endsection
