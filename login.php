<?php
require __DIR__ . '/app/lib.php';

$hasUsers = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
$err = '';
$user = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $user = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    $wait = throttle_wait('login');
    if ($wait > 0) {
        $err = '尝试过于频繁，请 ' . $wait . ' 秒后再试';
    } elseif ($user === '' || $pass === '') {
        $err = '请输入账号和密码';
    } elseif (!$hasUsers) {
        // 首次运行：创建管理员账号
        if (mb_strlen($user) < 3) {
            $err = '账号至少 3 个字符';
        } elseif (strlen($pass) < 8) {
            $err = '密码至少 8 位，建议混合大小写字母、数字和符号';
        } else {
            $st = db()->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (:u, :p, :t)');
            $st->execute([':u' => $user, ':p' => password_hash($pass, PASSWORD_DEFAULT), ':t' => time()]);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)db()->lastInsertId();
            $_SESSION['username'] = $user;
            throttle_reset('login');
            redirect('index.php');
        }
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE username = :u');
        $st->execute([':u' => $user]);
        $row = $st->fetch();
        if ($row && password_verify($pass, $row['password_hash'])) {
            throttle_reset('login');
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$row['id'];
            $_SESSION['username'] = $row['username'];
            redirect('index.php');
        } else {
            throttle_fail('login');
            $err = '账号或密码错误';
        }
    }
}
?>
<!doctype html>
<html lang="zh">
<head><meta charset="utf-8"><title>登录 - 便签</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
<div class="auth-card">
  <div class="auth-logo">📝</div>
  <h1><?= $hasUsers ? '欢迎回来' : '初始化管理员' ?></h1>
  <p class="auth-sub"><?= $hasUsers ? '登录以管理你的便签' : '首次使用，请创建管理员账号' ?></p>
  <?php if ($err): ?><div class="alert alert-err"><?= h($err) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <label>账号</label>
    <input type="text" name="username" value="<?= h($user) ?>" autofocus required maxlength="32"
           minlength="3" placeholder="<?= $hasUsers ? '' : '至少 3 个字符' ?>" autocomplete="username">
    <label>密码</label>
    <input type="password" name="password" required minlength="<?= $hasUsers ? 1 : 8 ?>"
           <?= $hasUsers ? '' : 'placeholder="至少 8 位，建议混合字符"' ?> autocomplete="<?= $hasUsers ? 'current-password' : 'new-password' ?>">
    <?php if (!$hasUsers): ?>
    <p class="hint" style="text-align:left">账号与密码只保存在本机数据库（bcrypt 哈希），请务必记牢。</p>
    <?php endif; ?>
    <button class="btn btn-block"><?= $hasUsers ? '登 录' : '创建并登录' ?></button>
  </form>
  <?php if ($hasUsers): ?><div class="auth-back"><a href="index.php">← 返回列表</a></div><?php endif; ?>
</div>
</body>
</html>