@php
    $variants = $service->variants;
    $default = $variants->firstWhere('is_featured', true) ?? $variants->first();
    $unit = $default?->quantity_unit ?? '個';
    // Alpine 需要 min/max/step/單價 才能在前端試算；⛔ 實際金額仍由後端重算。
    $bounds = $variants->mapWithKeys(fn ($v) => [$v->id => [
        'min' => (int) $v->min_quantity,
        'max' => (int) $v->max_quantity,
        'step' => (int) $v->step_quantity,
        'default' => (int) $v->default_quantity,
        'unit_price' => (float) $v->unit_price,
    ]])->all();
@endphp

@extends('layouts.app', [
    'title' => $service->seo_title ?: $service->name . '｜IGLIKEFOLLOW',
    'description' => $service->meta_description ?: $service->summary,
])

@section('content')
<main>
    @if (! empty($isPreview))
        <p class="bg-amber-100 px-5 py-3 text-center text-sm font-bold text-amber-900">
            草稿預覽模式：此頁包含尚未發布的內容，不會對外公開。
        </p>
    @endif

    <nav aria-label="麵包屑" class="mx-auto max-w-[1320px] px-5 pt-8 sm:px-8">
        <ol class="flex flex-wrap items-center gap-2 text-sm text-black/55">
            <li><a href="{{ route('home') }}" class="hover:text-ink hover:underline">首頁</a></li>
            <li aria-hidden="true">/</li>
            <li>
                <a href="{{ route('platform', $platform->slug) }}" class="hover:text-ink hover:underline">
                    {{ $platform->name }}
                </a>
            </li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="font-semibold text-ink">{{ $service->name }}</li>
        </ol>
    </nav>

    <section class="mx-auto max-w-[1320px] px-5 pb-2 pt-6 sm:px-8">
        <p class="eyebrow">{{ $platform->name }}</p>
        <h1 class="mt-3 text-[clamp(1.85rem,2.9vw,2.7rem)] font-bold leading-[1.12] tracking-[-0.04em]">
            {{ $service->h1 ?: $service->name }}
        </h1>
        <p class="mt-3 max-w-2xl leading-7 text-black/60">{{ $service->summary }}</p>
    </section>

    @if ($variants->isEmpty())
        <section class="mx-auto max-w-3xl px-5 py-14 sm:px-8">
            <div class="surface p-7 sm:p-9">
                <h2 class="text-2xl font-bold tracking-[-0.03em]">方案準備中</h2>
                <p class="mt-4 leading-8 text-black/60">
                    這個服務目前沒有已發布的方案。方案資料確認後才會顯示價格與數量範圍。
                </p>
            </div>
        </section>
    @else
    <div x-data="{
            variant: '{{ $default->id }}',
            payment: 'line-pay',
            bounds: {{ Illuminate\Support\Js::from($bounds) }},
            quantity: {{ $default->default_quantity }},
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
        <section class="mx-auto grid max-w-[1320px] items-start gap-8 px-5 py-8 sm:px-8 lg:grid-cols-[1fr_420px] lg:gap-12">

            <div>
                {{-- 購買前必要理解：適合對象與必要條件，放在選購模組上方 --}}
                <dl class="grid gap-px overflow-hidden rounded-2xl bg-black/10 sm:grid-cols-3">
                    <div class="bg-white p-4">
                        <dt class="text-xs font-bold text-black/50">適合目標</dt>
                        <dd class="mt-1.5 font-semibold">{{ $service->goal ?: '社群成長' }}</dd>
                    </div>
                    <div class="bg-white p-4">
                        <dt class="text-xs font-bold text-black/50">需要填寫</dt>
                        <dd class="mt-1.5 font-semibold">{{ $service->input_label }}</dd>
                    </div>
                    <div class="bg-white p-4">
                        <dt class="text-xs font-bold text-black/50">必要條件</dt>
                        <dd class="mt-1.5 font-semibold">帳號或貼文須為公開</dd>
                    </div>
                </dl>
                <p class="mt-3 text-sm leading-6 text-black/55">{{ $service->delivery_summary }}</p>

                {{-- 款式導航：真實 radio，關閉 JS 仍可選 --}}
                <aside aria-labelledby="variant-title" class="mt-8">
                    <h2 id="variant-title" class="text-lg font-bold tracking-[-0.02em]">選擇款式</h2>
                    <p class="mt-2 text-sm leading-6 text-black/55">不同款式的來源與單價不同。</p>
                    <div class="mt-5 grid gap-2 sm:grid-cols-2">
                        @foreach ($variants as $variant)
                            <label class="variant-card">
                                <input type="radio" name="variant" value="{{ $variant->id }}" form="checkout-form"
                                       class="sr-only" x-model="variant" @change="selectVariant('{{ $variant->id }}')"
                                       @checked($variant->is($default))>
                                <span class="flex items-baseline justify-between gap-3">
                                    <span class="font-bold">{{ $variant->label }}</span>
                                    <span class="shrink-0 text-sm tabular-nums text-black/60">
                                        NT${{ number_format((float) $variant->unit_price, 2) }}／{{ $variant->quantity_unit }}
                                    </span>
                                </span>
                                @if (filled($variant->description))
                                    <span class="mt-1.5 block text-sm leading-6 text-black/60">{{ $variant->description }}</span>
                                @endif
                                <span class="mt-2 block text-xs tabular-nums text-black/50">
                                    {{ number_format($variant->min_quantity) }}–{{ number_format($variant->max_quantity) }} {{ $variant->quantity_unit }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </aside>
            </div>

            <div class="lg:sticky lg:top-6">
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
                                1. 輸入數量（{{ $unit }}）
                            </label>
                            <input id="quantity" name="quantity" type="number" inputmode="numeric" required
                                   x-model="quantity"
                                   :min="b.min" :max="b.max" :step="b.step"
                                   value="{{ old('quantity', $default->default_quantity) }}"
                                   aria-describedby="quantity-hint"
                                   class="min-h-14 w-full rounded-2xl border border-black/15 bg-white px-4 py-3 text-base tabular-nums">
                            <p id="quantity-hint" class="mt-2 text-xs leading-5 text-black/55">
                                可輸入 <span x-text="Number(b.min).toLocaleString()">{{ number_format($default->min_quantity) }}</span>
                                至 <span x-text="Number(b.max).toLocaleString()">{{ number_format($default->max_quantity) }}</span>
                                {{ $unit }}，需為 <span x-text="b.step">{{ $default->step_quantity }}</span> 的倍數。
                            </p>
                            <p class="mt-2 text-sm" x-show="!valid" x-cloak>
                                <span class="text-red-700">數量不符合此款式的可購買範圍。</span>
                            </p>
                            @error('quantity') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
                            @error('variant') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="target" class="mb-3 block text-sm font-bold">2. {{ $service->input_label }}</label>
                            <input id="target" name="target" value="{{ old('target') }}" required maxlength="255"
                                   placeholder="{{ $service->input_hint }}"
                                   aria-describedby="target-hint"
                                   class="min-h-14 w-full rounded-2xl border border-black/15 bg-white px-4 py-3 text-base placeholder:text-black/35">
                            <p id="target-hint" class="mt-2 text-xs leading-5 text-black/50">{{ $service->delivery_summary }}</p>
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

                        <ul class="space-y-1.5 text-xs leading-5 text-black/55">
                            <li>· {{ $service->input_label }}須為公開狀態才能交付。</li>
                            <li>· 試算金額僅供參考，實際金額由後端重新計算。</li>
                            <li>· 目前為本機 mock：不會扣款，也不會建立真實訂單。</li>
                        </ul>

                        <button type="submit" class="primary-button">測試快速結帳</button>
                    </form>
                </section>
            </div>
        </section>
    </div>
    @endif

    {{-- 內容區塊：固定安全模板輸出 H2／段落；⛔ 後台內容不得注入 HTML 或 script --}}
    @if ($service->contentSections->isNotEmpty())
        <section class="border-t border-black/10 bg-white">
            <div class="mx-auto max-w-3xl px-5 py-14 sm:px-8">
                @foreach ($service->contentSections as $section)
                    <article class="@if (! $loop->first) mt-10 @endif">
                        <h2 class="text-2xl font-bold tracking-[-0.035em]">{{ $section->heading }}</h2>
                        <p class="mt-3 whitespace-pre-line leading-8 text-black/60">{{ $section->body }}</p>
                        @if ($section->image_path)
                            <img src="{{ \Illuminate\Support\Facades\Storage::url($section->image_path) }}"
                                 alt="{{ $section->image_alt }}"
                                 class="mt-5 h-auto w-full rounded-2xl" loading="lazy">
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <section class="border-t border-black/10 bg-white">
        <div class="mx-auto max-w-[1320px] px-5 py-14 sm:px-8">
            <h2 class="text-2xl font-bold tracking-[-0.03em]">服務說明</h2>
            <dl class="mt-6 grid gap-px overflow-hidden rounded-[1.75rem] bg-black/10 sm:grid-cols-3">
                <div class="bg-white p-6">
                    <dt class="text-sm font-bold">交付方式</dt>
                    <dd class="mt-2 leading-7 text-black/60">{{ $service->delivery_summary }}</dd>
                </div>
                <div class="bg-white p-6">
                    <dt class="text-sm font-bold">需要填寫</dt>
                    <dd class="mt-2 leading-7 text-black/60">{{ $service->input_label }}</dd>
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

    @if ($service->faqs->isNotEmpty())
        <section class="border-t border-black/10 bg-paper">
            <div class="mx-auto max-w-3xl px-5 py-12 sm:px-8">
                <h2 class="text-2xl font-bold tracking-[-0.035em]">常見問題</h2>
                <div class="mt-6 divide-y divide-black/10 border-y border-black/10">
                    @foreach ($service->faqs as $faq)
                        <details class="group py-5">
                            <summary class="min-h-11 cursor-pointer list-none text-base font-bold">{{ $faq->question }}</summary>
                            <p class="mt-3 leading-7 text-black/60">{{ $faq->answer }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="border-t border-black/10 bg-paper">
        <div class="mx-auto max-w-[1320px] px-5 py-14 sm:px-8">
            <h2 class="text-2xl font-bold tracking-[-0.03em]">{{ $platform->name }} 其他服務</h2>
            <ul class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($platform->services()->published()->orderBy('sort_order')->get() as $other)
                    @if ($other->id !== $service->id)
                        <li>
                            <a href="{{ route('service', [$platform->slug, $other->slug]) }}"
                               class="flex min-h-20 flex-col justify-center rounded-2xl border border-black/10 bg-white px-5 py-4 transition-colors duration-200 hover:border-ink">
                                <span class="font-bold">{{ $other->name }}</span>
                                <span class="mt-1 text-sm text-black/55">{{ $other->summary }}</span>
                            </a>
                        </li>
                    @endif
                @endforeach
            </ul>
            <a href="{{ route('platform', $platform->slug) }}"
               class="mt-8 inline-flex min-h-12 items-center rounded-full border border-black/15 bg-white px-5 text-sm font-bold transition-colors duration-200 hover:border-ink">
                回到 {{ $platform->name }} 服務總覽
            </a>
        </div>
    </section>
</main>
@endsection
