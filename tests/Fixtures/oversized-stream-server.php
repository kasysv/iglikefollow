<?php

/*
 * localhost 測試 fixture:模擬一個會送出超大 body 的 provider。
 *
 * ⛔ 只給 `TheMostPanelBoundedTransportIntegrationTest` 用,由測試以
 * `php -S 127.0.0.1:<port>` 啟動、測試結束即終止;它永遠不出現在任何
 * 部署或 runtime 路徑上,也不聽非 loopback 位址。
 *
 * `/stream` 刻意**不宣告 Content-Length**:宣告了長度的超大回應在 header
 * 階段就會被拒,考驗不到傳輸中的中止;這裡要證明的是 chunked／未知長度的
 * body 也會在下載完成之前被 bounded sink 的 short write 截停。
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

while (ob_get_level() > 0) {
    ob_end_clean();
}

if ($uri === '/ping') {
    echo 'pong';

    exit;
}

if ($uri === '/small') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'order' => 23501]);

    exit;
}

if ($uri === '/stream') {
    header('Content-Type: application/octet-stream');

    // 40 × 256 KiB = 10 MiB,遠超 2 MiB 上限。
    $chunk = str_repeat('A', 262_144);

    for ($i = 0; $i < 40; $i++) {
        echo $chunk;
        flush();

        // 客戶端中止後就停:讓「對方在我們中止後就送不進來」可被觀察。
        if (connection_aborted()) {
            exit;
        }
    }

    exit;
}

http_response_code(404);
