<!doctype html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- 草稿預覽一律 noindex，⛔ 不依賴全站 IndexingPolicy；正式開放索引後仍不得外洩。 --}}
    <meta name="robots" content="{{ (! empty($isPreview) || ! app(\App\Support\IndexingPolicy::class)->allows(request())) ? 'noindex, nofollow' : 'index, follow' }}">
    <meta name="description" content="{{ $description ?? 'IGLIKEFOLLOW Instagram 社群服務本機開發預覽。' }}">
    <title>{{ $title ?? $siteName }}</title>
    {{-- M2-C:自我 canonical(僅在頁面明確提供時輸出;preview 頁不輸出)。 --}}
    @if (! empty($canonical))
        <link rel="canonical" href="{{ $canonical }}">
    @endif
    {{-- Favicon:由品牌方形標誌產生的本機資產,無外部來源。 --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <header class="border-b border-black/10 bg-paper/95">
        {{--
            R1:mobile header 三元素(品牌／常見問題／選擇服務)必須同時可見。
            ⛔ 原本 w-40 的 wordmark 實際渲染 160px 會溢出自己的連結框並壓到
            「常見問題」;改為 <640px 收起方形 mark、wordmark 降到 w-32,
            並縮小 gap／padding。⛔ 不刪任何連結、不刪 alt、不改成 JS-only。
        --}}
        <div class="mx-auto flex min-h-20 max-w-[1220px] items-center justify-between gap-3 px-4 sm:gap-6 sm:px-8">
            {{-- Logo 是圖片，公司名稱仍須以可存取名稱保留，⛔ 不可只剩一張沒有名字的圖。 --}}
            <a href="{{ route('home') }}" aria-label="{{ $siteName }} 首頁"
               data-probe="brand" class="flex min-w-0 shrink items-center gap-2 sm:gap-3">
                {{-- 品牌方形標誌:裝飾性(名稱由 wordmark 的 alt 提供),固定尺寸避免 CLS。
                     ⛔ 手機空間不足時先讓它退場,保留帶 alt 的 wordmark。 --}}
                <img src="{{ asset('images/iglikefollow-mark.png') }}" alt=""
                     class="hidden h-11 w-11 shrink-0 rounded-xl sm:block sm:h-12 sm:w-12" width="361" height="361">
                <img src="{{ asset('images/iglikefollow-logo.png') }}" alt="{{ $siteName }}"
                     class="h-auto w-32 max-w-full sm:w-52" width="715" height="143">
            </a>
            @php $onFaq = request()->routeIs('faq'); @endphp
            <nav aria-label="主要導覽" class="hidden items-center gap-7 text-sm font-semibold md:flex">
                @foreach ($navPlatforms as $navPlatform)
                    <a href="{{ route('platform', $navPlatform->slug) }}" class="hover:opacity-60">{{ $navPlatform->name }}</a>
                @endforeach
                {{-- R5:可爬的真實連結;⛔ 不用 JS-only click、query 或 fragment 當主形式。 --}}
                <a href="{{ route('faq') }}"
                   @if ($onFaq) aria-current="page" @endif
                   class="hover:opacity-60 {{ $onFaq ? 'underline underline-offset-4' : '' }}">常見問題</a>
                <a href="{{ route('home') }}#platforms" class="rounded-full bg-ink px-5 py-3 text-white">選擇服務</a>
            </nav>
            {{-- Mobile:同一組目的地;min-h-11(44px)符合 tap target,⛔ 文字不換行。 --}}
            <div class="flex shrink-0 items-center gap-1.5 md:hidden">
                <a href="{{ route('faq') }}"
                   @if ($onFaq) aria-current="page" @endif
                   data-probe="nav-faq"
                   class="flex min-h-11 items-center whitespace-nowrap px-1.5 text-sm font-semibold {{ $onFaq ? 'underline underline-offset-4' : '' }}">常見問題</a>
                <a href="{{ route('home') }}#platforms"
                   data-probe="nav-cta"
                   class="flex min-h-11 items-center whitespace-nowrap rounded-full bg-ink px-3.5 text-sm font-bold text-white">選擇服務</a>
            </div>
        </div>
    </header>

    @yield('content')

    <footer class="border-t border-black/10 bg-white">
        <div class="mx-auto max-w-[1220px] px-5 py-12 sm:px-8">
            {{-- 頁尾只列已發布內容；⛔ draft／archived 不得出現在公開導覽。 --}}
            <nav aria-label="頁尾服務導覽" class="grid gap-8 sm:grid-cols-3">
                @foreach ($navPlatforms as $footerPlatform)
                    <div>
                        <p class="text-sm font-bold">{{ $footerPlatform->name }}</p>
                        @php $footerServices = $footerPlatform->status === 'published'
                            ? $footerPlatform->services()->published()->whereNotNull('product_slug')->orderBy('sort_order')->get()
                            : collect(); @endphp
                        @if ($footerServices->isNotEmpty())
                            <ul class="mt-3 space-y-2 text-sm leading-6 text-black/70">
                                @foreach ($footerServices as $footerService)
                                    <li>
                                        {{-- D-103:公開內鏈一律直達 /product/ canonical。 --}}
                                        <a class="hover:text-ink hover:underline"
                                           href="{{ $footerService->primaryUrl() }}">
                                            {{ $footerService->card_title ?: $footerService->name }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-3 text-sm text-black/60">服務資料準備中。</p>
                        @endif
                    </div>
                @endforeach
            </nav>
            <p class="mt-10 border-t border-black/10 pt-6 text-xs leading-6 text-black/60">
                <span class="font-semibold text-black/70">{{ $siteName }}</span>

            </p>
        </div>
    </footer>
</body>
</html>
