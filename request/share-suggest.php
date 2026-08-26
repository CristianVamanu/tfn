<?php
declare(strict_types=1);
include '../includes/inc.php';
header('Content-Type: application/json; charset=utf-8');

if ($loggedIn !== '1') { echo json_encode(['status'=>'error','message'=>'AUTH']); exit; }
if (!isset($_POST['csrf_token']) || !checkCsrfToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'invalid_csrf']);
    exit;
}

$uid = (int)($userID ?? 0);
if ($uid <= 0 && isset($_SESSION['user_id'])) {
    $uid = (int) $_SESSION['user_id'];
}
$offset = (int)($_POST['offset'] ?? 0);
$limit  = (int)($_POST['limit'] ?? 10);
if ($limit <= 0) { $limit = 10; }
if ($limit > 50) { $limit = 50; }

try {
    // Prefer recent chat partners; then fill from following as fallback.
    $items = [];
    $used  = [];

    // Recent chat partners do not support offset, so request offset+limit and slice.
    if (method_exists($RL, 'RL_GetRecentChatPartners')) {
        $rcAll = $RL->RL_GetRecentChatPartners($uid, max(0, $offset + $limit));
        $rcut  = array_slice($rcAll, $offset, $limit);
        foreach ($rcut as $r) {
            $uid2 = (int)($r['user_id'] ?? 0);
            if ($uid2 <= 0) { continue; }
            $used[$uid2] = true;
            $items[] = [
                'user_id'  => $uid2,
                'username' => (string)($r['username'] ?? ''),
                'fullname' => (string)(($r['user_fullname'] ?? '') ?: ($r['username'] ?? '')),
                'avatar'   => (string)($r['avatar'] ?? 'uploads/user_avatars/default.jpeg'),
            ];
        }
    }

    // If not enough, pull from following considering combined offset.
    if (count($items) < $limit && method_exists($RL, 'RL_ProfileGetFollowing')) {
        // Compute how much of the combined stream we've already consumed from recents
        $rcCount = isset($rcAll) ? count($rcAll) : 0;
        $neededFromFollowing = $limit - count($items);
        $followOffset = max(0, $offset - $rcCount);
        $rows = $RL->RL_ProfileGetFollowing($uid, $followOffset, $neededFromFollowing * 2); // fetch extra to skip dups
        foreach ($rows as $r) {
            $uid2 = (int)($r['user_id'] ?? 0);
            if ($uid2 <= 0 || isset($used[$uid2])) { continue; }
            $items[] = [
                'user_id'  => $uid2,
                'username' => (string)($r['username'] ?? ''),
                'fullname' => (string)(($r['user_fullname'] ?? '') ?: ($r['username'] ?? '')),
                'avatar'   => (string)($r['user_avatar'] ?? 'uploads/user_avatars/default.jpeg'),
            ];
            if (count($items) >= $limit) break;
        }
    }
    echo json_encode(['status'=>'success','items'=>$items]);
} catch (\Throwable $e) {
    echo json_encode(['status'=>'error','message'=>'SERVER']);
}
exit;
