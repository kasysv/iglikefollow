{{-- 用 checkout layout：它的 robots meta 是無條件 noindex，
     ⛔ layouts.app 會依 IndexingPolicy 判斷，開放索引後就會變成 index, follow。 --}}
@extends('layouts.checkout', ['title' => 'Mock 訂單結果'])

@php
    $item = $order->items->first();
    $attempt = $order->paymentAttempts->last();
@endphp

@section('content')
<main class="mx-auto max-w-3xl px-5 py-12 sm:px-8 lg:py-16">
    <div class="surface p-7 sm:p-10">
        <span class="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-900">本機 MOCK</span>

        <p class="eyebrow mt-8">Order created</p>
        <h1 class="mt-4 text-3xl font-bold tracking-[-0.04em] sm:text-4xl">訂單已建立。</h1>
        <p class="mt-5 leading-8 text-black/60">
            結帳資料已通過後端驗證並建立本站訂單。付款結果由下方付款紀錄呈現。
        </p>

        <div class="mt-8 rounded-2xl bg-paper p-5">
            <p class="text-xs font-bold text-black/50">訂單編號</p>
            {{-- 對外只露不可猜測的 reference，⛔ 不露資料庫 id。 --}}
            <p class="mt-1 text-xl font-bold tracking-[-0.02em] tabular-nums">{{ $order->reference }}</p>
        </div>

        <dl class="mt-8 divide-y divide-black/10 border-y border-black/10">
            <div class="flex justify-between gap-6 py-4">
                <dt class="text-black/50">訂單狀態</dt>
                <dd class="font-bold">{{ $order->order_status->label() }}</dd>
            </div>
            <div class="flex justify-between gap-6 py-4">
                <dt class="text-black/50">付款狀態</dt>
                <dd class="font-bold">{{ $order->payment_status->label() }}</dd>
            </div>
            @if ($item)
                <div class="flex justify-between gap-6 py-4">
                    <dt class="text-black/50">平台</dt><dd class="font-bold">{{ $item->platform_name }}</dd>
                </div>
                <div class="flex justify-between gap-6 py-4">
                    <dt class="text-black/50">服務分類</dt><dd class="font-bold">{{ $item->service_name }}</dd>
                </div>
                <div class="flex justify-between gap-6 py-4">
                    <dt class="text-black/50">服務項目</dt><dd class="font-bold">{{ $item->variant_label }}</dd>
                </div>
                <div class="flex justify-between gap-6 py-4">
                    <dt class="text-black/50">數量</dt>
                    <dd class="font-bold tabular-nums">{{ number_format($item->quantity) }} {{ $item->quantity_unit }}</dd>
                </div>
                <div class="flex flex-col gap-2 py-4 sm:flex-row sm:justify-between">
                    <dt class="text-black/50">交付對象</dt>
                    <dd class="break-all font-bold">{{ $item->target_value }}</dd>
                </div>
            @endif
            <div class="flex justify-between gap-6 py-4">
                <dt class="text-black/50">應付金額（後端重算）</dt>
                <dd class="font-bold tabular-nums">NT${{ number_format($order->total_amount) }}</dd>
            </div>
            {{-- ⛔ 只顯示遮罩後的聯絡資料與發票類型。 --}}
            <div class="flex justify-between gap-6 py-4">
                <dt class="text-black/50">發票類型</dt><dd class="font-bold">{{ $order->invoiceSummary() }}</dd>
            </div>
            <div class="flex justify-between gap-6 py-4">
                <dt class="text-black/50">通知 Email</dt>
                <dd class="break-all font-bold">{{ $order->maskedEmail() }}</dd>
            </div>
            @if ($order->maskedPhone())
                <div class="flex justify-between gap-6 py-4">
                    <dt class="text-black/50">聯絡手機</dt>
                    <dd class="font-bold tabular-nums">{{ $order->maskedPhone() }}</dd>
                </div>
            @endif
        </dl>

        @if ($attempt)
            <h2 class="mt-10 text-lg font-bold tracking-[-0.02em]">付款紀錄</h2>
            <ul class="mt-4 space-y-2">
                @foreach ($order->paymentAttempts as $row)
                    <li class="flex flex-wrap items-baseline justify-between gap-3 rounded-2xl border border-black/10 bg-white px-4 py-3 text-sm">
                        <span class="font-semibold">{{ $row->status->label() }}</span>
                        <span class="tabular-nums text-black/55">
                            NT${{ number_format($row->amount) }}
                            @if ($row->failure_code) ・{{ $row->failure_message }} @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif

        <p class="mt-8 rounded-2xl bg-paper p-5 text-sm leading-7 text-black/60">
            這是本機測試結果：<strong class="text-ink">沒有扣款、沒有呼叫任何金流或履約服務，也不會開立任何發票。</strong>
            付款狀態由 Fake 結果模擬，⛔ 不代表真實付款。聯絡資料僅以遮罩顯示。
        </p>

        <a href="{{ route('home') }}#platforms"
           class="mt-8 inline-flex min-h-12 items-center justify-center rounded-full bg-ink px-7 font-bold text-white">
            返回選擇服務
        </a>
    </div>
</main>
@endsection
