<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Bảng job + nonce. Dùng dbDelta khi kích hoạt.
 *
 * Multisite: register_activation_hook chỉ tạo bảng cho ĐÚNG blog đang active lúc
 * kích hoạt — network-activate KHÔNG lặp qua từng site, nên các subsite khác thiếu
 * bảng. maybe_install() vá chỗ này: tự tạo bảng cho blog đang phục vụ request lần
 * đầu chạm tới (rẻ: kiểm một lần/blog/request). Nếu không có bước này, INSERT nonce
 * trên subsite thiếu bảng sẽ lỗi và bị báo nhầm là "replay".
 */
class TGS_Agent_DB
{
    /** Blog đã bảo đảm có bảng trong request này (tránh SHOW TABLES lặp lại). */
    private static $ready = array();

    public static function jobs_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'tgs_htsoft_jobs';
    }

    public static function nonces_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'tgs_htsoft_nonces';
    }

    public static function install()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $jobs = self::jobs_table();
        $nonces = self::nonces_table();

        $sql_jobs = "CREATE TABLE $jobs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id CHAR(36) NOT NULL,
            idempotency_key VARCHAR(191) NOT NULL,
            branch_code VARCHAR(50) NOT NULL DEFAULT '',
            action VARCHAR(50) NOT NULL DEFAULT 'create_retail_invoice',
            payload_json LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            bhdid CHAR(36) NULL,
            bhdcode VARCHAR(50) NULL,
            result_json LONGTEXT NULL,
            error_code VARCHAR(50) NULL,
            error_message TEXT NULL,
            requested_by VARCHAR(100) NULL,
            attempt_count INT NOT NULL DEFAULT 0,
            claimed_at DATETIME NULL,
            heartbeat_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY job_id (job_id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY status_branch (status, branch_code, created_at),
            KEY bhdcode (bhdcode)
        ) $charset;";

        $sql_nonces = "CREATE TABLE $nonces (
            nonce VARCHAR(191) NOT NULL,
            client_id VARCHAR(100) NOT NULL,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY  (nonce),
            KEY expires_at (expires_at)
        ) $charset;";

        dbDelta($sql_jobs);
        dbDelta($sql_nonces);
    }

    /** Bảo đảm bảng của blog hiện tại đã có; tạo nếu thiếu. Kiểm một lần/blog/request. */
    public static function maybe_install()
    {
        global $wpdb;
        $blog = is_multisite() ? (int) get_current_blog_id() : 0;
        if (!empty(self::$ready[$blog])) {
            return;
        }
        $nonces = self::nonces_table();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $nonces));
        if ($found !== $nonces) {
            self::install(); // dbDelta tạo cả hai bảng cho đúng blog đang chạy; an toàn khi gọi lại.
        }
        self::$ready[$blog] = true;
    }

    /**
     * Ghi nonce một lần. Tự dọn nonce hết hạn.
     * Trả: true = MỚI (chưa dùng) · false = TRÙNG (replay thật) · WP_Error = lỗi lưu phía DB.
     * Phân biệt rõ để tầng xác thực không báo nhầm lỗi DB thành "replay".
     */
    public static function use_nonce($nonce, $client_id, $ttl_seconds)
    {
        global $wpdb;
        self::maybe_install();
        $table = self::nonces_table();
        $now = current_time('mysql', true);
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE expires_at < %s", $now));
        $expires = gmdate('Y-m-d H:i:s', time() + (int) $ttl_seconds);
        // INSERT IGNORE: 1 dòng = mới · 0 dòng = trùng (đã dùng) · false = lỗi câu lệnh.
        $affected = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (nonce, client_id, expires_at) VALUES (%s, %s, %s)",
            $nonce, $client_id, $expires
        ));
        if ($affected === false) {
            return new WP_Error('NONCE_STORE_FAILED', 'Không ghi được nonce: ' . $wpdb->last_error);
        }
        return $affected === 1;
    }
}
