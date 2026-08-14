function DB_UNITS_COUNT() { return (int)db()->query('SELECT COUNT(*) FROM units')->fetchColumn(); }
function DB_LEASED_COUNT() { return (int)db()->query("SELECT COUNT(*) FROM units WHERE status='Leased'")->fetchColumn(); }

