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
            // DÒNG ĐỐI TRỪ (đổi trả) KHÔNG phải tiền thật — đối trừ ở sổ công nợ, không lập phiếu thu.
            // Bỏ qua như tgs_pos payments_of(). Chỉ các khoản khách TRẢ THẬT (tiền mặt/QR…) mới đẩy.
            if (!empty($r['offset_netting'])) { continue; }
            $amt = round((float) ($r['amount'] ?? 0));
            if ($amt <= 0) { continue; }
            $method = strtolower(trim((string) ($r['method'] ?? '')));
            $label  = (string) ($r['label'] ?? '');
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

    /** Hook tgs_after_order_create: auto tạo job nếu bật. */
    public static function on_order_create($sale_ledger_id, $order_data = null, $products_data = null, $metas = null)
    {
        if (get_option('tgs_agent_pos_auto') != 1) {
            return;
        }
        self::queue_by_ledger_id((int) $sale_ledger_id);
    }
}
