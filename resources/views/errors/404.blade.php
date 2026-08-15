@extends('layouts.app', ['title' => '找不到頁面｜IGLIKEFOLLOW'])

@section('content')
    <main class="mx-auto flex min-h-[70vh] max-w-3xl items-center px-5 py-20 text-center">
        <div class="w-full">
            <p class="eyebrow">404</p>
            <h1 class="mt-4 text-4xl font-bold tracking-[-0.035em] sm:text-6xl">這個頁面不存在。</h1>
            <p class="mx-auto mt-6 max-w-xl text-base leading-8 text-black/60">
                網址可能已變更，或內容尚未建立。回到首頁重新選擇需要的 Instagram 服務。
            </p>
            <a href="{{ route('home') }}"
               class="mt-8 inline-flex min-h-12 items-center justify-center rounded-full bg-ink px-7 font-bold text-white">
                返回首頁
            </a>
        </div>
    </main>
@endsection
