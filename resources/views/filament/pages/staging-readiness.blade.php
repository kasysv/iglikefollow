<x-filament-panels::page>
    @php($report = $this->getReport())

    {{-- ⛔ 唯讀狀態頁:沒有任何 enable/測試連線/重送/標記/清除動作。 --}}
    <div style="display:flex;flex-direction:column;gap:1rem;font-size:.875rem;line-height:1.6;">
        <p style="opacity:.8;">
            blocker <strong>{{ $report['blockers'] }}</strong>;
            能力未開啟(blocked,預期狀態)<strong>{{ $report['blocked'] }}</strong>。
            「credential 已填」不等於「允許連線」;開啟任何能力需要另一次明確批准。
        </p>

        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="text-align:left;border-bottom:1px solid rgba(128,128,128,.4);">
                        <th style="padding:.4rem .5rem;">檢查</th>
                        <th style="padding:.4rem .5rem;">狀態值</th>
                        <th style="padding:.4rem .5rem;">判定</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['checks'] as $check)
                        <tr style="border-bottom:1px solid rgba(128,128,128,.15);">
                            <td style="padding:.4rem .5rem;">{{ $check['label'] }}</td>
                            <td style="padding:.4rem .5rem;font-family:monospace;">{{ $check['value'] }}</td>
                            <td style="padding:.4rem .5rem;">
                                @if ($check['status'] === 'ok')
                                    ✔ ok
                                @elseif ($check['status'] === 'blocked')
                                    ◻ 未開啟(預期)
                                @else
                                    ✘ blocker
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p style="opacity:.7;">
            CLI 對應:<code>php artisan app:staging-readiness</code>(同一份報告;
            <code>--strict-live-readiness</code> 會把未開啟的付款/發票/派單能力視為失敗)。
        </p>
    </div>
</x-filament-panels::page>
