<?php
include "../includes/inc.php";

if (!function_exists('dz_resolve_binary')) {
    function dz_resolve_binary(?string $configured, array $fallbacks = []): ?string
    {
        $candidates = [];

        if ($configured !== null) {
            $normalized = trim($configured);
            if ($normalized !== '') {
                $candidates[] = $normalized;
            }
        }

        foreach ($fallbacks as $candidate) {
            $normalized = trim((string) $candidate);
            if ($normalized !== '') {
                $candidates[] = $normalized;
            }
        }

        $candidates = array_values(array_unique($candidates));

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            if (strpos($candidate, DIRECTORY_SEPARATOR) !== false) {
                if (is_file($candidate) && (DIRECTORY_SEPARATOR === '\\' || is_executable($candidate))) {
                    return $candidate;
                }
                continue;
            }

            if (!function_exists('shell_exec')) {
                continue;
            }

            $probeCommand = DIRECTORY_SEPARATOR === '\\'
                ? 'where '
                : 'command -v ';

            $resolved = @shell_exec($probeCommand . escapeshellarg($candidate));
            if (is_string($resolved)) {
                $resolved = trim($resolved);
                if ($resolved !== '') {
                    $firstLine = strtok($resolved, "\r\n");
                    if ($firstLine !== false && $firstLine !== '') {
                        return $firstLine;
                    }
                }
            }
        }

        return null;
    }
}
if (!function_exists('render_chat_locked_placeholder')) {
    function render_chat_locked_placeholder(int $postId, string $baseUrl, string $label): string
    {
        $safeLabel = iN_HelpSecure($label);
        $normalizedBase = rtrim((string) $baseUrl, '/');
        $href = ($normalizedBase !== '' ? $normalizedBase : '') . '/posts/' . $postId;
        $safeHref = iN_HelpSecure($href);
        $lockedIcon = render_icon('locked', true);

        return '<a class="chat-share-card chat-share-card--locked" href="' . $safeHref . '" target="_blank" rel="noopener noreferrer">'
            . '<div class="csp-thumb csp-thumb-locked">'
            . '  <div class="csp-placeholder csp-placeholder-locked">'
            . '    <span class="csp-lock">' . $lockedIcon . '</span>'
            . '    <span class="csp-label">' . $safeLabel . '</span>'
            . '  </div>'
            . '</div>'
            . '<div class="csp-meta">'
            . '  <div class="csp-user">' . $safeLabel . '</div>'
            . '  <div class="csp-type">' . $safeLabel . '</div>'
            . '</div>'
            . '</a>';
    }
}
header('Content-Type: application/json; charset=utf-8');
if (isset($_POST['p']) && $loggedIn == '1') {
    // CSRF token validation
    if (!isset($_POST['csrf_token']) || !checkCsrfToken($_POST['csrf_token'])) {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'invalid_csrf',
            'message_text' => customLang('invalid_csrf_token')
        ]);
        exit;
    }

    $p = isset($_POST['p']) ? trim($_POST['p']) : '';

    /*Send New Message*/
    if ($p === 'sendNewMessage') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me  = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $to  = (int) ($_POST['to'] ?? 0);
            $msg = (string) ($_POST['message'] ?? '');
            $replyTo = (int) ($_POST['reply_to'] ?? 0);

            if ($me <= 0 || $to <= 0 || trim($msg) === '') {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters']);
                exit;
            }

            // Delegate to conversation service (no SQL here)
            $row = $RL->RL_SendMessage($me, $to, $msg, $replyTo);
            if (isset($row['error'])) {
                echo json_encode(['status' => 'error', 'message' => 'message_send_failed', 'message_text' => customLang('message_send_failed')]);
                exit;
            }

            echo json_encode(['status' => 'success', 'data' => $row]);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'server_error', 'message_text' => customLang('server_error')]);
        }
        exit;
    }

    /* Accept / decline / block an incoming message request */
    if ($p === 'messageRequestAction') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $requestId = (int)($_POST['request_id'] ?? 0);
            $action = (string)($_POST['action'] ?? '');
            if ($me <= 0 || $requestId <= 0 || !method_exists($RL, 'RL_UpdateMessageRequestAction')) {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters']);
                exit;
            }
            $res = $RL->RL_UpdateMessageRequestAction($me, $requestId, $action);
            if (isset($res['error'])) {
                echo json_encode(['status' => 'error', 'message' => $res['error']]);
                exit;
            }
            echo json_encode(['status' => 'success'] + $res);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'server_error']);
        }
        exit;
    }

    /* Start/poll/control one-to-one Agora chat calls */
    if ($p === 'startChatCall') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $to = (int)($_POST['to'] ?? 0);
            $callType = (string)($_POST['call_type'] ?? 'audio');
            $currencyCode = !empty($globalCurCode) ? strtoupper((string)$globalCurCode) : (!empty($currency) ? strtoupper((string)$currency) : 'USD');
            if ($me <= 0 || $to <= 0 || !method_exists($RL, 'RL_StartChatCall')) {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters']);
                exit;
            }
            $res = $RL->RL_StartChatCall($me, $to, $callType, $currencyCode);
            if (isset($res['error'])) {
                echo json_encode(['status' => 'error', 'message' => $res['error'], 'call' => $res['call'] ?? null]);
                exit;
            }
            echo json_encode(['status' => 'success'] + $res);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'server_error']);
        }
        exit;
    }

    if ($p === 'pollChatCalls') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $with = (int)($_POST['with'] ?? 0);
            if ($me <= 0 || !method_exists($RL, 'RL_ListActiveChatCalls')) {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters']);
                exit;
            }
            echo json_encode(['status' => 'success', 'items' => $RL->RL_ListActiveChatCalls($me, $with)]);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'server_error']);
        }
        exit;
    }

    if (in_array($p, ['acceptChatCall', 'rejectChatCall', 'cancelChatCall', 'endChatCall', 'missChatCall'], true)) {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $callId = (int)($_POST['call_id'] ?? 0);
            $actionMap = [
                'acceptChatCall' => 'accept',
                'rejectChatCall' => 'reject',
                'cancelChatCall' => 'cancel',
                'endChatCall' => 'end',
                'missChatCall' => 'miss',
            ];
            if ($me <= 0 || $callId <= 0 || !method_exists($RL, 'RL_UpdateChatCallStatus')) {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters']);
                exit;
            }
            $res = $RL->RL_UpdateChatCallStatus($me, $callId, $actionMap[$p]);
            if (isset($res['error'])) {
                echo json_encode(['status' => 'error', 'message' => $res['error'], 'call' => $res['call'] ?? null]);
                exit;
            }
            echo json_encode(['status' => 'success'] + $res);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'server_error']);
        }
        exit;
    }

    if ($p === 'joinChatCall') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $callId = (int)($_POST['call_id'] ?? 0);
            if ($me <= 0 || $callId <= 0 || !method_exists($RL, 'RL_BuildChatCallJoinPayload')) {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters']);
                exit;
            }
            $res = $RL->RL_BuildChatCallJoinPayload(
                $me,
                $callId,
                (string)($agoraAppId ?? ''),
                isset($agoraAppCert) ? (string)$agoraAppCert : null,
                isset($agoraTokenExpire) ? (int)$agoraTokenExpire : 7200,
                !empty($agoraAllowTokenless)
            );
            if (isset($res['error'])) {
                echo json_encode(['status' => 'error', 'message' => $res['error'], 'call' => $res['call'] ?? null]);
                exit;
            }
            echo json_encode(['status' => 'success'] + $res);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'server_error']);
        }
        exit;
    }

    /* React to a message */
    if ($p === 'reactMessage') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $mid = (int)($_POST['message_id'] ?? 0);
            $reaction = (string)($_POST['reaction'] ?? 'heart');
            if ($me <= 0 || $mid <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters']);
                exit;
            }
            $res = $RL->RL_ReactToMessage($me, $mid, $reaction);
            if (isset($res['error'])) {
                echo json_encode(['status' => 'error', 'message' => $res['error']]);
                exit;
            }
            echo json_encode(['status' => 'success'] + $res);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'server_error']);
        }
        exit;
    }

    /* Delete message for me / everyone */
    if ($p === 'deleteMessage') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $mid = (int)($_POST['message_id'] ?? 0);
            $scope = (string)($_POST['scope'] ?? 'self');
            if ($me <= 0 || $mid <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters']);
                exit;
            }
            $res = $RL->RL_DeleteChatMessage($me, $mid, $scope);
            if (isset($res['error'])) {
                echo json_encode(['status' => 'error', 'message' => $res['error']]);
                exit;
            }
            echo json_encode(['status' => 'success'] + $res);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'server_error']);
        }
        exit;
    }

    /* Report message */
    if ($p === 'reportMessage') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $mid = (int)($_POST['message_id'] ?? 0);
            $reason = (string)($_POST['reason'] ?? 'other');
            $note = (string)($_POST['note'] ?? '');
            if ($me <= 0 || $mid <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters']);
                exit;
            }
            $res = $RL->RL_ReportChatMessage($me, $mid, $reason, $note);
            if (isset($res['error'])) {
                echo json_encode(['status' => 'error', 'message' => $res['error']]);
                exit;
            }
            echo json_encode(['status' => 'success']);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'server_error']);
        }
        exit;
    }

    /* Conversation-level actions for the full messages page */
    if ($p === 'conversationAction') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $to = (int)($_POST['to'] ?? 0);
            $action = strtolower(trim((string)($_POST['action'] ?? '')));
            if ($me <= 0 || $to <= 0 || $me === $to || !in_array($action, ['clear', 'delete', 'pin', 'unpin', 'block'], true)) {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters']);
                exit;
            }
            if ($action === 'clear') {
                $res = $RL->RL_ClearConversationForUser($me, $to);
            } elseif ($action === 'delete') {
                $res = $RL->RL_DeleteConversationForUser($me, $to);
            } elseif ($action === 'block') {
                $res = $RL->RL_BlockConversationUser($me, $to);
            } else {
                $res = $RL->RL_SetConversationPinned($me, $to, $action === 'pin');
            }
            if (isset($res['error'])) {
                echo json_encode(['status' => 'error', 'message' => $res['error']]);
                exit;
            }
            echo json_encode(['status' => 'success'] + $res);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'server_error']);
        }
        exit;
    }

    if ($p === 'downloadConversation') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $to = (int)($_POST['to'] ?? 0);
            if ($me <= 0 || $to <= 0 || $me === $to) {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters']);
                exit;
            }
            $res = $RL->RL_BuildConversationDownload($me, $to);
            if (isset($res['error'])) {
                echo json_encode(['status' => 'error', 'message' => $res['error']]);
                exit;
            }
            echo json_encode(['status' => 'success'] + $res);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'server_error']);
        }
        exit;
    }

    if ($p === 'reportConversation') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $to = (int)($_POST['to'] ?? 0);
            $reason = (string)($_POST['reason'] ?? 'conversation');
            $note = (string)($_POST['note'] ?? '');
            if ($me <= 0 || $to <= 0 || $me === $to) {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters']);
                exit;
            }
            $res = $RL->RL_ReportConversation($me, $to, $reason, $note);
            if (isset($res['error'])) {
                echo json_encode(['status' => 'error', 'message' => $res['error']]);
                exit;
            }
            echo json_encode(['status' => 'success'] + $res);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'server_error']);
        }
        exit;
    }

    /* Send a wallet tip to an approved creator in chat */
    if ($p === 'sendChatTip') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $to = (int)($_POST['to'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $currencyCode = 'USD';
            if (!empty($globalCurCode)) {
                $currencyCode = strtoupper((string)$globalCurCode);
            } elseif (!empty($currency)) {
                $currencyCode = strtoupper((string)$currency);
            }
            if ($me <= 0 || $to <= 0 || $amount <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters']);
                exit;
            }
            $res = $RL->RL_SendChatWalletTip($me, $to, $amount, $currencyCode);
            if (isset($res['error'])) {
                echo json_encode(['status' => 'error', 'message' => $res['error'], 'balance' => $res['balance'] ?? null, 'required' => $res['required'] ?? null]);
                exit;
            }
            echo json_encode(['status' => 'success'] + $res);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'server_error']);
        }
        exit;
    }

    /* Share a post to one or more recipients */
    if ($p === 'sharePost') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me  = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $pid = (int) ($_POST['post_id'] ?? 0);
            $to  = (string) ($_POST['to'] ?? '');
            $msg = trim((string) ($_POST['message'] ?? ''));
            if ($me <= 0 || $pid <= 0) { echo json_encode(['status'=>'error','message'=>'invalid_parameters']); exit; }
            $recipients = [];
            if ($to !== '') {
                if ($to[0] === '[') { $recipients = json_decode($to, true); }
                else { $recipients = [$to]; }
            }
            $recipients = is_array($recipients) ? array_values(array_unique(array_filter(array_map('intval', $recipients)))) : [];
            if (empty($recipients)) { echo json_encode(['status'=>'error','message'=>'no_recipients']); exit; }
            $postRow = $RL->RL_GetPostById($pid, $me, null);
            if (!$postRow) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'not_allowed',
                    'message_text' => customLang('chat_locked_post_placeholder')
                ]);
                exit;
            }

            $sent = 0; $errors = 0;
            foreach ($recipients as $rcp) {
                if ($rcp <= 0) continue;
                // 1) First send the shared post marker so preview appears above text for recipient but below for sender UI
                $r2 = $RL->RL_SendFileMessage($me, (int)$rcp, 'post:' . $pid, '');
                if (isset($r2['error'])) { $errors++; }
                else { $sent++; }
                // 2) Then optional text (appears below the shared card in our bubbles order)
                if ($msg !== '') {
                    $r = $RL->RL_SendMessage($me, (int)$rcp, $msg);
                    if (isset($r['error'])) { $errors++; }
                }
            }
            // Return updated unique share total for this post
            $totalShares = 0;
            try { if (method_exists($RL, 'RL_CountPostShares')) { $totalShares = (int)$RL->RL_CountPostShares($pid); } } catch (\Throwable $__) {}
            echo json_encode(['status'=>'success','sent'=>$sent,'errors'=>$errors,'total'=>$totalShares]);
        } catch (\Throwable $e) {
            echo json_encode(['status'=>'error','message'=>'server_error']);
        }
        exit;
    }

    /*Fetch New Messages*/
    if ($p === 'fetchNewMessages') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me    = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $with  = (int) ($_POST['with'] ?? 0);
            $since = (int) ($_POST['since'] ?? 0);

            if ($me <= 0 || $with <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters', 'message_text' => customLang('invalid_parameters')]);
                exit;
            }

            $items = $RL->RL_GetChatNewMessages($me, $with, $since, 'Europe/Istanbul');
            // Inline share-post preview HTML to avoid async flicker on receiver side
            try {
                if (is_array($items)) {
                    $lockedLabel = customLang('chat_locked_post_placeholder');
                    foreach ($items as &$mm) {
                        $f = isset($mm['file']) ? (string)$mm['file'] : '';
                        if ($f !== '' && preg_match('/^post:\\d+$/i', $f)) {
                            $pid = (int)preg_replace('/^post:/i', '', $f);
                            if ($pid > 0 && method_exists($RL, 'RL_GetPostById')) {
                                $pShare = $RL->RL_GetPostById($pid, $me, null);
                                if ($pShare) {
                                    $ownerU = (string)($pShare['owner_username'] ?? '');
                                    $url    = rtrim((string)$base_url, '/') . '/posts/' . $pid . '/' . $ownerU;
                                    $type   = (string)($pShare['post_type'] ?? '');
                                    $files  = array_filter(array_map('trim', explode(',', (string)($pShare['post_file'] ?? ''))));
                                    $thumb  = $files ? $files[0] : '';
                                    $coverRel = '';
                                    if ($type === 'podcast' && method_exists($RL, 'RL_GetPostCoverPath')) {
                                        try { $coverRel = (string) $RL->RL_GetPostCoverPath($pid); } catch (\Throwable $__) { $coverRel = ''; }
                                    }
                                    $thumbUrl = $thumb !== '' ? storage_url($thumb) : '';
                                    $coverUrl = $coverRel !== '' ? storage_url($coverRel) : '';
                                    $altTxt = dzShortCaption($pShare, 80);
                                    if ($altTxt === '') { $altTxt = sprintf(customLang('alt_post_by_user'), $ownerU ?: 'user'); }
                                    ob_start(); ?>
                                    <a class="chat-share-card" href="<?php echo iN_HelpSecure($url); ?>" target="_blank" rel="noopener noreferrer">
                                      <div class="csp-thumb">
                                        <?php if ($type === 'image' && $thumbUrl !== ''): ?>
                                          <img src="<?php echo iN_HelpSecure($thumbUrl); ?>" alt="<?php echo iN_HelpSecure($altTxt); ?>">
                                        <?php elseif ($type === 'video' && $thumbUrl !== ''): ?>
                                          <?php $thumbVid = $thumbUrl; if ($thumbVid !== '' && method_exists($RL, 'videoThumbNail')) { try { $thumbVid = $RL->videoThumbNail($thumbVid); } catch (\Throwable $__) {} } ?>
                                          <?php if ($thumbVid !== ''): ?>
                                            <img src="<?php echo iN_HelpSecure($thumbVid); ?>" alt="<?php echo iN_HelpSecure($altTxt); ?>">
                                            <span class="csp-play">&#9658;</span>
                                          <?php else: ?>
                                            <div class="csp-placeholder">Post</div>
                                          <?php endif; ?>
                                        <?php elseif ($type === 'podcast'): ?>
                                          <?php if ($coverUrl !== ''): ?>
                                            <img src="<?php echo iN_HelpSecure($coverUrl); ?>" alt="<?php echo iN_HelpSecure($altTxt); ?>">
                                            <span class="csp-play">&#9658;</span>
                                          <?php else: ?>
                                            <div class="csp-placeholder">Post</div>
                                          <?php endif; ?>
                                        <?php else: ?>
                                          <div class="csp-placeholder">Post</div>
                                        <?php endif; ?>
                                      </div>
                                      <div class="csp-meta">
                                        <div class="csp-user">@<?php echo iN_HelpSecure($ownerU); ?></div>
                                        <div class="csp-type"><?php echo $type === 'video' ? iN_HelpSecure(customLang('post_type_reel', 'Reel')) : ($type === 'podcast' ? customLang('menu_podcasts') : iN_HelpSecure(customLang('post_type_post', 'Post'))); ?></div>
                                      </div>
                                    </a>
                                    <?php $mm['share_html'] = trim((string)ob_get_clean());
                                } else {
                                    $mm['share_html'] = render_chat_locked_placeholder($pid, (string)($base_url ?? ''), $lockedLabel);
                                }
                            }
                        }
                    }
                    unset($mm);
                }
            } catch (\Throwable $__) { /* ignore */ }

            echo json_encode(['status' => 'success', 'items' => $items]);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'server_error', 'message_text' => customLang('server_error')]);
        }
        exit;
    }

    /*Mark existing incoming messages as delivered+read for this conversation*/
    if ($p === 'markReadConversation') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me   = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $with = (int) ($_POST['with'] ?? 0);
            if ($me <= 0 || $with <= 0) {
                echo json_encode(['status'=>'error','message'=>'invalid_parameters', 'message_text' => customLang('invalid_parameters')]);
                exit;
            }
            $nowTs  = time();
            $updated = method_exists($RL,'RL_MarkConversationRead') ? $RL->RL_MarkConversationRead($me, $with, $nowTs) : 0;
            if ((int)$updated === 0 && method_exists($RL,'getDb')) {
                // Fallback #1: direct UPDATE once more in this request context
                try {
                    $qUpd = $RL->getDb()->prepare('UPDATE i_messages
                        SET delivered_at = IFNULL(delivered_at, :now), read_at = :now
                        WHERE user_one = :with AND user_two = :me
                          AND (delivered_at IS NULL OR read_at IS NULL OR read_at = 0)');
                    $qUpd->bindValue(':now', $nowTs, \PDO::PARAM_INT);
                    $qUpd->bindValue(':with', $with, \PDO::PARAM_INT);
                    $qUpd->bindValue(':me',  $me,  \PDO::PARAM_INT);
                    $qUpd->execute();
                    $updated = (int)$qUpd->rowCount();
                } catch (\Throwable $___) { /* ignore */ }
                // Fallback #2: explicit id list update (works even under strict sql_safe_updates)
                if ((int)$updated === 0) {
                    try {
                        $pdo = $RL->getDb();
                        $sel = $pdo->prepare('SELECT message_id FROM i_messages WHERE user_one = :with AND user_two = :me AND (delivered_at IS NULL OR read_at IS NULL OR read_at = 0) ORDER BY message_id ASC LIMIT 500');
                        $sel->execute([':with'=>$with, ':me'=>$me]);
                        $ids = array_map('intval', $sel->fetchAll(\PDO::FETCH_COLUMN, 0) ?: []);
                        if (!empty($ids)) {
                            $place = implode(',', array_fill(0, count($ids), '?'));
                            $sql = 'UPDATE i_messages SET delivered_at = IFNULL(delivered_at, ?), read_at = ? WHERE message_id IN (' . $place . ')';
                            $params = array_merge([$nowTs, $nowTs], $ids);
                            $upd2 = $pdo->prepare($sql);
                            $upd2->execute($params);
                            $updated = (int)$upd2->rowCount();
                            // no-op
                        }
                    } catch (\Throwable $_____) { /* ignore */ }
                }
            }
            // end mark-read
            // Return max_read_id after update so client can backfill
            $maxReadId = 0; $maxReadTs = 0;
            try {
                $q = $RL->getDb()->prepare('SELECT MAX(message_id) AS mid, MAX(read_at) AS rt FROM i_messages WHERE user_one = :me AND user_two = :with AND read_at IS NOT NULL AND read_at > 0');
                $q->bindValue(':me', $me, \PDO::PARAM_INT);
                $q->bindValue(':with', $with, \PDO::PARAM_INT);
                $q->execute();
                $row2 = $q->fetch(\PDO::FETCH_ASSOC) ?: [];
                $maxReadId = (int)($row2['mid'] ?? 0);
                $maxReadTs = (int)($row2['rt'] ?? 0);
            } catch (\Throwable $__) { $maxReadId = 0; $maxReadTs = 0; }
            echo json_encode(['status'=>'success','ok'=>true,'updated'=>(int)$updated,'max_read_id'=>$maxReadId,'max_read_ts'=>$maxReadTs]);
        } catch (\Throwable $e) {
            echo json_encode(['status'=>'error','message'=>'server_error', 'message_text' => customLang('server_error')]);
        }
        exit;
    }

    /*Fetch Outgoing Message Statuses (last N)*/
    if ($p === 'fetchMessageStatuses') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me    = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $with  = (int) ($_POST['with'] ?? 0);
            $limit = (int) ($_POST['limit'] ?? 40);
            if ($limit <= 0) { $limit = 40; }
            if ($limit > 100) { $limit = 100; }
            if ($me <= 0 || $with <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters', 'message_text' => customLang('invalid_parameters')]);
                exit;
            }
            // If client provided explicit message id list, fetch statuses for those ids specifically
            $rows = [];
            $idsRaw = isset($_POST['ids']) ? (string)$_POST['ids'] : '';
            $idsArr = [];
            if ($idsRaw !== '') {
                $tmp = json_decode($idsRaw, true);
                if (is_array($tmp)) {
                    foreach ($tmp as $id) { $id = (int)$id; if ($id > 0) { $idsArr[] = $id; } }
                }
            }
            if (!empty($idsArr)) {
                $in = implode(',', array_map('intval', array_slice(array_values(array_unique($idsArr)), 0, 200)));
                $sql = 'SELECT message_id, delivered_at, read_at FROM i_messages WHERE user_one = :me AND user_two = :with AND message_id IN (' . $in . ')';
                $st  = $RL->getDb()->prepare($sql);
                $st->bindValue(':me', $me, \PDO::PARAM_INT);
                $st->bindValue(':with', $with, \PDO::PARAM_INT);
                $st->execute();
                $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as &$r) {
                    $r = [
                        'message_id'   => (int)($r['message_id'] ?? 0),
                        'delivered_at' => (int)($r['delivered_at'] ?? 0),
                        'read_at'      => (int)($r['read_at'] ?? 0),
                    ];
                }
                unset($r);
            } else {
                $rows = method_exists($RL, 'RL_GetOutgoingMessageStatuses') ? $RL->RL_GetOutgoingMessageStatuses($me, $with, $limit) : [];
            }
            // Also return the highest read message id for this direction (me -> with)
            $maxReadId = 0; $maxReadTs = 0;
            try {
                $q = $RL->getDb()->prepare('SELECT MAX(message_id) AS mid, MAX(read_at) AS rt FROM i_messages WHERE user_one = :me AND user_two = :with AND read_at IS NOT NULL AND read_at > 0');
                $q->bindValue(':me', $me, \PDO::PARAM_INT);
                $q->bindValue(':with', $with, \PDO::PARAM_INT);
                $q->execute();
                $row2 = $q->fetch(\PDO::FETCH_ASSOC) ?: [];
                $maxReadId = (int)($row2['mid'] ?? 0);
                $maxReadTs = (int)($row2['rt'] ?? 0);
            } catch (\Throwable $__) { $maxReadId = 0; $maxReadTs = 0; }

            echo json_encode(['status' => 'success', 'items' => $rows, 'max_read_id' => $maxReadId, 'max_read_ts' => $maxReadTs]);
            exit;
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'server_error', 'message_text' => customLang('server_error')]);
        }
        exit;
    }

    /*Fetch Older Messages*/
    if ($p === 'fetchOlderMessages') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me     = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $with   = (int) ($_POST['with'] ?? 0);
            $before = (int) ($_POST['before'] ?? 0);
            $limit  = (int) ($_POST['limit'] ?? 0);
            if ($limit <= 0) { $limit = (int) ($scrollLimitChat ?? 20); }

            if ($me <= 0 || $with <= 0 || $before <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters', 'message_text' => customLang('invalid_parameters')]);
                exit;
            }

            $items = $RL->RL_GetChatOlderMessages($me, $with, $before, $limit, 'Europe/Istanbul');
            echo json_encode(['status' => 'success', 'items' => $items]);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'server_error', 'message_text' => customLang('server_error')]);
        }
        exit;
    }

    /*Send Paid Image Message*/
    if ($p === 'sendPaidMediaImage') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $with = (int) ($_POST['with'] ?? 0);
            $price = (float) ($_POST['price'] ?? 0);
            $currencyCode = !empty($globalCurCode) ? strtoupper((string)$globalCurCode) : (!empty($currency) ? strtoupper((string)$currency) : 'USD');
            if ($me <= 0 || $with <= 0 || $price <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters']);
                exit;
            }
            if (!method_exists($RL, 'RL_CanSendPaidChatMedia') || !$RL->RL_CanSendPaidChatMedia($me)) {
                echo json_encode(['status' => 'error', 'message' => 'creator_required']);
                exit;
            }
            if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
                echo json_encode(['status' => 'error', 'message' => 'no_image_uploaded']);
                exit;
            }
            $err = (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) {
                echo json_encode(['status' => 'error', 'message' => 'upload_error']);
                exit;
            }
            $tmp = (string) $_FILES['image']['tmp_name'];
            $name = (string) $_FILES['image']['name'];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) {
                echo json_encode(['status' => 'error', 'message' => 'unsupported_file_type']);
                exit;
            }
            if (getimagesize($tmp) === false) {
                echo json_encode(['status' => 'error', 'message' => 'not_an_image']);
                exit;
            }
            $todayDirFs = createTodayDirectory();
            $baseName = 'chatpaid_' . $me . '_' . time() . '_' . mt_rand(1000,9999) . '.' . $ext;
            $destFs = rtrim($todayDirFs, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $baseName;
            if (!move_uploaded_file($tmp, $destFs)) {
                echo json_encode(['status' => 'error', 'message' => 'store_failed']);
                exit;
            }
            $todayRel = 'uploads/files/' . basename($todayDirFs) . '/' . $baseName;
            $row = $RL->RL_SendPaidChatMedia($me, $with, $todayRel, 'image', $price, $currencyCode);
            if (isset($row['error'])) {
                echo json_encode(['status' => 'error', 'message' => $row['error']]);
                exit;
            }
            echo json_encode(['status' => 'success', 'data' => $row['data'] ?? null]);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'server_error']);
        }
        exit;
    }

    /*Send Paid Video Message*/
    if ($p === 'sendPaidMediaVideo') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $with = (int) ($_POST['with'] ?? 0);
            $price = (float) ($_POST['price'] ?? 0);
            $currencyCode = !empty($globalCurCode) ? strtoupper((string)$globalCurCode) : (!empty($currency) ? strtoupper((string)$currency) : 'USD');
            if ($me <= 0 || $with <= 0 || $price <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters']);
                exit;
            }
            if (!method_exists($RL, 'RL_CanSendPaidChatMedia') || !$RL->RL_CanSendPaidChatMedia($me)) {
                echo json_encode(['status' => 'error', 'message' => 'creator_required']);
                exit;
            }
            if (!isset($_FILES['video']) || !is_array($_FILES['video'])) {
                echo json_encode(['status' => 'error', 'message' => 'no_video_uploaded']);
                exit;
            }
            $err = (int) ($_FILES['video']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) {
                echo json_encode(['status' => 'error', 'message' => 'upload_error']);
                exit;
            }
            $tmp = (string) $_FILES['video']['tmp_name'];
            $name = (string) $_FILES['video']['name'];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['mp4','mov','m4v','webm','avi','mkv'], true)) {
                echo json_encode(['status' => 'error', 'message' => 'unsupported_file_type']);
                exit;
            }
            $todayDirFs = createTodayDirectory();
            $baseName = 'chatpaidvid_' . $me . '_' . time() . '_' . mt_rand(1000,9999) . '.' . $ext;
            $destFs = rtrim($todayDirFs, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $baseName;
            if (!move_uploaded_file($tmp, $destFs)) {
                echo json_encode(['status' => 'error', 'message' => 'store_failed']);
                exit;
            }
            $todayRel = 'uploads/files/' . basename($todayDirFs) . '/' . $baseName;
            $row = $RL->RL_SendPaidChatMedia($me, $with, $todayRel, 'video', $price, $currencyCode);
            if (isset($row['error'])) {
                echo json_encode(['status' => 'error', 'message' => $row['error']]);
                exit;
            }
            echo json_encode(['status' => 'success', 'data' => $row['data'] ?? null]);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'server_error']);
        }
        exit;
    }

    /*Unlock Paid Media*/
    if ($p === 'unlockPaidMedia') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $messageId = (int) ($_POST['message_id'] ?? 0);
            if ($me <= 0 || $messageId <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters']);
                exit;
            }
            $res = $RL->RL_UnlockChatPaidMedia($me, $messageId);
            if (isset($res['error'])) {
                echo json_encode(['status' => 'error', 'message' => $res['error'], 'balance' => $res['balance'] ?? null, 'required' => $res['required'] ?? null]);
                exit;
            }
            echo json_encode(['status' => 'success'] + $res);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'server_error']);
        }
        exit;
    }

    /*Send Image Message*/
    if ($p === 'sendImageMessage') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me   = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $with = (int) ($_POST['with'] ?? 0);
            if ($me <= 0 || $with <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters', 'message_text' => customLang('invalid_parameters')]);
                exit;
            }
            if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
                echo json_encode(['status' => 'error', 'message' => 'no_image_uploaded', 'message_text' => customLang('no_image_uploaded')]);
                exit;
            }

            $err = (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) {
                echo json_encode(['status' => 'error', 'message' => 'upload_error', 'message_text' => customLang('upload_error')]);
                exit;
            }

            $tmp  = (string) $_FILES['image']['tmp_name'];
            $name = (string) $_FILES['image']['name'];
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $okExt = ['jpg','jpeg','png','gif','webp'];
            if (!in_array($ext, $okExt, true)) {
                echo json_encode(['status' => 'error', 'message' => 'unsupported_file_type', 'message_text' => customLang('unsupported_file_type')]);
                exit;
            }
            $info = getimagesize($tmp);
            if ($info === false && defined('APP_DEBUG') && APP_DEBUG) { error_log('chat: getimagesize() failed'); }
            if ($info === false) {
                echo json_encode(['status' => 'error', 'message' => 'not_an_image', 'message_text' => customLang('not_an_image')]);
                exit;
            }

            // Save under /uploads/files/YYYY-MM-DD/
            $todayDirFs = createTodayDirectory();
            $baseName   = 'chat_' . $me . '_' . time() . '_' . mt_rand(1000,9999) . '.' . $ext;
            $destFs     = rtrim($todayDirFs, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $baseName;

            $ok = move_uploaded_file($tmp, $destFs);
            if (!$ok && defined('APP_DEBUG') && APP_DEBUG) { error_log('chat: move_uploaded_file() failed'); }
            if (!$ok) {
                echo json_encode(['status' => 'error', 'message' => 'store_failed', 'message_text' => customLang('store_failed')]);
                exit;
            }

            // Build web-relative path (strip ../includes)
            $todayRel = 'uploads/files/' . basename($todayDirFs) . '/' . $baseName;

            $row = $RL->RL_SendFileMessage($me, $with, $todayRel, 'image');
            if (isset($row['error'])) {
                echo json_encode(['status' => 'error']);
                exit;
            }

            echo json_encode(['status' => 'success', 'data' => $row]);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'server_error', 'message_text' => customLang('server_error')]);
        }
        exit;
    }

    /*Send Video Message*/
    if ($p === 'sendVideoMessage') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me   = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $with = (int) ($_POST['with'] ?? 0);
            if ($me <= 0 || $with <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters', 'message_text' => customLang('invalid_parameters')]);
                exit;
            }
            if (!isset($_FILES['video']) || !is_array($_FILES['video'])) {
                echo json_encode(['status' => 'error', 'message' => 'no_video_uploaded', 'message_text' => customLang('no_video_uploaded')]);
                exit;
            }

            $err = (int) ($_FILES['video']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) {
                echo json_encode(['status' => 'error', 'message' => 'upload_error', 'message_text' => customLang('upload_error')]);
                exit;
            }

            $tmp  = (string) $_FILES['video']['tmp_name'];
            $name = (string) $_FILES['video']['name'];
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $okExt = ['mp4','mov','m4v','webm','avi','mkv'];
            if (!in_array($ext, $okExt, true)) {
                echo json_encode(['status' => 'error', 'message' => 'unsupported_file_type', 'message_text' => customLang('unsupported_file_type')]);
                exit;
            }

            $uploadDirFs = createTodayDirectory();
            if (!is_dir($uploadDirFs)) {
                $ok = mkdir($uploadDirFs, 0755, true);
                if (!$ok && defined('APP_DEBUG') && APP_DEBUG) { error_log('chat: mkdir() failed'); }
            }
            $baseBase = 'chatvid_' . $me . '_' . time() . '_' . mt_rand(1000,9999);
            $srcFs    = rtrim($uploadDirFs, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $baseBase . '.' . $ext;
            $ok = move_uploaded_file($tmp, $srcFs);
            if (!$ok && defined('APP_DEBUG') && APP_DEBUG) { error_log('chat: move_uploaded_file() failed'); }
            if (!$ok) {
                echo json_encode(['status' => 'error', 'message' => 'store_failed', 'message_text' => customLang('store_failed')]);
                exit;
            }

            $ffmpegBin = dz_resolve_binary(isset($ffmpegPath) ? (string) $ffmpegPath : null, ['ffmpeg']);
            if ($ffmpegBin === null) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'ffmpeg_unavailable',
                    'message_text' => 'FFmpeg binary is not available on the server. Configure the correct path in admin settings.'
                ]);
                exit;
            }

            $outFs  = rtrim($uploadDirFs, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $baseBase . '.mp4';
            $cmd    = escapeshellarg($ffmpegBin) . ' -y -i ' . escapeshellarg($srcFs)
                    . ' -c:v libx264 -preset veryfast -crf 23 -c:a aac -movflags +faststart '
                    . escapeshellarg($outFs) . ' 2>&1';
            $ok = shell_exec($cmd);
            if ($ok === null && defined('APP_DEBUG') && APP_DEBUG) { error_log('chat: shell_exec() failed'); }

            if (!is_file($outFs) || filesize($outFs) <= 1024) {
                echo json_encode(['status' => 'error', 'message' => 'ffmpeg_failed', 'message_text' => customLang('ffmpeg_failed')]);
                exit;
            }

            // remove original uploaded if different from output
            if (is_file($srcFs) && realpath($srcFs) !== realpath($outFs)) {
                $ok = unlink($srcFs);
                if (!$ok && defined('APP_DEBUG') && APP_DEBUG) { error_log('chat: unlink() failed'); }
            }

            // Build web-relative path: uploads/files/YYYY-MM-DD/<name>.mp4
            $marker   = '/uploads/files/';
            $uploadFs = str_replace('\\', '/', $uploadDirFs);
            $relDir   = 'uploads/files/' . basename(rtrim($uploadFs, '/'));
            $pos      = strpos($uploadFs, $marker);
            if ($pos !== false) {
                $relDir = ltrim(substr($uploadFs, $pos), '/');
            }
            $relDir = preg_replace('#/+#', '/', $relDir);
            $todayRel = $relDir . '/' . basename($outFs);

            $row = $RL->RL_SendFileMessage($me, $with, $todayRel, 'video');
            if (isset($row['error'])) {
                echo json_encode(['status' => 'error']);
                exit;
            }
            echo json_encode(['status' => 'success', 'data' => $row]);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'server_error', 'message_text' => customLang('server_error')]);
        }
        exit;
    }

    /*Typing: Ping (start/keepalive/stop)*/
    if ($p === 'typingPing') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me    = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $with  = (int) ($_POST['with'] ?? 0);
            $flag  = isset($_POST['typing']) ? (int) $_POST['typing'] : 1; // 1=start/keepalive, 0=stop
            $isOn  = $flag === 1;

            if ($me <= 0 || $with <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters']);
                exit;
            }

            $res = $RL->RL_SetTyping($me, $with, $isOn);
            if (isset($res['error'])) {
                echo json_encode(['status' => 'error']);
                exit;
            }
            echo json_encode(['status' => 'success', 'is_typing' => $isOn]);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error']);
        }
        exit;
    }

    /*Typing: Status (is other user typing to me?)*/
    if ($p === 'typingStatus') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me   = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $with = (int) ($_POST['with'] ?? 0);
            if ($me <= 0 || $with <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'invalid_parameters', 'message_text' => customLang('invalid_parameters')]);
                exit;
            }
            if (method_exists($RL, 'RL_PruneTyping')) { $RL->RL_PruneTyping(45); }
            $typing = $RL->RL_IsTyping($with, $me, 8);
            echo json_encode(['status' => 'success', 'typing' => (bool) $typing]);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'error']);
        }
        exit;
    }

    /* Render small preview card for a shared post (used by chat.js) */
    if ($p === 'postSharePreview') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $me   = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $pid  = (int) ($_POST['post_id'] ?? 0);
            if ($me <= 0 || $pid <= 0) { echo json_encode(['status'=>'error','message'=>'invalid_parameters']); exit; }
            if (!isset($RL) || !method_exists($RL, 'RL_GetPostById')) { echo json_encode(['status'=>'error','message'=>'not_supported']); exit; }
            $post = $RL->RL_GetPostById($pid, $me, null);
            $lockedLabel = customLang('chat_locked_post_placeholder');
            $html = '';
            if ($post) {
                $owner = (string)($post['owner_username'] ?? '');
                $url   = rtrim((string)$base_url, '/') . '/posts/' . $pid . '/' . $owner;
                $type  = (string)($post['post_type'] ?? '');
                $files = array_filter(array_map('trim', explode(',', (string)($post['post_file'] ?? ''))));
                $thumb = $files ? $files[0] : '';
                $coverRel = '';
                if ($type === 'podcast' && method_exists($RL, 'RL_GetPostCoverPath')) {
                    try { $coverRel = (string) $RL->RL_GetPostCoverPath($pid); } catch (\Throwable $__) { $coverRel = ''; }
                }
                $thumbUrlResolved = $thumb !== '' ? storage_url($thumb) : '';
                $coverUrlResolved = $coverRel !== '' ? storage_url($coverRel) : '';
                $label = $owner !== '' ? $owner : 'post';
                $altTxt = dzShortCaption($post, 80);
                if ($altTxt === '') { $altTxt = sprintf(customLang('alt_post_by_user'), $owner ?: 'user'); }
                ob_start(); ?>
                <a class="chat-share-card" href="<?php echo iN_HelpSecure($url); ?>" target="_blank" rel="noopener noreferrer">
                  <div class="csp-thumb">
                    <?php if ($type === 'image' && $thumbUrlResolved !== ''): ?>
                      <img src="<?php echo iN_HelpSecure($thumbUrlResolved); ?>" alt="<?php echo iN_HelpSecure($altTxt); ?>">
                    <?php elseif ($type === 'video' && $thumbUrlResolved !== ''): ?>
                      <?php $thumbVid = $thumbUrlResolved; if ($thumbVid !== '' && method_exists($RL, 'videoThumbNail')) { try { $thumbVid = $RL->videoThumbNail($thumbVid); } catch (\Throwable $__) {} } ?>
                      <?php if ($thumbVid !== ''): ?>
                        <img src="<?php echo iN_HelpSecure($thumbVid); ?>" alt="<?php echo iN_HelpSecure($altTxt); ?>">
                        <span class="csp-play">&#9658;</span>
                      <?php else: ?>
                        <div class="csp-placeholder">Post</div>
                      <?php endif; ?>
                    <?php elseif ($type === 'podcast'): ?>
                      <?php if ($coverUrlResolved !== ''): ?>
                        <img src="<?php echo iN_HelpSecure($coverUrlResolved); ?>" alt="<?php echo iN_HelpSecure($altTxt); ?>">
                        <span class="csp-play">&#9658;</span>
                      <?php else: ?>
                        <div class="csp-placeholder">Post</div>
                      <?php endif; ?>
                    <?php else: ?>
                      <div class="csp-placeholder">Post</div>
                    <?php endif; ?>
                  </div>
                  <div class="csp-meta">
                    <div class="csp-user">@<?php echo iN_HelpSecure($label); ?></div>
                    <div class="csp-type"><?php echo iN_HelpSecure($type === 'video' ? customLang('post_type_reel', 'Reel') : ($type === 'podcast' ? customLang('menu_podcasts') : customLang('post_type_post', 'Post'))); ?></div>
                  </div>
                </a>
                <?php $html = trim(ob_get_clean());
            } else {
                $html = render_chat_locked_placeholder($pid, (string)($base_url ?? ''), $lockedLabel);
            }
            echo json_encode(['status'=>'success','html'=>$html]);
        } catch (\Throwable $e) {
            echo json_encode(['status'=>'error','message'=>'server_error']);
        }
        exit;
    }
}
?>
