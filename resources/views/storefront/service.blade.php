@php
    $variants = $service['variants'];
    $defaultKey = collect($variants)->search(fn ($v) => ! empty($v['featured'])) ?: array_key_first($variants);
    $unit = $service['quantity_unit'] ?? '個';
    // Alpine 需要 min/max/step/單價 才能在前端試算；⛔ 實際金額仍由後端重算。
    $bounds = collect($variants)->map(fn ($v) => $v['quantity'])->all();
@endphp

@extends('layouts.app', [
    'title' => $service['name'] . '｜IGLIKEFOLLOW',
    'description' => $service['name'] . '：' . $service['summary'] . ' 自由輸入數量並免會員快速結帳。本頁為本機開發預覽，不會建立真實訂單。',
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

    <section class="mx-auto max-w-[1220px] px-5 py-10 sm:px-8 lg:py-12">
        <p class="eyebrow">{{ $platform['name'] }}</p>
        <h1 class="mt-5 text-[clamp(2.2rem,4.6vw,3.8rem)] font-bold leading-[1.06] tracking-[-0.045em]">
            {{ $service['name'] }}
        </h1>
        <p class="mt-5 max-w-2xl text-base leading-8 text-black/60 sm:text-lg">{{ $service['summary'] }}</p>
    </section>

    <div x-data="{
            variant: '{{ $defaultKey }}',
            payment: 'line-pay',
            bounds: {{ Illuminate\Support\Js::from($bounds) }},
            quantity: {{ $variants[$defaultKey]['quantity']['default'] }},
            get b() { return this.bounds[this.variant] },
            selectVariant(key) { this.variant = key; this.quantity = this.bounds[key].default },
            get estimate() {
                const q = Number(this.quantity) || 0
                return Math.round(q * this.b.unit_price).toLocaleString()
            },
            get valid() {
                const q = Number(this.quantity)
                return Number.isInteger(q) && q >= this.b.min && q <= this.b.max && q % this.b.step === 0
            }
         }">
        <section class="mx-auto grid max-w-[1220px] items-start gap-8 px-5 pb-14 sm:px-8 lg:grid-cols-[1fr_440px] lg:gap-12">

            {{-- 款式導航：真實 radio，關閉 JS 仍可選。不使用 sticky，避免捲動時跟著移動。 --}}
            <aside aria-labelledby="variant-title" class="lg:h-fit">
                <h2 id="variant-title" class="text-lg font-bold tracking-[-0.02em]">選擇款式</h2>
                <p class="mt-2 text-sm leading-6 text-black/55">不同款式的來源與單價不同。</p>
                <div class="mt-5 grid gap-2 sm:grid-cols-2">
                    @foreach ($variants as $key => $variant)
                        <label class="variant-card">
                            <input type="radio" name="variant" value="{{ $key }}" form="checkout-form"
                                   class="sr-only" x-model="variant" @change="selectVariant('{{ $key }}')"
                                   @checked($key === $defaultKey)>
                            <span class="flex items-baseline justify-between gap-3">
                                <span class="font-bold">{{ $variant['label'] }}</span>
                                <span class="shrink-0 text-sm tabular-nums opacity-70">
                                    NT${{ rtrim(rtrim(number_format($variant['quantity']['unit_price'], 2), '0'), '.') }}
                                    <span class="opacity-70">/{{ $unit }}</span>
                                </span>
                            </span>
                            <span class="mt-1.5 block text-sm leading-6 opacity-70">{{ $variant['description'] }}</span>
                            <span class="mt-2 block text-xs tabular-nums opacity-60">
                                {{ number_format($variant['quantity']['min']) }}–{{ number_format($variant['quantity']['max']) }} {{ $unit }}
                            </span>
                        </label>
                    @endforeach
                </div>
            </aside>

            <div>
                <section id="checkout" class="surface p-5 sm:p-7" aria-labelledby="checkout-title">
                    <div class="flex items-start justify-between gap-5">
                        <div>
                            <p class="eyebrow">Quick checkout</p>
                            <h2 id="checkout-title" class="mt-2 text-2xl font-bold tracking-[-0.03em]">快速選購</h2>
                        </div>
                        <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-900">本機 MOCK</span>
                    </div>

                    <form id="checkout-form" action="{{ route('checkout.mock') }}" method="post" class="mt-7 space-y-7">
                        @csrf

                        <div>
                            <label for="quantity" class="mb-3 block text-sm font-bold">
                                1. 輸入數量（<span x-text="unitLabel ?? '{{ $unit }}'">{{ $unit }}</span>）
                            </label>
                            <input id="quantity" name="quantity" type="number" inputmode="numeric" required
                                   x-model="quantity"
                                   :min="b.min" :max="b.max" :step="b.step"
                                   value="{{ old('quantity', $variants[$defaultKey]['quantity']['default']) }}"
                                   aria-describedby="quantity-hint"
                                   class="min-h-14 w-full rounded-2xl border border-black/15 bg-white px-4 py-3 text-base tabular-nums">
                            <p id="quantity-hint" class="mt-2 text-xs leading-5 text-black/55">
                                可輸入
                                <span x-text="Number(b.min).toLocaleString()">{{ number_format($variants[$defaultKey]['quantity']['min']) }}</span>
                                至
                                <span x-text="Number(b.max).toLocaleString()">{{ number_format($variants[$defaultKey]['quantity']['max']) }}</span>
                                {{ $unit }}，需為
                                <span x-text="b.step">{{ $variants[$defaultKey]['quantity']['step'] }}</span>
                                的倍數。
                            </p>
                            <p class="mt-2 text-sm" x-show="!valid" x-cloak>
                                <span class="text-red-700">數量不符合此款式的可購買範圍。</span>
                            </p>
                            @error('quantity') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
                            @error('variant') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>

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

                        <div class="rounded-2xl bg-paper p-5">
                            <div class="flex items-baseline justify-between gap-4">
                                <span class="text-sm font-bold">試算金額</span>
                                <span class="text-2xl font-bold tabular-nums tracking-[-0.03em]">
                                    NT$<span x-text="estimate">—</span>
                                </span>
                            </div>
                            <p class="mt-2 text-xs leading-5 text-black/50">
                                <span x-text="Number(quantity || 0).toLocaleString()"></span> {{ $unit }}
                                × NT$<span x-text="b.unit_price"></span>
                            </p>
                        </div>

                        <button type="submit" class="primary-button">測試快速結帳</button>
                        <p class="text-center text-xs leading-5 text-black/50">
                            試算僅供參考，實際金額由後端重新計算。此按鈕不會付款、不會建立真實訂單。
                        </p>
                    </form>
                </section>
            </div>
        </section>
    </div>

    <section class="border-t border-black/10 bg-white">
        <div class="mx-auto max-w-[1220px] px-5 py-14 sm:px-8">
            <h2 class="text-2xl font-bold tracking-[-0.03em]">服務說明</h2>
            <dl class="mt-6 grid gap-px overflow-hidden rounded-[1.75rem] bg-black/10 sm:grid-cols-3">
                <div class="bg-white p-6">
                    <dt class="text-sm font-bold">交付方式</dt>
                    <dd class="mt-2 leading-7 text-black/60">{{ $service['delivery'] }}</dd>
                </div>
                <div class="bg-white p-6">
                    <dt class="text-sm font-bold">需要填寫</dt>
                    <dd class="mt-2 leading-7 text-black/60">{{ $service['input_label'] }}</dd>
                </div>
                <div class="bg-white p-6">
                    <dt class="text-sm font-bold">付款方式</dt>
                    <dd class="mt-2 leading-7 text-black/60">
                        LINE Pay 或綠界付款。付款成功由後端驗證後才建立履約流程。
                    </dd>
                </div>
            </dl>
        </div>
    </section>

    <section class="border-t border-black/10 bg-paper">
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
