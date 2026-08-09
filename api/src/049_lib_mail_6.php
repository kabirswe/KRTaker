function mail_switch($pdo, $flag) {
    return admin_cfg($pdo, 'mail_' . $flag, '1') !== '0';
}
