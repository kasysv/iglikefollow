@extends('layouts.app', ['title' => 'Mock 結帳完成｜IGLIKEFOLLOW'])

@section('content')
<main class="mx-auto max-w-3xl px-5 py-16 sm:px-8 lg:py-24">
    <div class="surface p-7 sm:p-10">
        <span class="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-900">本機 MOCK</span>
        <p class="eyebrow mt-8">Validation passed</p>
        <h1 class="mt-4 text-4xl font-bold tracking-[-0.04em] sm:text-6xl">測試資料已通過後端驗證。</h1>
        <p class="mt-5 leading-8 text-black/60">
            沒有扣款、沒有建立資料庫訂單，也沒有呼叫綠界、LINE Pay、DASH 或履約平台。
        </p>

        <dl class="mt-8 divide-y divide-black/10 border-y border-black/10">
            <div class="flex justify-between gap-6 py-4"><dt class="text-black/50">方案</dt><dd class="font-bold">{{ $plan['label'] }}</dd></div>
            <div class="flex justify-between gap-6 py-4"><dt class="text-black/50">Mock 金額</dt><dd class="font-bold">NT${{ number_format($plan['price']) }}</dd></div>
            <div class="flex justify-between gap-6 py-4"><dt class="text-black/50">付款方式</dt><dd class="font-bold">{{ $paymentLabel }}</dd></div>
            <div class="flex flex-col gap-2 py-4 sm:flex-row sm:justify-between"><dt class="text-black/50">目標</dt><dd class="break-all font-bold">{{ $target }}</dd></div>
        </dl>

        <a href="{{ route('home') }}#checkout"
           class="mt-8 inline-flex min-h-12 items-center justify-center rounded-full bg-ink px-7 font-bold text-white">
            返回修改
        </a>
    </div>
</main>
@endsection
