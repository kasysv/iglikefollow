# M4C Staging Runbook(RunCloud / Linux)

⛔ 本 runbook 不含任何 key、token、MerchantID 或個資;credential 一律由
Owner 於部署後在 `/admin` 串接設定加密輸入。真正部署執行與 sandbox E2E
需另案批准;本檔只描述程序與唯讀檢查。

## 1. 前置

- RunCloud web application:PHP 8.2+(TheMostPanel dispatch 能力需
  libcurl ≥ 8.4;不足時該能力自動 fail closed,不是部署 blocker)。
- document root 必須是 `<app>/public`。
- HTTPS 憑證(Let's Encrypt)生效後才設 `APP_URL=https://…`。

## 2. .env(只列非機密鍵)

```
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://<staging-domain>
ALLOW_INDEXING=false
QUEUE_CONNECTION=database
PAYMENTS_SANDBOX_ENABLED=false
INVOICE_SANDBOX_ENABLED=false
FULFILLMENT_DRIVER=disabled
FULFILLMENT_DISPATCH_ENABLED=false
FULFILLMENT_STAGING_THEMOSTPANEL_DISPATCH_ENABLED=false
FULFILLMENT_STATUS_POLLING_ENABLED=false
```

⛔ 預設全關。打開任何一個能力(付款/發票/派單/輪詢)都需要另一次明確批准。

## 3. 部署步驟(另案批准後執行)

1. `git clone` / `git pull` 指定 commit;`composer install --no-dev`。
2. `php artisan migrate --force`(第一次部署)。
3. `php artisan config:cache && php artisan route:cache`。
4. Queue worker(supervisor/systemd):
   `php artisan queue:work --tries=1 --max-time=3600`
   ⛔ `--tries=1`:花錢的 job 永不自動重試。
5. Cron:`* * * * * cd <app> && php artisan schedule:run >> /dev/null 2>&1`
   (scheduler 內含每 10 分鐘的履約狀態輪詢,gate 未開時為 no-op)。

## 4. 驗收(唯讀)

```
bash deploy/runcloud/staging-preflight.sh <app-dir>
php artisan app:staging-readiness           # 人讀
php artisan app:staging-readiness --json    # 機器讀
```

blocker=0 才算 staging-ready;「能力未開啟(blocked)」是預期狀態。

## 5. Callback URL checklist(sandbox,另案啟用)

- 綠界付款 ReturnURL/PaymentInfoURL → `https://<staging-domain>/…`(依既有 route)
- LINE Pay confirm/cancel URL → 同上
- ⛔ 瀏覽器返回頁不是付款證明;可信來源規則不因 staging 改變。

## 6. Maintenance 與 rollback

- 進維護:`php artisan down`;恢復:`php artisan up`。
- code rollback:`git revert <commit>` → `composer install --no-dev` →
  `php artisan config:clear && php artisan config:cache`。
- ⛔ 不得用整庫備份直接覆蓋 DB;資料異動一律另案評估。
- failed jobs 只可檢視(`php artisan queue:failed`);清除需另案決定。
