function mail_hdr($s, $max = 200) {
    $s = (string)$s;
    $s = str_replace(["\r", "\n", "\0"], ' ', $s);
    if (strlen($s) > $max) $s = substr($s, 0, $max);
    return $s;
}
/* SA1 v21 hardening: count ALL attempts (successes + failures) for send-throttling
   (recent_fails only counts ok=0, so it can't cap successful sends). */
