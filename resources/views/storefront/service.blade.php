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
        // 服務項目簡介同時給 Alpine 切換用；⛔ 仍以下方 server-rendered 版本為主。
        'label' => (string) $v->label,
        'description' => (string) $v->description,
        'unit' => (string) $v->quantity_unit,
    ]])->all();
    $hasAnyDescription = $variants->contains(fn ($v) => filled($v->description));

    // 從 /checkout 返回修改時沿用原本的選擇，⛔ 否則客人得重新挑一次。
    $resumed = ($resumedVariantId ?? null) !== null
        ? $variants->firstWhere('id', $resumedVariantId)
        : null;
    $default = $resumed ?? $default;
    $startQuantity = $resumedQuantity ?? $default?->default_quantity;
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

    @if (session('checkout_notice'))
        {{-- 選購資料過期或商品變更時的安全返回提示，⛔ 不得直接 500。 --}}
        <p class="mx-auto max-w-[1320px] px-5 pt-6 sm:px-8" role="status">
            <span class="block rounded-2xl bg-amber-100 px-4 py-3 text-sm font-semibold text-amber-900">
                {{ session('checkout_notice') }}
            </span>
        </p>
    @endif

    <nav aria-label="麵包屑" class="mx-auto max-w-[1320px] px-5 pt-8 sm:px-8">
        <ol class="flex flex-wrap items-center gap-2 text-sm text-black/55">
            <li><a href="{{ route('home') }}" class="hover:text-ink hover:underline">首頁</a></li>
            <li aria-hidden="true">/</li>
            <li>
                {{-- R3:麵包屑 Hub anchor=「平台名+服務」 --}}
                <a href="{{ route('platform', $platform->slug) }}" class="hover:text-ink hover:underline">
                    {{ $platform->name }}服務
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
        <p class="mt-3 max-w-2xl text-base leading-7 text-black/70">{{ $service->summary }}</p>

        @if ($service->hero_image_path)
            <img src="{{ \Illuminate\Support\Facades\Storage::url($service->hero_image_path) }}"
                 alt="{{ $service->hero_image_alt }}"
                 class="mt-6 h-auto w-full max-w-3xl rounded-[1.75rem]" loading="lazy">
        @endif
    </section>

    @if ($variants->isEmpty())
        <section class="mx-auto max-w-3xl px-5 py-14 sm:px-8">
            <div class="surface p-7 sm:p-9">
                <h2 class="text-2xl font-bold tracking-[-0.03em]">方案準備中</h2>
                <p class="mt-4 text-base leading-8 text-black/70">
                    這個服務目前沒有已發布的方案。方案資料確認後才會顯示價格與數量範圍。
                </p>
            </div>
        </section>
    @else
    {{-- 服務頁只管選品：服務項目、數量與即時試算。
         付款方式與電子發票狀態已移到 /checkout，⛔ 這裡不再持有。 --}}
    <div x-data="{
            variant: '{{ $default->id }}',
            bounds: {{ Illuminate\Support\Js::from($bounds) }},
            quantity: {{ $startQuantity }},
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
        <section class="mx-auto grid max-w-[1320px] items-start gap-8 px-5 py-8 sm:px-8 lg:grid-cols-[1fr_450px] lg:gap-12">

            <div>
                {{-- 購買前必要理解：適合對象與必要條件，放在選購模組上方 --}}
                <dl class="grid gap-px overflow-hidden rounded-2xl bg-black/10 sm:grid-cols-3">
                    <div class="bg-white p-4">
                        <dt class="text-sm font-bold text-black/60">適合目標</dt>
                        <dd class="mt-1.5 font-semibold">{{ $service->goal ?: '社群成長' }}</dd>
                    </div>
                    <div class="bg-white p-4">
                        <dt class="text-sm font-bold text-black/60">需要填寫</dt>
                        <dd class="mt-1.5 font-semibold">{{ $service->input_label }}</dd>
                    </div>
                    <div class="bg-white p-4">
                        <dt class="text-sm font-bold text-black/60">必要條件</dt>
                        <dd class="mt-1.5 font-semibold">帳號或貼文須為公開</dd>
                    </div>
                </dl>
                <p class="mt-3 text-base leading-7 text-black/70">{{ $service->delivery_summary }}</p>

                {{-- 服務項目導航：真實 radio，關閉 JS 仍可選 --}}
                <aside aria-labelledby="variant-title" class="mt-8">
                    {{-- M2-C:選購區標題由後台 cta_label 驅動;無值 fallback「選擇數量方案」。 --}}
                    <h2 id="variant-title" class="text-lg font-bold tracking-[-0.02em]">{{ $service->cta_label ?: '選擇數量方案' }}</h2>
                    <p class="mt-2 text-sm leading-6 text-black/65">不同服務項目的來源與單價不同。</p>
                    <div class="mt-5 grid gap-2 sm:grid-cols-2">
                        @foreach ($variants as $variant)
                            <label class="variant-card">
                                <input type="radio" name="variant" value="{{ $variant->id }}" form="checkout-form"
                                       class="sr-only" x-model="variant" @change="selectVariant('{{ $variant->id }}')"
                                       @checked($variant->is($default))>
                                {{--
                                    R1:手機改成「名稱一行、單價下一行」。
                                    ⛔ 原本 flex + shrink-0 會把單價擠出 viewport,
                                    在 390px 只看得到款式名稱。sm 以上恢復左右排列。
                                --}}
                                <span class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between sm:gap-3">
                                    <span class="min-w-0 font-bold">{{ $variant->label }}</span>
                                    <span data-probe="variant-price"
                                          class="text-sm tabular-nums text-black/65 sm:shrink-0">
                                        NT${{ number_format((float) $variant->unit_price, 2) }}／{{ $variant->quantity_unit }}
                                    </span>
                                </span>
                                @if ($variant->image_path)
                                    <img src="{{ \Illuminate\Support\Facades\Storage::url($variant->image_path) }}"
                                         alt="{{ $variant->image_alt }}"
                                         class="mt-3 h-auto w-full rounded-xl" loading="lazy">
                                @endif
                                {{-- 說明改由下方「服務項目簡介」框呈現，⛔ 卡片內不重複同一段文字。 --}}
                                <span data-probe="variant-bounds"
                                      class="mt-2 block text-sm tabular-nums text-black/65">
                                    {{ number_format($variant->min_quantity) }}–{{ number_format($variant->max_quantity) }} {{ $variant->quantity_unit }}
                                </span>
                            </label>
                        @endforeach
                    </div>

                    {{-- 目前選中服務項目的簡介。初始 HTML 先輸出預設服務項目，⛔ 關閉 JS 仍看得到內容。 --}}
                    @if ($hasAnyDescription)
                        <div class="mt-4 rounded-2xl border border-black/10 bg-paper p-5"
                             aria-live="polite" aria-atomic="true">
                            <p class="text-sm font-bold text-black/60">服務項目簡介</p>
                            <p class="mt-2 font-bold" x-text="b.label">{{ $default->label }}</p>
                            {{-- whitespace-pre-line 讓後台輸入的換行在前台保留；
                                 ⛔ 沒有它時 HTML 會把換行折成一整段。x-text 也會照樣
                                 輸出換行字元，所以切換服務項目後仍然分行。 --}}
                            <p class="mt-1.5 whitespace-pre-line leading-7 text-black/60"
                               x-text="b.description || '這個服務項目尚未填寫簡介。'">
                                {{ $default->description ?: '這個服務項目尚未填寫簡介。' }}
                            </p>
                            <p class="mt-3 text-sm tabular-nums text-black/65">
                                單價 NT$<span x-text="b.unit_price">{{ number_format((float) $default->unit_price, 2) }}</span>／<span
                                    x-text="b.unit">{{ $default->quantity_unit }}</span>
                                ・可買
                                <span x-text="Number(b.min).toLocaleString()">{{ number_format($default->min_quantity) }}</span>–<span
                                    x-text="Number(b.max).toLocaleString()">{{ number_format($default->max_quantity) }}</span>
                                <span x-text="b.unit">{{ $default->quantity_unit }}</span>
                            </p>
                        </div>
                    @endif
                </aside>
            </div>

            {{-- 右欄只做選品試算；履約、聯絡、發票與付款一律在 /checkout 填寫。
                 卡片短，sticky 不會讓任何欄位捲不到，⛔ 也不加內層 scrollbar。 --}}
            <div class="lg:sticky lg:top-6">
                <section id="checkout" data-probe="estimate-card" class="surface p-5 sm:p-7" aria-labelledby="estimate-title">
                    <div class="flex items-start justify-between gap-5">
                        <div>
                            <p class="eyebrow">Estimate</p>
                            <h2 id="estimate-title" class="mt-2 text-2xl font-bold tracking-[-0.03em]">方案試算</h2>
                        </div>

                    </div>

                    {{-- 服務項目的 radio 在左欄，⛔ 這裡不重複第二組 selector。 --}}
                    <dl class="mt-6 space-y-2 border-b border-black/10 pb-5 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-black/65">已選服務項目</dt>
                            <dd class="font-bold" x-text="b.label">{{ $default->label }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-black/65">單價</dt>
                            <dd class="font-semibold tabular-nums">
                                NT$<span x-text="b.unit_price">{{ number_format((float) $default->unit_price, 2) }}</span>／<span
                                    x-text="b.unit">{{ $default->quantity_unit }}</span>
                            </dd>
                        </div>
                    </dl>

                    <form id="checkout-form" action="{{ route('checkout.start') }}" method="post" class="mt-6">
                        @csrf
                        {{-- 左欄的 radio 以 form="checkout-form" 關聯到這張表單，本身就是
                             唯一的 variant 成功控制項。⛔ 不可再放同名 hidden：那會讓
                             無 JavaScript 送出時出現重複 key，並可能用預設值蓋掉客人選的。 --}}

                        <label for="quantity" class="mb-3 block text-sm font-bold">
                            輸入數量（<span x-text="b.unit">{{ $unit }}</span>）
                        </label>
                        <input id="quantity" name="quantity" type="number" inputmode="numeric" required
                               x-model="quantity"
                               :min="b.min" :max="b.max" :step="b.step"
                               value="{{ old('quantity', $startQuantity) }}"
                               aria-describedby="quantity-hint"
                               class="min-h-14 w-full rounded-2xl border border-black/15 bg-white px-4 py-3 text-base tabular-nums">
                        <p id="quantity-hint" class="mt-2 text-sm leading-6 text-black/65">
                            可輸入 <span x-text="Number(b.min).toLocaleString()">{{ number_format($default->min_quantity) }}</span>
                            至 <span x-text="Number(b.max).toLocaleString()">{{ number_format($default->max_quantity) }}</span>
                            <span x-text="b.unit">{{ $unit }}</span>，需為
                            <span x-text="b.step">{{ $default->step_quantity }}</span> 的倍數。
                        </p>
                        <p class="mt-2 text-sm" x-show="!valid" x-cloak>
                            <span class="text-red-700">數量不符合此服務項目的可購買範圍。</span>
                        </p>
                        @error('quantity') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
                        @error('variant') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror

                        <div class="mt-6 rounded-2xl bg-paper p-5">
                            <div class="flex items-baseline justify-between gap-4">
                                <span class="text-sm font-bold">試算總額</span>
                                <span class="text-2xl font-bold tabular-nums tracking-[-0.03em]">
                                    NT$<span x-text="estimate">—</span>
                                </span>
                            </div>
                            <p class="mt-2 text-sm leading-6 text-black/65">
                                實際金額以結帳頁顯示為準。
                            </p>
                        </div>

                        {{-- M2-D-A:真正的購買動作,用 accent 與一般導覽按鈕區分。 --}}
                        <button type="submit" data-probe="purchase-cta" class="primary-button primary-button--purchase mt-6">繼續結帳</button>

                        <p class="mt-4 text-sm leading-6 text-black/65">
                            下一步填寫{{ $service->input_label }}、聯絡方式與電子發票。
                        </p>
                    </form>
                </section>
            </div>
        </section>
    </div>
    @endif

    {{-- 內容區塊：固定安全模板輸出 H2／段落；⛔ 後台內容不得注入 HTML 或 script --}}
    @if (filled($service->intro) || $service->contentSections->isNotEmpty())
        <section class="border-t border-black/10 bg-white">
            <div class="mx-auto max-w-3xl px-5 py-14 sm:px-8">
                {{-- 「詳細介紹」是長文欄位，放在長文區塊開頭；hero 只留一句話說明。 --}}
                @if (filled($service->intro))
                    <p class="whitespace-pre-line leading-8 text-black/70">{{ $service->intro }}</p>
                @endif

                @foreach ($service->contentSections as $section)
                    <article class="@if (! $loop->first || filled($service->intro)) mt-10 @endif">
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
                        LINE Pay 或綠界付款；付款完成後開始安排交付。
                    </dd>
                </div>
            </dl>
        </div>
    </section>

    @if ($service->faqs->isNotEmpty())
        <section class="border-t border-black/10 bg-paper">
            <div class="mx-auto max-w-3xl px-5 py-12 sm:px-8">
                <h2 class="text-2xl font-bold tracking-[-0.035em]">常見問題</h2>
                {{-- R5:問題以 h3 可讀 heading;收合後答案仍在初始 HTML。 --}}
                <div class="mt-6 divide-y divide-black/10 border-y border-black/10">
                    @foreach ($service->faqs as $faq)
                        <details class="group py-5">
                            <summary class="min-h-11 cursor-pointer list-none">
                                <h3 class="text-base font-bold">{{ $faq->question }}</h3>
                            </summary>
                            <p class="mt-3 text-base leading-7 text-black/70">{{ $faq->answer }}</p>
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
                @foreach ($platform->services()->published()->whereNotNull('product_slug')->orderBy('sort_order')->get() as $other)
                    @if ($other->id !== $service->id)
                        <li>
                            <a href="{{ $other->primaryUrl() }}"
                               class="flex min-h-20 flex-col justify-center rounded-2xl border border-black/10 bg-white px-5 py-4 transition-colors duration-200 hover:border-ink">
                                <span class="font-bold">{{ $other->card_title ?: $other->name }}</span>
                                <span class="mt-1 text-sm leading-6 text-black/65">{{ $other->summary }}</span>
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
