# M4C Staging Runbook(RunCloud / Linux)

⛔ 本 runbook 不含任何 key、token、MerchantID 或個資;credential 一律由
Owner 於部署後在 `/admin` 串接設定加密輸入。實際部署執行需依
`staging-input-checklist.md` 取得 Owner 輸入後另行進行;所有未部署/
未驗證項目一律 **NOT VERIFIED**。

部署包全貌:`staging-deployment-plan.md`(計畫與順序)、
`staging.env.example`(env 範本)、`queue-worker.conf.example`、
`scheduler.cron.example`、`deploy-script.example.sh`(未執行範本)、
`staging-preflight.sh`(部署前唯讀)、`staging-post-deploy-check.sh`
(部署後唯讀)、`staging-input-checklist.md`(Owner 一次性輸入)。

## 1. 前置

- **Git / Atomic 模式未決(NEED OWNER INPUT)**:deploy script 現只支援
  一般 Git deployment(內建 mode guard);Atomic 需依 RunCloud hooks
  另立範本。
- RunCloud Web App public path 必須指向 `<release>/public`。
- PHP ≥ 8.2＋ext-curl;TheMostPanel dispatch 另需 libcurl ≥ 8.4
  (不足時該能力自動 fail closed,非部署 blocker)。
- HTTPS 憑證生效後才設 `APP_URL=https://…`。

## 2. .env(依 `staging.env.example`;只列非機密鍵)

全部能力 flag=false、`FULFILLMENT_DRIVER=disabled`、
`ALLOW_INDEXING=false`、`QUEUE_CONNECTION=database`、
`DB_QUEUE_RETRY_AFTER=90`。⛔ secret 只在 RunCloud 端設定。

## 3. Queue worker(long-lived;依 `queue-worker.conf.example`)

```
php artisan queue:work database --sleep=3 --tries=3 --timeout=60 --max-time=3600
```

- ⛔ `--tries=3` **不會**覆蓋 job 自身 `$tries`:
  `SubmitFulfillmentOrder::$tries=1` 由 job 封頂(花錢的 add 永不因
  worker 設定重送);`IssueInvoiceForOrder`/`PrepareFulfillmentForPaidOrder`
  /`SyncFulfillmentStatus` 為 `$tries=3`,與 worker 值一致。
- `--timeout=60` < database `retry_after=90`:避免同一 job 被兩個
  worker 同時處理。
- RunCloud Process Manager/Supervisor `autorestart=true`;
  **每次部署後必須 `php artisan queue:restart`**(deploy script 已含),
  否則舊 worker 繼續跑舊程式碼。

## 4. Scheduler

cron 每分鐘 `schedule:run`(依 `scheduler.cron.example`,placeholder
path)。內含每 10 分鐘 `fulfillment:queue-status-sync`;gate(staging＋
polling flag＋dispatch capability)未開時為 no-op。

## 5. 部署順序(詳見 plan §3;由 deploy script 落實)

確認 commit → `artisan down` → **backup gate(非空 DB dump,migrate
前必須)** → `composer install --no-dev` → `migrate --force` →
config/route/view cache → **`queue:restart`** →
`app:staging-readiness`(blocker 即停,維持 maintenance)→
`artisan up` → `staging-post-deploy-check.sh <https-base-url>`。

## 6. 驗收(唯讀)

```
bash deploy/runcloud/staging-preflight.sh <app-dir>      # 部署前
php artisan app:staging-readiness [--json]               # 同一報告
bash deploy/runcloud/staging-post-deploy-check.sh <url>  # 部署後(GET/HEAD only)
```

blocker=0 才算 staging-ready;「能力未開啟(blocked)」是預期狀態。
staging 必須維持 noindex(header/meta/robots `Disallow: /`),⛔ 不得
使用任何會讓 Google 看見可索引內容的設定。

## 7. Callback URL checklist(sandbox;另案啟用)

| 用途 | Method | Path(staging domain 前綴)|
|---|---|---|
| 綠界 server callback(唯一付款證明來源)| POST | `/payments/ecpay/callback` |
| LINE Pay 瀏覽器返回(⛔ 不是付款證明)| GET | `/payments/linepay/{reference}/confirm` |
| LINE Pay 取消返回 | GET | `/payments/linepay/{reference}/cancel` |

若 staging 有存取保護,必須放行綠界 server-to-server callback(path
例外或 IP allowlist)。

## 8. Maintenance 與 rollback(分層;詳見 plan §4)

- 維護:`php artisan down` / `php artisan up`。
- **Code rollback**:`git revert <commit>` → `composer install --no-dev`
  → cache 重建 → `queue:restart`。⛔ `git revert` 不是資料庫 rollback。
- **Schema/data rollback**:獨立決策——逐支 migration 評估＋備份驗證
  後才可執行;⛔ 不得以整庫備份直接覆蓋 live DB。
- failed jobs 只可檢視(`php artisan queue:failed`);清除需另案決定。
