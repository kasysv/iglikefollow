{{-- 付款結果頁。⛔ 只顯示伺服器端已確認的狀態，本頁不改變任何訂單。 --}}
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>訂單狀態</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-neutral-50">
    <main class="mx-auto max-w-lg px-5 py-12">
        <h1 class="text-2xl font-bold text-neutral-900">訂單 {{ $order->reference }}</h1>

        @php($status = $order->payment_status)

        <div class="mt-6 rounded-xl border border-neutral-200 bg-white p-5">
            <p class="text-sm text-neutral-500">目前狀態</p>
            <p class="mt-1 text-lg font-bold text-neutral-900">{{ $status->label() }}</p>

            <p class="mt-4 text-sm leading-relaxed text-neutral-600">
                @if ($order->isPaid())
                    付款已完成，我們會開始處理這筆訂單。
                @elseif ($status->needsReconciliation())
                    {{-- ⛔ 不對客人宣稱「失敗」：錢可能已經扣了。 --}}
                    這筆付款的結果尚未確認，我們正在與付款服務核對。
                    請先不要重新付款，以免重複扣款。
                @elseif ($status === \App\Enums\PaymentStatus::Succeeded)
                    付款已完成。
                @elseif ($status->isOpen())
                    我們正在確認付款結果，這可能需要幾分鐘。
                @else
                    這筆付款沒有完成，您可以重新選購後再試一次。
                @endif
            </p>
        </div>

        <div class="mt-6 rounded-xl border border-neutral-200 bg-white p-5">
            <p class="text-sm text-neutral-500">應付金額</p>
            <p class="mt-1 text-lg font-bold tabular-nums text-neutral-900">
                NT${{ number_format((int) $order->total_amount) }}
            </p>
        </div>

        <a href="{{ route('home') }}"
           class="mt-8 inline-block rounded-lg bg-neutral-900 px-5 py-3 text-sm font-bold text-white">
            回到首頁
        </a>
    </main>
</body>
</html>
