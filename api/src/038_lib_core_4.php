function money_bd($n) {
    $n = (int)$n; $neg = $n < 0; $n = abs($n);
    $s = (string)$n;
    if (strlen($s) <= 3) $out = $s;
    else {
        $last3 = substr($s, -3);
        $rest = substr($s, 0, -3);
        $rest = (string)(int)$rest;
        $out = strlen($rest) > 2 ? money_bd((int)$rest) . ',' . $last3 : $rest . ',' . $last3;
    }
    return ($neg ? '−' : '') . $out;
}
