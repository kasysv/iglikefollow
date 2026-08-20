@extends('layouts.checkout', ['title' => '結帳'])

@section('content')
<main class="mx-auto max-w-[1120px] px-5 py-8 sm:px-8 lg:py-12"
      x-data="{
          invoiceKind: '{{ old('invoice_kind', 'personal') }}',
          personalMode: '{{ old('personal_invoice_mode', 'email') }}',
          summaryOpen: false,
      }">

    <h1 class="text-[clamp(1.7rem,2.6vw,2.3rem)] font-bold leading-[1.15] tracking-[-0.04em]">確認訂單資料</h1>
    <p class="mt-3 text-sm leading-7 text-black/55">
        商品與數量已在上一頁選好。這一頁填寫交付對象、聯絡方式與電子發票。
    </p>

    {{-- 手機版：摘要先以可展開區塊呈現，⛔ 不用會遮住錯誤訊息的全螢幕 modal。 --}}
    <section class="surface mt-6 p-5 lg:hidden" aria-labelledby="summary-mobile-title">
        <button type="button" class="flex min-h-11 w-full items-center justify-between gap-4 text-left"
                @click="summaryOpen = !summaryOpen"
                :aria-expanded="summaryOpen ? 'true' : 'false'"
                aria-controls="summary-mobile">
            <span>
                <span id="summary-mobile-title" class="block text-sm font-bold">訂單摘要</span>
                <span class="mt-1 block text-xs text-black/55">{{ $service->name }}／{{ $variant->label }}</span>
            </span>
            <span class="shrink-0 text-lg font-bold tabular-nums">NT${{ number_format($amount) }}</span>
        </button>

        <div id="summary-mobile" x-show="summaryOpen" x-cloak class="mt-5 border-t border-black/10 pt-5">
            @include('storefront.partials.checkout-summary')
        </div>
    </section>

    <div class="mt-6 grid items-start gap-8 lg:mt-8 lg:grid-cols-[1fr_360px] lg:gap-12">

        {{-- Sandbox 付款開啟時才送往真正的付款流程；⛔ 預設仍走 local mock。
             這只決定表單 POST 到哪裡：兩條路徑背後的驗證與建單完全相同。 --}}
        @php($paymentsEnabled = config('integrations.payments.sandbox_enabled'))

        <form action="{{ $paymentsEnabled ? route('payments.start') : route('checkout.mock') }}"
              method="post" class="space-y-8">
            @csrf

            {{-- 1. 履約資料 --}}
            <section class="surface p-5 sm:p-7" aria-labelledby="fulfilment-title">
                <h2 id="fulfilment-title" class="text-lg font-bold tracking-[-0.02em]">1. 交付對象</h2>
                <p class="mt-2 text-sm leading-6 text-black/55">{{ $service->delivery_summary }}</p>

                <div class="mt-5">
                    <label for="target" class="mb-2 block text-sm font-semibold">
                        {{ $service->input_label }} <span class="text-red-700" aria-hidden="true">*</span>
                    </label>
                    <input id="target" name="target" value="{{ old('target') }}" required maxlength="255"
                           placeholder="{{ $service->input_hint }}"
                           aria-describedby="target-hint"
                           class="min-h-14 w-full rounded-2xl border border-black/15 bg-white px-4 py-3 text-base placeholder:text-black/35">
                    <p id="target-hint" class="mt-2 text-xs leading-5 text-black/50">
                        {{ $service->input_label }}須為公開狀態才能交付。
                    </p>
                    @error('target') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </section>

            {{-- 2. 聯絡資料：只收通知所需的最小欄位，⛔ 不要求姓名、地址或會員帳號。 --}}
            <section class="surface p-5 sm:p-7" aria-labelledby="contact-title">
                <h2 id="contact-title" class="text-lg font-bold tracking-[-0.02em]">2. 聯絡資料</h2>

                <div class="mt-5 space-y-4">
                    <div>
                        <label for="customer_email" class="mb-2 block text-sm font-semibold">
                            Email <span class="text-red-700" aria-hidden="true">*</span>
                        </label>
                        <input id="customer_email" name="customer_email" type="email" required
                               inputmode="email" autocomplete="email" maxlength="80"
                               value="{{ old('customer_email') }}"
                               aria-describedby="email-hint"
                               class="min-h-14 w-full rounded-2xl border border-black/15 bg-white px-4 py-3 text-base">
                        <p id="email-hint" class="mt-2 text-xs leading-5 text-black/50">用來接收訂單與電子發票通知。</p>
                        @error('customer_email') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="customer_phone" class="mb-2 block text-sm font-semibold">
                            手機 <span class="font-normal text-black/45">（選填）</span>
                        </label>
                        <input id="customer_phone" name="customer_phone" type="tel"
                               inputmode="tel" autocomplete="tel" maxlength="20"
                               value="{{ old('customer_phone') }}"
                               aria-describedby="phone-hint"
                               class="min-h-14 w-full rounded-2xl border border-black/15 bg-white px-4 py-3 text-base tabular-nums">
                        <p id="phone-hint" class="mt-2 text-xs leading-5 text-black/50">客服需要聯絡時使用。</p>
                        @error('customer_phone') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            {{-- 3. 電子發票：全程無紙化，⛔ 不提供紙本、列印、地址或郵寄欄位。 --}}
            <section class="surface p-5 sm:p-7" aria-labelledby="invoice-title">
                <h2 id="invoice-title" class="text-lg font-bold tracking-[-0.02em]">3. 電子發票</h2>

                <div class="mt-5 grid gap-2 sm:grid-cols-2">
                    <label class="payment-card">
                        <input type="radio" name="invoice_kind" value="personal"
                               x-model="invoiceKind" @checked(old('invoice_kind', 'personal') === 'personal')>
                        <span class="font-bold">個人電子發票</span>
                    </label>
                    <label class="payment-card">
                        <input type="radio" name="invoice_kind" value="business"
                               x-model="invoiceKind" @checked(old('invoice_kind') === 'business')>
                        <span class="font-bold">公司統編發票</span>
                    </label>
                </div>
                @error('invoice_kind') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror

                <div x-show="invoiceKind === 'personal'" x-cloak class="mt-5">
                    <p class="mb-2 text-sm font-semibold">發票存放方式</p>
                    <div class="space-y-2">
                        @foreach ([
                            'email' => ['Email 電子發票', '開立後寄送到上方 Email。'],
                            'mobile_barcode' => ['手機條碼載具', '存入你的手機條碼，可自行歸戶。'],
                            'donation' => ['捐贈發票', '將這張發票捐贈給社福機構。'],
                        ] as $mode => [$modeLabel, $modeHint])
                            <label class="invoice-option">
                                <input type="radio" name="personal_invoice_mode" value="{{ $mode }}"
                                       x-model="personalMode"
                                       :disabled="invoiceKind !== 'personal'"
                                       @checked(old('personal_invoice_mode', 'email') === $mode)>
                                <span>
                                    <span class="block font-semibold">{{ $modeLabel }}</span>
                                    <span class="mt-0.5 block text-xs leading-5 text-black/50">{{ $modeHint }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('personal_invoice_mode') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror

                    {{-- 條件欄位隱藏時一律 disabled，⛔ 不可夾帶前一模式的殘留值。 --}}
                    <div x-show="personalMode === 'mobile_barcode'" x-cloak class="mt-4">
                        <label for="carrier_number" class="mb-2 block text-sm font-semibold">手機條碼</label>
                        <input id="carrier_number" name="carrier_number" maxlength="8" placeholder="/ABC1234"
                               value="{{ old('carrier_number') }}"
                               :disabled="!(invoiceKind === 'personal' && personalMode === 'mobile_barcode')"
                               class="min-h-14 w-full rounded-2xl border border-black/15 bg-white px-4 py-3 text-base uppercase placeholder:normal-case placeholder:text-black/35">
                        <p class="mt-2 text-xs leading-5 text-black/50">格式為 / 加 7 碼大寫英數字。</p>
                        @error('carrier_number') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>

                    <div x-show="personalMode === 'donation'" x-cloak class="mt-4">
                        <label for="love_code" class="mb-2 block text-sm font-semibold">愛心碼</label>
                        <input id="love_code" name="love_code" inputmode="numeric" maxlength="7" placeholder="12345"
                               value="{{ old('love_code') }}"
                               :disabled="!(invoiceKind === 'personal' && personalMode === 'donation')"
                               class="min-h-14 w-full rounded-2xl border border-black/15 bg-white px-4 py-3 text-base tabular-nums placeholder:text-black/35">
                        <p class="mt-2 text-xs leading-5 text-black/50">3 至 7 位數字。捐贈後不另開立個人發票。</p>
                        @error('love_code') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- 公司模式固定為無紙化 Email 電子載具，⛔ 不顯示捐贈、紙本或地址。 --}}
                <div x-show="invoiceKind === 'business'" x-cloak class="mt-5 space-y-4">
                    <div>
                        <label for="buyer_tax_id" class="mb-2 block text-sm font-semibold">統一編號</label>
                        <input id="buyer_tax_id" name="buyer_tax_id" inputmode="numeric" maxlength="8"
                               placeholder="12345678" value="{{ old('buyer_tax_id') }}"
                               :disabled="invoiceKind !== 'business'"
                               class="min-h-14 w-full rounded-2xl border border-black/15 bg-white px-4 py-3 text-base tabular-nums placeholder:text-black/35">
                        <p class="mt-2 text-xs leading-5 text-black/50">8 位數字。</p>
                        @error('buyer_tax_id') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="buyer_name" class="mb-2 block text-sm font-semibold">公司／行號登記名稱</label>
                        <input id="buyer_name" name="buyer_name" maxlength="60"
                               value="{{ old('buyer_name') }}"
                               :disabled="invoiceKind !== 'business'"
                               class="min-h-14 w-full rounded-2xl border border-black/15 bg-white px-4 py-3 text-base">
                        @error('buyer_name') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>

                    <p class="rounded-xl bg-paper px-4 py-3 text-xs leading-6 text-black/60">
                        電子發票寄送至上方 Email，不提供紙本與郵寄。
                    </p>
                </div>
            </section>

            {{-- 4. 付款方式：與電子發票選擇互相獨立。 --}}
            <section class="surface p-5 sm:p-7" aria-labelledby="payment-title">
                <h2 id="payment-title" class="text-lg font-bold tracking-[-0.02em]">4. 付款方式</h2>

                <div class="mt-5 grid gap-2 sm:grid-cols-2">
                    <label class="payment-card">
                        <input type="radio" name="payment" value="line-pay" @checked(old('payment', 'line-pay') === 'line-pay')>
                        <span class="font-bold">LINE Pay</span>
                    </label>
                    <label class="payment-card">
                        <input type="radio" name="payment" value="ecpay" @checked(old('payment') === 'ecpay')>
                        <span class="font-bold">綠界付款</span>
                    </label>
                </div>
                @error('payment') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
            </section>

            <div class="surface p-5 sm:p-7">
                <div class="flex items-baseline justify-between gap-4">
                    <span class="text-sm font-bold">應付金額</span>
                    <span class="text-3xl font-bold tabular-nums tracking-[-0.03em]">NT${{ number_format($amount) }}</span>
                </div>
                <p class="mt-2 text-xs leading-5 text-black/50">金額由伺服器依目前單價重新計算。</p>

                {{-- R4:顧客語氣;本機不會真實扣款由既有 flags/mock gate 保證,⛔ 不靠文案。 --}}
                <button type="submit" class="primary-button mt-5">前往付款</button>

                <p class="mt-4 text-xs leading-6 text-black/55">
                    支援 LINE Pay、綠界付款；付款成功後自動處理，並開立電子發票。
                </p>
            </div>
        </form>

        {{-- 桌面版 sticky 摘要 --}}
        <aside class="hidden lg:sticky lg:top-6 lg:block" aria-labelledby="summary-title">
            <div class="surface p-5 sm:p-6">
                <h2 id="summary-title" class="text-sm font-bold">訂單摘要</h2>
                <div class="mt-4">
                    @include('storefront.partials.checkout-summary')
                </div>
            </div>
        </aside>
    </div>
</main>
@endsection
