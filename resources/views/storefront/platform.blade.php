@php
    $services = $platform->services;
    $featured = $services->firstWhere('is_featured', true);
    $rest = $featured ? $services->reject(fn ($s) => $s->is($featured)) : $services;
    $goals = $services->filter(fn ($s) => filled($s->goal))->groupBy('goal');
    $isAvailable = $services->isNotEmpty();
@endphp

@extends('layouts.app', [
    'title' => $platform->seo_title ?: $platform->name . ' 社群成長服務｜IGLIKEFOLLOW',
    'description' => $platform->meta_description ?: $platform->tagline,
])

@section('content')
<main>
    @if (! empty($isPreview))
        <p class="bg-amber-100 px-5 py-3 text-center text-sm font-bold text-amber-900">
            草稿預覽模式：此頁包含尚未發布的內容，不會對外公開。
        </p>
    @endif

    {{-- 平台切換：真實連結，非 JS tab --}}
    <nav aria-label="平台切換" class="border-b border-black/10 bg-white">
        {{-- tab 列允許在自己的容器內水平捲動,⛔ 不得造成頁面級 overflow。 --}}
        <div data-probe="platform-tabs" data-allow-xscroll
             class="mx-auto flex max-w-[1320px] gap-1 overflow-x-auto px-5 sm:px-8">
            @foreach (app(\App\Support\CatalogRepository::class)->navigablePlatforms() as $tab)
                @php $isCurrent = $tab->slug === $platform->slug; @endphp
                <a href="{{ route('platform', $tab->slug) }}"
                   @if ($isCurrent) aria-current="page" @endif
                   class="platform-tab {{ $isCurrent ? 'platform-tab--active' : '' }}">
                    {{-- M2-D-A:20px 裝飾性 Logo 幫助快速辨識平台;平台名稱仍是文字。
                         R1:間距由 .platform-tab 的 gap-2 提供(元件不輸出 $attributes)。 --}}
                    <x-platform-brand-icon :slug="$tab->slug" size="sm" />
                    {{ $tab->name }}
                    @if ($tab->status !== 'published')
                        <span class="ml-1.5 text-xs font-medium opacity-55">準備中</span>
                    @endif
                </a>
            @endforeach
        </div>
    </nav>

    <nav aria-label="麵包屑" class="mx-auto max-w-[1320px] px-5 pt-7 sm:px-8">
        <ol class="flex flex-wrap items-center gap-2 text-sm text-black/55">
            <li><a href="{{ route('home') }}" class="hover:text-ink hover:underline">首頁</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="font-semibold text-ink">{{ $platform->name }}</li>
        </ol>
    </nav>

    {{--
        Hero：有真實圖片才用非對稱雙欄；沒有就是單欄。

        ⭐ R2（Owner 指定）：沒有真實 `hero_image_path` 時的右側抽象 SVG 假圖
        已**完整刪除**，⛔ 不是 CSS 隱藏、⛔ 也不換另一張 placeholder。

        ⛔ 雙欄 class 必須跟著條件走。只刪 SVG 而留下 `lg:grid-cols-[…]`，
        桌面會空出一個 0.85fr 的死欄位，文字被擠在左半邊——那是「假圖沒了但
        版面壞了」，不是 Owner 要的結果。
    --}}
    <section @class([
        'mx-auto max-w-[1320px] px-5 py-10 sm:px-8 lg:py-14',
        'lg:grid lg:grid-cols-[1.15fr_0.85fr] lg:items-center lg:gap-14' => (bool) $platform->hero_image_path,
    ])>
        <div>
            {{-- M2-D-A:Hero 只放一次 28px 裝飾性 Logo,⛔ 不在每張服務卡重複。 --}}
            <p class="flex items-center gap-2">
                <x-platform-brand-icon :slug="$platform->slug" size="md" />
                <span class="eyebrow">{{ $platform->eyebrow ?: $platform->name }}</span>
            </p>
            <h1 class="mt-4 text-[clamp(2.1rem,3.6vw,3.4rem)] font-bold leading-[1.08] tracking-[-0.045em]">
                {{ $platform->h1 ?: $platform->name . ' 社群成長服務' }}
            </h1>
            {{--
                ⭐ 無圖時放寬到 `max-w-3xl`（約 65–75 字元），⛔ 不是放到整列寬。

                單欄之後若沿用 `max-w-xl`，文字會縮在整列的左三分之一，看起來
                像右邊還有東西沒載入；但放到滿版又會讓行寬超過可讀範圍。
            --}}
            <p @class([
                'mt-5 text-base leading-8 text-black/70 sm:text-lg',
                'max-w-xl' => (bool) $platform->hero_image_path,
                'max-w-3xl' => ! $platform->hero_image_path,
            ])>
                {{ $platform->tagline }}
            </p>

            {{-- 「詳細介紹」屬於平台頁最上方的內容，接在一句話介紹之後。
                 ⛔ 原本只輸出在頁面最下方（約 79% 處），管理者填了會找不到。 --}}
            @if (filled($platform->intro))
                <p @class([
                    'mt-4 whitespace-pre-line text-base leading-8 text-black/70',
                    'max-w-xl' => (bool) $platform->hero_image_path,
                    'max-w-3xl' => ! $platform->hero_image_path,
                ])>
                    {{ $platform->intro }}
                </p>
            @endif

            @if ($isAvailable && $goals->isNotEmpty())
                <div class="mt-7 flex flex-wrap gap-2">
                    @foreach ($goals->keys() as $goal)
                        <a href="#goals" class="goal-chip">{{ $goal }}</a>
                    @endforeach
                </div>
            @endif
        </div>

        @if ($platform->hero_image_path)
            <div class="mt-10 lg:mt-0">
                <img src="{{ \Illuminate\Support\Facades\Storage::url($platform->hero_image_path) }}"
                     alt="{{ $platform->hero_image_alt }}"
                     class="h-auto w-full rounded-[1.75rem]" loading="lazy">
            </div>
        @endif
        {{-- ⛔ 沒有真實圖片時這裡不輸出任何東西——連空的容器都沒有。 --}}
    </section>

    @if ($isAvailable)
        <section class="border-t border-black/10 bg-white">
            <div class="mx-auto max-w-[1320px] px-5 py-12 sm:px-8 lg:py-16">
                {{-- R3:固定 H2 加入平台名稱 --}}
                <h2 class="text-2xl font-bold tracking-[-0.035em] sm:text-3xl">選擇 {{ $platform->name }} 服務</h2>

                @if ($featured)
                    <a href="{{ $featured->primaryUrl() }}"
                       class="service-card service-card--featured mt-7">
                        <div class="flex flex-wrap items-start justify-between gap-5">
                            <div class="max-w-xl">
                                <span class="featured-badge">
                                    主打服務
                                </span>
                                <h3 class="mt-4 text-2xl font-bold tracking-[-0.03em] sm:text-3xl">
                                    {{ $featured->card_title ?: $featured->name }}
                                </h3>
                                <p class="mt-3 text-base leading-7 text-black/70">{{ $featured->card_blurb ?: $featured->summary }}</p>
                                <p class="mt-4 text-sm leading-6 text-black/65">
                                    {{ $featured->variants->pluck('label')->join('、') }}
                                </p>
                            </div>
                            <span class="service-card__arrow" aria-hidden="true">→</span>
                        </div>
                    </a>
                @endif

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @foreach ($rest as $service)
                        <a href="{{ $service->primaryUrl() }}" class="service-card">
                            @if ($service->card_image_path)
                                <img src="{{ \Illuminate\Support\Facades\Storage::url($service->card_image_path) }}"
                                     alt="{{ $service->card_image_alt }}"
                                     class="mb-4 aspect-[4/3] w-full rounded-xl object-cover" loading="lazy">
                            @endif
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <h3 class="text-lg font-bold tracking-[-0.02em]">
                                        {{ $service->card_title ?: $service->name }}
                                    </h3>
                                    <p class="mt-2 text-base leading-7 text-black/70">
                                        {{ $service->card_blurb ?: $service->summary }}
                                    </p>
                                    @if (filled($service->goal))
                                        <span class="mt-3 inline-flex rounded-full bg-mist px-2.5 py-1 text-xs font-semibold text-black/60">
                                            {{ $service->goal }}
                                        </span>
                                    @endif
                                </div>
                                <span class="service-card__arrow" aria-hidden="true">→</span>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>

        @if ($goals->isNotEmpty())
            <section id="goals" class="scroll-mt-20 border-t border-black/10 bg-paper">
                <div class="mx-auto max-w-[1320px] px-5 py-12 sm:px-8 lg:py-16">
                    <h2 class="text-2xl font-bold tracking-[-0.035em] sm:text-3xl">你想達成什麼目標？</h2>
                    <div class="mt-7 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($goals as $goal => $goalServices)
                            <div class="rounded-2xl border border-black/10 bg-white p-5">
                                <h3 class="font-bold">{{ $goal }}</h3>
                                <ul class="mt-3 space-y-2 text-sm">
                                    @foreach ($goalServices as $service)
                                        <li>
                                            <a href="{{ $service->primaryUrl() }}"
                                               class="inline-flex min-h-11 items-center text-black/65 transition-colors duration-200 hover:text-ink hover:underline">
                                                {{ $service->card_title ?: $service->name }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        <section class="border-t border-black/10 bg-white">
            <div class="mx-auto max-w-[1320px] px-5 py-12 sm:px-8 lg:py-16">
                <h2 class="text-2xl font-bold tracking-[-0.035em] sm:text-3xl">{{ $platform->name }} 服務比較</h2>
                <div class="mt-6 overflow-x-auto">
                    <table class="w-full min-w-[640px] border-collapse text-left text-sm">
                        <caption class="sr-only">{{ $platform->name }} 各服務的交付方式與需填寫欄位比較</caption>
                        <thead>
                            <tr class="border-b border-black/15">
                                <th scope="col" class="py-3 pr-4 font-bold">服務</th>
                                <th scope="col" class="py-3 pr-4 font-bold">適合目標</th>
                                <th scope="col" class="py-3 pr-4 font-bold">需要填寫</th>
                                <th scope="col" class="py-3 font-bold">交付方式</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($services as $service)
                                <tr class="border-b border-black/10 align-top">
                                    <th scope="row" class="py-4 pr-4 font-semibold">
                                        <a href="{{ $service->primaryUrl() }}"
                                           class="hover:text-trust hover:underline">
                                            {{ $service->card_title ?: $service->name }}
                                        </a>
                                    </th>
                                    <td class="py-4 pr-4 text-black/70">{{ $service->goal ?: '—' }}</td>
                                    <td class="py-4 pr-4 text-black/70">{{ $service->input_label }}</td>
                                    <td class="py-4 leading-6 text-black/70">{{ $service->delivery_summary }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- 「詳細介紹」已改在頁面上方的 hero 顯示，⛔ 這裡不再重複輸出同一段文字。 --}}

        @if ($faqs->isNotEmpty())
            <section class="border-t border-black/10 bg-paper">
                <div class="mx-auto max-w-3xl px-5 py-12 sm:px-8 lg:py-16">
                    <h2 class="text-2xl font-bold tracking-[-0.035em] sm:text-3xl">{{ $platform->name }} 常見問題</h2>
                    <div class="mt-6 divide-y divide-black/10 border-y border-black/10">
                        @foreach ($faqs as $faq)
                            {{-- R5:問題以 h3 可讀 heading;收合後答案仍在初始 HTML。 --}}
                            <details class="group py-5">
                                <summary class="min-h-11 cursor-pointer list-none">
                                    <h3 class="text-base font-bold">{{ $faq->question }}</h3>
                                </summary>
                                <p class="mt-3 text-base leading-7 text-black/70">{{ $faq->answer }}</p>
                            </details>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif
    @else
        <section class="border-t border-black/10 bg-white">
            <div class="mx-auto max-w-3xl px-5 py-14 sm:px-8 lg:py-20">
                <div class="surface p-7 sm:p-9">
                    <h2 class="text-2xl font-bold tracking-[-0.03em] sm:text-3xl">服務資料準備中</h2>
                    {{-- 詳細介紹已在上方 hero 顯示，⛔ 這裡不重複；只說明目前沒有方案。 --}}
                    <p class="mt-4 text-base leading-8 text-black/70">
                        目前沒有可販售的方案、價格或交付時間可以提供。等到服務資料確認後，這一頁才會顯示實際內容。
                    </p>
                    <a href="{{ route('home') }}#platforms"
                       class="mt-7 inline-flex min-h-14 items-center justify-center rounded-full border border-black/15 bg-white px-6 text-base font-bold transition-colors duration-200 hover:border-ink">
                        查看其他平台服務
                    </a>
                </div>
            </div>
        </section>
    @endif
</main>
@endsection
