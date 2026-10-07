<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Xác thực HMAC cho REST — khớp JobClient.cs của AddIn:
 * signature = hex(HMAC-SHA256(secret, METHOD \n PATH \n TS \n NONCE \n hex(SHA256(body)))).
 * PATH = phần path của URL (không query), đúng như client ký.
 */
class TGS_Agent_Auth
{
    /** Client đã xác thực cho request hiện tại. */
    public static $current = null;

    /**
     * permission_callback cho REST. Trả true hoặc WP_Error(401).
     */
    public static function rest_permission(WP_REST_Request $request)
    {
        $client_id = (string) $request->get_header('X-TGS-Client-ID');
        $ts        = (string) $request->get_header('X-TGS-Timestamp');
        $nonce     = (string) $request->get_header('X-TGS-Nonce');
        $sig       = (string) $request->get_header('X-TGS-Signature');

        if ($client_id === '' || $ts === '' || $nonce === '' || $sig === '') {
            return self::deny('Thiếu header xác thực.');
        }

        $client = TGS_Agent_Config::get_client($client_id);
        if (!$client || $client['secret'] === '') {
            return self::deny('Client không hợp lệ.');
        }

        if (!ctype_digit($ts)) {
            return self::deny('Timestamp sai định dạng.');
        }
        if (abs(time() - (int) $ts) > TGS_AGENT_SKEW_SECONDS) {
            return self::deny('Timestamp lệch quá giới hạn.');
        }

        $method    = strtoupper($request->get_method());
        $path      = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
        $path      = $path === null ? '' : $path;
        $body      = $request->get_body();
        $body_hash = hash('sha256', $body === null ? '' : $body);

        $canonical = implode("\n", array($method, $path, $ts, $nonce, $body_hash));
        $expected  = hash_hmac('sha256', $canonical, $client['secret']);

        if (!hash_equals($expected, strtolower($sig))) {
            return self::deny('Chữ ký không hợp lệ.');
        }

        if (!TGS_Agent_DB::use_nonce($nonce, $client_id, TGS_AGENT_NONCE_TTL)) {
            return self::deny('Nonce đã được dùng (replay).');
        }

        self::$current = array_merge($client, array('client_id' => $client_id));
        return true;
    }

    private static function deny($message)
    {
        return new WP_Error('AUTH_FAILED', $message, array('status' => 401));
    }
}
