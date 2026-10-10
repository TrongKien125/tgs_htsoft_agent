<?php
/**
 * Plugin Name: TGS HTsoft Agent (Job Broker)
 * Description: Hàng đợi job cho TGS HTsoft AddIn — nhận đơn từ POS/WooCommerce, cấp cho AddIn qua REST (HMAC), nhận kết quả. Xem TGS_ADDIN_KE_HOACH_WORDPRESS.md.
 * Version: 0.1.0
 * Author: TGS
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TGS_AGENT_VER', '0.1.0');
define('TGS_AGENT_DIR', plugin_dir_path(__FILE__));
define('TGS_AGENT_URL', plugin_dir_url(__FILE__));
define('TGS_AGENT_NS', 'tgs-htsoft-agent/v1');

// Ngưỡng an toàn HMAC.
if (!defined('TGS_AGENT_SKEW_SECONDS')) {
    define('TGS_AGENT_SKEW_SECONDS', 300);
}
if (!defined('TGS_AGENT_NONCE_TTL')) {
    define('TGS_AGENT_NONCE_TTL', 600);
}
// Job claimed quá hạn này không heartbeat -> unknown (không tự cấp lại).
if (!defined('TGS_AGENT_LEASE_SECONDS')) {
    define('TGS_AGENT_LEASE_SECONDS', 900);
}

require_once TGS_AGENT_DIR . 'includes/class-tgs-agent-config.php';
require_once TGS_AGENT_DIR . 'includes/class-tgs-agent-db.php';
require_once TGS_AGENT_DIR . 'includes/class-tgs-agent-auth.php';
require_once TGS_AGENT_DIR . 'includes/class-tgs-agent-jobs.php';
require_once TGS_AGENT_DIR . 'includes/class-tgs-agent-source.php';
require_once TGS_AGENT_DIR . 'includes/rest/class-tgs-agent-rest.php';
require_once TGS_AGENT_DIR . 'includes/class-tgs-agent-admin.php';
require_once TGS_AGENT_DIR . 'includes/class-tgs-agent-woo.php';

// Tạo/cập nhật bảng khi kích hoạt (chỉ cho blog đang active lúc đó trên multisite).
register_activation_hook(__FILE__, array('TGS_Agent_DB', 'install'));

// Multisite: subsite mới -> tạo bảng ngay trong ngữ cảnh site đó. Các request về sau
// vẫn có maybe_install() bọc lót, nhưng tạo sẵn ở đây đỡ phải vá lúc chạy.
add_action('wp_insert_site', function ($new_site) {
    if (!is_multisite()) {
        return;
    }
    switch_to_blog((int) $new_site->blog_id);
    try {
        TGS_Agent_DB::install();
    } finally {
        restore_current_blog();
    }
});

// Đăng ký REST.
add_action('rest_api_init', array('TGS_Agent_REST', 'register_routes'));

// Trang quản trị.
add_action('admin_menu', array('TGS_Agent_Admin', 'register_menu'));
add_action('admin_post_tgs_agent_requeue', array('TGS_Agent_Admin', 'handle_requeue'));
add_action('admin_post_tgs_agent_queue_order', array('TGS_Agent_Admin', 'handle_queue_order'));
add_action('admin_post_tgs_agent_queue_bill_z', array('TGS_Agent_Admin', 'handle_queue_bill_z'));
add_action('admin_post_tgs_agent_recreate_job', array('TGS_Agent_Admin', 'handle_recreate_job'));
add_action('admin_post_tgs_agent_delete_job', array('TGS_Agent_Admin', 'handle_delete_job'));

// Auto tạo job khi tgs_pos tạo phiếu bán (mặc định TẮT — bật bằng option 'tgs_agent_pos_auto').
// LƯU Ý: không enqueue NGAY lúc tạo nữa — chờ eVAT phát hành (xem on_order_create +
// sweep_evat_ready_orders). Hook này giờ chỉ bảo đảm WP-Cron quét đã được lên lịch.
add_action('tgs_after_order_create', array('TGS_Agent_Source', 'on_order_create'), 20, 4);

// Tạo job cho 1 phiếu NGAY trong request (đồng bộ): do_action('tgs_agent_queue_now', $ledger_id).
// Không phụ thuộc option auto / cron, NHƯNG VẪN CHỜ eVAT: eVAT chưa phát hành xong -> EVAT_NOT_READY.
add_action('tgs_agent_queue_now', array('TGS_Agent_Source', 'queue_now'), 10, 1);

// WP-Cron: quét đơn đã PHÁT HÀNH eVAT + chưa đẩy -> enqueue create_retail_invoice.
// Chạy sau lúc bán nên payload có khối VAT (vat_invoice_of) -> hóa đơn HTsoft đủ thuế.
add_filter('cron_schedules', function ($s) {
    if (!isset($s['tgs_agent_2min'])) {
        $s['tgs_agent_2min'] = array('interval' => 120, 'display' => 'TGS Agent mỗi 2 phút');
    }
    return $s;
});
add_action('tgs_agent_enqueue_sweep', array('TGS_Agent_Source', 'sweep_evat_ready_orders'));
add_action('init', array('TGS_Agent_Source', 'ensure_enqueue_sweep_scheduled'));
// Gỡ lịch khi tắt plugin (chỉ cho blog đang active lúc đó).
register_deactivation_hook(__FILE__, function () {
    $ts = wp_next_scheduled('tgs_agent_enqueue_sweep');
    if ($ts) {
        wp_unschedule_event($ts, 'tgs_agent_enqueue_sweep');
    }
});

// Auto tạo job create_return khi tgs_pos commit phiếu hoàn THUẦN (mặc định TẮT — bật bằng
// option 'tgs_agent_return_auto'). Bật option này cũng TỰ TẮT đường đẩy trực tiếp cho hoàn
// thuần (xem TGS_POS_HTsoft_Invoice_Push::on_return_committed) -> không đẩy trùng.
add_action('tgs_pos_return_committed', array('TGS_Agent_Source', 'on_return_committed'), 20, 2);

// Hook WooCommerce (mặc định TẮT — bật bằng option 'tgs_agent_woo_enabled').
TGS_Agent_Woo::init();
