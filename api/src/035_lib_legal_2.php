function wa_link($phone, $text) {
    $p = preg_replace('/[^0-9]/', '', (string)$phone);
    if (!$p) return '';
    if (strlen($p) === 10) $p = '880' . $p;
    elseif (strpos($p, '0') === 0 && strlen($p) === 11) $p = '88' . $p;
    return 'https://wa.me/' . $p . '?text=' . rawurlencode($text);
}
/* Generate a printable invoice (Phase 8: invoice PDF/print) */
/* V3.75: branded print logo — Website header logo (wl_logo_nav) at the print size
   (wl_h_print, default 30px); falls back to wl_logo_url, then a text wordmark.
   V3.77: dedicated print slot (wl_logo_print) takes precedence; margin (wl_ma_print) /
   padding (wl_pa_print) / title (wl_t_print — site name next to the logo). */
function print_brand_img() {
    $pdo = db();
    $def = ['wl_site_name' => 'KRTaker', 'wl_logo_print' => '', 'wl_logo_nav' => '', 'wl_logo_url' => '',
            'wl_h_print' => '30', 'wl_ma_print' => '0', 'wl_pa_print' => '0', 'wl_t_print' => '0'];
    $st = $pdo->query("SELECT k, v FROM admin_settings WHERE k IN ('wl_site_name','wl_logo_print','wl_logo_nav','wl_logo_url','wl_h_print','wl_ma_print','wl_pa_print','wl_t_print')");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $def[$r['k']] = $r['v'];
    $h = max(16, min(240, (int)($def['wl_h_print'] ?: 30)));
    $ma = max(0, min(80, (int)($def['wl_ma_print'] ?: 0)));
    $pa = max(0, min(80, (int)($def['wl_pa_print'] ?: 0)));
    $name = esc($def['wl_site_name'] ?: 'KRTaker');
    $logo = $def['wl_logo_print'] !== '' ? $def['wl_logo_print'] : ($def['wl_logo_nav'] !== '' ? $def['wl_logo_nav'] : $def['wl_logo_url']);
    $style = 'height:' . $h . 'px;width:auto;object-fit:contain';
    if ($ma) $style .= ';margin:' . $ma . 'px';
    if ($pa) $style .= ';padding:' . $pa . 'px';
    if ($logo !== '') {
        $out = '<img src="' . esc($logo) . '" alt="' . $name . '" style="' . $style . '">';
    } else {
        $out = '<span style="font-size:' . max(16, $h - 2) . 'px;font-weight:800;color:#2F80ED;letter-spacing:-.3px">' . $name . '</span>';
    }
    if (($def['wl_t_print'] ?? '0') === '1') {
        $out .= '<span style="display:block;font-size:' . max(13, (int)round($h * 0.75)) . 'px;font-weight:800;color:#101828;letter-spacing:-.3px;margin-top:2px">' . $name . '</span>';
    }
    return $out;
}
