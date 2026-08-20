<!doctype html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- 結帳頁永遠 noindex；⛔ 不得因全站開放索引而讓訂單表單進入搜尋結果。 --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? '結帳' }}｜{{ $siteName ?? 'IGLIKEFOLLOW' }}</title>
    {{-- Favicon:由品牌方形標誌產生的本機資產,無外部來源。 --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    {{-- Enclosed checkout：保留品牌，⛔ 移除平台導覽避免客人在填單途中離開。 --}}
    <header class="border-b border-black/10 bg-paper/95">
        <div class="mx-auto flex min-h-20 max-w-[1120px] items-center justify-between gap-6 px-5 sm:px-8">
            <a href="{{ route('home') }}" aria-label="{{ $siteName ?? 'IGLIKEFOLLOW' }} 首頁">
                <img src="{{ asset('images/iglikefollow-logo.png') }}" alt="{{ $siteName ?? 'IGLIKEFOLLOW' }}"
                     class="h-auto w-36 sm:w-44" width="715" height="143">
            </a>

        </div>
    </header>

    @yield('content')

    <footer class="border-t border-black/10 bg-white">
        <div class="mx-auto max-w-[1120px] px-5 py-8 sm:px-8">
            <p class="text-xs leading-6 text-black/50">
{{-- R4:顧客語氣;真正的安全由 payment/dispatch flags 擋,不靠文案。 --}}
                付款成功後自動處理，並開立電子發票。
            </p>
        </div>
    </footer>
</body>
</html>
