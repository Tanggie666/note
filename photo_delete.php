<?php
require __DIR__ . '/app/lib.php';
require_admin();
check_csrf();

$pid = (int)($_POST['photo_id'] ?? 0);
$nid = (int)($_POST['note_id'] ?? 0);

$st = db()->prepare('SELECT id, stored_name FROM photos WHERE id = :id AND note_id = :nid');
$st->execute([':id' => $pid, ':nid' => $nid]);
$p = $st->fetch();
if (!$p) { http_response_code(404); exit('照片不存在'); }

// 只删这一张：先删文件，再删记录（删文件失败不阻断，避免留下死记录）
$fpath = __DIR__ . '/photos/' . $p['stored_name'];
if (is_file($fpath)) { @unlink($fpath); }
db()->prepare('DELETE FROM photos WHERE id = :id')->execute([':id' => $pid]);

$_SESSION['flash_note'] = '已删除照片 ' . $p['stored_name'] . '（便签保留）。';
redirect('view.php?id=' . $nid);