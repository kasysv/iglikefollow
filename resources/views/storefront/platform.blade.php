@php
    $featured = collect($platform['services'])->first(fn ($s) => ! empty($s['featured_service']));
    $rest = collect($platform['services'])->reject(fn ($s) => ! empty($s['featured_service']));
    $goals = collect($platform['services'])
        ->filter(fn ($s) => ! empty($s['goal']))
        ->groupBy('goal');
@endphp

@extends('layouts.app', [
    'title' => $platform['name'] . '社群成長服務｜IGLIKEFOLLOW',
    'description' => $platform['name'] . ' 服務總覽：' . $platform['tagline'] . ' 依照經營目標選擇服務，免會員快速結帳。',
])

@section('content')
<main>
    {{-- 平台切換：真實連結，非 JS tab --}}
    <nav aria-label="平台切換" class="border-b border-black/10 bg-white">
        <div class="mx-auto flex max-w-[1320px] gap-1 overflow-x-auto px-5 sm:px-8">
            @foreach (config('catalog.platforms') as $tab)
                @php $isCurrent = $tab['slug'] === $platform['slug']; @endphp
                <a href="{{ route('platform', $tab['slug']) }}"
                   @if ($isCurrent) aria-current="page" @endif
                   class="platform-tab {{ $isCurrent ? 'platform-tab--active' : '' }}">
                    {{ $tab['name'] }}
                    @unless ($tab['available'])
                        <span class="ml-1.5 text-xs font-medium opacity-55">準備中</span>
                    @endunless
                </a>
            @endforeach
        </div>
    </nav>

    <nav aria-label="麵包屑" class="mx-auto max-w-[1320px] px-5 pt-7 sm:px-8">
        <ol class="flex flex-wrap items-center gap-2 text-sm text-black/55">
            <li><a href="{{ route('home') }}" class="hover:text-ink hover:underline">首頁</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="font-semibold text-ink">{{ $platform['name'] }}</li>
        </ol>
    </nav>

    {{-- Hero：非對稱雙欄，右側為自建 CSS/SVG 抽象視覺 --}}
    <section class="mx-auto max-w-[1320px] px-5 py-10 sm:px-8 lg:grid lg:grid-cols-[1.15fr_0.85fr] lg:items-center lg:gap-14 lg:py-14">
        <div>
            <p class="eyebrow">{{ $platform['name'] }}</p>
            <h1 class="mt-4 text-[clamp(2.1rem,3.6vw,3.4rem)] font-bold leading-[1.08] tracking-[-0.045em]">
                {{ $platform['name'] }} 社群成長服務
            </h1>
            <p class="mt-5 max-w-xl text-base leading-8 text-black/60 sm:text-lg">
                依照經營目標，選擇適合的服務。所有服務皆為免會員結帳，付款成功由後端驗證後才建立履約流程。
            </p>
            @if ($platform['available'])
                <div class="mt-7 flex flex-wrap gap-2">
                    @foreach ($goals->keys() as $goal)
                        <a href="#goals" class="goal-chip">{{ $goal }}</a>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- 自建抽象視覺：純 CSS/SVG，不使用任何競品或平台官方素材 --}}
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
    </section>

    @if ($platform['available'])
        <section class="border-t border-black/10 bg-white">
            <div class="mx-auto max-w-[1320px] px-5 py-12 sm:px-8 lg:py-16">
                <h2 class="text-2xl font-bold tracking-[-0.035em] sm:text-3xl">選擇服務</h2>

                {{-- 主打卡：整張 <a> 可點 --}}
                @if ($featured)
                    <a href="{{ route('service', [$platform['slug'], $featured['slug']]) }}"
                       class="service-card service-card--featured mt-7">
                        <div class="flex flex-wrap items-start justify-between gap-5">
                            <div class="max-w-xl">
                                <span class="inline-flex rounded-full bg-trust/12 px-3 py-1 text-xs font-bold text-trust">
                                    主打服務
                                </span>
                                <h3 class="mt-4 text-2xl font-bold tracking-[-0.03em] sm:text-3xl">
                                    {{ str($featured['name'])->after($platform['name'] . ' ')->toString() ?: $featured['name'] }}
                                </h3>
                                <p class="mt-3 leading-7 text-black/60">{{ $featured['card_blurb'] ?? $featured['summary'] }}</p>
                                <p class="mt-4 text-sm text-black/55">
                                    {{ collect($featured['variants'])->pluck('label')->join('、') }}
                                </p>
                            </div>
                            <span class="service-card__arrow" aria-hidden="true">→</span>
                        </div>
                    </a>
                @endif

                {{-- 其餘服務：2×2 緊湊卡，無孤卡 --}}
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @foreach ($rest as $service)
                        <a href="{{ route('service', [$platform['slug'], $service['slug']]) }}" class="service-card">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <h3 class="text-lg font-bold tracking-[-0.02em]">
                                        {{ str($service['name'])->after($platform['name'] . ' ')->toString() ?: $service['name'] }}
                                    </h3>
                                    <p class="mt-2 text-sm leading-6 text-black/60">
                                        {{ $service['card_blurb'] ?? $service['summary'] }}
                                    </p>
                                    @if (! empty($service['goal']))
                                        <span class="mt-3 inline-flex rounded-full bg-mist px-2.5 py-1 text-xs font-semibold text-black/60">
                                            {{ $service['goal'] }}
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

        {{-- 依目標選服務 --}}
        <section id="goals" class="scroll-mt-20 border-t border-black/10 bg-paper">
            <div class="mx-auto max-w-[1320px] px-5 py-12 sm:px-8 lg:py-16">
                <h2 class="text-2xl font-bold tracking-[-0.035em] sm:text-3xl">你想達成什麼目標？</h2>
                <div class="mt-7 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($goals as $goal => $services)
                        <div class="rounded-2xl border border-black/10 bg-white p-5">
                            <h3 class="font-bold">{{ $goal }}</h3>
                            <ul class="mt-3 space-y-2 text-sm">
                                @foreach ($services as $service)
                                    <li>
                                        <a href="{{ route('service', [$platform['slug'], $service['slug']]) }}"
                                           class="inline-flex min-h-11 items-center text-black/65 transition-colors duration-200 hover:text-ink hover:underline">
                                            {{ str($service['name'])->after($platform['name'] . ' ')->toString() ?: $service['name'] }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- 服務比較（初始 HTML 可見） --}}
        <section class="border-t border-black/10 bg-white">
            <div class="mx-auto max-w-[1320px] px-5 py-12 sm:px-8 lg:py-16">
                <h2 class="text-2xl font-bold tracking-[-0.035em] sm:text-3xl">服務比較</h2>
                <div class="mt-6 overflow-x-auto">
                    <table class="w-full min-w-[640px] border-collapse text-left text-sm">
                        <caption class="sr-only">{{ $platform['name'] }} 各服務的交付方式與需填寫欄位比較</caption>
                        <thead>
                            <tr class="border-b border-black/15">
                                <th scope="col" class="py-3 pr-4 font-bold">服務</th>
                                <th scope="col" class="py-3 pr-4 font-bold">適合目標</th>
                                <th scope="col" class="py-3 pr-4 font-bold">需要填寫</th>
                                <th scope="col" class="py-3 font-bold">交付方式</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($platform['services'] as $service)
                                <tr class="border-b border-black/10 align-top">
                                    <th scope="row" class="py-4 pr-4 font-semibold">
                                        <a href="{{ route('service', [$platform['slug'], $service['slug']]) }}"
                                           class="hover:text-trust hover:underline">
                                            {{ str($service['name'])->after($platform['name'] . ' ')->toString() ?: $service['name'] }}
                                        </a>
                                    </th>
                                    <td class="py-4 pr-4 text-black/60">{{ $service['goal'] ?? '—' }}</td>
                                    <td class="py-4 pr-4 text-black/60">{{ $service['input_label'] }}</td>
                                    <td class="py-4 leading-6 text-black/60">{{ $service['delivery'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="border-t border-black/10 bg-paper">
            <div class="mx-auto max-w-3xl px-5 py-12 sm:px-8 lg:py-16">
                <h2 class="text-2xl font-bold tracking-[-0.035em] sm:text-3xl">{{ $platform['name'] }} 常見問題</h2>
                <div class="mt-6 divide-y divide-black/10 border-y border-black/10">
                    <details class="group py-5">
                        <summary class="min-h-11 cursor-pointer list-none text-base font-bold">需要提供密碼嗎？</summary>
                        <p class="mt-3 leading-7 text-black/60">
                            不需要。所有服務只需要公開的帳號或貼文網址，不會要求登入資訊。
                        </p>
                    </details>
                    <details class="group py-5">
                        <summary class="min-h-11 cursor-pointer list-none text-base font-bold">單篇貼文讚與自動貼文讚差在哪裡？</summary>
                        <p class="mt-3 leading-7 text-black/60">
                            單篇貼文讚是輸入一條貼文網址，一次性交付該篇的讚數。
                            自動貼文讚是輸入公開帳號並預付篇數，之後發布的新貼文依序自動交付，用完為止。
                        </p>
                    </details>
                    <details class="group py-5">
                        <summary class="min-h-11 cursor-pointer list-none text-base font-bold">帳號需要公開嗎？</summary>
                        <p class="mt-3 leading-7 text-black/60">
                            需要。帳號或貼文必須為公開狀態才能完成交付；自動貼文讚在帳號轉為私密期間無法交付。
                        </p>
                    </details>
                    <details class="group py-5">
                        <summary class="min-h-11 cursor-pointer list-none text-base font-bold">現在可以真的付款嗎？</summary>
                        <p class="mt-3 leading-7 text-black/60">
                            不可以。目前是本機開發預覽，沒有連接任何正式金流或下單平台。
                        </p>
                    </details>
                </div>
            </div>
        </section>
    @else
        <section class="border-t border-black/10 bg-white">
            <div class="mx-auto max-w-3xl px-5 py-14 sm:px-8 lg:py-20">
                <div class="surface p-7 sm:p-9">
                    <h2 class="text-2xl font-bold tracking-[-0.03em] sm:text-3xl">服務資料準備中</h2>
                    <p class="mt-4 leading-8 text-black/60">{{ $platform['unavailable_note'] }}</p>
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
