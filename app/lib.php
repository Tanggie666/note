<?php
declare(strict_types=1);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => isset($_SERVER['HTTPS']),
]);
session_start();
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Shanghai');
ini_set('display_errors', '0');
error_reporting(E_ALL);

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) { mkdir($dir, 0770, true); }
    $pdo = new PDO('sqlite:' . $dir . '/app.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        created_at INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        body BLOB NOT NULL,
        visibility TEXT NOT NULL DEFAULT "public",
        encrypted INTEGER NOT NULL DEFAULT 0,
        salt BLOB,
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS photos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        note_id INTEGER NOT NULL REFERENCES notes(id) ON DELETE CASCADE,
        orig_name TEXT NOT NULL,
        mime TEXT NOT NULL,
        stored_name TEXT NOT NULL UNIQUE,
        size INTEGER NOT NULL,
        encrypted INTEGER NOT NULL DEFAULT 0
    )');
    try { $pdo->exec('ALTER TABLE photos ADD COLUMN encrypted INTEGER NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
    $pdo->exec('CREATE TABLE IF NOT EXISTS throttle (
        k TEXT PRIMARY KEY,
        fails INTEGER NOT NULL DEFAULT 0,
        last INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('DELETE FROM throttle WHERE last < ' . (time() - 86400));
    return $pdo;
}

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
    return $_SESSION['csrf'];
}

/** 把 ini 简写（8M / 1G）换算成字节 */
function ini_bytes(string $v): int {
    $v = trim($v);
    if ($v === '') return 0;
    $unit = strtolower($v[strlen($v) - 1]);
    $n = (int)$v;
    if ($unit === 'g') return $n * 1024 * 1024 * 1024;
    if ($unit === 'm') return $n * 1024 * 1024;
    if ($unit === 'k') return $n * 1024;
    return $n;
}

function check_csrf(): void {
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        // POST 体积超过 post_max_size 时 PHP 会丢弃整个 $_POST，
        // 表现出来就成了“CSRF 校验失败”，这里换成看得懂的提示。
        $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        $max = ini_bytes((string)ini_get('post_max_size'));
        if ($len > 0 && $max > 0 && $len > $max) {
            http_response_code(413);
            header('Content-Type: text/plain; charset=utf-8');
            exit('上传内容 ' . round($len / 1048576, 1) . 'MB 超过服务器 post_max_size (' . ini_get('post_max_size') . ')。请在服务器 php.ini 中调大 post_max_size 与 upload_max_filesize，重载 PHP 后重试。');
        }
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('CSRF 校验失败，请刷新页面后重试。');
    }
}

function is_admin(): bool {
    return !empty($_SESSION['uid']);
}

function current_username(): string {
    return $_SESSION['username'] ?? '';
}

function require_admin(): void {
    if (!is_admin()) { header('Location: login.php'); exit; }
}

/**
 * 该便签在当前会话中是否已通过查看密码校验。
 * 注意：管理员并不自动等于"已解锁"——加密便签的密钥只能由查看密码派生，
 * 服务端不保存任何密钥，所以管理员也必须输一次密码。
 */
function note_unlocked(int $id): bool {
    return !empty($_SESSION['unlocked'][$id]);
}

function get_note_key(int $id): ?string {
    $k = $_SESSION['note_keys'][$id] ?? null;
    return is_string($k) && $k !== '' ? $k : null;
}

/** 解锁成功后把密钥写入会话（改密后必须调用，否则会话里会残留旧密钥） */
function set_note_key(int $id, string $key): void {
    $_SESSION['unlocked'][$id] = true;
    $_SESSION['note_keys'][$id] = $key;
}

/** 便签不再加密、或删除便签时清理会话中的密钥 */
function forget_note_key(int $id): void {
    unset($_SESSION['unlocked'][$id], $_SESSION['note_keys'][$id]);
}

/* ---------------- 登录 / 解锁限速（防爆破） ----------------
 * 存在 SQLite 里并按 客户端IP+作用域 计数：
 * 只放在 $_SESSION 里的话，攻击者丢掉 Cookie 就能重置计数，等于没防。
 */

function client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function throttle_key(string $scope): string {
    return $scope . '|' . client_ip();
}

/** 返回还需等待的秒数，0 表示可以尝试 */
function throttle_wait(string $scope): int {
    $st = db()->prepare('SELECT fails, last FROM throttle WHERE k = :k');
    $st->execute([':k' => throttle_key($scope)]);
    $r = $st->fetch();
    if (!$r) { return 0; }
    $fails = (int)$r['fails'];
    $last  = (int)$r['last'];
    if ($fails < 5) { return 0; }
    if (time() - $last > 900) {                       // 15 分钟无动作 → 计数清零
        db()->prepare('DELETE FROM throttle WHERE k = :k')->execute([':k' => throttle_key($scope)]);
        return 0;
    }
    $wait = 1 << min($fails - 5, 6);                  // 1,2,4,8,16,32,64 秒
    $left = $wait - (time() - $last);
    return $left > 0 ? $left : 0;
}

function throttle_fail(string $scope): void {
    $k = throttle_key($scope);
    $st = db()->prepare('SELECT fails, last FROM throttle WHERE k = :k');
    $st->execute([':k' => $k]);
    $r = $st->fetch();
    if (!$r || time() - (int)$r['last'] > 900) {
        db()->prepare('INSERT INTO throttle (k, fails, last) VALUES (:k, 1, :t)
                       ON CONFLICT(k) DO UPDATE SET fails = 1, last = :t2')
            ->execute([':k' => $k, ':t' => time(), ':t2' => time()]);
        return;
    }
    db()->prepare('UPDATE throttle SET fails = fails + 1, last = :t WHERE k = :k')
        ->execute([':t' => time(), ':k' => $k]);
}

function throttle_reset(string $scope): void {
    db()->prepare('DELETE FROM throttle WHERE k = :k')->execute([':k' => throttle_key($scope)]);
}
/**
 * 照片区容器要加的 class。
 * columns 布局的列数只看容器宽度，照片数不够时多出来的列会整列空着（表现为右边一大条白边），
 * 所以 1 张、2 张照片时显式锁死列数，让它们铺满整行。
 */
function photo_grid_class(int $count): string {
    if ($count >= 1 && $count <= 4) { return ' photos-c' . $count; }
    return '';
}

function redirect(string $to): void { header('Location: ' . $to); exit; }
