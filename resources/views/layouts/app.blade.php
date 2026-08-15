<!doctype html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="{{ app(\App\Support\IndexingPolicy::class)->allows(request()) ? 'index, follow' : 'noindex, nofollow' }}">
    <meta name="description" content="{{ $description ?? 'IGLIKEFOLLOW Instagram 社群服務本機開發預覽。' }}">
    <title>{{ $title ?? 'IGLIKEFOLLOW' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <header class="border-b border-black/10 bg-paper/95">
        <div class="mx-auto flex min-h-20 max-w-[1220px] items-center justify-between gap-6 px-5 sm:px-8">
            <a href="{{ route('home') }}" aria-label="IGLIKEFOLLOW 首頁">
                <img src="{{ asset('images/iglikefollow-logo.png') }}" alt="IG LIKE FOLLOW"
                     class="h-auto w-44 sm:w-52" width="715" height="143">
            </a>
            <nav aria-label="主要導覽" class="hidden items-center gap-7 text-sm font-semibold md:flex">
                <a href="#services" class="hover:opacity-60">服務項目</a>
                <a href="#process" class="hover:opacity-60">購買流程</a>
                <a href="#faq" class="hover:opacity-60">常見問題</a>
                <a href="#checkout" class="rounded-full bg-ink px-5 py-3 text-white">立即選購</a>
            </nav>
            <a href="#checkout" class="rounded-full bg-ink px-4 py-3 text-sm font-bold text-white md:hidden">選購</a>
        </div>
    </header>

    @yield('content')
</body>
</html>
