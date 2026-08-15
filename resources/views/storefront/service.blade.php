@php
    $featuredKey = collect($service['plans'])->search(fn ($plan) => ! empty($plan['featured']))
        ?: array_key_first($service['plans']);
@endphp

@extends('layouts.app', [
    'title' => $service['name'] . '｜IGLIKEFOLLOW',
    'description' => $service['name'] . '：' . $service['summary'] . ' 選擇數量方案並免會員快速結帳。本頁為本機開發預覽，不會建立真實訂單。',
])

@section('content')
<main>
    <nav aria-label="麵包屑" class="mx-auto max-w-[1220px] px-5 pt-8 sm:px-8">
        <ol class="flex flex-wrap items-center gap-2 text-sm text-black/55">
            <li><a href="{{ route('home') }}" class="hover:text-ink hover:underline">首頁</a></li>
            <li aria-hidden="true">/</li>
            <li>
                <a href="{{ route('platform', $platform['slug']) }}" class="hover:text-ink hover:underline">
                    {{ $platform['name'] }}
                </a>
            </li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="font-semibold text-ink">{{ $service['name'] }}</li>
        </ol>
    </nav>

    <section class="mx-auto grid max-w-[1220px] gap-10 px-5 py-10 sm:px-8 lg:grid-cols-[1fr_500px] lg:gap-14 lg:py-14">
        <div>
            <p class="eyebrow">{{ $platform['name'] }}</p>
            <h1 class="mt-5 text-[clamp(2.2rem,4.6vw,3.8rem)] font-bold leading-[1.06] tracking-[-0.045em]">
                {{ $service['name'] }}
            </h1>
            <p class="mt-5 max-w-2xl text-base leading-8 text-black/60 sm:text-lg">{{ $service['summary'] }}</p>

            <dl class="mt-8 max-w-2xl divide-y divide-black/10 border-y border-black/10">
                <div class="py-4">
                    <dt class="text-sm font-bold">交付方式</dt>
                    <dd class="mt-2 leading-7 text-black/60">{{ $service['delivery'] }}</dd>
                </div>
                <div class="py-4">
                    <dt class="text-sm font-bold">需要填寫</dt>
                    <dd class="mt-2 leading-7 text-black/60">{{ $service['input_label'] }}</dd>
                </div>
                <div class="py-4">
                    <dt class="text-sm font-bold">付款方式</dt>
                    <dd class="mt-2 leading-7 text-black/60">LINE Pay 或綠界付款。付款成功由後端驗證後才建立履約流程。</dd>
                </div>
            </dl>

            <div class="mt-8 rounded-2xl border border-amber-200 bg-amber-50 p-5">
                <p class="text-sm font-bold text-amber-900">本機開發預覽</p>
                <p class="mt-2 text-sm leading-6 text-amber-900/80">
                    這一頁的方案與價格是開發用的 mock 資料，不是正式售價，也不會建立真實訂單。
                </p>
            </div>
        </div>

        <section id="checkout" class="surface h-fit p-5 sm:p-7" aria-labelledby="checkout-title"
                 x-data="{ plan: '{{ $featuredKey }}', payment: 'line-pay' }">
            <div class="flex items-start justify-between gap-5">
                <div>
                    <p class="eyebrow">Quick checkout</p>
                    <h2 id="checkout-title" class="mt-2 text-2xl font-bold tracking-[-0.03em]">快速選購</h2>
                </div>
                <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-900">本機 MOCK</span>
            </div>

            <form action="{{ route('checkout.mock') }}" method="post" class="mt-7 space-y-7">
                @csrf
                <fieldset>
                    <legend class="mb-3 text-sm font-bold">1. 選擇方案</legend>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach ($service['plans'] as $key => $plan)
                            <label class="choice-card">
                                <input type="radio" name="plan" value="{{ $key }}" class="sr-only"
                                       x-model="plan" @checked($key === $featuredKey)>
                                <span class="text-xs font-semibold sm:text-sm">{{ $plan['label'] }}</span>
                                <span class="mt-3 block whitespace-nowrap text-lg font-bold tracking-[-0.04em] sm:text-xl">
                                    NT${{ number_format($plan['price']) }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('plan') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
                </fieldset>

                <div>
                    <label for="target" class="mb-3 block text-sm font-bold">2. {{ $service['input_label'] }}</label>
                    <input id="target" name="target" value="{{ old('target') }}" required maxlength="255"
                           placeholder="{{ $service['input_hint'] }}"
                           aria-describedby="target-hint"
                           class="min-h-14 w-full rounded-2xl border border-black/15 bg-white px-4 py-3 text-base placeholder:text-black/35">
                    <p id="target-hint" class="mt-2 text-xs leading-5 text-black/50">{{ $service['delivery'] }}</p>
                    @error('target') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <fieldset>
                    <legend class="mb-3 text-sm font-bold">3. 選擇付款方式</legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label class="payment-card">
                            <input type="radio" name="payment" value="line-pay" x-model="payment" checked>
                            <span class="font-bold">LINE Pay</span>
                        </label>
                        <label class="payment-card">
                            <input type="radio" name="payment" value="ecpay" x-model="payment">
                            <span class="font-bold">綠界付款</span>
                        </label>
                    </div>
                    @error('payment') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
                </fieldset>

                <button type="submit" class="primary-button">測試快速結帳</button>
                <p class="text-center text-xs leading-5 text-black/50">
                    此按鈕只驗證本機 mock 流程，不會付款、不會建立真實訂單。
                </p>
            </form>
        </section>
    </section>

    <section class="border-t border-black/10 bg-white">
        <div class="mx-auto max-w-[1220px] px-5 py-14 sm:px-8">
            <h2 class="text-2xl font-bold tracking-[-0.03em]">{{ $platform['name'] }} 其他服務</h2>
            <ul class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($platform['services'] as $other)
                    @if ($other['slug'] !== $service['slug'])
                        <li>
                            <a href="{{ route('service', [$platform['slug'], $other['slug']]) }}"
                               class="flex min-h-20 flex-col justify-center rounded-2xl border border-black/10 bg-paper px-5 py-4 transition hover:border-ink">
                                <span class="font-bold">{{ $other['name'] }}</span>
                                <span class="mt-1 text-sm text-black/55">{{ $other['summary'] }}</span>
                            </a>
                        </li>
                    @endif
                @endforeach
            </ul>
            <a href="{{ route('platform', $platform['slug']) }}"
               class="mt-8 inline-flex min-h-12 items-center rounded-full border border-black/15 bg-white px-5 text-sm font-bold transition hover:border-ink">
                回到 {{ $platform['name'] }} 服務總覽
            </a>
        </div>
    </section>
</main>
@endsection
