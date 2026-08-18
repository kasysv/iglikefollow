{{-- 履約對照卡:Owner-only、edit-only、read-only。 --}}
{{-- ⛔ provider-controlled 文字(name/category/type)一律 {{ }} escaped;禁止 {!! !!}。 --}}
@if ($card === null)
    {{-- create 頁無 record:區塊本身已隱藏,這裡是雙重保險。 --}}
@else
@php
    $status = $card['status'];
    $mapping = $card['mapping'];
    $provider = $card['provider'];
    $assessment = $card['assessment'];
@endphp

<div style="display:flex;flex-direction:column;gap:1rem;font-size:0.875rem;line-height:1.6;">
    <p style="opacity:.75;">
        以下為<strong>目前已儲存值</strong>的對照——尚未儲存的表單輸入不會參與比較。
    </p>

    {{-- 1. 對應狀態 --}}
    <div>
        <strong>對應狀態:</strong>
        @if ($status === 'none')
            尚未設定履約對應。
        @elseif ($status === 'disabled')
            已停用(草稿)。
        @else
            已啟用。
        @endif
        <span style="opacity:.75;">啟用對應不等於自動派單;自動派單另有總開關,本階段一律關閉。</span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(16rem,1fr));gap:1rem;">
        {{-- 2. 本站商品 --}}
        <div style="border:1px solid rgba(128,128,128,.35);border-radius:.5rem;padding: .75rem 1rem;">
            <div style="font-weight:600;margin-bottom:.35rem;">本站商品(已儲存)</div>
            <div>SKU:{{ $card['sku'] }}</div>
            <div>名稱:{{ $card['label'] }}</div>
            <div>計價率:NT$ {{ $card['unitPrice'] }} / {{ $card['quantityUnit'] }}</div>
            <div>min {{ $card['min'] }}／max {{ $card['max'] }}／step {{ $card['step'] }}</div>
            <div>
                實際可購:
                @if ($card['siteFirst'] === null)
                    無(設定不合規)
                @else
                    {{ $card['siteFirst'] }}–{{ $card['siteLast'] }}
                @endif
            </div>
            @if ($card['defaultTotal'] !== null)
                <div>預設數量 {{ $card['defaultQuantity'] }} 試算:NT$ {{ $card['defaultTotal'] }}(整數台幣)</div>
            @endif
        </div>

        {{-- 3–4. TheMostPanel 項目 --}}
        <div style="border:1px solid rgba(128,128,128,.35);border-radius:.5rem;padding:.75rem 1rem;">
            <div style="font-weight:600;margin-bottom:.35rem;">TheMostPanel 項目(已儲存對應)</div>
            @if ($status === 'none')
                <div style="opacity:.75;">尚未選擇供應商項目。</div>
            @elseif ($card['providerMissing'])
                <div>⚠ 對應的服務代碼 {{ $mapping->provider_service_id }} 已不在本機目錄;僅可停用保留或改選可用項目。</div>
            @else
                <div>服務代碼:{{ $provider->provider_service_id }}</div>
                <div>名稱:{{ $provider->name }}</div>
                <div>分類:{{ $provider->category }}</div>
                <div>型別:{{ $provider->service_type }}</div>
                <div>min {{ $provider->minimum_quantity_raw }}／max {{ $provider->maximum_quantity_raw }}</div>
                <div>refill:{{ $provider->supports_refill ? '有' : '無' }}／cancel:{{ $provider->supports_cancel ? '有' : '無' }}</div>
                <div>最後觀察:{{ $provider->last_seen_at ?? '未記錄' }}</div>
                @if (! $provider->is_available)
                    <div>⚠ 此項目已標記不可用;僅可停用保留。</div>
                @endif
                <div style="margin-top:.35rem;">
                    Provider raw rate:NT$ {{ $provider->rate_raw }}<br>
                    <span style="opacity:.75;">幣別為 TWD;計費基準尚未確認,不是本站售價,暫不計算成本/毛利。</span>
                </div>
            @endif
        </div>
    </div>

    {{-- 5. 相容性 --}}
    <div>
        <strong>相容性:</strong>
        @if ($status === 'none')
            <span style="opacity:.75;">設定對應後才有相容性判定。</span>
        @elseif ($card['providerMissing'])
            <span>✘ 無法判定:供應商項目不在目錄。</span>
        @else
            {{ $assessment->label() }}
            @if (! $assessment->compatible && $assessment->reason !== \App\Support\QuantityCompatibility::INVALID_SITE_QUANTITY_STRUCTURE)
                <span style="opacity:.85;">
                    (本站實際可購 {{ $card['siteFirst'] ?? '無' }}@if($card['siteFirst'] !== null)–{{ $card['siteLast'] }}@endif;
                    供應商 min {{ $provider->minimum_quantity_raw }}／max {{ $provider->maximum_quantity_raw }})
                </span>
            @endif
        @endif
    </div>
</div>
@endif
