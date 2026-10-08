<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Trang quản trị:
 *  - "Jobs": danh sách job, lọc, tạo lại.
 *  - "Quản lý đồng bộ": trạng thái agent (online/last-seen), thống kê job, cấu hình WooCommerce.
 */
class TGS_Agent_Admin
{
    public static function register_menu()
    {
        add_menu_page(
            'TGS HTsoft Agent', 'HTsoft Agent', 'manage_options',
            'tgs-htsoft-agent', array(__CLASS__, 'render'), 'dashicons-update', 58
        );
        add_submenu_page('tgs-htsoft-agent', 'Jobs', 'Jobs', 'manage_options',
            'tgs-htsoft-agent', array(__CLASS__, 'render'));
        add_submenu_page('tgs-htsoft-agent', 'Danh sách đơn', 'Danh sách đơn', 'manage_options',
            'tgs-htsoft-agent-orders', array(__CLASS__, 'render_orders'));
        add_submenu_page('tgs-htsoft-agent', 'Quản lý đồng bộ', 'Quản lý đồng bộ', 'manage_options',
            'tgs-htsoft-agent-sync', array(__CLASS__, 'render_sync'));
    }

    // ----- Danh sách đơn (tgs_pos) + nút thêm vào queue thủ công -----
    public static function render_orders()
    {
        if (!current_user_can('manage_options')) { return; }

        if (isset($_GET['tgs_q'])) {
            $q = sanitize_text_field(wp_unslash($_GET['tgs_q']));
            $ok = strpos($q, 'ok:') === 0;
            echo '<div class="notice ' . ($ok ? 'notice-success' : 'notice-error')
                . ' is-dismissible"><p>' . esc_html($q) . '</p></div>';
        }

        $today = current_time('Y-m-d');
        $from = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : $today;
        $to   = isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : $today;
        // Chuẩn hoá: nếu sai định dạng thì về hôm nay.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = $today; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $to = $today; }

        $orders = TGS_Agent_Source::recent_sale_orders(1000, $from, $to);
        $auto = get_option('tgs_agent_pos_auto') == 1;
        $site_srid = TGS_Agent_Source::resolve_srid();

        echo '<div class="wrap"><h1>Danh sách đơn (tgs_pos) → queue</h1>';

        // Bộ lọc ngày (mặc định hôm nay → hôm nay)
        echo '<form method="get" style="margin:8px 0;">';
        echo '<input type="hidden" name="page" value="tgs-htsoft-agent-orders">';
        echo 'Từ ngày <input type="date" name="from" value="' . esc_attr($from) . '"> ';
        echo 'đến <input type="date" name="to" value="' . esc_attr($to) . '"> ';
        echo '<button class="button">Lọc</button> ';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=tgs-htsoft-agent-orders&from=' . $today . '&to=' . $today)) . '">Hôm nay</a>';
        echo '</form>';
        echo '<p>Tự động tạo job khi có đơn mới: <strong>' . ($auto ? 'BẬT' : 'TẮT')
            . '</strong> (đổi ở <a href="' . esc_url(admin_url('admin.php?page=tgs-htsoft-agent-sync')) . '">Quản lý đồng bộ</a>).</p>';
        if (!$site_srid) {
            echo '<div class="notice notice-error inline"><p><strong>Site CHƯA nối chi nhánh HTsoft (SRID)</strong> '
                . '→ không tạo được queue. Cấu hình ở plugin <code>tgs-multisite-hierarchy</code>.</p></div>';
        } else {
            echo '<p>Chi nhánh site: <code>' . esc_html($site_srid) . '</code>. '
                . 'Đơn chỉ vào queue được khi <strong>người bán đã nối nhân viên HTsoft (NVID)</strong>.</p>';
        }

        echo '<table class="widefat striped"><thead><tr>'
            . '<th>Mã phiếu</th><th>Ngày</th><th>Khách</th><th>Tổng tiền</th>'
            . '<th>TT phiếu</th><th>NV (HTsoft)</th><th>Job</th><th></th></tr></thead><tbody>';

        if (!$orders) {
            echo '<tr><td colspan="8">Chưa có phiếu bán (local_ledger type 10).</td></tr>';
        } else {
            foreach ($orders as $o) {
                $code = (string) ($o['local_ledger_code'] ?? '');
                $job = TGS_Agent_Source::job_status_for_code($code);
                $cust = (string) ($o['local_ledger_person_name'] ?? '');
                $phone = (string) ($o['local_ledger_person_phone'] ?? '');
                $nv = TGS_Agent_Source::resolve_nv($o['user_id'] ?? 0);
                $can_queue = $job === null && $site_srid && !empty($nv['nvid']);

                echo '<tr>';
                echo '<td><strong>' . esc_html($code) . '</strong></td>';
                echo '<td>' . esc_html((string) ($o['created_at'] ?? '')) . '</td>';
                echo '<td>' . esc_html(trim($cust . ' ' . $phone)) . '</td>';
                echo '<td>' . esc_html(number_format((float) ($o['local_ledger_total_amount'] ?? 0))) . '</td>';
                echo '<td>' . esc_html((string) ($o['local_ledger_status'] ?? '')) . '</td>';
                $uid = (int) ($o['user_id'] ?? 0);
                $uobj = $uid ? get_userdata($uid) : null;
                $uname = $uobj ? $uobj->user_login : ('#' . $uid);
                echo '<td>' . (!empty($nv['nvid'])
                    ? esc_html((string) ($nv['nv_code'] ?: $nv['nvid']))
                    : '<span style="color:#b32d2e;">chưa nối NV</span><br><small>user: ' . esc_html($uname) . '</small>') . '</td>';
                echo '<td>' . ($job ? '<span style="color:#1a7f37;">' . esc_html($job) . '</span>' : '—') . '</td>';
                echo '<td>';
                if ($job !== null) {
                    echo '<span class="description">đã có job</span>';
                } elseif ($can_queue) {
                    $url = wp_nonce_url(
                        admin_url('admin-post.php?action=tgs_agent_queue_order&ledger_id=' . (int) ($o['local_ledger_id'] ?? 0)
                            . '&from=' . rawurlencode($from) . '&to=' . rawurlencode($to)),
                        'tgs_agent_queue_order_' . (int) ($o['local_ledger_id'] ?? 0)
                    );
                    echo '<a class="button button-primary button-small" href="' . esc_url($url) . '">Thêm vào queue</a>';
                } else {
                    echo '<span class="description" style="color:#b32d2e;">thiếu chi nhánh/NV</span>';
                }
                echo '</td></tr>';
            }
        }
        echo '</tbody></table></div>';
    }

    public static function handle_queue_order()
    {
        if (!current_user_can('manage_options')) { wp_die('Không đủ quyền'); }
        $ledger_id = isset($_GET['ledger_id']) ? (int) $_GET['ledger_id'] : 0;
        check_admin_referer('tgs_agent_queue_order_' . $ledger_id);
        $res = TGS_Agent_Source::queue_by_ledger_id($ledger_id);
        $msg = is_wp_error($res) ? ('err:' . $res->get_error_message())
            : ('ok:' . (isset($res['created']) && $res['created'] ? 'created' : 'existed'));
        $args = array('page' => 'tgs-htsoft-agent-orders', 'tgs_q' => $msg);
        if (isset($_GET['from'])) { $args['from'] = sanitize_text_field(wp_unslash($_GET['from'])); }
        if (isset($_GET['to'])) { $args['to'] = sanitize_text_field(wp_unslash($_GET['to'])); }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    // ----- Jobs list -----
    public static function render()
    {
        if (!current_user_can('manage_options')) { return; }
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
        echo '<a href="' . esc_url($all_url) . '">Tất cả</a>';
        foreach (TGS_Agent_Jobs::STATUSES as $s) {
            $n = isset($counts[$s]) ? (int) $counts[$s]->n : 0;
            echo ' | <a href="' . esc_url(add_query_arg('status', $s, $all_url)) . '">' . esc_html($s) . ' (' . $n . ')</a>';
        }
        echo '</p>';

        echo '<table class="widefat striped"><thead><tr>'
            . '<th>Thời gian</th><th>job_id</th><th>idempotency_key</th><th>CN</th>'
            . '<th>Action</th><th>Status</th><th>BHDCODE</th><th>Lỗi</th><th></th></tr></thead><tbody>';
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
                echo '</td></tr>';
            }
        }
        echo '</tbody></table></div>';
    }

    // ----- Quản lý đồng bộ -----
    public static function render_sync()
    {
        if (!current_user_can('manage_options')) { return; }

        // Lưu cấu hình Woo.
        if (isset($_POST['tgs_agent_settings_nonce'])
            && check_admin_referer('tgs_agent_settings', 'tgs_agent_settings_nonce')) {
            // Bật auto tạo job: ĐẶT LẠI mốc cắt = NGAY BÂY GIỜ (0->1). Từ mốc này về
            // sau mới tự tạo job; đơn cũ trước mốc xử lý bằng nút "Tạo job" tay.
            $pos_auto_prev = (int) get_option('tgs_agent_pos_auto');
            $pos_auto_now  = isset($_POST['pos_auto']) ? 1 : 0;
            update_option('tgs_agent_pos_auto', $pos_auto_now);
            if ($pos_auto_now === 1 && $pos_auto_prev !== 1) {
                update_option('tgs_agent_pos_auto_since', current_time('mysql'));
            }
            update_option('tgs_agent_return_auto', isset($_POST['return_auto']) ? 1 : 0);
            update_option('tgs_agent_woo_enabled', isset($_POST['woo_enabled']) ? 1 : 0);
            update_option('tgs_agent_woo_status', sanitize_text_field(wp_unslash($_POST['woo_status'] ?? 'processing')));
            update_option('tgs_agent_woo_branch', sanitize_text_field(wp_unslash($_POST['woo_branch'] ?? '')));
            update_option('tgs_agent_woo_mh_meta', sanitize_text_field(wp_unslash($_POST['woo_mh_meta'] ?? '_htsoft_mhcode')));
            update_option('tgs_agent_woo_nv_code', sanitize_text_field(wp_unslash($_POST['woo_nv_code'] ?? '')));
            echo '<div class="notice notice-success is-dismissible"><p>Đã lưu cấu hình.</p></div>';
        }

        global $wpdb;
        $table = TGS_Agent_DB::jobs_table();
        $counts = $wpdb->get_results("SELECT status, COUNT(*) n FROM $table GROUP BY status", OBJECT_K);
        $agents = get_option('tgs_agent_agents', array());
        if (!is_array($agents)) { $agents = array(); }

        echo '<div class="wrap"><h1>Quản lý đồng bộ — HTsoft Agent</h1>';

        // Link REST cho AddIn
        $base = untrailingslashit(rest_url(TGS_AGENT_NS)); // .../wp-json/tgs-htsoft-agent/v1
        $pretty = get_option('permalink_structure') !== '';
        echo '<h2>Link gọi (REST) — dán vào AddIn</h2>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>WordpressUrl (config.json)</th><td>'
            . '<input type="text" readonly class="large-text code" onclick="this.select()" value="'
            . esc_attr($base) . '"></td></tr>';
        $eps = array(
            'POST /ping'                  => $base . '/ping',
            'POST /jobs/claim'            => $base . '/jobs/claim',
            'POST /jobs'                  => $base . '/jobs',
            'POST /jobs/{id}/result'      => $base . '/jobs/{id}/result',
            'POST /jobs/{id}/heartbeat'   => $base . '/jobs/{id}/heartbeat',
        );
        echo '<tr><th>Endpoint</th><td><ul style="margin:0;">';
        foreach ($eps as $label => $url) {
            echo '<li><code>' . esc_html($label) . '</code> → <code>' . esc_html($url) . '</code></li>';
        }
        echo '</ul></td></tr>';
        echo '</tbody></table>';
        if (!$pretty) {
            echo '<div class="notice notice-warning inline"><p><strong>Cảnh báo:</strong> Pretty permalinks đang TẮT. '
                . 'HMAC ký theo path nên cần bật (Settings → Permalinks, chọn khác "Plain") để chữ ký khớp '
                . '<code>/wp-json/...</code>.</p></div>';
        }

        // Trạng thái agent
        echo '<h2>Agent (máy cửa hàng)</h2>';
        echo '<table class="widefat striped"><thead><tr>'
            . '<th>Client</th><th>Chi nhánh</th><th>Trạng thái</th><th>Hoạt động gần nhất</th>'
            . '<th>Sự kiện</th><th>IP</th></tr></thead><tbody>';
        if (!$agents) {
            echo '<tr><td colspan="6">Chưa có agent nào gọi tới (chưa ping/claim).</td></tr>';
        } else {
            foreach ($agents as $cid => $a) {
                $last = isset($a['last_seen']) ? $a['last_seen'] : '';
                $ago = $last ? human_time_diff(strtotime($last . ' UTC'), time()) : '';
                $online = $last && (time() - strtotime($last . ' UTC')) <= 120;
                echo '<tr>';
                echo '<td><strong>' . esc_html($cid) . '</strong></td>';
                echo '<td>' . esc_html(isset($a['branch']) ? $a['branch'] : '') . '</td>';
                echo '<td>' . ($online
                    ? '<span style="color:#1a7f37;font-weight:600;">● Online</span>'
                    : '<span style="color:#999;">○ Offline</span>') . '</td>';
                echo '<td>' . esc_html($ago ? ($ago . ' trước') : '—') . '</td>';
                echo '<td>' . esc_html(isset($a['last_event']) ? $a['last_event'] : '') . '</td>';
                echo '<td>' . esc_html(isset($a['ip']) ? $a['ip'] : '') . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';

        // Thống kê job
        echo '<h2 style="margin-top:24px;">Thống kê job</h2><p>';
        foreach (TGS_Agent_Jobs::STATUSES as $s) {
            $n = isset($counts[$s]) ? (int) $counts[$s]->n : 0;
            echo '<span style="display:inline-block;min-width:140px;">' . esc_html($s) . ': <strong>' . $n . '</strong></span> ';
        }
        echo '</p>';

        // Cấu hình WooCommerce
        $woo_enabled = get_option('tgs_agent_woo_enabled') == 1;
        $woo_status  = get_option('tgs_agent_woo_status', 'processing');
        $woo_branch  = get_option('tgs_agent_woo_branch', '');
        $woo_mh_meta = get_option('tgs_agent_woo_mh_meta', '_htsoft_mhcode');
        $woo_nv_code = get_option('tgs_agent_woo_nv_code', '');

        $pos_auto = get_option('tgs_agent_pos_auto') == 1;
        $return_auto = get_option('tgs_agent_return_auto') == 1;

        echo '<h2 style="margin-top:24px;">Cấu hình tạo job</h2>';
        echo '<form method="post">';
        wp_nonce_field('tgs_agent_settings', 'tgs_agent_settings_nonce');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Auto tạo job khi tgs_pos tạo phiếu bán</th><td><label><input type="checkbox" name="pos_auto" value="1" '
            . checked($pos_auto, true, false) . '> Bật (hook <code>tgs_after_order_create</code>)</label>'
            . ' <span class="description">Tắt → chỉ tạo thủ công ở "Danh sách đơn".</span></td></tr>';
        echo '<tr><th>Auto tạo job khi tgs_pos hoàn hàng (hoàn thuần)</th><td><label><input type="checkbox" name="return_auto" value="1" '
            . checked($return_auto, true, false) . '> Bật (hook <code>tgs_pos_return_committed</code>, action <code>create_return</code>)</label>'
            . ' <span class="description">Chỉ hoàn THUẦN đi qua queue; hoàn kèm đổi trả vẫn đẩy trực tiếp. '
            . 'Bật cái này sẽ TẮT đường đẩy trực tiếp cho hoàn thuần (không đẩy trùng) — chỉ bật khi AddIn đã xử lý được <code>create_return</code>.</span></td></tr>';
        echo '<tr><th>Bật tạo job từ Woo</th><td><label><input type="checkbox" name="woo_enabled" value="1" '
            . checked($woo_enabled, true, false) . '> Bật</label></td></tr>';
        echo '<tr><th>Trạng thái đơn kích hoạt</th><td><input type="text" name="woo_status" value="'
            . esc_attr($woo_status) . '" class="regular-text"> <span class="description">vd processing, completed</span></td></tr>';
        echo '<tr><th>Chi nhánh (branch_code)</th><td><input type="text" name="woo_branch" value="'
            . esc_attr($woo_branch) . '" class="regular-text"></td></tr>';
        echo '<tr><th>Meta key MHCODE trên product</th><td><input type="text" name="woo_mh_meta" value="'
            . esc_attr($woo_mh_meta) . '" class="regular-text"></td></tr>';
        echo '<tr><th>Mã nhân viên mặc định (nv_code)</th><td><input type="text" name="woo_nv_code" value="'
            . esc_attr($woo_nv_code) . '" class="regular-text"> <span class="description">mã NV HTsoft, vd CNTESAA00001</span></td></tr>';
        echo '</tbody></table>';
        submit_button('Lưu cấu hình');
        echo '</form>';

        echo '</div>';
    }

    public static function handle_requeue()
    {
        if (!current_user_can('manage_options')) { wp_die('Không đủ quyền'); }
        $job_id = isset($_GET['job_id']) ? sanitize_text_field(wp_unslash($_GET['job_id'])) : '';
        check_admin_referer('tgs_agent_requeue_' . $job_id);
        TGS_Agent_Jobs::requeue($job_id);
        wp_safe_redirect(admin_url('admin.php?page=tgs-htsoft-agent'));
        exit;
    }
}
