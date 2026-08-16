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
        <div class="mx-auto flex max-w-[1320px] gap-1 overflow-x-auto px-5 sm:px-8">
            @foreach (app(\App\Support\CatalogRepository::class)->navigablePlatforms() as $tab)
                @php $isCurrent = $tab->slug === $platform->slug; @endphp
                <a href="{{ route('platform', $tab->slug) }}"
                   @if ($isCurrent) aria-current="page" @endif
                   class="platform-tab {{ $isCurrent ? 'platform-tab--active' : '' }}">
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

    {{-- Hero：非對稱雙欄，右側為自建 CSS/SVG 抽象元素 --}}
    <section class="mx-auto max-w-[1320px] px-5 py-10 sm:px-8 lg:grid lg:grid-cols-[1.15fr_0.85fr] lg:items-center lg:gap-14 lg:py-14">
        <div>
            <p class="eyebrow">{{ $platform->eyebrow ?: $platform->name }}</p>
            <h1 class="mt-4 text-[clamp(2.1rem,3.6vw,3.4rem)] font-bold leading-[1.08] tracking-[-0.045em]">
                {{ $platform->h1 ?: $platform->name . ' 社群成長服務' }}
            </h1>
            <p class="mt-5 max-w-xl text-base leading-8 text-black/60 sm:text-lg">
                {{ $platform->tagline }}
            </p>

            {{-- 「詳細介紹」屬於平台頁最上方的內容，接在一句話介紹之後。
                 ⛔ 原本只輸出在頁面最下方（約 79% 處），管理者填了會找不到。 --}}
            @if (filled($platform->intro))
                <p class="mt-4 max-w-xl whitespace-pre-line leading-8 text-black/60">
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
        @else
            {{-- 自建抽象視覺：純 SVG，不使用任何競品或平台官方素材 --}}
            <div class="mt-10 lg:mt-0" aria-hidden="true">
                <svg viewBox="0 0 420 300" class="h-auto w-full max-w-[460px]" role="presentation" focusable="false">
                    <defs>
                        <linearGradient id="g1" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0%" stop-color="#10110f" stop-opacity="0.09"/>
                            <stop offset="100%" stop-color="#165b45" stop-opacity="0.16"/>
                        </linearGradient>
                    </defs>
                    <rect x="14" y="26" width="392" height="248" rx="28" fill="url(#g1)"/>
                    <rect x="46" y="58" width="150" height="12" rx="6" fill="#10110f" opacity="0.30"/>
                    <rect x="46" y="82" width="96" height="10" rx="5" fill="#10110f" opacity="0.16"/>
                    <circle cx="322" cy="96" r="42" fill="none" stroke="#165b45" stroke-width="2.5" opacity="0.5"/>
                    <circle cx="322" cy="96" r="26" fill="#165b45" opacity="0.14"/>
                    <rect x="46" y="132" width="118" height="104" rx="18" fill="#ffffff" opacity="0.85"/>
                    <rect x="176" y="132" width="118" height="104" rx="18" fill="#ffffff" opacity="0.6"/>
                    <rect x="306" y="132" width="68" height="104" rx="18" fill="#ffffff" opacity="0.38"/>
                    <rect x="62" y="152" width="60" height="9" rx="4.5" fill="#10110f" opacity="0.28"/>
                    <rect x="62" y="170" width="82" height="9" rx="4.5" fill="#10110f" opacity="0.14"/>
                    <path d="M62 214 L92 196 L118 206 L146 180" fill="none" stroke="#165b45" stroke-width="3"
                          stroke-linecap="round" stroke-linejoin="round" opacity="0.75"/>
                </svg>
            </div>
        @endif
    </section>

    @if ($isAvailable)
        <section class="border-t border-black/10 bg-white">
            <div class="mx-auto max-w-[1320px] px-5 py-12 sm:px-8 lg:py-16">
                <h2 class="text-2xl font-bold tracking-[-0.035em] sm:text-3xl">選擇服務</h2>

                @if ($featured)
                    <a href="{{ route('service', [$platform->slug, $featured->slug]) }}"
                       class="service-card service-card--featured mt-7">
                        <div class="flex flex-wrap items-start justify-between gap-5">
                            <div class="max-w-xl">
                                <span class="inline-flex rounded-full bg-trust/12 px-3 py-1 text-xs font-bold text-trust">
                                    主打服務
                                </span>
                                <h3 class="mt-4 text-2xl font-bold tracking-[-0.03em] sm:text-3xl">
                                    {{ str($featured->card_title ?: $featured->name)->after($platform->name . ' ')->toString() ?: $featured->name }}
                                </h3>
                                <p class="mt-3 leading-7 text-black/60">{{ $featured->card_blurb ?: $featured->summary }}</p>
                                <p class="mt-4 text-sm text-black/55">
                                    {{ $featured->variants->pluck('label')->join('、') }}
                                </p>
                            </div>
                            <span class="service-card__arrow" aria-hidden="true">→</span>
                        </div>
                    </a>
                @endif

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @foreach ($rest as $service)
                        <a href="{{ route('service', [$platform->slug, $service->slug]) }}" class="service-card">
                            @if ($service->card_image_path)
                                <img src="{{ \Illuminate\Support\Facades\Storage::url($service->card_image_path) }}"
                                     alt="{{ $service->card_image_alt }}"
                                     class="mb-4 aspect-[4/3] w-full rounded-xl object-cover" loading="lazy">
                            @endif
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <h3 class="text-lg font-bold tracking-[-0.02em]">
                                        {{ str($service->card_title ?: $service->name)->after($platform->name . ' ')->toString() ?: $service->name }}
                                    </h3>
                                    <p class="mt-2 text-sm leading-6 text-black/60">
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
                                            <a href="{{ route('service', [$platform->slug, $service->slug]) }}"
                                               class="inline-flex min-h-11 items-center text-black/65 transition-colors duration-200 hover:text-ink hover:underline">
                                                {{ str($service->name)->after($platform->name . ' ')->toString() ?: $service->name }}
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
                <h2 class="text-2xl font-bold tracking-[-0.035em] sm:text-3xl">服務比較</h2>
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
                                        <a href="{{ route('service', [$platform->slug, $service->slug]) }}"
                                           class="hover:text-trust hover:underline">
                                            {{ str($service->name)->after($platform->name . ' ')->toString() ?: $service->name }}
                                        </a>
                                    </th>
                                    <td class="py-4 pr-4 text-black/60">{{ $service->goal ?: '—' }}</td>
                                    <td class="py-4 pr-4 text-black/60">{{ $service->input_label }}</td>
                                    <td class="py-4 leading-6 text-black/60">{{ $service->delivery_summary }}</td>
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
                            <details class="group py-5">
                                <summary class="min-h-11 cursor-pointer list-none text-base font-bold">{{ $faq->question }}</summary>
                                <p class="mt-3 leading-7 text-black/60">{{ $faq->answer }}</p>
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
                    <p class="mt-4 leading-8 text-black/60">
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
