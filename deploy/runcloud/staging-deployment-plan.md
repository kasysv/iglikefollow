# M4C Staging Deployment Plan(RunCloud / Linux)

⛔ 本計畫是**部署包文件**:本輪未登入 RunCloud/VPS、未建立 Web App/DB/
SSL、未改 DNS/Cloudflare、未執行任何 script。所有實際執行需 Owner 依
`staging-input-checklist.md` 提供資料並另行進行;文件中未部署/未驗證
項目一律 `NOT VERIFIED`。

## 1. Deployment 模式(NEED OWNER INPUT)

| 模式 | 概要 | 前置條件 |
|---|---|---|
| 一般 Git deployment | RunCloud Web App 直接掛 Git repo,同一目錄 in-place 更新;`deploy-script.example.sh` 對應此模式 | RunCloud 端完成 repository access/deploy key;Web App root=`<app>/public` |
| Atomic deployment | RunCloud 以 release 目錄＋symlink 切換(clone→dependency→activate 生命週期由其 deployment script hooks 驅動);共享 `storage/`、`.env` 需掛在 shared path | RunCloud plan 支援 Atomic;hooks 需另立範本 |

**共同 gate(兩種模式都必須成立)**:public root 指向 `public/`、PHP ≥
8.2＋ext-curl、HTTPS 憑證有效、`.env` 依 `staging.env.example`(全部
能力 flag=false、`ALLOW_INDEXING=false`)、queue worker＋cron 已依範本
配置、backup 目錄存在且不在 web root。

⛔ 在 Owner 回覆「plan 是否支援 Atomic、選哪一種」之前,**模式未決**;
`deploy-script.example.sh` 內建 mode guard,非 git 模式直接停止,不猜
RunCloud release path。

## 2. 逐項基礎設定

1. **Web root**:RunCloud Web App 的 public path 必須指向
   `<release>/public`(⛔ 不是 repo root)。
2. **PHP**:≥ 8.2,ext-curl 必備;TheMostPanel dispatch 能力另需
   libcurl 版本不限(R1:short-write 中止不挑版本;僅需 ext-curl)。
3. **HTTPS**:Let's Encrypt 憑證生效後才設 `APP_URL=https://…`;
   readiness 把 http URL 判為 blocker。
4. **Database**:staging 專用 DB 與帳號(placeholder 見 env 範本);
   ⛔ 與任何 production 資料完全隔離。
5. **Queue**:`QUEUE_CONNECTION=database`;worker 依
   `queue-worker.conf.example`(`--tries=3 --timeout=60 --max-time=3600`,
   timeout 60 < retry_after 90;job 自身 `$tries` 優先——
   `SubmitFulfillmentOrder::$tries=1` 不受 worker 值影響)。
6. **Scheduler**:cron 依 `scheduler.cron.example` 每分鐘
   `schedule:run`;內含之 10 分鐘輪詢在 gate 未開時為 no-op。
7. **Storage/權限**:`storage/` 與 `bootstrap/cache/` 需可寫
   (preflight 只檢查,不 chmod)。
8. **Backup**:migrate 前必須有非空 DB dump(deploy script 的 backup
   gate);retention 依 Owner checklist 決定;dump 不放 web root。
9. **Log**:RunCloud log rotation 或系統 logrotate;`LOG_LEVEL=info`。
10. **Access protection**:staging 可加存取保護,但方式不得擋掉
    `POST /payments/ecpay/callback`(server-to-server);詳見 checklist。
11. **Indexing**:`ALLOW_INDEXING=false` 恆定;post-deploy check 驗證
    noindex header/meta 與 robots `Disallow: /`——⛔ 不得使用任何會讓
    Google 看見可索引內容的設定。

## 3. Release 順序(deploy-script.example.sh 的步驟即此順序)

```text
確認 commit/環境 → maintenance on → backup gate(非空 dump)
→ composer install --no-dev → migrate --force → config/route/view cache
→ queue:restart(worker 是 long-lived,必須 reload 才吃到新版)
→ app:staging-readiness(blocker 即停,維持 maintenance)
→ maintenance off → staging-post-deploy-check.sh(唯讀 HTTP 驗收)
```

### 首次部署 vs 後續 release

- **首次**:先完成 §2 全部基礎設定與 `.env`;首次 `migrate --force`
  會建立全部 schema;post-deploy check 前先人工確認 APP_KEY 已在
  RunCloud 端設定(preflight 只驗 presence)。
- **後續 release**:走完整 §3 順序;⛔ `git revert` 只回復程式碼,
  **不是**資料庫 rollback——schema/data 變更不會因 revert 消失。

## 4. 失敗處置與 rollback 分層

1. **Fail-stop**:script 任一步失敗即停,**保留 maintenance**,輸出
   人工處置提示;⛔ 絕不自稱已 rollback。
2. **Code rollback**:`git revert <commit>`(或 checkout 前一個已驗證
   commit)→ composer install → cache 重建 → queue:restart。
3. **Schema/data rollback**:⛔ 獨立決策——只有在逐支 migration 評估
   down() 語意、且 pre-deploy 備份完成驗證(可還原測試)後才可執行;
   ⛔ 不得以整庫備份直接覆蓋、不得把 code revert 當成資料回復。

## 5. Callback URL(精確 route;⛔ 現行 Laravel routes 逐字核對)

| 用途 | Method | Path |
|---|---|---|
| 綠界 server callback(付款證明來源)| POST | `/payments/ecpay/callback` |
| LINE Pay 瀏覽器返回(⛔ 不是付款證明)| GET | `/payments/linepay/{reference}/confirm` |
| LINE Pay 取消返回 | GET | `/payments/linepay/{reference}/cancel` |

sandbox 憑證與啟用另案批准;URL 先於 RunCloud 部署後以 staging domain
填入各後台。

## 6. NOT VERIFIED 清單

實際 VPS 環境、HTTPS 憑證、DB/queue/cron 運作、backup 還原、真實
readiness 輸出、post-deploy check 於 staging 網域的結果——全部
`NOT VERIFIED`,待部署輪執行。
