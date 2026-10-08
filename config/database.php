<?php
/**
 * ==========================================================================
 *  OUTING MANAGEMENT SYSTEM — config/database.php
 * --------------------------------------------------------------------------
 *  ★ SATU-SATUNYA TEMPAT MENYIMPAN KREDENSIAL DATABASE ★
 *
 *  SESUAIKAN 5 NILAI DI BAWAH DENGAN DATA CPANEL / INFINITYFREE ANDA:
 *    DB_HOST : biasanya "localhost"  (InfinityFree: sqlXXX.epizy.com / lihat panel)
 *    DB_NAME : InfinityFree memakai prefix akun, contoh "epiz_12345678_outing"
 *    DB_USER : contoh "epiz_12345678"
 *    DB_PASS : password MySQL (di panel InfinityFree, BUKAN password akun)
 *
 *  Folder /config sudah diproteksi .htaccess (deny from all) sehingga file ini
 *  tidak dapat dibaca langsung melalui browser.
 * ==========================================================================
 */

defined('APP_STARTED') or exit('Direct access is not allowed.');

define('DB_HOST', 'localhost');
define('DB_NAME', 'outing_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_PORT', '3306');
define('DB_CHARSET', 'utf8mb4');

/**
 * Koneksi PDO tunggal (singleton).
 * Semua query di aplikasi ini memakai PREPARED STATEMENT.
 *
 * @return PDO
 */
function db()
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // error jadi exception
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // hasil array asosiatif
        PDO::ATTR_EMULATE_PREPARES   => false,                  // prepared statement ASLI (anti SQLi)
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // Jangan bocorkan detail kredensial ke pengunjung
        http_response_code(500);
        if (defined('APP_DEBUG') && APP_DEBUG) {
            exit('Koneksi database gagal: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
        exit('Koneksi database gagal. Periksa kembali konfigurasi pada <code>config/database.php</code> '
            . '(host, nama database, user, password) dan pastikan <code>database.sql</code> sudah diimport.');
    }

    return $pdo;
}

/**
 * Shortcut query prepared statement.
 *
 * Contoh: $row = fetch_one("SELECT * FROM users WHERE user_id = ? LIMIT 1", [$uid]);
 *
 * @param string $sql
 * @param array  $params
 * @return array|null
 */
function fetch_one($sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * Shortcut query banyak baris.
 *
 * @param string $sql
 * @param array  $params
 * @return array
 */
function fetch_all($sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Shortcut query satu nilai (COUNT / SUM).
 *
 * @param string $sql
 * @param array  $params
 * @return mixed
 */
function fetch_value($sql, array $params = [], $default = null)
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $val = $stmt->fetchColumn();

    return ($val === false || $val === null) ? $default : $val;
}

/**
 * Jalankan INSERT / UPDATE / DELETE.
 *
 * @param string $sql
 * @param array  $params
 * @return int jumlah baris terpengaruh
 */
function run_query($sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount();
}

/**
 * INSERT lalu ambil id terakhir.
 *
 * @param string $sql
 * @param array  $params
 * @return int
 */
function insert_get_id($sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return (int) db()->lastInsertId();
}
