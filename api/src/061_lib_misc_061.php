function ln_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'LN-','') AS INTEGER)) FROM legal_notices")->fetchColumn();
    return 'LN-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function ce_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'EV-','') AS INTEGER)) FROM case_events")->fetchColumn();
    return 'EV-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function case_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'CASE-','') AS INTEGER)) FROM cases")->fetchColumn();
    return 'CASE-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
