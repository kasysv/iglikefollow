{{-- 用 checkout layout：它的 robots meta 是無條件 noindex，
     ⛔ layouts.app 會依 IndexingPolicy 判斷，開放索引後就會變成 index, follow。 --}}
@extends('layouts.checkout', ['title' => 'Mock 結帳完成'])

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
            <div class="flex justify-between gap-6 py-4"><dt class="text-black/50">平台</dt><dd class="font-bold">{{ $platformName }}</dd></div>
            <div class="flex justify-between gap-6 py-4"><dt class="text-black/50">服務</dt><dd class="font-bold">{{ $serviceName }}</dd></div>
            <div class="flex justify-between gap-6 py-4"><dt class="text-black/50">服務項目</dt><dd class="font-bold">{{ $variantLabel }}</dd></div>
            <div class="flex justify-between gap-6 py-4"><dt class="text-black/50">數量</dt><dd class="font-bold">{{ number_format($quantity) }} {{ $quantityUnit }}</dd></div>
            <div class="flex justify-between gap-6 py-4"><dt class="text-black/50">Mock 金額（後端重算）</dt><dd class="font-bold">NT${{ number_format($mockAmount) }}</dd></div>
            <div class="flex justify-between gap-6 py-4"><dt class="text-black/50">付款方式</dt><dd class="font-bold">{{ $paymentLabel }}</dd></div>
            <div class="flex flex-col gap-2 py-4 sm:flex-row sm:justify-between"><dt class="text-black/50">目標</dt><dd class="break-all font-bold">{{ $target }}</dd></div>
            {{-- ⛔ 只顯示遮罩後的聯絡資料與發票類型；完整 Email／手機／載具／統編不得回顯。 --}}
            <div class="flex justify-between gap-6 py-4"><dt class="text-black/50">發票類型</dt><dd class="font-bold">{{ $invoiceSummary }}</dd></div>
            <div class="flex justify-between gap-6 py-4"><dt class="text-black/50">通知 Email</dt><dd class="break-all font-bold">{{ $maskedEmail }}</dd></div>
            @if ($maskedPhone)
                <div class="flex justify-between gap-6 py-4"><dt class="text-black/50">聯絡手機</dt><dd class="font-bold tabular-nums">{{ $maskedPhone }}</dd></div>
            @endif
        </dl>

        <p class="mt-6 rounded-2xl bg-paper p-5 text-sm leading-7 text-black/60">
            這是本機測試結果：<strong class="text-ink">不會扣款、不會建立真實訂單，也不會開立任何發票。</strong>
            上方聯絡資料僅在這次回應中遮罩顯示，未寫入資料庫、log 或 session。
        </p>

        <a href="{{ route('home') }}#platforms"
           class="mt-8 inline-flex min-h-12 items-center justify-center rounded-full bg-ink px-7 font-bold text-white">
            返回選擇服務
        </a>
    </div>
</main>
@endsection
