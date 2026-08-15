# IGLIKEFOLLOW

IGLIKEFOLLOW 新站本機骨架：Laravel 12、Blade、Tailwind CSS、Alpine.js。

## Current scope

- 伺服器輸出的首頁與 SEO 正文。
- fail-closed noindex／robots 與 `X-Robots-Tag`。
- `/api/health` 健康檢查。
- mock catalog 與不連線的快速結帳流程。
- 不包含正式商品、正式資料庫、金流、履約 API、DASH 或 URL migration。

## Local checks

```powershell
composer install
npm install
npm run build
php artisan test
php artisan serve
```

## Indexing safety

Indexing stays disabled unless all conditions are true:

```dotenv
APP_ENV=production
ALLOW_INDEXING=true
INDEXABLE_HOST=www.iglikefollow.com
```

The runtime request host must also exactly match `INDEXABLE_HOST`. Do not use this combination in staging, local, or any unapproved release environment.
