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

    /** Badge nhỏ gọn cho bảng (text, nền, chữ, tooltip). */
    private static function pill($text, $bg, $fg, $title = '')
    {
        return '<span title="' . esc_attr($title) . '" style="display:inline-block;padding:0 6px;'
            . 'border-radius:3px;background:' . $bg . ';color:' . $fg . ';font-size:11px;'
            . 'line-height:18px;white-space:nowrap;">' . $text . '</span>';
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
        $pf = TGS_Agent_Source::prefetch_list($orders); // gom dữ liệu theo lô (tránh query mỗi dòng)
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

        echo '<style>.tgs-orders td,.tgs-orders th{padding:4px 8px;font-size:12px;vertical-align:top;}'
            . '.tgs-orders small{color:#888;}</style>';
        echo '<table class="widefat striped tgs-orders"><thead><tr>'
            . '<th style="width:40px;">STT</th>'
            . '<th>Mã / Ngày</th><th>Khách</th><th>Loại · KM/CK</th><th style="text-align:right;">Tổng tiền</th>'
            . '<th>eVAT</th><th>TT</th><th>NV</th><th>Job</th><th>Thao tác</th></tr></thead><tbody>';

        if (!$orders) {
            echo '<tr><td colspan="10">Chưa có phiếu bán (local_ledger type 10).</td></tr>';
        } else {
            // STT đánh TỪ DƯỚI LÊN: đơn cũ nhất (cuối bảng) = 1, mới nhất (đầu bảng) = tổng số.
            $total_rows = count($orders);
            $row_index = 0;
            foreach ($orders as $o) {
                $stt = $total_rows - $row_index;
                $row_index++;
                $lid = (int) ($o['local_ledger_id'] ?? 0);
                $dates = '&from=' . rawurlencode($from) . '&to=' . rawurlencode($to);

                $code = (string) ($o['local_ledger_code'] ?? '');
                $job_row = isset($pf['jobs'][$code]) ? $pf['jobs'][$code] : null;
                $job = $job_row ? (string) $job_row['status'] : null;
                $cust = (string) ($o['local_ledger_person_name'] ?? '');
                $phone = (string) ($o['local_ledger_person_phone'] ?? '');
                $nv = TGS_Agent_Source::resolve_nv($o['user_id'] ?? 0); // cache theo user trong request
                $can_queue = $job === null && $site_srid && !empty($nv['nvid']);
                $otype = !empty($pf['credit'][$lid]) ? 'Bán nợ' : 'Bán lẻ';
                $pd = isset($pf['promo'][$lid]) ? $pf['promo'][$lid]
                    : array('km' => false, 'ck' => false, 'z' => false, 'ck_amount' => 0.0);

                echo '<tr>';
                // STT (từ dưới lên)
                echo '<td style="color:#888;">' . (int) $stt . '</td>';
                // Mã / Ngày
                echo '<td><strong>' . esc_html($code) . '</strong><br>'
                    . '<small>' . esc_html((string) ($o['created_at'] ?? '')) . '</small></td>';
                // Khách
                echo '<td>' . esc_html($cust)
                    . ($phone !== '' ? '<br><small>' . esc_html($phone) . '</small>' : '') . '</td>';
                // Loại đơn · KM/CK
                $tags = array(self::pill($otype, '#eef', '#334'));
                if ($pd['z'])  { $tags[] = self::pill('Z', '#efe4fb', '#8250df', 'Có phiếu tách KM (mã Z)'); }
                if ($pd['km']) { $tags[] = self::pill('KM', '#daf1dd', '#1a7f37'); }
                if ($pd['ck']) { $tags[] = self::pill('CK ' . number_format($pd['ck_amount']), '#fde8c8', '#8a6116'); }
                echo '<td>' . implode(' ', $tags) . '</td>';
                // Tổng tiền
                echo '<td style="text-align:right;white-space:nowrap;">'
                    . esc_html(number_format((float) ($o['local_ledger_total_amount'] ?? 0))) . '</td>';

                // eVAT: hiện tại + lúc đẩy (gọn, chi tiết ở tooltip) — lấy từ prefetch.
                $ev_now  = isset($pf['evat'][$lid]) ? $pf['evat'][$lid]
                    : array('state' => '', 'no' => '', 'done' => false);
                $ev_push = $job_row ? TGS_Agent_Source::evat_push_from_row($job_row) : null;
                $st = $ev_now['state'];
                if ($st === '') {
                    $now_pill = self::pill('–', '#eee', '#888', 'Chưa có eVAT');
                } elseif ($ev_now['done']) {
                    $now_pill = self::pill('✔ ' . $ev_now['no'], '#daf1dd', '#1a7f37', 'Đã phát hành');
                } elseif (in_array($st, array('pending', 'issued'), true)) {
                    $now_pill = self::pill('…', '#fde8c8', '#8a6116', 'Đang phát hành (' . $st . ')');
                } else {
                    $now_pill = self::pill('✗', '#fde1e1', '#b32d2e', 'Lỗi phát hành: ' . $st);
                }
                if ($ev_push === null) {
                    $push_pill = self::pill('chưa job', '#eee', '#888', 'Chưa tạo job');
                } elseif (!empty($ev_push['had'])) {
                    $push_pill = self::pill('✔', '#daf1dd', '#1a7f37',
                        'Payload lúc đẩy CÓ eVAT' . ($ev_push['no'] !== '' ? ': ' . $ev_push['no'] : ''));
                } else {
                    $push_pill = self::pill('✗', '#fde1e1', '#b32d2e', 'Payload lúc đẩy CHƯA có eVAT');
                }
                echo '<td style="white-space:nowrap;"><small>nay</small> ' . $now_pill
                    . '<br><small>đẩy</small> ' . $push_pill . '</td>';

                // TT phiếu
                echo '<td>' . esc_html((string) ($o['local_ledger_status'] ?? '')) . '</td>';

                // NV
                $uid = (int) ($o['user_id'] ?? 0);
                $uobj = $uid ? get_userdata($uid) : null;
                $uname = $uobj ? $uobj->user_login : ('#' . $uid);
                echo '<td>' . (!empty($nv['nvid'])
                    ? esc_html((string) ($nv['nv_code'] ?: $nv['nvid']))
                    : self::pill('chưa nối NV', '#fde1e1', '#b32d2e') . '<br><small>' . esc_html($uname) . '</small>') . '</td>';

                // Job: click mở modal chi tiết (+ nút Tạo lại job trong modal).
                if ($job_row) {
                    $jd  = "Job: " . $job_row['job_id'] . "\n"
                         . "Trạng thái: " . $job_row['status'] . "\n"
                         . "Action: " . $job_row['action'] . "\n"
                         . "Chi nhánh: " . $job_row['branch_code'] . "\n"
                         . "BHDCODE: " . (string) $job_row['bhdcode'] . "\n"
                         . "Lần thử (attempt): " . $job_row['attempt_count'] . "\n"
                         . "Tạo: " . $job_row['created_at'] . "   | Cập nhật: " . $job_row['updated_at'] . "\n"
                         . "Claim: " . ($job_row['claimed_at'] ?: '—') . "   | Heartbeat: " . ($job_row['heartbeat_at'] ?: '—') . "\n";
                    $jerr = trim((($job_row['error_code'] ? $job_row['error_code'] . ': ' : '') . (string) $job_row['error_message']));
                    if ($jerr !== '') { $jd .= "Lỗi: " . $jerr . "\n"; }
                    if (!empty($job_row['result_json'])) {
                        $rj = json_decode((string) $job_row['result_json'], true);
                        $jd .= "\nresult_json:\n" . (is_array($rj)
                            ? wp_json_encode($rj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                            : (string) $job_row['result_json']);
                    }
                    $recreate = wp_nonce_url(
                        admin_url('admin-post.php?action=tgs_agent_recreate_job&ledger_id=' . $lid . $dates),
                        'tgs_agent_recreate_job_' . $lid
                    );
                    echo '<td><a href="#" class="tgs-job-detail" title="Xem chi tiết job"'
                        . ' data-title="Job đơn ' . esc_attr($code) . '"'
                        . ' data-detail="' . esc_attr($jd) . '"'
                        . ' data-recreate="' . esc_attr($recreate) . '">'
                        . self::pill($job, '#daf1dd', '#1a7f37') . '</a></td>';
                } else {
                    echo '<td>—</td>';
                }

                // Thao tác
                echo '<td style="white-space:nowrap;">';
                if ($job !== null) {
                    $del_url = wp_nonce_url(
                        admin_url('admin-post.php?action=tgs_agent_delete_job&ledger_id=' . $lid . $dates),
                        'tgs_agent_delete_job_' . $lid
                    );
                    echo '<a class="button button-small tgs-confirm" style="color:#b32d2e;border-color:#b32d2e;" href="'
                        . esc_url($del_url) . '" data-confirm="Xoá job khỏi queue cho đơn ' . esc_attr($code) . '?"'
                        . ' data-ok="Xoá queue">Xoá queue</a>';
                } elseif ($can_queue) {
                    $url = wp_nonce_url(
                        admin_url('admin-post.php?action=tgs_agent_queue_order&ledger_id=' . $lid . $dates),
                        'tgs_agent_queue_order_' . $lid
                    );
                    echo '<a class="button button-primary button-small" href="' . esc_url($url) . '">Thêm queue</a>';
                } else {
                    echo '<span class="description" style="color:#b32d2e;">thiếu CN/NV</span>';
                }
                // Đẩy lại RIÊNG phiếu Z — chỉ khi đơn có Z + đủ chi nhánh/NV.
                if (!empty($pd['z']) && $site_srid && !empty($nv['nvid'])) {
                    $z_url = wp_nonce_url(
                        admin_url('admin-post.php?action=tgs_agent_queue_bill_z&ledger_id=' . $lid . $dates),
                        'tgs_agent_queue_bill_z_' . $lid
                    );
                    echo '<br><a class="button button-small tgs-confirm" style="margin-top:3px;color:#8250df;border-color:#8250df;" href="'
                        . esc_url($z_url) . '" data-confirm="Đẩy lại RIÊNG phiếu Z (tách KM) của đơn ' . esc_attr($code) . '?'
                        . ' Nếu phiếu Z đã tạo trước đó, HTsoft sẽ có hoá đơn Z TRÙNG."'
                        . ' data-ok="Đẩy lại Z">Đẩy lại Z</a>';
                }
                echo '</td></tr>';
            }
        }
        echo '</tbody></table>';
        self::render_confirm_modal();
        self::render_job_modal();
        echo '</div>';
    }

    /** Modal chi tiết job (link .tgs-job-detail): hiện data-detail + nút Copy + Tạo lại job (data-recreate). */
    private static function render_job_modal()
    {
        ?>
        <div id="tgs-job-overlay" style="display:none;position:fixed;inset:0;z-index:100000;
            background:rgba(0,0,0,.5);">
          <div role="dialog" aria-modal="true" aria-labelledby="tgs-job-title" style="max-width:640px;
              margin:8% auto;background:#fff;border-radius:6px;box-shadow:0 8px 30px rgba(0,0,0,.3);padding:18px 20px;">
            <h2 id="tgs-job-title" style="margin:0 0 10px;font-size:16px;">Chi tiết job</h2>
            <pre id="tgs-job-body" style="max-height:50vh;overflow:auto;background:#f6f7f7;padding:10px;
                border-radius:4px;white-space:pre-wrap;word-break:break-word;font-size:12px;margin:0 0 14px;"></pre>
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <a class="button button-primary" id="tgs-job-recreate" href="#"
                 style="background:#8250df;border-color:#8250df;">Tạo lại job</a>
              <span>
                <button type="button" class="button" id="tgs-job-copy">Copy</button>
                <button type="button" class="button" id="tgs-job-close">Đóng</button>
              </span>
            </div>
          </div>
        </div>
        <script>
        (function () {
            var ov = document.getElementById('tgs-job-overlay'),
                title = document.getElementById('tgs-job-title'),
                body = document.getElementById('tgs-job-body'),
                copy = document.getElementById('tgs-job-copy'),
                recreate = document.getElementById('tgs-job-recreate'),
                closeB = document.getElementById('tgs-job-close');
            function close() { ov.style.display = 'none'; }
            function fallback(t) {
                var ta = document.createElement('textarea');
                ta.value = t; document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); copy.textContent = 'Đã copy ✓'; }
                catch (e) { copy.textContent = 'Copy lỗi'; }
                document.body.removeChild(ta);
            }
            document.addEventListener('click', function (e) {
                var a = e.target.closest ? e.target.closest('a.tgs-job-detail') : null;
                if (!a) { return; }
                e.preventDefault();
                title.textContent = a.getAttribute('data-title') || 'Chi tiết job';
                body.textContent = a.getAttribute('data-detail') || '';
                var rc = a.getAttribute('data-recreate');
                if (rc) { recreate.href = rc; recreate.style.display = ''; }
                else { recreate.style.display = 'none'; }
                copy.textContent = 'Copy';
                ov.style.display = 'block';
            });
            copy.addEventListener('click', function () {
                var t = body.textContent;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(t).then(function () { copy.textContent = 'Đã copy ✓'; },
                        function () { fallback(t); });
                } else { fallback(t); }
            });
            recreate.addEventListener('click', function (e) {
                if (!window.confirm('Xoá job hiện tại và TẠO LẠI job mới cho đơn này?')) { e.preventDefault(); }
            });
            closeB.addEventListener('click', close);
            ov.addEventListener('click', function (e) { if (e.target === ov) { close(); } });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && ov.style.display === 'block') { close(); }
            });
        })();
        </script>
        <?php
    }

    /** Modal xác nhận dùng chung cho các nút có class .tgs-confirm (data-confirm, data-ok). */
    private static function render_confirm_modal()
    {
        ?>
        <div id="tgs-confirm-overlay" style="display:none;position:fixed;inset:0;z-index:100000;
            background:rgba(0,0,0,.5);">
          <div role="dialog" aria-modal="true" aria-labelledby="tgs-confirm-msg" style="max-width:420px;
              margin:12% auto;background:#fff;border-radius:6px;box-shadow:0 8px 30px rgba(0,0,0,.3);
              padding:20px 22px;">
            <h2 style="margin:0 0 10px;font-size:16px;">Xác nhận</h2>
            <p id="tgs-confirm-msg" style="margin:0 0 18px;line-height:1.5;"></p>
            <div style="text-align:right;">
              <button type="button" class="button" id="tgs-confirm-cancel">Huỷ</button>
              <button type="button" class="button button-primary" id="tgs-confirm-ok">Đồng ý</button>
            </div>
          </div>
        </div>
        <script>
        (function () {
            var overlay = document.getElementById('tgs-confirm-overlay');
            var msgEl   = document.getElementById('tgs-confirm-msg');
            var okBtn   = document.getElementById('tgs-confirm-ok');
            var cancel  = document.getElementById('tgs-confirm-cancel');
            var pending = null;
            function close() { overlay.style.display = 'none'; pending = null; }
            document.addEventListener('click', function (e) {
                var a = e.target.closest ? e.target.closest('a.tgs-confirm') : null;
                if (!a) { return; }
                e.preventDefault();
                pending = a.getAttribute('href');
                msgEl.textContent = a.getAttribute('data-confirm') || 'Bạn có chắc không?';
                okBtn.textContent = a.getAttribute('data-ok') || 'Đồng ý';
                overlay.style.display = 'block';
            });
            okBtn.addEventListener('click', function () { if (pending) { window.location.href = pending; } });
            cancel.addEventListener('click', close);
            overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && overlay.style.display === 'block') { close(); }
            });
        })();
        </script>
        <?php
    }

    public static function handle_delete_job()
    {
        if (!current_user_can('manage_options')) { wp_die('Không đủ quyền'); }
        $ledger_id = isset($_GET['ledger_id']) ? (int) $_GET['ledger_id'] : 0;
        check_admin_referer('tgs_agent_delete_job_' . $ledger_id);
        $order = TGS_Agent_Source::get_order($ledger_id);
        $code = $order ? (string) ($order['local_ledger_code'] ?? '') : '';
        $deleted = $code !== '' ? TGS_Agent_Source::delete_job_for_code($code) : 0;
        $msg = $deleted > 0 ? 'ok:deleted' : 'err:không tìm thấy job để xoá';
        $args = array('page' => 'tgs-htsoft-agent-orders', 'tgs_q' => $msg);
        if (isset($_GET['from'])) { $args['from'] = sanitize_text_field(wp_unslash($_GET['from'])); }
        if (isset($_GET['to'])) { $args['to'] = sanitize_text_field(wp_unslash($_GET['to'])); }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
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

    public static function handle_queue_bill_z()
    {
        if (!current_user_can('manage_options')) { wp_die('Không đủ quyền'); }
        $ledger_id = isset($_GET['ledger_id']) ? (int) $_GET['ledger_id'] : 0;
        check_admin_referer('tgs_agent_queue_bill_z_' . $ledger_id);
        $res = TGS_Agent_Source::queue_bill_z_by_ledger_id($ledger_id);
        $msg = is_wp_error($res) ? ('err:' . $res->get_error_message())
            : ('ok:Z ' . (isset($res['created']) && $res['created'] ? 'created' : 'existed'));
        $args = array('page' => 'tgs-htsoft-agent-orders', 'tgs_q' => $msg);
        if (isset($_GET['from'])) { $args['from'] = sanitize_text_field(wp_unslash($_GET['from'])); }
        if (isset($_GET['to'])) { $args['to'] = sanitize_text_field(wp_unslash($_GET['to'])); }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public static function handle_recreate_job()
    {
        if (!current_user_can('manage_options')) { wp_die('Không đủ quyền'); }
        $ledger_id = isset($_GET['ledger_id']) ? (int) $_GET['ledger_id'] : 0;
        check_admin_referer('tgs_agent_recreate_job_' . $ledger_id);
        // Xoá job cũ (nếu có) rồi tạo lại — áp đủ guard eVAT/chi nhánh/NV/đã-đẩy trong queue_by_ledger_id.
        $order = TGS_Agent_Source::get_order($ledger_id);
        $code = $order ? (string) ($order['local_ledger_code'] ?? '') : '';
        if ($code !== '') { TGS_Agent_Source::delete_job_for_code($code); }
        $res = TGS_Agent_Source::queue_by_ledger_id($ledger_id);
        $msg = is_wp_error($res) ? ('err:' . $res->get_error_message())
            : ('ok:recreated ' . (isset($res['created']) && $res['created'] ? 'created' : 'existed'));
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

                // Lỗi: hiện gọn, click mở modal chi tiết (kèm result_json) + nút copy.
                $err = trim(($r->error_code ? $r->error_code . ': ' : '') . (string) $r->error_message);
                if ($err === '' && empty($r->result_json)) {
                    echo '<td>—</td>';
                } else {
                    $detail = $err;
                    if (!empty($r->result_json)) {
                        $pj = json_decode((string) $r->result_json, true);
                        $pretty = is_array($pj)
                            ? wp_json_encode($pj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                            : (string) $r->result_json;
                        $detail .= ($detail !== '' ? "\n\n" : '') . "result_json:\n" . $pretty;
                    }
                    $short = $err !== '' ? mb_strimwidth($err, 0, 48, '…') : 'xem chi tiết';
                    $color = $err !== '' ? '#b32d2e' : '#2271b1';
                    echo '<td><a href="#" class="tgs-detail" style="color:' . $color . ';" '
                        . 'data-title="Chi tiết job ' . esc_attr(substr($r->job_id, 0, 8)) . '" '
                        . 'data-detail="' . esc_attr($detail) . '">' . esc_html($short) . '</a></td>';
                }

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
        echo '</tbody></table>';
        self::render_detail_modal();
        echo '</div>';
    }

    /** Modal xem chi tiết (dùng cho .tgs-detail): hiện data-detail trong <pre> + nút Copy. */
    private static function render_detail_modal()
    {
        ?>
        <div id="tgs-detail-overlay" style="display:none;position:fixed;inset:0;z-index:100000;
            background:rgba(0,0,0,.5);">
          <div role="dialog" aria-modal="true" aria-labelledby="tgs-detail-title" style="max-width:640px;
              margin:8% auto;background:#fff;border-radius:6px;box-shadow:0 8px 30px rgba(0,0,0,.3);padding:18px 20px;">
            <h2 id="tgs-detail-title" style="margin:0 0 10px;font-size:16px;">Chi tiết</h2>
            <pre id="tgs-detail-body" style="max-height:50vh;overflow:auto;background:#f6f7f7;padding:10px;
                border-radius:4px;white-space:pre-wrap;word-break:break-word;font-size:12px;margin:0 0 14px;"></pre>
            <div style="text-align:right;">
              <button type="button" class="button" id="tgs-detail-copy">Copy</button>
              <button type="button" class="button button-primary" id="tgs-detail-close">Đóng</button>
            </div>
          </div>
        </div>
        <script>
        (function () {
            var ov = document.getElementById('tgs-detail-overlay'),
                title = document.getElementById('tgs-detail-title'),
                body = document.getElementById('tgs-detail-body'),
                copy = document.getElementById('tgs-detail-copy'),
                closeB = document.getElementById('tgs-detail-close');
            function close() { ov.style.display = 'none'; }
            function fallback(t) {
                var ta = document.createElement('textarea');
                ta.value = t; document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); copy.textContent = 'Đã copy ✓'; }
                catch (e) { copy.textContent = 'Copy lỗi'; }
                document.body.removeChild(ta);
            }
            document.addEventListener('click', function (e) {
                var a = e.target.closest ? e.target.closest('a.tgs-detail') : null;
                if (!a) { return; }
                e.preventDefault();
                title.textContent = a.getAttribute('data-title') || 'Chi tiết';
                body.textContent = a.getAttribute('data-detail') || '';
                copy.textContent = 'Copy';
                ov.style.display = 'block';
            });
            copy.addEventListener('click', function () {
                var t = body.textContent;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(t).then(function () { copy.textContent = 'Đã copy ✓'; },
                        function () { fallback(t); });
                } else { fallback(t); }
            });
            closeB.addEventListener('click', close);
            ov.addEventListener('click', function (e) { if (e.target === ov) { close(); } });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && ov.style.display === 'block') { close(); }
            });
        })();
        </script>
        <?php
    }

    // ----- Quản lý đồng bộ -----
    public static function render_sync()
    {
        if (!current_user_can('manage_options')) { return; }

        // Lưu cấu hình Woo.
        if (isset($_POST['tgs_agent_settings_nonce'])
            && check_admin_referer('tgs_agent_settings', 'tgs_agent_settings_nonce')) {
            $was_auto = get_option('tgs_agent_pos_auto') == 1;
            $now_auto = isset($_POST['pos_auto']);
            update_option('tgs_agent_pos_auto', $now_auto ? 1 : 0);
            // Lần ĐẦU bật -> ghi mốc thời điểm: auto chỉ lấy đơn TỪ MỐC NÀY trở đi (không backfill cả quá khứ).
            if ($now_auto && !$was_auto) {
                update_option('tgs_agent_pos_auto_since', current_time('mysql'));
            } elseif (!$now_auto) {
                delete_option('tgs_agent_pos_auto_since');
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
