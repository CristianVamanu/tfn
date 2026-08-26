<?php
declare(strict_types=1);
include '../includes/inc.php';
header('Content-Type: application/json; charset=utf-8');

if ($loggedIn !== '1') {
    echo json_encode(['status' => 'error', 'message' => 'AUTH']);
    exit;
}
if (!isset($_POST['csrf_token']) || !checkCsrfToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'invalid_csrf']);
    exit;
}

$q = trim((string)($_POST['q'] ?? ''));
$uid = (int)($userID ?? 0);
if ($q === '') { echo json_encode(['status'=>'success','items'=>[]]); exit; }

try {
    $rows = method_exists($RL, 'RL_SearchUser') ? $RL->RL_SearchUser($q, $uid) : [];
    $offset = isset($_POST['offset']) ? max(0, (int)$_POST['offset']) : 0;
    $limit  = isset($_POST['limit']) ? max(1, min(50, (int)$_POST['limit'])) : 10;
    if ($offset > 0 || $limit) { $rows = array_slice($rows, $offset, $limit); }
    $out  = [];
    foreach ($rows as $r) {
        $out[] = [
            'user_id'  => (int)($r['user_id'] ?? 0),
            'username' => (string)($r['username'] ?? ''),
            'fullname' => (string)(($r['fullname'] ?? '') ?: ($r['username'] ?? '')),
            'avatar'   => (string)($r['avatar'] ?? 'uploads/user_avatars/default.jpeg'),
        ];
    }
    echo json_encode(['status'=>'success','items'=>$out]);
} catch (\Throwable $e) {
    echo json_encode(['status'=>'error','message'=>'SERVER']);
}
exit;
