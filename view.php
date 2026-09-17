<?php
require __DIR__ . '/app/lib.php';
require __DIR__ . '/app/crypto.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM notes WHERE id = :id');
$stmt->execute([':id' => $id]);
$note = $stmt->fetch();
if (!$note) { http_response_code(404); exit('便签不存在'); }

$vis = $note['visibility'];
$visLabel = ['public' => '公开', 'password' => '密码', 'private' => '私密'];
$visClass = ['public' => 'c-public', 'password' => 'c-password', 'private' => 'c-private'];
$badge    = ['public' => 'badge-public', 'password' => 'badge-password', 'private' => 'badge-private'];

if ($vis === 'private' && !is_admin()) { http_response_code(403); exit('需要管理员登录'); }

// ---- 密码便签：没解锁 / 没密钥 / 密钥已失效 → 显示解锁表单 ----
$needUnlock = false;
if ($vis === 'password') {
    $key = get_note_key($id);
    if (!note_unlocked($id) || ((int)$note['encrypted'] === 1 && $key === null)) {
        $needUnlock = true;
    } elseif ((int)$note['encrypted'] === 1 && decrypt_string($note['body'], $key) === null) {
        // 会话里的密钥和密文对不上（例如密码已在别处被改过）→ 清掉，重新要密码
        forget_note_key($id);
        $needUnlock = true;
        $staleKey = true;
    } else {
        $_SESSION['unlocked'][$id] = true;
    }
}

$err = '';
if ($needUnlock) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $wait = throttle_wait('unlock' . $id);
        if ($wait > 0) {
            $err = '尝试过于频繁，请 ' . $wait . ' 秒后再试';
        } else {
            $pass = (string)($_POST['password'] ?? '');
            $enc  = ((int)$note['encrypted'] === 1);
            $salt = (string)($note['salt'] ?? '');
            if ($pass === '') {
                $err = '请输入密码';
            } elseif (!$enc && strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES) {
                // 门禁型数据（标了密码但正文未加密，或 salt 不是标准长度）：
                // 无法校验密码是否正确，只能放行。这里明确告知，避免"输什么都行"被误以为安全。
                $_SESSION['unlocked'][$id] = true;
                redirect('view.php?id=' . $id);
            } else {
                $k = derive_key($pass, $salt);
                $ok = !$enc || (decrypt_string($note['body'], $k) !== null);
                if ($ok) {
                    throttle_reset('unlock' . $id);
                    set_note_key($id, $k);
                    redirect('view.php?id=' . $id);
                } else {
                    throttle_fail('unlock' . $id);
                    $err = '密码错误';
                }
            }
        }
    }
    ?>
<!doctype html>
<html lang="zh">
<head><meta charset="utf-8"><title><?= h($note['title']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
<div class="auth-card">
  <div class="auth-logo">🔒</div>
  <h1><?= h($note['title']) ?></h1>
  <p class="auth-sub">此便签受密码保护，输入密码查看</p>
  <?php if (!empty($staleKey)): ?><div class="alert alert-warn">上次的解锁状态已失效，请重新输入密码。</div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-err"><?= h($err) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="password" name="password" placeholder="输入查看密码" required autofocus autocomplete="off">
    <button class="btn btn-block">解锁查看</button>
  </form>
  <div class="auth-back"><a href="index.php">← 返回列表</a></div>
</div>
</body>
</html>
    <?php
    exit;
}

// ---- 正文 ----
if ((int)$note['encrypted'] === 1) {
    $body = decrypt_string($note['body'], (string)get_note_key($id));
    if ($body === null) {
        // 兜底：理论上到不了这里，真到了就清钥重来，而不是显示"解密失败"
        forget_note_key($id);
        $qs = http_build_query(['id' => $id, 'stale' => 1]);
        redirect('view.php?' . $qs);
    }
} else {
    $body = (string)$note['body'];
}

// ---- 照片 ----
$ph = db()->prepare('SELECT id, orig_name FROM photos WHERE note_id = :id ORDER BY id');
$ph->execute([':id' => $id]);
$photos = $ph->fetchAll();

$canEdit = is_admin();
?>
<!doctype html>
<html lang="zh">
<head><meta charset="utf-8"><title><?= h($note['title']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="style.css">
</head>
<body>
<nav class="topbar">
  <a class="brand" href="index.php"><span class="logo-dot">📝</span>我的便签</a>
  <div class="topbar-right">
    <a class="btn btn-ghost btn-sm" href="index.php">← 列表</a>
    <?php if ($canEdit): ?><a class="btn btn-sm" href="edit.php?id=<?= $id ?>">编辑</a><?php endif; ?>
  </div>
</nav>
<div class="container">
  <div class="detail-card <?= $visClass[$vis] ?>">
    <div class="detail-head">
      <h1><?= h($note['title']) ?></h1>
      <div class="detail-meta">
        <span class="badge <?= $badge[$vis] ?>"><?= $visLabel[$vis] ?></span>
        <?php if ((int)$note['encrypted'] === 1): ?><span class="badge badge-password">🔐 已加密</span><?php endif; ?>
        <span>创建 <?= date('Y-m-d H:i', (int)$note['created_at']) ?></span>
        <span>更新 <?= date('Y-m-d H:i', (int)$note['updated_at']) ?></span>
      </div>
    </div>
    <?php if (!empty($_SESSION['flash_note'])): ?>
      <div class="alert alert-ok"><?= h($_SESSION['flash_note']) ?></div>
      <?php unset($_SESSION['flash_note']); ?>
    <?php endif; ?>
    <pre class="note-body"><?= h($body) ?></pre>
    <?php if ($photos): ?>
    <div class="photos<?= photo_grid_class(count($photos)) ?>">
      <?php foreach ($photos as $p): ?>
      <figure class="photo-item">
        <a href="photo.php?id=<?= (int)$p['id'] ?>" target="_blank"><img src="photo.php?id=<?= (int)$p['id'] ?>" alt="<?= h($p['orig_name']) ?>" loading="lazy"></a>
        <figcaption><?= h($p['orig_name']) ?></figcaption>
        <?php if ($canEdit): ?>
        <form method="post" action="photo_delete.php" onsubmit="return confirm('只删除这一张照片？便签本身会保留。')">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="photo_id" value="<?= (int)$p['id'] ?>">
          <input type="hidden" name="note_id" value="<?= $id ?>">
          <button class="photo-del" title="删除这张照片">✕</button>
        </form>
        <?php endif; ?>
      </figure>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($canEdit): ?>
    <div class="detail-actions">
      <a class="btn" href="edit.php?id=<?= $id ?>">✏️ 编辑</a>
      <form method="post" action="delete.php" onsubmit="return confirm('删除整条便签？正文和全部照片都会一并删除，不可恢复。')">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn btn-danger">🗑 删除便签</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
