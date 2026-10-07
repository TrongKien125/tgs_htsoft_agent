<?php
/**
 * Copy -> config.php rồi điền. config.php KHÔNG commit.
 * Mỗi client (AddIn hoặc POS) có một secret HMAC dùng chung.
 * Có thể thay bằng hằng số TGS_AGENT_CLIENTS (array) định nghĩa ở wp-config.php.
 */
if (!defined('ABSPATH')) { exit; }

return array(
    // client_id => array('secret' => '...', 'branches' => ['*'] | ['CNTEST', ...])
    'store-01' => array(
        'secret'   => 'DOI_THANH_CHUOI_NGAU_NHIEN_>=32_KY_TU',
        'branches' => array('*'),
    ),
);
