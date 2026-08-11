# KRTaker — MySQL Conversion Dry-Run

**Date:** 2026-06-12 · **Scope:** read-only audit (no DB touched) · **Verdict:** feasible, high-effort, NOT recommended now

## 1. Inventory

| Item | Count |
|---|---|
| Tables (unique) | **123** (`CREATE TABLE IF NOT EXISTS` × 124 — `tenants` defined twice) |
| `ALTER TABLE` migrations | 38 |
| Column defaults `DEFAULT (datetime('now'))` | 105 |
| `AUTOINCREMENT` columns | 25 |
| Triggers | 0 ✅ |
| FTS / virtual tables | 0 ✅ |
| JSON functions (`json_extract` etc.) | 0 ✅ (JSON stored as TEXT) |
| Migration gate | `PRAGMA user_version` (currently `20260822`) |
| DB file | `/home/krtaker/krtaker_landing.db` (SQLite, WAL mode, busy_timeout 15s, foreign_keys ON) |
| API surface | single-file `api/index.php` (~20K lines, 1000+ SQL statements via PDO) |

## 2. SQLite-specific SQL found in queries (must rewrite)

| Pattern | Occurrences | MySQL equivalent |
|---|---|---|
| `datetime('now')` | **265** | `NOW()` |
| `datetime('now', '-24 hours')` / `'-7 days'` / `?` modifier | **51** | `DATE_SUB(NOW(), INTERVAL 24 HOUR)` etc. |
| `julianday(...)` | 2 | `DATEDIFF()` / `TO_DAYS()` |
| `strftime('%s', ts)` | 1 | `UNIX_TIMESTAMP(ts)` |
| `PRAGMA table_info(...)` | 22 | `SHOW COLUMNS` / `INFORMATION_SCHEMA.COLUMNS` |
| `PRAGMA user_version` (read) | 1 | `schema_meta` table (new) |
| `PRAGMA user_version=…` (write) | 2 | `schema_meta` UPSERT |
| `PRAGMA quick_check` | 1 | `CHECK TABLE` |
| `PRAGMA journal_mode/busy_timeout/synchronous/temp_store/foreign_keys` | 8 | drop (InnoDB defaults) / `innodb_lock_wait_timeout` |
| `INSERT OR IGNORE` | **75** | `INSERT IGNORE` |
| `INSERT OR REPLACE` | 10 | `REPLACE INTO` |
| `ON CONFLICT(...) DO UPDATE/SET` | **38** | `ON DUPLICATE KEY UPDATE` (different clause order) |
| `sqlite_master` / `sqlite_…` refs | 3 | `information_schema.tables` |

## 3. Schema-level differences

- **`INTEGER PRIMARY KEY AUTOINCREMENT`** → MySQL `BIGINT AUTO_INCREMENT PRIMARY KEY` (AUTO_INCREMENT column must be a key — fine, but every PK column def must be touched).
- **Dynamic/loose typing** → strict columns: every `TEXT` needs a type decision (`VARCHAR(n)` vs `TEXT` vs `LONGTEXT`; money/decimal columns should become `DECIMAL`). 105 `DEFAULT (datetime('now'))` → `DEFAULT CURRENT_TIMESTAMP` (note: `TEXT`-typed columns can't hold a timestamp default in MySQL without switching to `DATETIME/TIMESTAMP`).
- **Numeric-as-string hazard:** the app stores numbers as TEXT and the frontend already casts with `Number()` (documented). MySQL would return native `INT`/`DECIMAL` types → loose `==` mostly fine, but strict `===` / string-concat / `'0'`-vs-`0` checks could silently change behavior. Medium regression risk, needs a full QA pass.
- **Case sensitivity:** 0 `COLLATE`/`NOCASE` uses; 200 `LIKE` queries. SQLite LIKE is case-insensitive for ASCII by default; MySQL depends on collation (`utf8mb4_general_ci` default = insensitive — OK, but must pin collation to avoid surprises).
- **`GROUP BY` (35) / `HAVING` (1):** MySQL 5.7+ `ONLY_FULL_GROUP_BY` would reject several non-aggregated selects; SQLite tolerates them. Must enable/disable sql_mode deliberately.
- **`?`-style PDO params, `lastInsertId()`, transactions:** all portable ✅.

## 4. Migration path (if ever needed)

1. **DDL transform:** dump SQLite schema → sed/scripted rewrite (AUTOINCREMENT→AUTO_INCREMENT, datetime defaults, TEXT→typed columns) → run on MySQL.
2. **Data export:** `sqlite3 krtaker_landing.db .dump` → rewrite INSERTs (quoting/hex blobs) → import. ~123 tables, no blobs in DB (files live in DATA_DIR as `ppv_…`, `job_…`, photos on disk).
3. **Query rewrite:** ~380 statements touch SQLite-specific syntax (the 265+51 datetime, 123 upsert/ignore/replace, 22 PRAGMA table_info, julianday/strftime, user_version). Estimate **2–4 focused dev-days** + regression suite.
4. **Migration gate swap:** replace `PRAGMA user_version` gate with a `schema_meta(key, version)` table (one-time code change + every future migration bump).
5. **Config:** new `DB_*` env (host/name/user/pass); `krenv()` already supports env overrides; PDO DSN switch in `001_lib_core.php` (1 line + error paths).

## 5. Risks / blockers

- **Effort/benefit:** SQLite works in production today (WAL, busy_timeout solved the lock storms). MySQL buys: replication/HA, concurrent writers, external BI tooling. Not needed until multi-server or >1 concurrent writer is required.
- **Type-regression QA:** native numeric returns could ripple through 40+ views (frontend already `Number()`-casts, mitigates).
- **`ONLY_FULL_GROUP_BY`** and **LIKE collation** need explicit sql_mode/collation config or silent query breakage.
- **One-shot risk:** a blind SQLite→MySQL port of a 20K-line artifact without a unit suite (none exists) is the highest-risk change in the repo. If pursued, do it behind a `DB_DRIVER=mysql` env flag and run the full staging smoke suite side-by-side.

## 6. Recommendation

**Keep SQLite** for now. The codebase is deeply SQLite-idiomatic (380+ touch points) and the app is single-node. If MySQL is needed later (multi-server HA or third-party reporting), the cheapest entry is the **dry-run dump + typed-DDL conversion + rewrite of the 5 query patterns above**, with the `schema_meta` gate swap — roughly **2–4 days** including QA, no schema redesign required (no FTS/JSON/virtual tables to unwind).
