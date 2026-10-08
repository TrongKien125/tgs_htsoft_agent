<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Hook WooCommerce: khi đơn chuyển sang trạng thái cấu hình -> tạo job create_retail_invoice.
 * MẶC ĐỊNH TẮT. Bật: option 'tgs_agent_woo_enabled' = 1.
 * Cấu hình (option):
 *   tgs_agent_woo_enabled    (0/1)
 *   tgs_agent_woo_status     trạng thái kích hoạt, mặc định 'processing'
 *   tgs_agent_woo_branch     branch_code gửi kèm (mặc định '')
 *   tgs_agent_woo_mh_meta    meta key của product chứa MHCODE HTsoft, mặc định '_htsoft_mhcode'
 *
 * LƯU Ý: mapping sản phẩm Woo -> MHCODE phụ thuộc dữ liệu thực tế (xem §6 Q3). Sản phẩm thiếu
 * MHCODE sẽ bị bỏ qua và job bị đánh dấu thiếu dòng. Hoàn thiện theo site cụ thể.
 */
class TGS_Agent_Woo
{
    public static function init()
    {
        if (get_option('tgs_agent_woo_enabled') != 1) {
            return;
        }
        $status = get_option('tgs_agent_woo_status', 'processing');
        add_action('woocommerce_order_status_' . $status, array(__CLASS__, 'on_order'), 10, 1);
    }

    public static function on_order($order_id)
    {
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // BẮT BUỘC: chi nhánh (site→SRID) + nhân viên (user→NVID). Thiếu → KHÔNG tạo job.
        $srid = class_exists('TGS_Agent_Source') ? TGS_Agent_Source::resolve_srid() : null;
        if (!$srid) { return; }
        $nv = TGS_Agent_Source::resolve_nv($order->get_user_id());
        if (empty($nv['nvid'])) { return; }
        $branch = $srid;
        $mh_meta = (string) get_option('tgs_agent_woo_mh_meta', '_htsoft_mhcode');

        $lines = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $mhcode = $product ? $product->get_meta($mh_meta) : '';
            if (!$mhcode) {
                continue; // thiếu MHCODE -> bỏ qua dòng (hoàn thiện theo site)
            }
            $qty = (float) $item->get_quantity();
            $total = (float) $order->get_item_total($item, false, true); // đơn giá gồm thuế
            $lines[] = array(
                'mhcode'    => (string) $mhcode,
                'mhid'      => null,
                'kho_code'  => null,
                'soluong'   => $qty,
                'giaban'    => round($total),
                'chietkhau' => 0,
                'ghi_chu'   => '',
                'imei'      => array(),
            );
        }

        $pos_ref = $order->get_order_number();
        $payload = array(
            'pos_ref'     => (string) $pos_ref,
            'branch_code' => $branch,
            'ngay_hd'     => $order->get_date_created() ? $order->get_date_created()->format('c') : null,
            'khach'       => array(
                'ma'      => null,
                'ten'     => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'di_dong' => $order->get_billing_phone(),
                'dia_chi' => $order->get_billing_address_1(),
                'email'   => $order->get_billing_email(),
            ),
            'srid'        => $srid,
            'nvid'        => $nv['nvid'],
            'nv_code'     => $nv['nv_code'],
            'ly_do_xuat'  => 'XBA',
            'nguon_ban'   => 'Gần shop',
            'ghi_chu'     => 'Woo #' . $order_id,
            'lines'       => $lines,
            'payments'    => array(array('hinh_thuc' => 'tien_mat', 'amount' => round((float) $order->get_total()))),
            'expected'    => array('tong_tien' => round((float) $order->get_total()), 'so_dong' => count($lines)),
        );

        $blog_id = get_current_blog_id();
        TGS_Agent_Jobs::create(array(
            'idempotency_key' => $blog_id . ':create_retail_invoice:' . $pos_ref,
            'action'          => 'create_retail_invoice',
            'branch_code'     => $branch,
            'payload'         => $payload,
            'requested_by'    => 'woocommerce',
        ));
    }
}
