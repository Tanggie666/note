<?php
require __DIR__ . '/app/lib.php';
require __DIR__ . '/app/crypto.php';
require_admin();

$id = (int)($_GET['id'] ?? $_POST['edit_id'] ?? 0);
$note = null;
if ($id) {
    $st = db()->prepare('SELECT id,title,visibility,encrypted,salt FROM notes WHERE id=:id');
    $st->execute([':id' => $id]);
    $note = $st->fetch();
    if (!$note) { http_response_code(404); exit('便签不存在'); }
}

$formError = '';
$uploadErrors = [];
$bodyVal = '';
$titleVal = $note['title'] ?? '';
$visVal = $note['visibility'] ?? 'public';

$UPLOAD_ERR_TEXT = [
    UPLOAD_ERR_INI_SIZE   => '超过服务器 upload_max_filesize 限制（请调大 php.ini）',
    UPLOAD_ERR_FORM_SIZE  => '超过表单上传限制',
    UPLOAD_ERR_PARTIAL    => '文件只上传了一部分',
    UPLOAD_ERR_NO_FILE    => '没有选择文件',
    UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
    UPLOAD_ERR_CANT_WRITE => '服务器写入磁盘失败',
    UPLOAD_ERR_EXTENSION  => '被 PHP 扩展拦截',
];
$ALLOWED_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

// ---- 处理提交 ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $titleVal = trim((string)($_POST['title'] ?? ''));
    $bodyVal  = (string)($_POST['body'] ?? '');
    $visVal   = (string)($_POST['visibility'] ?? 'public');
    $newPass  = (string)($_POST['password'] ?? '');
    if (!in_array($visVal, ['public', 'password', 'private'], true)) { $visVal = 'public'; }
    if ($titleVal === '') { $titleVal = '无标题'; }

    $nid  = $note ? (int)$note['id'] : 0;
    $wasEncrypted = $note ? (int)$note['encrypted'] === 1 : false;
    $oldKey = $nid ? get_note_key($nid) : null;   // 会话里的旧密钥（用于解开旧照片）

    $writtenFiles = [];
    $newPhotoIds = [];
    $pdo = db();
    try {
        /* ---- 1. 决定本次的加密状态与密钥 ---- */
        $wantEnc = ($visVal === 'password');
        $canEnc  = ($newPass !== '') || ($wasEncrypted && $oldKey !== null);

        if ($wantEnc && !$canEnc) {
            if (!$note) {
                throw new RuntimeException('「密码保护」必须设置查看密码。');
            }
            if ($wasEncrypted) {
                throw new RuntimeException('当前会话没有这条便签的解密密钥：请先在查看页输入原密码解锁后再编辑；或直接填写一个新密码来重置（旧内容将无法恢复）。');
            }
            // 旧数据：标了 password 但正文没加密 → 视作"门禁"，保持不加密
            $wantEnc = false;
        }

        $targetEnc = $wantEnc;
        $salt = null;
        $key  = null;
        $rekey = false;   // 是否需要用新密钥重写所有照片

        if ($targetEnc) {
            if ($newPass !== '') {
                $salt  = random_bytes(16);
                $key   = derive_key($newPass, $salt);
                $rekey = true;                       // 设了（新）密码 → 所有照片换成新钥
            } else {
                $key  = $oldKey;                     // 沿用旧密码（旧盐、旧钥）
                $salt = $note['salt'];
            }
        }

        /* ---- 2. 落库 ---- */
        $pdo->beginTransaction();

        $encBody = $targetEnc ? encrypt_string($bodyVal, $key) : $bodyVal;

        if ($note) {
            $st = $pdo->prepare('UPDATE notes SET title=:t, body=:b, visibility=:v, encrypted=:e, salt=:s, updated_at=:u WHERE id=:id');
            $st->execute([':t' => $titleVal, ':b' => $encBody, ':v' => $visVal, ':e' => (int)$targetEnc, ':s' => $salt, ':u' => time(), ':id' => $nid]);
        } else {
            $st = $pdo->prepare('INSERT INTO notes (title,body,visibility,encrypted,salt,created_at,updated_at) VALUES (:t,:b,:v,:e,:s,:c,:c)');
            $st->execute([':t' => $titleVal, ':b' => $encBody, ':v' => $visVal, ':e' => (int)$targetEnc, ':s' => $salt, ':c' => time()]);
            $nid = (int)$pdo->lastInsertId();
        }

        /* ---- 3. 新上传的照片 ---- */
        if (!empty($_FILES['photos']['name']) && is_array($_FILES['photos']['name']) && $_FILES['photos']['name'][0] !== '') {
            $dir = __DIR__ . '/photos';
            if (!is_dir($dir)) { mkdir($dir, 0770, true); }

            $cnt = count($_FILES['photos']['name']);
            if ($cnt > 20) {
                $uploadErrors[] = '一次最多上传 20 张，本次只处理前 20 张。';
                $cnt = 20;
            }
            for ($i = 0; $i < $cnt; $i++) {
                $fname = basename((string)($_FILES['photos']['name'][$i] ?? "file$i"));
                $err   = (int)($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($err !== UPLOAD_ERR_OK) {
                    $uploadErrors[] = $fname . '：' . ($UPLOAD_ERR_TEXT[$err] ?? ('上传错误 code=' . $err));
                    continue;
                }
                $tmp  = (string)$_FILES['photos']['tmp_name'][$i];
                $size = (int)$_FILES['photos']['size'][$i];
                if ($size <= 0)          { $uploadErrors[] = $fname . '：文件为空'; continue; }
                if ($size > 8 * 1024 * 1024) { $uploadErrors[] = $fname . '：' . round($size / 1048576, 1) . 'MB 超出 8MB 限制'; continue; }

                $info = @getimagesize($tmp);
                $mime = $info['mime'] ?? '';
                if (!isset($ALLOWED_MIME[$mime])) { $uploadErrors[] = $fname . '：不是支持的图片格式（仅 jpg/png/webp/gif）'; continue; }
                if (!empty($info[0]) && !empty($info[1]) && (int)$info[0] * (int)$info[1] > 40000000) {
                    $uploadErrors[] = $fname . '：分辨率过大（超过 4000 万像素）'; continue;
                }

                // GD 重编码：剥离 EXIF / 脚本载荷，统一格式
                $img = @imagecreatefromstring((string)file_get_contents($tmp));
                if ($img === false) { $uploadErrors[] = $fname . '：图片解码失败（可能已损坏）'; continue; }
                ob_start();
                if ($mime === 'image/jpeg')     { imagejpeg($img, null, 88); }
                elseif ($mime === 'image/png')  { imagepng($img, null, 6); }
                elseif ($mime === 'image/webp') { imagewebp($img, null, 85); }
                else                            { imagegif($img); }
                imagedestroy($img);
                $bin = (string)ob_get_clean();
                if ($bin === '') { $uploadErrors[] = $fname . '：图片重新编码失败'; continue; }

                if ($targetEnc) {
                    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                    $bin = $nonce . sodium_crypto_secretbox($bin, $nonce, $key);
                }
                $stored = bin2hex(random_bytes(16)) . '.bin';
                if (file_put_contents($dir . '/' . $stored, $bin, LOCK_EX) === false) {
                    $uploadErrors[] = $fname . '：写入 photos/ 失败，请检查目录权限';
                    continue;
                }
                $writtenFiles[] = $dir . '/' . $stored;

                $st = $pdo->prepare('INSERT INTO photos (note_id,orig_name,mime,stored_name,size,encrypted) VALUES (:n,:o,:m,:s,:z,:e)');
                $st->execute([':n' => $nid, ':o' => $fname, ':m' => $mime, ':s' => $stored, ':z' => strlen($bin), ':e' => (int)$targetEnc]);
                $newPhotoIds[] = (int)$pdo->lastInsertId();   // 必须在 INSERT 之后取
            }
        }

        /* ---- 4. 可见性切换 / 改密：重处理已有照片 ---- */
        $need = (int)$targetEnc;
        $rows = $pdo->prepare('SELECT id,stored_name,encrypted FROM photos WHERE note_id=:id');
        $rows->execute([':id' => $nid]);
        foreach ($rows->fetchAll() as $pr) {
            if (in_array((int)$pr['id'], $newPhotoIds, true)) { continue; }   // 本次新传的已是当前密钥
            if ((int)$pr['encrypted'] === $need && !$rekey) { continue; }
            $fpath = __DIR__ . '/photos/' . $pr['stored_name'];
            if (!is_file($fpath)) { continue; }
            $raw = (string)file_get_contents($fpath);

            if ((int)$pr['encrypted'] === 1) {
                if ($oldKey === null) {
                    throw new RuntimeException('照片 ' . $pr['stored_name'] . ' 需要原密码才能解密：请先在查看页输入原密码解锁后再保存。');
                }
                $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
                $plain = sodium_crypto_secretbox_open(substr($raw, $nonceLen), substr($raw, 0, $nonceLen), $oldKey);
                if ($plain === false) {
                    throw new RuntimeException('旧照片解密失败，无法转换（原密码可能已被改过）。');
                }
                $raw = $plain;
            }
            if ($need === 1) {
                $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $raw = $nonce . sodium_crypto_secretbox($raw, $nonce, $key);
            }
            file_put_contents($fpath, $raw, LOCK_EX);
            $pdo->prepare('UPDATE photos SET encrypted=:e, size=:z WHERE id=:id')->execute([':e' => $need, ':z' => strlen($raw), ':id' => $pr['id']]);
        }

        $pdo->commit();

        /* ---- 5. 关键：把新密钥写回会话，否则查看页会拿旧钥解密 → "解密失败" ---- */
        if ($targetEnc) { set_note_key($nid, $key); } else { forget_note_key($nid); }
        if ($targetEnc) { $_SESSION["unlocked"][$nid] = true; }


    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        foreach ($writtenFiles as $wf) { if (is_file($wf)) { @unlink($wf); } }
        $formError = $e->getMessage();
    }

    if ($formError === '') {
        if ($uploadErrors) {
            $_SESSION['flash_upload_errors'] = $uploadErrors;
            redirect('edit.php?id=' . $nid);
        }
        redirect('view.php?id=' . $nid);
    }
    if ($uploadErrors) { $_SESSION['flash_upload_errors'] = $uploadErrors; }
}

// ---- 编辑预填 ----
$needKey = false;
if ($note) {
    $st = db()->prepare('SELECT body,encrypted,salt FROM notes WHERE id=:id');
    $st->execute([':id' => $note['id']]);
    $row = $st->fetch();
    if ($row && (int)$row['encrypted'] === 1) {
        $key = get_note_key((int)$note['id']);
        if ($key === null) {
            $needKey = true;          // 无密钥：正文留空，但允许"填新密码重置"
            $bodyVal = '';
        } else {
            $dec = decrypt_string($row['body'], $key);
            if ($dec === null) {
                forget_note_key((int)$note['id']);   // 会话密钥已过期 → 清掉，让用户重新输密码
                $needKey = true;
                $bodyVal = '';
            } else {
                $bodyVal = $dec;
            }
        }
    } else {
        $bodyVal = $row['body'] ?? '';
    }
}
$ph = $note ? db()->prepare('SELECT * FROM photos WHERE note_id=:id ORDER BY id') : null;
if ($ph) { $ph->execute([':id' => $note['id']]); $photos = $ph->fetchAll(); } else { $photos = []; }
?>
<!doctype html>
<html lang="zh">
<head><meta charset="utf-8"><title><?= $note ? '编辑' : '新建' ?>便签</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="style.css">
</head>
<body>
<nav class="topbar">
  <a class="brand" href="index.php"><span class="logo-dot">📝</span>我的便签</a>
  <div class="topbar-right"><a class="btn btn-ghost btn-sm" href="index.php">← 取消</a></div>
</nav>
<div class="container" style="max-width:660px">
<?php if (!empty($_SESSION['flash_upload_errors'])): ?>
<div class="alert alert-err">
  <strong>以下照片没能保存：</strong><br>
  <?php foreach ($_SESSION['flash_upload_errors'] as $fe): ?>· <?= h($fe) ?><br><?php endforeach; ?>
</div>
<?php unset($_SESSION['flash_upload_errors']); endif; ?>
<?php if ($formError !== ''): ?>
<div class="alert alert-err"><strong>保存失败：</strong><?= h($formError) ?></div>
<?php endif; ?>
<?php if ($needKey): ?>
<div class="alert alert-warn">
  <strong>这条便签的内容是加密的，当前会话没有解密密钥。</strong><br>
  正文无法显示。你可以在下面填入<strong>新密码</strong>并保存，用新密码重写这条便签（<strong>旧内容与旧密码都会失效、无法找回</strong>）；<br>
  或者先回到<a href="view.php?id=<?= (int)$note['id'] ?>">查看页</a>输入原密码解锁后再来编辑。
</div>
<?php endif; ?>
<form class="detail-card" id="noteForm" method="post" enctype="multipart/form-data" style="padding:1.8rem 2rem">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
<?php if ($note): ?><input type="hidden" name="edit_id" value="<?= (int)$note['id'] ?>"><?php endif; ?>
<div class="detail-head">
  <h1 style="font-size:1.25rem"><?= $note ? '✏️ 编辑便签' : '🆕 新建便签' ?></h1>
</div>
<label>标题</label>
<input type="text" name="title" value="<?= h($titleVal) ?>" required maxlength="100" placeholder="给这条便签起个名字…">
<label>正文</label>
<textarea name="body" placeholder="写点什么…"><?= h($bodyVal) ?></textarea>
<label>可见性</label>
<select name="visibility" id="vis">
  <option value="public"   <?= $visVal === 'public'   ? 'selected' : '' ?>>🌍 公开 — 任何人可见</option>
  <option value="password" <?= $visVal === 'password' ? 'selected' : '' ?>>🔒 密码保护 — 需密码查看</option>
  <option value="private"  <?= $visVal === 'private'  ? 'selected' : '' ?>>🔐 私密 — 仅登录后可见</option>
</select>
<div id="passRow" style="display:none">
  <label>查看密码</label>
  <input type="password" name="password" autocomplete="new-password" placeholder="<?= ($note && (int)$note['encrypted'] === 1) ? '重置密码（留空=不改，原密码继续有效）' : '设置查看密码' ?>">
  <p class="hint">⚠️ 密码不保存在服务器上，只能用于解密。忘记密码后内容无法恢复，只能用新密码重置。</p>
</div>
<?php if ($photos): ?>
<label>已上传照片（<?= count($photos) ?> 张）</label>
<div class="photos<?= photo_grid_class(count($photos)) ?>">
<?php foreach ($photos as $p): ?>
  <figure class="photo-item">
    <a href="photo.php?id=<?= (int)$p['id'] ?>" target="_blank"><img src="photo.php?id=<?= (int)$p['id'] ?>" alt="<?= h($p['orig_name']) ?>" loading="lazy"></a>
    <figcaption><?= h($p['orig_name']) ?></figcaption>
  </figure>
<?php endforeach; ?>
</div>
<p class="hint">删除单张照片请到<a href="view.php?id=<?= (int)$note['id'] ?>">查看页</a>，每张照片右上角有 ✕。</p>
<?php endif; ?>
<label>📷 添加照片</label>
<input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
<p class="hint">支持 jpg / png / webp / gif，可多选，单张 ≤ 8MB、一次 ≤ 20 张。上传后自动压缩并清除 EXIF 等隐藏数据。</p>
<div class="detail-actions" style="border:0;padding-top:1.4rem;margin-top:.8rem">
  <button class="btn" type="submit" id="saveBtn" style="min-width:110px">💾 保存</button>
  <div id="progressWrap" style="display:none;flex:1;align-items:center;gap:.7rem">
    <div style="flex:1;height:8px;background:#e8ebf2;border-radius:99px;overflow:hidden">
      <div id="progressBar" style="height:100%;width:0%;background:linear-gradient(90deg,#5b6cf5,#9b6cff);border-radius:99px;transition:width .2s"></div>
    </div>
    <span id="progressText" style="font-size:.82rem;color:#7a8494;min-width:3.2em;text-align:right">0%</span>
  </div>
</div>
</form>
</div>
<script>
const vis = document.getElementById('vis');
const row = document.getElementById('passRow');
function upd(){ row.style.display = vis.value === 'password' ? 'block' : 'none'; }
vis.addEventListener('change', upd); upd();

document.getElementById('noteForm').addEventListener('change', function(e) {
  if (e.target.name !== 'photos[]' || !e.target.files) return;
  if (e.target.files.length > 20) {
    alert('一次最多上传 20 张，请分批上传。');
    e.target.value = '';
    return;
  }
  const total = [...e.target.files].reduce((s, f) => s + f.size, 0);
  if (total > 40 * 1024 * 1024) {
    alert('所选图片总大小 ' + (total/1048576).toFixed(1) + 'MB 过大，请分批上传或先压缩。');
    e.target.value = '';
  }
});
document.getElementById('noteForm').addEventListener('submit', function(e) {
  e.preventDefault();
  const btn = document.getElementById('saveBtn');
  const wrap = document.getElementById('progressWrap');
  const bar  = document.getElementById('progressBar');
  const txt  = document.getElementById('progressText');
  btn.disabled = true; btn.style.opacity = .6;
  wrap.style.display = 'flex';

  const fd = new FormData(this);
  const xhr = new XMLHttpRequest();
  xhr.open('POST', location.pathname + location.search);
  xhr.upload.addEventListener('progress', function(ev) {
    if (ev.lengthComputable) {
      const pct = Math.round(ev.loaded / ev.total * 100);
      bar.style.width = pct + '%';
      txt.textContent = pct + '%';
    }
  });
  xhr.addEventListener('load', function() {
    if (xhr.status >= 200 && xhr.status < 400) {
      bar.style.width = '100%'; txt.textContent = '完成';
      location.href = 'view.php?id=' + (new URLSearchParams(location.search).get('id') || '');
      location.reload();
    } else {
      alert('保存失败（HTTP ' + xhr.status + '）：\n' + xhr.responseText.slice(0, 400));
      btn.disabled = false; btn.style.opacity = 1;
      wrap.style.display = 'none';
    }
  });
  xhr.addEventListener('error', function() {
    alert('网络错误，上传中断');
    btn.disabled = false; btn.style.opacity = 1;
    wrap.style.display = 'none';
  });
  xhr.send(fd);
});
</script>
</body>
</html>
