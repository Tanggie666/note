<?php
require __DIR__ . '/app/lib.php';
require_admin();
check_csrf();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('参数错误'); }

$pdo = db();
$ph = $pdo->prepare('SELECT stored_name FROM photos WHERE note_id = :id');
$ph->execute([':id' => $id]);
foreach ($ph->fetchAll(PDO::FETCH_COLUMN) as $f) {
    $path = __DIR__ . '/photos/' . $f;
    if (is_file($path)) { @unlink($path); }
}
$pdo->prepare('DELETE FROM notes WHERE id = :id')->execute([':id' => $id]);
forget_note_key($id);
redirect('index.php');