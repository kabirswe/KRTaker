function service_authed() {
    $h = $_SERVER['HTTP_X_SERVICE_KEY'] ?? '';
    return hash_equals(SERVICE_KEY, $h);
}
