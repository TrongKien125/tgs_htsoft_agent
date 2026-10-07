<?php
if (!defined('ABSPATH')) { exit; }

/**
 * REST routes, namespace tgs-htsoft-agent/v1. Tất cả xác thực HMAC (TGS_Agent_Auth).
 */
class TGS_Agent_REST
{
    public static function register_routes()
    {
        $ns = TGS_AGENT_NS;
        $auth = array('TGS_Agent_Auth', 'rest_permission');

        register_rest_route($ns, '/ping', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'ping'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/jobs/claim', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'claim'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/jobs', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'create'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/jobs/(?P<id>[A-Za-z0-9\-]+)/result', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'result'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/jobs/(?P<id>[A-Za-z0-9\-]+)/heartbeat', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'heartbeat'),
            'permission_callback' => $auth,
        ));
    }

    public static function ping(WP_REST_Request $req)
    {
        return new WP_REST_Response(array(
            'ok'          => true,
            'server_time' => gmdate('c'),
            'client_id'   => TGS_Agent_Auth::$current['client_id'],
        ), 200);
    }

    public static function claim(WP_REST_Request $req)
    {
        $body = json_decode($req->get_body(), true);
        $branch = isset($body['branch_code']) ? (string) $body['branch_code'] : '';
        if ($branch === '') {
            return self::err('VALIDATION_ERROR', 'Thiếu branch_code', 422);
        }
        if (!TGS_Agent_Config::client_allows_branch(TGS_Agent_Auth::$current, $branch)) {
            return self::err('BRANCH_FORBIDDEN', 'Client không được phép chi nhánh này', 403);
        }
        $row = TGS_Agent_Jobs::claim_next($branch);
        if (!$row) {
            return new WP_REST_Response(new stdClass(), 200); // {} = không có việc
        }
        return new WP_REST_Response(TGS_Agent_Jobs::to_public($row), 200);
    }

    public static function create(WP_REST_Request $req)
    {
        $body = json_decode($req->get_body(), true);
        if (!is_array($body)) {
            return self::err('VALIDATION_ERROR', 'Body không hợp lệ', 422);
        }
        $branch = isset($body['payload']['branch_code']) ? (string) $body['payload']['branch_code']
                : (isset($body['branch_code']) ? (string) $body['branch_code'] : '');
        if ($branch !== '' && !TGS_Agent_Config::client_allows_branch(TGS_Agent_Auth::$current, $branch)) {
            return self::err('BRANCH_FORBIDDEN', 'Client không được phép chi nhánh này', 403);
        }
        $res = TGS_Agent_Jobs::create(array(
            'idempotency_key' => isset($body['idempotency_key']) ? $body['idempotency_key'] : '',
            'action'          => isset($body['action']) ? $body['action'] : 'create_retail_invoice',
            'branch_code'     => $branch,
            'payload'         => isset($body['payload']) ? $body['payload'] : array(),
            'requested_by'    => TGS_Agent_Auth::$current['client_id'],
        ));
        if (is_wp_error($res)) {
            return self::from_wp_error($res);
        }
        return new WP_REST_Response($res, $res['created'] ? 202 : 200);
    }

    public static function result(WP_REST_Request $req)
    {
        $job_id = $req->get_param('id');
        $body = json_decode($req->get_body(), true);
        if (!is_array($body)) {
            return self::err('VALIDATION_ERROR', 'Body không hợp lệ', 422);
        }
        $res = TGS_Agent_Jobs::set_result($job_id, $body);
        if (is_wp_error($res)) {
            return self::from_wp_error($res);
        }
        return new WP_REST_Response($res, 200);
    }

    public static function heartbeat(WP_REST_Request $req)
    {
        $job_id = $req->get_param('id');
        $ok = TGS_Agent_Jobs::heartbeat($job_id);
        return new WP_REST_Response(array('ok' => $ok), $ok ? 200 : 409);
    }

    private static function err($code, $msg, $status)
    {
        return new WP_REST_Response(array('error' => array('code' => $code, 'message' => $msg)), $status);
    }

    private static function from_wp_error(WP_Error $e)
    {
        $data = $e->get_error_data();
        $status = is_array($data) && isset($data['status']) ? $data['status'] : 400;
        return self::err($e->get_error_code(), $e->get_error_message(), $status);
    }
}
