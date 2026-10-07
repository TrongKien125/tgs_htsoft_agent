<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Trang quản trị: danh sách job, lọc trạng thái, nút "Tạo job lại" cho failed/cancelled.
 */
class TGS_Agent_Admin
{
    public static function register_menu()
    {
        add_menu_page(
            'TGS HTsoft Agent',
            'HTsoft Agent',
            'manage_options',
            'tgs-htsoft-agent',
            array(__CLASS__, 'render'),
            'dashicons-update',
            58
        );
    }

    public static function render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        global $wpdb;
        $table = TGS_Agent_DB::jobs_table();

        $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $where = '';
        $params = array();
        if ($status !== '' && in_array($status, TGS_Agent_Jobs::STATUSES, true)) {
            $where = 'WHERE status = %s';
            $params[] = $status;
        }
        $sql = "SELECT * FROM $table $where ORDER BY created_at DESC LIMIT 200";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);

        $counts = $wpdb->get_results("SELECT status, COUNT(*) n FROM $table GROUP BY status", OBJECT_K);

        echo '<div class="wrap"><h1>TGS HTsoft Agent — Jobs</h1>';

        echo '<p>';
        $all_url = admin_url('admin.php?page=tgs-htsoft-agent');
        echo '<a href="' . esc_url($all_url) . '">Tất cả</a> ';
        foreach (TGS_Agent_Jobs::STATUSES as $s) {
            $n = isset($counts[$s]) ? (int) $counts[$s]->n : 0;
            $url = add_query_arg('status', $s, $all_url);
            echo ' | <a href="' . esc_url($url) . '">' . esc_html($s) . ' (' . $n . ')</a>';
        }
        echo '</p>';

        echo '<table class="widefat striped"><thead><tr>'
            . '<th>Thời gian</th><th>job_id</th><th>idempotency_key</th><th>CN</th>'
            . '<th>Action</th><th>Status</th><th>BHDCODE</th><th>Lỗi</th><th></th>'
            . '</tr></thead><tbody>';

        if (!$rows) {
            echo '<tr><td colspan="9">Chưa có job.</td></tr>';
        } else {
            foreach ($rows as $r) {
                echo '<tr>';
                echo '<td>' . esc_html($r->created_at) . '</td>';
                echo '<td><code>' . esc_html(substr($r->job_id, 0, 8)) . '…</code></td>';
                echo '<td>' . esc_html($r->idempotency_key) . '</td>';
                echo '<td>' . esc_html($r->branch_code) . '</td>';
                echo '<td>' . esc_html($r->action) . '</td>';
                echo '<td><strong>' . esc_html($r->status) . '</strong></td>';
                echo '<td>' . esc_html((string) $r->bhdcode) . '</td>';
                echo '<td>' . esc_html(trim(($r->error_code ? $r->error_code . ': ' : '') . (string) $r->error_message)) . '</td>';
                echo '<td>';
                if (in_array($r->status, array('failed', 'cancelled'), true)) {
                    $url = wp_nonce_url(
                        admin_url('admin-post.php?action=tgs_agent_requeue&job_id=' . rawurlencode($r->job_id)),
                        'tgs_agent_requeue_' . $r->job_id
                    );
                    echo '<a class="button button-small" href="' . esc_url($url) . '">Tạo job lại</a>';
                }
                echo '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div>';
    }

    public static function handle_requeue()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Không đủ quyền');
        }
        $job_id = isset($_GET['job_id']) ? sanitize_text_field(wp_unslash($_GET['job_id'])) : '';
        check_admin_referer('tgs_agent_requeue_' . $job_id);
        TGS_Agent_Jobs::requeue($job_id);
        wp_safe_redirect(admin_url('admin.php?page=tgs-htsoft-agent'));
        exit;
    }
}
