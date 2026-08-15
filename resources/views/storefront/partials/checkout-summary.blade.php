{{-- 訂單摘要：桌面 sticky 欄與手機展開區塊共用同一份，⛔ 避免兩處數字不一致。 --}}
<dl class="space-y-3 text-sm">
    <div class="flex justify-between gap-4">
        <dt class="text-black/55">平台</dt>
        <dd class="font-semibold">{{ $platform->name }}</dd>
    </div>
    <div class="flex justify-between gap-4">
        <dt class="text-black/55">服務分類</dt>
        <dd class="font-semibold">{{ $service->name }}</dd>
    </div>
    <div class="flex justify-between gap-4">
        <dt class="text-black/55">服務項目</dt>
        <dd class="font-semibold">{{ $variant->label }}</dd>
    </div>
    <div class="flex justify-between gap-4">
        <dt class="text-black/55">數量</dt>
        <dd class="font-semibold tabular-nums">{{ number_format($quantity) }} {{ $variant->quantity_unit }}</dd>
    </div>
    <div class="flex justify-between gap-4">
        <dt class="text-black/55">單價</dt>
        <dd class="font-semibold tabular-nums">
            NT${{ number_format((float) $variant->unit_price, 2) }}／{{ $variant->quantity_unit }}
        </dd>
    </div>
</dl>

<div class="mt-4 flex items-baseline justify-between gap-4 border-t border-black/10 pt-4">
    <span class="text-sm font-bold">總額</span>
    <span class="text-2xl font-bold tabular-nums tracking-[-0.03em]">NT${{ number_format($amount) }}</span>
</div>

{{-- 回商品頁修改；session 仍保留，⛔ 不必重新找商品。 --}}
<a href="{{ $returnUrl }}#checkout"
   class="mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-full border border-black/15 bg-white px-5 text-sm font-bold transition-colors duration-200 hover:border-ink">
    返回修改
</a>
