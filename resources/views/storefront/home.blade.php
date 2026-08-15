@extends('layouts.app', [
    'title' => 'Instagram 粉絲服務｜IGLIKEFOLLOW',
    'description' => '選擇 Instagram 粉絲方案、填寫帳號或網址並快速付款。此頁目前為本機 mock 預覽，不會建立真實訂單。',
])

@section('content')
<main>
    <section class="mx-auto grid max-w-[1220px] gap-10 px-5 py-12 sm:px-8 lg:grid-cols-[1fr_520px] lg:gap-16 lg:py-20">
        <div class="flex flex-col justify-center lg:pb-12">
            <p class="eyebrow">Instagram growth service</p>
            <h1 class="mt-5 max-w-3xl text-[clamp(3rem,7vw,6.8rem)] font-bold leading-[0.98] tracking-[-0.055em]">
                讓成長，<br>更簡單。
            </h1>
            <p class="mt-6 text-xl font-semibold tracking-[-0.02em] sm:text-2xl">{{ $service['name'] }}</p>
            <p class="mt-4 max-w-2xl text-base leading-8 text-black/60 sm:text-lg">
                免會員、快速付款、訂單即時建立。從選擇方案到完成付款，用更少步驟開始 Instagram 服務。
            </p>
            <div class="mt-9 grid max-w-2xl grid-cols-3 gap-3 border-y border-black/10 py-5 text-sm">
                <div><strong class="block text-base">安全交易</strong><span class="text-black/55">後端驗證付款</span></div>
                <div><strong class="block text-base">快速交付</strong><span class="text-black/55">付款後建立訂單</span></div>
                <div><strong class="block text-base">安心服務</strong><span class="text-black/55">專人處理例外</span></div>
            </div>
        </div>

        <section id="checkout" class="surface p-5 sm:p-7" aria-labelledby="checkout-title"
                 x-data="{ plan: 'followers-1000', payment: 'line-pay' }">
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
                        @foreach ($plans as $key => $plan)
                            <label class="choice-card">
                                <input type="radio" name="plan" value="{{ $key }}" class="sr-only"
                                       x-model="plan" @checked(!empty($plan['featured']))>
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
                    <label for="target" class="mb-3 block text-sm font-bold">2. Instagram 帳號或網址</label>
                    <input id="target" name="target" value="{{ old('target') }}" required maxlength="255"
                           placeholder="例如：username 或 instagram.com/username"
                           class="min-h-14 w-full rounded-2xl border border-black/15 bg-white px-4 py-3 text-base placeholder:text-black/35">
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

    <section id="services" class="bg-ink text-white">
        <div class="mx-auto max-w-[1220px] px-5 py-16 sm:px-8 lg:py-24">
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-white/55">Instagram services</p>
            <div class="mt-5 grid gap-10 lg:grid-cols-[0.8fr_1.2fr]">
                <h2 class="text-4xl font-bold leading-tight tracking-[-0.045em] sm:text-6xl">一個平台，找到真正不同的服務。</h2>
                <div class="grid gap-3 sm:grid-cols-3">
                    <article class="rounded-3xl bg-white/8 p-6">
                        <p class="text-sm text-white/55">01</p><h3 class="mt-12 text-2xl font-bold">粉絲</h3>
                        <p class="mt-3 leading-7 text-white/65">依粉絲服務意圖選擇適合方案，同頁比較數量，不建立重複 SEO 頁。</p>
                    </article>
                    <article class="rounded-3xl bg-white/8 p-6">
                        <p class="text-sm text-white/55">02</p><h3 class="mt-12 text-2xl font-bold">貼文讚</h3>
                        <p class="mt-3 leading-7 text-white/65">針對指定貼文的一次性服務，與自動貼文讚分開說明。</p>
                    </article>
                    <article class="rounded-3xl bg-white/8 p-6">
                        <p class="text-sm text-white/55">03</p><h3 class="mt-12 text-2xl font-bold">留言</h3>
                        <p class="mt-3 leading-7 text-white/65">以獨立服務頁承接留言需求，避免粉絲與留言搜尋意圖混淆。</p>
                    </article>
                </div>
            </div>
        </div>
    </section>

    <section id="process" class="mx-auto max-w-[1220px] px-5 py-16 sm:px-8 lg:py-24">
        <p class="eyebrow">How it works</p>
        <h2 class="mt-4 max-w-3xl text-4xl font-bold tracking-[-0.045em] sm:text-6xl">三個步驟，完成訂單。</h2>
        <div class="mt-10 grid gap-px overflow-hidden rounded-[1.75rem] bg-black/10 sm:grid-cols-3">
            <article class="bg-white p-7"><span class="text-sm text-black/45">01</span><h3 class="mt-12 text-2xl font-bold">選擇方案</h3><p class="mt-3 leading-7 text-black/55">比較真正不同的服務與數量。</p></article>
            <article class="bg-white p-7"><span class="text-sm text-black/45">02</span><h3 class="mt-12 text-2xl font-bold">填寫目標</h3><p class="mt-3 leading-7 text-black/55">依服務輸入帳號或貼文網址。</p></article>
            <article class="bg-white p-7"><span class="text-sm text-black/45">03</span><h3 class="mt-12 text-2xl font-bold">安全付款</h3><p class="mt-3 leading-7 text-black/55">後端確認付款，再建立履約訂單。</p></article>
        </div>
    </section>

    <section id="faq" class="border-t border-black/10 bg-white">
        <div class="mx-auto max-w-3xl px-5 py-16 sm:px-8 lg:py-24">
            <p class="eyebrow">FAQ</p>
            <h2 class="mt-4 text-4xl font-bold tracking-[-0.04em]">購買前常見問題</h2>
            <div class="mt-8 divide-y divide-black/10 border-y border-black/10">
                <details class="group py-5"><summary class="min-h-11 cursor-pointer list-none text-lg font-bold">需要註冊會員嗎？</summary><p class="mt-3 leading-7 text-black/60">不需要。正式流程會以免會員快速結帳為基線。</p></details>
                <details class="group py-5"><summary class="min-h-11 cursor-pointer list-none text-lg font-bold">付款後會立即建立訂單嗎？</summary><p class="mt-3 leading-7 text-black/60">正式版必須由後端驗證綠界或 LINE Pay 付款成功後才建立履約流程，不能只相信前端成功頁。</p></details>
                <details class="group py-5"><summary class="min-h-11 cursor-pointer list-none text-lg font-bold">現在可以真的付款嗎？</summary><p class="mt-3 leading-7 text-black/60">不可以。目前是本機 mock 骨架，沒有連接任何正式金流或下單平台。</p></details>
            </div>
        </div>
    </section>
</main>
@endsection
