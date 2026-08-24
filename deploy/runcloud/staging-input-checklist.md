# M4C Staging:Owner 一次性輸入清單

⛔ **不要把密碼、private key、API key 或 Merchant credential 貼到
Project、Control 或聊天**。secret 未來只在 RunCloud env UI、server 或
Owner-only `/admin` 串接設定安全輸入。本清單只需要**非機密**答案。

## 一次回覆即可全部開工的項目

1. **Staging subdomain**:預計使用的完整網域(例
   `staging.example.tld`);DNS 是否已可指向 VPS。
2. **RunCloud server / Web App**:是否已建立?PHP version?
   `php -r 'echo curl_version()["version"];'` 的 libcurl 版本
   (R1:dispatch 傳輸中止不挑 libcurl 版本,僅需 ext-curl)。
3. **Deployment 模式**:RunCloud plan 是否支援 Atomic deployment?
   選 **Git** 或 **Atomic**?(deploy script 現只支援 Git;Atomic 需
   另立 hooks 範本。)
4. **Repository access**:RunCloud 端 deploy key / repo 連接是否完成?
   部署分支或固定 commit 策略?
5. **Database**:MySQL/MariaDB 版本;staging DB 名稱與帳號規劃
   (值不用貼,確認「已建立」即可)。
6. **Cache / Queue**:維持 `database` driver(範本預設)或改 Redis?
7. **Backup policy**:DB dump 頻率、retention、存放位置(非 web
   root)、是否做過 restore test;log retention 天數。
8. **Access protection**:staging 是否加存取保護(HTTP basic /
   IP allowlist / RunCloud 功能)?⛔ 所選方式必須放行
   `POST /payments/ecpay/callback`(server-to-server,無法帶
   basic-auth)——建議 path 例外或 IP allowlist 併用。

## Owner 不需要提供的(另案處理)

- 任何 API key / MerchantID / HashKey / HashIV / Channel Secret
  (→ 部署後於 `/admin` 加密輸入;啟用另需明確批准)。
- 付款/發票/派單/輪詢 flags 的開啟決定(→ 逐項另案批准)。
- production 網域、DNS 切換、301(→ 完全不在 staging 範圍)。
