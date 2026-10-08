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

// Auto tạo job khi tgs_pos tạo phiếu bán (mặc định TẮT — bật bằng option 'tgs_agent_pos_auto').
add_action('tgs_after_order_create', array('TGS_Agent_Source', 'on_order_create'), 20, 4);

// Auto tạo job create_return khi tgs_pos commit phiếu hoàn THUẦN (mặc định TẮT — bật bằng
// option 'tgs_agent_return_auto'). Bật option này cũng TỰ TẮT đường đẩy trực tiếp cho hoàn
// thuần (xem TGS_POS_HTsoft_Invoice_Push::on_return_committed) -> không đẩy trùng.
add_action('tgs_pos_return_committed', array('TGS_Agent_Source', 'on_return_committed'), 20, 2);

// Hook WooCommerce (mặc định TẮT — bật bằng option 'tgs_agent_woo_enabled').
TGS_Agent_Woo::init();
