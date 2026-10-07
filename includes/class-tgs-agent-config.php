<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Đọc danh sách client + secret. Ưu tiên hằng số TGS_AGENT_CLIENTS (wp-config),
 * rồi tới config.php (không commit).
 */
class TGS_Agent_Config
{
    private static $clients = null;

    private static function load()
    {
        if (self::$clients !== null) {
            return;
        }
        $clients = array();

        if (defined('TGS_AGENT_CLIENTS') && is_array(TGS_AGENT_CLIENTS)) {
            $clients = TGS_AGENT_CLIENTS;
        } else {
            $file = TGS_AGENT_DIR . 'config.php';
            if (file_exists($file)) {
                $data = include $file;
                if (is_array($data)) {
                    $clients = $data;
                }
            }
        }
        self::$clients = $clients;
    }

    /** Trả cấu hình client hoặc null. */
    public static function get_client($client_id)
    {
        self::load();
        if ($client_id === '' || !isset(self::$clients[$client_id])) {
            return null;
        }
        $c = self::$clients[$client_id];
        return array(
            'secret'   => isset($c['secret']) ? (string) $c['secret'] : '',
            'branches' => isset($c['branches']) && is_array($c['branches']) ? $c['branches'] : array('*'),
        );
    }

    public static function client_allows_branch($client, $branch_code)
    {
        if (!$client) {
            return false;
        }
        $b = $client['branches'];
        return in_array('*', $b, true) || in_array($branch_code, $b, true);
    }
}
