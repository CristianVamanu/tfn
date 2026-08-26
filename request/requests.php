<?php

use CreatorPulse\App\Controllers\PaymentHandler;
use CreatorPulse\App\Controllers\PodcastAdsHandler;
use CreatorPulse\App\Controllers\ReelsHandler;
use CreatorPulse\App\Controllers\UserHandler;

include "../includes/inc.php";
// Payments are loaded lazily via PaymentFactory when needed
require_once __DIR__ . '/../includes/payments/PaymentFactory.php';
require_once __DIR__ . '/../app/Controllers/UserHandler.php';
require_once __DIR__ . '/../app/Controllers/PaymentHandler.php';
require_once __DIR__ . '/../app/Controllers/ReelsHandler.php';
require_once __DIR__ . '/../app/Controllers/PodcastAdsHandler.php';

$userHandler = new UserHandler($RL);
$paymentHandler = new PaymentHandler($RL);
$reelsHandler = new ReelsHandler($RL);
$podcastAdsHandler = new PodcastAdsHandler($RL);

header('Content-Type: application/json; charset=utf-8');

$dzJsonOptions = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $dzJsonOptions |= JSON_INVALID_UTF8_SUBSTITUTE;
} else {
    $dzJsonOptions |= JSON_PARTIAL_OUTPUT_ON_ERROR;
}
if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
    $dzJsonOptions |= JSON_PARTIAL_OUTPUT_ON_ERROR;
}

if (!function_exists('dz_json_response')) {
    /**
     * Safely echo a JSON payload ensuring invalid UTF-8 doesn't break the response.
     */
    function dz_json_response($payload, int $statusCode = 200): void
    {
        global $dzJsonOptions;

        // Guarantee pristine output (prevents stray whitespace/HTML from previous buffers)
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        http_response_code($statusCode);
        $json = json_encode($payload, $dzJsonOptions);

        if ($json === false) {
            $fallback = [
                'status'  => 'error',
                'message' => 'JSON encoding error.',
                'code'    => 'JSON_ENCODE_ERROR',
            ];
            $json = json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $json = '{"status":"error","message":"JSON encoding error.","code":"JSON_ENCODE_ERROR"}';
            }
            http_response_code(500);
        }

        echo $json;
    }
}

$liveStreamingEnabledGlobal = isset($liveStreamingEnabled) ? (bool)$liveStreamingEnabled : true;
$liveChatEnabledGlobal = isset($liveChatEnabled) ? (bool)$liveChatEnabled : true;
$liveViewerLimitGlobal = isset($liveViewerLimit) ? (int)$liveViewerLimit : 0;
$agoraReadOnlyTokenGlobal = (isset($agoraReadOnlyToken) && is_string($agoraReadOnlyToken)) ? $agoraReadOnlyToken : '';
$audioRoomsEnabledGlobal = isset($audioRoomsEnabled) ? (bool)$audioRoomsEnabled : true;
$audioRoomChatEnabledGlobal = isset($audioRoomChatEnabled) ? (bool)$audioRoomChatEnabled : true;
$audioRoomPaidEnabledGlobal = isset($audioRoomPaidEnabled) ? (bool)$audioRoomPaidEnabled : true;
$audioRoomCustomPriceEnabledGlobal = isset($audioRoomCustomPriceEnabled) ? (bool)$audioRoomCustomPriceEnabled : true;
$audioRoomPricePresetsGlobal = isset($audioRoomPricePresets) && is_array($audioRoomPricePresets) ? $audioRoomPricePresets : [5.0, 10.0, 15.0, 20.0];
$audioRoomPriceMinimumGlobal = isset($audioRoomPriceMinimum) ? (float)$audioRoomPriceMinimum : 1.0;
$audioRoomPriceMaximumGlobal = isset($audioRoomPriceMaximum) ? (float)$audioRoomPriceMaximum : 500.0;
$audioRoomNonCreatorDailyMinutesGlobal = isset($audioRoomNonCreatorDailyMinutes) ? (int)$audioRoomNonCreatorDailyMinutes : 60;
$audioRoomMaxSpeakersGlobal = isset($audioRoomMaxSpeakers) ? (int)$audioRoomMaxSpeakers : 12;
$audioRoomMaxListenersGlobal = isset($audioRoomMaxListeners) ? (int)$audioRoomMaxListeners : 0;

if (!function_exists('dz_audio_room_session_key')) {
    function dz_audio_room_session_key(): string
    {
        $session = session_id();
        if (is_string($session) && $session !== '') {
            return substr(hash('sha256', 'session|' . $session), 0, 48);
        }
        return substr(hash('sha256', 'guest|' . ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 48);
    }
}

if (!function_exists('dz_audio_room_can_manage')) {
    function dz_audio_room_can_manage($RL, array $room, int $userId): bool
    {
        $ownerId = (int)($room['owner_id'] ?? 0);
        if ($userId <= 0) { return false; }
        if ($ownerId > 0 && $ownerId === $userId) { return true; }
        return isset($RL) && method_exists($RL, 'RL_IsAudioRoomModerator') && $RL->RL_IsAudioRoomModerator((int)($room['room_id'] ?? 0), $userId);
    }
}

if (!function_exists('dz_audio_room_decorate_user_rows')) {
    function dz_audio_room_decorate_user_rows(array $rows, string $baseUrl): array
    {
        return array_map(static function (array $row) use ($baseUrl): array {
            $avatar = (string)($row['user_avatar'] ?? '');
            if ($avatar === '') {
                $avatar = 'uploads/avatars/default_avatar.png';
            }
            $row['avatar_url'] = function_exists('storage_resolve_media_url')
                ? storage_resolve_media_url($avatar, $baseUrl)
                : rtrim($baseUrl, '/') . '/' . ltrim($avatar, '/');
            $row['display_name'] = trim((string)($row['user_fullname'] ?? '')) !== ''
                ? trim((string)$row['user_fullname'])
                : (string)($row['username'] ?? '');
            return $row;
        }, $rows);
    }
}

if (!function_exists('dz_audio_room_viewer_role')) {
    function dz_audio_room_viewer_role($RL, int $roomId, array $room, int $userId): string
    {
        if ($userId <= 0) { return 'listener'; }
        $ownerId = (int)($room['owner_id'] ?? 0);
        if ($ownerId > 0 && $userId === $ownerId) { return 'host'; }
        if (isset($RL) && method_exists($RL, 'RL_IsAudioRoomModerator') && $RL->RL_IsAudioRoomModerator($roomId, $userId)) {
            return 'moderator';
        }
        if (isset($RL) && method_exists($RL, 'RL_IsAudioRoomSpeaker') && $RL->RL_IsAudioRoomSpeaker($roomId, $userId)) {
            return 'speaker';
        }
        return 'listener';
    }
}

if (!function_exists('dz_ebook_upload_file')) {
    function dz_ebook_upload_file(string $field, array $allowedExt, string $targetFolder, string $prefix, int $maxMb = 80): array
    {
        if (!isset($_FILES[$field]) || !is_array($_FILES[$field]) || (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'missing_file'];
        }
        $file = $_FILES[$field];
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > ($maxMb * 1024 * 1024)) {
            return ['ok' => false, 'error' => 'file_too_large'];
        }
        $original = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            return ['ok' => false, 'error' => 'file_type_not_supported'];
        }
        $projectRoot = realpath(__DIR__ . '/..');
        if ($projectRoot === false) {
            return ['ok' => false, 'error' => 'upload_failed'];
        }
        $dateDir = date('Y-m-d');
        $relativeDir = trim($targetFolder, '/') . '/' . $dateDir;
        $absoluteDir = $projectRoot . '/' . $relativeDir;
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true)) {
            return ['ok' => false, 'error' => 'upload_failed'];
        }
        $safeName = $prefix . '_' . time() . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
        $absolute = $absoluteDir . '/' . $safeName;
        if (!move_uploaded_file((string)$file['tmp_name'], $absolute)) {
            return ['ok' => false, 'error' => 'upload_failed'];
        }
        return [
            'ok' => true,
            'path' => $relativeDir . '/' . $safeName,
            'name' => $original,
            'size' => $size,
            'ext' => $ext,
        ];
    }
}

if (!function_exists('dz_audio_room_state_payload')) {
    function dz_audio_room_state_payload($RL, int $roomId, int $viewerId, string $baseUrl): array
    {
        $room = isset($RL) && method_exists($RL, 'RL_GetAudioRoomById') ? $RL->RL_GetAudioRoomById($roomId) : null;
        if (!$room) {
            return ['status' => 'error', 'message' => 'room_not_found'];
        }
        if (method_exists($RL, 'RL_MaybeAutoEndAudioRoom') && $RL->RL_MaybeAutoEndAudioRoom($roomId)) {
            $room = $RL->RL_GetAudioRoomById($roomId) ?: $room;
        }
        $role = dz_audio_room_viewer_role($RL, $roomId, (array)$room, $viewerId);
        $speakerStatus = $viewerId > 0 && method_exists($RL, 'RL_GetAudioRoomSpeakerStatus')
            ? $RL->RL_GetAudioRoomSpeakerStatus($roomId, $viewerId)
            : '';
        $participant = $viewerId > 0 && method_exists($RL, 'RL_GetAudioRoomParticipantState')
            ? $RL->RL_GetAudioRoomParticipantState($roomId, $viewerId)
            : [];
        $isManager = dz_audio_room_can_manage($RL, (array)$room, $viewerId);
        $participants = [];
        $speakerRequests = [];
        if ($isManager) {
            $participants = method_exists($RL, 'RL_GetAudioRoomParticipants')
                ? $RL->RL_GetAudioRoomParticipants($roomId, time() - 60, 100)
                : [];
            $speakerRequests = method_exists($RL, 'RL_GetAudioRoomSpeakerRequests')
                ? $RL->RL_GetAudioRoomSpeakerRequests($roomId, 'pending', 50)
                : [];
            $participants = dz_audio_room_decorate_user_rows($participants, $baseUrl);
            $speakerRequests = dz_audio_room_decorate_user_rows($speakerRequests, $baseUrl);
            if (method_exists($RL, 'RL_GetAudioRoomSpeakerStatus')) {
                foreach ($participants as &$participantRow) {
                    $participantUserId = (int)($participantRow['user_id'] ?? 0);
                    $participantRow['speaker_status'] = $participantUserId > 0 ? $RL->RL_GetAudioRoomSpeakerStatus($roomId, $participantUserId) : '';
                    $participantRow['chat_mute_remaining'] = $participantUserId > 0 && method_exists($RL, 'RL_GetAudioRoomChatMuteRemaining') ? $RL->RL_GetAudioRoomChatMuteRemaining($roomId, $participantUserId) : 0;
                }
                unset($participantRow);
            }
        }
        return [
            'status' => 'success',
            'room_status' => (string)($room['status'] ?? 'created'),
            'role' => $role,
            'speaker_status' => $speakerStatus,
            'participant' => $participant,
            'chat_mute_remaining' => $viewerId > 0 && method_exists($RL, 'RL_GetAudioRoomChatMuteRemaining') ? $RL->RL_GetAudioRoomChatMuteRemaining($roomId, $viewerId) : 0,
            'speakers' => method_exists($RL, 'RL_CountAudioRoomParticipants') ? $RL->RL_CountAudioRoomParticipants($roomId, time() - 60, ['host','moderator','speaker']) : 0,
            'listeners' => method_exists($RL, 'RL_CountAudioRoomParticipants') ? $RL->RL_CountAudioRoomParticipants($roomId, time() - 60, ['listener']) : 0,
            'server_time' => time(),
            'auto_end_at' => isset($room['auto_end_at']) ? (int)$room['auto_end_at'] : 0,
            'ended_at' => isset($room['ended_at']) ? (int)$room['ended_at'] : 0,
            'end_reason' => (string)($room['end_reason'] ?? ''),
            'can_manage' => $isManager ? 1 : 0,
            'participants' => $participants,
            'speaker_requests' => $speakerRequests,
        ];
    }
}

$walletTopupLimits = ['enabled' => false, 'min' => 0.0, 'max' => 0.0];
if (isset($RL) && is_object($RL) && method_exists($RL, 'RL_GetWalletTopupLimits')) {
    try {
        $fetchedLimits = (array) $RL->RL_GetWalletTopupLimits();
        $walletTopupLimits = array_merge($walletTopupLimits, $fetchedLimits);
    } catch (Throwable $__) {
        // Best-effort; fall back to defaults when lookup fails.
    }
}
$walletTopupLimits['enabled'] = (bool) ($walletTopupLimits['enabled'] ?? false);
$walletTopupLimits['min'] = (float) ($walletTopupLimits['min'] ?? 0.0);
$walletTopupLimits['max'] = (float) ($walletTopupLimits['max'] ?? 0.0);

$walletTopupAdminOverride = isset($isAdminUser)
    ? (bool) $isAdminUser
    : (bool) ($GLOBALS['isAdminUser'] ?? false);
if ($walletTopupAdminOverride) {
    $walletTopupLimits['enabled'] = true;
}
$imagesEnabledGlobal = !empty($enableImagePosts);
$videosEnabledGlobal = !empty($enableVideoPosts);
$podcastsEnabledGlobal = isset($enablePodcastPosts) ? (bool) $enablePodcastPosts : true;
$adsEnabledGlobal    = !empty($enableAds);
$adsDisabledResponse = [
    'status' => 'error',
    'message' => customLang('content_disabled_ads', 'Advertisements are currently disabled.'),
];

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

// ---- Public Webhook Endpoints (compat + unified) ----
// CLI argument support: allow `php requests.php p=subscriptions_renew`
if (PHP_SAPI === 'cli' && isset($argv) && is_array($argv)) {
    $cliP = null;
    foreach (array_slice($argv, 1) as $arg) {
        if (strpos($arg, '=') !== false) {
            [$k, $v] = explode('=', $arg, 2);
            $k = trim($k);
            if ($k === '') {
                continue;
            }
            $v = trim($v);
            if (!isset($_GET[$k]) && !isset($_POST[$k])) {
                $_GET[$k] = $v;
            }
            if ($k === 'p' && $cliP === null) {
                $cliP = $v;
            }
        } else {
            if ($cliP === null) {
                $cliP = $arg;
            }
        }
    }
    if ($cliP !== null && !isset($_GET['p']) && !isset($_POST['p'])) {
        $_GET['p'] = $cliP;
    }
}
$pIncoming = strtolower(trim((string)($_GET['p'] ?? $_POST['p'] ?? '')));
$requestConfigBool = static function ($value, bool $default = false): bool {
    if ($value === null) {
        return $default;
    }
    if (is_bool($value)) {
        return $value;
    }
    $normalized = strtolower(trim((string)$value));
    if ($normalized === '') {
        return $default;
    }
    return in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true);
};
$ebooksEnabledRequest = $requestConfigBool($siteData['ebooks_enabled'] ?? null, true);
$ebookCreatorUploadsEnabledRequest = $requestConfigBool($siteData['ebook_creator_uploads_enabled'] ?? null, true);

if ($pIncoming === 'ebook_wishlist_toggle') {
    $uid=isset($userID)?(int)$userID:0;
    if($loggedIn!=='1'||$uid<=0){dz_json_response(['status'=>'error','message'=>customLang('login_required')],401);exit;}
    if(!function_exists('checkCsrfToken')||!checkCsrfToken((string)($_POST['csrf_token']??''))){dz_json_response(['status'=>'error','message'=>customLang('invalid_csrf_token')],403);exit;}
    $state=isset($RL)&&method_exists($RL,'RL_ToggleEbookWishlist')?$RL->RL_ToggleEbookWishlist((int)($_POST['ebook_id']??0),$uid):null;
    if($state===null){dz_json_response(['status'=>'error','message'=>customLang('ebook_not_found','eBook not found.')],404);exit;}
    dz_json_response(['status'=>'success','wishlisted'=>$state,'message'=>$state?customLang('ebook_wishlist_added','Added to wishlist.'):customLang('ebook_wishlist_removed','Removed from wishlist.')]);exit;
}

if ($pIncoming === 'ebook_coupon_validate') {
    $ebookId=(int)($_POST['ebook_id']??0);$code=trim((string)($_POST['coupon_code']??''));
    $ebook=isset($RL)&&method_exists($RL,'RL_GetEbookById')?$RL->RL_GetEbookById($ebookId,(int)($userID??0)):null;
    $coupon=$ebook&&method_exists($RL,'RL_ValidateEbookCoupon')?$RL->RL_ValidateEbookCoupon($code,$ebookId,(float)$ebook['price']):null;
    if(!$coupon){dz_json_response(['status'=>'error','message'=>customLang('ebook_coupon_invalid','This coupon is invalid or expired.')],422);exit;}
    dz_json_response(['status'=>'success','code'=>(string)$coupon['code'],'discount_amount'=>(float)$coupon['discount_amount'],'final_amount'=>(float)$coupon['final_amount'],'final_display'=>function_exists('dz_format_currency')?dz_format_currency((float)$coupon['final_amount'],(string)$ebook['currency']):number_format((float)$coupon['final_amount'],2)]);exit;
}

if ($pIncoming === 'ebook_sample') {
    $ebookId=(int)($_GET['id']??0);$ebook=isset($RL)&&method_exists($RL,'RL_GetEbookById')?$RL->RL_GetEbookById($ebookId,(int)($userID??0)):null;
    $remote=(string)($ebook['sample_path']??'');
    if(!$ebook||(string)($ebook['status']??'')!=='active'||$remote===''||!str_starts_with(ltrim($remote,'/'),'uploads/ebooks/samples/')){http_response_code(404);exit;}
    $absolute=function_exists('storage_resolve_absolute_path')?storage_resolve_absolute_path($remote):realpath(__DIR__.'/../'.ltrim($remote,'/'));
    if(!$absolute||!is_file($absolute)){http_response_code(404);exit;}
    while(ob_get_level()>0){ob_end_clean();} header('Content-Type: application/pdf');header('Content-Length: '.filesize($absolute));header('Content-Disposition: inline; filename="sample.pdf"');header('X-Content-Type-Options: nosniff');readfile($absolute);exit;
}

if ($pIncoming === 'ebook_review_save') {
    try {
        $uid = isset($userID) ? (int)$userID : 0;
        if (!$ebooksEnabledRequest || $loggedIn !== '1' || $uid <= 0) {
            dz_json_response(['status'=>'error','message'=>customLang('login_required')], 401); exit;
        }
        if (!function_exists('checkCsrfToken') || !checkCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
            dz_json_response(['status'=>'error','message'=>customLang('invalid_csrf_token')], 403); exit;
        }
        $ebookId = (int)($_POST['ebook_id'] ?? 0);
        $rating = (int)($_POST['rating'] ?? 0);
        $body = trim((string)($_POST['review_text'] ?? ''));
        if ($ebookId <= 0 || $rating < 1 || $rating > 5 || !isset($RL) || !method_exists($RL, 'RL_SaveEbookReview')) {
            dz_json_response(['status'=>'error','message'=>customLang('ebook_review_invalid','Choose a rating from 1 to 5.')], 422); exit;
        }
        if (!$RL->RL_SaveEbookReview($ebookId, $uid, $rating, $body)) {
            dz_json_response(['status'=>'error','message'=>customLang('ebook_review_access_required','Only verified readers can review this eBook.')], 403); exit;
        }
        dz_json_response(['status'=>'success','message'=>customLang('ebook_review_saved','Your review was saved.')]);
    } catch (Throwable $e) { dz_json_response(['status'=>'error','message'=>customLang('server_error','Server error.')], 500); }
    exit;
}

if ($pIncoming === 'ebook_download_prepare') {
    try {
        if (!$ebooksEnabledRequest) {
            dz_json_response(['status' => 'error', 'message' => customLang('ebooks_disabled', 'eBooks are disabled.')], 403);
            exit;
        }
        $uid = isset($userID) ? (int)$userID : 0;
        $ebookId = (int)($_POST['ebook_id'] ?? 0);
        if ($loggedIn !== '1' || $uid <= 0) {
            dz_json_response(['status' => 'error', 'message' => customLang('login_required')], 401);
            exit;
        }
        if (!function_exists('checkCsrfToken') || !checkCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
            dz_json_response(['status' => 'error', 'message' => customLang('invalid_csrf_token')], 403);
            exit;
        }
        if ($ebookId <= 0 || !isset($RL) || !method_exists($RL, 'RL_GetEbookById') || !method_exists($RL, 'RL_UserCanDownloadEbook')) {
            dz_json_response(['status' => 'error', 'message' => customLang('ebook_not_found', 'eBook not found.')], 404);
            exit;
        }
        $ebook = $RL->RL_GetEbookById($ebookId, $uid);
        if (!$ebook || !$RL->RL_UserCanDownloadEbook($ebookId, $uid)) {
            dz_json_response(['status' => 'error', 'message' => customLang('ebook_not_available', 'This eBook is not available.')], 403);
            exit;
        }
        if (method_exists($RL, 'RL_GetEbookEntitlement') && method_exists($RL, 'RL_GrantEbookEntitlement')) {
            $entitlement = $RL->RL_GetEbookEntitlement($ebookId, $uid);
            if (!$entitlement) {
                $source = ((int)($ebook['user_id'] ?? 0) === $uid) ? 'owner' : 'free';
                if (!$RL->RL_GrantEbookEntitlement($ebookId, $uid, null, $source)) {
                    dz_json_response(['status' => 'error', 'message' => customLang('ebook_download_unavailable', 'Download access could not be created.')], 409);
                    exit;
                }
            }
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $now = time();
        $tokens = is_array($_SESSION['ebook_download_tokens'] ?? null) ? $_SESSION['ebook_download_tokens'] : [];
        $tokens = array_filter($tokens, static fn($entry) => is_array($entry) && (int)($entry['expires'] ?? 0) >= $now);
        if (count($tokens) >= 20) {
            uasort($tokens, static fn($a, $b) => ((int)($a['expires'] ?? 0)) <=> ((int)($b['expires'] ?? 0)));
            $tokens = array_slice($tokens, -19, null, true);
        }
        $plainToken = bin2hex(random_bytes(24));
        $tokens[hash('sha256', $plainToken)] = ['ebook_id' => $ebookId, 'user_id' => $uid, 'expires' => $now + 300];
        $_SESSION['ebook_download_tokens'] = $tokens;
        $downloadUrl = rtrim((string)($base_url ?? '/'), '/') . '/request/requests.php?p=ebook_download&id=' . $ebookId . '&token=' . rawurlencode($plainToken);
        dz_json_response(['status' => 'success', 'download_url' => $downloadUrl]);
    } catch (Throwable $e) {
        dz_json_response(['status' => 'error', 'message' => customLang('server_error', 'Server error.')], 500);
    }
    exit;
}

if ($pIncoming === 'ebook_download') {
    try {
        if (!$ebooksEnabledRequest) {
            dz_json_response(['status' => 'error', 'message' => customLang('ebooks_disabled', 'eBooks are disabled.')], 403);
            exit;
        }
        $ebookId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        $uid = isset($userID) ? (int)$userID : 0;
        if ($loggedIn !== '1' || $uid <= 0) {
            dz_json_response(['status' => 'error', 'message' => customLang('login_required')], 401);
            exit;
        }
        if ($ebookId <= 0 || !isset($RL) || !method_exists($RL, 'RL_GetEbookById') || !method_exists($RL, 'RL_UserCanDownloadEbook')) {
            dz_json_response(['status' => 'error', 'message' => customLang('ebook_not_found', 'eBook not found.')], 404);
            exit;
        }
        $ebook = $RL->RL_GetEbookById($ebookId, $uid);
        if (!$ebook || !$RL->RL_UserCanDownloadEbook($ebookId, $uid)) {
            dz_json_response(['status' => 'error', 'message' => customLang('ebook_not_available', 'This eBook is not available.')], 403);
            exit;
        }
        $plainToken = trim((string)($_GET['token'] ?? ''));
        if ($plainToken === '' || session_status() !== PHP_SESSION_ACTIVE) {
            dz_json_response(['status' => 'error', 'message' => customLang('ebook_download_link_expired', 'This download link has expired. Please try again.')], 403);
            exit;
        }
        $tokenHash = hash('sha256', $plainToken);
        $tokenData = $_SESSION['ebook_download_tokens'][$tokenHash] ?? null;
        unset($_SESSION['ebook_download_tokens'][$tokenHash]);
        if (!is_array($tokenData)
            || (int)($tokenData['ebook_id'] ?? 0) !== $ebookId
            || (int)($tokenData['user_id'] ?? 0) !== $uid
            || (int)($tokenData['expires'] ?? 0) < time()) {
            dz_json_response(['status' => 'error', 'message' => customLang('ebook_download_link_expired', 'This download link has expired. Please try again.')], 403);
            exit;
        }
        $remotePath = (string)($ebook['file_path'] ?? '');
        if ($remotePath === '' || !str_starts_with(ltrim($remotePath, '/'), 'uploads/ebooks/files/')) {
            dz_json_response(['status' => 'error', 'message' => customLang('ebook_not_found', 'eBook not found.')], 404);
            exit;
        }
        $absolute = function_exists('storage_resolve_absolute_path')
            ? storage_resolve_absolute_path($remotePath)
            : realpath(__DIR__ . '/../' . ltrim($remotePath, '/'));
        if ($absolute === '' || !is_file($absolute)) {
            dz_json_response(['status' => 'error', 'message' => customLang('ebook_not_found', 'eBook not found.')], 404);
            exit;
        }
        $fileSize = (int)filesize($absolute);
        if (method_exists($RL, 'RL_LogEbookDownload') && !$RL->RL_LogEbookDownload($ebookId, $uid, $fileSize)) {
            dz_json_response(['status' => 'error', 'message' => customLang('ebook_download_limit_reached', 'Your download limit has been reached.')], 403);
            exit;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $downloadName = trim((string)($ebook['file_name'] ?? ''));
        if ($downloadName === '') {
            $downloadName = basename($absolute);
        }
        $ext = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
        $contentType = match ($ext) {
            'pdf' => 'application/pdf',
            'epub' => 'application/epub+zip',
            'mobi' => 'application/x-mobipocket-ebook',
            default => 'application/octet-stream',
        };
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . $fileSize);
        header('Content-Disposition: attachment; filename="' . addslashes($downloadName) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($absolute);
    } catch (Throwable $e) {
        dz_json_response(['status' => 'error', 'message' => customLang('server_error', 'Server error.')], 500);
    }
    exit;
}

if ($pIncoming === 'ebook_owner_update' || $pIncoming === 'ebook_owner_action') {
    try {
        $uid = isset($userID) ? (int)$userID : 0;
        if ($loggedIn !== '1' || $uid <= 0) { dz_json_response(['status'=>'error','message'=>customLang('login_required')],401); exit; }
        if (!checkCsrfToken((string)($_POST['csrf_token'] ?? ''))) { dz_json_response(['status'=>'error','message'=>customLang('invalid_csrf_token')],403); exit; }
        $ebookId=max(0,(int)($_POST['ebook_id']??0));
        if($pIncoming==='ebook_owner_update'){
            $result=$RL->RL_UpdateOwnerEbook($ebookId,$uid,$_POST);
        } else {
            $result=$RL->RL_OwnerEbookAction($ebookId,$uid,(string)($_POST['owner_action']??''));
        }
        if(empty($result['ok'])){
            $key=(string)($result['error']??'server_error');
            dz_json_response(['status'=>'error','message'=>customLang($key,'The eBook could not be updated.')],$key==='ebook_not_found'?404:422); exit;
        }
        dz_json_response(['status'=>'success','message'=>customLang('ebook_management_saved','eBook changes saved.'),'ebook'=>$result]);
    } catch(Throwable $e){ dz_json_response(['status'=>'error','message'=>customLang('server_error','Server error.')],500); }
    exit;
}

if ($pIncoming === 'ebook_create') {
    try {
        if (!$ebooksEnabledRequest || !$ebookCreatorUploadsEnabledRequest) {
            dz_json_response(['status' => 'error', 'message' => customLang('ebooks_uploads_disabled', 'Creator eBook uploads are disabled.')], 403);
            exit;
        }
        $uid = isset($userID) ? (int)$userID : 0;
        if ($loggedIn !== '1' || $uid <= 0) {
            dz_json_response(['status' => 'error', 'message' => customLang('login_required')], 401);
            exit;
        }
        $csrfToken = (string)($_POST['csrf_token'] ?? '');
        if (!function_exists('checkCsrfToken') || !checkCsrfToken($csrfToken)) {
            dz_json_response(['status' => 'error', 'message' => customLang('invalid_csrf_token')], 403);
            exit;
        }
        $creatorStatusLocal = strtolower((string)($creatorStatus ?? 'none'));
        if ($creatorStatusLocal !== 'approved') {
            dz_json_response(['status' => 'error', 'message' => customLang('ebook_creator_required', 'Only approved creators can publish eBooks.')], 403);
            exit;
        }
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '') {
            dz_json_response(['status' => 'error', 'message' => customLang('ebook_title_required', 'Please add an eBook title.')], 422);
            exit;
        }
        $ebookLanguageCode = method_exists($RL, 'RL_ValidateEbookLanguageTag')
            ? $RL->RL_ValidateEbookLanguageTag((string)($_POST['language_code'] ?? ''), true)
            : trim((string)($_POST['language_code'] ?? 'en'));
        if ($ebookLanguageCode === '') {
            dz_json_response(['status' => 'error', 'message' => customLang('ebook_language_invalid', 'Choose a valid eBook language.')], 422);
            exit;
        }
        $ebookMaxFileMb = max(1, min(2048, (int)($siteData['ebook_max_file_mb'] ?? 120)));
        $bookUpload = dz_ebook_upload_file('ebook_file', ['pdf', 'epub', 'mobi'], 'uploads/ebooks/files', 'ebook_' . $uid, $ebookMaxFileMb);
        if (empty($bookUpload['ok'])) {
            dz_json_response(['status' => 'error', 'message' => customLang($bookUpload['error'] ?? 'upload_failed', 'Upload failed.')], 422);
            exit;
        }
        $coverPath = '';
        if (isset($_FILES['cover_file']) && (int)($_FILES['cover_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $coverUpload = dz_ebook_upload_file('cover_file', ['jpg', 'jpeg', 'png', 'webp'], 'uploads/ebooks/covers', 'cover_' . $uid, 12);
            if (empty($coverUpload['ok'])) {
                @unlink(realpath(__DIR__ . '/../' . ltrim((string)$bookUpload['path'], '/')) ?: '');
                dz_json_response(['status' => 'error', 'message' => customLang($coverUpload['error'] ?? 'upload_failed', 'Upload failed.')], 422);
                exit;
            }
            $coverPath = (string)$coverUpload['path'];
        }
        $samplePath = '';
        if (isset($_FILES['sample_file']) && (int)($_FILES['sample_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $sampleUpload = dz_ebook_upload_file('sample_file', ['pdf'], 'uploads/ebooks/samples', 'sample_' . $uid, min(30, $ebookMaxFileMb));
            if (empty($sampleUpload['ok'])) { dz_json_response(['status'=>'error','message'=>customLang($sampleUpload['error']??'upload_failed','Upload failed.')],422); exit; }
            $samplePath=(string)$sampleUpload['path'];
        }
        $ebookMinPrice = max(0.0, (float)($siteData['ebook_min_price'] ?? 0));
        $ebookMaxPrice = max($ebookMinPrice, (float)($siteData['ebook_max_price'] ?? 500));
        $priceRaw = trim((string)($_POST['price'] ?? '0'));
        if ($priceRaw === '') {
            $priceRaw = '0';
        }
        $priceNormalized = str_replace(',', '.', $priceRaw);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $priceNormalized)) {
            dz_json_response(['status' => 'error', 'message' => customLang('ebook_price_invalid', 'Enter a valid price. Use 0 for a free eBook.')], 422);
            exit;
        }
        $price = round((float)$priceNormalized, 2);
        if ($price > 0 && ($price < $ebookMinPrice || $price > $ebookMaxPrice)) {
            dz_json_response(['status' => 'error', 'message' => customLang('ebook_price_out_of_range', 'The eBook price is outside the allowed range.')], 422);
            exit;
        }
        $ebookCurrencyCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($currency ?? 'USD')));
        if ($ebookCurrencyCode === '') {
            $ebookCurrencyCode = 'USD';
        }
        $publishAction = strtolower(trim((string)($_POST['publish_action'] ?? 'submit')));
        $targetStatus = $publishAction === 'draft' ? 'draft' : 'pending';
        $bookAbsolute = realpath(__DIR__ . '/../' . ltrim((string)$bookUpload['path'], '/'));
        $ebookId = $RL->RL_CreateEbook([
            'owner_id' => $uid,
            'title' => $title,
            'description' => trim((string)($_POST['description'] ?? '')),
            'short_description' => trim((string)($_POST['short_description'] ?? '')),
            'isbn' => trim((string)($_POST['isbn'] ?? '')),
            'language_code' => $ebookLanguageCode,
            'page_count' => max(0, (int)($_POST['page_count'] ?? 0)),
            'publication_date' => trim((string)($_POST['publication_date'] ?? '')),
            'version_label' => trim((string)($_POST['version_label'] ?? '1.0')),
            'price' => $price,
            'currency' => $ebookCurrencyCode,
            'cover_path' => $coverPath,
            'file_path' => (string)$bookUpload['path'],
            'file_name' => (string)$bookUpload['name'],
            'file_size' => (int)$bookUpload['size'],
            'sample_path' => $samplePath,
            'mime_type' => function_exists('mime_content_type') && is_string($bookAbsolute) ? (string)mime_content_type($bookAbsolute) : '',
            'checksum_sha256' => is_string($bookAbsolute) && is_file($bookAbsolute) ? (string)hash_file('sha256', $bookAbsolute) : '',
            'status' => $targetStatus,
            'download_limit' => max(0, (int)($siteData['ebook_download_limit'] ?? 5)),
            'access_days' => max(0, (int)($siteData['ebook_access_days'] ?? 0)),
        ]);
        if ($ebookId <= 0) {
            dz_json_response(['status' => 'error', 'message' => customLang('ebook_publish_failed', 'eBook could not be published.')], 500);
            exit;
        }
        dz_json_response([
            'status' => 'success',
            'message' => $targetStatus === 'draft'
                ? customLang('ebook_draft_saved', 'eBook draft saved.')
                : customLang('ebook_submitted_for_review', 'eBook submitted for review.'),
            'ebook_id' => $ebookId,
        ]);
    } catch (Throwable $e) {
        dz_json_response(['status' => 'error', 'message' => customLang('server_error', 'Server error.')], 500);
    }
    exit;
}

if ($pIncoming === 'ebook_purchase') {
    try {
        if (!$ebooksEnabledRequest) {
            dz_json_response(['status' => 'error', 'message' => customLang('ebooks_disabled', 'eBooks are disabled.')], 403);
            exit;
        }
        $uid = isset($userID) ? (int)$userID : 0;
        if ($loggedIn !== '1' || $uid <= 0) {
            dz_json_response(['status' => 'error', 'message' => customLang('login_required')], 401);
            exit;
        }
        $csrfToken = (string)($_POST['csrf_token'] ?? '');
        if (!function_exists('checkCsrfToken') || !checkCsrfToken($csrfToken)) {
            dz_json_response(['status' => 'error', 'message' => customLang('invalid_csrf_token')], 403);
            exit;
        }
        $ebookId = (int)($_POST['ebook_id'] ?? $_POST['id'] ?? 0);
        if ($ebookId <= 0 || !isset($RL) || !method_exists($RL, 'RL_PurchaseEbookWithWallet')) {
            dz_json_response(['status' => 'error', 'message' => customLang('ebook_not_found', 'eBook not found.')], 404);
            exit;
        }
        $result = $RL->RL_PurchaseEbookWithWallet($uid, $ebookId);
        if (!empty($result['ok'])) {
            dz_json_response([
                'status' => 'success',
                'message' => customLang('ebook_purchase_completed', 'eBook purchase completed.'),
                'new_balance' => $result['new_balance'] ?? null,
            ]);
            exit;
        }
        $error = (string)($result['error'] ?? 'ebook_purchase_failed');
        $messageKey = $error === 'insufficient_wallet' ? 'wallet_balance_not_enough_add_funds' : $error;
        dz_json_response([
            'status' => 'error',
            'code' => $error,
            'message' => customLang($messageKey, 'Your wallet balance is not enough. Add funds to continue.'),
            'balance' => $result['balance'] ?? null,
            'required' => $result['required'] ?? null,
        ], $error === 'insufficient_wallet' ? 402 : 422);
    } catch (Throwable $e) {
        dz_json_response(['status' => 'error', 'message' => customLang('server_error', 'Server error.')], 500);
    }
    exit;
}

if ($pIncoming === 'ebook_create_payment') {
    try {
        if (!$ebooksEnabledRequest) {
            dz_json_response(['status' => 'error', 'message' => customLang('ebooks_disabled', 'eBooks are disabled.')], 403);
            exit;
        }
        $uid = isset($userID) ? (int)$userID : 0;
        if ($loggedIn !== '1' || $uid <= 0) {
            dz_json_response(['status' => 'error', 'message' => customLang('login_required')], 401);
            exit;
        }
        $csrfToken = (string)($_POST['csrf_token'] ?? '');
        if (!function_exists('checkCsrfToken') || !checkCsrfToken($csrfToken)) {
            dz_json_response(['status' => 'error', 'message' => customLang('invalid_csrf_token')], 403);
            exit;
        }
        $provider = strtolower(trim((string)($_POST['provider'] ?? 'wallet')));
        $allowedProviders = ['stripe', 'paypal', 'nowpayments', 'coinbase', 'flutterwave', 'paystack', 'iyzico', 'payu', 'wallet'];
        $ebookId = (int)($_POST['ebook_id'] ?? $_POST['id'] ?? 0);
        if ($ebookId <= 0 || $provider === '' || !in_array($provider, $allowedProviders, true)) {
            dz_json_response(['status' => 'error', 'message' => customLang('error_invalid_parameters', 'Invalid parameters.')], 422);
            exit;
        }
        if (!isset($RL) || !method_exists($RL, 'RL_GetEbookById') || !method_exists($RL, 'RL_RecordEbookPurchase')) {
            dz_json_response(['status' => 'error', 'message' => customLang('server_error', 'Server error.')], 500);
            exit;
        }
        $ebook = $RL->RL_GetEbookById($ebookId, $uid);
        if (!$ebook || (string)($ebook['status'] ?? '') !== 'active') {
            dz_json_response(['status' => 'error', 'message' => customLang('ebook_not_found', 'eBook not found.')], 404);
            exit;
        }
        $sellerId = (int)($ebook['owner_id'] ?? 0);
        $baseAmount = round(max(0.0, (float)($ebook['price'] ?? 0)), 2);
        $couponCode = strtoupper(trim((string)($_POST['coupon_code'] ?? '')));
        $coupon = $couponCode !== '' && method_exists($RL, 'RL_ValidateEbookCoupon') ? $RL->RL_ValidateEbookCoupon($couponCode, $ebookId, $baseAmount) : null;
        if ($couponCode !== '' && !$coupon) { dz_json_response(['status'=>'error','message'=>customLang('ebook_coupon_invalid','This coupon is invalid or expired.')],422); exit; }
        if ($coupon) { $baseAmount = (float)$coupon['final_amount']; }
        $ebookCurrency = strtoupper((string)($ebook['currency'] ?? ($currency ?? 'USD')));
        if ($ebookCurrency === '') {
            $ebookCurrency = 'USD';
        }
        if ($sellerId <= 0) {
            dz_json_response(['status' => 'error', 'message' => customLang('ebook_not_found', 'eBook not found.')], 404);
            exit;
        }
        if ($sellerId === $uid || (int)($ebook['viewer_purchased'] ?? 0) === 1 || $baseAmount <= 0.0) {
            dz_json_response([
                'status' => 'success',
                'provider' => $provider,
                'already' => true,
                'message' => customLang('ebook_already_available', 'This eBook is already available.'),
                'redirect_url' => rtrim((string)($base_url ?? ''), '/') . '/ebooks',
            ]);
            exit;
        }

        $feePercent = isset($paymentFeePercent) ? max(0.0, (float)$paymentFeePercent) : 0.0;
        $feeFixed = isset($paymentFeeFixed) ? max(0.0, (float)$paymentFeeFixed) : 0.0;
        $taxPercent = isset($paymentTaxPercent) ? max(0.0, (float)$paymentTaxPercent) : 0.0;
        $feeAmount = round(($baseAmount * ($feePercent / 100.0)) + $feeFixed, 2);
        $taxAmount = round($baseAmount * ($taxPercent / 100.0), 2);
        $grossAmount = round($baseAmount + $feeAmount + $taxAmount, 2);
        if ($grossAmount <= 0.0) {
            $grossAmount = $baseAmount;
        }

        if ($provider === 'wallet') {
            if (!method_exists($RL, 'RL_PurchaseEbookWithWallet')) {
                dz_json_response(['status' => 'error', 'message' => customLang('wallet_purchase_not_supported', 'Wallet purchase is not supported.')], 422);
                exit;
            }
            $result = $RL->RL_PurchaseEbookWithWallet($uid, $ebookId, $baseAmount, $couponCode);
            if (!empty($result['ok'])) {
                dz_json_response([
                    'status' => 'success',
                    'provider' => 'wallet',
                    'reference' => (string)($result['reference'] ?? ''),
                    'message' => customLang('ebook_purchase_completed', 'eBook purchase completed.'),
                    'new_balance' => $result['new_balance'] ?? null,
                    'redirect_url' => rtrim((string)($base_url ?? ''), '/') . '/ebooks',
                ]);
                exit;
            }
            $error = (string)($result['error'] ?? 'ebook_purchase_failed');
            $messageKey = $error === 'insufficient_wallet' ? 'wallet_balance_not_enough_add_funds' : $error;
            dz_json_response([
                'status' => 'error',
                'code' => $error,
                'message' => customLang($messageKey, 'Your wallet balance is not enough. Add funds to continue.'),
                'balance' => $result['balance'] ?? null,
                'required' => $result['required'] ?? null,
            ], $error === 'insufficient_wallet' ? 402 : 422);
            exit;
        }

        $cfg = PaymentFactory::config();
        $providerKey = $provider === 'coinbase_commerce' ? 'coinbase' : $provider;
        $providerCfg = $cfg[$providerKey] ?? [];
        if (empty($providerCfg['enabled'])) {
            dz_json_response(['status' => 'error', 'message' => customLang('payment_provider_unavailable', 'Selected payment provider is not available.')], 422);
            exit;
        }
        $currencyCode = strtoupper((string)($providerCfg['currency'] ?? ($cfg['currency'] ?? $ebookCurrency)));
        if ($currencyCode === '') {
            $currencyCode = $ebookCurrency;
        }
        $GLOBALS['paymentSuccessUrl'] = rtrim((string)($base_url ?? ''), '/') . '/ebooks?payment=success';
        $GLOBALS['paymentCancelUrl'] = rtrim((string)($base_url ?? ''), '/') . '/ebooks?payment=cancel';

        $buyerEmail = '';
        $buyerName = '';
        $buyerUsername = '';
        if (isset($userData) && is_array($userData)) {
            $buyerEmail = trim((string)($userData['contact_email'] ?? $userData['user_email'] ?? ''));
            $buyerName = trim((string)($userData['user_fullname'] ?? $userData['username'] ?? ''));
            $buyerUsername = trim((string)($userData['username'] ?? ''));
        }
        if ($buyerEmail === '' && method_exists($RL, 'RL_GetUserDetails')) {
            $viewerData = $RL->RL_GetUserDetails($uid) ?: [];
            if (is_array($viewerData)) {
                $buyerEmail = trim((string)($viewerData['contact_email'] ?? $viewerData['user_email'] ?? ''));
                $buyerName = $buyerName !== '' ? $buyerName : trim((string)($viewerData['user_fullname'] ?? $viewerData['username'] ?? ''));
                $buyerUsername = $buyerUsername !== '' ? $buyerUsername : trim((string)($viewerData['username'] ?? ''));
            }
        }

        $ebookTitle = trim((string)($ebook['title'] ?? 'eBook'));
        $meta = [
            'type' => 'ebook',
            'ebook_id' => $ebookId,
            'seller_id' => $sellerId,
            'buyer_id' => $uid,
            'title' => customLang('ebook_payment_title', 'eBook purchase'),
            'description' => $ebookTitle,
            'gross_amount' => number_format($grossAmount, 2, '.', ''),
            'net_amount' => number_format($baseAmount, 2, '.', ''),
            'fee_amount' => number_format($feeAmount, 2, '.', ''),
            'tax_amount' => number_format($taxAmount, 2, '.', ''),
            'ebook_title' => $ebookTitle,
            'coupon_code' => $couponCode,
        ];
        if ($buyerEmail !== '') { $meta['buyer_email'] = $buyerEmail; }
        if ($buyerName !== '') { $meta['buyer_name'] = $buyerName; }
        if ($buyerUsername !== '') { $meta['buyer_username'] = $buyerUsername; }

        $gateway = PaymentFactory::make($provider);
        $response = $gateway->createOneTimePayment($grossAmount, $currencyCode, $meta);
        $checkoutUrl = (string)($response['checkout_url'] ?? '');
        if ($checkoutUrl === '') {
            $msg = customLang('provider_no_checkout_url', 'Payment provider did not return a checkout URL.');
            if (!empty($response['message']) && is_string($response['message'])) {
                $msg = $response['message'];
            } elseif (!empty($response['error']) && is_string($response['error'])) {
                $msg = customLang('payment_error_prefix', 'Payment error:') . ' ' . $response['error'];
            }
            dz_json_response(['status' => 'error', 'message' => $msg], 422);
            exit;
        }
        $reference = (string)($response['reference'] ?? '');
        if ($reference === '') {
            $reference = 'EBOOK-' . $uid . '-' . $ebookId . '-' . time() . '-' . bin2hex(random_bytes(3));
            $response['reference'] = $reference;
        }
        $RL->RL_RecordEbookPurchase($ebookId, $uid, $sellerId, $provider, $reference, $grossAmount, $currencyCode, 'pending', 'checkout_created', (array)$response);
        if (method_exists($RL, 'RL_UpdateEbookPurchaseAmountsByReference')) {
            $RL->RL_UpdateEbookPurchaseAmountsByReference($provider, $reference, $grossAmount, $currencyCode, $feeAmount, $currencyCode, $taxAmount, $baseAmount);
        }
        dz_json_response([
            'status' => 'success',
            'checkout_url' => $checkoutUrl,
            'reference' => $reference,
            'provider' => $provider,
            'currency' => $currencyCode,
            'subtotal' => $baseAmount,
            'total_amount' => $grossAmount,
            'fee_amount' => $feeAmount,
            'tax_amount' => $taxAmount,
        ]);
    } catch (Throwable $e) {
        dz_json_response(['status' => 'error', 'message' => customLang('payment_init_failed', 'Payment could not be started.') . ' ' . $e->getMessage()], 500);
    }
    exit;
}

if ($pIncoming === 'ebook_payment_status') {
    try {
        $uid = isset($userID) ? (int)$userID : 0;
        if ($loggedIn !== '1' || $uid <= 0) {
            dz_json_response(['status' => 'error', 'message' => customLang('login_required')], 401);
            exit;
        }
        $provider = strtolower(trim((string)($_POST['provider'] ?? '')));
        $reference = trim((string)($_POST['reference'] ?? ''));
        if ($provider === '' || $reference === '' || !isset($RL) || !method_exists($RL, 'RL_GetEbookPurchaseByReference')) {
            dz_json_response(['status' => 'success', 'payment_status' => 'unknown']);
            exit;
        }
        $row = $RL->RL_GetEbookPurchaseByReference($provider, $reference);
        if (!$row || (int)($row['buyer_id'] ?? 0) !== $uid) {
            dz_json_response(['status' => 'success', 'payment_status' => 'unknown']);
            exit;
        }
        dz_json_response([
            'status' => 'success',
            'payment_status' => (string)($row['status'] ?? 'unknown'),
            'ebook_id' => (int)($row['ebook_id'] ?? 0),
        ]);
    } catch (Throwable $e) {
        dz_json_response(['status' => 'error', 'message' => customLang('server_error', 'Server error.')], 500);
    }
    exit;
}

if ($pIncoming === 'twofactor_qr') {
    $userHandler->handleTwoFactorQr();
    return;
}

if ($pIncoming === 'register_account') {
    $userHandler->handleRegisterAccount();
    return;
}

if ($pIncoming === 'forgot_password_request') {
    $userHandler->handleForgotPasswordRequest();
    return;
}

if ($pIncoming === 'reset_password_submit') {
    $userHandler->handleResetPasswordSubmit();
    return;
}

// Cron-like endpoint to process due subscriptions (no CSRF, no login)
// Optional protection via env secret: SUBSCRIPTIONS_CRON_SECRET or CRON_SUBSCRIPTIONS_TOKEN
if ($pIncoming === 'subscriptions_renew') {
    $paymentHandler->handleSubscriptionsRenewCron();
    return;
}
// Lightweight public stats endpoint (no CSRF, no login): returns current like count for a live
if ($pIncoming === 'live_stats') {
    try {
        $liveID = (int)($_POST['live_id'] ?? $_GET['live_id'] ?? 0);
        if ($liveID <= 0) { echo json_encode(['status' => 'error', 'message' => customLang('invalid_live_id')]); exit; }
        // Resolve owner id (to exclude from viewer counts)
        $ownerId = method_exists($RL,'RL_GetLiveOwnerId') ? $RL->RL_GetLiveOwnerId($liveID) : 0;

        // Likes
        $likes = 0;
        if (isset($RL) && method_exists($RL, 'RL_CountLiveLikes')) {
            $likes = (int)$RL->RL_CountLiveLikes($liveID);
        }
        // Viewers (heartbeat-based)
        $cut = time() - 15; // online window (faster drop)
        $viewers = method_exists($RL,'RL_CountLiveViewers') ? $RL->RL_CountLiveViewers($liveID, $cut, $ownerId) : 0;
        // Occasionally prune (1% chance)
        if (mt_rand(1,100) === 1 && method_exists($RL,'RL_PruneLiveViewers')) { $RL->RL_PruneLiveViewers($cut - 120); }
        echo json_encode(['status' => 'success', 'likes' => $likes, 'viewers' => $viewers]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
    }
    exit;
}

// Heartbeat: mark viewer as present (no CSRF, supports guests)
if ($pIncoming === 'live_ping') {
    try {
        if (!isset($RL)) { echo json_encode(['status'=>'error']); exit; }
        $liveID = (int)($_POST['live_id'] ?? $_GET['live_id'] ?? 0);
        if ($liveID <= 0) { echo json_encode(['status'=>'error','message'=>customLang('invalid_live_id')]); exit; }
        if (!$liveStreamingEnabledGlobal) { echo json_encode(['status'=>'error','message'=>'live_stream_disabled']); exit; }
        $sess = session_id();
        if (!$sess) { $sess = substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? 'na')), 0, 48); }
        $uid  = isset($userID) ? (int)$userID : 0;
        $now  = time();
        // Exclude owner from heartbeat (owner is not a viewer)
        $ownerId = method_exists($RL,'RL_GetLiveOwnerId') ? $RL->RL_GetLiveOwnerId($liveID) : 0;
        if ($ownerId > 0 && $uid === $ownerId) { echo json_encode(['status'=>'success']); exit; }
        if (method_exists($RL,'RL_UpsertLiveViewer')) { $RL->RL_UpsertLiveViewer($liveID, $sess, $uid, $now); }
        echo json_encode(['status'=>'success']);
    } catch (Throwable $e) {
        echo json_encode(['status'=>'error']);
    }
    exit;
}
if ($pIncoming === 'ads_payment_webhook' || $pIncoming === 'tips_payment_webhook') {
    // Backwards compatibility: forward to unified handler
    include __DIR__ . '/webhooks.php';
    exit;
}
// Public endpoint: fetch live tips since a timestamp (no CSRF, no login)
if ($pIncoming === 'live_tips') {
    try {
        $liveID = (int)($_GET['live_id'] ?? $_POST['live_id'] ?? 0);
        $since  = (int)($_GET['since']   ?? $_POST['since']   ?? 0);
        if ($liveID <= 0) { echo json_encode(['status'=>'error','message'=>'INVALID']); exit; }
        if (!isset($RL) || !method_exists($RL, 'RL_GetLiveTipsSince')) { echo json_encode(['status'=>'error','message'=>'NOFN']); exit; }
        $tips = $RL->RL_GetLiveTipsSince($liveID, $since);
        $maxSince = $since;
        foreach ($tips as $t) { if (isset($t['ts'])) { $maxSince = max($maxSince, (int)$t['ts']); } }
        echo json_encode(['status'=>'success','tips'=>$tips,'since'=>$maxSince]);
    } catch (Throwable $e) {
        echo json_encode(['status'=>'error','message'=>customLang('server_error')]);
    }
    exit;
}

// Public endpoint: fetch live chat messages since a given id (no CSRF, no login)
if ($pIncoming === 'live_chat_fetch') {
    try {
        if (!$liveChatEnabledGlobal) {
            echo json_encode(['status'=>'error','message'=>'live_chat_disabled']);
            exit;
        }
        if (!isset($RL) || !method_exists($RL, 'getDb')) { echo json_encode(['status'=>'error','message'=>'DB']); exit; }
        $db = $RL->getDb();
        $liveID = (int)($_GET['live_id'] ?? $_POST['live_id'] ?? 0);
        $sinceId = (int)($_GET['since_id'] ?? $_POST['since_id'] ?? 0);
        $limit   = (int)($_GET['limit'] ?? $_POST['limit'] ?? 50);
        if ($liveID <= 0) { echo json_encode(['status'=>'error','message'=>'INVALID']); exit; }
        if ($limit <= 0) $limit = 50; if ($limit > 100) $limit = 100;
        if (!method_exists($RL, 'RL_GetLiveChatMessagesSince')) { echo json_encode(['status'=>'error','message'=>'NOFN']); exit; }
        $res = $RL->RL_GetLiveChatMessagesSince($liveID, $sinceId, $limit);
        echo json_encode(['status' => 'success', 'messages' => ($res['messages'] ?? []), 'last_id' => ($res['last_id'] ?? $sinceId)]);
    } catch (Throwable $e) {
        echo json_encode(['status'=>'error','message'=>'SERVER']);
    }
    exit;
}

// Audio Room: unified room state for live UI updates.
if ($pIncoming === 'audio_room_state') {
    try {
        $roomId = (int)($_GET['room_id'] ?? $_POST['room_id'] ?? 0);
        $viewerId = isset($userID) ? (int)$userID : 0;
        if (!$audioRoomsEnabledGlobal) {
            echo json_encode(['status' => 'error', 'message' => 'audio_rooms_disabled']);
            exit;
        }
        if ($roomId <= 0 || !isset($RL) || !method_exists($RL, 'RL_GetAudioRoomById')) {
            echo json_encode(['status' => 'error', 'message' => 'invalid_room_id']);
            exit;
        }
        $room = $RL->RL_GetAudioRoomById($roomId);
        if (!$room || !method_exists($RL, 'RL_UserCanViewAudioRoom') || !$RL->RL_UserCanViewAudioRoom($viewerId, $room)) {
            echo json_encode(['status' => 'error', 'message' => 'room_not_found']);
            exit;
        }
        echo json_encode(dz_audio_room_state_payload($RL, $roomId, $viewerId, isset($base_url) ? (string)$base_url : ''));
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
    }
    exit;
}

// Audio Room: lightweight public stats.
if ($pIncoming === 'audio_room_stats') {
    try {
        $roomId = (int)($_GET['room_id'] ?? $_POST['room_id'] ?? 0);
        $viewerId = isset($userID) ? (int)$userID : 0;
        if ($roomId <= 0 || !isset($RL) || !method_exists($RL, 'RL_GetAudioRoomById')) {
            echo json_encode(['status' => 'error', 'message' => 'invalid_room_id']);
            exit;
        }
        $room = $RL->RL_GetAudioRoomById($roomId);
        if ($room && method_exists($RL, 'RL_MaybeAutoEndAudioRoom') && $RL->RL_MaybeAutoEndAudioRoom($roomId)) {
            $room = $RL->RL_GetAudioRoomById($roomId);
        }
        if (!$room || !method_exists($RL, 'RL_UserCanViewAudioRoom') || !$RL->RL_UserCanViewAudioRoom($viewerId, $room)) {
            echo json_encode(['status' => 'error', 'message' => 'room_not_found']);
            exit;
        }
        $cut = time() - 30;
        $listeners = method_exists($RL, 'RL_CountAudioRoomParticipants') ? $RL->RL_CountAudioRoomParticipants($roomId, $cut, ['listener']) : 0;
        $speakers = method_exists($RL, 'RL_CountAudioRoomParticipants') ? $RL->RL_CountAudioRoomParticipants($roomId, $cut, ['host','moderator','speaker']) : 0;
        echo json_encode([
            'status' => 'success',
            'room_status' => (string)($room['status'] ?? 'created'),
            'listeners' => $listeners,
            'speakers' => $speakers,
            'is_paid' => (int)($room['is_paid'] ?? 0) === 1,
            'server_time' => time(),
            'auto_end_at' => isset($room['auto_end_at']) ? (int)$room['auto_end_at'] : 0,
            'ended_at' => isset($room['ended_at']) ? (int)$room['ended_at'] : 0,
            'end_reason' => (string)($room['end_reason'] ?? ''),
            'participant' => $viewerId > 0 && method_exists($RL, 'RL_GetAudioRoomParticipantState') ? $RL->RL_GetAudioRoomParticipantState($roomId, $viewerId) : [],
            'chat_mute_remaining' => $viewerId > 0 && method_exists($RL, 'RL_GetAudioRoomChatMuteRemaining') ? $RL->RL_GetAudioRoomChatMuteRemaining($roomId, $viewerId) : 0,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
    }
    exit;
}

// Audio Room: heartbeat for participants.
if ($pIncoming === 'audio_room_ping') {
    try {
        if (!$audioRoomsEnabledGlobal) { echo json_encode(['status'=>'error','message'=>'audio_rooms_disabled']); exit; }
        $roomId = (int)($_POST['room_id'] ?? $_GET['room_id'] ?? 0);
        $uid = isset($userID) ? (int)$userID : 0;
        if ($roomId <= 0 || !isset($RL) || !method_exists($RL, 'RL_UserCanJoinAudioRoom')) {
            echo json_encode(['status'=>'error','message'=>'invalid_room_id']);
            exit;
        }
        $access = $RL->RL_UserCanJoinAudioRoom($uid, $roomId);
        if (empty($access['ok'])) {
            echo json_encode(['status'=>'error','message'=>(string)($access['reason'] ?? 'not_allowed')]);
            exit;
        }
        $room = (array)($access['room'] ?? []);
        $ownerId = (int)($room['owner_id'] ?? 0);
        $role = 'listener';
        if ($uid > 0 && $uid === $ownerId) {
            $role = 'host';
        } elseif ($uid > 0 && method_exists($RL, 'RL_IsAudioRoomModerator') && $RL->RL_IsAudioRoomModerator($roomId, $uid)) {
            $role = 'moderator';
        } elseif ($uid > 0 && method_exists($RL, 'RL_IsAudioRoomSpeaker') && $RL->RL_IsAudioRoomSpeaker($roomId, $uid)) {
            $role = 'speaker';
        }
        $speakerStatus = '';
        if ($uid > 0 && method_exists($RL, 'RL_GetAudioRoomSpeakerStatus')) {
            $speakerStatus = $RL->RL_GetAudioRoomSpeakerStatus($roomId, $uid);
        }
        $participantBefore = method_exists($RL, 'RL_GetAudioRoomParticipantState') ? $RL->RL_GetAudioRoomParticipantState($roomId, $uid) : [];
        $participantStatusBefore = (string)($participantBefore['status'] ?? '');
        if (in_array($participantStatusBefore, ['removed','banned'], true)) {
            echo json_encode(['status'=>'error','message'=>$participantStatusBefore === 'banned' ? 'banned' : 'removed','participant'=>$participantBefore]);
            exit;
        }
        if (method_exists($RL, 'RL_TouchAudioRoomParticipant')) {
            $RL->RL_TouchAudioRoomParticipant($roomId, dz_audio_room_session_key(), $uid, $role, time());
        } elseif (method_exists($RL, 'RL_UpsertAudioRoomParticipant')) {
            $RL->RL_UpsertAudioRoomParticipant($roomId, dz_audio_room_session_key(), $uid, $role, true, false, time());
        }
        $participantState = method_exists($RL, 'RL_GetAudioRoomParticipantState') ? $RL->RL_GetAudioRoomParticipantState($roomId, $uid) : [];
        echo json_encode([
            'status'=>'success',
            'role'=>$role,
            'speaker_status'=>$speakerStatus,
            'participant'=>$participantState,
            'chat_mute_remaining'=>method_exists($RL, 'RL_GetAudioRoomChatMuteRemaining') ? $RL->RL_GetAudioRoomChatMuteRemaining($roomId, $uid) : 0,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['status'=>'error']);
    }
    exit;
}

// Audio Room: fetch chat/activity messages.
if ($pIncoming === 'audio_room_chat_fetch') {
    try {
        if (!$audioRoomChatEnabledGlobal) {
            echo json_encode(['status'=>'error','message'=>'audio_room_chat_disabled']);
            exit;
        }
        $roomId = (int)($_GET['room_id'] ?? $_POST['room_id'] ?? 0);
        $sinceId = (int)($_GET['since_id'] ?? $_POST['since_id'] ?? 0);
        $limit = (int)($_GET['limit'] ?? $_POST['limit'] ?? 50);
        $uid = isset($userID) ? (int)$userID : 0;
        if ($roomId <= 0 || !isset($RL) || !method_exists($RL, 'RL_GetAudioRoomById')) {
            echo json_encode(['status'=>'error','message'=>'invalid_room_id']);
            exit;
        }
        $room = $RL->RL_GetAudioRoomById($roomId);
        if (!$room || !method_exists($RL, 'RL_UserCanViewAudioRoom') || !$RL->RL_UserCanViewAudioRoom($uid, $room)) {
            echo json_encode(['status'=>'error','message'=>'room_not_found']);
            exit;
        }
        if (!method_exists($RL, 'RL_GetAudioRoomMessagesSince')) {
            echo json_encode(['status'=>'error','message'=>'not_supported']);
            exit;
        }
        $messages = $RL->RL_GetAudioRoomMessagesSince($roomId, $sinceId, $limit);
        $lastId = $sinceId;
        foreach ($messages as $message) {
            $lastId = max($lastId, (int)($message['id'] ?? 0));
        }
        echo json_encode(['status'=>'success','messages'=>$messages,'last_id'=>$lastId]);
    } catch (Throwable $e) {
        echo json_encode(['status'=>'error','message'=>'SERVER']);
    }
    exit;
}

// Feed advertisements: sidebar placement (supports guests; CSRF required)
if ($pIncoming === 'ads_feed_sidebar') {
    try {
        $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 3;
        if ($limit <= 0) { $limit = 3; }
        if ($limit > 5) { $limit = 5; }

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }
        if (!$adsEnabledGlobal) {
            http_response_code(403);
            echo json_encode($adsDisabledResponse);
            exit;
        }
        if (!$adsEnabledGlobal) {
            http_response_code(403);
            echo json_encode($adsDisabledResponse);
            exit;
        }

        if (!isset($RL) || !method_exists($RL, 'RL_GetActiveAdvertisementsForFeed')) {
            echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
            exit;
        }

        $excludeRaw = $_POST['exclude'] ?? ($_POST['shown'] ?? []);
        $exclude = [];
        if (is_array($excludeRaw)) {
            foreach ($excludeRaw as $id) {
                $id = (int) $id;
                if ($id > 0) { $exclude[$id] = $id; }
            }
        } elseif (is_string($excludeRaw) && $excludeRaw !== '') {
            foreach (explode(',', $excludeRaw) as $part) {
                $id = (int) trim($part);
                if ($id > 0) { $exclude[$id] = $id; }
            }
        }
        $exclude = array_values($exclude);

        $viewerId = isset($userID) ? (int) $userID : 0;

        $ads = $RL->RL_GetActiveAdvertisementsForFeed($limit, $exclude, $viewerId > 0 ? $viewerId : null);
        $ads = array_values(array_filter($ads, static function ($ad) use ($videosEnabledGlobal, $imagesEnabledGlobal): bool {
            $type = strtolower((string) ($ad['media_type'] ?? ''));
            if ($type === 'video' && !$videosEnabledGlobal) { return false; }
            if ($type === 'image' && !$imagesEnabledGlobal) { return false; }
            return true;
        }));

        $base = isset($base_url) ? rtrim((string) $base_url, '/') . '/' : '/';
        $resolveMedia = static function (string $path) use ($base): string {
            if ($path === '') { return ''; }
            if (function_exists('storage_resolve_media_url')) {
                return storage_resolve_media_url($path, $base);
            }
            return $base . ltrim($path, '/');
        };

        $cards = [];
        foreach ($ads as $ad) {
            $mediaItems = isset($ad['media_items']) && is_array($ad['media_items']) ? $ad['media_items'] : [];
            $mediaUrls = [];
            if ($mediaItems) {
                foreach ($mediaItems as $item) {
                    $resolved = $resolveMedia((string) $item);
                    if ($resolved !== '') {
                        $mediaUrls[] = $resolved;
                    }
                }
            }

            $username = (string) ($ad['username'] ?? '');
            $profileUrl = $base . 'profile/' . rawurlencode($username);
            $adLink = (string) ($ad['ad_link'] ?? '');
            $ctaUrl = $adLink !== '' ? $adLink : $profileUrl;

            $mediaType = (string) ($ad['media_type'] ?? 'image');
            $videoThumb = '';
            if ($mediaType === 'video' && $mediaUrls && method_exists($RL, 'videoThumbNail')) {
                try {
                    $thumbCandidate = $RL->videoThumbNail($mediaUrls[0]);
                    if ($thumbCandidate) {
                        $videoThumb = $thumbCandidate;
                    }
                } catch (Throwable $__) {
                    // ignore thumbnail errors
                }
            }

            $cards[] = [
                'ad_id'            => (int) ($ad['ad_id'] ?? 0),
                'title'            => (string) ($ad['title'] ?? ''),
                'description'      => (string) ($ad['description'] ?? ''),
                'media_type'       => $mediaType,
                'media_urls'       => $mediaUrls,
                'video_thumb_url'  => $videoThumb,
                'cta_url'          => $ctaUrl,
                'cta_label'        => customLang('ads_visit_advertiser', 'Visit advertiser'),
                'sponsored_label'  => customLang('ads_sponsored', 'Sponsored'),
                'owner_username'   => $username,
                'owner_fullname'   => (string) ($ad['user_fullname'] ?? ''),
                'ad_link'          => $adLink,
            ];
        }

        echo json_encode([
            'status' => 'success',
            'ads'    => $cards,
            'adIds'  => array_column($cards, 'ad_id'),
        ]);
    } catch (Throwable $e) {
        if (isset($RL) && method_exists($RL, 'logError')) {
            $RL->logError('ads_feed_sidebar failed: ' . $e->getMessage());
        }
        echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
    }
    exit;
}

// Feed advertisements: in-stream placement (returns HTML fragments; CSRF required)
if ($pIncoming === 'ads_feed_instream') {
    try {
        $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 1;
        if ($limit <= 0) { $limit = 1; }
        if ($limit > 3) { $limit = 3; }

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }

        if (!isset($RL) || !method_exists($RL, 'RL_GetActiveAdvertisementsForFeed')) {
            echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
            exit;
        }

        $excludeRaw = $_POST['exclude'] ?? ($_POST['shown'] ?? []);
        $exclude = [];
        if (is_array($excludeRaw)) {
            foreach ($excludeRaw as $id) {
                $id = (int) $id;
                if ($id > 0) { $exclude[$id] = $id; }
            }
        } elseif (is_string($excludeRaw) && $excludeRaw !== '') {
            foreach (explode(',', $excludeRaw) as $part) {
                $id = (int) trim($part);
                if ($id > 0) { $exclude[$id] = $id; }
            }
        }
        $exclude = array_values($exclude);

        $viewerId = isset($userID) ? (int) $userID : 0;
        $ads = $RL->RL_GetActiveAdvertisementsForFeed($limit, $exclude, $viewerId > 0 ? $viewerId : null, 'image');
        $ads = array_values(array_filter($ads, static function ($ad) use ($videosEnabledGlobal, $imagesEnabledGlobal): bool {
            $type = strtolower((string) ($ad['media_type'] ?? ''));
            if ($type === 'video' && !$videosEnabledGlobal) { return false; }
            if ($type === 'image' && !$imagesEnabledGlobal) { return false; }
            return true;
        }));

        if (!$ads) {
            echo json_encode(['status' => 'success', 'html' => '', 'adIds' => []]);
            exit;
        }

        $base = isset($base_url) ? rtrim((string) $base_url, '/') . '/' : '/';
        $resolveMedia = static function (string $path) use ($base): string {
            if ($path === '') { return ''; }
            if (function_exists('storage_resolve_media_url')) {
                return storage_resolve_media_url($path, $base);
            }
            return $base . ltrim($path, '/');
        };

        $adsHtml = '';
        $renderedIds = [];
        $theme = isset($currentTheme) ? (string) $currentTheme : 'default';
        $partialPath = __DIR__ . '/../themes/' . $theme . '/partials/feedAd.php';
        if (!is_file($partialPath)) {
            echo json_encode(['status' => 'error', 'message' => 'feed_ad_partial_missing']);
            exit;
        }

        foreach ($ads as $ad) {
            $mediaItems = isset($ad['media_items']) && is_array($ad['media_items']) ? $ad['media_items'] : [];
            $mediaUrls = [];
            foreach ($mediaItems as $path) {
                $resolved = $resolveMedia((string) $path);
                if ($resolved !== '') {
                    $mediaUrls[] = $resolved;
                }
            }
            if (empty($mediaUrls)) {
                continue;
            }

            $username = (string) ($ad['username'] ?? '');
            $profileUrl = $base . 'profile/' . rawurlencode($username);

            $adPayload = $ad;
            $adPayload['media_urls'] = $mediaUrls;
            $adPayload['primary_media_url'] = $mediaUrls[0];
            $adLink = (string) ($ad['ad_link'] ?? '');
            $adPayload['target_url'] = $adLink !== '' ? $adLink : $profileUrl;
            $adPayload['ad_link'] = $adLink;
            $adPayload['viewer_id'] = $viewerId;

            if (!empty($mediaUrls) && ($adPayload['media_type'] ?? '') === 'video' && method_exists($RL, 'videoThumbNail')) {
                try {
                    $thumb = $RL->videoThumbNail($mediaUrls[0]);
                    if ($thumb) {
                        $adPayload['video_thumb_url'] = $thumb;
                    }
                } catch (Throwable $__) {
                    // ignore thumbnail failures
                }
            }

            $ad = $adPayload; // expose $ad for the partial
            ob_start();
            include $partialPath;
            $adsHtml .= ob_get_clean();
            $renderedIds[] = (int) ($adPayload['ad_id'] ?? 0);
        }

        echo json_encode([
            'status' => 'success',
            'html'   => $adsHtml,
            'adIds'  => $renderedIds,
        ]);
    } catch (Throwable $e) {
        if (isset($RL) && method_exists($RL, 'logError')) {
            $RL->logError('ads_feed_instream failed: ' . $e->getMessage());
        }
        echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
    }
    exit;
}

// Feed video advertisements: pre-roll / mid-roll slot payload (CSRF required)
if ($pIncoming === 'ads_video_slot') {
    try {
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }
        if (!$adsEnabledGlobal || !$videosEnabledGlobal) {
            http_response_code(403);
            echo json_encode($adsDisabledResponse);
            exit;
        }
        if (!isset($RL) || !method_exists($RL, 'RL_GetActiveAdvertisementsForFeed')) {
            echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
            exit;
        }

        $excludeRaw = $_POST['exclude'] ?? ($_POST['shown'] ?? []);
        $exclude = [];
        if (is_array($excludeRaw)) {
            foreach ($excludeRaw as $id) {
                $id = (int) $id;
                if ($id > 0) { $exclude[$id] = $id; }
            }
        } elseif (is_string($excludeRaw) && $excludeRaw !== '') {
            foreach (explode(',', $excludeRaw) as $part) {
                $id = (int) trim($part);
                if ($id > 0) { $exclude[$id] = $id; }
            }
        }
        $exclude = array_values($exclude);

        $viewerId = isset($userID) ? (int) $userID : 0;
        $ads = $RL->RL_GetActiveAdvertisementsForFeed(1, $exclude, $viewerId > 0 ? $viewerId : null, 'video');
        if (!$ads) {
            echo json_encode(['status' => 'success', 'ad' => null]);
            exit;
        }

        $ad = $ads[0];
        $base = isset($base_url) ? rtrim((string) $base_url, '/') . '/' : '/';
        $resolveMedia = static function (string $path) use ($base): string {
            if ($path === '') { return ''; }
            if (function_exists('storage_resolve_media_url')) {
                return storage_resolve_media_url($path, $base);
            }
            return $base . ltrim($path, '/');
        };
        $mediaItems = isset($ad['media_items']) && is_array($ad['media_items']) ? $ad['media_items'] : [];
        $mediaUrl = '';
        foreach ($mediaItems as $path) {
            $resolved = $resolveMedia((string) $path);
            if ($resolved !== '') {
                $mediaUrl = $resolved;
                break;
            }
        }
        if ($mediaUrl === '') {
            echo json_encode(['status' => 'success', 'ad' => null]);
            exit;
        }

        $username = (string) ($ad['username'] ?? '');
        $profileUrl = $base . 'profile/' . rawurlencode($username);
        $adLink = (string) ($ad['ad_link'] ?? '');
        $targetUrl = $adLink !== '' ? $adLink : $profileUrl;
        $poster = '';
        if (method_exists($RL, 'videoThumbNail')) {
            try { $poster = (string) $RL->videoThumbNail($mediaUrl); } catch (Throwable $__) { $poster = ''; }
        }

        echo json_encode([
            'status' => 'success',
            'ad' => [
                'ad_id' => (int) ($ad['ad_id'] ?? 0),
                'title' => (string) ($ad['title'] ?? ''),
                'description' => (string) ($ad['description'] ?? ''),
                'media_url' => $mediaUrl,
                'poster_url' => $poster,
                'target_url' => $targetUrl,
                'sponsored_label' => customLang('ads_sponsored', 'Sponsored'),
                'cta_label' => customLang('ads_visit_advertiser', 'Visit advertiser'),
                'skip_label' => customLang('ads_video_skip', 'Skip ad'),
                'ad_label' => customLang('ads_video_label', 'Advertisement'),
            ],
        ]);
    } catch (Throwable $e) {
        if (isset($RL) && method_exists($RL, 'logError')) {
            $RL->logError('ads_video_slot failed: ' . $e->getMessage());
        }
        echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
    }
    exit;
}

// Feed advertisements: track impression/click (guest friendly; CSRF required)
if ($pIncoming === 'ads_track_impression' || $pIncoming === 'ads_track_click') {
    try {
        $adId = isset($_POST['ad_id']) ? (int) $_POST['ad_id'] : 0;
        if ($adId <= 0) {
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_request', 'Invalid request')]);
            exit;
        }

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }
        if (!$adsEnabledGlobal) {
            http_response_code(403);
            echo json_encode($adsDisabledResponse);
            exit;
        }

        if (!isset($RL)) {
            echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
            exit;
        }

        $viewerId = isset($userID) ? (int) $userID : 0;
        $viewerParam = $viewerId > 0 ? $viewerId : null;

        if ($pIncoming === 'ads_track_impression') {
            $ok = method_exists($RL, 'RL_RecordAdImpression') ? $RL->RL_RecordAdImpression($adId, $viewerParam) : false;
        } else {
            $ok = method_exists($RL, 'RL_RecordAdClick') ? $RL->RL_RecordAdClick($adId, $viewerParam) : false;
        }

        echo json_encode(['status' => $ok ? 'success' : 'error']);
    } catch (Throwable $e) {
        if (isset($RL) && method_exists($RL, 'logError')) {
            $RL->logError('ads_track_metric failed: ' . $e->getMessage());
        }
        echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
    }
    exit;
}
// Guest feed infinite scroll (available to guests; requires CSRF token)
if ($pIncoming === 'guest_feed_more') {
    try {
        $offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;
        $limit  = isset($_POST['limit'])  ? (int) $_POST['limit']  : 20;
        if ($limit < 5)  { $limit = 5; }
        if ($limit > 40) { $limit = 40; }

        $csrfToken = (string)($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('invalid_csrf_token')
            ]);
            exit;
        }

        if (!isset($RL) || !method_exists($RL, 'RL_GetGuestFeedPosts')) {
            echo json_encode(['status' => 'error', 'message' => 'NOFN']);
            exit;
        }

        $rows = $RL->RL_GetGuestFeedPosts($limit + 1, $offset);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        $layout = __DIR__ . '/../themes/' . $currentTheme . '/layouts/post_view.php';
        if (!is_file($layout)) {
            echo json_encode(['status' => 'error', 'message' => 'TPL']);
            exit;
        }

        $viewMode = 'feed';
        $__externalPosts = $rows;
        $__suppressEmptyFeedMessage = true;
        while (ob_get_level() > 0) { ob_end_clean(); }
        ob_start();
        include $layout;
        $html = trim(ob_get_clean());

        echo json_encode([
            'status'      => 'ok',
            'html'        => $html,
            'has_more'    => $hasMore,
            'next_offset' => $offset + count($rows)
        ]);
    } catch (Throwable $e) {
        if (isset($RL) && is_object($RL) && is_callable([$RL, 'logError'])) {
            $RL->logError('guest_feed_more error: ' . $e->getMessage());
        }
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'SERVER']);
    }
    exit;
}

if ($pIncoming === 'subscription_fees_calculate') {
    $paymentHandler->handleSubscriptionFeesCalculate();
    return;
}
if ($pIncoming === 'podcast_ad_click') {
    $podcastAdsHandler->handleTrackClick();
    return;
}
if (isset($_POST['p'])) {
    $p = trim((string) $_POST['p']);

    if ($p === 'podcast_ad_list_podcasts') {
        $podcastAdsHandler->handleListMyPodcasts();
        return;
    }

    if ($p === 'podcast_ad_packages') {
        $podcastAdsHandler->handleListPackages();
        return;
    }

    if ($p === 'podcast_ad_start_payment') {
        $podcastAdsHandler->handleStartPayment();
        return;
    }

    if ($p === 'search') {
        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($token)) {
            http_response_code(403);
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('invalid_csrf_token')
            ]);
            exit;
        }

        $filePath = __DIR__ . '/../themes/' . $currentTheme . '/header/search.php';
        if (!is_file($filePath)) {
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('requested_content_not_found', 'Requested content not found.')
            ]);
            exit;
        }

        ob_start();
        include $filePath;
        $htmlContent = ob_get_clean();

        echo json_encode([
            'status' => 'success',
            'html'   => $htmlContent
        ]);
        exit;
    }

    if ($p === 'guest_update_language') {
        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($token)) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => customLang('invalid_csrf_token')
            ]);
            exit;
        }

        $language = isset($_POST['language']) ? strtolower(trim((string) $_POST['language'])) : '';
        $languageOptions = function_exists('discoverAvailableLanguages') ? discoverAvailableLanguages() : [];
        if (empty($languageOptions)) {
            $languageOptions = ['eng'];
        }

        if ($language === '' || !in_array($language, $languageOptions, true)) {
            echo json_encode([
                'status' => 'error',
                'message' => customLang('language_not_available', 'Language not available.')
            ]);
            exit;
        }

        $cookieName = defined('GUEST_LANGUAGE_COOKIE_NAME') ? GUEST_LANGUAGE_COOKIE_NAME : 'guest_language';
        $expires = time() + 31556926;
        $secure = isset($__isHttps)
            ? (bool) $__isHttps
            : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443'));

        if (PHP_VERSION_ID >= 70300) {
            setcookie($cookieName, $language, [
                'expires'  => $expires,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        } else {
            $path = '/; SameSite=Lax';
            setcookie($cookieName, $language, $expires, $path, '', $secure, false);
        }

        $_COOKIE[$cookieName] = $language;

        echo json_encode([
            'status' => 'success',
            'message' => customLang('language_switch_success', 'Language updated.'),
            'data' => [
                'language' => $language,
            ],
        ]);
        exit;
    }

    if ($p === 'podcast_ad_submit') {
        $podcastAdsHandler->handleCreate();
        exit;
    }

    if ($p === 'podcast_ad_delete') {
        $podcastAdsHandler->handleDelete();
        exit;
    }

    if ($p === 'explore_more') {

        try {
            $cursorTime = isset($_POST['cursor_time']) ? (int) $_POST['cursor_time'] : 0;
            $cursorId   = isset($_POST['cursor_id'])   ? (int) $_POST['cursor_id']   : 0;
            $limit      = isset($_POST['limit']) ? (int) $_POST['limit'] : 25;

            if ($limit < 5)  { $limit = 5;  }
            if ($limit > 50) { $limit = 50; } // safety upper bound

            // Parse and normalize incoming start_layout
            $startLayoutIn = isset($_POST['start_layout']) ? strtolower(trim((string)$_POST['start_layout'])) : 'right';
            $startLayout   = ($startLayoutIn === 'left') ? 'left' : 'right';

            // Sanity/availability checks
            if (!isset($RL) || !is_object($RL) || !method_exists($RL, 'getPageByCursor') || !method_exists($RL, 'renderToString')) {
                http_response_code(500);
                echo json_encode([
                    'status'  => 'error',
                    'message' => customLang('error_server_missing_method'),
                    'code'    => 'MISSING_METHOD'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $result = $RL->getPageByCursor($cursorTime, $cursorId, $limit);

            // Use client-provided start layout when rendering
            $html = $RL->renderToString($result['blocks'], $startLayout, false); // 'right' / 'left', no wrapper
            $html = trim((string) $html);

            // Safety: remove any accidental outer explore-grid wrapper so we only send blocks.
            if ($html !== '' && preg_match('/^<\s*div/i', $html) && stripos($html, 'explore-grid') !== false) {
                $withoutWrapper = $html;
                // Quick strip when markup is exactly <div class="explore-grid"> ... </div>
                if (preg_match('/^<div[^>]*class="[^"]*explore-grid[^"]*"[^>]*>(.*)<\/div>$/si', $html, $m)) {
                    $withoutWrapper = $m[1];
                } else {
                    // Fallback: remove first opening div and trailing closing div.
                    $posStart = strpos($html, '>');
                    $posEnd   = strrpos($html, '</div>');
                    if ($posStart !== false && $posEnd !== false && $posEnd > $posStart) {
                        $withoutWrapper = substr($html, $posStart + 1, $posEnd - $posStart - 1);
                    }
                }
                $html = trim($withoutWrapper);
            }

            $blockChunks = [];
            if ($html !== '') {
                $parts = preg_split('/(?=<section\s+class="[^"]*\bexplore-block\b)/i', $html, -1, PREG_SPLIT_NO_EMPTY);
                if (is_array($parts)) {
                    foreach ($parts as $part) {
                        $chunk = trim($part);
                        if ($chunk !== '') {
                            $blockChunks[] = $chunk;
                        }
                    }
                }
            }

            // Compute and return next_start_layout
            $blockCount = is_array($result['blocks'] ?? null) ? count($result['blocks']) : 0;
            // if we rendered an odd number of blocks, flip; if even, keep same
            $nextStartLayout = ($blockCount % 2 === 0) ? $startLayout : ($startLayout === 'right' ? 'left' : 'right');

            dz_json_response([
                'status'      => 'ok',
                'html'        => $html,
                'blocks_html' => $blockChunks,
                'has_more'    => $result['hasMore'],
                'next_cursor' => $result['nextCursor'], // ['time' => int, 'id' => int]
                'next_start_layout' => $nextStartLayout,
            ]);
        } catch (\Throwable $e) {
            // Log for server-side debugging
            if (isset($RL) && is_object($RL) && is_callable([$RL, 'logError'])) {
                $RL->logError('explore_more error: ' . $e->getMessage());
            } else {
                error_log('explore_more error: ' . $e->getMessage());
            }
            dz_json_response([
                'status'   => 'error',
                'message'  => 'LOAD_FAILED',
                'error'    => $e->getMessage(),
            ], 500);
        }
        exit;
    }

    if ($p === 'podcasts_more') {
        $csrfToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('error_csrf', 'Invalid token.')]);
            exit;
        }

        if (!$podcastsEnabledGlobal) {
            dz_json_response([
                'status'  => 'error',
                'message' => customLang('content_disabled_podcasts', 'Podcast sharing is currently disabled.'),
            ], 403);
            exit;
        }

        $baseUrl = isset($base_url) ? (string) $base_url : '';
        $langCode = isset($currentLang) && is_string($currentLang) ? strtolower((string) $currentLang) : 'eng';
        if ($langCode === '') {
            $langCode = 'eng';
        }

        $cursorTime = isset($_POST['cursor_time']) ? (int) $_POST['cursor_time'] : 0;
        $cursorId   = isset($_POST['cursor_id']) ? (int) $_POST['cursor_id'] : 0;
        if ($cursorTime < 0) { $cursorTime = 0; }
        if ($cursorId < 0) { $cursorId = 0; }

        $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 12;
        if ($limit < 6) { $limit = 6; }
        if ($limit > 40) { $limit = 40; }
        $limitPlusOne = $limit + 1;

        $categorySlug = isset($_POST['category']) ? (string) $_POST['category'] : '';
        $categorySlug = strtolower(trim($categorySlug));
        if ($categorySlug !== '') {
            $categorySlug = preg_replace('/[^a-z0-9_-]+/', '', $categorySlug);
        }

        if (!isset($RL) || !is_object($RL) || !method_exists($RL, 'getDb')) {
            dz_json_response([
                'status'  => 'error',
                'message' => 'MISSING_DB',
            ], 500);
            exit;
        }

        try {
            if (method_exists($RL, 'RL_EnsurePodcastCategoriesTable')) {
                $RL->RL_EnsurePodcastCategoriesTable();
            }

            $pdo = $RL->getDb();
            $params = [':lang' => $langCode];
            $where = [
                "p.post_type = 'podcast'",
                "p.post_visibility = 'everyone'",
                "(p.approval_status IS NULL OR p.approval_status = 'approved')",
            ];

            if ($cursorTime > 0 || $cursorId > 0) {
                $where[] = '(p.post_created_time < :cursor_time OR (p.post_created_time = :cursor_time AND p.post_id < :cursor_id))';
                $params[':cursor_time'] = $cursorTime;
                $params[':cursor_id'] = $cursorId;
            }

            if ($categorySlug !== '') {
                $where[] = 'pc.slug = :catSlug';
                $params[':catSlug'] = $categorySlug;
            }

            $sql = "SELECT
                        p.post_id,
                        p.post_owner_id,
                        p.post_text,
                        p.post_file,
                        p.audio_duration_seconds,
                        p.post_created_time,
                        p.podcast_category_id,
                        COALESCE(pct.name, pc.name) AS podcast_category_name,
                        pc.slug  AS podcast_category_slug,
                        pc.status AS podcast_category_status,
                        u.username AS owner_username,
                        u.user_fullname AS owner_fullname,
                        u.user_avatar AS owner_avatar,
                        u.verified_status AS owner_verified
                    FROM i_user_posts AS p
                    INNER JOIN i_users AS u ON u.user_id = p.post_owner_id
                    LEFT JOIN i_podcast_categories AS pc ON pc.id = p.podcast_category_id
                    LEFT JOIN i_podcast_category_translations AS pct ON pct.category_id = pc.id AND pct.language = :lang";

            $sql .= ' WHERE ' . implode(' AND ', $where);
            $sql .= ' ORDER BY p.post_created_time DESC, p.post_id DESC LIMIT :limit_plus';

            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit_plus', $limitPlusOne, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            $podcasts = [];
            foreach ($rows as $row) {
                $postId = (int) ($row['post_id'] ?? 0);
                if ($postId <= 0) {
                    continue;
                }

                $ownerId = (int) ($row['post_owner_id'] ?? 0);
                $ownerUsername = (string) ($row['owner_username'] ?? '');
                $ownerFullname = (string) ($row['owner_fullname'] ?? '');
                $displayName = $ownerFullname !== '' ? $ownerFullname : $ownerUsername;

                $avatarRel = (string) ($row['owner_avatar'] ?? '');
                if ($avatarRel === '' && method_exists($RL, 'RL_userAvatar') && $ownerId > 0) {
                    try {
                        $avatarRel = (string) $RL->RL_userAvatar($ownerId);
                    } catch (\Throwable $__) {
                        $avatarRel = '';
                    }
                }
                if ($avatarRel === '') {
                    $avatarRel = 'uploads/user_avatars/default.jpeg';
                }
                $avatarUrl = storage_url($avatarRel);

                $coverRel = '';
                if (isset($row['cover_path'])) {
                    $coverRel = (string) $row['cover_path'];
                } elseif (method_exists($RL, 'RL_GetPostCoverPath')) {
                    try {
                        $coverRel = (string) $RL->RL_GetPostCoverPath($postId);
                    } catch (\Throwable $__) {
                        $coverRel = '';
                    }
                }
                $coverUrl = $coverRel !== '' ? storage_url($coverRel) : '';

                $audioKey = isset($row['post_file']) ? (string) $row['post_file'] : '';
                $audioUrl = $audioKey !== '' ? storage_url($audioKey) : '';

                $podcasts[] = [
                    'id'              => $postId,
                    'title'           => trim((string) ($row['post_text'] ?? '')),
                    'username'        => $ownerUsername,
                    'fullname'        => $displayName,
                    'avatar'          => $avatarUrl,
                    'verified'        => (int) ($row['owner_verified'] ?? 0) === 1,
                    'category_name'   => isset($row['podcast_category_name']) ? (string) $row['podcast_category_name'] : '',
                    'category_slug'   => isset($row['podcast_category_slug']) ? (string) $row['podcast_category_slug'] : '',
                    'duration'        => isset($row['audio_duration_seconds']) ? (int) $row['audio_duration_seconds'] : null,
                    'cover_url'       => $coverUrl,
                    'audio_url'       => $audioUrl,
                    'liked'           => false,
                    'bookmarked'      => false,
                    'created_ts'      => isset($row['post_created_time']) ? (int) $row['post_created_time'] : 0,
                ];
            }

            $viewerId = isset($userID) ? (int) $userID : 0;
            if ($viewerId > 0 && $podcasts) {
                foreach ($podcasts as $idx => $podcastRow) {
                    $pid = (int) ($podcastRow['id'] ?? 0);
                    if ($pid <= 0) { continue; }
                    if (method_exists($RL, 'RL_HasLiked')) {
                        try {
                            $podcasts[$idx]['liked'] = (bool) $RL->RL_HasLiked($viewerId, $pid, 'post');
                        } catch (\Throwable $__) {
                            $podcasts[$idx]['liked'] = false;
                        }
                    }
                    if (method_exists($RL, 'RL_IsBookmarked')) {
                        try {
                            $podcasts[$idx]['bookmarked'] = (bool) $RL->RL_IsBookmarked($viewerId, $pid, 'image');
                        } catch (\Throwable $__) {
                            $podcasts[$idx]['bookmarked'] = false;
                        }
                    }
                }
            }

            $hasMore = false;
            if ($podcasts && count($podcasts) > $limit) {
                $hasMore = true;
                $podcasts = array_slice($podcasts, 0, $limit);
            }

            $nextCursor = ['time' => 0, 'id' => 0];
            if ($podcasts) {
                $last = $podcasts[count($podcasts) - 1];
                $nextCursor = [
                    'time' => isset($last['created_ts']) ? (int) $last['created_ts'] : 0,
                    'id'   => isset($last['id']) ? (int) $last['id'] : 0,
                ];
            }

            $partialPath = __DIR__ . '/../themes/' . ($currentTheme ?? 'default') . '/layouts/partials/podcastRow.php';
            if (!is_file($partialPath)) {
                dz_json_response([
                    'status'  => 'error',
                    'message' => 'TPL_MISSING',
                ], 500);
                exit;
            }

            $playLabel = customLang('page_podcasts_play', 'Play');
            $saveLabel = customLang('page_podcasts_save', 'Save');
            $likeLabel = customLang('page_podcasts_like', 'Like');
            $recentTitle = customLang('page_podcasts_recent_title', 'Recent');
            $durationLabel = customLang('page_podcasts_duration_label', 'Duration');
            $formatDuration = static function (?int $seconds): string {
                if ($seconds === null || $seconds < 0) {
                    return '--:--';
                }
                $minutes = (int) floor($seconds / 60);
                $remaining = $seconds % 60;
                if ($minutes > 599) {
                    $minutes = 599;
                }
                return sprintf('%02d:%02d', $minutes, $remaining);
            };

            $htmlChunks = [];
            foreach ($podcasts as $podcast) {
                ob_start();
                include $partialPath;
                $htmlChunks[] = ob_get_clean();
            }

            dz_json_response([
                'status'      => 'ok',
                'html'        => implode('', $htmlChunks),
                'has_more'    => $hasMore,
                'next_cursor' => $nextCursor,
            ]);
        } catch (\Throwable $e) {
            if (isset($RL) && is_callable([$RL, 'logError'])) {
                $RL->logError('podcasts_more error: ' . $e->getMessage());
            } else {
                error_log('podcasts_more error: ' . $e->getMessage());
            }
            dz_json_response([
                'status'  => 'error',
                'message' => 'SERVER_ERROR',
            ], 500);
        }
        exit;
    }

    if ($loggedIn != '1') {
        echo json_encode([
            'status'  => 'error',
            'message' => customLang('login_required')
        ]);
        exit;
    }

    // CSRF token validation
    if (!isset($_POST['csrf_token']) || !checkCsrfToken($_POST['csrf_token'])) {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => customLang('invalid_csrf_token')
        ]);
        exit;
    }

    $p = isset($_POST['p']) ? trim($_POST['p']) : '';

    if ($p === '') {
        echo json_encode([
            'status'  => 'error',
            'message' => customLang('error_invalid_parameters', 'Invalid parameters.')
        ]);
        exit;
    }

    if ($p === 'wallet_topup_modal') {
        $paymentHandler->handleWalletTopupModal($walletTopupLimits);
        return;
    }

    if ($p === 'wallet_topup_create_payment') {
        $paymentHandler->handleWalletTopupCreatePayment($walletTopupLimits);
        return;
    }

    if ($p === 'wallet_balance_get') {
        $paymentHandler->handleWalletBalanceGet();
        return;
    }

    // =============================
    // Live Chat: Send message
    // =============================
    if ($p === 'live_chat_send') {
        try {
            if (!isset($RL) || !method_exists($RL, 'getDb')) {
                echo json_encode(['status'=>'error','message'=>customLang('db_not_available')]); exit;
            }
            if (!$liveChatEnabledGlobal) {
                echo json_encode(['status'=>'error','message'=>'live_chat_disabled']);
                exit;
            }
            $db = $RL->getDb();
            $liveID = (int)($_POST['live_id'] ?? 0);
            $msgRaw = (string)($_POST['message'] ?? '');
            $uid    = (int)($userID ?? 0);
            if ($liveID <= 0 || $uid <= 0) { echo json_encode(['status'=>'error','message'=>'INVALID']); exit; }

            $msg = trim($msgRaw);
            // Normalize whitespace
            $msg = preg_replace("/[\r\n\t]+/", ' ', $msg);
            if ($msg === '' || mb_strlen($msg, 'UTF-8') === 0) {
                echo json_encode(['status'=>'error','message'=>'EMPTY']); exit;
            }
            // Limit length to 500 chars
            if (mb_strlen($msg, 'UTF-8') > 500) { $msg = mb_substr($msg, 0, 500, 'UTF-8'); }

            $now = time();
            // Rate limit: one message per 3 seconds per user per live
            $lastTs = method_exists($RL,'RL_GetLastLiveChatMessageTime') ? $RL->RL_GetLastLiveChatMessageTime($liveID, $uid) : 0;
            if ($lastTs > 0) {
                $diff = $now - $lastTs;
                if ($diff < 3) {
                    echo json_encode(['status'=>'error','message'=>'COOLDOWN','retry_after'=> (3 - $diff)]); exit;
                }
            }

            // Optional: verify live exists and not ended
            try {
                $status = method_exists($RL,'RL_GetLiveStatus') ? (string)($RL->RL_GetLiveStatus($liveID) ?? '') : '';
                if ($status && $status !== 'live' && $status !== 'created') {
                    echo json_encode(['status'=>'error','message'=>'ENDED']); exit;
                }
            } catch (Throwable $__) { /* ignore */ }

            // Insert message via helper
            $id = method_exists($RL,'RL_InsertLiveChatMessage') ? $RL->RL_InsertLiveChatMessage($liveID, $uid, $msg, $now) : 0;

            // Load sender data for UI
            $username = '';
            $fullname = '';
            $verified = 0;
            try {
                if (method_exists($RL,'RL_GetUserDetails')) {
                    $uRow = $RL->RL_GetUserDetails($uid);
                    if (is_array($uRow)) {
                        $username = (string)($uRow['username'] ?? '');
                        $fullname = (string)($uRow['user_fullname'] ?? '');
                        $verified = (int)($uRow['verified_status'] ?? 0);
                    }
                }
            } catch (Throwable $__) {}
            $avatar = method_exists($RL, 'RL_userAvatar') ? $RL->RL_userAvatar($uid) : 'uploads/user_avatars/default.jpeg';

            echo json_encode([
                'status' => 'success',
                'message' => [
                    'id' => $id,
                    'live_id' => $liveID,
                    'user_id' => $uid,
                    'message' => $msg,
                    'created_at' => $now,
                    'username' => $username,
                    'user_fullname' => $fullname,
                    'verified_status' => $verified,
                    'avatar' => $avatar
                ]
            ]);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>'SERVER']);
        }
        exit;
    }

    if ($p === 'uploadImage') {
        $debugRef = date('YmdHis');
        try {
            $debugRef .= '_' . substr(bin2hex(random_bytes(4)), 0, 8);
        } catch (Throwable $__) {
            $debugRef .= '_' . substr(md5((string) microtime(true)), 0, 8);
        }
        $debugLogDir = __DIR__ . '/../uploads/logs';
        $debugLogFile = $debugLogDir . '/upload_error.log';
        $GLOBALS['uploadDebugRef'] = $debugRef;
        $writeDebug = static function (array $payload) use ($debugLogDir, $debugLogFile): void {
            if (!is_dir($debugLogDir)) {
                @mkdir($debugLogDir, 0755, true);
            }
            $line = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if ($line === false) {
                $fallback = '[uploadImage] ' . date('c');
                if (isset($payload['ref'])) {
                    $fallback .= ' ref=' . $payload['ref'];
                }
                if (isset($payload['stage'])) {
                    $fallback .= ' stage=' . $payload['stage'];
                }
                $line = $fallback;
            }
            @file_put_contents($debugLogFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        };
        $writeDebug([
            'ref' => $debugRef,
            'time' => date('c'),
            'stage' => 'start',
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'content_length' => (int) ($_SERVER['CONTENT_LENGTH'] ?? 0),
        ]);
        register_shutdown_function(static function () use ($writeDebug, $debugRef): void {
            $err = error_get_last();
            if (!$err || !isset($err['type'])) {
                return;
            }
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array($err['type'], $fatalTypes, true)) {
                return;
            }
            $writeDebug([
                'ref' => $debugRef,
                'time' => date('c'),
                'stage' => 'fatal',
                'error' => [
                    'type' => (int) $err['type'],
                    'message' => (string) ($err['message'] ?? ''),
                    'file' => (string) ($err['file'] ?? ''),
                    'line' => (int) ($err['line'] ?? 0),
                ],
            ]);
        });
        if ($loggedIn !== '1' || (int) ($userID ?? 0) <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            exit;
        }
        if (!$imagesEnabledGlobal) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('content_disabled_images', 'Image sharing is currently disabled.')]);
            exit;
        }
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }
        try {
            $reelsHandler->handleUploadImage();
        } catch (Throwable $e) {
            $filesMeta = [];
            if (isset($_FILES['images'])) {
                $images = $_FILES['images'];
                $names = (array) ($images['name'] ?? []);
                $sizes = (array) ($images['size'] ?? []);
                $errors = (array) ($images['error'] ?? []);
                foreach ($names as $idx => $name) {
                    $filesMeta[] = [
                        'name' => (string) $name,
                        'size' => (int) ($sizes[$idx] ?? 0),
                        'error' => (int) ($errors[$idx] ?? UPLOAD_ERR_NO_FILE),
                    ];
                }
            }
            $writeDebug([
                'ref' => $debugRef,
                'time' => date('c'),
                'stage' => 'exception',
                'message' => $e->getMessage(),
                'location' => $e->getFile() . ':' . $e->getLine(),
                'user_id' => (int) ($userID ?? 0),
                'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                'post_keys' => array_keys($_POST ?? []),
                'files' => $filesMeta,
            ]);
            $isAdminViewer = isset($isAdminUser) ? (bool) $isAdminUser : (bool) ($GLOBALS['isAdminUser'] ?? false);
            $debugEnabled = $isAdminViewer || (defined('APP_DEBUG') && APP_DEBUG);
            $response = [
                'status' => 'error',
                'message' => customLang('server_error'),
                'debug_ref' => $debugRef,
            ];
            if ($debugEnabled) {
                $response['debug_detail'] = $e->getMessage();
                $response['debug_location'] = $e->getFile() . ':' . $e->getLine();
                $response['debug_log'] = $debugLogFile;
                $response['debug_log_writable'] = is_writable(dirname($debugLogFile));
            }
            dz_json_response($response, 500);
        }
        return;
    }

    // =============================
    // Profile: Update basic info + avatar/cover
    // =============================
    if ($p === 'update_profile') {
        if ($loggedIn !== '1' || (int) ($userID ?? 0) <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            exit;
        }
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }
        $userHandler->handleUpdateProfile();
        return;
    }

    if ($p === 'creator_identity_submit') {
        try {
            if (!isset($RL) || !is_object($RL) || !method_exists($RL, 'RL_SaveCreatorVerificationRequest')) {
                echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
                exit;
            }
            $uid = isset($userID) ? (int)$userID : 0;
            if ($uid <= 0) {
                echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
                exit;
            }

            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
            $maxMbSetting = isset($availableUploadFileSize) ? (float)$availableUploadFileSize : 5.0;
            if ($maxMbSetting <= 0) { $maxMbSetting = 5.0; }
            $maxBytes = (int)round($maxMbSetting * 1048576);
            $now = time();
            $folder = date('Y/m', $now);
            $absDir = __DIR__ . '/../uploads/verification/' . $folder . '/';
            if (!is_dir($absDir)) { @mkdir($absDir, 0755, true); }
            $relDir = 'uploads/verification/' . $folder . '/';
            $finfo = function_exists('finfo_open') ? @finfo_open(FILEINFO_MIME_TYPE) : null;

            $normalizeUpload = static function(string $field, ?int $idx = null) {
                if (!isset($_FILES[$field])) { return null; }
                $file = $_FILES[$field];
                if (is_array($file['name'])) {
                    if ($idx === null) { $idx = 0; }
                    if (!isset($file['name'][$idx])) { return null; }
                    return [
                        'name'     => $file['name'][$idx],
                        'type'     => $file['type'][$idx] ?? '',
                        'tmp_name' => $file['tmp_name'][$idx] ?? '',
                        'error'    => $file['error'][$idx] ?? UPLOAD_ERR_NO_FILE,
                        'size'     => $file['size'][$idx] ?? 0,
                    ];
                }
                if ($idx !== null) { return null; }
                return $file;
            };

            $processUpload = function(array $file, string $slug) use ($allowedMimes, $maxBytes, $absDir, $relDir, $uid, $finfo) {
                if (!isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                    return [false, customLang('upload_failed')];
                }
                $size = (int)($file['size'] ?? 0);
                if ($size <= 0 || $size > $maxBytes) {
                    return [false, customLang('file_too_large')];
                }
                $tmp = $file['tmp_name'];
                $mime = '';
                if ($finfo) {
                    $mime = (string)@finfo_file($finfo, $tmp);
                } elseif (function_exists('mime_content_type')) {
                    $mime = (string)@mime_content_type($tmp);
                }
                if ($mime === '' && !empty($file['type'])) {
                    $mime = (string)$file['type'];
                }
                if (!in_array($mime, $allowedMimes, true)) {
                    return [false, customLang('ui_only_images_allowed')];
                }
                $imgInfo = @getimagesize($tmp);
                if ($imgInfo === false) {
                    return [false, customLang('invalid_file_format')];
                }
                $imgType = (int)($imgInfo[2] ?? 0);
                if (!in_array($imgType, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
                    return [false, customLang('ui_only_images_allowed')];
                }
                $w = (int)($imgInfo[0] ?? 0);
                $h = (int)($imgInfo[1] ?? 0);
                if ($w <= 0 || $h <= 0) {
                    return [false, customLang('invalid_file_format')];
                }

                $src = null;
                if ($imgType === IMAGETYPE_JPEG) { $src = @imagecreatefromjpeg($tmp); }
                elseif ($imgType === IMAGETYPE_PNG) { $src = @imagecreatefrompng($tmp); }
                elseif ($imgType === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) { $src = @imagecreatefromwebp($tmp); }
                if (!$src) {
                    return [false, customLang('invalid_file_format')];
                }

                $dst = @imagecreatetruecolor($w, $h);
                if ($dst) {
                    $white = imagecolorallocate($dst, 255, 255, 255);
                    @imagefilledrectangle($dst, 0, 0, $w, $h, $white);
                    @imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
                } else {
                    $dst = $src;
                }
                @imagedestroy($src);

                try { $rand = bin2hex(random_bytes(6)); } catch (Throwable $__) { $rand = str_replace('.', '', uniqid('', true)); }
                $name = $slug . '_' . $uid . '_' . $rand . '.jpg';
                $dest = $absDir . $name;
                if (!is_dir(dirname($dest))) { @mkdir(dirname($dest), 0755, true); }
                $saved = @imagejpeg($dst, $dest, 90);
                @imagedestroy($dst);
                if (!$saved || !is_file($dest)) {
                    return [false, customLang('upload_failed')];
                }
                return [true, $relDir . $name];
            };

            $documents = [];
            foreach ([
                'id_front' => 'id_front',
                'id_back'  => 'id_back',
            ] as $field => $slug) {
                $file = $normalizeUpload($field);
                if (!$file) {
                    echo json_encode(['status' => 'error', 'message' => customLang('creator_identity_missing_' . $field, 'Required document missing.')]);
                    exit;
                }
                [$ok, $pathOrErr] = $processUpload($file, $slug);
                if (!$ok) {
                    echo json_encode(['status' => 'error', 'message' => $pathOrErr]);
                    exit;
                }
                $documents[$slug] = $pathOrErr;
            }

            foreach ([
                'passport'        => 'passport',
                'driver_license'  => 'driver_license',
                'selfie'          => 'selfie'
            ] as $field => $slug) {
                $file = $normalizeUpload($field);
                if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                [$ok, $pathOrErr] = $processUpload($file, $slug);
                if ($ok) {
                    $documents[$slug] = $pathOrErr;
                }
            }

            if (isset($_FILES['additional_docs']) && is_array($_FILES['additional_docs']['name'])) {
                $documents['additional'] = [];
                foreach ((array)$_FILES['additional_docs']['name'] as $idx => $__) {
                    $file = $normalizeUpload('additional_docs', (int)$idx);
                    if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                        continue;
                    }
                    [$ok, $pathOrErr] = $processUpload($file, 'extra' . $idx);
                    if ($ok) {
                        $documents['additional'][] = $pathOrErr;
                    }
                }
                if (empty($documents['additional'])) {
                    unset($documents['additional']);
                }
            }

            $note = trim((string)($_POST['notes'] ?? ''));
            $saved = $RL->RL_SaveCreatorVerificationRequest($uid, $documents, $note);
            if (!$saved) {
                echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
                exit;
            }

            echo json_encode([
                'status' => 'success',
                'message' => customLang('creator_identity_submitted', 'Your documents were submitted for review.'),
                'data' => $saved,
            ]);
        } catch (Throwable $e) {
            if (isset($RL) && method_exists($RL, 'logError')) {
                $RL->logError('creator_identity_submit failed: ' . $e->getMessage());
            }
            echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
        }
        exit;
    }

    if ($p === 'creator_plans_save') {
        try {
            if (!isset($RL) || !is_object($RL) || !method_exists($RL, 'RL_SaveUserSubscriptionPlans')) {
                echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
                exit;
            }
            $uid = isset($userID) ? (int)$userID : 0;
            if ($uid <= 0) {
                echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
                exit;
            }

            $priceFields = ['weekly','monthly','halfyear','yearly'];
            $plans = array_fill_keys($priceFields, null);
            $hasPlanValue = false;
            foreach ($priceFields as $field) {
                $key = 'price_' . $field;
                $raw = isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
                if ($raw === '') { continue; }
                $normalized = str_replace(',', '.', $raw);
                $val = filter_var($normalized, FILTER_VALIDATE_FLOAT);
                if ($val === false) {
                    echo json_encode(['status' => 'error', 'message' => customLang('invalid_price_value', 'Invalid price value.')]);
                    exit;
                }
                $val = round((float)$val, 2);
                if ($val <= 0) { continue; }
                $plans[$field] = $val;
                $hasPlanValue = true;
            }

            $toggleRaw = isset($_POST['subscription_enabled']) ? strtolower(trim((string)$_POST['subscription_enabled'])) : '0';
            $subscriptionEnabled = in_array($toggleRaw, ['1', 'true', 'on', 'open'], true);

            if ($subscriptionEnabled && !$hasPlanValue) {
                echo json_encode(['status' => 'error', 'message' => customLang('creator_plan_required', 'Please define at least one subscription price.')]);
                exit;
            }

            $currencyCode = isset($currency) ? (string)$currency : 'USD';
            $shouldPersistPlans = $hasPlanValue;
            if (!$shouldPersistPlans) {
                foreach ($plans as $value) {
                    if ($value !== null) {
                        $shouldPersistPlans = true;
                        break;
                    }
                }
            }

            if ($shouldPersistPlans) {
                $ok = $RL->RL_SaveUserSubscriptionPlans($uid, $plans, $currencyCode);
                if (!$ok) {
                    echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
                    exit;
                }
            }

            try {
                if (isset($db) && $db instanceof PDO) {
                    $st = $db->prepare('UPDATE i_users SET subscription_status = :sub_status, subscrition_status = :legacy_status WHERE user_id = :uid');
                    $st->execute([
                        ':sub_status' => $subscriptionEnabled ? 'open' : 'close',
                        ':legacy_status' => $subscriptionEnabled ? 'active' : 'passive',
                        ':uid' => $uid,
                    ]);
                }
                if ($subscriptionEnabled && method_exists($RL, 'RL_UpdateCreatorStatus')) {
                    $RL->RL_UpdateCreatorStatus($uid, 'approved');
                }
            } catch (Throwable $__) {}

            echo json_encode([
                'status' => 'success',
                'message' => customLang('creator_plan_saved', 'Subscription plans saved.'),
                'data' => [
                    'plans' => array_filter(
                        $plans,
                        static function ($value) { return $value !== null; }
                    ),
                    'currency' => $currencyCode,
                    'subscription_enabled' => $subscriptionEnabled,
                ],
            ]);
        } catch (Throwable $e) {
            if (isset($RL) && method_exists($RL, 'logError')) { $RL->logError('creator_plans_save failed: ' . $e->getMessage()); }
            echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
        }
        exit;
    }

    if ($p === 'creator_payout_update') {
        try {
            if (!isset($RL) || !is_object($RL) || !method_exists($RL, 'RL_UpdateUserPayoutPreferences')) {
                echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
                exit;
            }
            $uid = isset($userID) ? (int)$userID : 0;
            if ($uid <= 0) {
                echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
                exit;
            }
            $token = (string) ($_POST['csrf_token'] ?? '');
            if (!checkCsrfToken($token)) {
                echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
                exit;
            }

            $method = strtolower(trim((string)($_POST['payout_method'] ?? 'none')));
            $allowedMethods = ['none','bank','paypal','stripe','payoneer','other'];
            if (!in_array($method, $allowedMethods, true)) {
                echo json_encode(['status' => 'error', 'message' => customLang('invalid_payout_method', 'Invalid payout method.')]);
                exit;
            }

            $details = [];
            $limitText = static function(string $value, int $limit = 190): string {
                $trimmed = trim($value);
                if ($trimmed === '') { return ''; }
                if (function_exists('mb_substr')) {
                    return mb_substr($trimmed, 0, $limit, 'UTF-8');
                }
                return substr($trimmed, 0, $limit);
            };

            if ($method === 'bank') {
                $details['account_name'] = $limitText((string)($_POST['account_name'] ?? ''));
                $details['bank_name'] = $limitText((string)($_POST['bank_name'] ?? ''));
                $iban = strtoupper(str_replace(' ', '', (string)($_POST['iban'] ?? '')));
                $accountNo = $limitText((string)($_POST['account_number'] ?? ''));
                $swift = strtoupper(str_replace(' ', '', $limitText((string)($_POST['swift'] ?? ''), 11)));
                if ($iban === '' && $accountNo === '') {
                    echo json_encode(['status' => 'error', 'message' => customLang('bank_account_required', 'Provide IBAN or account number.')]);
                    exit;
                }
                if ($iban !== '') { $details['iban'] = $iban; }
                if ($accountNo !== '') { $details['account_number'] = $accountNo; }
                if ($swift !== '') { $details['swift'] = $swift; }
                $notes = $limitText((string)($_POST['bank_notes'] ?? ''), 300);
                if ($notes !== '') { $details['notes'] = $notes; }
            } elseif ($method === 'paypal') {
                $email = trim((string)($_POST['paypal_email'] ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['status' => 'error', 'message' => customLang('invalid_email')]);
                    exit;
                }
                $details['email'] = $email;
                $details['paypal_email'] = $email;
            } elseif ($method === 'stripe') {
                $accountId = strtoupper($limitText((string)($_POST['stripe_account_id'] ?? ''), 64));
                if ($accountId === '') {
                    echo json_encode(['status' => 'error', 'message' => customLang('settings_payout_stripe_account_required', 'Enter your Stripe account ID.')]);
                    exit;
                }
                $details['account_id'] = $accountId;
                $email = trim((string)($_POST['stripe_email'] ?? ''));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $details['email'] = $email;
                }
            } elseif ($method === 'payoneer') {
                $email = trim((string)($_POST['payoneer_email'] ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['status' => 'error', 'message' => customLang('settings_payout_payoneer_email_required', 'Enter a valid Payoneer email.')]);
                    exit;
                }
                $details['email'] = $email;
            } elseif ($method === 'other') {
                $notes = $limitText((string)($_POST['payout_notes'] ?? ''), 300);
                if ($notes === '') {
                    echo json_encode(['status' => 'error', 'message' => customLang('payout_note_required', 'Describe how you wish to receive payouts.')]);
                    exit;
                }
                $details['notes'] = $notes;
            }

            $RL->RL_UpdateUserPayoutPreferences($uid, $method, $details);

            $payoutContext = null;
            if (method_exists($RL, 'RL_GetUserPayoutDetails')) {
                try {
                    $payoutContext = $RL->RL_GetUserPayoutDetails($uid);
                } catch (Throwable $__) {
                    $payoutContext = null;
                }
            }

            echo json_encode([
                'status' => 'success',
                'message' => customLang('payout_preferences_saved', 'Payout preferences updated.'),
                'data' => ['method' => $method, 'details' => $details],
                'payout' => $payoutContext,
            ]);
        } catch (Throwable $e) {
            if (isset($RL) && method_exists($RL, 'logError')) { $RL->logError('creator_payout_update failed: ' . $e->getMessage()); }
            echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
        }
        exit;
    }

    if ($p === 'settings_bank_update') {
        try {
            if (!isset($RL) || !is_object($RL) || !method_exists($RL, 'RL_SavePayoutMethodDetails')) {
                echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
                exit;
            }
            $uid = isset($userID) ? (int) $userID : 0;
            if ($uid <= 0) {
                echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
                exit;
            }
            $token = (string) ($_POST['csrf_token'] ?? '');
            if (!checkCsrfToken($token)) {
                echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
                exit;
            }

            $limitText = static function (string $value, int $limit = 190): string {
                $trimmed = trim($value);
                if ($trimmed === '') { return ''; }
                if (function_exists('mb_substr')) {
                    return mb_substr($trimmed, 0, $limit, 'UTF-8');
                }
                return substr($trimmed, 0, $limit);
            };

            $bankData = [
                'account_name'   => $limitText((string) ($_POST['account_name'] ?? ''), 120),
                'bank_name'      => $limitText((string) ($_POST['bank_name'] ?? ''), 120),
                'iban'           => strtoupper(str_replace(' ', '', $limitText((string) ($_POST['iban'] ?? ''), 34))),
                'swift'          => strtoupper(str_replace(' ', '', $limitText((string) ($_POST['swift'] ?? ''), 11))),
                'account_number' => $limitText((string) ($_POST['account_number'] ?? ''), 34),
                'country'        => $limitText((string) ($_POST['country'] ?? ''), 80),
                'city'           => $limitText((string) ($_POST['city'] ?? ''), 80),
                'address'        => $limitText((string) ($_POST['address'] ?? ''), 190),
                'postcode'       => $limitText((string) ($_POST['postcode'] ?? ''), 32),
            ];

            if ($bankData['account_name'] === '' || $bankData['bank_name'] === '') {
                echo json_encode(['status' => 'error', 'message' => customLang('settings_bank_error_required', 'Fill in the required bank account fields.')]);
                exit;
            }
            if ($bankData['iban'] === '' && $bankData['account_number'] === '') {
                echo json_encode(['status' => 'error', 'message' => customLang('bank_account_required', 'Provide IBAN or account number.')]);
                exit;
            }

            $setDefault = isset($_POST['set_default']) && $_POST['set_default'] === '1';
            $saveOk = $RL->RL_SavePayoutMethodDetails($uid, 'bank', $bankData, $setDefault, 'pending');
            if (!$saveOk) {
                echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
                exit;
            }

            $payoutContext = null;
            if (method_exists($RL, 'RL_GetUserPayoutDetails')) {
                try {
                    $payoutContext = $RL->RL_GetUserPayoutDetails($uid);
                } catch (Throwable $__) {
                    $payoutContext = null;
                }
            }

            echo json_encode([
                'status'  => 'success',
                'message' => customLang('settings_bank_saved', 'Bank information updated.'),
                'data'    => ['method' => 'bank', 'details' => $bankData, 'default' => $setDefault],
                'payout'  => $payoutContext,
            ]);
        } catch (Throwable $e) {
            if (isset($RL) && method_exists($RL, 'logError')) {
                $RL->logError('settings_bank_update failed: ' . $e->getMessage());
            }
            echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
        }
        exit;
    }

    if ($p === 'settings_bank_withdraw') {
        try {
            if (!isset($RL) || !is_object($RL) || !method_exists($RL, 'RL_CreateWithdrawalRequest')) {
                echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
                exit;
            }
            $uid = isset($userID) ? (int) $userID : 0;
            if ($uid <= 0) {
                echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
                exit;
            }
            $token = (string) ($_POST['csrf_token'] ?? '');
            if (!checkCsrfToken($token)) {
                echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
                exit;
            }

            $amount = isset($_POST['amount']) ? (float) $_POST['amount'] : 0.0;
            $method = strtolower(trim((string) ($_POST['method'] ?? 'bank')));
            $note = isset($_POST['note']) ? trim((string) $_POST['note']) : '';

            $currencyCode = isset($currency) ? strtoupper((string) $currency) : 'USD';
            $minimum = isset($minimumWithdrawalAmount) ? (float) $minimumWithdrawalAmount : 0.0;

            $payoutDetails = method_exists($RL, 'RL_GetUserPayoutDetails') ? $RL->RL_GetUserPayoutDetails($uid) : ['methods' => [], 'default_method' => 'bank', 'connected' => []];
            if ($method === '' || $method === 'default') {
                $method = $payoutDetails['default_method'] ?? 'bank';
            }
            if (!in_array($method, ['bank','stripe','payoneer','paypal','other'], true)) {
                echo json_encode(['status' => 'error', 'message' => customLang('invalid_payout_method', 'Invalid payout method.')]);
                exit;
            }
            $connectedMap = $payoutDetails['connected'] ?? [];
            if (empty($connectedMap[$method])) {
                echo json_encode(['status' => 'error', 'message' => customLang('settings_bank_method_not_connected', 'Connect this payout method before requesting a withdrawal.')]);
                exit;
            }
            $destination = $payoutDetails['methods'][$method] ?? [];

            $options = [
                'minimum'        => $minimum,
                'single_pending' => true,
            ];
            if ($note !== '') {
                $options['note'] = mb_substr($note, 0, 250, 'UTF-8');
            }

            $result = $RL->RL_CreateWithdrawalRequest($uid, $amount, $currencyCode, $method, $destination, $options);
            if (empty($result['ok'])) {
                $errorCode = $result['error'] ?? 'UNKNOWN_ERROR';
                $messageMap = [
                    'INVALID_AMOUNT'       => customLang('settings_bank_error_amount', 'Enter a valid withdrawal amount.'),
                    'AMOUNT_BELOW_MINIMUM' => sprintf(customLang('settings_bank_error_minimum', 'Minimum withdrawal amount is %s.'), number_format($minimum, 2)),
                    'INSUFFICIENT_FUNDS'   => customLang('settings_bank_error_balance', 'Insufficient available balance.'),
                    'PENDING_EXISTS'       => customLang('settings_bank_error_pending', 'You already have a pending withdrawal request.'),
                    'INVALID_METHOD'       => customLang('invalid_payout_method', 'Invalid payout method.'),
                    'USER_NOT_FOUND'       => customLang('server_error'),
                    'DB_ERROR'             => customLang('server_error'),
                ];
                $message = $messageMap[$errorCode] ?? customLang('server_error');
                echo json_encode(['status' => 'error', 'message' => $message, 'code' => $errorCode]);
                exit;
            }

            $newSummary = method_exists($RL, 'RL_GetWithdrawalSummary')
                ? $RL->RL_GetWithdrawalSummary($uid)
                : [];

            echo json_encode([
                'status'  => 'success',
                'message' => customLang('settings_bank_request_submitted', 'Withdrawal request submitted successfully.'),
                'data'    => [
                    'payout_id' => $result['payout_id'] ?? null,
                    'reference' => $result['reference'] ?? null,
                    'balance'   => $result['balance'] ?? null,
                    'summary'   => $newSummary,
                ],
            ]);
        } catch (Throwable $e) {
            if (isset($RL) && method_exists($RL, 'logError')) {
                $RL->logError('settings_bank_withdraw failed: ' . $e->getMessage());
            }
            echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
        }
        exit;
    }

    if ($p === 'settings_update_payout_methods') {
        try {
            if (!isset($RL) || !is_object($RL) || !method_exists($RL, 'RL_GetUserPayoutDetails')) {
                echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
                exit;
            }
            $uid = isset($userID) ? (int) $userID : 0;
            if ($uid <= 0) {
                echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
                exit;
            }
            $token = (string) ($_POST['csrf_token'] ?? '');
            if (!checkCsrfToken($token)) {
                echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
                exit;
            }

            $action = isset($_POST['action']) ? (string) $_POST['action'] : 'save';
            $payoutDetails = $RL->RL_GetUserPayoutDetails($uid);

            if (strpos($action, 'disconnect:') === 0) {
                $method = strtolower(substr($action, strlen('disconnect:')));
                if ($method !== '') {
                    $methods = $payoutDetails['methods'] ?? [];
                    if (isset($methods[$method])) {
                        unset($methods[$method]['status']);
                        unset($methods[$method]['connected']);
                        $RL->RL_SavePayoutMethodDetails($uid, $method, ['status' => 'pending'], false);
                        $connected = $payoutDetails['connected'] ?? [];
                        if (isset($connected[$method])) {
                            $connected[$method] = false;
                        }
                        $payoutDetails = $RL->RL_GetUserPayoutDetails($uid);
                    }
                }
                echo json_encode([
                    'status' => 'success',
                    'message' => customLang('settings_payout_method_disconnected', 'Payout method disconnected.'),
                    'data' => $payoutDetails,
                    'payout' => $payoutDetails,
                ]);
                exit;
            }

            if (strpos($action, 'connect:') === 0 || strpos($action, 'configure:') === 0) {
                $method = strtolower(preg_replace('/^(connect:|configure:)/', '', $action));
                if ($method === 'bank') {
                    echo json_encode([
                        'status' => 'success',
                        'message' => customLang('settings_payout_bank_redirect', 'Update your bank details in the Bank tab.'),
                        'data' => $payoutDetails,
                        'payout' => $payoutDetails,
                    ]);
                    exit;
                }
                echo json_encode([
                    'status' => 'success',
                    'message' => customLang('settings_payout_method_configure', 'Update this payout method in the creator payout settings.'),
                    'data' => $payoutDetails,
                    'payout' => $payoutDetails,
                ]);
                exit;
            }

            $defaultMethod = isset($_POST['default_method']) ? strtolower(trim((string) $_POST['default_method'])) : '';
            if ($defaultMethod !== '' && $defaultMethod !== 'none') {
                $RL->RL_SavePayoutMethodDetails($uid, $defaultMethod, [], true, null, ['preserve_status' => true]);
                $payoutDetails = $RL->RL_GetUserPayoutDetails($uid);
            } elseif ($defaultMethod === 'none') {
                $RL->RL_UpdateUserPayoutPreferences($uid, 'none', []);
                $payoutDetails = $RL->RL_GetUserPayoutDetails($uid);
            }

            $priorities = isset($_POST['priority']) && is_array($_POST['priority']) ? $_POST['priority'] : [];
            $existingMethods = [];
            if (!empty($payoutDetails['methods']) && is_array($payoutDetails['methods'])) {
                foreach ($payoutDetails['methods'] as $methodKey => $_payload) {
                    $existingMethods[strtolower((string) $methodKey)] = true;
                }
            }
            foreach ($priorities as $method => $value) {
                $priority = (int) $value;
                if ($priority <= 0) { $priority = 1; }
                $methodKey = strtolower((string) $method);
                 if (!isset($existingMethods[$methodKey])) {
                     continue;
                 }
                $RL->RL_SavePayoutMethodDetails($uid, $methodKey, ['priority' => $priority], false, null, ['preserve_status' => true]);
            }

            $updatedDetails = $RL->RL_GetUserPayoutDetails($uid);
            echo json_encode([
                'status' => 'success',
                'message' => customLang('settings_payout_methods_saved', 'Payout method preferences updated.'),
                'data' => $updatedDetails,
                'payout' => $updatedDetails,
            ]);
        } catch (Throwable $e) {
            if (isset($RL) && method_exists($RL, 'logError')) {
                $RL->logError('settings_update_payout_methods failed: ' . $e->getMessage());
            }
            echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
        }
        exit;
    }

    if ($p === 'settings_update_language') {
        $userHandler->handleSettingsUpdateLanguage();
        return;
    }

    if ($p === 'settings_update_preferences') {
        $userHandler->handleSettingsUpdatePreferences();
        return;
    }


    // =============================
    // Security: Update password
    // =============================
    if ($p === 'settings_update_password') {
        $userHandler->handleSettingsUpdatePassword();
        return;
    }

    // =============================
    // Security: Two-factor helpers
    // =============================
    if ($p === 'settings_security_prepare_2fa') {
        $userHandler->handleSettingsSecurityPrepareTwoFactor();
        return;
    }

    if ($p === 'settings_security_enable_2fa') {
        $userHandler->handleSettingsSecurityEnableTwoFactor();
        return;
    }

    if ($p === 'settings_security_disable_2fa') {
        $userHandler->handleSettingsSecurityDisableTwoFactor();
        return;
    }

    if ($p === 'settings_security_generate_codes') {
        $userHandler->handleSettingsSecurityGenerateCodes();
        return;
    }

    if ($p === 'settings_security_revoke_session') {
        $userHandler->handleSettingsSecurityRevokeSession();
        return;
    }

    if ($p === 'settings_security_revoke_others') {
        $userHandler->handleSettingsSecurityRevokeOthers();
        return;
    }

    // =============================
    // Advertisements: Dashboard APIs
    // =============================
    if ($p === 'ads_dashboard_data') {
        $uid = isset($userID) ? (int) $userID : 0;
        if ($loggedIn !== '1' || $uid <= 0) {
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            exit;
        }
        if (!$adsEnabledGlobal) {
            echo json_encode($adsDisabledResponse);
            exit;
        }

        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($token)) {
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }

        if (!isset($RL) || !method_exists($RL, 'RL_ListUserAdvertisements')) {
            echo json_encode(['status' => 'error', 'message' => customLang('ads_dashboard_fetch_failed')]);
            exit;
        }

        try {
            $ads = $RL->RL_ListUserAdvertisements($uid);
            $financials = method_exists($RL, 'RL_GetAdvertisementFinancials') ? $RL->RL_GetAdvertisementFinancials($uid) : [];
            $metrics = method_exists($RL, 'RL_SummarizeAdMetrics') ? $RL->RL_SummarizeAdMetrics($uid) : ['impressions' => 0, 'clicks' => 0];

            $currencyCode = strtoupper((string) ($financials['currency_code'] ?? ''));
            if ($currencyCode === '') {
                $currencyCode = 'USD';
            }

            $currencySymbol = '';
            if (isset($currencys) && is_array($currencys) && isset($currencys[$currencyCode])) {
                $currencySymbol = (string) $currencys[$currencyCode];
            }
            if ($currencySymbol === '' && $currencyCode === 'USD') {
                $currencySymbol = '$';
            }
            if ($currencySymbol === '') {
                $currencySymbol = $currencyCode;
            }

            $summary = [
                'active'          => (int) ($financials['active'] ?? 0),
                'paused'          => (int) ($financials['paused'] ?? 0),
                'totalBudget'     => round((float) ($financials['total_budget'] ?? 0.0), 2),
                'totalSpent'      => round((float) ($financials['total_spent'] ?? 0.0), 2),
                'totalRemaining'  => round((float) ($financials['total_remaining'] ?? 0.0), 2),
                'impressions'     => (int) ($metrics['impressions'] ?? 0),
                'clicks'          => (int) ($metrics['clicks'] ?? 0),
            ];

            $timeframes = ['daily', 'weekly', 'monthly', 'yearly'];
            $charts = [];
            foreach ($timeframes as $rangeKey) {
                if (method_exists($RL, 'RL_GetAdvertisementTimeSeries')) {
                    $charts[$rangeKey] = $RL->RL_GetAdvertisementTimeSeries($uid, $rangeKey);
                } else {
                    $charts[$rangeKey] = ['labels' => [], 'views' => [], 'clicks' => [], 'spend' => []];
                }
            }

            $adsPayload = [];
            foreach ($ads as $row) {
                $adId = (int) ($row['ad_id'] ?? 0);
                if ($adId <= 0) {
                    continue;
                }

                $adsPayload[] = [
                    'ad_id'       => $adId,
                    'title'       => (string) ($row['title'] ?? ''),
                    'description' => (string) ($row['description'] ?? ''),
                    'status'      => (string) ($row['status'] ?? 'draft'),
                    'media_type'  => (string) ($row['media_type'] ?? ''),
                    'total_budget'=> round((float) ($row['total_budget'] ?? 0.0), 2),
                    'spent'       => round((float) ($row['spent_amount'] ?? 0.0), 2),
                    'remaining'   => round((float) ($row['remaining_amount'] ?? 0.0), 2),
                    'views'       => (int) ($row['views_total'] ?? ($row['views'] ?? 0)),
                    'clicks'      => (int) ($row['clicks_total'] ?? ($row['clicks'] ?? 0)),
                    'ad_link'     => (string) ($row['ad_link'] ?? ''),
                    'updated_at'  => isset($row['updated_at']) ? (int) $row['updated_at'] : null,
                ];
            }

            $currencyFormatPayload = [];
            if (isset($GLOBALS['currency_format_settings']) && is_array($GLOBALS['currency_format_settings'])) {
                $s = $GLOBALS['currency_format_settings'];
                $currencyFormatPayload = [
                    'position'      => $s['position']      ?? 'left',
                    'decimalPlaces' => $s['decimal_places']?? 2,
                    'decimalSep'    => $s['decimal_sep']   ?? '.',
                    'thousandsSep'  => $s['thousands_sep'] ?? ',',
                    'code'          => $currencyCode,
                    'symbol'        => $currencySymbol,
                ];
            } elseif (isset($GLOBALS['currency_format_client']) && is_array($GLOBALS['currency_format_client'])) {
                $currencyFormatPayload = $GLOBALS['currency_format_client'];
                $currencyFormatPayload['code'] = $currencyFormatPayload['code'] ?? $currencyCode;
                $currencyFormatPayload['symbol'] = $currencyFormatPayload['symbol'] ?? $currencySymbol;
            }

            echo json_encode([
                'status' => 'success',
                'data'   => [
                    'summary' => $summary,
                    'charts'  => $charts,
                    'ads'     => $adsPayload,
                    'currency'=> ['code' => $currencyCode, 'symbol' => $currencySymbol],
                    'currency_format' => $currencyFormatPayload,
                ],
            ]);
            exit;
        } catch (Throwable $e) {
            if (isset($RL) && method_exists($RL, 'logError')) {
                $RL->logError('ads_dashboard_data failed: ' . $e->getMessage());
            } else {
                error_log('ads_dashboard_data failed: ' . $e->getMessage());
            }
            echo json_encode(['status' => 'error', 'message' => customLang('ads_dashboard_fetch_failed')]);
            exit;
        }
    }

    if ($p === 'ads_update_status') {
        $uid = isset($userID) ? (int) $userID : 0;
        if ($loggedIn !== '1' || $uid <= 0) {
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            exit;
        }
        if (!$adsEnabledGlobal) {
            echo json_encode($adsDisabledResponse);
            exit;
        }

        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($token)) {
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }

        $adId = isset($_POST['ad_id']) ? (int) $_POST['ad_id'] : 0;
        $newStatus = strtolower(trim((string) ($_POST['new_status'] ?? '')));
        if ($adId <= 0 || !in_array($newStatus, ['active', 'paused'], true)) {
            echo json_encode(['status' => 'error', 'message' => customLang('ads_dashboard_status_update_failed')]);
            exit;
        }

        if (!isset($RL) || !method_exists($RL, 'RL_UpdateAdvertisementStatusForOwner')) {
            echo json_encode(['status' => 'error', 'message' => customLang('ads_dashboard_status_update_failed')]);
            exit;
        }

        try {
            $updated = $RL->RL_UpdateAdvertisementStatusForOwner($uid, $adId, $newStatus);
            if (!$updated) {
                echo json_encode(['status' => 'error', 'message' => customLang('ads_dashboard_status_update_failed')]);
                exit;
            }

            echo json_encode([
                'status'  => 'success',
                'message' => customLang('ads_dashboard_status_updated'),
                'data'    => ['status' => $newStatus, 'ad_id' => $adId],
            ]);
            exit;
        } catch (Throwable $e) {
            if (isset($RL) && method_exists($RL, 'logError')) {
                $RL->logError('ads_update_status failed: ' . $e->getMessage());
            }
            echo json_encode(['status' => 'error', 'message' => customLang('ads_dashboard_status_update_failed')]);
            exit;
        }
    }

    if ($p === 'ads_update_copy') {
        $uid = isset($userID) ? (int) $userID : 0;
        if ($loggedIn !== '1' || $uid <= 0) {
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            exit;
        }
        if (!$adsEnabledGlobal) {
            echo json_encode($adsDisabledResponse);
            exit;
        }

        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($token)) {
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }

        $adId = isset($_POST['ad_id']) ? (int) $_POST['ad_id'] : 0;
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $adLinkRaw = trim((string) ($_POST['ad_link'] ?? ''));

        $adLink = $adLinkRaw;
        if ($adLink !== '') {
            if (!preg_match('#^https?://#i', $adLink)) {
                $adLink = 'https://' . $adLink;
            }
            if (!filter_var($adLink, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $adLink)) {
                $adLink = '';
            }
        }

        if ($adId <= 0 || $title === '') {
            echo json_encode(['status' => 'error', 'message' => customLang('ads_dashboard_copy_update_failed')]);
            exit;
        }

        if (!isset($RL) || !method_exists($RL, 'RL_UpdateAdvertisementCopy')) {
            echo json_encode(['status' => 'error', 'message' => customLang('ads_dashboard_copy_update_failed')]);
            exit;
        }

        if (function_exists('mb_substr')) {
            $title = mb_substr($title, 0, 255);
            $description = mb_substr($description, 0, 1000);
        } else {
            $title = substr($title, 0, 255);
            $description = substr($description, 0, 1000);
        }

        try {
            $updated = $RL->RL_UpdateAdvertisementCopy($uid, $adId, $title, $description, $adLink);
            if (!$updated) {
                echo json_encode(['status' => 'error', 'message' => customLang('ads_dashboard_copy_update_failed')]);
                exit;
            }

            echo json_encode([
                'status'  => 'success',
                'message' => customLang('ads_dashboard_copy_updated'),
                'data'    => [
                    'ad_id'       => $adId,
                    'title'       => $title,
                    'description' => $description,
                    'ad_link'     => $adLink,
                ],
            ]);
            exit;
        } catch (Throwable $e) {
            if (isset($RL) && method_exists($RL, 'logError')) {
                $RL->logError('ads_update_copy failed: ' . $e->getMessage());
            }
            echo json_encode(['status' => 'error', 'message' => customLang('ads_dashboard_copy_update_failed')]);
            exit;
        }
    }

    // =============================
    // Advertisements: Image Upload
    // =============================
    if ($p === 'ads_upload_images') {
        $uid = isset($userID) ? (int) $userID : 0;
        if (!isset($loggedIn) || $loggedIn !== '1' || $uid <= 0) {
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            exit;
        }
        if (!$adsEnabledGlobal) {
            echo json_encode($adsDisabledResponse);
            exit;
        }
        if (!$imagesEnabledGlobal) {
            echo json_encode(['status' => 'error', 'message' => customLang('content_disabled_images', 'Image sharing is currently disabled.')]);
            exit;
        }

        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($token)) {
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }

        // Hardened ads image upload: validate + re-encode to JPEG/PNG
        if (!isset($_FILES['images'])) {
            echo json_encode(['status' => 'error', 'message' => customLang('no_image_uploaded')]);
            exit;
        }

        $todayDir = date('Y-m-d');
        $absDir   = __DIR__ . '/../uploads/advertisement_ifiles/' . $todayDir . '/';
        if (!is_dir($absDir)) { mkdir($absDir, 0755, true); }

        $relativePrefix = 'uploads/advertisement_ifiles/' . $todayDir . '/';
        $saved = [];
        $skipReasons = [];

        // Allowed only JPEG/PNG for ads
        $allowedMimesAds = ['image/jpeg', 'image/png'];
        // Keep a reasonable cap for ads even if global is huge
        $maxMbAds = isset($availableUploadFileSize) ? min((float) $availableUploadFileSize, 10.0) : 5.0;
        if ($maxMbAds <= 0) { $maxMbAds = 5.0; }
        $maxBytesAds = (int) round($maxMbAds * 1048576);
        $finfoAds = function_exists('finfo_open') ? @finfo_open(FILEINFO_MIME_TYPE) : null;

        foreach ((array)($_FILES['images']['tmp_name'] ?? []) as $key => $tmpName) {
            $err = (int) ($_FILES['images']['error'][$key] ?? UPLOAD_ERR_OK);
            $size = (int) ($_FILES['images']['size'][$key] ?? 0);
            if ($err !== UPLOAD_ERR_OK || empty($tmpName) || !is_uploaded_file($tmpName)) {
                $skipReasons[] = 'slot' . $key . ': upload_error=' . $err;
                continue; // skip invalid entry silently
            }
            if ($size <= 0 || $size > $maxBytesAds) {
                $skipReasons[] = 'slot' . $key . ': size_bytes=' . $size;
                continue;
            }
            $mime = '';
            if ($finfoAds) {
                $mime = (string) @finfo_file($finfoAds, $tmpName);
            }
            if ($mime === '' && function_exists('mime_content_type')) {
                $mime = (string) @mime_content_type($tmpName);
            }
            if ($mime === '' && isset($_FILES['images']['type'][$key])) {
                $mime = (string) $_FILES['images']['type'][$key];
            }
            $mime = strtolower(trim($mime));
            if ($mime !== '' && strpos($mime, ';') !== false) {
                $mime = substr($mime, 0, strpos($mime, ';'));
            }
            if ($mime === 'image/jpg') {
                $mime = 'image/jpeg';
            }
            if ($mime === '' || !in_array($mime, $allowedMimesAds, true)) {
                $skipReasons[] = 'slot' . $key . ': mime=' . $mime;
                continue;
            }
            $info = @getimagesize($tmpName);
            if ($info === false) { $skipReasons[] = 'slot' . $key . ': getimagesize_failed'; continue; }
            $imgType = (int) ($info[2] ?? 0);
            if (!in_array($imgType, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) { $skipReasons[] = 'slot' . $key . ': img_type=' . $imgType; continue; }
            $w = (int) ($info[0] ?? 0); $h = (int) ($info[1] ?? 0);
            if ($w <= 0 || $h <= 0) { $skipReasons[] = 'slot' . $key . ': dimensions=' . $w . 'x' . $h; continue; }

            // Load and re-encode
            $gd = null; $outExt = 'jpg';
            if ($imgType === IMAGETYPE_JPEG) { $gd = @imagecreatefromjpeg($tmpName); $outExt = 'jpg'; }
            elseif ($imgType === IMAGETYPE_PNG) { $gd = @imagecreatefrompng($tmpName); $outExt = 'png'; }
            if (!$gd) { $skipReasons[] = 'slot' . $key . ': gd_init_failed'; continue; }

            try { $rand = bin2hex(random_bytes(8)); } catch (Throwable $__) { $rand = str_replace('.', '', uniqid('ad_', true)); }
            $fileName = 'adimg_' . $rand . '.' . $outExt;
            $dest     = $absDir . $fileName;

            $ok = false;
            if ($outExt === 'jpg') {
                $ok = @imagejpeg($gd, $dest, 90);
            } else {
                @imagealphablending($gd, false);
                @imagesavealpha($gd, true);
                $ok = @imagepng($gd, $dest, 6);
            }
            @imagedestroy($gd);
            if (!$ok || !is_file($dest)) { $skipReasons[] = 'slot' . $key . ': reencode_failed'; continue; }

            // Optional filter only for PNG destination
            $filterKey = 'filter_' . $key; $filterVal = $_POST[$filterKey] ?? 'none';
            if ($outExt === 'png' && $filterVal !== 'none' && function_exists('applyFilterToImage')) {
                try { applyFilterToImage($dest, $filterVal); } catch (Throwable $__) { /* ignore */ }
            }

            $saved[] = $relativePrefix . $fileName;
        }

        if (empty($saved)) {
            if (!empty($skipReasons)) {
                error_log('ads_upload_images skipped: ' . implode('; ', $skipReasons));
            }
            echo json_encode(['status' => 'error', 'message' => customLang('upload_failed')]);
            exit;
        }

        echo json_encode([
            'status'      => 'success',
            'media_paths' => $saved,
            'ad_media'    => implode(',', $saved)
        ]);
        exit;
    }

    // ==================================
    // Advertisements: Finalize (Image)
    // ==================================
    if ($p === 'ads_finalize_image') {
        $uid = isset($userID) ? (int) $userID : 0;
        if (!isset($loggedIn) || $loggedIn !== '1' || $uid <= 0) {
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            exit;
        }
        if (!$adsEnabledGlobal) {
            echo json_encode($adsDisabledResponse);
            exit;
        }
        if (!$imagesEnabledGlobal) {
            echo json_encode(['status' => 'error', 'message' => customLang('content_disabled_images', 'Image sharing is currently disabled.')]);
            exit;
        }
        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($token)) {
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }
        // Required fields
        $title       = trim($_POST['ad_title'] ?? '');
        $description = trim($_POST['ad_description'] ?? '');
        $mediaType   = 'image'; // enforce for this endpoint
        $adMedia     = trim($_POST['ad_media'] ?? ''); // comma-separated paths from previous step
        $adLinkRaw   = trim($_POST['ad_link'] ?? '');

        // Optional / numeric
        $targetImpressions  = (int) ($_POST['target_impressions'] ?? 0);
        $pricePerImpression = (float)($_POST['price_per_impression'] ?? 0);
        $durationDays       = (int) ($_POST['duration_days'] ?? 1);
        $totalBudget        = isset($_POST['total_budget']) ? (float)$_POST['total_budget'] : 0.0;
        $status             = $_POST['ad_status'] ?? 'draft';

        $adLink = $adLinkRaw;
        if ($adLink !== '') {
            if (!preg_match('#^https?://#i', $adLink)) {
                $adLink = 'https://' . $adLink;
            }
            if (!filter_var($adLink, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $adLink)) {
                $adLink = '';
            }
        }

        // Basic validations
        $minBudget = isset($minimumAdsAmount) ? (float)$minimumAdsAmount : 10.0;
        if ($title === '' || $description === '' || $adMedia === '' || $adLink === '') {
            echo json_encode(['status' => 'error', 'message' => customLang('ads_fields_required_image')]);
            exit;
        }
        if ($targetImpressions <= 0 || $pricePerImpression <= 0 || $durationDays < 1) {
            echo json_encode(['status' => 'error', 'message' => customLang('ads_values_must_be_greater_than_zero')]);
            exit;
        }

        // Auto-calc budget if not provided
        if ($totalBudget <= 0 && $targetImpressions > 0 && $pricePerImpression >= 0) {
            $totalBudget = round($targetImpressions * $pricePerImpression, 2);
        }

        if ($totalBudget < $minBudget) {
            echo json_encode(['status' => 'error', 'message' => strtr(customLang('ui_min_total_budget'), ['{amount}' => '$' . number_format($minBudget, 2)])]);
            exit;
        }

        $adId = 0;
        if (isset($RL) && method_exists($RL, 'RL_CreateAdvertisement')) {
            $adId = (int) $RL->RL_CreateAdvertisement(
                (int)$userID,
                $title,
                $description,
                $mediaType,
                $adMedia,
                $targetImpressions,
                $pricePerImpression,
                $durationDays,
                $totalBudget,
                // Always create as draft; require payment to activate
                'draft',
                time(),
                $adLink
            );
        }

        if ($adId > 0) {
            echo json_encode(['status' => 'success', 'message' => customLang('ad_saved_draft_complete_payment'), 'ad_id' => $adId, 'needs_payment' => true]);
        } else {
            echo json_encode(['status' => 'error', 'message' => customLang('failed_to_save_advertisement')]);
        }
        exit;
    }

    // ==================================
    // Advertisements: Upload Temp Video
    // ==================================
    if ($p === 'ads_upload_temp_video') {
        $uid = isset($userID) ? (int) $userID : 0;
        if (!isset($loggedIn) || $loggedIn !== '1' || $uid <= 0) {
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            exit;
        }
        if (!$adsEnabledGlobal) {
            echo json_encode($adsDisabledResponse);
            exit;
        }
        if (!$videosEnabledGlobal) {
            echo json_encode(['status' => 'error', 'message' => customLang('content_disabled_videos', 'Video sharing is currently disabled.')]);
            exit;
        }

        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($token)) {
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }

        // Accept single file via key 'video'
        if (!isset($_FILES['video']) || !is_uploaded_file($_FILES['video']['tmp_name'])) {
            echo json_encode(['status' => 'error', 'message' => customLang('no_video_uploaded')]);
            exit;
        }

        $fileArr = $_FILES['video'];
        $name = stripslashes($fileArr['name']);
        $size = (int) $fileArr['size'];
        // Server-side MIME detection (strict whitelist)
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowedMimes = ['video/mp4', 'video/webm', 'video/quicktime'];
        $detectedMime = '';
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $detectedMime = (string) @finfo_file($fi, $fileArr['tmp_name']);
                @finfo_close($fi);
            }
        }
        if ($detectedMime === '' && function_exists('mime_content_type')) {
            $detectedMime = (string) @mime_content_type($fileArr['tmp_name']);
        }
        if ($detectedMime === '' && isset($fileArr['type'])) {
            $detectedMime = (string) $fileArr['type'];
        }
        $normalizedMime = strtolower(trim($detectedMime));
        if ($normalizedMime !== '' && strpos($normalizedMime, ';') !== false) {
            $normalizedMime = substr($normalizedMime, 0, strpos($normalizedMime, ';'));
        }
        if ($normalizedMime === 'video/x-m4v' || $normalizedMime === 'video/m4v') {
            $normalizedMime = 'video/mp4';
        }
        // Use finfo(FILEINFO_MIME_TYPE) only for MIME detection

        $validFormats = explode(',', $availableFileExtensions);
        // Reject when MIME cannot be detected or not in whitelist
        if ($normalizedMime === '' || !in_array($normalizedMime, $allowedMimes, true)) {
            echo json_encode(['status' => 'error', 'message' => customLang('only_video_files_allowed')]);
            exit;
        }
        if (!in_array($ext, $validFormats, true)) {
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_file_format')]);
            exit;
        }
        if (convert_to_mb($size) > $availableUploadFileSize) {
            echo json_encode(['status' => 'error', 'message' => customLang('file_too_large')]);
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

        $ffprobeBin = dz_resolve_binary(isset($ffprobePath) ? (string) $ffprobePath : null, ['ffprobe']);
        if ($ffprobeBin === null) {
            echo json_encode([
                'status' => 'error',
                'message' => 'ffprobe_unavailable',
                'message_text' => 'FFprobe binary is not available on the server. Configure the correct path in admin settings.'
            ]);
            exit;
        }

        $microtime  = microtime();
        $removeMicrotime = preg_replace('/(0)\.(\d+) (\d+)/', '$3$1$2', $microtime);
        $baseName   = 'reel_' . $removeMicrotime . '_' . $userID;
        $todayDir   = date('Y-m-d');
        $adsDir     = __DIR__ . '/../uploads/advertisement_ifiles/' . $todayDir;
        if (!is_dir($adsDir)) { mkdir($adsDir, 0755, true); }

        $uploadedTmp = $adsDir . '/' . $baseName . '.' . $ext;
        if (!move_uploaded_file($fileArr['tmp_name'], $uploadedTmp)) {
            echo json_encode(['status' => 'error', 'message' => customLang('upload_failed')]);
            exit;
        }

        $finalPath = $adsDir . '/' . $baseName . '.mp4';
        if ($ext !== 'mp4') {
            // Re-encode to MP4
            $cmd = escapeshellarg($ffmpegBin) . ' -y -i ' . escapeshellarg($uploadedTmp) . ' -c:v libx264 -preset veryfast -crf 23 -c:a aac -movflags +faststart ' . escapeshellarg($finalPath) . ' 2>&1';
            shell_exec($cmd);
            @unlink($uploadedTmp);
        } else {
            rename($uploadedTmp, $finalPath);
        }

        if (!is_file($finalPath)) {
            echo json_encode(['status' => 'error', 'message' => customLang('conversion_failed')]);
            exit;
        }

        // (Optional) generate a poster next to video for preview
        $posterAbs = '';
        if (is_file($finalPath)) {
            require_once __DIR__ . '/../includes/helpers/createVideoThumbnailInSameDir.php';
            $posterPath = createVideoThumbnailInSameDir($ffmpegBin, $finalPath);
            if ($posterPath) { $posterAbs = $posterPath; }
        }

        // Build clean web-relative paths from absolute filesystem paths
        $projectRoot = realpath(__DIR__ . '/..'); // points to project root
        $absFinal = realpath($finalPath);
        $relVideo = $absFinal && $projectRoot
            ? ltrim(str_replace([$projectRoot, '\\'], ['', '/'], $absFinal), '/')
            : ltrim(str_replace('../', '', $finalPath), '/');

        // Helper to normalize media URL using storage adapter when available.
        $buildMediaUrl = static function (string $relative) use ($base_url) : string {
            $normalized = ltrim($relative, '/');
            if ($normalized === '') { return ''; }
            if (function_exists('storage_resolve_media_url')) {
                return storage_resolve_media_url($normalized, $base_url ?? '');
            }
            if (isset($base_url) && $base_url !== '') {
                return rtrim((string)$base_url, '/') . '/' . $normalized;
            }
            return $normalized;
        };

        $relPoster = '';
        if ($posterAbs) {
            $absPoster = realpath($posterAbs);
            if ($absPoster && $projectRoot) {
                $relPoster = ltrim(str_replace([$projectRoot, '\\'], ['', '/'], $absPoster), '/');
            } else {
                $relPoster = ltrim(str_replace('../', '', (string)$posterAbs), '/');
            }
        } else {
            // Fallback: derive poster path by swapping extension if FFmpeg thumbnail failed
            $derivedPoster = preg_replace('/\.mp4$/i', '.png', $relVideo);
            if ($derivedPoster && $projectRoot && is_file($projectRoot . '/' . $derivedPoster)) {
                $relPoster = ltrim($derivedPoster, '/');
            }
        }

        $videoUrl = $buildMediaUrl($relVideo);
        $posterUrl = $relPoster !== '' ? $buildMediaUrl($relPoster) : '';

        echo json_encode(['status' => 'success', 'video_url' => $videoUrl, 'poster' => $posterUrl]);
        exit;
    }

    // ==================================
    // Advertisements: Finalize (Video)
    // ==================================
    if ($p === 'ads_finalize_video') {
        $uid = isset($userID) ? (int) $userID : 0;
        if (!isset($loggedIn) || $loggedIn !== '1' || $uid <= 0) {
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            exit;
        }
        if (!$adsEnabledGlobal) {
            echo json_encode($adsDisabledResponse);
            exit;
        }
        if (!$videosEnabledGlobal) {
            echo json_encode(['status' => 'error', 'message' => customLang('content_disabled_videos', 'Video sharing is currently disabled.')]);
            exit;
        }
        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($token)) {
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }
        $title       = trim($_POST['ad_title'] ?? '');
        $description = trim($_POST['ad_description'] ?? '');
        $mediaType   = 'video';
        $videoUrl    = trim($_POST['video_url'] ?? ''); // absolute or relative
        $adLinkRaw   = trim($_POST['ad_link'] ?? '');

        $targetImpressions  = (int) ($_POST['target_impressions'] ?? 0);
        $pricePerImpression = (float)($_POST['price_per_impression'] ?? 0);
        $durationDays       = (int) ($_POST['duration_days'] ?? 1);
        $totalBudget        = isset($_POST['total_budget']) ? (float)$_POST['total_budget'] : 0.0;
        $status             = $_POST['ad_status'] ?? 'draft';

        $adLink = $adLinkRaw;
        if ($adLink !== '') {
            if (!preg_match('#^https?://#i', $adLink)) {
                $adLink = 'https://' . $adLink;
            }
            if (!filter_var($adLink, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $adLink)) {
                $adLink = '';
            }
        }

        // Basic validations
        $minBudget = isset($minimumAdsAmount) ? (float)$minimumAdsAmount : 10.0;
        if ($title === '' || $description === '' || $videoUrl === '' || $adLink === '') {
            echo json_encode(['status' => 'error', 'message' => customLang('ads_fields_required_video')]);
            exit;
        }
        if ($targetImpressions <= 0 || $pricePerImpression <= 0 || $durationDays < 1) {
            echo json_encode(['status' => 'error', 'message' => customLang('ads_values_must_be_greater_than_zero')]);
            exit;
        }

        // Normalize to relative path stored in DB (same style as examples)
        $adMedia = $videoUrl;
        if (isset($base_url) && $base_url) {
            $adMedia = ltrim(str_replace(rtrim($base_url, '/'), '', $videoUrl), '/');
        }
        $adMedia = ltrim(str_replace(['..\\', '../'], '', $adMedia), '/');

        if ($totalBudget <= 0 && $targetImpressions > 0 && $pricePerImpression >= 0) {
            $totalBudget = round($targetImpressions * $pricePerImpression, 2);
        }

        if ($totalBudget < $minBudget) {
            echo json_encode(['status' => 'error', 'message' => strtr(customLang('ui_min_total_budget'), ['{amount}' => '$' . number_format($minBudget, 2)])]);
            exit;
        }

        $adId = 0;
        if (isset($RL) && method_exists($RL, 'RL_CreateAdvertisement')) {
            $adId = (int) $RL->RL_CreateAdvertisement(
                (int)$userID,
                $title,
                $description,
                $mediaType,
                $adMedia,
                $targetImpressions,
                $pricePerImpression,
                $durationDays,
                $totalBudget,
                'draft',
                time(),
                $adLink
            );
        }

        if ($adId > 0) {
            echo json_encode(['status' => 'success', 'message' => customLang('ad_saved_draft_complete_payment'), 'ad_id' => $adId, 'needs_payment' => true]);
        } else {
            echo json_encode(['status' => 'error', 'message' => customLang('failed_to_save_advertisement')]);
        }
        exit;
    }

    // ==================================
    // Advertisements: Create Payment
    // ==================================
    if ($p === 'ads_create_payment') {
        $uid = isset($userID) ? (int) $userID : 0;
        if (!isset($loggedIn) || $loggedIn !== '1' || $uid <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            exit;
        }
        if (!$adsEnabledGlobal) {
            http_response_code(403);
            echo json_encode($adsDisabledResponse);
            exit;
        }
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!function_exists('checkCsrfToken') || !checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }
        $provider = strtolower(trim($_POST['provider'] ?? ''));
        $adId     = (int) ($_POST['ad_id'] ?? 0);
        $mode     = strtolower(trim($_POST['mode'] ?? 'one_time')); // one_time | subscription

        if (!$adId || $provider === '') {
            echo json_encode(['status' => 'error', 'message' => customLang('missing_provider_or_ad_id')]);
            exit;
        }
        $ad = method_exists($RL, 'RL_GetAdvertisementById') ? $RL->RL_GetAdvertisementById($adId) : null;
        if (!$ad) { echo json_encode(['status' => 'error', 'message' => customLang('advertisement_not_found')]); exit; }
        if ((int)$ad['user_id'] !== (int)$userID) { echo json_encode(['status' => 'error', 'message' => customLang('unauthorized')]); exit; }
        $adMediaType = strtolower((string)($ad['media_type'] ?? ''));
        if ($adMediaType === 'video' && !$videosEnabledGlobal) {
            echo json_encode(['status' => 'error', 'message' => customLang('content_disabled_videos', 'Video sharing is currently disabled.')]);
            exit;
        }
        if ($adMediaType === 'image' && !$imagesEnabledGlobal) {
            echo json_encode(['status' => 'error', 'message' => customLang('content_disabled_images', 'Image sharing is currently disabled.')]);
            exit;
        }

        $cfg = PaymentFactory::config();
        if (defined('APP_DEBUG') && APP_DEBUG === true && $provider === 'stripe') {
            $key = (string)($cfg['stripe']['secret_key'] ?? '');
            $len = strlen($key);
            $prefix = $len >= 7 ? substr($key, 0, 7) : $key;
            @file_put_contents(__DIR__ . '/../payments_debug.log', date('c') . " STRIPE_SECRET_LEN=$len PREFIX=$prefix\n", FILE_APPEND);
        }
        $currency = $cfg['currency'] ?? 'USD';
        $amount = (float)($ad['total_budget'] ?? 0.0);
        $minBudget = isset($minimumAdsAmount) ? (float)$minimumAdsAmount : 10.0;
        if ($amount <= 0) { echo json_encode(['status' => 'error', 'message' => customLang('invalid_amount')]); exit; }
        if ($amount < $minBudget) { echo json_encode(['status' => 'error', 'message' => strtr(customLang('ui_min_total_budget'), ['{amount}' => '$' . number_format($minBudget, 2)])]); exit; }

        $buyerEmail = '';
        $buyerName = '';
        $buyerUsername = '';
        if (isset($userData) && is_array($userData)) {
            $buyerEmail = trim((string)($userData['contact_email'] ?? $userData['user_email'] ?? ''));
            $buyerName = trim((string)($userData['user_fullname'] ?? $userData['username'] ?? ''));
            $buyerUsername = trim((string)($userData['username'] ?? ''));
        }

        try {
            // Disallow crypto providers for subscription mode
            if ($mode === 'subscription' && in_array($provider, ['nowpayments','coinbase'], true)) {
                echo json_encode(['status' => 'error', 'message' => customLang('provider_no_subscriptions')]);
                exit;
            }
            // Wallet provider: deduct in-app credits without external redirect
            if ($provider === 'wallet') {
                if (!isset($RL) || !method_exists($RL, 'RL_DebitWalletForAd')) {
                    echo json_encode(['status' => 'error', 'message' => customLang('wallet_payment_not_supported')]);
                    exit;
                }
                $res = $RL->RL_DebitWalletForAd((int)$userID, (int)$adId, (float)$amount, (string)$currency);
                if (!($res['ok'] ?? false)) {
                    $msg = $res['error'] ?? customLang('wallet_payment_failed');
                    if (isset($res['balance'])) { $msg .= ' Balance: ' . number_format((float)$res['balance'], 2); }
                    echo json_encode(['status' => 'error', 'message' => $msg]);
                    exit;
                }
                echo json_encode([
                    'status' => 'success',
                    'provider' => 'wallet',
                    'reference' => (string)($res['reference'] ?? ''),
                    'new_balance' => isset($res['balance']) ? (float)$res['balance'] : null,
                    'message' => customLang('wallet_payment_ad_activated')
                ]);
                exit;
            }

            // External providers (redirect)
            $gw = PaymentFactory::make($provider);
            $metaBase = [
                'type'   => 'advertisement',
                'ad_id'  => $adId,
                'title'  => $ad['title'] ?? 'Advertisement',
                'buyer_id' => (int)$userID,
            ];
            if ($buyerEmail !== '') { $metaBase['buyer_email'] = $buyerEmail; }
            if ($buyerName !== '') { $metaBase['buyer_name'] = $buyerName; }
            if ($buyerUsername !== '') { $metaBase['buyer_username'] = $buyerUsername; }

            if ($mode === 'subscription') {
                $resp = $gw->createSubscription(
                    'Ad Subscription - ' . ($ad['title'] ?? ''),
                    $amount,
                    $currency,
                    'month',
                    1,
                    $metaBase
                );
            } else {
                $resp = $gw->createOneTimePayment(
                    $amount,
                    $currency,
                    $metaBase
                );
            }

            $checkout = (string)($resp['checkout_url'] ?? '');
            if ($checkout === '') {
                $msg = customLang('provider_no_checkout_url');
                if (!empty($resp['error'])) { $msg = customLang('payment_error_prefix') . ' ' . $resp['error']; }
                // Debug log (dev only)
                if (defined('APP_DEBUG') && APP_DEBUG === true) {
                    $log = date('c') . " PROVIDER=$provider AD=$adId MODE=$mode RESPONSE=" . json_encode($resp);
                    @file_put_contents(__DIR__ . '/../payments_debug.log', $log . "\n", FILE_APPEND);
                }
                echo json_encode(['status' => 'error', 'message' => $msg]);
                exit;
            }

            // Record payment as pending for cross-reference (if table available)
            $reference = (string)($resp['reference'] ?? '');
            if ($reference !== '' && isset($RL) && method_exists($RL, 'RL_RecordAdPayment')) {
                $RL->RL_RecordAdPayment(
                    $adId,
                    $provider,
                    $reference,
                    $amount,
                    $currency,
                    'pending',
                    $mode === 'subscription' ? 'subscription_created' : 'checkout_created',
                    (array)$resp,
                    'advertisement',
                    $adId
                );
                // Also store expected fee/tax/net based on admin settings (estimates)
                if (method_exists($RL, 'RL_UpdateAdPaymentAmountsByReference')) {
                    $feePct = isset($paymentFeePercent) ? (float)$paymentFeePercent : 0.0;
                    $feeFix = isset($paymentFeeFixed) ? (float)$paymentFeeFixed : 0.0;
                    $taxPct = isset($paymentTaxPercent) ? (float)$paymentTaxPercent : 0.0;
                    $estTax = $taxPct > 0 ? round($amount * ($taxPct/100), 2) : null;
                    $estFee = ($feePct > 0 || $feeFix > 0) ? round(($amount * ($feePct/100)) + $feeFix, 2) : null;
                    $estNet = ($estTax !== null || $estFee !== null) ? round($amount - (float)($estTax ?? 0) - (float)($estFee ?? 0), 2) : null;
                    $RL->RL_UpdateAdPaymentAmountsByReference($provider, $reference, $amount, $currency, $estFee, $currency, $estTax, $estNet);
                }
            }

            echo json_encode(['status' => 'success', 'checkout_url' => $checkout, 'reference' => $reference, 'provider' => $provider]);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => customLang('payment_init_failed') . ' ' . $e->getMessage()]);
        }
        exit;
    }

    // ==================================
    // Advertisements: Payment Webhook (all providers)
    // ==================================
    if ($p === 'ads_payment_webhook') {
        // Deprecated: this legacy path is superseded by request/webhooks.php
        // Guard against accidental use to avoid duplicate processing.
        http_response_code(410);
        echo json_encode(['status' => 'error', 'message' => 'Deprecated endpoint. Use request/webhooks.php?provider=...']);
        exit;
    }

    $validPages = ['create_new', 'new_messages', 'new_notifications', 'menu'];
    if (in_array($p, $validPages)) {
        $filePath = __DIR__ . '/../themes/' . $currentTheme . '/header/' . $p . '.php';

        if (file_exists($filePath)) {
            ob_start();
            include $filePath;
            $htmlContent = ob_get_clean();

            echo json_encode([
                'status' => 'success',
                'html' => $htmlContent
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => customLang('requested_content_not_found')
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if ($p === 'popUp') {
        $popup = $_POST['pop_type'] ?? '';
        if (is_string($popup)) {
            $popup = trim($popup);
        } else {
            $popup = '';
        }

        if ($popup !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $popup)) {
            $popupsDir = realpath(__DIR__ . '/../themes/' . $currentTheme . '/popUps');
            if ($popupsDir !== false && is_dir($popupsDir)) {
                $candidate = $popupsDir . DIRECTORY_SEPARATOR . $popup . '.php';
                $resolved  = realpath($candidate);

                if ($resolved !== false && str_starts_with($resolved, $popupsDir . DIRECTORY_SEPARATOR) && is_file($resolved)) {
                    while (ob_get_level() > 0) { ob_end_clean(); }

                    ob_start();
                    include $resolved; // This file must output HTML only
                    $htmlContent = trim(ob_get_clean());

                    echo json_encode([
                        'status' => 'success',
                        'html'   => $htmlContent,
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
        }

        http_response_code(404);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Requested content not found.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Lightweight header peek for badges/sounds
    if ($p === 'header_peek') {
        try {
            // Resolve current user id robustly (session fallback)
            $uid = 0;
            if (isset($userID) && (int)$userID > 0) {
                $uid = (int)$userID;
            } elseif (!empty($userData['user_id'])) {
                $uid = (int)$userData['user_id'];
            } elseif (!empty($_SESSION['iuid'])) {
                $uid = (int)$_SESSION['iuid'];
            } elseif (!empty($_SESSION['user_id'])) {
                $uid = (int)$_SESSION['user_id'];
            }
            if ($uid <= 0) {
                echo json_encode([
                    'status'          => 'ok',
                    'last_msg_time'   => 0,
                    'last_notif_ts'   => 0,
                    'last_notif_type' => '',
                    'notif_unread'    => 0
                ]);
                exit;
            }

            // Ensure we have a PDO handle
            if (!isset($RL) || !is_object($RL) || !method_exists($RL, 'getDb')) {
                echo json_encode(['status'=>'error','message'=>'DB']);
                exit;
            }
            $pdo = $RL->getDb();
            // Optional: mark all notifications as read when dropdown is explicitly requested
            $markAsRead = isset($_POST['mark_seen']) && (int)$_POST['mark_seen'] === 1;
            if ($markAsRead && $pdo instanceof \PDO) {
                $markStmt = $pdo->prepare('UPDATE i_notifications
                                               SET is_read = 1, read_at = :now
                                             WHERE recipient_id = :uid
                                               AND is_read = 0
                                               AND type <> "message_reaction"');
                $markStmt->bindValue(':now', time(), \PDO::PARAM_INT);
                $markStmt->bindValue(':uid', $uid, \PDO::PARAM_INT);
                $markStmt->execute();
            }

            // NOTE: Do NOT short-circuit when notify_push is disabled; we still
            // need badge counts and dropdown updates. Push/OneSignal can read
            // this flag separately.

            // Latest INCOMING message for this user (sender -> me)
            $lastMsgTime = 0; $lastMsgFrom = 0;
            if (method_exists($RL, 'RL_GetLatestIncomingMessageMeta')) {
                $meta = $RL->RL_GetLatestIncomingMessageMeta($uid);
                $lastMsgTime = (int)($meta['message_time'] ?? 0);
                $lastMsgFrom = (int)($meta['from_id'] ?? 0);
            }
            if ($lastMsgTime <= 0 && method_exists($RL, 'RL_GetNewMessages')) {
                // Fallback to any side via conversation list
                $conv = $RL->RL_GetNewMessages($uid, 1, 0);
                if (!empty($conv)) { $lastMsgTime = (int)($conv[0]['last_time'] ?? 0); }
            }
            if ($pdo instanceof \PDO) {
                try {
                    $reactionStmt = $pdo->prepare('SELECT n.actor_id, n.created_at
                                                     FROM i_notifications n
                                                    WHERE n.recipient_id = :uid
                                                      AND n.type = "message_reaction"
                                                    ORDER BY n.created_at DESC, n.id DESC
                                                    LIMIT 1');
                    $reactionStmt->bindValue(':uid', $uid, \PDO::PARAM_INT);
                    $reactionStmt->execute();
                    $reactionMeta = $reactionStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
                    $reactionTs = (int)($reactionMeta['created_at'] ?? 0);
                    if ($reactionTs > $lastMsgTime) {
                        $lastMsgTime = $reactionTs;
                        $lastMsgFrom = (int)($reactionMeta['actor_id'] ?? 0);
                    }
                } catch (\Throwable $__) {
                    // ignore and keep message timestamp
                }
            }

            // Latest notification for this user (force via direct SQL to avoid zero payloads)
            $lastNotifTs = 0; $lastNotifType = ''; $lastUnreadCount = 0;
            if ($pdo instanceof \PDO) {
                try {
                    $sqlNotif = "SELECT
                                    MAX(created_at) AS last_ts,
                                    MAX(CASE WHEN is_read = 0 THEN created_at ELSE 0 END) AS last_unread_ts,
                                    SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_cnt,
                                    (SELECT type FROM i_notifications WHERE recipient_id = :uid2 AND type <> 'message_reaction' ORDER BY created_at DESC, id DESC LIMIT 1) AS last_type
                                 FROM i_notifications
                                 WHERE recipient_id = :uid1
                                   AND type <> 'message_reaction'";
                    $stNotif = $pdo->prepare($sqlNotif);
                    $stNotif->bindValue(':uid1', $uid, \PDO::PARAM_INT);
                    $stNotif->bindValue(':uid2', $uid, \PDO::PARAM_INT);
                    $stNotif->execute();
                    $rowNotif = $stNotif->fetch(\PDO::FETCH_ASSOC) ?: [];
                    $lastUnreadTs = (int) ($rowNotif['last_unread_ts'] ?? 0);
                    $lastTsFallback = (int) ($rowNotif['last_ts'] ?? 0);
                    $lastNotifTs = $lastUnreadTs > 0 ? $lastUnreadTs : $lastTsFallback;
                    $lastUnreadCount = (int) ($rowNotif['unread_cnt'] ?? 0);
                    $lastNotifType = (string) ($rowNotif['last_type'] ?? '');
                } catch (\Throwable $__) {
                    // ignore and keep zeros
                }
            }

            echo json_encode([
                'status'          => 'ok',
                'last_msg_time'   => $lastMsgTime,
                'last_msg_from'   => $lastMsgFrom,
                'last_notif_ts'   => $lastNotifTs,
                'last_notif_type' => $lastNotifType,
                'notif_unread'    => $lastUnreadCount
            ]);
        } catch (Throwable $e) {
            // Return ok with zeros to avoid UI breaking when DB hiccups
            echo json_encode([
                'status'          => 'ok',
                'last_msg_time'   => 0,
                'last_notif_ts'   => 0,
                'last_notif_type' => '',
                'notif_unread'    => 0
            ]);
        }
        exit;
    }

    // ==================================
    // Live: Create live stream (title + audience)
    // ==================================
    if ($p === 'create_live') {
        try {
            if (!isset($userID) || (int)$userID <= 0) {
                echo json_encode(['status'=>'error','message'=>'Login required.']);
                exit;
            }
            if (!$liveStreamingEnabledGlobal) {
                echo json_encode(['status'=>'error','message'=>'live_stream_disabled']);
                exit;
            }
            $titleRaw = (string)($_POST['title'] ?? '');
            $title    = trim(strip_tags($titleRaw));
            $aud      = strtolower(trim((string)($_POST['audience'] ?? 'everyone')));
            $allowed  = ['everyone','followers','following','subscribers','only_me'];
            if ($title === '' || mb_strlen($title) < 3) {
                echo json_encode(['status'=>'error','message'=>'Please enter a title (min 3 chars).']);
                exit;
            }
            if (!in_array($aud, $allowed, true)) {
                echo json_encode(['status'=>'error','message'=>'Invalid audience.']);
                exit;
            }
            if (!isset($RL) || !method_exists($RL, 'RL_CreateLiveStream')) {
                echo json_encode(['status'=>'error','message'=>'Live not supported.']);
                exit;
            }
            $liveId = $RL->RL_CreateLiveStream((int)$userID, $title, $aud, 'live');
            if (!$liveId) {
                echo json_encode(['status'=>'error','message'=>'Could not create live.']);
                exit;
            }
            $url = rtrim((string)$base_url, '/') . '/live/' . $liveId;
            echo json_encode(['status'=>'success','live_id'=>$liveId,'url'=>$url]);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        }
        exit;
    }

    // ==================================
    // Live: End live stream (owner only)
    // ==================================
    if ($p === 'end_live') {
        try {
            if (!isset($userID) || (int)$userID <= 0) {
                echo json_encode(['status'=>'error','message'=>'Login required.']);
                exit;
            }
            $liveId = (int)($_POST['live_id'] ?? 0);
            if ($liveId <= 0) { echo json_encode(['status'=>'error','message'=>customLang('invalid_live_id')]); exit; }

            if (!isset($RL) || !method_exists($RL,'RL_GetLiveById')) { echo json_encode(['status'=>'error','message'=>'Live not supported.']); exit; }
            $row = $RL->RL_GetLiveById($liveId);
            if (!$row) { echo json_encode(['status'=>'error','message'=>'Live not found']); exit; }
            $ownerId = (int)($row['user_id'] ?? 0);
            if ($ownerId <= 0 || (int)$userID !== $ownerId) { echo json_encode(['status'=>'error','message'=>'Not allowed.']); exit; }

            if (!method_exists($RL,'RL_EndLiveStream')) { echo json_encode(['status'=>'error','message'=>'Live end not supported.']); exit; }
            $ok = $RL->RL_EndLiveStream($ownerId, $liveId);
            if (!$ok) { echo json_encode(['status'=>'error','message'=>'Could not end live or already ended.']); exit; }

            // Suggest redirect to profile
            $username = (string)($row['username'] ?? '');
            $redirect = rtrim((string)$base_url, '/') . ($username !== '' ? ('/profile/' . $username) : '/');
            echo json_encode(['status'=>'success','redirect'=>$redirect]);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        }
        exit;
    }

    // ==================================
    // Live: Get Agora join info (owner/viewer)
    // ==================================
    if ($p === 'agora_join') {
        try {
            if (!isset($userID) || (int)$userID <= 0) { echo json_encode(['status'=>'error','message'=>'Login required.']); exit; }
            if (!$liveStreamingEnabledGlobal) { echo json_encode(['status'=>'error','message'=>'live_stream_disabled']); exit; }
            $liveId = (int)($_POST['live_id'] ?? 0);
            if ($liveId <= 0) { echo json_encode(['status'=>'error','message'=>customLang('invalid_live_id')]); exit; }

            // Load live
            if (!isset($RL) || !method_exists($RL,'RL_GetLiveById')) { echo json_encode(['status'=>'error','message'=>'Live not supported.']); exit; }
            $row = $RL->RL_GetLiveById($liveId);
            if (!$row) { echo json_encode(['status'=>'error','message'=>'Live not found']); exit; }
            $status = (string)($row['status'] ?? '');
            if ($status === 'ended') { echo json_encode(['status'=>'error','message'=>'Live has ended.']); exit; }
            $ownerId = (int)($row['user_id'] ?? 0);
            $isOwner = ($ownerId > 0 && (int)$userID === $ownerId);
            $aud     = (string)($row['audience'] ?? 'everyone');

            // Gating (mirror layout gating)
            $canView = true;
            if (!$isOwner) {
                if ($aud === 'only_me') { $canView = false; }
                elseif ($aud === 'followers') {
                    if (!method_exists($RL, 'RL_IsFollowing') || !$RL->RL_IsFollowing((int)$userID, $ownerId)) { $canView = false; }
                } elseif ($aud === 'subscribers') {
                    if (!method_exists($RL, 'RL_IsSubscriber') || !$RL->RL_IsSubscriber((int)$userID, $ownerId)) { $canView = false; }
                } elseif ($aud === 'following') {
                    if (!method_exists($RL, 'RL_IsFollowing') || !$RL->RL_IsFollowing($ownerId, (int)$userID)) { $canView = false; }
                }
            }
            if (!$canView) { echo json_encode(['status'=>'error','message'=>'You are not allowed to join this live.']); exit; }

            // Build join info
            require_once __DIR__ . '/../includes/helpers/agora.php';
            $channel = agora_channel_name($liveId, $ownerId);
            $uid     = (string) ( $isOwner ? $ownerId : $userID );
            $role    = $isOwner ? 'host' : 'audience';

            if ($liveViewerLimitGlobal > 0 && !$isOwner && isset($RL) && method_exists($RL,'RL_CountLiveViewers')) {
                $recentCut = time() - 25;
                $viewerCount = $RL->RL_CountLiveViewers($liveId, $recentCut, $ownerId);
                if ($viewerCount >= $liveViewerLimitGlobal) {
                    echo json_encode(['status'=>'error','message'=>'live_capacity_reached']);
                    exit;
                }
            }

            // Config
            // Sanitize App ID (remove stray whitespace or separators)
            $appIdRaw = isset($agoraAppId) ? (string)$agoraAppId : '';
            $appId    = preg_replace('/[^A-Fa-f0-9]/', '', trim($appIdRaw));
            $appCertRaw = isset($agoraAppCert) ? (string)$agoraAppCert : '';
            $appCert = trim($appCertRaw);
            $expire  = isset($agoraTokenExpire) ? (int)$agoraTokenExpire : 7200;
            if ($appId === '' || strlen($appId) !== 32) {
                echo json_encode(['status'=>'error','message'=>'Agora App ID is invalid or missing in configuration.']);
                exit;
            }
            // Guard against common misconfiguration: app id mistakenly set to certificate
            $appCertHex = preg_replace('/[^A-Fa-f0-9]/', '', $appCert);
            if ($appCertHex !== '' && strcasecmp($appId, $appCertHex) === 0) {
                echo json_encode(['status'=>'error','message'=>'Agora configuration error: App ID and App Certificate appear to be swapped or identical. Please correct them.']);
                exit;
            }

            $payload = agora_build_join_payload($appId, $appCert, $channel, $uid, $role, $expire);
            $payload['region'] = isset($agoraRegion) ? (string)$agoraRegion : 'GLOBAL';
            if (!$isOwner && $agoraReadOnlyTokenGlobal !== '') {
                $payload['token'] = $agoraReadOnlyTokenGlobal;
                $payload['token_source'] = 'readonly';
                $payload['debug'] = false;
                $payload['allow_tokenless'] = false;
            }
            // Attach light debug meta (no secrets)
            $payload['meta'] = [
                'appId_source' => isset($agoraSource) ? (string)$agoraSource : 'db',
                'appId_dbg'    => substr($appId, 0, 6) . '…' . substr($appId, -6),
                'ts'           => time(),
            ];
            // Allow tokenless fallback for debugging if enabled in config
            // Robust boolean cast for enum('0','1') or other truthy inputs
            $payload['allow_tokenless'] = filter_var($agoraAllowTokenless ?? false, FILTER_VALIDATE_BOOLEAN);
            echo json_encode(['status'=>'success','data'=>$payload]);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        }
        exit;
    }

    // ==================================
    // Audio Room: Create room
    // ==================================
    if ($p === 'create_audio_room') {
        try {
            if (!isset($userID) || (int)$userID <= 0) {
                echo json_encode(['status'=>'error','message'=>'Login required.']);
                exit;
            }
            if (!$audioRoomsEnabledGlobal) {
                echo json_encode(['status'=>'error','message'=>'audio_rooms_disabled']);
                exit;
            }
            $csrfToken = (string)($_POST['csrf_token'] ?? '');
            if (!checkCsrfToken($csrfToken)) {
                http_response_code(403);
                echo json_encode(['status'=>'error','message'=>customLang('invalid_csrf_token')]);
                exit;
            }
            if (!isset($RL) || !method_exists($RL, 'RL_CreateAudioRoom')) {
                echo json_encode(['status'=>'error','message'=>'Audio rooms not supported.']);
                exit;
            }

            $uid = (int)$userID;
            $isCreator = method_exists($RL, 'RL_IsApprovedCreator') && $RL->RL_IsApprovedCreator($uid);
            $durationSeconds = null;
            if (!$isCreator && $audioRoomNonCreatorDailyMinutesGlobal > 0 && method_exists($RL, 'RL_GetAudioRoomRemainingSecondsForUser')) {
                $remaining = $RL->RL_GetAudioRoomRemainingSecondsForUser($uid, $audioRoomNonCreatorDailyMinutesGlobal);
                if ($remaining !== null && $remaining <= 0) {
                    echo json_encode(['status'=>'error','message'=>'audio_room_daily_limit_reached']);
                    exit;
                }
                $durationSeconds = $remaining !== null ? max(60, (int)$remaining) : null;
            }

            $title = trim(strip_tags((string)($_POST['title'] ?? '')));
            $description = trim(strip_tags((string)($_POST['description'] ?? '')));
            $audience = strtolower(trim((string)($_POST['audience'] ?? 'everyone')));
            $isPaid = in_array(strtolower(trim((string)($_POST['is_paid'] ?? '0'))), ['1','true','yes','on'], true);
            $entryPrice = null;
            $currencyCode = isset($paymentsCurrency) ? (string)$paymentsCurrency : (isset($currency) ? (string)$currency : 'USD');

            if ($title === '' || mb_strlen($title) < 3) {
                echo json_encode(['status'=>'error','message'=>'Please enter a title (min 3 chars).']);
                exit;
            }
            if (!in_array($audience, ['everyone','followers','following','subscribers','only_me'], true)) {
                echo json_encode(['status'=>'error','message'=>'Invalid audience.']);
                exit;
            }
            if ($isPaid) {
                if (!$audioRoomPaidEnabledGlobal || !$isCreator) {
                    echo json_encode(['status'=>'error','message'=>'audio_room_paid_not_allowed']);
                    exit;
                }
                $priceRaw = trim((string)($_POST['entry_price'] ?? ''));
                if ($priceRaw === '' || !is_numeric($priceRaw)) {
                    echo json_encode(['status'=>'error','message'=>'invalid_audio_room_price']);
                    exit;
                }
                $entryPrice = round((float)$priceRaw, 2);
                $isPreset = false;
                foreach ($audioRoomPricePresetsGlobal as $preset) {
                    if (abs((float)$preset - $entryPrice) < 0.001) { $isPreset = true; break; }
                }
                if (!$isPreset && !$audioRoomCustomPriceEnabledGlobal) {
                    echo json_encode(['status'=>'error','message'=>'audio_room_custom_price_disabled']);
                    exit;
                }
                if ($entryPrice < $audioRoomPriceMinimumGlobal || $entryPrice > $audioRoomPriceMaximumGlobal) {
                    echo json_encode(['status'=>'error','message'=>'audio_room_price_out_of_range']);
                    exit;
                }
            }

            $roomId = $RL->RL_CreateAudioRoom(
                $uid,
                $title,
                $description,
                $audience,
                $isPaid,
                $entryPrice,
                $currencyCode,
                $audioRoomMaxSpeakersGlobal,
                $audioRoomMaxListenersGlobal > 0 ? $audioRoomMaxListenersGlobal : null,
                'live',
                $durationSeconds
            );
            if ($roomId <= 0) {
                echo json_encode(['status'=>'error','message'=>'Could not create audio room.']);
                exit;
            }
            $url = rtrim((string)$base_url, '/') . '/audio-room/' . $roomId;
            echo json_encode(['status'=>'success','room_id'=>$roomId,'url'=>$url]);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        }
        exit;
    }

    // ==================================
    // Audio Room: End room
    // ==================================
    if ($p === 'end_audio_room') {
        try {
            if (!isset($userID) || (int)$userID <= 0) {
                echo json_encode(['status'=>'error','message'=>'Login required.']);
                exit;
            }
            $csrfToken = (string)($_POST['csrf_token'] ?? '');
            if (!checkCsrfToken($csrfToken)) {
                http_response_code(403);
                echo json_encode(['status'=>'error','message'=>customLang('invalid_csrf_token')]);
                exit;
            }
            $roomId = (int)($_POST['room_id'] ?? 0);
            if ($roomId <= 0 || !isset($RL) || !method_exists($RL, 'RL_GetAudioRoomById')) {
                echo json_encode(['status'=>'error','message'=>'invalid_room_id']);
                exit;
            }
            $room = $RL->RL_GetAudioRoomById($roomId);
            if (!$room) { echo json_encode(['status'=>'error','message'=>'room_not_found']); exit; }
            if (!dz_audio_room_can_manage($RL, $room, (int)$userID)) {
                echo json_encode(['status'=>'error','message'=>'Not allowed.']);
                exit;
            }
            $ok = method_exists($RL, 'RL_EndAudioRoomAsManager')
                ? $RL->RL_EndAudioRoomAsManager((int)$userID, $roomId, 'manager_ended')
                : (method_exists($RL, 'RL_EndAudioRoom') && $RL->RL_EndAudioRoom((int)($room['owner_id'] ?? 0), $roomId, 'owner_ended'));
            echo json_encode(['status'=>$ok ? 'success' : 'error']);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        }
        exit;
    }

    // ==================================
    // Audio Room: Agora join info
    // ==================================
    if ($p === 'audio_room_join') {
        try {
            if (!isset($userID) || (int)$userID <= 0) {
                echo json_encode(['status'=>'error','message'=>'Login required.']);
                exit;
            }
            if (!$audioRoomsEnabledGlobal) {
                echo json_encode(['status'=>'error','message'=>'audio_rooms_disabled']);
                exit;
            }
            $csrfToken = (string)($_POST['csrf_token'] ?? '');
            if (!checkCsrfToken($csrfToken)) {
                http_response_code(403);
                echo json_encode(['status'=>'error','message'=>customLang('invalid_csrf_token')]);
                exit;
            }
            $roomId = (int)($_POST['room_id'] ?? 0);
            if ($roomId <= 0 || !isset($RL) || !method_exists($RL, 'RL_UserCanJoinAudioRoom')) {
                echo json_encode(['status'=>'error','message'=>'invalid_room_id']);
                exit;
            }
            $uid = (int)$userID;
            $access = $RL->RL_UserCanJoinAudioRoom($uid, $roomId);
            if (empty($access['ok'])) {
                echo json_encode(['status'=>'error','message'=>(string)($access['reason'] ?? 'not_allowed')]);
                exit;
            }
            $room = (array)($access['room'] ?? []);
            $ownerId = (int)($room['owner_id'] ?? 0);
            $role = 'listener';
            if ($uid === $ownerId) {
                $role = 'host';
            } elseif (method_exists($RL, 'RL_IsAudioRoomModerator') && $RL->RL_IsAudioRoomModerator($roomId, $uid)) {
                $role = 'moderator';
            } elseif (method_exists($RL, 'RL_IsAudioRoomSpeaker') && $RL->RL_IsAudioRoomSpeaker($roomId, $uid)) {
                $role = 'speaker';
            }

            if ($audioRoomMaxListenersGlobal > 0 && $role === 'listener' && method_exists($RL, 'RL_CountAudioRoomParticipants')) {
                $listenerCount = $RL->RL_CountAudioRoomParticipants($roomId, time() - 30, ['listener']);
                if ($listenerCount >= $audioRoomMaxListenersGlobal) {
                    echo json_encode(['status'=>'error','message'=>'audio_room_capacity_reached']);
                    exit;
                }
            }

            require_once __DIR__ . '/../includes/helpers/agora.php';
            $appIdRaw = isset($agoraAppId) ? (string)$agoraAppId : '';
            $appId = preg_replace('/[^A-Fa-f0-9]/', '', trim($appIdRaw));
            $appCert = trim((string)($agoraAppCert ?? ''));
            $expire = isset($agoraTokenExpire) ? (int)$agoraTokenExpire : 7200;
            if ($appId === '' || strlen($appId) !== 32) {
                echo json_encode(['status'=>'error','message'=>'Agora App ID is invalid or missing in configuration.']);
                exit;
            }
            $channel = (string)($room['agora_channel'] ?? '');
            if ($channel === '' && function_exists('agora_audio_room_channel_name')) {
                $channel = agora_audio_room_channel_name($roomId, $ownerId);
            }
            $payload = agora_build_audio_room_join_payload($appId, $appCert, $channel, (string)$uid, $role, $expire);
            $payload['region'] = isset($agoraRegion) ? (string)$agoraRegion : 'GLOBAL';
            $payload['room_id'] = $roomId;
            $payload['allow_tokenless'] = filter_var($agoraAllowTokenless ?? false, FILTER_VALIDATE_BOOLEAN);
            $payload['meta'] = [
                'appId_source' => isset($agoraSource) ? (string)$agoraSource : 'db',
                'appId_dbg' => substr($appId, 0, 6) . '…' . substr($appId, -6),
                'ts' => time(),
            ];
            $payload['auto_end_at'] = isset($room['auto_end_at']) ? (int)$room['auto_end_at'] : 0;
            $payload['server_time'] = time();
            $payload['speaker_status'] = method_exists($RL, 'RL_GetAudioRoomSpeakerStatus') ? $RL->RL_GetAudioRoomSpeakerStatus($roomId, $uid) : '';

            if (method_exists($RL, 'RL_UpsertAudioRoomParticipant')) {
                $RL->RL_UpsertAudioRoomParticipant($roomId, dz_audio_room_session_key(), $uid, $role, $role === 'listener', false, time());
            }
            echo json_encode(['status'=>'success','data'=>$payload]);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        }
        exit;
    }

    if ($p === 'audio_room_leave') {
        $csrfToken = (string)($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status'=>'error','message'=>customLang('invalid_csrf_token')]);
            exit;
        }
        $roomId = (int)($_POST['room_id'] ?? 0);
        $ok = $roomId > 0 && isset($RL) && method_exists($RL, 'RL_LeaveAudioRoomParticipant') && $RL->RL_LeaveAudioRoomParticipant($roomId, dz_audio_room_session_key(), time());
        echo json_encode(['status'=>$ok ? 'success' : 'error']);
        exit;
    }

    if ($p === 'audio_room_chat_send') {
        try {
            if (!isset($userID) || (int)$userID <= 0) { echo json_encode(['status'=>'error','message'=>'Login required.']); exit; }
            if (!$audioRoomChatEnabledGlobal) { echo json_encode(['status'=>'error','message'=>'audio_room_chat_disabled']); exit; }
            $csrfToken = (string)($_POST['csrf_token'] ?? '');
            if (!checkCsrfToken($csrfToken)) { http_response_code(403); echo json_encode(['status'=>'error','message'=>customLang('invalid_csrf_token')]); exit; }
            $roomId = (int)($_POST['room_id'] ?? 0);
            $message = trim((string)($_POST['message'] ?? ''));
            if ($message === '' || mb_strlen($message) > 500) { echo json_encode(['status'=>'error','message'=>'invalid_message']); exit; }
            if (!isset($RL) || !method_exists($RL, 'RL_UserCanJoinAudioRoom') || !method_exists($RL, 'RL_InsertAudioRoomMessage')) {
                echo json_encode(['status'=>'error','message'=>'not_supported']);
                exit;
            }
            $access = $RL->RL_UserCanJoinAudioRoom((int)$userID, $roomId);
            if (empty($access['ok'])) { echo json_encode(['status'=>'error','message'=>(string)($access['reason'] ?? 'not_allowed')]); exit; }
            $chatMuteRemaining = method_exists($RL, 'RL_GetAudioRoomChatMuteRemaining') ? $RL->RL_GetAudioRoomChatMuteRemaining($roomId, (int)$userID) : 0;
            if ($chatMuteRemaining > 0) {
                echo json_encode(['status'=>'error','message'=>'chat_muted','remaining'=>$chatMuteRemaining]);
                exit;
            }
            $id = $RL->RL_InsertAudioRoomMessage($roomId, (int)$userID, $message, 'chat');
            echo json_encode(['status'=>$id > 0 ? 'success' : 'error','message_id'=>$id]);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>'server_error']);
        }
        exit;
    }

    if ($p === 'audio_room_speaker_request') {
        $csrfToken = (string)($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) { http_response_code(403); echo json_encode(['status'=>'error','message'=>customLang('invalid_csrf_token')]); exit; }
        $roomId = (int)($_POST['room_id'] ?? 0);
        $uid = isset($userID) ? (int)$userID : 0;
        $chatMuteRemaining = $uid > 0 && isset($RL) && method_exists($RL, 'RL_GetAudioRoomChatMuteRemaining') ? $RL->RL_GetAudioRoomChatMuteRemaining($roomId, $uid) : 0;
        if ($chatMuteRemaining > 0) {
            echo json_encode(['status'=>'error','message'=>'chat_muted','remaining'=>$chatMuteRemaining]);
            exit;
        }
        $ok = $uid > 0 && $roomId > 0 && isset($RL) && method_exists($RL, 'RL_UserCanJoinAudioRoom') && !empty($RL->RL_UserCanJoinAudioRoom($uid, $roomId)['ok']) && method_exists($RL, 'RL_RequestAudioRoomSpeaker') && $RL->RL_RequestAudioRoomSpeaker($roomId, $uid);
        echo json_encode(['status'=>$ok ? 'success' : 'error']);
        exit;
    }

    if ($p === 'audio_room_mic_update') {
        try {
            $csrfToken = (string)($_POST['csrf_token'] ?? '');
            if (!checkCsrfToken($csrfToken)) { http_response_code(403); echo json_encode(['status'=>'error','message'=>customLang('invalid_csrf_token')]); exit; }
            $roomId = (int)($_POST['room_id'] ?? 0);
            $muted = in_array(strtolower(trim((string)($_POST['muted'] ?? '1'))), ['1','true','yes','on'], true);
            $uid = isset($userID) ? (int)$userID : 0;
            if ($roomId <= 0 || $uid <= 0 || !isset($RL) || !method_exists($RL, 'RL_UserCanJoinAudioRoom')) {
                echo json_encode(['status'=>'error','message'=>'invalid_request']);
                exit;
            }
            $access = $RL->RL_UserCanJoinAudioRoom($uid, $roomId);
            if (empty($access['ok'])) { echo json_encode(['status'=>'error','message'=>(string)($access['reason'] ?? 'not_allowed')]); exit; }
            $room = (array)($access['room'] ?? []);
            $ownerId = (int)($room['owner_id'] ?? 0);
            $role = 'listener';
            if ($uid === $ownerId) {
                $role = 'host';
            } elseif (method_exists($RL, 'RL_IsAudioRoomModerator') && $RL->RL_IsAudioRoomModerator($roomId, $uid)) {
                $role = 'moderator';
            } elseif (method_exists($RL, 'RL_IsAudioRoomSpeaker') && $RL->RL_IsAudioRoomSpeaker($roomId, $uid)) {
                $role = 'speaker';
            }
            if (!in_array($role, ['host','moderator','speaker'], true)) {
                echo json_encode(['status'=>'error','message'=>'not_speaker']);
                exit;
            }
            $speakerStatus = method_exists($RL, 'RL_GetAudioRoomSpeakerStatus') ? $RL->RL_GetAudioRoomSpeakerStatus($roomId, $uid) : '';
            if (!$muted && $role === 'speaker' && $speakerStatus === 'muted') {
                echo json_encode(['status'=>'error','message'=>'muted_by_moderator','speaker_status'=>$speakerStatus]);
                exit;
            }
            $ok = method_exists($RL, 'RL_UpdateAudioRoomParticipantMic') && $RL->RL_UpdateAudioRoomParticipantMic($roomId, dz_audio_room_session_key(), $uid, $muted);
            echo json_encode(['status'=>$ok ? 'success' : 'error','muted'=>$muted ? 1 : 0,'role'=>$role,'speaker_status'=>$speakerStatus]);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>'server_error']);
        }
        exit;
    }

    if ($p === 'audio_room_manage_panel') {
        try {
            $csrfToken = (string)($_POST['csrf_token'] ?? '');
            if (!checkCsrfToken($csrfToken)) { http_response_code(403); echo json_encode(['status'=>'error','message'=>customLang('invalid_csrf_token')]); exit; }
            $roomId = (int)($_POST['room_id'] ?? 0);
            $uid = isset($userID) ? (int)$userID : 0;
            if ($roomId <= 0 || $uid <= 0 || !isset($RL) || !method_exists($RL, 'RL_GetAudioRoomById')) {
                echo json_encode(['status'=>'error','message'=>'invalid_request']);
                exit;
            }
            $room = $RL->RL_GetAudioRoomById($roomId);
            if (!$room || !dz_audio_room_can_manage($RL, $room, $uid)) {
                echo json_encode(['status'=>'error','message'=>'not_allowed']);
                exit;
            }
            $participants = method_exists($RL, 'RL_GetAudioRoomParticipants')
                ? $RL->RL_GetAudioRoomParticipants($roomId, time() - 45, 100)
                : [];
            $requests = method_exists($RL, 'RL_GetAudioRoomSpeakerRequests')
                ? $RL->RL_GetAudioRoomSpeakerRequests($roomId, 'pending', 50)
                : [];
            $baseForMedia = isset($base_url) ? (string)$base_url : '';
            $decorate = static function (array $row) use ($baseForMedia): array {
                $avatar = (string)($row['user_avatar'] ?? '');
                if ($avatar === '') { $avatar = 'uploads/avatars/default_avatar.png'; }
                $row['avatar_url'] = function_exists('storage_resolve_media_url')
                    ? storage_resolve_media_url($avatar, $baseForMedia)
                    : rtrim($baseForMedia, '/') . '/' . ltrim($avatar, '/');
                $row['display_name'] = trim((string)($row['user_fullname'] ?? '')) !== ''
                    ? trim((string)$row['user_fullname'])
                    : (string)($row['username'] ?? '');
                return $row;
            };
            $participants = array_map($decorate, $participants);
            if (method_exists($RL, 'RL_GetAudioRoomSpeakerStatus')) {
                foreach ($participants as &$participantRow) {
                    $participantUserId = (int)($participantRow['user_id'] ?? 0);
                    $participantRow['speaker_status'] = $participantUserId > 0 ? $RL->RL_GetAudioRoomSpeakerStatus($roomId, $participantUserId) : '';
                    $participantRow['chat_mute_remaining'] = $participantUserId > 0 && method_exists($RL, 'RL_GetAudioRoomChatMuteRemaining') ? $RL->RL_GetAudioRoomChatMuteRemaining($roomId, $participantUserId) : 0;
                }
                unset($participantRow);
            }
            $requests = array_map($decorate, $requests);
            echo json_encode([
                'status' => 'success',
                'participants' => $participants,
                'speaker_requests' => $requests,
            ]);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>'server_error']);
        }
        exit;
    }

    if ($p === 'audio_room_speaker_review') {
        try {
            $csrfToken = (string)($_POST['csrf_token'] ?? '');
            if (!checkCsrfToken($csrfToken)) { http_response_code(403); echo json_encode(['status'=>'error','message'=>customLang('invalid_csrf_token')]); exit; }
            $roomId = (int)($_POST['room_id'] ?? 0);
            $targetId = (int)($_POST['user_id'] ?? 0);
            $decision = strtolower(trim((string)($_POST['decision'] ?? '')));
            $uid = isset($userID) ? (int)$userID : 0;
            if ($roomId <= 0 || $targetId <= 0 || !in_array($decision, ['approved','rejected','cancelled'], true) || !isset($RL) || !method_exists($RL, 'RL_GetAudioRoomById')) {
                echo json_encode(['status'=>'error','message'=>'invalid_request']);
                exit;
            }
            $room = $RL->RL_GetAudioRoomById($roomId);
            if (!$room || !dz_audio_room_can_manage($RL, $room, $uid)) {
                echo json_encode(['status'=>'error','message'=>'not_allowed']);
                exit;
            }
            $ok = method_exists($RL, 'RL_ReviewAudioRoomSpeakerRequest') && $RL->RL_ReviewAudioRoomSpeakerRequest($roomId, $targetId, $uid, $decision);
            echo json_encode(['status'=>$ok ? 'success' : 'error']);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>'server_error']);
        }
        exit;
    }

    if ($p === 'audio_room_speaker_mute') {
        try {
            $csrfToken = (string)($_POST['csrf_token'] ?? '');
            if (!checkCsrfToken($csrfToken)) { http_response_code(403); echo json_encode(['status'=>'error','message'=>customLang('invalid_csrf_token')]); exit; }
            $roomId = (int)($_POST['room_id'] ?? 0);
            $targetId = (int)($_POST['user_id'] ?? 0);
            $muted = in_array(strtolower(trim((string)($_POST['muted'] ?? '1'))), ['1','true','yes','on'], true);
            $uid = isset($userID) ? (int)$userID : 0;
            if ($roomId <= 0 || $targetId <= 0 || !isset($RL) || !method_exists($RL, 'RL_GetAudioRoomById')) {
                echo json_encode(['status'=>'error','message'=>'invalid_request']);
                exit;
            }
            $room = $RL->RL_GetAudioRoomById($roomId);
            if (!$room || !dz_audio_room_can_manage($RL, $room, $uid)) {
                echo json_encode(['status'=>'error','message'=>'not_allowed']);
                exit;
            }
            if ((int)($room['owner_id'] ?? 0) === $targetId) {
                echo json_encode(['status'=>'error','message'=>'owner_cannot_be_muted']);
                exit;
            }
            $ok = method_exists($RL, 'RL_SetAudioRoomSpeakerMuteState') && $RL->RL_SetAudioRoomSpeakerMuteState($roomId, $targetId, $uid, $muted);
            echo json_encode(['status'=>$ok ? 'success' : 'error','muted'=>$muted ? 1 : 0]);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>'server_error']);
        }
        exit;
    }

    if ($p === 'audio_room_participant_action') {
        try {
            $csrfToken = (string)($_POST['csrf_token'] ?? '');
            if (!checkCsrfToken($csrfToken)) { http_response_code(403); echo json_encode(['status'=>'error','message'=>customLang('invalid_csrf_token')]); exit; }
            $roomId = (int)($_POST['room_id'] ?? 0);
            $targetId = (int)($_POST['user_id'] ?? 0);
            $action = strtolower(trim((string)($_POST['action'] ?? '')));
            $minutes = (int)($_POST['minutes'] ?? 1);
            $reason = trim(strip_tags((string)($_POST['reason'] ?? '')));
            $uid = isset($userID) ? (int)$userID : 0;
            if ($roomId <= 0 || $targetId <= 0 || $uid <= 0 || !in_array($action, ['chat_mute','chat_unmute','kick','ban','remove_speaker'], true) || !isset($RL) || !method_exists($RL, 'RL_GetAudioRoomById')) {
                echo json_encode(['status'=>'error','message'=>'invalid_request']);
                exit;
            }
            $room = $RL->RL_GetAudioRoomById($roomId);
            if (!$room || !dz_audio_room_can_manage($RL, $room, $uid)) {
                echo json_encode(['status'=>'error','message'=>'not_allowed']);
                exit;
            }
            $ownerId = (int)($room['owner_id'] ?? 0);
            if ($targetId === $ownerId) {
                echo json_encode(['status'=>'error','message'=>'owner_cannot_be_moderated']);
                exit;
            }
            $targetIsModerator = method_exists($RL, 'RL_IsAudioRoomModerator') && $RL->RL_IsAudioRoomModerator($roomId, $targetId);
            if ($targetIsModerator && $uid !== $ownerId) {
                echo json_encode(['status'=>'error','message'=>'moderator_action_requires_owner']);
                exit;
            }

            $ok = false;
            if ($action === 'chat_mute') {
                $ok = method_exists($RL, 'RL_SetAudioRoomChatMute') && $RL->RL_SetAudioRoomChatMute($roomId, $targetId, $uid, $minutes, $reason);
                if ($ok && method_exists($RL, 'RL_InsertAudioRoomMessage')) {
                    $RL->RL_InsertAudioRoomMessage($roomId, $uid, 'A participant was muted in chat.', 'system', ['event'=>'chat_mute','target_id'=>$targetId,'minutes'=>max(1, min(5, $minutes))]);
                }
            } elseif ($action === 'chat_unmute') {
                $ok = method_exists($RL, 'RL_ClearAudioRoomChatMute') && $RL->RL_ClearAudioRoomChatMute($roomId, $targetId, $uid);
            } elseif ($action === 'kick' || $action === 'ban') {
                $ok = method_exists($RL, 'RL_KickAudioRoomParticipant') && $RL->RL_KickAudioRoomParticipant($roomId, $targetId, $uid, $action === 'ban', $reason);
                if ($ok && method_exists($RL, 'RL_InsertAudioRoomMessage')) {
                    $RL->RL_InsertAudioRoomMessage($roomId, $uid, $action === 'ban' ? 'A participant was banned from the room.' : 'A participant was removed from the room.', 'system', ['event'=>$action,'target_id'=>$targetId]);
                }
            } elseif ($action === 'remove_speaker') {
                $ok = method_exists($RL, 'RL_SetAudioRoomSpeakerMuteState') && $RL->RL_SetAudioRoomSpeakerMuteState($roomId, $targetId, $uid, true);
            }
            echo json_encode(['status'=>$ok ? 'success' : 'error','action'=>$action]);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>'server_error']);
        }
        exit;
    }

    if ($p === 'audio_room_moderator_assign') {
        try {
            $csrfToken = (string)($_POST['csrf_token'] ?? '');
            if (!checkCsrfToken($csrfToken)) { http_response_code(403); echo json_encode(['status'=>'error','message'=>customLang('invalid_csrf_token')]); exit; }
            $roomId = (int)($_POST['room_id'] ?? 0);
            $targetId = (int)($_POST['user_id'] ?? 0);
            $uid = isset($userID) ? (int)$userID : 0;
            if ($roomId <= 0 || $targetId <= 0 || !isset($RL) || !method_exists($RL, 'RL_GetAudioRoomById')) {
                echo json_encode(['status'=>'error','message'=>'invalid_request']);
                exit;
            }
            $room = $RL->RL_GetAudioRoomById($roomId);
            if (!$room || (int)($room['owner_id'] ?? 0) !== $uid) {
                echo json_encode(['status'=>'error','message'=>'not_allowed']);
                exit;
            }
            $ok = method_exists($RL, 'RL_AssignAudioRoomModerator') && $RL->RL_AssignAudioRoomModerator($roomId, $targetId, $uid);
            echo json_encode(['status'=>$ok ? 'success' : 'error']);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>'server_error']);
        }
        exit;
    }

    if ($p === 'announce_audio_room_tip') {
        $csrfToken = (string)($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) { http_response_code(403); echo json_encode(['status'=>'error','message'=>customLang('invalid_csrf_token')]); exit; }
        $roomId = (int)($_POST['room_id'] ?? 0);
        $buyer = trim((string)($_POST['buyer'] ?? ''));
        $amount = (float)($_POST['amount'] ?? 0);
        $curr = strtoupper(trim((string)($_POST['currency'] ?? 'USD')));
        $uid = isset($userID) ? (int)$userID : 0;
        $ok = $roomId > 0 && $amount > 0 && isset($RL) && method_exists($RL, 'RL_InsertAudioRoomTipEvent') && $RL->RL_InsertAudioRoomTipEvent($roomId, $uid, $buyer, $amount, $curr);
        if ($ok && method_exists($RL, 'RL_InsertAudioRoomMessage')) {
            $RL->RL_InsertAudioRoomMessage($roomId, $uid, '', 'tip', ['buyer'=>$buyer, 'amount'=>$amount, 'currency'=>$curr]);
        }
        echo json_encode(['status'=>$ok ? 'success' : 'error']);
        exit;
    }

    // ==================================
    // Payment: Status poll (tips/purchase)
    // ==================================
    if ($p === 'payment_status') {
        $paymentHandler->handlePaymentStatus();
        return;
    }

    // ==================================
    // Tips: Create Payment
    // ==================================
    if ($p === 'tips_create_payment') {
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!function_exists('checkCsrfToken') || !checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }
        $paymentHandler->handleTipsCreatePayment();
        return;
    }

    // ==================================
    // Purchase: Create Payment
    // ==================================
    if ($p === 'purchase_create_payment') {
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!function_exists('checkCsrfToken') || !checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }
        $paymentHandler->handlePurchaseCreatePayment();
        return;
    }

    // ==================================
    // Audio Room Ticket: Create Payment
    // ==================================
    if ($p === 'audio_room_ticket_create_payment') {
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!function_exists('checkCsrfToken') || !checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }
        $paymentHandler->handleAudioRoomTicketCreatePayment();
        return;
    }

    if ($p === 'audio_room_tip_create_payment') {
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!function_exists('checkCsrfToken') || !checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }
        $paymentHandler->handleAudioRoomTipCreatePayment();
        return;
    }

    // ==================================
    // Subscription: Create Payment
    // ==================================
    if ($p === 'subscription_create_payment') {
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!function_exists('checkCsrfToken') || !checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }
        $paymentHandler->handleSubscriptionCreatePayment();
        return;
    }

    if ($p === 'upload_temp_podcast') {
        if ($loggedIn !== '1' || (int) ($userID ?? 0) <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            exit;
        }
        if (!$podcastsEnabledGlobal) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('content_disabled_podcasts', 'Podcast sharing is currently disabled.')]);
            exit;
        }
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }
        $reelsHandler->handleUploadTempPodcast();
        return;
    }

    if ($p === 'upload_temp_video') {
        if ($loggedIn !== '1' || (int) ($userID ?? 0) <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            exit;
        }
        if (!$videosEnabledGlobal) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('content_disabled_videos', 'Video sharing is currently disabled.')]);
            exit;
        }
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }
        $reelsHandler->handleUploadTempVideo();
        return;
    }

    if ($p === 'finalize_podcast') {
        if ($loggedIn !== '1' || (int) ($userID ?? 0) <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            exit;
        }
        if (!$podcastsEnabledGlobal) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('content_disabled_podcasts', 'Podcast sharing is currently disabled.')]);
            exit;
        }
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }
        $reelsHandler->handleFinalizePodcast();
        return;
    }

    if ($p === 'finalize_reel') {
        if ($loggedIn !== '1' || (int) ($userID ?? 0) <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            exit;
        }
        if (!$videosEnabledGlobal) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('content_disabled_videos', 'Video sharing is currently disabled.')]);
            exit;
        }
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }
        $reelsHandler->handleFinalizeReel();
        return;
    }

    /*Get Emojis*/
    if($p === 'getEmojiList'){
        $filePath = __DIR__ . '/../themes/' . $currentTheme . '/popUps/emoji_list.php';

        if (file_exists($filePath)) {
            ob_start();
            include $filePath;
            $htmlContent = ob_get_clean();

            echo json_encode([
                'status' => 'success',
                'html' => $htmlContent
            ]);
            exit;
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => customLang('requested_content_not_found')
            ]);
            exit;
        }
    }
    if ($p === 'like_post') {

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => customLang('invalid_request_method', 'Invalid request method.'),
            ]);
            exit;
        }

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => customLang('invalid_csrf_token'),
            ]);
            exit;
        }

        try {
            $uid    = isset($userID) ? (int)$userID : (int)($_SESSION['user_id'] ?? 0);
            $postID = (int)($_POST['post_id'] ?? 0);

            if ($uid <= 0 || $postID <= 0) {
                echo json_encode(['success' => false, 'message' => customLang('error_invalid_parameters')]);
                exit;
            }

            // Like toggle operation
            [$likedNow, $likes] = $RL->RL_ToggleLike($uid, $postID, time(), 'post');

            // Notification handling
            if (isset($RL) && is_object($RL)) {
                try {
                    $ownerId = 0;
                    if (method_exists($RL, 'RL_GetPostOwnerId')) {
                        $ownerId = (int) $RL->RL_GetPostOwnerId($postID);
                    } elseif (method_exists($RL, 'RL_GetPostById')) {
                        $postRow = $RL->RL_GetPostById($postID);
                        if (is_array($postRow) && !empty($postRow)) {
                            $ownerId = (int) ($postRow['post_owner_id'] ?? 0);
                        }
                    }

                    if ($ownerId > 0 && $ownerId !== $uid) {
                        if ($likedNow) {
                            // New like -> add notification
                            if (method_exists($RL, 'RL_CreatePostLikeNotification')) {
                                $RL->RL_CreatePostLikeNotification($uid, $ownerId, $postID, time());
                            } elseif (method_exists($RL, 'RL_CreateNotification')) {
                                $RL->RL_CreateNotification($ownerId, $uid, 'post_like', $postID, time());
                            }
                        } else {
                            // Unlike -> bildirim sil
                            if (method_exists($RL, 'RL_DeleteNotification')) {
                                $RL->RL_DeleteNotification($ownerId, $uid, 'post_like', $postID);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    if (is_callable([$RL, 'logError'])) {
                        $RL->logError('post_like notification error: ' . $e->getMessage());
                    } else {
                        error_log('post_like notification error: ' . $e->getMessage());
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'likes'   => $likes,
                'liked'   => $likedNow
            ]);
            exit;

        } catch (\Throwable $e) {
            if (isset($RL) && is_object($RL) && is_callable([$RL, 'logError'])) {
                $RL->logError('like_post error: ' . $e->getMessage());
            } else {
                error_log('like_post error: ' . $e->getMessage());
            }
            echo json_encode(['success' => false, 'message' => customLang('server_error'), 'code' => 'SERVER_ERROR']);
            exit;
        }
    }

    // Like/Unlike a live stream
    if ($p === 'like_live') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => customLang('invalid_request_method', 'Invalid request method.'),
            ]);
            exit;
        }

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => customLang('invalid_csrf_token'),
            ]);
            exit;
        }

        try {
            $uid    = isset($userID) ? (int)$userID : (int)($_SESSION['user_id'] ?? 0);
            $liveID = (int)($_POST['live_id'] ?? 0);
            if ($uid <= 0 || $liveID <= 0) {
                echo json_encode(['success' => false, 'message' => customLang('error_invalid_parameters')]);
                exit;
            }

            if (!isset($RL) || !method_exists($RL, 'RL_ToggleLiveLike')) {
                echo json_encode(['success' => false, 'message' => customLang('live_like_not_supported')]);
                exit;
            }

            [$likedNow, $likes] = $RL->RL_ToggleLiveLike($uid, $liveID, time());

            // Optional: notify live owner (type=live_like)
            try {
                if (method_exists($RL, 'RL_GetLiveById')) {
                    $live = $RL->RL_GetLiveById($liveID);
                    $ownerId = (int)($live['user_id'] ?? 0);
                    if ($ownerId > 0 && $ownerId !== $uid) {
                        if ($likedNow) {
                            if (method_exists($RL, 'RL_CreateLiveLikeNotification')) {
                                $RL->RL_CreateLiveLikeNotification($uid, $ownerId, $liveID, time());
                            }
                        } else {
                            if (method_exists($RL, 'RL_DeleteNotification')) {
                                $RL->RL_DeleteNotification($ownerId, $uid, 'live_like', $liveID);
                            }
                        }
                    }
                }
            } catch (\Throwable $__) { /* ignore */ }

            echo json_encode(['success' => true, 'liked' => $likedNow, 'likes' => (int)$likes]);
            exit;
        } catch (\Throwable $e) {
            if (isset($RL) && is_callable([$RL, 'logError'])) { $RL->logError('like_live error: ' . $e->getMessage()); }
            echo json_encode(['success' => false, 'message' => customLang('server_error')]);
            exit;
        }
    }

    // (moved to public pIncoming route above)

    // Announce a live tip immediately (fallback if RTM not available). Client calls after success.
    if ($p === 'announce_live_tip') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_request_method', 'Invalid request method.')]);
            exit;
        }

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }

        try {
            $liveID = (int)($_POST['live_id'] ?? 0);
            $buyer  = trim((string)($_POST['buyer'] ?? ''));
            $amount = (float)($_POST['amount'] ?? 0);
            $curr   = strtoupper(trim((string)($_POST['currency'] ?? 'USD')));
            if ($liveID <= 0 || $amount <= 0 || $buyer === '') { echo json_encode(['status'=>'error']); exit; }
            if (!isset($RL) || !method_exists($RL, 'RL_InsertLiveTipEvent')) { echo json_encode(['status'=>'error']); exit; }
            $ok = $RL->RL_InsertLiveTipEvent($liveID, $buyer, $amount, $curr, time());
            echo json_encode(['status'=>$ok ? 'success' : 'error']);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error']);
        }
        exit;
    }
    /*Add Comment*/
    if ($p === 'add_comment') {

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            dz_json_response([
                'status'  => 'error',
                'message' => customLang('invalid_request_method', 'Invalid request method.'),
                'code'    => 'INVALID_METHOD',
            ], 405);
            exit;
        }

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            dz_json_response([
                'status'  => 'error',
                'message' => customLang('invalid_csrf_token'),
                'code'    => 'INVALID_CSRF',
            ], 403);
            exit;
        }

        try {
            // ---- Normalize & collect inputs (server-side remains source of truth) ----
            $uid    = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $postID = (int) ($_POST['post_id'] ?? 0);
            $raw    = (string) ($_POST['comment'] ?? '');
            $time   = time();

            // Normalize whitespace; keep Unicode
            $comment = preg_replace('/\s+/u', ' ', trim($raw));

            // Soft cap for UX; DB may allow more but we keep UI predictable
            $maxLen = 5000;
            if (mb_strlen($comment, 'UTF-8') > $maxLen) {
                $comment = mb_substr($comment, 0, $maxLen, 'UTF-8');
            }

            // ---- Validation (messages are translated) ----
            if ($uid <= 0 || $postID <= 0) {
                dz_json_response([
                    'status'  => 'error',
                    'message' => customLang('error_invalid_parameters', 'Invalid parameters.'),
                    'code'    => 'INVALID_PARAMETERS',
                ]);
                exit;
            }

            if ($comment === '') {
                dz_json_response([
                    'status'  => 'error',
                    'message' => customLang('error_empty_comment', "You can't submit an empty comment."),
                    'code'    => 'EMPTY_COMMENT',
                ]);
                exit;
            }


            // ---- Insert via data layer (and capture inserted row id) ----
            $commentID   = 0;
            $insertedRow = null;

            if (isset($RL) && is_object($RL)) {
                if (method_exists($RL, 'RL_AddCommentReturningId')) {
                    // Tercih edilen: PK’yi direkt al
                    $commentID = (int) $RL->RL_AddCommentReturningId($uid, $postID, $comment, $time);
                } elseif (method_exists($RL, 'RL_AddComment')) {
                    // Backward compatibility: boolean insert + resolve via unique fields
                    $ok = (bool) $RL->RL_AddComment($uid, $postID, $comment, $time);
                    if ($ok) {
                        if (method_exists($RL, 'RL_GetCommentByComposite')) {
                            $row = $RL->RL_GetCommentByComposite($postID, $uid, $time);
                            if (is_array($row) && !empty($row)) {
                                $insertedRow = $row;
                                $commentID   = (int) ($row['c_id'] ?? 0);
                            }
                        } elseif (method_exists($RL, 'RL_GetRecentCommentsForPost')) {
                            // Fetch the newest comment — last resort (rare race conditions may differ)
                            $rows = $RL->RL_GetRecentCommentsForPost($postID, 1);
                            if (!empty($rows)) {
                                $insertedRow = $rows[0];
                                $commentID   = (int) ($rows[0]['c_id'] ?? 0);
                            }
                        }
                    }
                }
            }

            if ($commentID <= 0) {
                dz_json_response([
                    'status'  => 'error',
                    'message' => customLang('error_db_insert_failed', 'Failed to add the comment. Please try again.'),
                    'code'    => 'DB_INSERT_FAILED',
                ]);
                exit;
            }

            // ---- Fetch total comments ----
            $total = 0;
            if (isset($RL) && is_object($RL) && method_exists($RL, 'RL_TotalComment')) {
                $total = (int) $RL->RL_TotalComment($postID);
            }

            // ---- Render freshly created comment with theme partial (DB-backed values) ----
            $commentHtml = '';
            $filePath = __DIR__ . '/../themes/' . $currentTheme . '/layouts/newComment.php';

            // Load the row now if needed
            if ($insertedRow === null) {
                if (method_exists($RL, 'RL_GetCommentById')) {
                    $insertedRow = $RL->RL_GetCommentById($commentID);
                } elseif (method_exists($RL, 'RL_GetRecentCommentsForPost')) {
                    $rows = $RL->RL_GetRecentCommentsForPost($postID, 1);
                    $insertedRow = !empty($rows) ? $rows[0] : null;
                }
            }

            if (is_file($filePath)) {
                // Defaults
                $ownerAvatar     = $defaultUserAvatar ?? '';
                $commentUserName = $userData['username'] ?? ($userData['user_fullname'] ?? 'You');
                $commentText     = $comment;
                $commentTime     = $time;
                $commentIDLocal  = $commentID;
                $commentOwnerId  = $uid;
                $commentUpdatedTime = 0;
                $viewerId        = $uid;
                $viewerHasReported = false;
                // Update with DB values
                if (is_array($insertedRow)) {
                    $ownerAvatar     = (string)($insertedRow['avatar'] ?? $ownerAvatar);
                    $commentUserName = (string)($insertedRow['username'] ?? $commentUserName);
                    $commentText     = (string)($insertedRow['comment'] ?? $commentText);
                    $commentTime     = (int)   ($insertedRow['created_time'] ?? $commentTime);
                    $commentIDLocal  = (int)   ($insertedRow['c_id'] ?? $commentIDLocal);
                    $commentOwnerId  = (int)   ($insertedRow['uid_fk'] ?? $commentOwnerId);
                    $commentUpdatedTime = (int) ($insertedRow['updated_time'] ?? $commentUpdatedTime);
                }

                // Some themes expect this variable name
                $commentID = $commentIDLocal;

                if (!isset($iconPath) && isset($base_url)) {
                    $iconPath = rtrim($base_url, '/') . '/themes/' . $currentTheme . '/img/';
                }

                ob_start();
                include $filePath;
                $commentHtml = trim(ob_get_clean());
            }

            // ---- Mentions → Notifications (type: mention)
            $mentionedUsernames = [];
            if (isset($RL) && is_object($RL) && method_exists($RL, 'RL_ParseMentions')) {
                // Prefer DB-backed text if available
                $sourceText = (string)($insertedRow['comment'] ?? $comment);
                $mentionedUsernames = $RL->RL_ParseMentions($sourceText, 20);
            }

            // Resolve post owner once (needed for mention filtering and notifications)
            if (!isset($ownerId)) {
                $ownerId = 0;
            }
            if ($ownerId === 0 && isset($RL) && is_object($RL)) {
                if (method_exists($RL, 'RL_GetPostOwnerId')) {
                    $ownerId = (int) $RL->RL_GetPostOwnerId($postID);
                } elseif (method_exists($RL, 'RL_GetPostById')) {
                    $postRow = $RL->RL_GetPostById($postID);
                    if (is_array($postRow) && !empty($postRow)) {
                        $ownerId = (int) ($postRow['post_owner_id'] ?? 0);
                    }
                }
            }

            $mentionRecipients = [];
            $ownerMentioned = false;
            if (!empty($mentionedUsernames) && method_exists($RL, 'RL_UserIdsByUsernames')) {
                $map = $RL->RL_UserIdsByUsernames($mentionedUsernames);
                if (!empty($map)) {
                    $mentionRecipients = array_values($map);
                }
            }

            // Normalize mention recipients and de-duplicate; remove commenter, keep owner
            $ownerMentioned = false;
            if (!empty($mentionRecipients)) {
                $mentionRecipients = array_map('intval', $mentionRecipients);
                $mentionRecipients = array_values(array_unique($mentionRecipients));
                // Remove the commenter
                $mentionRecipients = array_values(array_filter($mentionRecipients, function ($id) use ($uid) {
                    return ($id > 0) && ($id !== $uid);
                }));
                // Was the owner mentioned?
                if ($ownerId > 0) {
                    $ownerMentioned = in_array($ownerId, $mentionRecipients, true);
                }
            }

            if (!empty($mentionRecipients) && method_exists($RL, 'RL_CreateMentionNotifications')) {
                try {
                    $RL->RL_CreateMentionNotifications($uid, $mentionRecipients, $commentID, $postID, $time);
                } catch (\Throwable $e) {
                    if (isset($RL) && is_object($RL) && is_callable([$RL, 'logError'])) {
                        $RL->logError('mention_notification error: ' . $e->getMessage());
                    } else {
                        error_log('mention_notification error: ' . $e->getMessage());
                    }
                }
            }

            if (isset($RL) && is_object($RL)) {
                try {
                    if ($ownerId > 0 && $ownerId !== $uid && empty($ownerMentioned)) {
                        if (method_exists($RL, 'RL_CreateCommentNotification')) {
                            $RL->RL_CreateCommentNotification($uid, $ownerId, $postID, $commentID, $time);
                        }
                    }
                } catch (\Throwable $e) {
                    if (isset($RL) && is_object($RL) && is_callable([$RL, 'logError'])) {
                        $RL->logError('post_comment notification error: ' . $e->getMessage());
                    } else {
                        error_log('post_comment notification error: ' . $e->getMessage());
                    }
                }
            }


            dz_json_response([
                'status'            => 'success',
                'message'           => customLang('comment_added', 'Comment added.'),
                'post_id'           => $postID,
                'user_id'           => $uid,
                'comment_id'        => $commentID,
                'created_time'      => (int)($insertedRow['created_time'] ?? $time),
                'username'          => (string)($insertedRow['username'] ?? ($userData['username'] ?? '')),
                'avatar'            => (string)($insertedRow['avatar'] ?? ($defaultUserAvatar ?? '')),
                'comment_text'      => (string)($insertedRow['comment'] ?? $comment),
                'total_comments'    => $total,
                'comment_sanitized' => htmlspecialchars((string)($insertedRow['comment'] ?? $comment), ENT_QUOTES, 'UTF-8'),
                'mentions'          => array_values($mentionedUsernames ?? []),
                'html'              => $commentHtml,
            ]);
            exit;
        } catch (\Throwable $e) {
            if (isset($RL) && is_object($RL) && is_callable([$RL, 'logError'])) {
                $RL->logError('add_comment error: ' . $e->getMessage());
            } else {
                error_log('add_comment error: ' . $e->getMessage());
            }
            dz_json_response([
                'status'  => 'error',
                'message' => customLang('error_server', 'A server error occurred. Please try again.'),
                'code'    => 'SERVER_ERROR',
            ]);
            exit;
        }
    }
    if ($p === 'get_comments') {
        try {
            $postID = (int)($_POST['post_id'] ?? 0);
            if ($postID <= 0) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => customLang('error_invalid_parameters', 'Invalid parameters.'),
                    'code'    => 'INVALID_PARAMETERS',
                ]);
                exit;
            }

            // Fetch all except the first 3 latest ones
            $rows = [];
            if (isset($RL) && is_object($RL) && method_exists($RL, 'RL_GetCommentsExceptLatestN')) {
                $rows = $RL->RL_GetCommentsExceptLatestN($postID, 3);
            }

            // Render HTML using your existing template
            $html = '';
            if (!empty($rows)) {
                $commentTemplate = __DIR__ . '/../themes/' . $currentTheme . '/layouts/newComment.php';
                if (is_file($commentTemplate)) {
                    ob_start();
                    foreach ($rows as $row) {
                        $ownerAvatar     = $row['avatar'];
                        $commentUserName = $row['username'];
                        $commentText     = $row['comment'];
                        $commentTime     = (int)$row['created_time'];
                        $commentID      = (int)$row['c_id'];
                        $commentOwnerId  = (int)($row['uid_fk'] ?? 0);
                        $commentUpdatedTime = isset($row['updated_time']) ? (int)$row['updated_time'] : 0;
                        // If template needs these:
                        $iconPath = $iconPath ?? (rtrim($base_url ?? '', '/') . '/themes/' . $currentTheme . '/img/');
                        $postID   = (int)$postID;

                        include $commentTemplate;
                    }
                    $html = trim(ob_get_clean());
                }
            }

            echo json_encode([
                'status'  => 'success',
                'message' => customLang('comments_loaded', 'Comments loaded.'),
                'html'    => $html, // may be empty if only 3 exist
            ]);
            exit;
        } catch (\Throwable $e) {
            if (isset($RL) && is_object($RL) && is_callable([$RL, 'logError'])) {
                $RL->logError('get_comments error: ' . $e->getMessage());
            } else {
                error_log('get_comments error: ' . $e->getMessage());
            }
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('error_server', 'A server error occurred. Please try again.'),
                'code'    => 'SERVER_ERROR',
            ]);
            exit;
        }
    }
    /* Like/Unlike a comment */
    if ($p === 'like_comment') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => customLang('invalid_request_method', 'Invalid request method.'),
            ]);
            exit;
        }

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => customLang('invalid_csrf_token'),
            ]);
            exit;
        }

        try {
            $uid       = isset($userID) ? (int)$userID : (int)($_SESSION['user_id'] ?? 0);
            $postID    = (int)($_POST['post_id'] ?? $_POST['id'] ?? 0);       // data-id (post)
            $commentID = (int)($_POST['comment_id'] ?? $_POST['comment'] ?? 0); // data-comment
            $itemType  = (string)($_POST['item_type'] ?? 'video');            // enum: video|image
            $time      = time();

            if ($uid <= 0 || $postID <= 0 || $commentID <= 0) {
                echo json_encode(['success' => false, 'message' => customLang('error_invalid_parameters')]);
                exit;
            }

            if (!isset($RL) || !method_exists($RL, 'RL_ToggleCommentLike')) {
                echo json_encode(['success' => false, 'message' => customLang('error_server_missing_method')]);
                exit;
            }

            $commentOwnerId = 0;
            if (isset($RL) && is_object($RL) && method_exists($RL, 'RL_GetCommentOwnerId')) {
                $commentOwnerId = (int) $RL->RL_GetCommentOwnerId($postID, $commentID);
            }

            [$likedNow, $likes] = $RL->RL_ToggleCommentLike($uid, $postID, $commentID, $time, $itemType);

            if ($commentOwnerId > 0 && $commentOwnerId !== $uid) {
                try {
                    if ($likedNow) {
                        if (method_exists($RL, 'RL_CreateCommentLikeNotification')) {
                            $RL->RL_CreateCommentLikeNotification($uid, $commentOwnerId, $postID, $commentID, $time);
                        }
                    } else {
                        if (method_exists($RL, 'RL_DeleteNotification')) {
                            // type=comment_like, object_id=commentID, parent_object_id=postID
                            $RL->RL_DeleteNotification($commentOwnerId, $uid, 'comment_like', $commentID, $postID);
                        }
                    }
                } catch (\Throwable $e) {
                    if (isset($RL) && is_callable([$RL, 'logError'])) {
                        $RL->logError('like_comment notification error: ' . $e->getMessage());
                    } else {
                        error_log('like_comment notification error: ' . $e->getMessage());
                    }
                    // Swallow notification errors — don't break the UI response
                }
            }

            echo json_encode([
                'success' => true,
                'likes'   => (int)$likes,
                'liked'   => (bool)$likedNow,
                'comment' => $commentID,
                'post_id' => $postID
            ]);
            exit;
        } catch (\Throwable $e) {
            if (isset($RL) && is_object($RL) && is_callable([$RL, 'logError'])) {
                $RL->logError('like_comment error: ' . $e->getMessage());
            } else {
                error_log('like_comment error: ' . $e->getMessage());
            }
            echo json_encode(['success' => false, 'message' => customLang('server_error')]);
            exit;
        }
    }
    if ($p === 'report_comment') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            dz_json_response([
                'status'  => 'error',
                'message' => customLang('invalid_request_method', 'Invalid request method.'),
                'code'    => 'INVALID_METHOD',
            ]);
            exit;
        }

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            dz_json_response([
                'status'  => 'error',
                'message' => customLang('invalid_csrf_token'),
                'code'    => 'INVALID_CSRF',
            ]);
            exit;
        }

        try {
            $uid         = isset($userID) ? (int)$userID : (int)($_SESSION['user_id'] ?? 0);
            $commentId   = (int)($_POST['comment_id'] ?? $_POST['comment'] ?? 0);
            $postIdHint  = (int)($_POST['post_id'] ?? $_POST['id'] ?? 0);
            $reasonRaw   = (string)($_POST['reason'] ?? '');

            if ($uid <= 0 || $commentId <= 0) {
                dz_json_response([
                    'status'  => 'error',
                    'message' => customLang('error_invalid_parameters', 'Invalid parameters.'),
                    'code'    => 'INVALID_PARAMETERS',
                ], 400);
                exit;
            }

            if (!isset($RL) || !is_object($RL) || !method_exists($RL, 'RL_ToggleCommentReport')) {
                dz_json_response([
                    'status'  => 'error',
                    'message' => customLang('error_server_missing_method', 'Not supported.'),
                    'code'    => 'MISSING_METHOD',
                ], 500);
                exit;
            }

            $commentPostId = 0;
            if (method_exists($RL, 'RL_GetCommentPostId')) {
                $commentPostId = (int) $RL->RL_GetCommentPostId($commentId);
            }
            if ($commentPostId <= 0 && $postIdHint > 0) {
                $commentPostId = $postIdHint;
            }
            if ($commentPostId <= 0) {
                dz_json_response([
                    'status'  => 'error',
                    'message' => customLang('error_not_found', 'Comment not found.'),
                    'code'    => 'NOT_FOUND',
                ], 404);
                exit;
            }

            $ownerId = 0;
            if (method_exists($RL, 'RL_GetCommentOwnerIdSimple')) {
                $ownerId = (int) $RL->RL_GetCommentOwnerIdSimple($commentId);
            } elseif (method_exists($RL, 'RL_GetCommentOwnerId')) {
                $ownerId = (int) $RL->RL_GetCommentOwnerId($commentPostId, $commentId);
            }
            if ($ownerId > 0 && $ownerId === $uid) {
                dz_json_response([
                    'status'  => 'error',
                    'message' => customLang('cannot_report_own_comment', 'You cannot report your own comment.'),
                    'code'    => 'OWN_COMMENT',
                ], 403);
                exit;
            }

            $reasonClean = trim($reasonRaw);
            if ($reasonClean !== '') {
                $reasonClean = preg_replace('/\s+/u', ' ', $reasonClean);
                if (mb_strlen($reasonClean, 'UTF-8') > 255) {
                    $reasonClean = mb_substr($reasonClean, 0, 255, 'UTF-8');
                }
            } else {
                $reasonClean = null;
            }

            $toggle = $RL->RL_ToggleCommentReport($commentId, $uid, $reasonClean, time());
            $reported = (bool) ($toggle['reported'] ?? false);

            $messageKey = $reported ? 'comment_reported' : 'comment_report_removed';
            $defaultMsg = $reported ? 'Comment reported.' : 'Comment report removed.';

            dz_json_response([
                'status'     => 'success',
                'message'    => customLang($messageKey, $defaultMsg),
                'reported'   => $reported,
                'comment_id' => $commentId,
                'post_id'    => $commentPostId,
            ]);
            exit;
        } catch (\Throwable $e) {
            if (isset($RL) && is_object($RL) && is_callable([$RL, 'logError'])) {
                $RL->logError('report_comment error: ' . $e->getMessage());
            } else {
                error_log('report_comment error: ' . $e->getMessage());
            }
            dz_json_response([
                'status'  => 'error',
                'message' => customLang('error_server', 'A server error occurred. Please try again.'),
                'code'    => 'SERVER_ERROR',
            ], 500);
            exit;
        }
    }
    if ($p === 'update_comment') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            dz_json_response([
                'status'  => 'error',
                'message' => customLang('invalid_request_method', 'Invalid request method.'),
                'code'    => 'INVALID_METHOD',
            ]);
            exit;
        }

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            dz_json_response([
                'status'  => 'error',
                'message' => customLang('invalid_csrf_token'),
                'code'    => 'INVALID_CSRF',
            ]);
            exit;
        }

        try {
            $uid        = isset($userID) ? (int)$userID : (int)($_SESSION['user_id'] ?? 0);
            $commentId  = (int)($_POST['comment_id'] ?? 0);
            $postIdHint = (int)($_POST['post_id'] ?? 0);
            $rawComment = (string)($_POST['comment'] ?? $_POST['comment_text'] ?? '');

            if ($uid <= 0 || $commentId <= 0) {
                dz_json_response([
                    'status'  => 'error',
                    'message' => customLang('error_invalid_parameters', 'Invalid parameters.'),
                    'code'    => 'INVALID_PARAMETERS',
                ], 400);
                exit;
            }

            if (!isset($RL) || !is_object($RL) || !method_exists($RL, 'RL_UpdateComment')) {
                dz_json_response([
                    'status'  => 'error',
                    'message' => customLang('error_server_missing_method', 'Not supported.'),
                    'code'    => 'MISSING_METHOD',
                ], 500);
                exit;
            }

            $clean = preg_replace('/\s+/u', ' ', trim($rawComment));
            if ($clean === '') {
                dz_json_response([
                    'status'  => 'error',
                    'message' => customLang('error_empty_comment', 'Comment cannot be empty.'),
                    'code'    => 'EMPTY_COMMENT',
                ], 400);
                exit;
            }
            $maxLen = 5000;
            if (mb_strlen($clean, 'UTF-8') > $maxLen) {
                $clean = mb_substr($clean, 0, $maxLen, 'UTF-8');
            }

            $commentRow = null;
            if (method_exists($RL, 'RL_GetCommentById')) {
                $commentRow = $RL->RL_GetCommentById($commentId);
            }
            if (!is_array($commentRow)) {
                dz_json_response([
                    'status'  => 'error',
                    'message' => customLang('error_not_found', 'Comment not found.'),
                    'code'    => 'NOT_FOUND',
                ], 404);
                exit;
            }

            $commentOwnerId = (int) ($commentRow['uid_fk'] ?? 0);
            if ($commentOwnerId !== $uid) {
                dz_json_response([
                    'status'  => 'error',
                    'message' => customLang('error_not_allowed', 'You are not allowed to edit this comment.'),
                    'code'    => 'NOT_ALLOWED',
                ], 403);
                exit;
            }

            $postId = (int) ($commentRow['item_id'] ?? $postIdHint);
            if ($postId <= 0 && method_exists($RL, 'RL_GetCommentPostId')) {
                $postId = (int) $RL->RL_GetCommentPostId($commentId);
            }

            $now = time();
            $updated = $RL->RL_UpdateComment($commentId, $uid, $clean, $now);
            if (!$updated) {
                dz_json_response([
                    'status'  => 'error',
                    'message' => customLang('comment_update_failed', 'Failed to update the comment.'),
                    'code'    => 'UPDATE_FAILED',
                ], 500);
                exit;
            }

            $updatedRow = $commentRow;
            if (method_exists($RL, 'RL_GetCommentById')) {
                $fresh = $RL->RL_GetCommentById($commentId);
                if (is_array($fresh)) {
                    $updatedRow = array_merge($commentRow, $fresh);
                }
            }

            $commentTemplate = __DIR__ . '/../themes/' . $currentTheme . '/layouts/newComment.php';
            $commentHtml = '';
            if (is_file($commentTemplate)) {
                $plainText          = (string) ($updatedRow['comment'] ?? $clean);
                $ownerAvatar        = (string) ($updatedRow['avatar'] ?? ($commentRow['avatar'] ?? ''));
                $commentUserName    = (string) ($updatedRow['username'] ?? '');
                $commentText        = $plainText;
                $commentTime        = (int) ($updatedRow['created_time'] ?? time());
                $commentUpdatedTime = (int) ($updatedRow['updated_time'] ?? 0);
                $commentID          = $commentId;
                $postID             = $postId;
                $viewerId           = $uid;
                $commentOwnerId     = (int) ($updatedRow['uid_fk'] ?? $uid);
                $viewerHasReported  = false;
                $viewerIsAdmin      = isset($isAdminUser) ? (bool) $isAdminUser : (bool) ($GLOBALS['isAdminUser'] ?? false);
                if (method_exists($RL, 'RL_HasUserReportedComment')) {
                    $viewerHasReported = (bool) $RL->RL_HasUserReportedComment($commentId, $uid);
                }

                if (!isset($iconPath) && isset($base_url)) {
                    $iconPath = rtrim($base_url, '/') . '/themes/' . $currentTheme . '/img/';
                }

                ob_start();
                include $commentTemplate;
                $commentHtml = trim(ob_get_clean());
            }

            $updatedTimestamp = (int) ($updatedRow['updated_time'] ?? 0);
            $plainRaw  = $clean;
            $plainSafe = htmlspecialchars($plainRaw, ENT_QUOTES, 'UTF-8');
            $commentHtmlSafe = nl2br($plainSafe);

            dz_json_response([
                'status'             => 'success',
                'message'            => customLang('comment_updated', 'Comment updated.'),
                'comment_updated'    => true,
                'comment_id'         => $commentId,
                'post_id'            => $postId,
                'comment_text_plain' => $plainRaw,
                'comment_text'       => $plainSafe,
                'comment_text_html'  => $commentHtmlSafe,
                'updated_time'       => $updatedTimestamp,
                'html'               => $commentHtml,
            ]);
            exit;
        } catch (\Throwable $e) {
            if (isset($RL) && is_object($RL) && is_callable([$RL, 'logError'])) {
                $RL->logError('update_comment error: ' . $e->getMessage());
            } else {
                error_log('update_comment error: ' . $e->getMessage());
            }
            dz_json_response([
                'status'  => 'error',
                'message' => customLang('error_server', 'A server error occurred. Please try again.'),
                'code'    => 'SERVER_ERROR',
            ], 500);
            exit;
        }
    }
    if ($p === 'delete_comment') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            dz_json_response([
                'status'  => 'error',
                'message' => customLang('invalid_request_method', 'Invalid request method.'),
                'code'    => 'INVALID_METHOD',
            ]);
            exit;
        }

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            dz_json_response([
                'status'  => 'error',
                'message' => customLang('invalid_csrf_token'),
                'code'    => 'INVALID_CSRF',
            ]);
            exit;
        }

        try {
            $uid        = isset($userID) ? (int)$userID : (int)($_SESSION['user_id'] ?? 0);
            $commentId  = (int)($_POST['comment_id'] ?? $_POST['comment'] ?? 0);
            $postIdHint = (int)($_POST['post_id'] ?? $_POST['id'] ?? 0);

            if ($uid <= 0 || $commentId <= 0) {
                dz_json_response([
                    'status'  => 'error',
                    'message' => customLang('error_invalid_parameters', 'Invalid parameters.'),
                    'code'    => 'INVALID_PARAMETERS',
                ], 400);
                exit;
            }

            if (!isset($RL) || !is_object($RL) || !method_exists($RL, 'RL_DeleteComment')) {
                dz_json_response([
                    'status'  => 'error',
                    'message' => customLang('error_server_missing_method', 'Not supported.'),
                    'code'    => 'MISSING_METHOD',
                ], 500);
                exit;
            }

            $isAdminViewer = isset($isAdminUser) ? (bool) $isAdminUser : (bool) ($GLOBALS['isAdminUser'] ?? false);

            $deleted = $RL->RL_DeleteComment($commentId, $uid, $isAdminViewer);

            if (empty($deleted['deleted'])) {
                $code = (string) ($deleted['error'] ?? 'delete_failed');
                $statusCode = 400;
                $messageKey = 'comment_delete_failed';
                $defaultMsg = 'Failed to delete the comment.';
                if ($code === 'not_allowed') {
                    $statusCode = 403;
                    $messageKey = 'comment_delete_not_allowed';
                    $defaultMsg = 'You are not allowed to delete this comment.';
                } elseif ($code === 'not_found') {
                    $statusCode = 404;
                    $messageKey = 'comment_delete_not_found';
                    $defaultMsg = 'Comment not found or already removed.';
                }
                dz_json_response([
                    'status'  => 'error',
                    'message' => customLang($messageKey, $defaultMsg),
                    'code'    => strtoupper($code),
                ], $statusCode);
                exit;
            }

            $postId = (int) ($deleted['post_id'] ?? $postIdHint);
            $total = null;
            if ($postId > 0 && method_exists($RL, 'RL_TotalComment')) {
                $total = (int) $RL->RL_TotalComment($postId);
            }

            dz_json_response([
                'status'          => 'success',
                'message'         => customLang('comment_deleted', 'Comment deleted.'),
                'deleted'         => true,
                'comment_id'      => $commentId,
                'post_id'         => $postId,
                'total_comments'  => $total,
                'comment_dom_id'  => 'comment-' . $commentId,
            ]);
            exit;
        } catch (\Throwable $e) {
            if (isset($RL) && is_object($RL) && is_callable([$RL, 'logError'])) {
                $RL->logError('delete_comment error: ' . $e->getMessage());
            } else {
                error_log('delete_comment error: ' . $e->getMessage());
            }
            dz_json_response([
                'status'  => 'error',
                'message' => customLang('error_server', 'A server error occurred. Please try again.'),
                'code'    => 'SERVER_ERROR',
            ], 500);
            exit;
        }
    }
    /*BookMark post*/
    if ($p === 'toggle_bookmark') {

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'status'  => 'error',
                'message' => customLang('invalid_request_method', 'Invalid request method.'),
                'code'    => 'INVALID_METHOD',
            ]);
            exit;
        }

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'status'  => 'error',
                'message' => customLang('invalid_csrf_token'),
                'code'    => 'INVALID_CSRF',
            ]);
            exit;
        }

        try {
            $uid     = isset($userID) ? (int) $userID : (int) ($_SESSION['user_id'] ?? 0);
            $postID  = (int) ($_POST['post_id'] ?? 0);
            $iType   = (string) ($_POST['item_type'] ?? 'image');
            $iType   = ($iType === 'video') ? 'video' : 'image'; // normalize

            if ($uid <= 0 || $postID <= 0) {
                echo json_encode([
                    'success' => false,
                    'status'  => 'error',
                    'message' => customLang('error_invalid_parameters', 'Invalid parameters.'),
                    'code'    => 'INVALID_PARAMETERS',
                ]);
                exit;
            }

            if (!isset($RL) || !is_object($RL) || !method_exists($RL, 'RL_ToggleBookmark')) {
                echo json_encode([
                    'success' => false,
                    'status'  => 'error',
                    'message' => customLang('error_server_missing_method', 'Server method not available.'),
                    'code'    => 'MISSING_METHOD',
                ]);
                exit;
            }

            $result = $RL->RL_ToggleBookmark($uid, $postID, $iType);
            $bookmarked = (bool) ($result['bookmarked'] ?? false);
            $total      = (int)  ($result['total'] ?? 0);

            echo json_encode([
                'success'    => true,
                'status'     => 'success',
                'bookmarked' => $bookmarked,
                'total'      => $total,
                'message'    => $bookmarked
                    ? customLang('bookmark_saved', 'Saved to your bookmarks.')
                    : customLang('bookmark_removed', 'Removed from your bookmarks.'),
            ]);
            exit;
        } catch (\Throwable $e) {
            if (isset($RL) && is_object($RL) && is_callable([$RL, 'logError'])) {
                $RL->logError('toggle_bookmark error: ' . $e->getMessage());
            } else {
                error_log('toggle_bookmark error: ' . $e->getMessage());
            }

            echo json_encode([
                'success' => false,
                'status'  => 'error',
                'message' => customLang('error_server', 'A server error occurred. Please try again.'),
                'code'    => 'SERVER_ERROR',
            ]);
            exit;
        }
    }
    // Toggle follow / unfollow
    if ($p === 'toggle_follow') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'status'  => 'error',
                'message' => customLang('invalid_request_method', 'Invalid request method.'),
            ]);
            exit;
        }

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'status'  => 'error',
                'message' => customLang('invalid_csrf_token'),
            ]);
            exit;
        }

        try {
            $actor = isset($userID) ? (int)$userID : 0;
            $target = (int)($_POST['target_id'] ?? 0);
            if ($actor <= 0 || $target <= 0 || $actor === $target) {
                echo json_encode(['success'=>false,'status'=>'error','message'=>customLang('error_invalid_parameters','Invalid parameters.')]);
                exit;
            }
            if (!isset($RL) || !is_object($RL) || !method_exists($RL, 'RL_ToggleFollow')) {
                echo json_encode(['success'=>false,'status'=>'error','message'=>customLang('error_server_missing_method','Server method not available.')]);
                exit;
            }
            $res = $RL->RL_ToggleFollow($actor, $target, time());
            $following = (bool)($res['following'] ?? false);
            $followers = (int)($res['followers'] ?? 0);
            $followingCount = (int)($res['following_count'] ?? 0);
            echo json_encode([
                'success' => true,
                'status'  => 'success',
                'following' => $following,
                'followers' => $followers,
                'following_count' => $followingCount,
                'message' => $following ? customLang('now_following','You are now following.') : customLang('now_unfollowed','Unfollowed successfully.')
            ]);
            exit;
        } catch (\Throwable $e) {
            if (isset($RL) && is_callable([$RL,'logError'])) { $RL->logError('toggle_follow error: '.$e->getMessage()); }
            echo json_encode(['success'=>false,'status'=>'error','message'=>customLang('error_server','A server error occurred. Please try again.')]);
            exit;
        }
    }
    // Get report reasons (configurable)
    if ($p === 'report_reasons') {
        try {
            // Prefer config value from i_site_configurations.report_reasons (JSON array)
            $reasonsCfg = [];
            if (isset($siteData) && is_array($siteData) && !empty($siteData['report_reasons'])) {
                $decoded = json_decode((string)$siteData['report_reasons'], true);
                if (is_array($decoded)) { $reasonsCfg = $decoded; }
            } elseif (isset($RL) && method_exists($RL, 'RL_configs')) {
                $cfg = (array)$RL->RL_configs();
                if (!empty($cfg['report_reasons'])) {
                    $decoded = json_decode((string)$cfg['report_reasons'], true);
                    if (is_array($decoded)) { $reasonsCfg = $decoded; }
                }
            }

            if (!$reasonsCfg) {
                // Default set (editable later from admin by updating report_reasons JSON)
                $reasonsCfg = [
                    ['code' => 'dislike', 'label' => "I just don't like it"],
                    ['code' => 'bullying', 'label' => 'Bullying or unwanted contact'],
                    ['code' => 'self_harm', 'label' => 'Suicide, self-injury or eating disorders'],
                    ['code' => 'violence', 'label' => 'Violence, hate or exploitation'],
                    ['code' => 'restricted_items', 'label' => 'Selling or promoting restricted items'],
                    ['code' => 'nudity', 'label' => 'Nudity or sexual activity'],
                    ['code' => 'scam', 'label' => 'Scam, fraud or spam'],
                    ['code' => 'false_info', 'label' => 'False information'],
                ];
            }

            echo json_encode(['status' => 'success', 'reasons' => $reasonsCfg]);
            exit;
        } catch (Throwable $e) {
            if (isset($RL) && is_callable([$RL, 'logError'])) { $RL->logError('report_reasons error: '.$e->getMessage()); }
            echo json_encode(['status' => 'error']);
            exit;
        }
    }
    // Visibility options (configurable)
    if ($p === 'get_visibility_options') {
        try {
            $opts = [];
            if (isset($siteData) && is_array($siteData) && !empty($siteData['visibility_options'])) {
                $decoded = json_decode((string)$siteData['visibility_options'], true);
                if (is_array($decoded)) { $opts = $decoded; }
            } elseif (isset($RL) && method_exists($RL, 'RL_configs')) {
                $cfg = (array)$RL->RL_configs();
                if (!empty($cfg['visibility_options'])) {
                    $decoded = json_decode((string)$cfg['visibility_options'], true);
                    if (is_array($decoded)) { $opts = $decoded; }
                }
            }
            if (!$opts) {
                $opts = [
                    ['code'=>'everyone','label'=>'Everyone'],
                    ['code'=>'followers','label'=>'Followers'],
                    ['code'=>'subscribers','label'=>'Subscribers'],
                    ['code'=>'locked','label'=>'Premium (locked)']
                ];
            }
            // If creator's subscription is closed, hide Subscribers option from picker
            $ud = isset($userData) && is_array($userData) ? $userData : ( (isset($RL) && method_exists($RL,'RL_GetUserDetails')) ? $RL->RL_GetUserDetails((int)($userID ?? 0)) : [] );
            $subNew = strtolower((string)($ud['subscription_status'] ?? ''));
            $subOld = strtolower((string)($ud['subscrition_status'] ?? ''));
            $isOpen = ($subNew === 'open') || ($subOld === 'active');
            if (!$isOpen) {
                $opts = array_values(array_filter($opts, static function($o){ return isset($o['code']) && strtolower((string)$o['code']) !== 'subscribers'; }));
            }
            echo json_encode(['status'=>'success','options'=>$opts]);
            exit;
        } catch (Throwable $e) {
            if (isset($RL) && is_callable([$RL,'logError'])) { $RL->logError('get_visibility_options error: '.$e->getMessage()); }
            echo json_encode(['status'=>'error']);
            exit;
        }
    }
    // Update post visibility (owner only)
    if ($p === 'update_post_visibility') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('invalid_request_method', 'Invalid request method.'),
            ]);
            exit;
        }

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('invalid_csrf_token'),
            ]);
            exit;
        }

        try {
            $uid = isset($userID) ? (int)$userID : 0;
            $postID = (int)($_POST['post_id'] ?? 0);
            $vis = trim((string)($_POST['visibility'] ?? ''));
            $allowed = ['everyone','followers','subscribers','locked'];
            if ($uid <= 0 || $postID <= 0 || !in_array($vis, $allowed, true)) {
                echo json_encode(['status'=>'error','message'=>customLang('error_invalid_parameters','Invalid parameters.')]);
                exit;
            }
            if (!isset($RL)) { echo json_encode(['status'=>'error','message'=>customLang('error_server','Server error.')]); exit; }
            $row = method_exists($RL,'RL_GetPostData') ? ($RL->RL_GetPostData($postID) ?? []) : [];
            $owner = (int)($row['post_owner_id'] ?? 0);
            $price = (int)($row['post_price'] ?? 0);
            if ($owner !== $uid) { echo json_encode(['status'=>'error','message'=>customLang('error_unauthorized','Unauthorized.')]); exit; }
            if ($vis === 'subscribers') {
                // Ensure creator has subscription open
                $ud = isset($userData) && is_array($userData) ? $userData : ( (isset($RL) && method_exists($RL,'RL_GetUserDetails')) ? $RL->RL_GetUserDetails((int)$uid) : [] );
                $subNew = strtolower((string)($ud['subscription_status'] ?? ''));
                $subOld = strtolower((string)($ud['subscrition_status'] ?? ''));
                $isOpen = ($subNew === 'open') || ($subOld === 'active');
                if (!$isOpen) {
                    echo json_encode([
                        'status'=>'error',
                        'code'  =>'SUBSCRIPTION_DISABLED',
                        'message'=>'Aboneler için gönderi paylaşamazsınız çünkü abonelik butonunu aktifleştirmediniz. Ayarlar > Abonelik ücretleri bölümünden etkinleştirin.'
                    ]);
                    exit;
                }
            }
            if ($vis === 'locked' && $price <= 0) {
                echo json_encode(['status'=>'error','code'=>'PRICE_REQUIRED','message'=>customLang('locked_price_required','Please set a price in Edit Post before enabling Premium.')]);
                exit;
            }
            if (method_exists($RL,'RL_UpdatePostVisibility')) { $RL->RL_UpdatePostVisibility($postID, $vis); }
            echo json_encode(['status'=>'success','visibility'=>$vis,'message'=>customLang('visibility_updated','Visibility updated.')]);
            exit;
        } catch (Throwable $e) {
            if (isset($RL) && is_callable([$RL,'logError'])) { $RL->logError('update_post_visibility error: '.$e->getMessage()); }
            echo json_encode(['status'=>'error','message'=>customLang('error_server','Server error.')]);
            exit;
        }
    }
    // Set price and lock the post in one step
    if ($p === 'update_post_premium') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('invalid_request_method', 'Invalid request method.'),
            ]);
            exit;
        }

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('invalid_csrf_token'),
            ]);
            exit;
        }

        try {
            $uid = isset($userID) ? (int)$userID : 0;
            $postID = (int)($_POST['post_id'] ?? 0);
            $price  = (int)($_POST['price'] ?? 0);
            $minPrice = (int)($premiumPostPriceMinimum ?? 1);
            $maxPrice = (int)($premiumPostPriceMaximum ?? 500);
            if ($uid <= 0 || $postID <= 0) { echo json_encode(['status'=>'error','message'=>customLang('error_invalid_parameters','Invalid parameters.')]); exit; }
            if ($price < $minPrice || $price > $maxPrice) {
                echo json_encode(['status'=>'error','code'=>'PRICE_RANGE','message'=>customLang('price_range_error', 'The price must be between {{premiumPostPriceMinimum}} and {{premiumPostPriceMaximum}}.')]);
                exit;
            }
            if (!isset($RL)) { echo json_encode(['status'=>'error','message'=>customLang('error_server','Server error.')]); exit; }
            $owner = method_exists($RL,'RL_GetPostOwnerId') ? $RL->RL_GetPostOwnerId($postID) : 0;
            if ($owner !== $uid) { echo json_encode(['status'=>'error','message'=>customLang('error_unauthorized','Unauthorized.')]); exit; }
            if (method_exists($RL,'RL_UpdatePostPriceAndLock')) { $RL->RL_UpdatePostPriceAndLock($postID, (int)$price); }
            echo json_encode(['status'=>'success','price'=>$price,'visibility'=>'locked','message'=>customLang('visibility_updated','Visibility updated.')]);
            exit;
        } catch (Throwable $e) {
            if (isset($RL) && is_callable([$RL,'logError'])) { $RL->logError('update_post_premium error: '.$e->getMessage()); }
            echo json_encode(['status'=>'error','message'=>customLang('error_server','Server error.')]);
            exit;
        }
    }
    // Edit post text
    if ($p === 'update_post_text') {
        try {
            $uid = isset($userID) ? (int)$userID : 0;
            $postID = (int)($_POST['post_id'] ?? 0);
            $textRaw = (string)($_POST['text'] ?? '');
            if ($uid <= 0 || $postID <= 0) { echo json_encode(['status'=>'error','message'=>customLang('error_invalid_parameters','Invalid parameters.')]); exit; }
            // Normalize text: trim, strip control chars, limit length
            $text = trim(preg_replace("/[\x00-\x08\x0B\x0C\x0E-\x1F]/", '', $textRaw));
            if (mb_strlen($text, 'UTF-8') > 2000) { $text = mb_substr($text, 0, 2000, 'UTF-8'); }

            if (!isset($RL)) { echo json_encode(['status'=>'error','message'=>customLang('error_server','Server error.')]); exit; }
            $owner = method_exists($RL,'RL_GetPostOwnerId') ? $RL->RL_GetPostOwnerId($postID) : 0;
            if ($owner !== $uid) { echo json_encode(['status'=>'error','message'=>customLang('error_unauthorized','Unauthorized.')]); exit; }
            if (method_exists($RL,'RL_UpdatePostText')) { $RL->RL_UpdatePostText($postID, $text); }

            // Return rendered HTML (simple nl2br). The frontend uses this to update DOM.
            $html = nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            echo json_encode(['status'=>'success','text'=>$text,'html'=>$html,'message'=>customLang('post_updated','Post updated.')]);
            exit;
        } catch (Throwable $e) {
            if (isset($RL) && is_callable([$RL,'logError'])) { $RL->logError('update_post_text error: '.$e->getMessage()); }
            echo json_encode(['status'=>'error','message'=>customLang('error_server','Server error.')]);
            exit;
        }
    }
    // Toggle comments on a post (owner only)
    if ($p === 'toggle_comments') {
        try {
            $uid = isset($userID) ? (int)$userID : 0;
            $postID = (int)($_POST['post_id'] ?? 0);
            if ($uid <= 0 || $postID <= 0) { echo json_encode(['status'=>'error','message'=>customLang('error_invalid_parameters','Invalid parameters.')]); exit; }
            if (!isset($RL)) { echo json_encode(['status'=>'error','message'=>customLang('error_server','Server error.')]); exit; }
            // Check ownership + current status
            $row = method_exists($RL,'RL_GetPostData') ? ($RL->RL_GetPostData($postID) ?? []) : [];
            if ((int)($row['post_owner_id'] ?? 0) !== $uid) { echo json_encode(['status'=>'error','message'=>customLang('error_unauthorized','Unauthorized.')]); exit; }
            $curr = strtolower((string)($row['comment_status'] ?? 'on')) === 'on' ? 'on' : 'off';
            $next = ($curr === 'on') ? 'off' : 'on';
            if (method_exists($RL,'RL_TogglePostCommentStatus')) { $RL->RL_TogglePostCommentStatus($postID, $next); }

            $label = ($next === 'on') ? 'Disable comments' : 'Enable comments';
            $composerHtml = '';
            if ($next === 'on') {
                $composerPath = __DIR__ . '/../themes/' . $currentTheme . '/layouts/commentComposer.php';
                if (is_file($composerPath)) {
                    ob_start();
                    include $composerPath;
                    $composerHtml = trim((string) ob_get_clean());
                }
            }
            echo json_encode(['status'=>'success','comment_status'=>$next,'label'=>$label,'html'=>$composerHtml]);
            exit;
        } catch (Throwable $e) {
            if (isset($RL) && is_callable([$RL,'logError'])) { $RL->logError('toggle_comments error: '.$e->getMessage()); }
            echo json_encode(['status'=>'error','message'=>customLang('error_server','Server error.')]);
            exit;
        }
    }
    // Delete post (owner only)
    if ($p === 'delete_post') {
        try {
            $uid = isset($userID) ? (int)$userID : 0;
            $postID = (int)($_POST['post_id'] ?? 0);
            if ($uid <= 0 || $postID <= 0) { echo json_encode(['status'=>'error','message'=>customLang('error_invalid_parameters','Invalid parameters.')]); exit; }
            if (!isset($RL)) { echo json_encode(['status'=>'error','message'=>customLang('error_server','Server error.')]); exit; }

            // Verify owner & gather type for cleanup
            $row = method_exists($RL,'RL_GetPostData') ? ($RL->RL_GetPostData($postID) ?? []) : [];
            if ((int)($row['post_owner_id'] ?? 0) !== $uid) { echo json_encode(['status'=>'error','message'=>customLang('error_unauthorized','Unauthorized.')]); exit; }
            $pType = (string)($row['post_type'] ?? 'image');
            $pFilesRaw = (string)($row['post_file'] ?? '');

            // Prefer deterministic deletion using i_post_media if available
            try {
                $root = realpath(__DIR__ . '/..'); // ReelsProject root
                $toDelete = [];
                // Collect media paths via helper
                $rowsM = method_exists($RL,'RL_GetPostMediaPaths') ? $RL->RL_GetPostMediaPaths($postID) : [];
                foreach ($rowsM as $pp) { $pp = ltrim((string)$pp, '/'); if ($pp !== '') { $toDelete[] = $pp; } }

                if ($pType === 'image') {
                    $parts = array_filter(array_map('trim', explode(',', $pFilesRaw)), static function($s){ return $s !== ''; });
                    foreach ($parts as $rel) { $toDelete[] = $rel; }
                } else { // video: try to delete the mp4 and possible derived thumbs in reels/files
                    if ($pFilesRaw !== '') {
                        $toDelete[] = $pFilesRaw; // original (usually uploads/reels/.../file.mp4)
                        $ext = strtolower(pathinfo($pFilesRaw, PATHINFO_EXTENSION));
                        $name = pathinfo($pFilesRaw, PATHINFO_FILENAME);
                        $dir  = str_replace('\\', '/', dirname($pFilesRaw));
                        $origAbs = $root . '/' . ltrim($dir . '/' . $name . '.' . $ext, '/');
                        $origAbs = @realpath($origAbs) ?: $origAbs;
                        $origSize = @is_file($origAbs) ? @filesize($origAbs) : 0;
                        $origMtime = @is_file($origAbs) ? @filemtime($origAbs) : 0;
                        // Same dir thumbnails
                        foreach (['png','jpg','jpeg','webp'] as $th) {
                            $toDelete[] = $dir . '/' . $name . '.' . $th;
                        }
                        // Mirror under /files/ (same date/filename pattern)
                        $altBase = str_replace('/reels/', '/files/', $dir . '/' . $name);
                        // mp4 in files (some setups duplicate)
                        foreach (['mp4','mov','webm','mkv'] as $vext) { $toDelete[] = $altBase . '.' . $vext; }
                        foreach (['png','jpg','jpeg','webp'] as $th) { $toDelete[] = $altBase . '.' . $th; }

                        // Targeted heuristic: some builds save different name like reel_*_<index>.mp4 in uploads/files/<date>/
                        // Extract date folder, clip index and timestamp from original filename pattern *_reels_blur_trim_<idx>_<ts>.mp4
                        $dateFolder = basename($dir); // e.g. 2025-09-10
                        $filesDirAbs = $root . '/uploads/files/' . $dateFolder;
                        if (@is_dir($filesDirAbs)) {
                            $clipIndex = null; $ts = null;
                            if (preg_match('/_reels_blur_trim_(\d+)_([0-9]+)/i', $name, $mm)) {
                                $clipIndex = $mm[1];
                                $ts = (int)$mm[2];
                            }
                            if ($clipIndex !== null) {
                                foreach (glob($filesDirAbs . '/reel_*_' . $clipIndex . '.mp4') ?: [] as $cand) {
                                    $cSize = @filesize($cand) ?: 0;
                                    $cTime = @filemtime($cand) ?: 0;
                                    $sizeClose = ($origSize > 0) ? (abs($cSize - $origSize) <= max(1048576, (int)($origSize * 0.2))) : false;
                                    $timeClose = ($ts > 0 && $cTime > 0) ? (abs($cTime - $ts) <= 900) : false; // ±15min
                                    if ($sizeClose || $timeClose) {
                                        $rel = 'uploads/files/' . $dateFolder . '/' . basename($cand);
                                        $toDelete[] = $rel;
                                        // also try same-name thumb in files
                                        $baseNoExt = substr($rel, 0, strrpos($rel, '.'));
                                        foreach (['png','jpg','jpeg','webp'] as $th) { $toDelete[] = $baseNoExt . '.' . $th; }
                                    }
                                }
                            }

                            // Also try matching by UID from final name pattern *_trim_<uid>_<ts>.mp4
                            $uidMatch = null; $tsMatch = null;
                            if (preg_match('/_trim_(\d+)_([0-9]+)/i', $name, $muid)) {
                                $uidMatch = $muid[1];
                                $tsMatch  = (int)$muid[2];
                            }
                            if ($uidMatch !== null) {
                                foreach (glob($filesDirAbs . '/reel_*_' . $uidMatch . '.mp4') ?: [] as $cand) {
                                    $cSize = @filesize($cand) ?: 0;
                                    $cTime = @filemtime($cand) ?: 0;
                                    $sizeClose = ($origSize > 0) ? (abs($cSize - $origSize) <= max(1048576, (int)($origSize * 0.2))) : false;
                                    $timeClose = ($tsMatch > 0 && $cTime > 0) ? (abs($cTime - $tsMatch) <= 3600) : false; // ±60min
                                    if ($sizeClose || $timeClose) {
                                        $rel = 'uploads/files/' . $dateFolder . '/' . basename($cand);
                                        $toDelete[] = $rel;
                                        $baseNoExt = substr($rel, 0, strrpos($rel, '.'));
                                        foreach (['png','jpg','jpeg','webp'] as $th) { $toDelete[] = $baseNoExt . '.' . $th; }
                                    }
                                }
                            }
                        }
                    }
                }
                // De-duplicate and attempt to remove safely
                $seen = [];
                foreach ($toDelete as $rel) {
                    $rel = trim($rel);
                    if ($rel === '' || isset($seen[$rel])) { continue; }
                    $seen[$rel] = true;
                    $safeRel = ltrim(str_replace(['..\\','../','\\'], ['','','/'], $rel), '/');
                    if ($safeRel === '') { continue; }
                    if (function_exists('storage_delete')) {
                        try { storage_delete($safeRel); } catch (Throwable $____) {}
                    }
                    $path = $root . '/' . $safeRel;
                    $rp = @realpath($path);
                    if ($rp === false) { $rp = $path; }
                    if (strpos($rp, $root) === 0 && @is_file($rp)) { @unlink($rp); }
                }
                // Remove deterministic media rows already handled by RL_DeletePostCascade
            } catch (Throwable $__e) { /* ignore file errors */ }

            // Finally delete the post and cascades
            if (method_exists($RL,'RL_DeletePostCascade')) { $RL->RL_DeletePostCascade($postID, $pType); }
            echo json_encode(['status'=>'success','message'=>customLang('post_deleted','Post deleted.')]);
            exit;
        } catch (Throwable $e) {
            if (isset($RL) && is_callable([$RL,'logError'])) { $RL->logError('delete_post error: '.$e->getMessage()); }
            echo json_encode(['status'=>'error','message'=>customLang('error_server','Server error.')]);
            exit;
        }
    }
    // Report / Unreport Post
    if ($p === 'report_post') {

        try {
            if (!isset($RL) || !method_exists($RL, 'getDb')) {
                echo json_encode(['success' => false, 'status' => 'error', 'message' => customLang('error_server', 'Server error.')]);
                exit;
            }
            $db = $RL->getDb();
            $uid    = isset($userID) ? (int)$userID : 0;
            $postID = (int)($_POST['post_id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($uid <= 0 || $postID <= 0) {
                echo json_encode(['success' => false, 'status' => 'error', 'message' => customLang('error_invalid_parameters', 'Invalid parameters.')]);
                exit;
            }

            // Validate post existence
            $ownerId = method_exists($RL,'RL_GetPostOwnerId') ? $RL->RL_GetPostOwnerId($postID) : 0;
            if ($ownerId <= 0) {
                echo json_encode(['success' => false, 'status' => 'error', 'message' => customLang('error_not_found', 'Post not found.')]);
                exit;
            }
            // Toggle via helper
            $res = method_exists($RL,'RL_TogglePostReport') ? $RL->RL_TogglePostReport($postID, $uid, ($reason !== '' ? $reason : null), time()) : ['reported'=>false];
            $reported = (bool)($res['reported'] ?? false);
            if ($reported) {
                echo json_encode([
                    'success'  => true,
                    'status'   => 'success',
                    'reported' => true,
                    'message'  => customLang('post_reported', 'Post reported. Thank you.'),
                ]);
            } else {
                echo json_encode([
                    'success'  => true,
                    'status'   => 'success',
                    'reported' => false,
                    'message'  => customLang('report_removed', 'Report removed.'),
                ]);
            }
            exit;
        } catch (Throwable $e) {
            if (isset($RL) && is_object($RL) && is_callable([$RL, 'logError'])) {
                $RL->logError('report_post error: ' . $e->getMessage());
            } else {
                error_log('report_post error: ' . $e->getMessage());
            }
            echo json_encode(['success' => false, 'status' => 'error', 'message' => customLang('error_server', 'Server error.')]);
            exit;
        }
    }
    // Infinite scroll for main feed (related-visible posts)
    if ($p === 'feed_more') {
        try {
            $uid    = (int)($userID ?? 0);
            $offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;
            $limit  = isset($_POST['limit'])  ? (int) $_POST['limit']  : 20;
            $filter = isset($_POST['filter']) ? strtolower(trim((string) $_POST['filter'])) : 'all';
            if ($filter === 'reels') { $filter = 'video'; }
            if (!in_array($filter, ['all', 'video', 'image', 'podcast'], true)) {
                $filter = 'all';
            }
            if ($uid <= 0) { echo json_encode(['status'=>'error','message'=>'AUTH']); exit; }
            if ($limit < 5)  { $limit = 5;  }
            if ($limit > 40) { $limit = 40; }
            if (!isset($RL) || !method_exists($RL, 'RL_FeedRelatedVisible')) { echo json_encode(['status'=>'error','message'=>'NOFN']); exit; }

            $postTypeFilter = $filter === 'all' ? null : $filter;
            $rows = $RL->RL_FeedRelatedVisible($uid, $limit + 1, $offset, $postTypeFilter);
            $hasMore = count($rows) > $limit;
            if ($hasMore) { $rows = array_slice($rows, 0, $limit); }

            $layout = __DIR__ . '/../themes/' . $currentTheme . '/layouts/post_view.php';
            if (!is_file($layout)) { echo json_encode(['status'=>'error','message'=>'TPL']); exit; }
            $viewMode = 'feed';
            $__externalPosts = $rows;
            $__suppressEmptyFeedMessage = true;
            while (ob_get_level() > 0) { ob_end_clean(); }
            ob_start();
            include $layout;
            $html = trim(ob_get_clean());

            echo json_encode([
                'status'      => 'ok',
                'html'        => $html,
                'has_more'    => $hasMore,
                'next_offset' => $offset + count($rows)
            ]);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>'SERVER']);
        }
        exit;
    }

    // Load more purchased premium posts for /premium page (infinite scroll)
    if ($p === 'premium_more') {
        try {
            $uid    = (int)($userID ?? 0);
            $offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;
            $limit  = isset($_POST['limit'])  ? (int) $_POST['limit']  : 20;
            if ($uid <= 0) { echo json_encode(['status'=>'error','message'=>'AUTH']); exit; }
            if ($limit < 5)  { $limit = 5;  }
            if ($limit > 40) { $limit = 40; }
            if (!isset($RL) || !method_exists($RL, 'RL_GetPurchasedPremiumFeed')) { echo json_encode(['status'=>'error','message'=>'NOFN']); exit; }

            $rows = $RL->RL_GetPurchasedPremiumFeed($uid, $limit + 1, $offset);
            $hasMore = count($rows) > $limit;
            if ($hasMore) { $rows = array_slice($rows, 0, $limit); }

            // Render using post_view.php with $__externalPosts and suppress empty message
            $layout = __DIR__ . '/../themes/' . $currentTheme . '/layouts/post_view.php';
            if (!is_file($layout)) { echo json_encode(['status'=>'error','message'=>'TPL']); exit; }
            $viewMode = 'feed';
            $__externalPosts = $rows;
            $__suppressEmptyFeedMessage = true;
            while (ob_get_level() > 0) { ob_end_clean(); }
            ob_start();
            include $layout;
            $html = trim(ob_get_clean());

            echo json_encode([
                'status' => 'ok',
                'html'   => $html,
                'has_more' => $hasMore,
                'next_offset' => $offset + count($rows)
            ]);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>'SERVER']);
        }
        exit;
    }
    /* Get Tips BOX */
    if($p === 'sendTip') {
        $paymentHandler->handleSendTip();
        return;
    }

    // Live/Profile Thanks: open tips by recipient user id
    if ($p === 'sendTipUser') {
        $paymentHandler->handleSendTipUser();
        return;
    }
    if ($p === 'profile_more') {
        try {
            $targetId   = isset($_POST['target_id']) ? (int) $_POST['target_id'] : 0;
            $filter     = isset($_POST['filter']) ? (string) $_POST['filter'] : 'all';
            $view       = isset($_POST['view']) && $_POST['view'] === 'feed' ? 'feed' : 'grid';
            $cursorTime = isset($_POST['cursor_time']) ? (int) $_POST['cursor_time'] : 0;
            $cursorId   = isset($_POST['cursor_id'])   ? (int) $_POST['cursor_id']   : 0;
            $limit      = isset($_POST['limit']) ? (int) $_POST['limit'] : 25;

            if ($limit < 5)  { $limit = 5;  }
            if ($limit > 50) { $limit = 50; }
            if ($targetId <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'INVALID_TARGET']);
                exit;
            }

            $startLayoutIn = isset($_POST['start_layout']) ? strtolower(trim((string)$_POST['start_layout'])) : 'right';
            $startLayout   = ($startLayoutIn === 'left') ? 'left' : 'right';

            if (!isset($RL) || !is_object($RL) || !method_exists($RL, 'RL_ProfileGetPageByCursor') || !method_exists($RL, 'RL_ProfileRenderToString') || !method_exists($RL, 'RL_ProfileRenderListToString')) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'MISSING_METHOD']);
                exit;
            }
            if ($view === 'feed' && !method_exists($RL, 'RL_ProfileGetFeedByCursor')) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'MISSING_FEED_METHOD']);
                exit;
            }

            if ($filter === 'podcasts') {
                echo json_encode([
                    'status'            => 'ok',
                    'html'              => '',
                    'has_more'          => false,
                    'tile_count'        => 0,
                    'next_cursor'       => ['time' => 0, 'id' => 0],
                    'next_start_layout' => $startLayout,
                ]);
                exit;
            }

            if ($view === 'feed') {
                $result = $RL->RL_ProfileGetFeedByCursor($targetId, $cursorTime, $cursorId, $limit, $filter);
                $posts = is_array($result['posts'] ?? null) ? $result['posts'] : [];
                $tileCount = count($posts);
                $html = '';
                if ($tileCount > 0) {
                    ob_start();
                    $__externalPosts = $posts;
                    $__suppressEmptyFeedMessage = true;
                    $viewMode = 'feed';
                    include __DIR__ . '/../themes/default/layouts/post_view.php';
                    $html = (string) ob_get_clean();
                    unset($__externalPosts, $__suppressEmptyFeedMessage, $viewMode);
                }
                echo json_encode([
                    'status'            => 'ok',
                    'html'              => $html,
                    'has_more'          => !empty($result['hasMore']) && ($tileCount > 0),
                    'next_cursor'       => $result['nextCursor'],
                    'next_start_layout' => $startLayout,
                    'tile_count'        => $tileCount,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $result = $RL->RL_ProfileGetPageByCursor($targetId, $cursorTime, $cursorId, $limit, $filter);
            $tileCount = 0; // count of non-ghost tiles rendered in this page
            if ($filter === 'all' || $filter === '' || $filter === null) {
                // All => Explore-style mosaic grid
                // Count real tiles
                foreach ($result['blocks'] as $block) {
                    for ($i = 0; $i < 5; $i++) {
                        if (!isset($block[$i]) || !is_array($block[$i])) { continue; }
                        if (($block[$i]['type'] ?? 'ghost') !== 'ghost') { $tileCount++; }
                    }
                }
                $html = $RL->RL_ProfileRenderToString($result['blocks'], $startLayout);
            } elseif ($filter === 'images') {
                // Other tabs => Images-style flat list. Flatten blocks to a simple ordered list of tiles.
                $tiles = [];
                foreach ($result['blocks'] as $block) {
                    for ($i = 0; $i < 5; $i++) {
                        if (!isset($block[$i]) || !is_array($block[$i])) { continue; }
                        $t = $block[$i];
                        if (($t['type'] ?? 'ghost') === 'ghost') { continue; }
                        $tiles[] = $t;
                    }
                }
                $tileCount = count($tiles);
                $html = $RL->RL_ProfileRenderListToString($tiles, 'images');
            } elseif ($filter === 'reels' || $filter === 'videos' || $filter === 'video') {
                // Reels tab => Reels-style list (thumbnails, different tile class)
                $tiles = [];
                foreach ($result['blocks'] as $block) {
                    for ($i = 0; $i < 5; $i++) {
                        if (!isset($block[$i]) || !is_array($block[$i])) { continue; }
                        $t = $block[$i];
                        if (($t['type'] ?? 'ghost') === 'ghost') { continue; }
                        $tiles[] = $t;
                    }
                }
                $tileCount = count($tiles);
                $html = $RL->RL_ProfileRenderListToString($tiles, 'reels');
            } elseif ($filter === 'premium' || $filter === 'subscriber' || $filter === 'subscribers') {
                // Premium & Subscribers => Images-style square grid (no tall area)
                $tiles = [];
                foreach ($result['blocks'] as $block) {
                    for ($i = 0; $i < 5; $i++) {
                        if (!isset($block[$i]) || !is_array($block[$i])) { continue; }
                        $t = $block[$i];
                        if (($t['type'] ?? 'ghost') === 'ghost') { continue; }
                        $tiles[] = $t;
                    }
                }
                $tileCount = count($tiles);
                $html = $RL->RL_ProfileRenderListToString($tiles, 'images');
            } else {
                // Fallback -> images-style
                $tiles = [];
                foreach ($result['blocks'] as $block) {
                    for ($i = 0; $i < 5; $i++) {
                        if (!isset($block[$i]) || !is_array($block[$i])) { continue; }
                        $t = $block[$i];
                        if (($t['type'] ?? 'ghost') === 'ghost') { continue; }
                        $tiles[] = $t;
                    }
                }
                $tileCount = count($tiles);
                $html = $RL->RL_ProfileRenderListToString($tiles, 'images');
            }

            $blockCount = is_array($result['blocks'] ?? null) ? count($result['blocks']) : 0;
            $nextStartLayout = ($blockCount % 2 === 0) ? $startLayout : ($startLayout === 'right' ? 'left' : 'right');

            // If no real tiles came back, force has_more=false to prevent runaway infinite appends
            $hasMore = !empty($result['hasMore']) && ($tileCount > 0);
            echo json_encode([
                'status'      => 'ok',
                'html'        => $html,
                'has_more'    => $hasMore,
                'next_cursor' => $result['nextCursor'],
                'next_start_layout' => $nextStartLayout,
                'tile_count'  => $tileCount,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            if (isset($RL) && is_object($RL) && is_callable([$RL, 'logError'])) { $RL->logError('profile_more error: ' . $e->getMessage()); }
            echo json_encode(['status' => 'error', 'message' => 'LOAD_FAILED']);
        }
    }
    /* Get Tips BOX */
    if ($p === 'subscribeMe') {
        $paymentHandler->handleSubscribeMeModal();
        return;
    }

    if ($p === 'subscription_update_payment') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!isset($userID) || (int) $userID <= 0) {
                echo json_encode(['status' => 'error', 'message' => customLang('please_login_first', 'Please login first.')]);
                exit;
            }

            $buyerId = (int) $userID;
            $subscriptionIdUpdate = (int) ($_POST['subscription_id'] ?? 0);
            $creatorIdUpdate = (int) ($_POST['creator_id'] ?? 0);
            $db = (isset($RL) && method_exists($RL, 'getDb')) ? $RL->getDb() : null;

            if ($subscriptionIdUpdate > 0 && $db instanceof PDO) {
                $st = $db->prepare('SELECT id, buyer_id, recipient_id FROM i_subscription_payments WHERE id = :id LIMIT 1');
                $st->execute([':id' => $subscriptionIdUpdate]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$row || (int) ($row['buyer_id'] ?? 0) !== $buyerId) {
                    echo json_encode(['status' => 'error', 'message' => customLang('error_invalid_parameters', 'Invalid parameters.')]);
                    exit;
                }
                $creatorIdUpdate = (int) ($row['recipient_id'] ?? 0);
            }

            if ($creatorIdUpdate <= 0 && !($subscriptionIdUpdate > 0)) {
                echo json_encode(['status' => 'error', 'message' => customLang('error_invalid_parameters', 'Invalid parameters.')]);
                exit;
            }

            $redirectBase = isset($base_url) ? rtrim((string) $base_url, '/') : '';
            $redirectUrl = ($redirectBase !== '' ? $redirectBase : '') . '/settings?tab=payments';
            $extraParams = [];
            if ($subscriptionIdUpdate > 0) { $extraParams['subscription_id'] = $subscriptionIdUpdate; }
            if ($creatorIdUpdate > 0) { $extraParams['creator_id'] = $creatorIdUpdate; }
            if ($extraParams) {
                $redirectUrl .= '&' . http_build_query(array_merge(['focus' => 'subscriptions'], $extraParams));
            }

            $message = customLang('settings_subscription_update_redirect', 'Redirecting you to payment settings to update your billing method.');
            echo json_encode(['status' => 'success', 'redirect_url' => $redirectUrl, 'message' => $message]);
            exit;
        } catch (Throwable $e) {
            if (isset($RL) && method_exists($RL, 'logError')) {
                $RL->logError('subscription_update_payment failed: ' . $e->getMessage());
            }
            echo json_encode(['status' => 'error', 'message' => customLang('error_server', 'A server error occurred. Please try again.')]);
            exit;
        }
    }

    if ($p === 'cancelSubscription' || $p === 'subscription_cancel') {
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!function_exists('checkCsrfToken') || !checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('invalid_csrf_token'),
            ]);
            exit;
        }

        $paymentHandler->handleSubscriptionCancel();
        return;
    }

    /* Get Purchase BOX */
    if($p === 'purchasePost') {
        $paymentHandler->handlePurchaseModal();
        return;
    }

    /* Get Full Post (renders a popup HTML and returns JSON) */
    if ($p === 'getFullPost') {
        try {
            // Accept both "post_id" and legacy "post"
            $postIdParam = (int) ($_POST['post_id'] ?? ($_POST['post'] ?? 0));
            $usernameParam = '';
            if ($postIdParam <= 0) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => customLang('error_invalid_parameters', 'Invalid parameters.'),
                    'code'    => 'INVALID_PARAMETERS',
                ]);
                exit;
            }

            // Path to theme popup template
            $filePath = __DIR__ . '/../themes/' . $currentTheme . '/popUps/fullPost.php';
            if (!is_file($filePath)) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => customLang('requested_content_not_found', 'Requested content not found.'),
                    'code'    => 'NOT_FOUND',
                ]);
                exit;
            }

            // Provide variables your template may rely on
            // - $postIdParam: numeric id of the post to render
            // - $iconPath: theme icons (if template needs it)
            if (!isset($iconPath) && isset($base_url)) {
                $iconPath = rtrim($base_url, '/') . '/themes/' . $currentTheme . '/img/';
            }

            // Ensure no stray output pollutes JSON
            while (ob_get_level() > 0) { ob_end_clean(); }

            ob_start();
            include $filePath; // This file should only echo HTML
            $html = trim(ob_get_clean());

            echo json_encode([
                'status' => 'success',
                'html'   => $html,
                'post_id'=> $postIdParam
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (\Throwable $e) {
            if (isset($RL) && is_object($RL) && is_callable([$RL, 'logError'])) {
                $RL->logError('getFullPost error: ' . $e->getMessage());
            } else {
                error_log('getFullPost error: ' . $e->getMessage());
            }
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('error_server', 'A server error occurred. Please try again.'),
                'code'    => 'SERVER_ERROR',
            ]);
            exit;
        }
    }
    if ($p === 'add_view') {

        try {
            $postId  = (int)($_POST['post_id'] ?? 0);
            $source  = (string)($_POST['source'] ?? 'feed');
            $dwellMs = (int)($_POST['dwell_ms'] ?? 0);
            $ratio   = (float)($_POST['visible_ratio'] ?? 0);

            $viewerUserId = isset($userID) ? (int)$userID : (int)($_SESSION['user_id'] ?? 0);
            if ($viewerUserId <= 0) { $viewerUserId = null; }

            if (!isset($_SESSION['guest_fp'])) {
                $ua  = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
                $ip  = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
                $ip2 = preg_replace('/\.\d+$/', '.0', $ip);
                $_SESSION['guest_fp'] = substr(hash('sha256', session_id().'|'.$ua.'|'.$ip2), 0, 32);
            }
            $guestSessHex = $viewerUserId ? null : $_SESSION['guest_fp'];

            if (!isset($_SESSION['view_dedupe'])) {
                $_SESSION['view_dedupe'] = [];
            }
            $DEDUP_TTL = 600;
            $nowTs = time();
            $lastTs = (int)($_SESSION['view_dedupe'][$postId] ?? 0);

            if ($lastTs && ($nowTs - $lastTs) < $DEDUP_TTL) {
                echo json_encode(['success' => false, 'reason' => 'session_dedupe']);
                exit;
            }
            $_SESSION['view_dedupe'][$postId] = $nowTs;
            if (method_exists($RL, 'RL_AddUniqueViewWithReason')) {
                $res = $RL->RL_AddUniqueViewWithReason($postId, $viewerUserId, $guestSessHex, $source, $dwellMs, $ratio);
                echo json_encode($res);
            } else {
                $ok = $RL->RL_AddUniqueView($postId, $viewerUserId, $guestSessHex, $source, $dwellMs, $ratio);
                echo json_encode(['success' => $ok]);
            }
            exit;
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'bad_request']);
            exit;
        }
    }
    /* Get More Image */
    if ($p === 'getMoreImage') {
        try {
            $limit  = isset($_POST['limit'])  ? (int)$_POST['limit']  : 10;
            $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;

            if ($limit < 1)  { $limit = 10; }
            if ($offset < 0) { $offset = 0; }

            // Theme icon path for badges
            $iconPath = $iconPath ?? (rtrim($base_url ?? '', '/') . '/themes/' . $currentTheme . '/img/');

            $items = $RL->RL_FeedAllImages((int)$userID, $limit, $offset);

            // Render small HTML tiles (same structure as images.php list items)
            ob_start();
            foreach ($items as $post) {
                $postId        = (int)($post['post_id'] ?? 0);
                $firstImage    = $post['first_image'] ?? ($post['post_file'] ?? '');
                if (function_exists('storage_resolve_media_url')) {
                    $postFilePath = storage_resolve_media_url((string)$firstImage, $base_url ?? '');
                } else {
                    $postFilePath = (string)($base_url ?? '') . ltrim((string)$firstImage, '/');
                }
                $hasMulti      = !empty($post['has_multiple_images']);
                $postViews     = (int)($post['post_views'] ?? 0);
                $totalPostLike = (int)$RL->RL_CountLikes($postId);
                $totalComment  = (int)$RL->RL_TotalComment($postId);
                $moreImage = __DIR__ . '/../themes/' . $currentTheme . '/layouts/moreImage.php';
                include $moreImage;
            }
            $html = trim((string)ob_get_clean());

            $count    = count($items);
            $next     = $offset + $count;
            $hasMore  = $count === $limit;

            echo json_encode([
                'status'      => 'ok',
                'html'        => $html,
                'next_offset' => $next,
                'has_more'    => $hasMore,
            ]);
            exit;
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('error_server_fetch_images')
            ]);
            exit;
        }
    }
    /* Get More Bookmarks (mixed image+video) */
    if ($p === 'getMoreBookmarks') {
        try {
            $limit  = isset($_POST['limit'])  ? (int)$_POST['limit']  : $pageScrollLimit;
            $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;

            if ($limit < 1)  { $limit = 10; }
            if ($offset < 0) { $offset = 0; }

            $items = $RL->RL_FeedBookmarksMixed((int)$userID, $limit, $offset);

            // Render HTML tiles (same structure as bookmarks layout)
            ob_start();
            foreach ($items as $post) {
                $postId = (int)($post['post_id'] ?? 0);
                $type   = (string)($post['post_type'] ?? 'image');
                if ($type === 'video') {
                    $videoKey = (string)($post['post_file'] ?? '');
                    $videoPath = function_exists('storage_resolve_media_url')
                        ? storage_resolve_media_url($videoKey, $base_url ?? '')
                        : ((string)($base_url ?? '') . ltrim($videoKey, '/'));
                    $displaySrc = $RL->videoThumbNail($videoPath);
                } else {
                    $firstImage = $post['first_image'] ?? ($post['post_file'] ?? '');
                    $displaySrc = function_exists('storage_resolve_media_url')
                        ? storage_resolve_media_url((string)$firstImage, $base_url ?? '')
                        : ((string)($base_url ?? '') . ltrim((string)$firstImage, '/'));
                }
                $hasMulti      = !empty($post['has_multiple_images']);
                $postViews     = (int)($post['post_views'] ?? 0);
                $totalPostLike = (int)$RL->RL_CountLikes($postId);
                $totalComment  = (int)$RL->RL_TotalComment($postId);

                $moreTpl = __DIR__ . '/../themes/' . $currentTheme . '/layouts/moreBookmarks.php';
                include $moreTpl;
            }
            $html = trim((string)ob_get_clean());

            $count    = count($items);
            $next     = $offset + $count;
            $hasMore  = $count === $limit;

            echo json_encode([
                'status'      => 'ok',
                'html'        => $html,
                'next_offset' => $next,
                'has_more'    => $hasMore,
            ]);
            exit;
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('error_server_fetch_bookmarks')
            ]);
            exit;
        }
    }
    if ($p === 'moreUser') {
        try {
            // ---- Inputs & normalization ----
            $limit  = isset($_POST['limit'])  ? (int) $_POST['limit']  : 24;
            $offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;

            if ($limit < 6)  { $limit = 6;  }
            if ($limit > 60) { $limit = 60; } // safety cap
            if ($offset < 0) { $offset = 0;  }

            // ---- Prerequisites ----
            if (!isset($RL) || !is_object($RL) || !method_exists($RL, 'RL_GetCreatorsWithPublicPreviews')) {
                http_response_code(500);
                echo json_encode([
                    'status'  => 'error',
                    'message' => customLang('error_server_missing_method'),
                    'code'    => 'MISSING_METHOD',
                ]);
                exit;
            }

            // Base paths for assets
            $baseUrl  = rtrim($base_url ?? '', '/') . '/';
            $iconPath = $iconPath ?? ($baseUrl . 'themes/' . $currentTheme . '/img/');

            // ---- Fetch data ----
            $creators = (array) $RL->RL_GetCreatorsWithPublicPreviews($limit, $offset);

            // ---- Render HTML ----
            ob_start();

            $moreImage = __DIR__ . '/../themes/' . $currentTheme . '/layouts/creators.php';
            include $moreImage;

            $html   = trim((string) ob_get_clean());
            $count  = count($creators);
            $next   = $offset + $count;
            $hasMore = $count === $limit; // naive hasMore; adjust if total known

            echo json_encode([
                'status'      => 'ok',
                'html'        => $html,
                'next_offset' => $next,
                'has_more'    => $hasMore,
            ]);
            exit;
        } catch (\Throwable $e) {
            if (isset($RL) && is_object($RL) && is_callable([$RL, 'logError'])) {
                $RL->logError('moreUser error: ' . $e->getMessage());
            } else {
                error_log('moreUser error: ' . $e->getMessage());
            }
            http_response_code(500);
            echo json_encode([
                'status'  => 'error',
                'message' => 'LOAD_FAILED',
            ]);
            exit;
        }
    }
    /*Follow Unfollow User*/
    if ($p === 'suggested_users') {
        try {
            if (empty($enableSuggestedUsers)) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => customLang('suggested_users_disabled', 'Suggested users are disabled.'),
                ]);
                exit;
            }

            $token = (string) ($_POST['csrf_token'] ?? '');
            if (!checkCsrfToken($token)) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => customLang('invalid_csrf_token'),
                ]);
                exit;
            }

            $viewerId = isset($userID) ? (int) $userID : (int) ($_SESSION['iuid'] ?? 0);
            if ($viewerId <= 0) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => customLang('error_not_logged_in', 'Login required.'),
                ]);
                exit;
            }

            $config = $suggestedUsersConfig ?? [
                'enabled'      => true,
                'limit'        => 5,
                'allow_reload' => true,
                'mode'         => 'creators',
            ];
            $limit = isset($config['limit']) ? (int) $config['limit'] : 5;
            if ($limit < 1) { $limit = 1; }
            if ($limit > 24) { $limit = 24; }
            $mode = is_string($config['mode'] ?? '') ? (string) $config['mode'] : 'creators';

            $suggested = [];
            if (isset($RL) && method_exists($RL, 'RL_GetSuggestedUsers')) {
                $suggested = $RL->RL_GetSuggestedUsers($viewerId, $limit, $mode);
            }

            if (is_array($suggested) && count($suggested) > 1) {
                shuffle($suggested);
                if (count($suggested) > $limit) {
                    $suggested = array_slice($suggested, 0, $limit);
                }
            }

            $baseUrl       = rtrim((string)($base_url ?? ''), '/') . '/';
            $suggestedUsers = $suggested;
            $listTpl = __DIR__ . '/../themes/' . $currentTheme . '/layouts/widgets/suggestedUsersList.php';
            ob_start();
            if (is_file($listTpl)) {
                include $listTpl;
            }
            $html = trim((string) ob_get_clean());

            echo json_encode([
                'status' => 'ok',
                'html'   => $html,
                'csrf'   => function_exists('generateCsrfToken') ? generateCsrfToken() : '',
            ]);
            exit;
        } catch (\Throwable $e) {
            if (isset($RL) && is_object($RL) && is_callable([$RL, 'logError'])) {
                $RL->logError('suggested_users error: ' . $e->getMessage());
            }
            http_response_code(500);
            echo json_encode([
                'status'  => 'error',
                'message' => 'LOAD_FAILED',
            ]);
            exit;
        }
    }
    /*Follow Unfollow User*/
    if ($p === 'follow_unfollow') {
        try {
            $actorId  = isset($userID) ? (int)$userID : (int)($_SESSION['user_id'] ?? 0);
            $targetId = (int)($_POST['target_id'] ?? $_POST['id'] ?? 0);
            $now      = time();

            if ($actorId <= 0 || $targetId <= 0) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => customLang('error_invalid_parameters'),
                    'code'    => 'INVALID_PARAMETERS',
                ]);
                exit;
            }
            if ($actorId === $targetId) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => customLang('cannot_follow_yourself'),
                    'code'    => 'SELF_FOLLOW_BLOCKED',
                ]);
                exit;
            }

            if (!isset($RL) || !is_object($RL) || !method_exists($RL, 'RL_ToggleFollow')) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => customLang('error_server_missing_method'),
                    'code'    => 'MISSING_METHOD',
                ]);
                exit;
            }

            $res = (array) $RL->RL_ToggleFollow($actorId, $targetId, $now);

            echo json_encode([
                'status'           => 'success',
                'following'        => (bool)($res['following'] ?? false),      // current state after toggle
                'followers_total'  => (int) ($res['followers'] ?? 0),          // target's followers
                'following_total'  => (int) ($res['following_count'] ?? 0),    // actor's following
                'target_id'        => $targetId,
            ]);
            exit;
        } catch (\Throwable $e) {
            if (isset($RL) && is_object($RL) && is_callable([$RL, 'logError'])) {
                $RL->logError('follow_unfollow error: ' . $e->getMessage());
            } else {
                error_log('follow_unfollow error: ' . $e->getMessage());
            }
            echo json_encode([
                'status'  => 'error',
                'message' => 'SERVER_ERROR',
            ]);
            exit;
        }
    }
    /* Full messages page: fetch paginated shared media/links/posts for a conversation */
    if ($p === 'get_conversation_assets') {
        try {
            $chatUserID = (int)($_POST['id'] ?? 0);
            $assetType = strtolower(trim((string)($_POST['type'] ?? 'media')));
            $limit = (int)($_POST['limit'] ?? 24);
            $offset = (int)($_POST['offset'] ?? 0);

            if ($chatUserID <= 0 || !in_array($assetType, ['media', 'links', 'posts'], true)) {
                echo json_encode([
                    'status' => 'error',
                    'message' => customLang('error_invalid_parameters', 'Invalid parameters.'),
                    'code' => 'INVALID_PARAMETERS',
                ]);
                exit;
            }

            if (!isset($userID) || (int)$userID <= 0 || !isset($RL) || !method_exists($RL, 'RL_GetConversationAssetsPayload')) {
                echo json_encode([
                    'status' => 'error',
                    'message' => customLang('error_login_required', 'Please login to continue.'),
                    'code' => 'LOGIN_REQUIRED',
                ]);
                exit;
            }

            $payload = $RL->RL_GetConversationAssetsPayload((int)$userID, $chatUserID, $assetType, (string)($base_url ?? ''), $limit, $offset);
            echo json_encode([
                'status' => 'success',
                'assets' => $payload,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (\Throwable $e) {
            if (isset($RL) && is_object($RL) && is_callable([$RL, 'logError'])) {
                $RL->logError('get_conversation_assets error: ' . $e->getMessage());
            } else {
                error_log('get_conversation_assets error: ' . $e->getMessage());
            }
            echo json_encode([
                'status' => 'error',
                'message' => customLang('error_server', 'A server error occurred. Please try again.'),
                'code' => 'SERVER_ERROR',
            ]);
            exit;
        }
    }

    /* Open Live Chat (renders live_chat.php and returns JSON) */
    if ($p === 'get_live_chat' || $p === 'get_chat') {
        try {
            // Accept several param names for flexibility
            $chatUserID = (int)($_POST['id'] ?? 0);
            if ($chatUserID <= 0) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => customLang('error_invalid_parameters', 'Invalid parameters.'),
                    'code'    => 'INVALID_PARAMETERS',
                ]);
                exit;
            }

            // Resolve theme layout path for the live chat UI
            $filePath = __DIR__ . '/../themes/' . $currentTheme . '/chat/live_chat.php';
            if (!is_file($filePath)) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => customLang('requested_content_not_found', 'Requested content not found.'),
                    'code'    => 'NOT_FOUND',
                ]);
                exit;
            }


            // When opening a chat, mark all incoming (from chatUserID -> me) as delivered+read
            $updatedSeen = 0;
            try {
                if (isset($userID) && (int)$userID > 0) {
                    $me   = (int)$userID;
                    $with = (int)$chatUserID;
                    // debug logging removed
                    $updatedSeen = method_exists($RL,'RL_MarkConversationRead') ? $RL->RL_MarkConversationRead($me, $with, time()) : 0;
                    // Fallback direct update if trait returned 0
                    if ((int)$updatedSeen === 0) {
                        try {
                            $qUpd = $RL->getDb()->prepare('UPDATE i_messages SET delivered_at = IFNULL(delivered_at, :now), read_at = :now WHERE user_one = :with AND user_two = :me AND (delivered_at IS NULL OR read_at IS NULL OR read_at = 0)');
                            $nowDbg = time();
                            $qUpd->execute([':now'=>$nowDbg, ':with'=>$with, ':me'=>$me]);
                            $updatedSeen = (int)$qUpd->rowCount();
                        } catch (\Throwable $____) {}
                        if ((int)$updatedSeen === 0) {
                            // Fallback #2: update by explicit id list
                            try {
                                $pdo = $RL->getDb();
                                $sel = $pdo->prepare('SELECT message_id FROM i_messages WHERE user_one = :with AND user_two = :me AND (delivered_at IS NULL OR read_at IS NULL OR read_at = 0) ORDER BY message_id ASC LIMIT 500');
                                $sel->execute([':with'=>$with, ':me'=>$me]);
                                $ids = array_map('intval', $sel->fetchAll(\PDO::FETCH_COLUMN, 0) ?: []);
                                if (!empty($ids)) {
                                    $place = implode(',', array_fill(0, count($ids), '?'));
                                    $sql = 'UPDATE i_messages SET delivered_at = IFNULL(delivered_at, ?), read_at = ? WHERE message_id IN (' . $place . ')';
                                    $params = array_merge([$nowDbg, $nowDbg], $ids);
                                    $upd2 = $pdo->prepare($sql);
                                    $upd2->execute($params);
                                    $updatedSeen = (int)$upd2->rowCount();
                                }
                            } catch (\Throwable $______) { /* ignore */ }
                        }
                    }
                    // debug logging removed
                }
            } catch (Throwable $__) { /* ignore */ }

            // Important: clean any active buffer so JSON is not polluted
            while (ob_get_level() > 0) { ob_end_clean(); }

            // Make $chatUserID available to the template scope
            ob_start();
            include $filePath; // must ONLY echo HTML
            $html = trim(ob_get_clean());

            // Safety: mark read again after render (covers race where new message arrived during render)
            try {
                if (isset($userID) && (int)$userID > 0) {
                    $me2   = (int)$userID;
                    $with2 = (int)$chatUserID;
                    $nowTs = time();
                    $updatedSeen2 = method_exists($RL,'RL_MarkConversationRead') ? $RL->RL_MarkConversationRead($me2, $with2, $nowTs) : 0;
                    if ((int)$updatedSeen2 === 0 && method_exists($RL,'getDb')) {
                        try {
                            $qUpd2 = $RL->getDb()->prepare('UPDATE i_messages
                                SET delivered_at = IFNULL(delivered_at, :now), read_at = :now
                                WHERE user_one = :with AND user_two = :me
                                  AND (delivered_at IS NULL OR read_at IS NULL OR read_at = 0)');
                            $qUpd2->bindValue(':now', $nowTs, \PDO::PARAM_INT);
                            $qUpd2->bindValue(':with', $with2, \PDO::PARAM_INT);
                            $qUpd2->bindValue(':me',  $me2,  \PDO::PARAM_INT);
                            $qUpd2->execute();
                            $updatedSeen2 = (int)$qUpd2->rowCount();
                        } catch (\Throwable $____) { /* ignore */ }
                    }
                    if (!empty($updatedSeen2)) { $updatedSeen = max((int)$updatedSeen, (int)$updatedSeen2); }
                }
            } catch (Throwable $__) { /* ignore */ }

            // Compute max_read_id for this conversation from current user (me -> with)
            $maxReadId = 0; $maxReadTs = 0;
            try {
                if (isset($userID) && (int)$userID > 0) {
                    $q = $RL->getDb()->prepare('SELECT MAX(message_id) AS mid, MAX(read_at) AS rt FROM i_messages WHERE user_one = :me AND user_two = :with AND read_at IS NOT NULL AND read_at > 0');
                    $q->bindValue(':me', (int)$userID, \PDO::PARAM_INT);
                    $q->bindValue(':with', (int)$chatUserID, \PDO::PARAM_INT);
                    $q->execute();
                    $row2 = $q->fetch(\PDO::FETCH_ASSOC) ?: [];
                    $maxReadId = (int)($row2['mid'] ?? 0);
                    $maxReadTs = (int)($row2['rt'] ?? 0);
                }
            } catch (\Throwable $__) { $maxReadId = 0; $maxReadTs = 0; }

            $conversationDetails = null;
            try {
                if (isset($userID) && (int)$userID > 0 && method_exists($RL, 'RL_GetConversationDetailsPayload')) {
                    $conversationDetails = $RL->RL_GetConversationDetailsPayload((int)$userID, (int)$chatUserID, (string)($base_url ?? ''), 6);
                }
            } catch (\Throwable $__) {
                $conversationDetails = null;
            }

            echo json_encode([
                'status'       => 'success',
                'html'         => $html,
                'chat_user_id' => $chatUserID,
                'updated'      => $updatedSeen,
                'max_read_id'  => $maxReadId,
                'max_read_ts'  => $maxReadTs,
                'details'      => $conversationDetails
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (\Throwable $e) {
            if (isset($RL) && is_object($RL) && is_callable([$RL, 'logError'])) {
                $RL->logError('get_live_chat error: ' . $e->getMessage());
            } else {
                error_log('get_live_chat error: ' . $e->getMessage());
            }
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('error_server', 'A server error occurred. Please try again.'),
                'code'    => 'SERVER_ERROR',
            ]);
            exit;
        }
    }
    /* Open Chat List (renders chat_list.php and returns JSON) */
    if ($p === 'getChatList') {
        try {
            // Resolve theme layout path for the live chat UI
            $filePath = __DIR__ . '/../themes/' . $currentTheme . '/chat/chat_list.php';
            if (!is_file($filePath)) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => customLang('requested_content_not_found', 'Requested content not found.'),
                    'code'    => 'NOT_FOUND',
                ]);
                exit;
            }
            // Important: clean any active buffer so JSON is not polluted
            while (ob_get_level() > 0) { ob_end_clean(); }

            // Make $chatUserID available to the template scope
            ob_start();
            include $filePath; // must ONLY echo HTML
            $html = trim(ob_get_clean());

            echo json_encode([
                'status'      => 'success',
                'html'        => $html
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (\Throwable $e) {
            if (isset($RL) && is_object($RL) && is_callable([$RL, 'logError'])) {
                $RL->logError('get_live_chat error: ' . $e->getMessage());
            } else {
                error_log('get_live_chat error: ' . $e->getMessage());
            }
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('error_server', 'A server error occurred. Please try again.'),
                'code'    => 'SERVER_ERROR',
            ]);
            exit;
        }
    }
}
?>
