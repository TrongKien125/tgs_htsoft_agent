<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Nghiệp vụ job: tạo (idempotent), claim (atomic), nhận kết quả, heartbeat, dọn lease.
 * Vòng đời: queued -> claimed -> completed | cancelled | failed | unknown.
 */
class TGS_Agent_Jobs
{
    const STATUSES = array('queued', 'claimed', 'completed', 'cancelled', 'failed', 'unknown');

    /**
     * Tạo job. Idempotent theo idempotency_key.
     * $args: idempotency_key, action, branch_code, payload (array|string), requested_by?
     * Trả array('job_id','status','created'(bool)) hoặc WP_Error.
     */
    public static function create(array $args)
    {
        global $wpdb;
        $table = TGS_Agent_DB::jobs_table();

        $key = isset($args['idempotency_key']) ? trim((string) $args['idempotency_key']) : '';
        $action = isset($args['action']) ? trim((string) $args['action']) : 'create_retail_invoice';
        $branch = isset($args['branch_code']) ? trim((string) $args['branch_code']) : '';
        if ($key === '') {
            return new WP_Error('VALIDATION_ERROR', 'Thiếu idempotency_key', array('status' => 422));
        }
        $payload = isset($args['payload']) ? $args['payload'] : array();
        $payload_json = is_string($payload) ? $payload : wp_json_encode($payload);

        // Đã có key -> trả job cũ (không tạo trùng).
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT job_id, status FROM $table WHERE idempotency_key = %s", $key
        ));
        if ($existing) {
            return array('job_id' => $existing->job_id, 'status' => $existing->status, 'created' => false);
        }

        $now = current_time('mysql', true);
        $job_id = wp_generate_uuid4();
        $ok = $wpdb->insert($table, array(
            'job_id'          => $job_id,
            'idempotency_key' => $key,
            'branch_code'     => $branch,
            'action'          => $action,
            'payload_json'    => $payload_json,
            'status'          => 'queued',
            'requested_by'    => isset($args['requested_by']) ? (string) $args['requested_by'] : null,
            'attempt_count'   => 0,
            'created_at'      => $now,
            'updated_at'      => $now,
        ));
        if ($ok === false) {
            // Có thể do race chèn trùng key -> đọc lại.
            $again = $wpdb->get_row($wpdb->prepare(
                "SELECT job_id, status FROM $table WHERE idempotency_key = %s", $key
            ));
            if ($again) {
                return array('job_id' => $again->job_id, 'status' => $again->status, 'created' => false);
            }
            return new WP_Error('DB_ERROR', 'Không tạo được job', array('status' => 500));
        }
        return array('job_id' => $job_id, 'status' => 'queued', 'created' => true);
    }

    /** Dọn job claimed quá lease không heartbeat -> unknown (không tự cấp lại). */
    public static function expire_stale($branch_code = null)
    {
        global $wpdb;
        $table = TGS_Agent_DB::jobs_table();
        $cutoff = gmdate('Y-m-d H:i:s', time() - (int) TGS_AGENT_LEASE_SECONDS);
        $now = current_time('mysql', true);
        if ($branch_code === null) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $table SET status='unknown', error_code='LEASE_EXPIRED', updated_at=%s
                 WHERE status='claimed' AND (heartbeat_at IS NULL OR heartbeat_at < %s)",
                $now, $cutoff
            ));
        } else {
            $wpdb->query($wpdb->prepare(
                "UPDATE $table SET status='unknown', error_code='LEASE_EXPIRED', updated_at=%s
                 WHERE status='claimed' AND branch_code=%s AND (heartbeat_at IS NULL OR heartbeat_at < %s)",
                $now, $branch_code, $cutoff
            ));
        }
    }

    /**
     * Claim một job queued của chi nhánh (atomic). Trả object job hoặc null.
     */
    public static function claim_next($branch_code)
    {
        global $wpdb;
        $table = TGS_Agent_DB::jobs_table();

        self::expire_stale($branch_code);

        $wpdb->query('START TRANSACTION');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE status='queued' AND branch_code=%s
             ORDER BY created_at ASC LIMIT 1 FOR UPDATE",
            $branch_code
        ));
        if (!$row) {
            $wpdb->query('COMMIT');
            return null;
        }
        $now = current_time('mysql', true);
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET status='claimed', claimed_at=%s, heartbeat_at=%s,
             attempt_count=attempt_count+1, updated_at=%s WHERE id=%d",
            $now, $now, $now, $row->id
        ));
        $wpdb->query('COMMIT');

        $row->status = 'claimed';
        return $row;
    }

    public static function heartbeat($job_id)
    {
        global $wpdb;
        $table = TGS_Agent_DB::jobs_table();
        $now = current_time('mysql', true);
        $n = $wpdb->query($wpdb->prepare(
            "UPDATE $table SET heartbeat_at=%s, updated_at=%s WHERE job_id=%s AND status='claimed'",
            $now, $now, $job_id
        ));
        return $n > 0;
    }

    /**
     * Nhận kết quả từ AddIn. $result: status, bhdid?, bhdcode?, error?, info?, raw(array)?
     */
    public static function set_result($job_id, array $result)
    {
        global $wpdb;
        $table = TGS_Agent_DB::jobs_table();

        $status = isset($result['status']) ? (string) $result['status'] : '';
        $allowed = array('completed', 'cancelled', 'failed', 'unknown');
        if (!in_array($status, $allowed, true)) {
            return new WP_Error('VALIDATION_ERROR', 'status không hợp lệ', array('status' => 422));
        }
        $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE job_id=%s", $job_id));
        if (!$job) {
            return new WP_Error('JOB_NOT_FOUND', 'Không tìm thấy job', array('status' => 404));
        }
        // Chỉ nhận kết quả khi đang claimed (hoặc unknown để đối soát muộn).
        if (!in_array($job->status, array('claimed', 'unknown'), true)) {
            return new WP_Error('INVALID_STATE', 'Job không ở trạng thái nhận kết quả', array('status' => 409));
        }

        $now = current_time('mysql', true);
        $wpdb->update($table, array(
            'status'        => $status,
            'bhdid'         => isset($result['bhdid']) ? (string) $result['bhdid'] : null,
            'bhdcode'       => isset($result['bhdcode']) ? (string) $result['bhdcode'] : null,
            'error_code'    => isset($result['error']) ? (string) $result['error'] : null,
            'error_message' => isset($result['info']) ? (string) $result['info'] : null,
            'result_json'   => wp_json_encode($result),
            'updated_at'    => $now,
        ), array('job_id' => $job_id));

        return array('ok' => true, 'status' => $status);
    }

    /** Đưa job failed/cancelled về queued để chạy lại (chỉ dùng từ admin). */
    public static function requeue($job_id)
    {
        global $wpdb;
        $table = TGS_Agent_DB::jobs_table();
        $job = $wpdb->get_row($wpdb->prepare("SELECT status FROM $table WHERE job_id=%s", $job_id));
        if (!$job) {
            return false;
        }
        if (!in_array($job->status, array('failed', 'cancelled'), true)) {
            return false;
        }
        $now = current_time('mysql', true);
        $wpdb->update($table, array(
            'status' => 'queued', 'error_code' => null, 'error_message' => null,
            'claimed_at' => null, 'heartbeat_at' => null, 'updated_at' => $now,
        ), array('job_id' => $job_id));
        return true;
    }

    public static function to_public($row)
    {
        return array(
            'job_id'          => $row->job_id,
            'action'          => $row->action,
            'idempotency_key' => $row->idempotency_key,
            'branch_code'     => $row->branch_code,
            'payload'         => json_decode($row->payload_json, true),
        );
    }
}
