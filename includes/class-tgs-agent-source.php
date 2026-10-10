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
    /**
     * Phiếu bán (type 10), lọc theo khoảng NGÀY (created_at). $from/$to dạng 'Y-m-d' (rỗng = bỏ lọc đầu đó).
     */
    public static function recent_sale_orders($limit = 500, $from = '', $to = '')
    {
        global $wpdb;
        $t = self::ledger_table();
        $type = (int) get_option('tgs_agent_ledger_sale_type', self::SALE_TYPE);
        $limit = max(1, min(1000, (int) $limit));

        // Chỉ phiếu GỐC: loại phiếu Z (con, có local_ledger_parent_id) — Z đi kèm payload phiếu gốc.
        $where = "local_ledger_type = %d AND (is_deleted IS NULL OR is_deleted = 0)"
               . " AND (local_ledger_parent_id IS NULL OR local_ledger_parent_id = 0)";
        $args = array($type);
        if ($from !== '') {
            $where .= " AND created_at >= %s";
            $args[] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $where .= " AND created_at <= %s";
            $args[] = $to . ' 23:59:59';
        }
        $args[] = $limit;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE $where ORDER BY local_ledger_id DESC LIMIT %d", $args
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

    /** Xoá job create_retail_invoice của 1 đơn (theo local_ledger_code). Trả số dòng xoá. */
    public static function delete_job_for_code($code)
    {
        $code = trim((string) $code);
        if ($code === '') {
            return 0;
        }
        $key = get_current_blog_id() . ':create_retail_invoice:' . $code;
        return TGS_Agent_Jobs::delete_by_idempotency_key($key);
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

    /**
     * Hóa đơn eVAT đã phát hành của phiếu (nếu có) → khối cho AddIn điền TAB THUẾ bên HTsoft.
     * Nguồn: bảng local_viettel_invoice, đúng như push_invoice_vat của tgs_pos.
     * Chỉ trả khi đã phát hành ('done') và có số hóa đơn; chưa lập → null (HTsoft để "chưa lập VAT").
     *   seri       → Ký hiệu HĐ · mau_so → Mẫu số HĐ · so_hoa_don → Số hóa đơn
     *   ngay_hd, tile_thue, thue (tổng tiền thuế)
     */
    public static function vat_invoice_of($sale_ledger_id)
    {
        global $wpdb;
        $vi = $wpdb->prefix . 'local_viettel_invoice';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $vi)) !== $vi) {
            return null;
        }
        $v = $wpdb->get_row($wpdb->prepare(
            "SELECT invoice_state, invoice_series, template_code, viettel_invoice_no,
                    total_before_tax, total_tax_amount, issue_sent_at
               FROM $vi WHERE sale_ledger_id = %d
              ORDER BY local_viettel_invoice_id DESC LIMIT 1",
            (int) $sale_ledger_id
        ), ARRAY_A);
        if (!$v || ($v['invoice_state'] ?? '') !== 'done') {
            return null;
        }
        $series = trim((string) $v['invoice_series']);
        $no     = trim((string) $v['viettel_invoice_no']);
        if ($no === '') {
            return null; // đã 'done' mà chưa có số → chưa đủ để điền tab thuế
        }
        // SoHD = seri + số (nếu số chưa gồm seri), giống push_invoice_vat.
        $so_hd  = ($series !== '' && stripos($no, $series) !== 0) ? ($series . $no) : $no;
        $pretax = (float) $v['total_before_tax'];
        $thue   = (float) $v['total_tax_amount'];
        $tile   = $pretax > 0 ? round($thue / $pretax * 100, 2) : 0;
        return array(
            'seri'       => $series,                            // → Ký hiệu HĐ
            'mau_so'     => trim((string) $v['template_code']), // → Mẫu số HĐ
            'so_hoa_don' => $so_hd,                             // → Số hóa đơn
            'ngay_hd'    => substr((string) ($v['issue_sent_at'] ?: ''), 0, 19),
            'tile_thue'  => $tile,                              // % thuế đại diện của hóa đơn
            'thue'       => round($thue),                       // tổng tiền thuế
        );
    }

    /**
     * Phiếu CÓ eVAT ĐANG TRONG QUÁ TRÌNH phát hành? (bản ghi eVAT mới nhất ở trạng thái
     * 'pending'/'issued' — chưa phát hành xong và chưa lỗi). true -> PHẢI CHỜ cron eVAT
     * xong mới tạo job HTsoft (để payload có khối thuế).
     *
     * CHỈ chặn khi đang chạy. Các tình huống SAU đều KHÔNG chờ (cho sang HTsoft luôn,
     * vat=null nếu chưa có khối thuế) — theo chính sách đã chốt:
     *   • Không có bản ghi eVAT (khách không lấy hóa đơn).
     *   • Phát hành HỤT: 'issue_error'/'cqt_error'/'validate_error'/'error'.
     *   • 'done' (đã phát hành — vat_invoice_of trả khối thuế).
     */
    public static function evat_is_pending($sale_ledger_id)
    {
        global $wpdb;
        $vi = $wpdb->prefix . 'local_viettel_invoice';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $vi)) !== $vi) {
            return false; // site không dùng eVAT
        }
        $state = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT invoice_state FROM $vi WHERE sale_ledger_id = %d
               AND (is_deleted = 0 OR is_deleted IS NULL)
              ORDER BY local_viettel_invoice_id DESC LIMIT 1",
            (int) $sale_ledger_id
        ));
        if ($state === '') {
            return false; // chưa phát sinh eVAT -> đẩy luôn
        }
        return in_array($state, array('pending', 'issued'), true); // chỉ chờ khi đang phát hành
    }

    /**
     * Trạng thái eVAT HIỆN TẠI của đơn (bản ghi local_viettel_invoice mới nhất).
     * Trả array('state'=>string, 'no'=>'<ký hiệu+số>', 'done'=>bool). state='' = chưa có eVAT.
     */
    public static function evat_current($sale_ledger_id)
    {
        global $wpdb;
        $vi = $wpdb->prefix . 'local_viettel_invoice';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $vi)) !== $vi) {
            return array('state' => '', 'no' => '', 'done' => false);
        }
        $v = $wpdb->get_row($wpdb->prepare(
            "SELECT invoice_state, invoice_series, viettel_invoice_no
               FROM $vi WHERE sale_ledger_id = %d
                 AND (is_deleted = 0 OR is_deleted IS NULL)
              ORDER BY local_viettel_invoice_id DESC LIMIT 1",
            (int) $sale_ledger_id
        ), ARRAY_A);
        if (!$v) {
            return array('state' => '', 'no' => '', 'done' => false);
        }
        $state  = (string) ($v['invoice_state'] ?? '');
        $series = trim((string) $v['invoice_series']);
        $no     = trim((string) $v['viettel_invoice_no']);
        $full   = ($series !== '' && $no !== '' && stripos($no, $series) !== 0) ? ($series . $no) : $no;
        return array('state' => $state, 'no' => $full, 'done' => ($state === 'done' && $no !== ''));
    }

    /**
     * eVAT trong payload job (snapshot LÚC TẠO JOB) theo mã phiếu.
     * Trả array('had'=>bool, 'no'=>string) — had=true nếu payload.vat có số hóa đơn.
     * null = chưa có job create_retail_invoice cho mã này.
     */
    public static function evat_at_push($code)
    {
        global $wpdb;
        $code = trim((string) $code);
        if ($code === '') { return null; }
        $jt = TGS_Agent_DB::jobs_table();
        $key = get_current_blog_id() . ':create_retail_invoice:' . $code;
        $payload_json = $wpdb->get_var($wpdb->prepare(
            "SELECT payload_json FROM $jt WHERE idempotency_key = %s", $key
        ));
        if ($payload_json === null) { return null; }
        $p = json_decode((string) $payload_json, true);
        $vat = is_array($p) && isset($p['vat']) ? $p['vat'] : null;
        if (is_array($vat) && !empty($vat['so_hoa_don'])) {
            return array('had' => true, 'no' => (string) $vat['so_hoa_don']);
        }
        return array('had' => false, 'no' => '');
    }

    /** Map dòng local_ledger_item → lines[] payload §3 (dùng chung cho đơn chính và phiếu Z). */
    private static function map_lines(array $items)
    {
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
                'thue_suat'   => (float) ($it['local_ledger_item_tax_percent'] ?? 0),          // % thuế suất của dòng
                'tien_thue'   => round((float) ($it['local_ledger_item_tax_amount'] ?? 0)),    // tiền thuế dòng (sau CK)
                'unit_name'   => (string) ($it['local_ledger_item_unit_name'] ?? ''),
                'qty_rate'    => (float) ($it['local_ledger_item_unit_ratio'] ?? 1),
                'soluong_ex'  => (float) ($it['local_ledger_item_unit_quantity'] ?? 0),
                'ghi_chu'     => (string) ($it['local_ledger_item_note'] ?? ''),
                'imei'        => array(),
                'is_gift'     => (int) ($it['local_ledger_item_gift_type'] ?? 0),
            );
        }
        return $lines;
    }

    /** Phiếu bán là PHIẾU Z? (parent type 10 và code = <mã gốc>+'Z') — khớp is_bill_z của tgs_pos. */
    public static function is_bill_z_ledger($ledger_id)
    {
        global $wpdb;
        $t = self::ledger_table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT local_ledger_parent_id, local_ledger_code FROM $t WHERE local_ledger_id = %d", (int) $ledger_id
        ), ARRAY_A);
        if (!$row) { return false; }
        $pid = (int) ($row['local_ledger_parent_id'] ?? 0);
        if ($pid <= 0) { return false; }
        $sale_type = (int) get_option('tgs_agent_ledger_sale_type', self::SALE_TYPE);
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT local_ledger_type, local_ledger_code FROM $t WHERE local_ledger_id = %d", $pid
        ), ARRAY_A);
        if (!$parent || (int) $parent['local_ledger_type'] !== $sale_type) { return false; }
        return strcasecmp(trim((string) $row['local_ledger_code']),
                          trim((string) $parent['local_ledger_code']) . 'Z') === 0;
    }

    /**
     * Phiếu Z đi kèm đơn bán (nếu có) → khối cho AddIn tạo HÓA ĐƠN THỨ HAI (mã = <mã chính>+'Z').
     * Z = type 10 con của đơn, có phiếu xuất riêng chứa dòng hàng (thường là hàng KM/tặng, giá 0).
     * Z nội bộ, KHÔNG khai thuế → không có khối vat. Không có Z → null.
     */
    public static function bill_z_of($sale_ledger_id)
    {
        global $wpdb;
        $t = self::ledger_table();
        $sale_type = (int) get_option('tgs_agent_ledger_sale_type', self::SALE_TYPE);
        $z_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT local_ledger_id FROM $t
             WHERE local_ledger_parent_id = %d AND local_ledger_type = %d
               AND (is_deleted IS NULL OR is_deleted = 0)
             ORDER BY local_ledger_id ASC LIMIT 1",
            (int) $sale_ledger_id, $sale_type
        ));
        if ($z_id <= 0) { return null; }
        $z_lines = self::map_lines(self::get_items($z_id));
        if (empty($z_lines)) { return null; }
        return array(
            'lines'   => $z_lines,
            'ghi_chu' => 'Phiếu tách hàng khuyến mãi (mã Z)',
            'so_dong' => count($z_lines),
        );
    }

    /**
     * Phiếu thu của đơn → payments[] payload. Đọc HÌNH THỨC THẬT từ meta (không mặc định tiền mặt).
     * Nguồn: local_ledger_meta.payment_receipts (như tgs_pos payments_of); fallback advance_meta.pos_payment.
     *   hinh_thuc: cash/'' → 'tien_mat' (khớp PaymentMap); còn lại giữ key POS (vd 'ttck_up_vietinbank') → AddIn map.
     * Bán nợ (credit_sale) → [] (AddIn để Công nợ). Không có phiếu thu hợp lệ → tiền mặt = tổng (như tgs_pos).
     */
    private static function payments_of(array $order)
    {
        global $wpdb;
        $total = round((float) ($order['local_ledger_total_amount'] ?? 0));
        $receipts = array();
        $credit = false;

        $mid = (int) ($order['local_ledger_meta_id'] ?? 0);
        if ($mid > 0) {
            $mt = $wpdb->prefix . 'local_ledger_meta';
            $mv = $wpdb->get_var($wpdb->prepare(
                "SELECT local_ledger_meta_value FROM $mt WHERE local_ledger_meta_id = %d", $mid
            ));
            $m = json_decode((string) $mv, true);
            if (is_array($m)) {
                if (!empty($m['credit_sale'])) { $credit = true; }
                if (!empty($m['payment_receipts']) && is_array($m['payment_receipts'])) {
                    $receipts = $m['payment_receipts'];
                }
            }
        }
        if (empty($receipts) && !$credit) {
            $adv = json_decode((string) ($order['local_ledger_advance_meta'] ?? ''), true);
            if (is_array($adv) && !empty($adv['pos_payment']['receipts']) && is_array($adv['pos_payment']['receipts'])) {
                $receipts = $adv['pos_payment']['receipts'];
            }
        }

        if ($credit) {
            return array(); // bán nợ: không sinh phiếu thu
        }

        $payments = array();
        foreach ($receipts as $r) {
            $amt = round((float) ($r['amount'] ?? 0));
            if ($amt <= 0) { continue; }
            $method = strtolower(trim((string) ($r['method'] ?? '')));
            $label  = (string) ($r['label'] ?? '');
            // DÒNG ĐỐI TRỪ (đổi trả): CHUYỀN QUA như hình thức "Đối trừ công nợ" (key 'doi_tru')
            // để tổng payments khớp tổng hóa đơn (940k tiền mặt + 450k đối trừ = tổng). AddIn map
            // 'doi_tru' → HTTT "Đối trừ công nợ" qua PaymentMap.
            if (!empty($r['offset_netting']) || $method === 'doi_tru') {
                $payments[] = array(
                    'hinh_thuc'  => 'doi_tru',
                    'amount'     => $amt,
                    'label'      => $label !== '' ? $label : 'Đối trừ công nợ',
                    'ghi_chu'    => (string) ($r['note'] ?? ''),
                    'offset'     => 1,                                               // đánh dấu đối trừ (không phải tiền mặt vào quỹ)
                    'offset_return_ledger_id' => (int) ($r['offset_return_ledger_id'] ?? 0),
                );
                continue;
            }
            $is_cash = $method === 'cash' || $method === '' || mb_stripos($label, 'tiền mặt') !== false || mb_stripos($label, 'tien mat') !== false;
            $payments[] = array(
                'hinh_thuc' => $is_cash ? 'tien_mat' : (string) ($r['method'] ?? ''), // key POS → AddIn PaymentMap
                'amount'    => $amt,
                'label'     => $label,                                                // nhãn để AddIn fallback/log
                'ghi_chu'   => (string) ($r['note'] ?? ''),
            );
        }
        if (empty($payments)) {
            $payments[] = array('hinh_thuc' => 'tien_mat', 'amount' => $total, 'label' => 'Tiền mặt', 'ghi_chu' => '');
        }
        return $payments;
    }

    /** Đơn BÁN NỢ? (meta local_ledger có cờ credit_sale) — khớp is_credit_sale của tgs_pos. */
    public static function is_credit_sale(array $order)
    {
        global $wpdb;
        $mid = (int) ($order['local_ledger_meta_id'] ?? 0);
        if ($mid <= 0) { return false; }
        $mt = $wpdb->prefix . 'local_ledger_meta';
        $mv = $wpdb->get_var($wpdb->prepare(
            "SELECT local_ledger_meta_value FROM $mt WHERE local_ledger_meta_id = %d", $mid
        ));
        $m = json_decode((string) $mv, true);
        return is_array($m) && !empty($m['credit_sale']);
    }

    /** ID phiếu Z (tách hàng khuyến mãi) con của đơn bán này — 0 nếu không có. */
    public static function bill_z_ledger_id($sale_ledger_id)
    {
        global $wpdb;
        $t = self::ledger_table();
        $sale_type = (int) get_option('tgs_agent_ledger_sale_type', self::SALE_TYPE);
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT local_ledger_id FROM $t
             WHERE local_ledger_parent_id = %d AND local_ledger_type = %d
               AND (is_deleted IS NULL OR is_deleted = 0) LIMIT 1",
            (int) $sale_ledger_id, $sale_type
        ));
    }

    /** Có phiếu Z con của đơn bán này không? (kiểm tra nhẹ, không load dòng). */
    private static function has_bill_z_child($sale_ledger_id)
    {
        return self::bill_z_ledger_id($sale_ledger_id) > 0;
    }

    /** Nhãn "loại đơn" cho màn Danh sách: 'Bán nợ' nếu credit_sale, ngược lại 'Bán lẻ'. */
    public static function order_type_label(array $order)
    {
        return self::is_credit_sale($order) ? 'Bán nợ' : 'Bán lẻ';
    }

    /**
     * Đơn có KHUYẾN MÃI / CHIẾT KHẤU? Dùng cho màn Danh sách.
     * - km: có dòng quà tặng (gift_type > 0) HOẶC có phiếu Z (tách hàng KM).
     * - ck: tổng chiết khấu dòng > 0.
     * Trả array('km'=>bool, 'ck'=>bool, 'z'=>bool, 'ck_amount'=>float).
     */
    public static function promo_discount_of(array $order)
    {
        $ledger_id = (int) ($order['local_ledger_id'] ?? 0);
        $ck = 0.0; $km = false;
        foreach (self::get_items($ledger_id) as $it) {
            $ck += (float) ($it['local_ledger_item_discount_amount'] ?? 0);
            if ((int) ($it['local_ledger_item_gift_type'] ?? 0) > 0) { $km = true; }
        }
        $z = self::has_bill_z_child($ledger_id);
        if ($z) { $km = true; }
        return array('km' => $km, 'ck' => $ck > 0, 'z' => $z, 'ck_amount' => $ck);
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

        $lines = self::map_lines($items);

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

        // LÝ DO XUẤT: lấy mã THẬT từ advance_meta (POS cho chọn XBA/XBB…), như tgs_pos đọc.
        // Không có -> mặc định 'XBA' (xuất bán).
        $ly_do_xuat = 'XBA';
        if (class_exists('TGS_POS_Export_Reason')) {
            $r = (string) TGS_POS_Export_Reason::read_from_meta($order['local_ledger_advance_meta'] ?? '');
            if ($r !== '') { $ly_do_xuat = $r; }
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
            'ly_do_xuat'  => $ly_do_xuat,
            'nguon_ban'   => $nguon_ban,           // tên nguồn thật ('' = HTsoft tự đặt)
            'ghi_chu'     => (string) ($order['local_ledger_note'] ?? ($order['local_ledger_title'] ?? '')),
            'lines'       => $lines,
            'payments'    => self::payments_of($order), // hình thức THẬT (cash/QR/…), không mặc định tiền mặt
            // Khối hóa đơn VAT (seri/mẫu số/số HĐ/thuế) để AddIn điền TAB THUẾ — null nếu chưa lập eVAT.
            'vat'         => self::vat_invoice_of((int) ($order['local_ledger_id'] ?? 0)),
            // Phiếu Z đi kèm: AddIn tạo HÓA ĐƠN THỨ HAI mã = <mã chính>+'Z' sau khi có mã chính. null nếu không tách.
            'bill_z'      => self::bill_z_of((int) ($order['local_ledger_id'] ?? 0)),
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
        // Phiếu Z KHÔNG queue riêng: nó đi kèm payload của phiếu gốc (AddIn tạo hóa đơn thứ hai mã +'Z').
        if (self::is_bill_z_ledger($ledger_id)) {
            return new WP_Error('IS_BILL_Z',
                'Đây là phiếu Z (mã …Z) — hãy queue phiếu gốc, phiếu Z sẽ đi kèm tự động.',
                array('status' => 422));
        }
        // Đã có mã HTsoft (đẩy trước đó) → KHÔNG queue lại, tránh tạo hóa đơn TRÙNG.
        // Vì mã POS giữ nguyên `BT…` (không override), nhận diện qua meta mapping.
        $adv = json_decode((string) ($order['local_ledger_advance_meta'] ?? ''), true);
        if (is_array($adv) && !empty($adv['htsoft']['bhdcode']) && (($adv['htsoft']['status'] ?? '') === 'ok')) {
            return new WP_Error('ALREADY_PUSHED',
                'Phiếu đã có mã HTsoft (' . (string) $adv['htsoft']['bhdcode'] . ') — không queue lại.',
                array('status' => 409));
        }
        // PHẢI CHỜ eVAT: đơn có hóa đơn eVAT đang chờ phát hành -> KHÔNG tạo job. Chờ cron
        // phát hành xong (invoice_state='done' + có số) rồi mới gửi, để payload đủ khối thuế.
        // Áp cho MỌI đường: nút thủ công, queue_now, sweep cron.
        if (self::evat_is_pending($ledger_id)) {
            return new WP_Error('EVAT_NOT_READY',
                'Hóa đơn eVAT chưa phát hành xong — chờ cron phát hành rồi mới tạo job HTsoft.',
                array('status' => 409));
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
     * ĐẨY LẠI CHỈ PHIẾU Z (tách khuyến mãi) của một đơn bán.
     *
     * Phiếu Z BẢN THÂN là một ledger bán (type 10) có mã riêng (<mã chính>+'Z') và dòng hàng
     * riêng, nên ta đẩy CHÍNH ledger Z đó qua ĐÚNG LUỒNG ĐƠN THƯỜNG 'create_retail_invoice'
     * (dùng build_payload như mọi đơn) — AddIn xử lý y hệt, KHÔNG cần action mới. Z nội bộ nên
     * payload tự có vat=null (không eVAT) và bill_z=null (Z không có Z con).
     *
     * $sale_ledger_id có thể là phiếu GỐC (tự tìm Z con) hoặc chính phiếu Z.
     * Là nút "đẩy lại" thủ công nên XÓA job cũ của mã Z rồi tạo mới (re-queue thật sự).
     * Trả kết quả TGS_Agent_Jobs::create() hoặc WP_Error.
     */
    public static function queue_bill_z_by_ledger_id($sale_ledger_id)
    {
        $sale_ledger_id = (int) $sale_ledger_id;
        if (!self::get_order($sale_ledger_id)) {
            return new WP_Error('NOT_FOUND', 'Không tìm thấy phiếu');
        }
        // Bấm trên phiếu gốc -> tìm Z con; bấm thẳng trên phiếu Z -> dùng luôn.
        $z_id = self::is_bill_z_ledger($sale_ledger_id)
            ? $sale_ledger_id
            : self::bill_z_ledger_id($sale_ledger_id);
        if ($z_id <= 0) {
            return new WP_Error('NO_BILL_Z', 'Đơn này không có phiếu Z (tách khuyến mãi).', array('status' => 422));
        }
        $z_order = self::get_order($z_id);
        if (!$z_order) {
            return new WP_Error('NO_BILL_Z', 'Không đọc được phiếu Z.', array('status' => 422));
        }
        $z_code = (string) ($z_order['local_ledger_code'] ?? '');
        if ($z_code === '') {
            return new WP_Error('NO_CODE', 'Phiếu Z thiếu local_ledger_code');
        }

        // Dựng payload Z y như đơn thường (lines từ phiếu xuất của Z; vat/bill_z tự = null).
        $z_items = self::get_items($z_id);
        $payload = self::build_payload($z_order, $z_items);

        if (empty($payload['srid'])) {
            return new WP_Error('NO_BRANCH',
                'Site chưa nối chi nhánh HTsoft (SRID) — không tạo queue. Cấu hình ở tgs-multisite-hierarchy.');
        }
        if (empty($payload['nvid'])) {
            $uid = (int) ($z_order['user_id'] ?? 0);
            return new WP_Error('NO_NV',
                'Người bán (user #' . $uid . ') chưa nối nhân viên HTsoft (NVID) — không tạo queue.');
        }
        if (empty($payload['lines'])) {
            return new WP_Error('NO_LINES', 'Phiếu Z không có dòng hàng hợp lệ — không tạo queue.');
        }

        $branch = (string) ($payload['branch_code'] ?? '');
        $key = get_current_blog_id() . ':create_retail_invoice:' . $z_code;
        // "Đẩy lại" → xóa job cũ của mã Z rồi tạo mới để thực sự re-queue.
        TGS_Agent_Jobs::delete_by_idempotency_key($key);

        return TGS_Agent_Jobs::create(array(
            'idempotency_key' => $key,
            'action'          => 'create_retail_invoice',
            'branch_code'     => $branch,
            'payload'         => $payload,
            'requested_by'    => 'admin_manual_z',
        ));
    }

    /**
     * AddIn tạo HĐ thành công → LƯU MAPPING mã POS ↔ mã HTsoft vào META, **KHÔNG override**
     * local_ledger_code (phiếu giữ nguyên mã POS `BT…`). Ghi vào `local_ledger_advance_meta['htsoft']`
     * — cùng chỗ tgs_pos dùng (push_order/VAT/offset đọc được, và chặn đẩy trùng qua đường SQL).
     * Lưu: bhdcode, bhdid, pos_ref (2 chiều), receipts (BPTTCODE), bhdcode_z, status='ok', via='addin'.
     * Idempotent: gọi lại chỉ cập nhật. Trả true nếu đã lưu.
     */
    public static function save_htsoft_mapping($pos_ref, $bhdcode, array $result = array())
    {
        $pos_ref = trim((string) $pos_ref);
        $bhdcode = trim((string) $bhdcode);
        if ($pos_ref === '' || $bhdcode === '') {
            return false;
        }
        global $wpdb;
        $lt = self::ledger_table();
        $type = (int) get_option('tgs_agent_ledger_sale_type', self::SALE_TYPE);
        if (!$wpdb->get_var("SHOW COLUMNS FROM `$lt` LIKE 'local_ledger_advance_meta'")) {
            return false; // site chưa có cột meta — không lưu được mapping
        }

        // Tìm phiếu theo mã POS; nếu đã lưu trước đó (mã POS giữ nguyên) vẫn tìm thấy.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT local_ledger_id, local_ledger_advance_meta FROM $lt
             WHERE local_ledger_code = %s AND local_ledger_type = %d
               AND (is_deleted IS NULL OR is_deleted = 0) LIMIT 1",
            $pos_ref, $type
        ));
        if (!$row) {
            return false;
        }

        $meta = json_decode((string) $row->local_ledger_advance_meta, true);
        $meta = is_array($meta) ? $meta : array();
        $htsoft = isset($meta['htsoft']) && is_array($meta['htsoft']) ? $meta['htsoft'] : array();
        $htsoft['bhdcode']   = $bhdcode;
        $htsoft['bhdid']     = isset($result['bhdid']) ? (string) $result['bhdid'] : (string) ($htsoft['bhdid'] ?? '');
        $htsoft['pos_ref']   = $pos_ref;              // giữ mã POS gốc → mapping 2 chiều
        $htsoft['status']    = 'ok';
        $htsoft['via']       = 'addin';
        $htsoft['pushed_at'] = current_time('mysql');
        if (!empty($result['receipts']) && is_array($result['receipts'])) {
            $htsoft['receipts'] = $result['receipts'];
        }
        if (!empty($result['bhdcode_z'])) {
            $htsoft['bhdcode_z'] = (string) $result['bhdcode_z'];
        }
        $meta['htsoft'] = $htsoft;

        $wpdb->update($lt,
            array(
                'local_ledger_advance_meta' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
                'updated_at'                => current_time('mysql'),
            ),
            array('local_ledger_id' => (int) $row->local_ledger_id)
        );

        // Cho tgs_pos reconcile phần còn lại (bill Z, phiếu thu BPTTCODE) nếu muốn — KHÔNG đổi mã.
        do_action('tgs_agent_invoice_reconciled', (int) $row->local_ledger_id, $bhdcode, $result, $pos_ref);
        return true;
    }

    /**
     * Hook tgs_after_order_create.
     *
     * ── VÌ SAO KHÔNG ENQUEUE NGAY Ở ĐÂY NỮA (đổi 2026-10-09) ────────────────
     * Trước kia tạo phiếu xong là enqueue luôn. Nhưng hóa đơn HTsoft nay tạo bằng
     * AddIn gói trọn một lần, trong payload có khối eVAT (vat_invoice_of). eVAT lại
     * được PHÁT HÀNH SAU lúc bán (bất đồng bộ qua tgs-viettel-invoice) -> nếu enqueue
     * ngay lúc tạo thì vat luôn rỗng, hóa đơn HTsoft LUÔN THIẾU phần thuế.
     *
     * Nay enqueue DỜI sang SAU khi eVAT đã phát hành (invoice_state='done'), do
     * sweep_evat_ready_orders() chạy theo WP-Cron đảm nhận. tgs-viettel-invoice KHÔNG
     * bắn action lúc phát hành nên phải quét thay vì bắt sự kiện. Ở đây chỉ bảo đảm
     * cron đã được lên lịch (phòng khi bật option sau khi plugin đã nạp).
     */
    public static function on_order_create($sale_ledger_id, $order_data = null, $products_data = null, $metas = null)
    {
        self::ensure_enqueue_sweep_scheduled();
    }

    /**
     * TẠO JOB cho 1 phiếu NGAY trong request (đồng bộ, không qua cron) nhưng VẪN CHỜ eVAT:
     * nếu eVAT chưa phát hành xong -> trả EVAT_NOT_READY, KHÔNG tạo job (gọi lại sau khi phát hành).
     * Dùng qua action: do_action('tgs_agent_queue_now', $ledger_id) — nên gọi SAU khi eVAT 'done'.
     * Áp mọi guard (eVAT/chi nhánh/NV/Z/đã-đẩy) + idempotent. Nuốt lỗi để KHÔNG làm hỏng luồng gọi.
     * Trả mảng kết quả.
     */
    public static function queue_now($ledger_id)
    {
        try {
            $res = self::queue_by_ledger_id((int) $ledger_id);
            if (is_wp_error($res)) {
                error_log('[TGS Agent] queue_now (ledger ' . (int) $ledger_id . '): ' . $res->get_error_message());
                return array('ok' => false, 'error' => $res->get_error_message());
            }
            return array('ok' => true) + (array) $res;
        } catch (\Throwable $e) {
            error_log('[TGS Agent] queue_now exception (ledger ' . (int) $ledger_id . '): ' . $e->getMessage());
            return array('ok' => false, 'error' => $e->getMessage());
        }
    }

    /** Lên lịch WP-Cron quét-enqueue nếu đang bật auto và chưa có lịch. */
    public static function ensure_enqueue_sweep_scheduled()
    {
        if (get_option('tgs_agent_pos_auto') != 1) {
            return;
        }
        // Nếu đã có lịch nhưng chu kỳ cũ (5 phút) -> gỡ để lên lại 2 phút.
        if (function_exists('wp_get_scheduled_event')) {
            $ev = wp_get_scheduled_event('tgs_agent_enqueue_sweep');
            if ($ev && isset($ev->schedule) && $ev->schedule !== 'tgs_agent_2min') {
                wp_unschedule_event($ev->timestamp, 'tgs_agent_enqueue_sweep');
            }
        }
        if (!wp_next_scheduled('tgs_agent_enqueue_sweep')) {
            wp_schedule_event(time() + 60, 'tgs_agent_2min', 'tgs_agent_enqueue_sweep');
        }
    }

    /**
     * QUÉT đơn bán đã PHÁT HÀNH eVAT và CHƯA đẩy HTsoft -> enqueue create_retail_invoice.
     *
     * Điều kiện:
     *   • phiếu bán POS (type 10, source POS), phiếu GỐC (không phải Z con),
     *   • eVAT KHÔNG còn đang phát hành: bản ghi eVAT mới nhất KHÔNG ở 'pending'/'issued'
     *     (gồm: không có eVAT, phát hành HỤT, hoặc 'done') — theo chính sách đã chốt,
     *   • chưa có mã HTsoft (advance_meta.htsoft.status != ok).
     * queue_by_ledger_id() tự idempotent + guard "đã đẩy"/"eVAT đang chờ" nên gọi lặp an toàn.
     *
     * @return int số job vừa enqueue
     */
    public static function sweep_evat_ready_orders($limit = 50)
    {
        if (get_option('tgs_agent_pos_auto') != 1) {
            return 0;
        }
        global $wpdb;
        $L = self::ledger_table();
        $V = $wpdb->prefix . 'local_viettel_invoice';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $V)) !== $V) {
            return 0; // site chưa dùng eVAT -> không có gì để quét
        }
        $sale_type  = (int) get_option('tgs_agent_ledger_sale_type', self::SALE_TYPE);
        $source_pos = defined('TGS_LEDGER_SOURCE_POS') ? (int) TGS_LEDGER_SOURCE_POS : 1;
        // CHỈ quét từ MỐC BẬT auto trở đi (không còn cửa sổ 7 ngày, không backfill quá khứ).
        // Thiếu mốc (auto bật trước khi có logic này) -> khởi tạo = bây giờ => bắt đầu từ lúc này.
        $from = (string) get_option('tgs_agent_pos_auto_since', '');
        if ($from === '') {
            $from = current_time('mysql');
            update_option('tgs_agent_pos_auto_since', $from);
        }
        $limit = max(1, min(200, (int) $limit));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.local_ledger_id
               FROM {$L} l
               LEFT JOIN {$V} vi ON vi.local_viettel_invoice_id = (
                     SELECT MAX(v2.local_viettel_invoice_id) FROM {$V} v2
                      WHERE v2.sale_ledger_id = l.local_ledger_id
                        AND (v2.is_deleted = 0 OR v2.is_deleted IS NULL))
              WHERE l.local_ledger_type = %d
                AND l.local_ledger_source = %d
                AND (l.is_deleted = 0 OR l.is_deleted IS NULL)
                AND (l.local_ledger_parent_id IS NULL OR l.local_ledger_parent_id = 0)
                AND l.created_at >= %s
                AND (vi.invoice_state IS NULL OR vi.invoice_state NOT IN ('pending','issued'))
                AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.local_ledger_advance_meta, '$.htsoft.status')), '') <> 'ok'
              ORDER BY l.local_ledger_id DESC
              LIMIT %d",
            $sale_type,
            $source_pos,
            $from,
            $limit
        ), ARRAY_A) ?: array();

        $n = 0;
        foreach ($rows as $r) {
            $res = self::queue_by_ledger_id((int) $r['local_ledger_id']);
            if (!is_wp_error($res)) {
                $n++;
            }
        }
        return $n;
    }

    /* =====================================================================
     * PHIẾU HOÀN (type 11) -> job create_return
     * ------------------------------------------------------------------
     * CHỈ hoàn THUẦN đi qua queue. Hoàn KÈM ĐỔI TRẢ vẫn đi đường trực tiếp
     * (TGS_POS_HTsoft_Invoice_Push) vì nó phải đẩy SAU phiếu bán mới rồi link
     * đối trừ BANHOANTRADOITRU — một luồng đồng bộ, đan xen, không tách rời ra
     * queue được mà không làm mồ côi phiếu hoàn.
     *
     * Khi bật option 'tgs_agent_return_auto': enqueue ở đây, đồng thời đường
     * trực tiếp TỰ TẮT cho nhánh hoàn thuần (xem on_return_committed của
     * class-tgs-pos-htsoft-invoice-push) -> không đẩy trùng.
     * ================================================================== */

    const RETURN_TYPE = 11; // TGS_LEDGER_TYPE_CUSTOMER_RETURN

    /** Hook tgs_pos_return_committed: auto enqueue phiếu hoàn THUẦN (nếu bật). */
    public static function on_return_committed($result, $args = array())
    {
        if (get_option('tgs_agent_return_auto') != 1) {
            return;
        }
        if (!is_array($result)) {
            return;
        }
        // Hoàn kèm đổi trả -> để đường trực tiếp lo (đẩy sau phiếu bán + đối trừ).
        if (is_array($args) && !empty($args['is_exchange'])) {
            return;
        }
        $rid = (int) ($result['return_ledger_id'] ?? 0);
        if ($rid > 0) {
            self::queue_return_by_ledger_id($rid);
        }
    }

    /**
     * Tạo job create_return từ 1 phiếu hoàn THUẦN (type 11).
     * Trả kết quả TGS_Agent_Jobs::create() hoặc WP_Error. Idempotent theo mã phiếu.
     */
    public static function queue_return_by_ledger_id($return_ledger_id)
    {
        $ret = self::get_order($return_ledger_id);
        if (!$ret) {
            return new WP_Error('NOT_FOUND', 'Không tìm thấy phiếu hoàn');
        }
        $return_type = (int) get_option('tgs_agent_ledger_return_type', self::RETURN_TYPE);
        if ((int) ($ret['local_ledger_type'] ?? 0) !== $return_type) {
            return new WP_Error('NOT_RETURN',
                'Phiếu không phải phiếu hoàn (type ' . $return_type . ').', array('status' => 422));
        }
        $code = (string) ($ret['local_ledger_code'] ?? '');
        if ($code === '') {
            return new WP_Error('NO_CODE', 'Phiếu hoàn thiếu local_ledger_code');
        }

        // GUARD CHỐNG TRÙNG: đã có mã HTsoft (đẩy trước đó, đường nào cũng vậy) ->
        // KHÔNG queue lại, tránh tạo phiếu hoàn HTsoft trùng. Mã HTsoft của phiếu
        // hoàn là BHTCODE, lưu trong advance_meta['htsoft'] — cùng chỗ push_return ghi.
        $adv = json_decode((string) ($ret['local_ledger_advance_meta'] ?? ''), true);
        if (is_array($adv) && !empty($adv['htsoft']['bhtcode']) && (($adv['htsoft']['status'] ?? '') === 'ok')) {
            return new WP_Error('ALREADY_PUSHED',
                'Phiếu hoàn đã có mã HTsoft (' . (string) $adv['htsoft']['bhtcode'] . ') — không queue lại.',
                array('status' => 409));
        }

        $items   = self::get_items($return_ledger_id);
        $payload = self::build_return_payload($ret, $items);

        // BẮT BUỘC: chi nhánh (site->SRID) + nhân viên (user->NVID) + có dòng hàng.
        if (empty($payload['srid'])) {
            return new WP_Error('NO_BRANCH',
                'Site chưa nối chi nhánh HTsoft (SRID) — không tạo queue hoàn. Cấu hình ở tgs-multisite-hierarchy.');
        }
        if (empty($payload['nvid'])) {
            $uid = (int) ($ret['user_id'] ?? 0);
            return new WP_Error('NO_NV',
                'Người tạo phiếu hoàn (user #' . $uid . ') chưa nối nhân viên HTsoft (NVID) — không tạo queue.');
        }
        if (empty($payload['lines'])) {
            return new WP_Error('NO_LINES', 'Phiếu hoàn không có dòng hàng hợp lệ — không tạo queue.');
        }

        return TGS_Agent_Jobs::create(array(
            'idempotency_key' => get_current_blog_id() . ':create_return:' . $code,
            'action'          => 'create_return',
            'branch_code'     => (string) $payload['branch_code'],
            'payload'         => $payload,
            'requested_by'    => 'tgs_pos',
        ));
    }

    /**
     * Build payload cho job create_return từ 1 phiếu hoàn (type 11).
     *
     * Dùng CHUNG map_lines() với đơn bán -> AddIn đọc dòng hàng y như
     * create_retail_invoice (KHÔNG dùng build_lines của đường connector). Các khoá
     * hoàn riêng (bhdcode_goc, ldn_code, HTTT header, refund) khớp push_return của
     * tgs_pos để AddIn dựng BANHOANTRA + (tuỳ chọn) BANCTHOANTRA đúng như tay.
     */
    public static function build_return_payload(array $ret, array $items)
    {
        $code = (string) ($ret['local_ledger_code'] ?? '');
        $rid  = (int) ($ret['local_ledger_id'] ?? 0);

        $srid = self::resolve_srid();
        $nv   = self::resolve_nv($ret['user_id'] ?? 0);

        // Mã hóa đơn bán GỐC (nếu phiếu bán cha đã đẩy HTsoft) -> hoàn theo phiếu;
        // không có -> hoàn tự do (null, khớp contract create_return §9.8).
        $bhdcode_goc = '';
        $parent_id = (int) ($ret['local_ledger_parent_id'] ?? 0);
        if ($parent_id > 0) {
            $parent = self::get_order($parent_id);
            if ($parent) {
                $padv = json_decode((string) ($parent['local_ledger_advance_meta'] ?? ''), true);
                if (is_array($padv) && !empty($padv['htsoft']['bhdcode'])) {
                    $bhdcode_goc = (string) $padv['htsoft']['bhdcode'];
                }
            }
        }

        $person_meta = json_decode((string) ($ret['local_ledger_person_meta'] ?? ''), true);
        if (!is_array($person_meta)) { $person_meta = array(); }
        $khach = array(
            'ma'      => (string) ($ret['local_ledger_person_code'] ?? ($person_meta['code'] ?? '')) ?: null,
            'ten'     => (string) ($ret['local_ledger_person_name'] ?? ($person_meta['name'] ?? '')),
            'di_dong' => (string) ($ret['local_ledger_person_phone'] ?? ($person_meta['phone'] ?? '')),
        );

        // Có HĐ gốc -> AddIn nạp hàng từ HĐ gốc, giaban=null để GIỮ GIÁ HTsoft
        // (tránh lệch tròn < 1đ -> VALIDATION_ERROR, xem §9.10). Hoàn tự do -> gửi
        // giá hoàn/ĐVT gốc GỒM THUẾ (như create_retail_invoice).
        $lines = self::map_return_lines($items, $bhdcode_goc !== '');
        $total = round((float) ($ret['local_ledger_total_amount'] ?? 0));

        // Payload KHỚP contract create_return (§9.8). 'srid'/'nvid' là khoá NỘI BỘ
        // (routing + guard), AddIn bỏ qua; AddIn dùng 'nv_code'.
        $payload = array(
            'pos_ref'     => $code,
            'branch_code' => $srid ?: '',            // = SRID (AddIn claim đúng chi nhánh)
            'srid'        => $srid,
            'nvid'        => $nv['nvid'],
            'nv_code'     => $nv['nv_code'],
            'khach'       => $khach,                  // dùng khi KHÔNG có bhdcode_goc
            'bhdcode_goc' => $bhdcode_goc !== '' ? $bhdcode_goc : null, // null = hoàn tự do
            // Lý do nhập lại HTsoft (mặc định 'NTH1' — khớp push_return của tgs_pos).
            'ly_do'       => (string) apply_filters('tgs_pos_htsoft_return_reason_code', 'NTH1'),
            'ghi_chu'     => mb_substr((string) ($ret['local_ledger_note'] ?? ''), 0, 100),
            'lines'       => $lines,
            'expected'    => array('tong_tien' => $total, 'so_dong' => count($lines)),
            // Kế toán: hoàn hàng KHÔNG chi tiền (đổi hàng) -> HTsoft giữ "còn nợ khách".
            // false = không tạo BANCTHOANTRA. HTTT header "Công nợ" do AddIn mặc định.
            'refund'      => false,
            // Hoàn THUẦN -> không đối trừ (đối trừ là của luồng đổi trả, đi đường trực tiếp).
            'doi_tru'     => array(),
        );

        /**
         * Tinh chỉnh payload hoàn theo dữ liệu thật (giữ nơi hiểu ledger), song song
         * với 'tgs_agent_ledger_to_payload' của đơn bán.
         * @param array $payload  payload mặc định
         * @param array $ret      dòng local_ledger của phiếu hoàn
         * @param array $items    dòng local_ledger_item của phiếu hoàn
         */
        return apply_filters('tgs_agent_return_to_payload', $payload, $ret, $items);
    }

    /**
     * Dòng hàng cho create_return: {mhcode, soluong, giaban} theo contract §9.8.
     * SOLUONG theo ĐVT gốc (nhỏ nhất). giaban = giá hoàn/ĐVT gốc GỒM THUẾ, hoặc null
     * để AddIn GIỮ giá HTsoft (khi hoàn theo HĐ gốc). Bỏ dòng thiếu sku / SL<=0.
     *
     * @param bool $keep_htsoft_price true -> giaban=null (có bhdcode_goc)
     */
    private static function map_return_lines(array $items, $keep_htsoft_price)
    {
        $lines = array();
        foreach ($items as $it) {
            $sku = trim((string) ($it['local_product_sku'] ?? ''));
            $sl  = (float) ($it['quantity'] ?? 0);
            if ($sku === '' || $sl <= 0) { continue; }
            $lines[] = array(
                'mhcode'  => $sku,
                'soluong' => $sl,
                'giaban'  => $keep_htsoft_price
                    ? null
                    : round((float) ($it['local_ledger_item_price_after_discount'] ?? 0)),
                'ghi_chu' => (string) ($it['local_ledger_item_note'] ?? ''),
            );
        }
        return $lines;
    }
}
