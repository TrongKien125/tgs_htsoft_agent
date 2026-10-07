<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Bảng job + nonce. Dùng dbDelta khi kích hoạt.
 */
class TGS_Agent_DB
{
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

    /** Ghi nonce một lần. Trả true nếu MỚI (chưa dùng). Tự dọn nonce hết hạn. */
    public static function use_nonce($nonce, $client_id, $ttl_seconds)
    {
        global $wpdb;
        $table = self::nonces_table();
        $now = current_time('mysql', true);
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE expires_at < %s", $now));
        $expires = gmdate('Y-m-d H:i:s', time() + (int) $ttl_seconds);
        // INSERT IGNORE: nếu trùng -> 0 dòng -> đã dùng.
        $affected = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (nonce, client_id, expires_at) VALUES (%s, %s, %s)",
            $nonce, $client_id, $expires
        ));
        return $affected === 1;
    }
}
