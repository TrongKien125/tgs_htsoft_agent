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
require_once TGS_AGENT_DIR . 'includes/rest/class-tgs-agent-rest.php';
require_once TGS_AGENT_DIR . 'includes/class-tgs-agent-admin.php';
require_once TGS_AGENT_DIR . 'includes/class-tgs-agent-woo.php';

// Tạo/cập nhật bảng khi kích hoạt.
register_activation_hook(__FILE__, array('TGS_Agent_DB', 'install'));

// Đăng ký REST.
add_action('rest_api_init', array('TGS_Agent_REST', 'register_routes'));

// Trang quản trị.
add_action('admin_menu', array('TGS_Agent_Admin', 'register_menu'));
add_action('admin_post_tgs_agent_requeue', array('TGS_Agent_Admin', 'handle_requeue'));

// Hook WooCommerce (mặc định TẮT — bật bằng option 'tgs_agent_woo_enabled').
TGS_Agent_Woo::init();
