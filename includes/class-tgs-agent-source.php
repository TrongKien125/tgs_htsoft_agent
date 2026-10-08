<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Nguồn đơn = tgs_pos (bảng local_ledger). Phiếu bán = local_ledger_type 10 (TGS_LEDGER_TYPE_SALE_ORDER).
 *
 *  - recent_sale_orders(): liệt kê đơn cho màn "Danh sách đơn".
 *  - queue_by_ledger_id(): build payload §3 + TGS_Agent_Jobs::create() (nút thủ công).
 *  - on_order_create(): hook tgs_after_order_create -> auto tạo job (nếu bật tgs_agent_pos_auto).
 *
 * MAPPING TIỀN/THANH TOÁN/NV chưa chốt 100% (giá/CK/payment/nv_code tuỳ dữ liệu thật) -> để qua
 * filter 'tgs_agent_ledger_to_payload' tinh chỉnh. Mặc định build best-effort từ cột đã xác nhận.
 */
class TGS_Agent_Source
{
    const SALE_TYPE = 10;

    private static function ledger_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'local_ledger';
    }
    private static function item_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'local_ledger_item';
    }

    /** Danh sách phiếu bán gần nhất (type 10). */
    public static function recent_sale_orders($limit = 100)
    {
        global $wpdb;
        $t = self::ledger_table();
        $type = (int) get_option('tgs_agent_ledger_sale_type', self::SALE_TYPE);
        $limit = max(1, min(500, (int) $limit));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE local_ledger_type = %d AND (is_deleted IS NULL OR is_deleted = 0)
             ORDER BY local_ledger_id DESC LIMIT %d", $type, $limit
        ), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public static function get_order($ledger_id)
    {
        global $wpdb;
        $t = self::ledger_table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE local_ledger_id = %d", (int) $ledger_id), ARRAY_A);
    }

    /** Loại ledger của phiếu XUẤT (nơi chứa dòng hàng) — con của phiếu bán type 10. */
    private static function export_type()
    {
        return defined('TGS_LEDGER_TYPE_SALE') ? (int) TGS_LEDGER_TYPE_SALE : 2;
    }

    /** Phiếu xuất (type 2) con của phiếu bán — nơi THẬT SỰ chứa dòng hàng. 0 nếu không có. */
    private static function export_ledger_of($sale_ledger_id)
    {
        global $wpdb;
        $t = self::ledger_table();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT local_ledger_id FROM $t
             WHERE local_ledger_parent_id = %d AND local_ledger_type = %d
               AND (is_deleted IS NULL OR is_deleted = 0)
             ORDER BY local_ledger_id ASC LIMIT 1",
            (int) $sale_ledger_id, self::export_type()
        ));
    }

    public static function get_items($ledger_id)
    {
        global $wpdb;
        $t = self::item_table();
        $ledger_id = (int) $ledger_id;
        // Dòng hàng nằm trên phiếu XUẤT (type 2) con của phiếu bán (type 10), KHÔNG
        // trên phiếu bán. Hop sang export child; không có thì thử ngay ledger truyền vào.
        $export_id = self::export_ledger_of($ledger_id);
        $target = $export_id > 0 ? $export_id : $ledger_id;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE local_ledger_id = %d ORDER BY local_ledger_item_id ASC", $target
        ), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    /** Đơn (theo local_ledger_code) đã có job chưa — trả status hoặc null. */
    public static function job_status_for_code($code)
    {
        global $wpdb;
        $jt = TGS_Agent_DB::jobs_table();
        $key = get_current_blog_id() . ':create_retail_invoice:' . $code;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT status FROM $jt WHERE idempotency_key = %s", $key
        ));
        return $row ? $row->status : null;
    }

    /** Option data của tgs-multisite-hierarchy (sites[].htsoft_srid, staff[].htsoft_nvid). */
    private static function hierarchy_data()
    {
        $key = defined('TGS_HIERARCHY_OPTION_KEY') ? TGS_HIERARCHY_OPTION_KEY : 'tgs_multisite_hierarchy_data';
        $raw = get_site_option($key, '');
        if (!$raw) { return array(); }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : array();
    }

    /** SITE hiện tại → SRID chi nhánh HTsoft. */
    public static function resolve_srid($blog_id = null)
    {
        $blog_id = $blog_id ?: (int) get_current_blog_id();
        if (class_exists('TGS_DM_Site')) {
            $srid = TGS_DM_Site::htsoft_srid($blog_id);
            if ($srid) { return $srid; }
        }
        $data = self::hierarchy_data();
        $srid = isset($data['sites'][$blog_id]['htsoft_srid']) ? trim((string) $data['sites'][$blog_id]['htsoft_srid']) : '';
        return $srid !== '' ? $srid : null;
    }

    /** WP USER (người bán) → ['nvid'=>GUID, 'nv_code'=>mã NV HTsoft].
     * Staff lưu ở option RIÊNG `tgs_staff_management_data` (plugin tgs-multisite-hierarchy),
     * keyed theo string user_id. Dùng API TGS_Staff_Data::get_htsoft_nvid() nếu có. */
    public static function resolve_nv($user_id)
    {
        $user_id = (int) $user_id;
        $out = array('nvid' => null, 'nv_code' => null);
        if ($user_id <= 0) { return $out; }

        $nvid = '';
        if (class_exists('TGS_Staff_Data')) {
            $nvid = (string) TGS_Staff_Data::get_htsoft_nvid($user_id);
        }
        if ($nvid === '') {
            // Fallback đọc thẳng option (lưu dạng array, keyed string user_id).
            $data = get_site_option('tgs_staff_management_data', array());
            if (is_string($data)) { $data = json_decode($data, true); }
            if (is_array($data) && isset($data['staff'][strval($user_id)]['htsoft_nvid'])) {
                $nvid = trim((string) $data['staff'][strval($user_id)]['htsoft_nvid']);
            }
        }
        $nvid = trim($nvid);
        if ($nvid === '') { return $out; }
        $out['nvid'] = $nvid;

        global $wpdb;
        $t = $wpdb->base_prefix . 'htsoft_nhanvien'; // global toàn network
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t) {
            $code = $wpdb->get_var($wpdb->prepare("SELECT NVCODE FROM {$t} WHERE NVID = %s", $nvid));
            if ($code) { $out['nv_code'] = (string) $code; }
        }
        return $out;
    }

    /** Build payload §3 từ 1 phiếu bán. */
    public static function build_payload(array $order, array $items)
    {
        $code = (string) ($order['local_ledger_code'] ?? '');

        // SITE -> chi nhánh (SRID). NV -> theo người bán (user_id của phiếu).
        $srid = self::resolve_srid();
        $nv   = self::resolve_nv($order['user_id'] ?? 0);
        // branch_code = SRID (AddIn claim đúng chi nhánh). BẮT BUỘC — không fallback tĩnh.
        $branch = $srid ?: '';
        $nvCode = $nv['nv_code'];

        $person_meta = json_decode((string) ($order['local_ledger_person_meta'] ?? ''), true);
        if (!is_array($person_meta)) { $person_meta = array(); }

        $khach = array(
            'ma'      => (string) ($order['local_ledger_person_code'] ?? ($person_meta['code'] ?? '')) ?: null,
            'ten'     => (string) ($order['local_ledger_person_name'] ?? ($person_meta['name'] ?? '')),
            'di_dong' => (string) ($order['local_ledger_person_phone'] ?? ($person_meta['phone'] ?? '')),
            'dia_chi' => (string) ($order['local_ledger_person_address'] ?? ($person_meta['address'] ?? '')),
            'email'   => (string) ($order['local_ledger_person_email'] ?? ($person_meta['email'] ?? '')),
        );

        $lines = array();
        foreach ($items as $it) {
            $sku = (string) ($it['local_product_sku'] ?? '');
            if ($sku === '') { continue; }
            $lines[] = array(
                'mhcode'      => $sku,
                'mhid'        => null,
                'kho_code'    => null,
                'soluong'     => (float) ($it['quantity'] ?? 0),                 // ĐVT nhỏ nhất
                'giaban'      => round((float) ($it['local_ledger_item_price_after_discount'] ?? 0)),
                'chietkhau'   => round((float) ($it['local_ledger_item_discount_amount'] ?? 0)),
                'unit_name'   => (string) ($it['local_ledger_item_unit_name'] ?? ''),
                'qty_rate'    => (float) ($it['local_ledger_item_unit_ratio'] ?? 1),
                'soluong_ex'  => (float) ($it['local_ledger_item_unit_quantity'] ?? 0),
                'ghi_chu'     => (string) ($it['local_ledger_item_note'] ?? ''),
                'imei'        => array(),
                'is_gift'     => (int) ($it['local_ledger_item_gift_type'] ?? 0),
            );
        }

        // NGUỒN BÁN: tên thật nằm trong advance_meta (khoá pos_sale_source), KHÔNG phải
        // cột local_ledger_source (là ID, vd "1" — HTsoft không hiểu, gây lỗi lưu).
        // Không có tên thật -> để TRỐNG, HTsoft tự đặt mặc định ("Gần shop").
        $nguon_ban = '';
        if (class_exists('TGS_POS_Sale_Source')) {
            $nguon_ban = (string) TGS_POS_Sale_Source::read_from_meta($order['local_ledger_advance_meta'] ?? '');
        } else {
            $adv = json_decode((string) ($order['local_ledger_advance_meta'] ?? ''), true);
            if (is_array($adv) && isset($adv['pos_sale_source'])) {
                $nguon_ban = (string) $adv['pos_sale_source'];
            }
        }

        $total = round((float) ($order['local_ledger_total_amount'] ?? 0));
        $payload = array(
            'pos_ref'     => $code,
            'branch_code' => $branch,              // = SRID (AddIn claim đúng chi nhánh)
            'srid'        => $srid,                // SRID chi nhánh HTsoft (site nối qua hierarchy)
            'ngay_hd'     => (string) ($order['created_at'] ?? ''),
            'khach'       => $khach,
            'nvid'        => $nv['nvid'],          // GUID nhân viên (connector/SQL dùng trực tiếp)
            'nv_code'     => $nvCode,              // mã NV HTsoft (AddIn fill lên form)
            'ly_do_xuat'  => 'XBA',
            'nguon_ban'   => $nguon_ban,           // tên nguồn thật ('' = HTsoft tự đặt)
            'ghi_chu'     => (string) ($order['local_ledger_note'] ?? ($order['local_ledger_title'] ?? '')),
            'lines'       => $lines,
            'payments'    => array(array('hinh_thuc' => 'tien_mat', 'amount' => $total)),
            'expected'    => array('tong_tien' => $total, 'so_dong' => count($lines)),
        );

        /**
         * Tinh chỉnh mapping tiền/CK/thanh toán/nv_code theo dữ liệu thật (giữ nơi hiểu ledger).
         * @param array $payload  payload mặc định
         * @param array $order    dòng local_ledger
         * @param array $items    dòng local_ledger_item
         */
        return apply_filters('tgs_agent_ledger_to_payload', $payload, $order, $items);
    }

    /** Tạo job từ 1 phiếu (nút thủ công / auto). Trả kết quả TGS_Agent_Jobs::create() hoặc WP_Error. */
    public static function queue_by_ledger_id($ledger_id)
    {
        $order = self::get_order($ledger_id);
        if (!$order) {
            return new WP_Error('NOT_FOUND', 'Không tìm thấy phiếu');
        }
        $code = (string) ($order['local_ledger_code'] ?? '');
        if ($code === '') {
            return new WP_Error('NO_CODE', 'Phiếu thiếu local_ledger_code');
        }
        $items = self::get_items($ledger_id);
        $payload = self::build_payload($order, $items);

        // BẮT BUỘC: chi nhánh (site→SRID) + nhân viên (user→NVID). Thiếu → KHÔNG tạo queue.
        if (empty($payload['srid'])) {
            return new WP_Error('NO_BRANCH',
                'Site chưa nối chi nhánh HTsoft (SRID) — không tạo queue. Cấu hình ở tgs-multisite-hierarchy.');
        }
        if (empty($payload['nvid'])) {
            $uid = (int) ($order['user_id'] ?? 0);
            return new WP_Error('NO_NV',
                'Người bán (user #' . $uid . ') chưa nối nhân viên HTsoft (NVID) — không tạo queue.');
        }

        $branch = (string) ($payload['branch_code'] ?? '');

        return TGS_Agent_Jobs::create(array(
            'idempotency_key' => get_current_blog_id() . ':create_retail_invoice:' . $code,
            'action'          => 'create_retail_invoice',
            'branch_code'     => $branch,
            'payload'         => $payload,
            'requested_by'    => 'tgs_pos',
        ));
    }

    /**
     * AddIn tạo HĐ thành công → GHI ĐÈ local_ledger_code = mã HTsoft (BHDCODE), swap trong title.
     * (Giống reconcile_local_codes của tgs_pos, bước phiếu CHÍNH.) Phần phiếu con/thu (Z, BPTTCODE)
     * để tgs_pos tự reconcile qua action 'tgs_agent_invoice_reconciled'.
     * Trả true nếu đã override.
     */
    public static function apply_htsoft_code($pos_ref, $bhdcode, array $result = array())
    {
        $pos_ref = trim((string) $pos_ref);
        $bhdcode = trim((string) $bhdcode);
        if ($pos_ref === '' || $bhdcode === '' || $bhdcode === $pos_ref) {
            return false;
        }
        global $wpdb;
        $lt = self::ledger_table();
        $type = (int) get_option('tgs_agent_ledger_sale_type', self::SALE_TYPE);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT local_ledger_id, local_ledger_code, local_ledger_title FROM $lt
             WHERE local_ledger_code = %s AND local_ledger_type = %d
               AND (is_deleted IS NULL OR is_deleted = 0) LIMIT 1",
            $pos_ref, $type
        ));
        if (!$row) {
            return false; // mã đã bị đè trước đó / không tìm thấy
        }

        $title = (string) $row->local_ledger_title;
        $new_title = $title !== '' ? str_replace($pos_ref, $bhdcode, $title) : $title;
        $wpdb->update($lt, array(
            'local_ledger_code'  => $bhdcode,
            'local_ledger_title' => $new_title,
            'updated_at'         => current_time('mysql'),
        ), array('local_ledger_id' => (int) $row->local_ledger_id));

        // Cho tgs_pos reconcile phần còn lại (bill Z, phiếu thu BPTTCODE) nếu muốn.
        do_action('tgs_agent_invoice_reconciled', (int) $row->local_ledger_id, $bhdcode, $result, $pos_ref);
        return true;
    }

    /** Hook tgs_after_order_create: auto tạo job nếu bật. */
    public static function on_order_create($sale_ledger_id, $order_data = null, $products_data = null, $metas = null)
    {
        if (get_option('tgs_agent_pos_auto') != 1) {
            return;
        }
        self::queue_by_ledger_id((int) $sale_ledger_id);
    }
}
