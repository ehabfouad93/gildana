<?php
declare(strict_types=1);

/**
 * PDO singleton + a tiny migration runner.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'], (int) $c['port'], $c['name'], $c['charset']
        );
        try {
            $pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $ex) {
            http_response_code(500);
            exit('Database connection failed. Check config.php and that MySQL is running.');
        }
    }
    return $pdo;
}

/** Convenience query helpers. */
function db_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function db_row(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function db_val(string $sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
}

function db_run(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

function db_insert(string $sql, array $params = []): int
{
    db_run($sql, $params);
    return (int) db()->lastInsertId();
}

/**
 * Runs any *.sql files in migrations/ that haven't run yet, in filename order.
 * Each file may contain multiple statements separated by ';'.
 */
/**
 * Does this column exist yet?
 *
 * Deploys here are `git pull` followed by a SEPARATE manual migrate step, so there is always a
 * window where new code is running against the old schema. Code that hard-depends on a new
 * column turns that window into an outage: the Inbox threw "Unknown column" and every message
 * was dropped on the floor until someone ran the migration.
 *
 * Checking lets those paths degrade to their previous behaviour instead — the new feature is
 * missing until the migration runs, which is honest, rather than the page being broken.
 *
 * Cached per request: INFORMATION_SCHEMA is cheap but this is called per query.
 */
function db_has_column(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];

    try {
        $cache[$key] = (bool) db_val(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $column]);
    } catch (Throwable $e) {
        $cache[$key] = false;   // cannot tell → assume not there and use the old path
    }
    return $cache[$key];
}

function migrate(): array
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(255) PRIMARY KEY,
        applied_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $applied = array_column(
        $pdo->query("SELECT filename FROM schema_migrations")->fetchAll(),
        'filename'
    );

    $dir = dirname(__DIR__) . '/migrations';
    $files = glob($dir . '/*.sql') ?: [];
    sort($files);

    $ran = [];
    foreach ($files as $file) {
        $name = basename($file);
        if (in_array($name, $applied, true)) continue;

        $sql = (string) file_get_contents($file);
        $pdo->exec($sql);
        $st = $pdo->prepare("INSERT INTO schema_migrations (filename, applied_at) VALUES (?, NOW())");
        $st->execute([$name]);
        $ran[] = $name;
    }
    return $ran;
}
