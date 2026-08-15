<!doctype html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- 結帳頁永遠 noindex；⛔ 不得因全站開放索引而讓訂單表單進入搜尋結果。 --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? '結帳' }}｜{{ $siteName ?? 'IGLIKEFOLLOW' }}</title>
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
            <p class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-900">本機 MOCK</p>
        </div>
    </header>

    @yield('content')

    <footer class="border-t border-black/10 bg-white">
        <div class="mx-auto max-w-[1120px] px-5 py-8 sm:px-8">
            <p class="text-xs leading-6 text-black/50">
                本頁為本機開發預覽，不會扣款、不會建立真實訂單，也不會開立發票。
            </p>
        </div>
    </footer>
</body>
</html>
