<?php
require __DIR__ . '/app/lib.php';
require __DIR__ . '/app/crypto.php';


$q = trim((string)($_GET['q'] ?? ''));
$rows = db()->prepare('SELECT id,title,visibility,encrypted,body,updated_at,created_at FROM notes ORDER BY id DESC');
$rows->execute();
$all = $rows->fetchAll();

$cnt = ['public' => 0, 'password' => 0, 'private' => 0];
foreach ($all as $n) { $cnt[$n['visibility']] = ($cnt[$n['visibility']] ?? 0) + 1; }
$total = count($all);

$visLabel = ['public' => '公开', 'password' => '密码', 'private' => '私密'];
$visClass = ['public' => 'c-public', 'password' => 'c-password', 'private' => 'c-private'];
$badge    = ['public' => 'badge-public', 'password' => 'badge-password', 'private' => 'badge-private'];

$admin = is_admin();
$notes = [];
foreach ($all as $n) {
    $vis = $n['visibility'];
    $id  = (int)$n['id'];
    $enc = (int)$n['encrypted'] === 1;

    if ($vis === 'private' && !$admin) {
        $viewable = false; $preview = '🔐 仅登录后可见'; $locked = true;
    } elseif ($vis === 'password') {
        $key = get_note_key($id);
        if ($key !== null && $enc) {
            $dec = decrypt_string((string)$n['body'], $key);
            if ($dec === null) { forget_note_key($id); $viewable = false; $preview = '🔒 内容已加密 · 需要查看密码'; $locked = true; }
            else { $viewable = true; $preview = $dec; $locked = false; }
        } elseif ($key !== null && !$enc) {
            $viewable = true; $preview = (string)$n['body']; $locked = false;
        } else {
            $viewable = false; $preview = $enc ? '🔒 内容已加密 · 需要查看密码' : '🔓 需要查看密码'; $locked = true;
        }
    } else {
        $viewable = true; $preview = (string)$n['body']; $locked = false;
    }

    if ($q !== '') {
        $hay = $n['title'] . ' ' . ($viewable ? $preview : '');
        if (stripos($hay, $q) === false) { continue; }
    }

    $pv = preg_replace('/\s+/u', ' ', trim($preview));
    $notes[] = [
        'id' => $id,
        'title' => $n['title'],
        'vis' => $vis,
        'locked' => $locked,
        'preview' => mb_substr($pv, 0, 90),
        'updated_at' => (int)$n['updated_at'],
    ];
}
?>
<!doctype html>
<html lang="zh">
<head><meta charset="utf-8"><title>便签</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="style.css">
</head>
<body>
<nav class="topbar">
  <a class="brand" href="index.php"><span class="logo-dot">📝</span>我的便签</a>
  <div class="topbar-right">
    <?php if ($admin): ?>
      <span class="user-chip">👤 <?= h(current_username()) ?></span>
      <a class="btn btn-ghost btn-sm" href="logout.php">退出</a>
    <?php else: ?>
      <a class="btn btn-sm" href="login.php">登录</a>
    <?php endif; ?>
  </div>
</nav>
<div class="container">
  <div class="page-head">
    <div>
      <h1>全部便签</h1>
      <p class="sub">共 <?= $total ?> 条<?= $q !== '' ? ' · 匹配 ' . count($notes) . ' 条' : '' ?></p>
    </div>
    <?php if ($admin): ?><a class="btn" href="edit.php">＋ 新建便签</a><?php endif; ?>
  </div>

  <div class="stat-row">
    <span class="stat-chip">🌍 公开 <b><?= $cnt['public'] ?></b></span>
    <span class="stat-chip">🔒 密码 <b><?= $cnt['password'] ?></b></span>
    <span class="stat-chip">🔐 私密 <b><?= $cnt['private'] ?></b></span>
  </div>

  <form class="toolbar" method="get">
    <div class="search-wrap"><input type="search" name="q" placeholder="搜索标题或内容…" value="<?= h($q) ?>"></div>
    <button class="btn btn-ghost">搜索</button>
    <?php if ($q !== ''): ?><a class="btn btn-ghost" href="index.php">清空</a><?php endif; ?>
  </form>

  <?php if ($notes): ?>
  <div class="wall">
  <?php foreach ($notes as $n): ?>
  <div class="note-card <?= $visClass[$n['vis']] ?>">
    <div class="note-top">
      <p class="note-title"><a href="view.php?id=<?= $n['id'] ?>"><?= h($n['title']) ?></a></p>
      <span class="badge <?= $badge[$n['vis']] ?>"><?= $visLabel[$n['vis']] ?></span>
    </div>
    <?php if ($n['locked']): ?>
      <p class="note-preview locked"><?= h($n['preview']) ?></p>
    <?php else: ?>
      <p class="note-preview"><?= h($n['preview'] !== '' ? $n['preview'] : '（暂无正文）') ?></p>
    <?php endif; ?>
    <div class="note-foot">
      <span><?= date('Y-m-d H:i', $n['updated_at']) ?></span>
      <?php if ($admin): ?><a class="btn btn-ghost btn-sm" href="edit.php?id=<?= $n['id'] ?>">编辑</a><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="empty">
    <div class="icon">🗒️</div>
    <p><?= $q !== '' ? '没有匹配「' . h($q) . '」的便签' : '还没有便签' ?></p>
    <?php if ($admin && $q === ''): ?><p class="hint">点击右上角「＋ 新建便签」开始记录</p><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
