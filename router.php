<?php
// php -S 内置服务器入口路由：php -S 0.0.0.0:8080 router.php
// 用一个「白名单 + 目录黑名单」的策略，保证内部文件永远不会被直接下载。

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if ($uri === '' || $uri === '/') { $uri = '/index.php'; }

$root = realpath(__DIR__);

// 1) 目录黑名单：内部数据、照片原件、库文件、备份
$blockedDirs = ['/data/', '/photos/', '/app/', '/_bak/', '/.hexstrike_data/', '/hexstrike_envs/'];
foreach ($blockedDirs as $d) {
    if (str_starts_with($uri, $d)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Forbidden');
    }
}

// 2) 路径里出现隐藏项或可疑后缀，一律拒绝
if (preg_match('#(^|/)(\.|_)#', $uri) ||
    preg_match('/\.(bak|orig|db|db-wal|db-shm|sqlite|sqlite3|ini|log|txt|md|htaccess|bin)$/i', $uri)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden');
}

// 3) 只允许这几个后缀被直接提供
$allowedExt = ['php', 'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'woff', 'woff2'];
$ext = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt, true)) {
    http_response_code(404);
    exit('Not Found');
}

$real = realpath(__DIR__ . $uri);
if ($real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('Not Found');
}

if (is_file($real)) {
    if ($ext !== 'php') {
        $mime = [
            'css' => 'text/css', 'js' => 'application/javascript',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
        ][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($real));
        readfile($real);
        return true;
    }
    require $real;
    return true;
}

http_response_code(404);
exit('Not Found');