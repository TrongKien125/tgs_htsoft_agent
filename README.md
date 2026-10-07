# TGS HTsoft Agent (Job Broker)

Hàng đợi job phía WordPress cho **TGS HTsoft AddIn**. POS/WooCommerce tạo job → AddIn (trong HTsoft)
`claim` qua REST (ký HMAC) → tạo hoá đơn bằng hàm HTsoft → báo kết quả về.
Kế hoạch tổng: `Documents/Windows/TGS_ADDIN_KE_HOACH_WORDPRESS.md`.

## Cài
1. Copy `config.sample.php` → `config.php`, điền `client_id` + `secret` (secret trùng với `ApiSecret`
   trong `config.json` của AddIn). `config.php` KHÔNG commit.
   - Hoặc định nghĩa `TGS_AGENT_CLIENTS` (array) trong `wp-config.php`.
2. Kích hoạt plugin → tự tạo bảng `wp_tgs_htsoft_jobs`, `wp_tgs_htsoft_nonces`.
3. **Bật pretty permalinks** (Settings → Permalinks) — cần cho REST + để chữ ký HMAC khớp path.

## REST (namespace `tgs-htsoft-agent/v1`)
Mọi endpoint xác thực HMAC: header `X-TGS-Client-ID`, `X-TGS-Timestamp`, `X-TGS-Nonce`,
`X-TGS-Signature = hex(HMAC-SHA256(secret, METHOD\nPATH\nTS\nNONCE\nhex(SHA256(body))))`.
`PATH` = phần path của URL (vd `/wp-json/tgs-htsoft-agent/v1/ping`), không gồm query.

| Method | Path | Ai gọi | Việc |
|---|---|---|---|
| POST | `/ping` | AddIn | kiểm tra kết nối + chữ ký |
| POST | `/jobs/claim` body `{branch_code}` | AddIn | lấy 1 job `queued` của chi nhánh → `claimed`; không có → `{}` |
| POST | `/jobs/{id}/result` | AddIn | báo `completed/cancelled/failed/unknown` + `bhdcode` |
| POST | `/jobs/{id}/heartbeat` | AddIn | gia hạn lease khi đang mở form |
| POST | `/jobs` | POS server-to-server | tạo job (idempotent theo `idempotency_key`) |

Tạo job từ PHP nội bộ (vd plugin POS khác):
```php
TGS_Agent_Jobs::create([
  'idempotency_key' => $blog_id.':create_retail_invoice:'.$pos_ref,
  'action' => 'create_retail_invoice',
  'branch_code' => 'CNTEST',
  'payload' => [ /* theo §3 kế hoạch */ ],
]);
```

## Vòng đời job
`queued → claimed → completed | cancelled | failed | unknown`.
Job `claimed` quá `TGS_AGENT_LEASE_SECONDS` (900s) không heartbeat → `unknown` (KHÔNG tự cấp lại;
xử lý ở trang quản trị). Chỉ `failed`/`cancelled` mới có nút **"Tạo job lại"**.

## Trang quản trị
Menu **HTsoft Agent**: danh sách job, lọc theo trạng thái, nút tạo lại cho failed/cancelled.

## WooCommerce (tùy chọn, mặc định TẮT)
Bật: `update_option('tgs_agent_woo_enabled', 1)`. Cấu hình qua option:
`tgs_agent_woo_status` (mặc định `processing`), `tgs_agent_woo_branch`, `tgs_agent_woo_mh_meta`
(meta key chứa MHCODE HTsoft trên product, mặc định `_htsoft_mhcode`).
Sản phẩm thiếu MHCODE bị bỏ qua — cần hoàn thiện mapping theo site (xem §6 Q3 kế hoạch).

## Hằng số có thể override (wp-config)
`TGS_AGENT_SKEW_SECONDS` (300), `TGS_AGENT_NONCE_TTL` (600), `TGS_AGENT_LEASE_SECONDS` (900),
`TGS_AGENT_CLIENTS` (array client).

## Bảo mật
- Secret để trong `config.php` (gitignored) hoặc `wp-config`. Không log secret.
- Chống replay: nonce dùng 1 lần trong TTL; timestamp lệch ≤ 300s.
- Mỗi client giới hạn chi nhánh qua `branches` (vd `['CNTEST']` hoặc `['*']`).
