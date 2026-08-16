{{-- 自動送出到付款服務的中繼頁。
     ⛔ 已簽章的欄位放在 POST body，不放 query string：網址會留在瀏覽器歷史、
     referrer 與沿途每一個 proxy log 裡。 --}}
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>正在前往付款頁</title>
</head>
<body class="bg-white">
    <noscript>
        <p>請按下方按鈕前往付款頁。</p>
    </noscript>

    <p style="font-family: system-ui, sans-serif; padding: 2rem; text-align: center;">
        正在前往付款頁，請稍候……
    </p>

    <form id="payment-form" method="POST" action="{{ $endpoint }}">
        @foreach ($fields as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach

        <noscript>
            <div style="text-align: center;">
                <button type="submit">前往付款</button>
            </div>
        </noscript>
    </form>

    <script>
        document.getElementById('payment-form').submit();
    </script>
</body>
</html>
