<?php
require __DIR__ . '/app/lib.php';
require __DIR__ . '/app/crypto.php';

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT p.id,p.note_id,p.orig_name,p.mime,p.stored_name,p.encrypted, n.visibility FROM photos p JOIN notes n ON n.id = p.note_id WHERE p.id = :id');
$st->execute([':id' => $id]);
$p = $st->fetch();
if (!$p) { http_response_code(404); exit('照片不存在'); }

$nid = (int)$p['note_id'];
if ($p['visibility'] === 'private' && !is_admin()) { http_response_code(403); exit('需要管理员登录'); }
if ($p['visibility'] === 'password' && !note_unlocked($nid)) { http_response_code(403); exit('需要密码解锁便签后才能查看照片'); }

$key = get_note_key($nid);
if ((int)$p['encrypted'] === 1 && ($key === null || $key === '')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('解密密钥不可用：请回到便签页重新输入查看密码。');
}

$path = __DIR__ . '/photos/' . $p['stored_name'];
if (!is_file($path)) { http_response_code(404); exit('照片文件丢失'); }
$bin = (string)file_get_contents($path);

if ((int)$p['encrypted'] === 1) {
    $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    if (strlen($bin) <= $nonceLen) { http_response_code(500); exit('照片数据损坏'); }
    $plain = sodium_crypto_secretbox_open(substr($bin, $nonceLen), substr($bin, 0, $nonceLen), $key);
    if ($plain === false) {
        // 密钥和密文不匹配（多为密码已在别处改过）→ 清掉过期密钥，让用户重新输入
        forget_note_key($nid);
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('解密失败：查看密码可能已被修改，请回到便签页重新输入当前密码。');
    }
    $bin = $plain;
}

header('Content-Type: ' . $p['mime']);
header('Content-Length: ' . strlen($bin));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode((string)$p['orig_name']));
echo $bin;