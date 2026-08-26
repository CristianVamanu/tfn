<?php
declare(strict_types=1);

namespace CreatorPulse\App\Controllers;

use Reel_Data;
use Throwable;

/**
 * Manages media uploads, FFmpeg processing, and reel finalisation while keeping existing storage side-effects.
 */
class ReelsHandler
{
    private Reel_Data $repository;

    public function __construct(Reel_Data $repository)
    {
        $this->repository = $repository;
    }

    public function handleUploadImage(): void
    {
        global $premiumPostPriceMinimum, $premiumPostPriceMaximum, $userData, $userID, $RL, $base_url, $availableUploadFileSize, $currentTheme, $lockedPreviewModeImages, $postApprovalRequired;

        $RL = $this->repository;

        header('Content-Type: application/json');

        if (!isset($userID) || (int) $userID <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            exit;
        }

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!function_exists('checkCsrfToken') || !checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }

        $postTitle     = trim(strip_tags((string) ($_POST['post_title'] ?? '')));
        $postTitle     = function_exists('mb_substr') ? mb_substr($postTitle, 0, 255, 'UTF-8') : substr($postTitle, 0, 255);
        $postText      = trim($_POST['post_text'] ?? '');
        $whoCanSee     = $_POST['who_can_see'] ?? 'everyone';
        $likeStatus    = $_POST['like_status'] ?? 'off';
        $commentStatus = $_POST['comment_status'] ?? 'on';
        $priceRaw      = trim($_POST['price'] ?? '');

        $allowedWho   = ['everyone', 'followers', 'subscribers', 'locked'];
        $allowedOnOff = ['on', 'off'];

        if (!in_array($whoCanSee, $allowedWho, true)) {
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_visibility_type')]);
            exit;
        }
        if ($whoCanSee === 'subscribers') {
            $ud = isset($userData) && is_array($userData) ? $userData : ( (isset($RL) && method_exists($RL,'RL_GetUserDetails')) ? $RL->RL_GetUserDetails((int)($userID ?? 0)) : [] );
            $subNew = strtolower((string)($ud['subscription_status'] ?? ''));
            $subOld = strtolower((string)($ud['subscrition_status'] ?? ''));
            $isOpen = ($subNew === 'open') || ($subOld === 'active');
            if (!$isOpen) {
                $subscriptionDisabledMessage = customLang(
                    'reel_subscription_disabled',
                    'You cannot publish subscriber-only posts because subscriptions are not enabled. Enable them via Settings > Subscription fees.'
                );
                echo json_encode([
                    'status'  => 'error',
                    'code'    => 'SUBSCRIPTION_DISABLED',
                    'message' => $subscriptionDisabledMessage
                ]);
                exit;
            }
        }
        if (!in_array($likeStatus, $allowedOnOff, true)) {
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_like_status')]);
            exit;
        }
        if (!in_array($commentStatus, $allowedOnOff, true)) {
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_comment_status')]);
            exit;
        }

        $minPrice = (int)($premiumPostPriceMinimum ?? 1);
        $maxPrice = (int)($premiumPostPriceMaximum ?? 500);

        $price = null;
        if ($priceRaw !== '') {
            $price = filter_var($priceRaw, FILTER_VALIDATE_INT);
            if ($price === false) {
                echo json_encode(['status' => 'error', 'message' => customLang('price_must_be_an_integer')]);
                exit;
            }
            if ($price < $minPrice || $price > $maxPrice) {
                echo json_encode(['status' => 'error', 'message' => customLang('price_range_error')]);
                exit;
            }
        }

        if ($whoCanSee !== 'locked') {
            $price = null;
        }

        if (!isset($_FILES['images'])) {
            echo json_encode(['status' => 'error', 'message' => customLang('no_image_uploaded')]);
            exit;
        }

        $files = $_FILES['images'];
        if (!is_array($files) || !array_key_exists('tmp_name', $files)) {
            echo json_encode(['status' => 'error', 'message' => customLang('no_image_uploaded')]);
            exit;
        }
        if (!is_array($files['tmp_name'])) {
            $files = [
                'tmp_name' => [$files['tmp_name'] ?? ''],
                'error'    => [$files['error'] ?? UPLOAD_ERR_NO_FILE],
                'size'     => [$files['size'] ?? 0],
                'name'     => [$files['name'] ?? ''],
                'type'     => [$files['type'] ?? ''],
            ];
        }

        $uploadDir = createTodayDirectory();
        $relativeDir = $uploadDir;
        $relativeDir = str_replace('\\', '/', $relativeDir);
        $marker = '/uploads/files/';
        $pos = strpos($relativeDir, $marker);
        if ($pos !== false) {
            $relativeDir = ltrim(substr($relativeDir, $pos), '/');
        } else {
            $relativeDir = 'uploads/files/' . basename(rtrim($relativeDir, '/'));
        }
        $relativeDir = preg_replace('#/+#', '/', $relativeDir);
        $relativeDir = trim($relativeDir, '/');
        $fileNames = [];
        $errors    = [];

        $shouldPublishToStorage = false;
        try {
            $shouldPublishToStorage = storage_manager()->isRemote();
        } catch (Throwable $__) {
            $shouldPublishToStorage = false;
        }

        $lockedPreviewPath = null;
        $lockedPreviewType = null;
        $lockedPreviewAbsolute = null;
        $lockedPreviewWarning = null;
        $teaserRelative = '';
        $teaserAbsolute = '';
        $teaserRelative = '';
        $teaserAbsolute = '';
        $firstLocalImagePath = null;
        $shouldGenerateLockedPreview = in_array($whoCanSee, ['subscribers', 'locked'], true)
            && isset($lockedPreviewModeImages)
            && $lockedPreviewModeImages !== 'off';

        $defaultMimes = ['image/jpeg', 'image/png', 'image/webp'];
        if (function_exists('imagecreatefromgif')) {
            $defaultMimes = array_merge($defaultMimes, [
                'image/gif',
                'image/x-gif',
            ]);
        }
        if (function_exists('imagecreatefrombmp')) {
            $defaultMimes = array_merge($defaultMimes, [
                'image/bmp',
                'image/x-bmp',
                'image/x-ms-bmp',
                'image/x-windows-bmp',
            ]);
        }
        if (function_exists('imagecreatefromwbmp')) {
            $defaultMimes = array_merge($defaultMimes, [
                'image/vnd.wap.wbmp',
                'image/wbmp',
            ]);
        }
        if (function_exists('imagecreatefromavif') && defined('IMAGETYPE_AVIF')) {
            $defaultMimes = array_merge($defaultMimes, [
                'image/avif',
                'image/avifs',
                'image/heic',
                'image/heif',
            ]);
        }
        if (function_exists('imagecreatefromstring')) {
            $defaultMimes = array_merge($defaultMimes, [
                'image/x-icon',
                'image/vnd.microsoft.icon',
            ]);
        }

        if (isset($allowedUploadImageMimes) && is_array($allowedUploadImageMimes) && $allowedUploadImageMimes !== []) {
            $allowedMimes = $allowedUploadImageMimes;
        } else {
            $allowedMimes = $defaultMimes;
        }
        $allowedMimes = array_values(array_unique(array_map(static fn($mime) => strtolower(trim((string) $mime)), $allowedMimes)));

        $maxMb = isset($availableUploadFileSize) ? (float) $availableUploadFileSize : 5.0;
        if ($maxMb <= 0) { $maxMb = 5.0; }
        $maxBytes = (int) round($maxMb * 1048576);

        $finfo = function_exists('finfo_open') ? @finfo_open(FILEINFO_MIME_TYPE) : null;

        foreach ($files['tmp_name'] as $key => $tmpName) {
            $errCode = (int) ($files['error'][$key] ?? UPLOAD_ERR_OK);
            $size    = (int) ($files['size'][$key] ?? 0);
            $name    = (string) ($files['name'][$key] ?? '');

            if ($errCode !== UPLOAD_ERR_OK || empty($tmpName) || !is_uploaded_file($tmpName)) {
                $errors[] = customLang('upload_failed');
                continue;
            }

            if ($size <= 0) {
                $errors[] = customLang('upload_failed');
                continue;
            }
            if ($size > $maxBytes) {
                $errors[] = customLang('file_too_large');
                continue;
            }

            $mime = '';
            if ($finfo) {
                $mime = (string) @finfo_file($finfo, $tmpName);
            } elseif (function_exists('mime_content_type')) {
                $mime = (string) @mime_content_type($tmpName);
            }
            $mime = strtolower(trim($mime));

            $imgInfo = @getimagesize($tmpName);
            if ($imgInfo === false) {
                $errors[] = customLang('invalid_file_format');
                continue;
            }
            $imgType = (int) ($imgInfo[2] ?? 0);

            if ($mime === '' && isset($imgInfo['mime'])) {
                $mime = strtolower(trim((string) $imgInfo['mime']));
            }
            if ($mime === '' && function_exists('image_type_to_mime_type')) {
                $mime = image_type_to_mime_type($imgType);
                $mime = strtolower(trim((string) $mime));
            }

            $normalizedMap = [
                'image/x-png'           => 'image/png',
                'image/x-citrix-png'    => 'image/png',
                'image/x-citrix-jpeg'   => 'image/jpeg',
                'image/pjpeg'           => 'image/jpeg',
                'image/jpg'             => 'image/jpeg',
                'image/x-jpg'           => 'image/jpeg',
                'image/x-bmp'           => 'image/bmp',
                'image/x-ms-bmp'        => 'image/bmp',
                'image/x-windows-bmp'   => 'image/bmp',
                'image/vnd.wap.wbmp'    => 'image/wbmp',
                'image/heic'            => 'image/avif',
                'image/heif'            => 'image/avif',
                'image/avifs'           => 'image/avif',
            ];
            if (isset($normalizedMap[$mime])) {
                $mime = $normalizedMap[$mime];
            }

            if ($mime === '') {
                $errors[] = customLang('invalid_file_format');
                continue;
            }
            if (!in_array($mime, $allowedMimes, true)) {
                $errors[] = customLang('ui_only_images_allowed');
                continue;
            }
            $supportedTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
            if (defined('IMAGETYPE_GIF')) {
                $supportedTypes[] = IMAGETYPE_GIF;
            }
            if (defined('IMAGETYPE_BMP')) {
                $supportedTypes[] = IMAGETYPE_BMP;
            }
            if (defined('IMAGETYPE_WBMP')) {
                $supportedTypes[] = IMAGETYPE_WBMP;
            }
            if (defined('IMAGETYPE_AVIF')) {
                $supportedTypes[] = IMAGETYPE_AVIF;
            }
            if (defined('IMAGETYPE_ICO')) {
                $supportedTypes[] = IMAGETYPE_ICO;
            }
            if (!in_array($imgType, $supportedTypes, true)) {
                $errors[] = customLang('ui_only_images_allowed');
                continue;
            }

            $gd = null;
            $outExt = 'jpg';
            try {
                if ($imgType === IMAGETYPE_JPEG) {
                    $gd = @imagecreatefromjpeg($tmpName);
                    $outExt = 'jpg';
                } elseif ($imgType === IMAGETYPE_PNG) {
                    $gd = @imagecreatefrompng($tmpName);
                    $outExt = 'png';
                } elseif ($imgType === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
                    $gd = @imagecreatefromwebp($tmpName);
                    $outExt = 'png';
                } elseif (defined('IMAGETYPE_GIF') && $imgType === IMAGETYPE_GIF && function_exists('imagecreatefromgif')) {
                    $gd = @imagecreatefromgif($tmpName);
                    $outExt = 'png';
                } elseif (defined('IMAGETYPE_BMP') && $imgType === IMAGETYPE_BMP && function_exists('imagecreatefrombmp')) {
                    $gd = @imagecreatefrombmp($tmpName);
                    $outExt = 'png';
                } elseif (defined('IMAGETYPE_WBMP') && $imgType === IMAGETYPE_WBMP && function_exists('imagecreatefromwbmp')) {
                    $gd = @imagecreatefromwbmp($tmpName);
                    $outExt = 'png';
                } elseif (defined('IMAGETYPE_AVIF') && $imgType === IMAGETYPE_AVIF && function_exists('imagecreatefromavif')) {
                    $gd = @imagecreatefromavif($tmpName);
                    $outExt = 'png';
                } elseif (defined('IMAGETYPE_ICO') && $imgType === IMAGETYPE_ICO && function_exists('imagecreatefromstring')) {
                    $data = @file_get_contents($tmpName);
                    if ($data !== false) {
                        $gd = @imagecreatefromstring($data);
                        $outExt = 'png';
                    }
                }
            } catch (Throwable $__) {
                $gd = null;
            }

            if (!$gd) {
                if (function_exists('imagecreatefromstring')) {
                    $data = @file_get_contents($tmpName);
                    if ($data !== false) {
                        try {
                            $gd = @imagecreatefromstring($data);
                            if (is_resource($gd) || $gd instanceof \GdImage) {
                                $outExt = $outExt === 'jpg' ? 'jpg' : 'png';
                            }
                        } catch (Throwable $__) {
                            $gd = null;
                        }
                    }
                }
            }

            if (!$gd) {
                $errors[] = customLang('invalid_file_format');
                continue;
            }

            try {
                $rand = bin2hex(random_bytes(8));
            } catch (Throwable $__) {
                $rand = str_replace('.', '', uniqid('img_', true));
            }
            $fileName    = 'img_' . $rand . '.' . $outExt;
            $destination = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

            $saved = false;
            if ($outExt === 'jpg') {
                $saved = @imagejpeg($gd, $destination, 90);
            } else {
                @imagealphablending($gd, false);
                @imagesavealpha($gd, true);
                $saved = @imagepng($gd, $destination, 6);
            }
            @imagedestroy($gd);

            if (!$saved || !is_file($destination)) {
                $errors[] = customLang('upload_failed');
                continue;
            }

            $filterKey   = 'filter_' . $key;
            $filterValue = $_POST[$filterKey] ?? 'none';
            if ($filterValue !== 'none' && strtolower(pathinfo($destination, PATHINFO_EXTENSION)) === 'png' && function_exists('applyFilterToImage')) {
                try { applyFilterToImage($destination, $filterValue); } catch (Throwable $__) { }
            }

            $remoteKey = $relativeDir . '/' . $fileName;

            if ($shouldPublishToStorage) {
                try {
                    $result = storage_publish($destination, $remoteKey, $outExt === 'png' ? 'image/png' : 'image/jpeg', 'public');
                    $remoteKey = $result->getRemoteKey();
                } catch (Throwable $storageFailure) {
                    if (function_exists('error_log')) {
                        error_log('[Storage] publish failed: ' . $storageFailure->getMessage());
                    }
                    $errors[] = customLang('upload_failed');
                    @unlink($destination);
                    continue;
                }
            }

            if ($firstLocalImagePath === null) {
                $firstLocalImagePath = $destination;
            }

            $fileNames[] = $remoteKey;
        }

        if (empty($fileNames)) {
            $msg = !empty($errors) ? $errors[0] : customLang('upload_failed');
            echo json_encode(['status' => 'error', 'message' => $msg]);
            exit;
        }

        if ($shouldGenerateLockedPreview && $firstLocalImagePath !== null) {
            $previewResult = $this->buildLockedPreviewFromImage(
                (string) $lockedPreviewModeImages,
                $firstLocalImagePath,
                $shouldPublishToStorage
            );

            if (!empty($previewResult['path']) && !empty($previewResult['type'])) {
                $lockedPreviewPath = (string) $previewResult['path'];
                $lockedPreviewType = (string) $previewResult['type'];
                if ($lockedPreviewWarning === null && $lockedPreviewModeImages === 'animated' && $lockedPreviewType !== 'animated') {
                    $lockedPreviewWarning = customLang('locked_preview_fallback_static')
                        ?: 'Animated preview was unavailable. A static pixelated frame was generated instead.';
                }
            } elseif (!empty($previewResult['error'])) {
                $lockedPreviewWarning = customLang('locked_preview_generation_failed')
                    ?: 'Locked preview could not be generated. Upload completed without it.';
            }

            if (!empty($previewResult['absolute'])) {
                $lockedPreviewAbsolute = (string) $previewResult['absolute'];
            }
        }

        $postFile   = implode(',', $fileNames);
        $postType   = 'image';
        $createdTime = time();
        $approvalStatus = (!empty($postApprovalRequired)) ? 'pending' : 'approved';
        $approvalStatus = $approvalStatus ?: 'approved';
        $approvedAt = $approvalStatus === 'approved' ? $createdTime : null;

        $insert = $RL->RL_InsertCroppedImage(
            $userID,
            $postFile,
            $postType,
            $createdTime,
            $postText,
            $whoCanSee,
            $price,
            $commentStatus,
            $likeStatus,
            null,
            null,
            $lockedPreviewPath,
            $lockedPreviewType,
            $approvalStatus,
            null,
            $approvedAt,
            null,
            $postTitle
        );

        $layoutFile = dirname(__DIR__, 2) . '/themes/' . $currentTheme . '/layouts/newCroppedPost.php';

        $lastInsertError = null;
        if (!$insert && isset($RL) && method_exists($RL, 'RL_GetLastPostSqlError')) {
            $lastInsertError = $RL->RL_GetLastPostSqlError();
        }
        if (!$insert && $lastInsertError) {
            $this->logUploadError('insert_failed', [
                'error' => $lastInsertError,
                'user_id' => (int) ($userID ?? 0),
                'post_type' => $postType,
            ]);
        }

        $html = '';
        $newPostId = 0;
        $htmlEncoding = 'none';
        if ($insert) {
            $post = $RL->RL_GetNewPost($userID, $postFile, $createdTime, $userID);
            $newPostId = isset($post['post_id']) ? (int) $post['post_id'] : 0;

            if ($post && is_file($layoutFile)) {
                $uploadsPath = rtrim($base_url, '/') . '/';

                ob_start();
                include $layoutFile;
                $html = ob_get_clean();
                if ($html !== '') {
                    try {
                        $encoded = base64_encode($html);
                        if ($encoded !== false) {
                            $html = $encoded;
                            $htmlEncoding = 'base64';
                        }
                    } catch (Throwable $__) {
                        $htmlEncoding = 'none';
                    }
                }
            }

            try {
                if (isset($RL) && method_exists($RL, 'getDb')) {
                    $db = $RL->getDb();
                    $pid = (int)($post['post_id'] ?? 0);
                        if ($pid > 0) {
                            $paths = array_values(array_filter(array_map('trim', explode(',', $postFile)), static fn($x)=>$x!==''));
                            if (isset($RL) && method_exists($RL,'RL_AddPostMediaMany')) { $RL->RL_AddPostMediaMany($pid, $paths, 'image'); }
                            if ($lockedPreviewPath) {
                                $RL->RL_AddPostMedia($pid, $lockedPreviewPath, 'locked_preview');
                            }
                        }
                }
            } catch (Throwable $__) { }
        }

        if (!$insert && $lockedPreviewPath !== null && $lockedPreviewPath !== '') {
            try {
                if (function_exists('storage_delete')) {
                    storage_delete($lockedPreviewPath);
                }
            } catch (Throwable $__) {
            }
            if ($lockedPreviewAbsolute && is_file($lockedPreviewAbsolute)) {
                @unlink($lockedPreviewAbsolute);
            }
        }
        $successMessage = customLang('reel_upload_success', 'Upload successful.');
        $failureMessage = customLang('reel_upload_failed', 'Unable to save the post.');
        if ($approvalStatus === 'pending') {
            $successMessage = customLang('reel_upload_pending', 'Post submitted for review. It will be visible after admin approval.');
        }

        $response = [
            'status'  => $insert ? 'success' : 'error',
            'html'    => $insert ? $html : '',
            'html_encoding' => $insert ? $htmlEncoding : 'none',
            'message' => $insert ? $successMessage : $failureMessage,
            'post_id' => $newPostId
        ];
        if ($insert) {
            $response['approval_status'] = $approvalStatus;
        }

        if ($lockedPreviewWarning !== null) {
            $response['locked_preview_warning'] = $lockedPreviewWarning;
        }

        if (!$insert && $lastInsertError && defined('APP_DEBUG') && APP_DEBUG) {
            $response['debug'] = $lastInsertError;
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function logUploadError(string $stage, array $context = []): void
    {
        $logDir = dirname(__DIR__, 2) . '/uploads/logs';
        $logFile = $logDir . '/upload_error.log';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $payload = array_merge([
            'ref' => (string) ($GLOBALS['uploadDebugRef'] ?? ''),
            'time' => date('c'),
            'stage' => $stage,
        ], $context);
        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) {
            $line = '[uploadImage] ' . date('c') . ' stage=' . $stage;
        }
        @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function handleUploadTempVideo(): void
    {
        global $maximumVideoDuration, $ffmpegPath, $ffprobePath, $availableFileExtensions, $availableUploadFileSize, $userID, $RL, $postApprovalRequired;

        $RL = $this->repository;

        header('Content-Type: application/json');

        $debugRef = date('YmdHis');
        try {
            $debugRef .= '_' . substr(bin2hex(random_bytes(4)), 0, 8);
        } catch (Throwable $__) {
            $debugRef .= '_' . substr(md5((string) microtime(true)), 0, 8);
        }
        $GLOBALS['uploadDebugRef'] = $debugRef;

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $this->logUploadError('start', [
            'ip' => (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')),
            'user_id' => (int) ($userID ?? 0),
            'content_length' => (int) ($_SERVER['CONTENT_LENGTH'] ?? 0),
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
            'max_input_time' => (string) ini_get('max_input_time'),
        ]);

        $self = $this;
        register_shutdown_function(static function () use ($self): void {
            $err = error_get_last();
            if (!$err || !isset($err['type'])) {
                return;
            }
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array($err['type'], $fatalTypes, true)) {
                return;
            }
            $self->logUploadError('fatal', [
                'error' => [
                    'type' => (int) $err['type'],
                    'message' => (string) ($err['message'] ?? ''),
                    'file' => (string) ($err['file'] ?? ''),
                    'line' => (int) ($err['line'] ?? 0),
                ],
            ]);
        });

        try {
            if (!isset($userID) || (int) $userID <= 0) {
                http_response_code(401);
                echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
                exit;
            }

            $csrfToken = (string) ($_POST['csrf_token'] ?? '');
            if (!function_exists('checkCsrfToken') || !checkCsrfToken($csrfToken)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
                exit;
            }

            define('MAX_VIDEO_DURATION', $maximumVideoDuration);

            $ffmpegBinary = dz_resolve_binary(isset($ffmpegPath) ? (string) $ffmpegPath : null, ['ffmpeg']);
            $ffprobeBinary = dz_resolve_binary(isset($ffprobePath) ? (string) $ffprobePath : null, ['ffprobe']);

            if (!is_string($ffmpegBinary) || $ffmpegBinary === '' || !is_string($ffprobeBinary) || $ffprobeBinary === '') {
                $this->logUploadError('ffmpeg_missing', [
                    'ffmpeg' => (string) $ffmpegBinary,
                    'ffprobe' => (string) $ffprobeBinary,
                ]);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'ffmpeg_unavailable',
                    'message_text' => 'Required media binaries (FFmpeg/FFprobe) are not available on the server.'
                ]);
                exit;
            }
            $ffmpegBinary = (string) $ffmpegBinary;
            $ffprobeBinary = (string) $ffprobeBinary;

            $uploadError = null;
            $uploadField = '';
            $uploadName = '';
            $uploadSize = 0;
            if (isset($_FILES['video'])) {
                $uploadField = 'video';
                $uploadError = (int) ($_FILES['video']['error'] ?? UPLOAD_ERR_OK);
                $uploadName = (string) ($_FILES['video']['name'] ?? '');
                $uploadSize = (int) ($_FILES['video']['size'] ?? 0);
            } elseif (isset($_FILES['uploading'])) {
                $uploadField = 'uploading';
                $uploadError = (int) ($_FILES['uploading']['error'][0] ?? UPLOAD_ERR_OK);
                $uploadName = (string) ($_FILES['uploading']['name'][0] ?? '');
                $uploadSize = (int) ($_FILES['uploading']['size'][0] ?? 0);
            }

            if ($uploadError !== null && $uploadError !== UPLOAD_ERR_OK) {
                $this->logUploadError('upload_error', [
                    'field' => $uploadField,
                    'code' => $uploadError,
                    'name' => $uploadName,
                    'size' => $uploadSize,
                ]);
                $messageKey = 'upload_failed';
                if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                    $messageKey = 'file_too_large';
                } elseif ($uploadError === UPLOAD_ERR_NO_FILE) {
                    $messageKey = 'no_video_uploaded';
                }
                echo json_encode([
                    'status' => 'error',
                    'message' => customLang($messageKey),
                    'debug_ref' => $debugRef,
                ]);
                exit;
            }

            $fileArr = null;
            if (isset($_FILES['video']) && is_uploaded_file($_FILES['video']['tmp_name'])) {
                $fileArr = [
                    'name' => $_FILES['video']['name'],
                    'size' => $_FILES['video']['size'],
                    'type' => $_FILES['video']['type'] ?? '',
                    'tmp_name' => $_FILES['video']['tmp_name'],
                ];
            } elseif (isset($_FILES['uploading']) && isset($_FILES['uploading']['tmp_name'][0]) && is_uploaded_file($_FILES['uploading']['tmp_name'][0])) {
                $fileArr = [
                    'name' => $_FILES['uploading']['name'][0],
                    'size' => $_FILES['uploading']['size'][0],
                    'type' => $_FILES['uploading']['type'][0] ?? '',
                    'tmp_name' => $_FILES['uploading']['tmp_name'][0],
                ];
            }

            if (!$fileArr) {
                $this->logUploadError('no_file', [
                    'files_keys' => array_keys($_FILES ?? []),
                ]);
                echo json_encode([
                    'status' => 'error',
                    'message' => customLang('no_video_uploaded'),
                    'debug_ref' => $debugRef,
                ]);
                exit;
            }

            $name = stripslashes($fileArr['name']);
            $size = (int) $fileArr['size'];
            $tmpPath = (string) $fileArr['tmp_name'];
            $ext = strtolower(getExtension($name));

            $this->logUploadError('file_received', [
                'name' => (string) $name,
                'size' => $size,
                'ext' => $ext,
            ]);

            $validFormats = explode(',', $availableFileExtensions);
            $normalizedFormats = array_values(array_filter(array_map(static function ($format): string {
                return strtolower(trim((string) $format));
            }, $validFormats)));

            $detectedMime = '';
            if ($tmpPath !== '' && is_file($tmpPath) && function_exists('finfo_open')) {
                $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $detectedMime = (string) @finfo_file($finfo, $tmpPath);
                    @finfo_close($finfo);
                }
            }

            if ($detectedMime === '' && $tmpPath !== '' && is_file($tmpPath) && function_exists('mime_content_type')) {
                $detectedMime = (string) @mime_content_type($tmpPath);
            }

            $detectedMime = strtolower(trim($detectedMime));
            if ($detectedMime !== '') {
                $semicolonPos = strpos($detectedMime, ';');
                if ($semicolonPos !== false) {
                    $detectedMime = trim(substr($detectedMime, 0, $semicolonPos));
                }
            }

            $extensionMimeMap = [
                'mp4' => ['video/mp4', 'video/x-m4v'],
                'm4v' => ['video/mp4', 'video/x-m4v'],
                'mov' => ['video/quicktime'],
                'webm' => ['video/webm'],
                'mkv' => ['video/x-matroska'],
                'avi' => ['video/x-msvideo'],
                '3gp' => ['video/3gpp', 'video/3gpp2'],
                '3g2' => ['video/3gpp2'],
                'flv' => ['video/x-flv'],
                'wmv' => ['video/x-ms-wmv'],
                'ogg' => ['video/ogg'],
                'ogv' => ['video/ogg'],
                'ts'  => ['video/mp2t'],
                'mpeg'=> ['video/mpeg'],
                'mpg' => ['video/mpeg'],
            ];

            $allowedMimeTypes = [];
            foreach ($normalizedFormats as $format) {
                if (isset($extensionMimeMap[$format])) {
                    foreach ($extensionMimeMap[$format] as $mime) {
                        $allowedMimeTypes[] = strtolower($mime);
                    }
                }
            }
            $allowedMimeTypes = array_values(array_unique($allowedMimeTypes));

            if ($detectedMime === '' || !str_starts_with($detectedMime, 'video/')) {
                if (isset($extensionMimeMap[$ext]) && $extensionMimeMap[$ext] !== []) {
                    $detectedMime = $extensionMimeMap[$ext][0];
                }
            }

            if ($detectedMime === '' || !str_starts_with($detectedMime, 'video/')) {
                $this->logUploadError('mime_invalid', [
                    'ext' => $ext,
                    'mime' => $detectedMime,
                ]);
                echo json_encode(['status' => 'error', 'message' => customLang('only_video_files_allowed'), 'debug_ref' => $debugRef]);
                exit;
            }

            if (!empty($allowedMimeTypes) && !in_array($detectedMime, $allowedMimeTypes, true)) {
                $this->logUploadError('mime_blocked', [
                    'ext' => $ext,
                    'mime' => $detectedMime,
                    'allowed' => $allowedMimeTypes,
                ]);
                echo json_encode(['status' => 'error', 'message' => customLang('invalid_file_format'), 'debug_ref' => $debugRef]);
                exit;
            }

            if (!in_array($ext, $validFormats, true)) {
                $this->logUploadError('ext_blocked', [
                    'ext' => $ext,
                    'allowed' => $validFormats,
                ]);
                echo json_encode(['status' => 'error', 'message' => customLang('invalid_file_format'), 'debug_ref' => $debugRef]);
                exit;
            }

            if (convert_to_mb($size) > $availableUploadFileSize) {
                $this->logUploadError('file_too_large', [
                    'size_mb' => convert_to_mb($size),
                    'limit_mb' => $availableUploadFileSize,
                ]);
                echo json_encode(['status' => 'error', 'message' => customLang('file_too_large'), 'debug_ref' => $debugRef]);
                exit;
            }

            $microtime = microtime();
            $removeMicrotime = preg_replace('/(0)\\.(\\d+) (\\d+)/', '$3$1$2', $microtime);
            $uploadedFileName = 'reel_' . $removeMicrotime . '_' . $userID;
            $filenameWithExt = $uploadedFileName . '.' . $ext;
            $todayDir = date('Y-m-d');
            $uploadDir = '../uploads/files/' . $todayDir . '/';

            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $uploadedPath = $uploadDir . $filenameWithExt;

            if (!move_uploaded_file($fileArr['tmp_name'], $uploadedPath)) {
                $this->logUploadError('move_failed', [
                    'tmp' => (string) $fileArr['tmp_name'],
                    'dest' => $uploadedPath,
                    'error' => error_get_last(),
                ]);
                echo json_encode(['status' => 'error', 'message' => customLang('upload_failed'), 'debug_ref' => $debugRef]);
                exit;
            }
            $this->logUploadError('moved', ['path' => $uploadedPath]);

            require_once dirname(__DIR__, 2) . '/includes/helpers/convertToMp4Format.php';
            require_once dirname(__DIR__, 2) . '/includes/helpers/convertVideoToBlurredReelsFormat.php';
            require_once dirname(__DIR__, 2) . '/includes/helpers/createVideoThumbnailInSameDir.php';

            $convertedDir = '../uploads/files/' . $todayDir;
            $convertedPath = $convertedDir . '/' . $uploadedFileName . '.mp4';
            $finalReelsPath = null;

            $cleanupTemporaryFiles = static function () use (&$uploadedPath, &$convertedPath, &$finalReelsPath): void {
                foreach ([$finalReelsPath, $convertedPath, $uploadedPath] as $path) {
                    if (!is_string($path) || $path === '') {
                        continue;
                    }

                    if (file_exists($path)) {
                        @unlink($path);
                    }
                }
            };

            if ($ext !== 'mp4') {
                $converted = convertToMp4Format($ffmpegBinary, $uploadedPath, $convertedDir, $uploadedFileName);
                if (!$converted) {
                    $cleanupTemporaryFiles();
                    $this->logUploadError('convert_failed', ['source' => $uploadedPath]);
                    echo json_encode(['status' => 'error', 'message' => customLang('mp4_conversion_failed'), 'debug_ref' => $debugRef]);
                    exit;
                }
                $convertedPath = $converted;
                $checkDurationPath = $convertedPath;
            } else {
                if (!file_exists($convertedDir)) {
                    mkdir($convertedDir, 0755, true);
                }
                if (!@rename($uploadedPath, $convertedPath)) {
                    $this->logUploadError('rename_failed', [
                        'from' => $uploadedPath,
                        'to' => $convertedPath,
                        'error' => error_get_last(),
                    ]);
                    $cleanupTemporaryFiles();
                    echo json_encode(['status' => 'error', 'message' => customLang('upload_failed'), 'debug_ref' => $debugRef]);
                    exit;
                }
                $checkDurationPath = $convertedPath;
            }

            $escapedFfprobeBinary = escapeshellarg($ffprobeBinary);
            $ffprobeCmd = $escapedFfprobeBinary . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($checkDurationPath);
            $durationOutput = \shell_exec($ffprobeCmd);
            $duration = (float) $durationOutput;

            $this->logUploadError('duration_checked', [
                'duration' => $duration,
                'raw' => trim((string) $durationOutput),
            ]);

            if (defined('APP_DEBUG') && APP_DEBUG) {
                @file_put_contents('duration_debug.log', "CMD: $ffprobeCmd\nDURATION_RAW: $durationOutput\nDURATION: $duration\n", FILE_APPEND);
            }

            if ($duration === 0.0) {
                $cleanupTemporaryFiles();
                $this->logUploadError('duration_failed', ['path' => $checkDurationPath]);
                echo json_encode(['status' => 'error', 'message' => customLang('unable_to_read_video_duration'), 'debug_ref' => $debugRef]);
                exit;
            }

            if ($duration > MAX_VIDEO_DURATION) {
                $cleanupTemporaryFiles();
                $this->logUploadError('duration_exceeds', ['duration' => $duration]);
                echo json_encode([
                    'status' => 'error',
                    'message' => strtr(customLang('video_length_exceeds_limit'), ['{seconds}' => (string)MAX_VIDEO_DURATION]),
                    'debug_ref' => $debugRef,
                ]);
                exit;
            }

            $reelsDir = '../uploads/reels/' . $todayDir;
            if (!file_exists($reelsDir)) {
                mkdir($reelsDir, 0755, true);
            }

            $finalReelsPath = convertVideoToBlurredReelsFormat($convertedPath, $reelsDir, $ffmpegBinary);
            if (!$finalReelsPath || !file_exists($finalReelsPath)) {
                $cleanupTemporaryFiles();
                $this->logUploadError('reels_convert_failed', [
                    'source' => $convertedPath,
                    'dest_dir' => $reelsDir,
                ]);
                echo json_encode(['status' => 'error', 'message' => customLang('reels_conversion_failed'), 'debug_ref' => $debugRef]);
                exit;
            }

            $thumbnailPath = createVideoThumbnailInSameDir($ffmpegBinary, $finalReelsPath);
            if (!$thumbnailPath) {
                $thumbnailPath = 'themes/default/assets/img/placeholder.svg';
            }

            if (file_exists($convertedPath) && realpath($convertedPath) !== realpath($finalReelsPath)) {
                @unlink($convertedPath);
            }

            if (file_exists($uploadedPath) && realpath($uploadedPath) !== realpath($finalReelsPath)) {
                @unlink($uploadedPath);
            }

            $relativeVideo = ltrim(str_replace('../', '', $finalReelsPath), '/');
            $relativePoster = ltrim(str_replace('../', '', $thumbnailPath), '/');

            $shouldPublishToStorage = false;
            try {
                $shouldPublishToStorage = storage_manager()->isRemote();
            } catch (Throwable $__) {
                $shouldPublishToStorage = false;
            }

            if ($shouldPublishToStorage) {
                try {
                    $videoResult = storage_publish_relative($relativeVideo, 'video/mp4', 'public');
                    $relativeVideo = $videoResult->getRemoteKey();
                } catch (Throwable $storageFailure) {
                    $cleanupTemporaryFiles();
                    $this->logUploadError('storage_publish_failed', [
                        'error' => $storageFailure->getMessage(),
                        'path' => $relativeVideo,
                    ]);
                    echo json_encode(['status' => 'error', 'message' => customLang('upload_failed'), 'debug_ref' => $debugRef]);
                    exit;
                }
            }

            if ($shouldPublishToStorage && $thumbnailPath && $relativePoster !== '' && file_exists($thumbnailPath)) {
                try {
                    $posterResult = storage_publish_relative($relativePoster, storage_guess_content_type($relativePoster), 'public');
                    $relativePoster = $posterResult->getRemoteKey();
                } catch (Throwable $thumbException) {
                    $this->logUploadError('poster_publish_failed', [
                        'error' => $thumbException->getMessage(),
                        'path' => $relativePoster,
                    ]);
                }
            }

            $videoUrl = storage_url($relativeVideo);
            $posterUrl = storage_url($relativePoster);

            $this->logUploadError('success', [
                'video' => $relativeVideo,
                'poster' => $relativePoster,
            ]);

            echo json_encode([
                'status'    => 'success',
                'video_url' => $videoUrl,
                'poster'    => $posterUrl
            ]);
            exit;
        } catch (Throwable $e) {
            $this->logUploadError('exception', [
                'message' => $e->getMessage(),
                'location' => $e->getFile() . ':' . $e->getLine(),
            ]);
            echo json_encode([
                'status' => 'error',
                'message' => customLang('server_error'),
                'debug_ref' => $debugRef,
            ]);
            exit;
        }
    }

    public function handleFinalizeReel(): void
    {
        global $premiumPostPriceMinimum, $premiumPostPriceMaximum, $userID, $RL, $currentTheme, $ffmpegPath, $ffprobePath, $lockedPreviewModeVideos, $availableFileExtensions, $availableUploadFileSize;

        $RL = $this->repository;

        header('Content-Type: application/json');

        if (!isset($userID) || (int) $userID <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            exit;
        }

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!function_exists('checkCsrfToken') || !checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }

        $videoUrl   = isset($_POST['video_url'])   ? trim($_POST['video_url'])    : '';
        $posterUrl  = isset($_POST['poster_url'])  ? trim($_POST['poster_url'])   : '';
        $trimStart  = isset($_POST['trim_start'])  ? (float) $_POST['trim_start'] : 0.0;
        $trimEnd    = isset($_POST['trim_end'])    ? (float) $_POST['trim_end']   : 0.0;
        $duration   = isset($_POST['duration'])    ? (float) $_POST['duration']   : 0.0;

        $postTitle  = trim(strip_tags((string) ($_POST['post_title'] ?? '')));
        $postTitle  = function_exists('mb_substr') ? mb_substr($postTitle, 0, 255, 'UTF-8') : substr($postTitle, 0, 255);
        $postText   = trim($_POST['post_text'] ?? '');
        $visibility = $_POST['visibility'] ?? 'everyone';
        $setPrice   = trim($_POST['set_price'] ?? '');
        $minPrice   = (int)($_POST['min_price'] ?? ($premiumPostPriceMinimum ?? 1));
        $maxPrice   = (int)($_POST['max_price'] ?? ($premiumPostPriceMaximum ?? 500));
        $hideLikes  = ($_POST['hide_likes_view'] ?? 'off') === 'on' ? 'on' : 'off';
        $noComment  = ($_POST['turn_on_off_comment'] ?? 'off') === 'on' ? 'on' : 'off';

        $lockedPreviewPath = null;
        $lockedPreviewType = null;
        $lockedPreviewAbsolute = null;
        $lockedPreviewWarning = null;
        $teaserRelative = '';
        $teaserAbsolute = '';

        $allowedVisibility = ['everyone', 'followers', 'subscribers', 'locked'];
        if (!in_array($visibility, $allowedVisibility, true)) {
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_visibility_type')]);
            exit;
        }

        $price = null;
        if ($visibility === 'locked') {
            if ($setPrice !== '') {
                $price = filter_var($setPrice, FILTER_VALIDATE_INT);
                if ($price === false) {
                    echo json_encode(['status' => 'error', 'message' => customLang('price_must_be_an_integer')]); exit;
                }
                if ($price < $minPrice || $price > $maxPrice) {
                    echo json_encode(['status' => 'error', 'message' => customLang('price_range_error')]); exit;
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => customLang('price_required_for_locked_visibility')]); exit;
            }
        }

        if ($videoUrl === '') {
            echo json_encode(['status' => 'error', 'message' => customLang('ads_missing_video_url')]); exit;
        }
        if (!is_finite($trimStart) || !is_finite($trimEnd) || $trimStart < 0 || $trimEnd <= 0 || $trimStart >= $trimEnd) {
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_trim_range')]); exit;
        }
        if ($duration > 0 && $trimEnd > $duration + 0.01) {
            echo json_encode(['status' => 'error', 'message' => customLang('trim_exceeds_duration')]); exit;
        }

        $sourceRel = storage_relative_from_url($videoUrl);
        $sourcePath = $sourceRel !== '' ? storage_resolve_absolute_path($sourceRel) : '';

        if ($sourceRel === '' || !is_file($sourcePath)) {
            $legacyRel = ltrim(str_replace(['..\\', '../'], '', $videoUrl), '/');
            $legacyPath = '../' . $legacyRel;

            if ($sourceRel === '') {
                $sourceRel = $legacyRel;
            }

            if (is_file($legacyPath)) {
                $sourcePath = $legacyPath;
            }
        }

        if ($sourceRel === '' || !is_file($sourcePath)) {
            echo json_encode(['status' => 'error', 'message' => customLang('source_video_not_found')]); exit;
        }

        $todayDir = date('Y-m-d');
        $outDir   = '../uploads/reels/' . $todayDir;
        if (!file_exists($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $srcBase = pathinfo($sourcePath, PATHINFO_FILENAME);
        $outName = $srcBase . '_trim_' . $userID . '_' . time() . '.mp4';
        $outPath = rtrim($outDir, '/') . '/' . $outName;

        $ffmpegBin = dz_resolve_binary(isset($ffmpegPath) ? (string) $ffmpegPath : null, ['ffmpeg']);
        if (!is_string($ffmpegBin) || $ffmpegBin === '') {
            echo json_encode([
                'status' => 'error',
                'message' => 'ffmpeg_unavailable',
                'message_text' => 'FFmpeg binary is not available on the server. Configure the correct path in admin settings.'
            ]);
            exit;
        }
        $ffmpegBin = (string) $ffmpegBin;
        $formatTime = static function (float $value): string {
            $formatted = number_format($value, 4, '.', '');
            $trimmed = rtrim(rtrim($formatted, '0'), '.');
            return $trimmed === '' ? '0' : $trimmed;
        };
        $ss = max(0.0, (float) $trimStart);
        $to = max(0.0, (float) $trimEnd);
        $durationSeconds = $to - $ss;
        if ($durationSeconds <= 0.0) {
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_trim_range')]);
            exit;
        }
        $ssArg = escapeshellarg($formatTime($ss));
        $durationArg = escapeshellarg($formatTime($durationSeconds));

        $runFfmpegTrim = static function (string $videoCodec, string $audioCodec, bool $useStrict) use ($ffmpegBin, $ssArg, $durationArg, $sourcePath, $outPath): array {
            if (is_file($outPath)) {
                @unlink($outPath);
            }
            $cmd = escapeshellarg($ffmpegBin) . ' -y ' .
                   '-ss ' . $ssArg . ' -i ' . escapeshellarg($sourcePath) . ' ' .
                   '-t ' . $durationArg . ' ' .
                   '-c:v ' . escapeshellarg($videoCodec) . ' -preset veryfast -crf 23 ' .
                   '-c:a ' . escapeshellarg($audioCodec);
            if ($useStrict) {
                $cmd .= ' -strict -2';
            }
            $cmd .= ' -movflags +faststart ' .
                   escapeshellarg($outPath) . ' 2>&1';
            $output = \shell_exec($cmd);
            $success = is_file($outPath) && filesize($outPath) > 0;
            return [$success, $output, $cmd];
        };

        [$success, $output, $cmd] = $runFfmpegTrim('libx264', 'aac', true);
        if (!$success) {
            $retryVideoCodec = null;
            $retryAudioCodec = null;
            if (stripos((string) $output, "Unknown encoder 'libx264'") !== false ||
                stripos((string) $output, 'Unknown encoder "libx264"') !== false) {
                $retryVideoCodec = 'h264';
            }
            if (stripos((string) $output, "Unknown encoder 'aac'") !== false ||
                stripos((string) $output, 'Unknown encoder "aac"') !== false) {
                $retryAudioCodec = 'copy';
            }
            if (stripos((string) $output, "encoder 'aac' is experimental") !== false) {
                $retryAudioCodec = 'aac';
            }
            if (stripos((string) $output, "use the non experimental encoder 'libfdk_aac'") !== false) {
                $retryAudioCodec = 'libfdk_aac';
            }
            if ($retryVideoCodec !== null || $retryAudioCodec !== null) {
                $fallbackVideo = $retryVideoCodec ?? 'libx264';
                $fallbackAudio = $retryAudioCodec ?? 'aac';
                $fallbackStrict = true;
                if ($fallbackAudio === 'copy' || $fallbackAudio === 'libfdk_aac') {
                    $fallbackStrict = false;
                }
                [$success, $output, $cmd] = $runFfmpegTrim($fallbackVideo, $fallbackAudio, $fallbackStrict);
            }
        }

        if (!$success) {
            if (defined('APP_DEBUG') && APP_DEBUG) {
                error_log('[finalize_video] FFmpeg command failed. CMD=' . $cmd . ' OUTPUT=' . $output);
            }
            echo json_encode(['status' => 'error', 'message' => 'FFmpeg finalize failed.']);
            exit;
        }

        require_once dirname(__DIR__, 2) . '/includes/helpers/createVideoThumbnailInSameDir.php';
        $thumbPath = createVideoThumbnailInSameDir($ffmpegBin, $outPath);
        if (!$thumbPath) {
            $thumbPath = 'themes/default/assets/img/placeholder.svg';
        }

        $createdTime   = time();
        $relativeVideo = ltrim(str_replace('../', '', $outPath), '/');
        $relativeThumb = ltrim(str_replace('../', '', $thumbPath), '/');
        $approvalStatus = (!empty($postApprovalRequired)) ? 'pending' : 'approved';
        $approvalStatus = $approvalStatus ?: 'approved';
        $approvedAt = $approvalStatus === 'approved' ? $createdTime : null;

        $shouldPublishToStorage = false;
        try {
            $shouldPublishToStorage = storage_manager()->isRemote();
        } catch (Throwable $__) {
            $shouldPublishToStorage = false;
        }

        $teaserFile = $_FILES['video_teaser'] ?? null;
        $hasTeaserUpload = is_array($teaserFile)
            && isset($teaserFile['tmp_name'])
            && is_uploaded_file($teaserFile['tmp_name']);
        if ($hasTeaserUpload && in_array($visibility, ['subscribers', 'locked'], true)) {
            $teaserName = stripslashes((string) ($teaserFile['name'] ?? ''));
            $teaserSize = (int) ($teaserFile['size'] ?? 0);
            $teaserTmp = (string) ($teaserFile['tmp_name'] ?? '');
            $teaserExt = strtolower(getExtension($teaserName));

            $validFormats = explode(',', (string) $availableFileExtensions);
            $normalizedFormats = array_values(array_filter(array_map(static function ($format): string {
                return strtolower(trim((string) $format));
            }, $validFormats)));

            $detectedMime = '';
            if ($teaserTmp !== '' && is_file($teaserTmp) && function_exists('finfo_open')) {
                $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $detectedMime = (string) @finfo_file($finfo, $teaserTmp);
                    @finfo_close($finfo);
                }
            }
            if ($detectedMime === '' && $teaserTmp !== '' && is_file($teaserTmp) && function_exists('mime_content_type')) {
                $detectedMime = (string) @mime_content_type($teaserTmp);
            }
            $detectedMime = strtolower(trim($detectedMime));
            $semicolonPos = strpos($detectedMime, ';');
            if ($semicolonPos !== false) {
                $detectedMime = trim(substr($detectedMime, 0, $semicolonPos));
            }

            $extensionMimeMap = [
                'mp4' => ['video/mp4', 'video/x-m4v'],
                'm4v' => ['video/mp4', 'video/x-m4v'],
                'mov' => ['video/quicktime'],
                'webm' => ['video/webm'],
                'mkv' => ['video/x-matroska'],
                'avi' => ['video/x-msvideo'],
                '3gp' => ['video/3gpp', 'video/3gpp2'],
                '3g2' => ['video/3gpp2'],
                'flv' => ['video/x-flv'],
                'wmv' => ['video/x-ms-wmv'],
                'ogg' => ['video/ogg'],
                'ogv' => ['video/ogg'],
                'ts'  => ['video/mp2t'],
                'mpeg'=> ['video/mpeg'],
                'mpg' => ['video/mpeg'],
            ];

            $allowedMimeTypes = [];
            foreach ($normalizedFormats as $format) {
                if (isset($extensionMimeMap[$format])) {
                    foreach ($extensionMimeMap[$format] as $mime) {
                        $allowedMimeTypes[] = strtolower($mime);
                    }
                }
            }
            $allowedMimeTypes = array_values(array_unique($allowedMimeTypes));

            if ($detectedMime === '' || !str_starts_with($detectedMime, 'video/')) {
                if (isset($extensionMimeMap[$teaserExt]) && $extensionMimeMap[$teaserExt] !== []) {
                    $detectedMime = $extensionMimeMap[$teaserExt][0];
                }
            }

            if ($detectedMime === '' || !str_starts_with($detectedMime, 'video/')) {
                echo json_encode(['status' => 'error', 'message' => customLang('video_teaser_invalid_format')]);
                exit;
            }
            if (!empty($allowedMimeTypes) && !in_array($detectedMime, $allowedMimeTypes, true)) {
                echo json_encode(['status' => 'error', 'message' => customLang('video_teaser_invalid_format')]);
                exit;
            }
            if (!empty($normalizedFormats) && !in_array($teaserExt, $normalizedFormats, true)) {
                echo json_encode(['status' => 'error', 'message' => customLang('video_teaser_invalid_format')]);
                exit;
            }
            if (isset($availableUploadFileSize) && $availableUploadFileSize > 0 && convert_to_mb($teaserSize) > $availableUploadFileSize) {
                echo json_encode(['status' => 'error', 'message' => customLang('file_too_large')]);
                exit;
            }

            $ffprobeBin = dz_resolve_binary(isset($ffprobePath) ? (string) $ffprobePath : null, ['ffprobe']);
            if (!is_string($ffprobeBin) || $ffprobeBin === '') {
                echo json_encode(['status' => 'error', 'message' => customLang('video_teaser_upload_failed')]);
                exit;
            }
            $ffprobeCmd = escapeshellarg($ffprobeBin) . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($teaserTmp);
            $durationOutput = \shell_exec($ffprobeCmd);
            $teaserDuration = (float) $durationOutput;
            if ($teaserDuration <= 0) {
                echo json_encode(['status' => 'error', 'message' => customLang('video_teaser_invalid_format')]);
                exit;
            }
            $maxTeaserDuration = 60;
            if ($teaserDuration > $maxTeaserDuration) {
                echo json_encode([
                    'status' => 'error',
                    'message' => strtr(customLang('video_teaser_too_long'), ['{seconds}' => (string) $maxTeaserDuration])
                ]);
                exit;
            }

            $microtime = microtime();
            $removeMicrotime = preg_replace('/(0)\\.(\\d+) (\\d+)/', '$3$1$2', $microtime);
            $teaserBase = 'teaser_' . $removeMicrotime . '_' . $userID;
            $teaserDir = '../uploads/teasers/' . $todayDir . '/';
            if (!file_exists($teaserDir)) {
                mkdir($teaserDir, 0755, true);
            }
            $teaserUploadPath = $teaserDir . $teaserBase . '.' . $teaserExt;
            if (!move_uploaded_file($teaserTmp, $teaserUploadPath)) {
                echo json_encode(['status' => 'error', 'message' => customLang('video_teaser_upload_failed')]);
                exit;
            }

            $teaserFinalPath = $teaserUploadPath;
            if ($teaserExt !== 'mp4') {
                require_once dirname(__DIR__, 2) . '/includes/helpers/convertToMp4Format.php';
                $converted = convertToMp4Format($ffmpegBin, $teaserUploadPath, rtrim($teaserDir, '/'), $teaserBase);
                if (!$converted || !is_file($converted)) {
                    if (is_file($teaserUploadPath)) {
                        @unlink($teaserUploadPath);
                    }
                    echo json_encode(['status' => 'error', 'message' => customLang('video_teaser_upload_failed')]);
                    exit;
                }
                $teaserFinalPath = $converted;
                if (is_file($teaserUploadPath) && realpath($teaserUploadPath) !== realpath($teaserFinalPath)) {
                    @unlink($teaserUploadPath);
                }
            }

            $teaserRelative = ltrim(str_replace('../', '', $teaserFinalPath), '/');
            $teaserAbsolute = $teaserFinalPath;

            if ($shouldPublishToStorage) {
                try {
                    $teaserResult = storage_publish_relative($teaserRelative, 'video/mp4', 'public');
                    $teaserRelative = $teaserResult->getRemoteKey();
                } catch (Throwable $teaserStorageFailure) {
                    if ($teaserAbsolute && is_file($teaserAbsolute)) {
                        @unlink($teaserAbsolute);
                    }
                    echo json_encode(['status' => 'error', 'message' => customLang('video_teaser_upload_failed')]);
                    exit;
                }
            }
        }

        $shouldGenerateLockedPreview = in_array($visibility, ['subscribers', 'locked'], true)
            && isset($lockedPreviewModeVideos)
            && $lockedPreviewModeVideos !== 'off';

        if ($shouldGenerateLockedPreview) {
            $previewResult = $this->buildLockedPreviewAsset(
                (string) $lockedPreviewModeVideos,
                $ffmpegBin,
                $outPath,
                $shouldPublishToStorage
            );

            if (!empty($previewResult['path']) && !empty($previewResult['type'])) {
                $lockedPreviewPath = (string) $previewResult['path'];
                $lockedPreviewType = (string) $previewResult['type'];
                if (
                    $lockedPreviewWarning === null &&
                    $lockedPreviewModeVideos === 'animated' &&
                    $lockedPreviewType !== 'animated'
                ) {
                    $lockedPreviewWarning = customLang('locked_preview_fallback_static')
                        ?: 'Animated preview was unavailable. A static pixelated frame was generated instead.';
                }
            } elseif (!empty($previewResult['error'])) {
                $lockedPreviewWarning = customLang('locked_preview_generation_failed')
                    ?: 'Locked preview could not be generated. Upload completed without it.';
            }

            if (!empty($previewResult['absolute'])) {
                $lockedPreviewAbsolute = (string) $previewResult['absolute'];
            }
        }

        if ($shouldPublishToStorage) {
            try {
                $videoResult = storage_publish_relative($relativeVideo, 'video/mp4', 'public');
                $relativeVideo = $videoResult->getRemoteKey();
            } catch (Throwable $storageFailure) {
                @unlink($outPath);
                echo json_encode(['status' => 'error', 'message' => customLang('upload_failed')]);
                exit;
            }
        }

        if ($shouldPublishToStorage && $thumbPath && $relativeThumb !== '' && file_exists($thumbPath)) {
            try {
                $thumbResult = storage_publish_relative($relativeThumb, storage_guess_content_type($relativeThumb), 'public');
                $relativeThumb = $thumbResult->getRemoteKey();
            } catch (Throwable $thumbError) {
            }
        }

        $dbInserted = false;
        if (isset($RL) && is_object($RL) && method_exists($RL, 'RL_InsertCroppedVideo')) {
            $dbInserted = (bool) $RL->RL_InsertCroppedVideo(
                $userID,
                $relativeVideo,
                'video',
                $createdTime,
                $postText,
                $visibility,
                $price,
                $noComment,
                $hideLikes,
                (int) round($durationSeconds),
                null,
                $lockedPreviewPath,
                $lockedPreviewType,
                $approvalStatus,
                null,
                $approvedAt,
                null,
                $postTitle
            );
        }

        $finalUrl = storage_url($relativeVideo);
        $poster   = storage_url($relativeThumb);

        $html = '';
        $htmlEncoding = 'none';
        $postIdForJson = 0;
        if ($dbInserted) {
            $post = $RL->RL_GetNewPost($userID, $relativeVideo, $createdTime, $userID);

            if ($post) {
                $postIdForJson = (int) $post['post_id'];
                try {
                    if (isset($RL) && method_exists($RL,'getDb')) {
                        $db = $RL->getDb();
                        if (isset($RL) && method_exists($RL,'RL_AddPostMedia')) {
                            $RL->RL_AddPostMedia($postIdForJson, $relativeVideo, 'reel');
                            $RL->RL_AddPostMedia($postIdForJson, $relativeThumb, 'thumb');
                            if ($lockedPreviewPath) {
                                $RL->RL_AddPostMedia($postIdForJson, $lockedPreviewPath, 'locked_preview');
                            }
                            if ($teaserRelative !== '') {
                                $RL->RL_AddPostMedia($postIdForJson, $teaserRelative, 'teaser');
                            }
                            $sourceKey = storage_relative_from_url($videoUrl);
                            if ($sourceKey === '') {
                                $sourceKey = $sourceRel;
                            }
                            if ($sourceKey !== '') {
                                $RL->RL_AddPostMedia($postIdForJson, $sourceKey, 'source');
                            }
                        }
                    }
                } catch (Throwable $__e) { }
            }

            $layoutFile = dirname(__DIR__, 2) . '/themes/' . $currentTheme . '/layouts/newCroppedPost.php';
            if ($post && is_file($layoutFile)) {
                $post['poster'] = $relativeThumb;

                ob_start();
                include $layoutFile;
                $html = ob_get_clean();
                if ($html !== '') {
                    try {
                        $encoded = base64_encode($html);
                        if ($encoded !== false) {
                            $html = $encoded;
                            $htmlEncoding = 'base64';
                        }
                    } catch (Throwable $__) {
                        $htmlEncoding = 'none';
                    }
                }
            }
        }

        if (!$dbInserted && $lockedPreviewPath !== null && $lockedPreviewPath !== '') {
            try {
                if (function_exists('storage_delete')) {
                    storage_delete($lockedPreviewPath);
                }
            } catch (Throwable $__) {
                // ignore cleanup failure
            }
            if ($lockedPreviewAbsolute && is_file($lockedPreviewAbsolute)) {
                @unlink($lockedPreviewAbsolute);
            }
        }
        if (!$dbInserted && $teaserRelative !== '') {
            try {
                if (function_exists('storage_delete')) {
                    storage_delete($teaserRelative);
                }
            } catch (Throwable $__) {
            }
            if ($teaserAbsolute && is_file($teaserAbsolute)) {
                @unlink($teaserAbsolute);
            }
        }

        $uploadsRoot = realpath(dirname(__DIR__, 2) . '/uploads');
        $keep = [
            realpath($outPath),
            realpath('../' . ltrim(str_replace(['..\\', '../'], '', $relativeThumb), '/')),
        ];

        $srcReal = realpath($sourcePath);
        if ($srcReal && $uploadsRoot && strpos($srcReal, $uploadsRoot) === 0 && !in_array($srcReal, $keep, true)) {
            @unlink($srcReal);
        }

        if (!empty($posterUrl)) {
            $posterRel = storage_relative_from_url($posterUrl);
            $posterOldAbs = $posterRel !== '' ? storage_resolve_absolute_path($posterRel) : false;
            $newThumbAbs  = $relativeThumb !== '' ? storage_resolve_absolute_path($relativeThumb) : false;

            if ($posterOldAbs && is_file($posterOldAbs) && $uploadsRoot && strpos($posterOldAbs, $uploadsRoot) === 0 && $posterOldAbs !== $newThumbAbs) {
                @unlink($posterOldAbs);
            }
        }

        $successMessage = customLang('reel_upload_success', 'Upload successful.');
        if ($approvalStatus === 'pending') {
            $successMessage = customLang('reel_upload_pending', 'Post submitted for review. It will be visible after admin approval.');
        }
        $failureMessage = customLang('reel_upload_failed', 'DB insert failed.');

        $response = [
            'status'           => $dbInserted ? 'success' : 'error',
            'message'          => $dbInserted ? $successMessage : $failureMessage,
            'final_video_url'  => $finalUrl,
            'poster'           => $poster,
            'html'             => $html,
            'html_encoding'    => $htmlEncoding,
            'post_id'          => $postIdForJson,
        ];
        if ($dbInserted) {
            $response['approval_status'] = $approvalStatus;
        }

        if ($lockedPreviewWarning !== null) {
            $response['locked_preview_warning'] = $lockedPreviewWarning;
        }

        $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }

        $encoded = json_encode($response, $jsonFlags);
        if ($encoded === false) {
            // Fallback: strip potentially problematic payload and retry encoding.
            $response['html'] = '';
            $encoded = json_encode($response, $jsonFlags);
            if ($encoded === false) {
                $encoded = json_encode([
                    'status'          => 'error',
                    'message'         => 'Unable to encode response payload.',
                    'final_video_url' => $finalUrl,
                    'poster'          => $poster,
                    'post_id'         => $postIdForJson,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        echo (string) $encoded;
        exit;
    }

    public function handleUploadTempPodcast(): void
    {
        global $availableUploadFileSize, $maximumAudioDuration, $maximumVideoDuration, $userID, $RL, $enablePodcastPosts, $ffprobePath;

        $RL = $this->repository;
        header('Content-Type: application/json');

        $podcastsEnabled = isset($enablePodcastPosts) ? (bool) $enablePodcastPosts : true;

        if (!isset($userID) || (int) $userID <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            exit;
        }

        if (!$podcastsEnabled) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('content_disabled_podcasts', 'Podcast sharing is currently disabled.')]);
            exit;
        }

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!function_exists('checkCsrfToken') || !checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }

        $fileArr = null;
        if (isset($_FILES['audio']) && is_uploaded_file($_FILES['audio']['tmp_name'])) {
            $fileArr = [
                'name' => $_FILES['audio']['name'],
                'size' => $_FILES['audio']['size'],
                'type' => $_FILES['audio']['type'] ?? '',
                'tmp_name' => $_FILES['audio']['tmp_name'],
            ];
        } elseif (isset($_FILES['uploading']['tmp_name'][0]) && is_uploaded_file($_FILES['uploading']['tmp_name'][0])) {
            $fileArr = [
                'name' => $_FILES['uploading']['name'][0],
                'size' => $_FILES['uploading']['size'][0],
                'type' => $_FILES['uploading']['type'][0] ?? '',
                'tmp_name' => $_FILES['uploading']['tmp_name'][0],
            ];
        }

        if (!$fileArr) {
            echo json_encode(['status' => 'error', 'message' => customLang('podcast_missing_audio', 'No audio uploaded.')]);
            exit;
        }

        $name = stripslashes($fileArr['name']);
        $size = (int) $fileArr['size'];
        $tmpPath = (string) $fileArr['tmp_name'];
        $ext = strtolower(getExtension($name));

        $maxMb = isset($availableUploadFileSize) ? (float) $availableUploadFileSize : 10.0;
        if ($maxMb <= 0) {
            $maxMb = 10.0;
        }
        $maxBytes = (int) round($maxMb * 1048576);
        if ($size <= 0 || $size > $maxBytes) {
            echo json_encode(['status' => 'error', 'message' => customLang('podcast_file_too_large', 'Audio file is too large.')]);
            exit;
        }

        $detectedMime = '';
        if ($tmpPath !== '' && is_file($tmpPath) && function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detectedMime = (string) @finfo_file($finfo, $tmpPath);
                @finfo_close($finfo);
            }
        }
        if ($detectedMime === '' && $tmpPath !== '' && is_file($tmpPath) && function_exists('mime_content_type')) {
            $detectedMime = (string) @mime_content_type($tmpPath);
        }
        if ($detectedMime === '' && !empty($fileArr['type'])) {
            $detectedMime = (string) $fileArr['type'];
        }

        $detectedMime = strtolower(trim($detectedMime));
        if ($detectedMime !== '') {
            $semiPos = strpos($detectedMime, ';');
            if ($semiPos !== false) {
                $detectedMime = trim(substr($detectedMime, 0, $semiPos));
            }
        }

        $allowedExt = ['mp3', 'm4a'];
        $allowedMimes = [
            'audio/mpeg',
            'audio/mp3',
            'audio/x-mp3',
            'audio/mp4',
            'audio/x-m4a',
            'audio/m4a',
            'audio/aac'
        ];

        if (!in_array($ext, $allowedExt, true)) {
            echo json_encode(['status' => 'error', 'message' => customLang('podcast_only_audio_allowed', 'Only MP3 or M4A audio files are allowed.')]);
            exit;
        }
        if ($detectedMime !== '' && !in_array($detectedMime, $allowedMimes, true)) {
            echo json_encode(['status' => 'error', 'message' => customLang('podcast_only_audio_allowed', 'Only MP3 or M4A audio files are allowed.')]);
            exit;
        }

        $durationSeconds = null;
        $ffprobeBinary = dz_resolve_binary(isset($ffprobePath) ? (string) $ffprobePath : null, ['ffprobe']);
        if ($ffprobeBinary) {
            $cmd = escapeshellarg((string) $ffprobeBinary) . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($tmpPath) . ' 2>&1';
            $out = @shell_exec($cmd);
            if (is_string($out)) {
                $out = trim($out);
                if ($out !== '' && is_numeric($out)) {
                    $durationSeconds = (int) round((float) $out);
                }
            }
        }

        $maxDuration = isset($maximumAudioDuration) ? (int) $maximumAudioDuration : null;
        if ((!$maxDuration || $maxDuration <= 0) && isset($maximumVideoDuration)) {
            $maxDuration = (int) $maximumVideoDuration;
        }
        if (!$maxDuration || $maxDuration <= 0) {
            $maxDuration = 7200; // default 2h limit
        } elseif ($maxDuration > 0 && $maxDuration < 100000) {
            // Stored as minutes in admin panel
            $maxDuration = $maxDuration * 60;
        }
        if ($durationSeconds !== null && $durationSeconds > $maxDuration) {
            echo json_encode(['status' => 'error', 'message' => customLang('podcast_duration_limit', 'Audio exceeds the maximum allowed duration.')]);
            exit;
        }

        $dateFolder = date('Y-m-d');
        $absDir = dirname(__DIR__, 2) . '/uploads/podcasts/' . $dateFolder;
        if (!is_dir($absDir)) {
            @mkdir($absDir, 0755, true);
        }

        try {
            $rand = bin2hex(random_bytes(8));
        } catch (Throwable $__) {
            $rand = str_replace('.', '', uniqid('', true));
        }
        $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '', pathinfo($name, PATHINFO_FILENAME));
        if ($safeBase === '') {
            $safeBase = 'audio';
        }
        $targetName = $safeBase . '_' . $userID . '_' . $rand . '.' . $ext;
        $absolutePath = rtrim($absDir, '/') . '/' . $targetName;

        if (!@move_uploaded_file($tmpPath, $absolutePath)) {
            echo json_encode(['status' => 'error', 'message' => customLang('upload_failed')]);
            exit;
        }

        $relativePath = 'uploads/podcasts/' . $dateFolder . '/' . $targetName;
        $shouldPublishToStorage = false;
        try {
            $shouldPublishToStorage = storage_manager()->isRemote();
        } catch (Throwable $__) {
            $shouldPublishToStorage = false;
        }

        if ($shouldPublishToStorage) {
            try {
                $contentType = function_exists('storage_guess_content_type') ? storage_guess_content_type($relativePath) : 'audio/mpeg';
                $publish = storage_publish_relative($relativePath, $contentType, 'public');
                $relativePath = $publish->getRemoteKey();
            } catch (Throwable $publishErr) {
                echo json_encode(['status' => 'error', 'message' => customLang('upload_failed')]);
                exit;
            }
        }

        $audioUrl = storage_url($relativePath);

        echo json_encode([
            'status' => 'success',
            'audio_url' => $audioUrl,
            'duration_seconds' => $durationSeconds
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function handleFinalizePodcast(): void
    {
        global $premiumPostPriceMinimum, $premiumPostPriceMaximum, $userID, $RL, $currentTheme, $postApprovalRequired, $enablePodcastPosts, $maximumAudioDuration, $maximumVideoDuration, $availableUploadFileSize, $ffprobePath;

        $RL = $this->repository;

        header('Content-Type: application/json');

        $podcastsEnabled = isset($enablePodcastPosts) ? (bool) $enablePodcastPosts : true;

        if (!isset($userID) || (int) $userID <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            exit;
        }
        if (!$podcastsEnabled) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('content_disabled_podcasts', 'Podcast sharing is currently disabled.')]);
            exit;
        }

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!function_exists('checkCsrfToken') || !checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }

        $audioUrl = isset($_POST['audio_url']) ? trim((string) $_POST['audio_url']) : '';
        $audioDuration = $_POST['duration'] ?? ($_POST['audio_duration'] ?? null);
        if ($audioDuration !== null) {
            $audioDuration = (float) $audioDuration;
            if (!is_finite($audioDuration)) {
                $audioDuration = null;
            }
        }

        $postTitle  = trim(strip_tags((string) ($_POST['post_title'] ?? '')));
        $postTitle  = function_exists('mb_substr') ? mb_substr($postTitle, 0, 255, 'UTF-8') : substr($postTitle, 0, 255);
        $postText   = trim($_POST['post_text'] ?? '');
        $visibility = $_POST['visibility'] ?? 'everyone';
        $setPrice   = trim($_POST['set_price'] ?? '');
        $minPrice   = (int)($premiumPostPriceMinimum ?? 1);
        $maxPrice   = (int)($premiumPostPriceMaximum ?? 500);
        $hideLikes  = ($_POST['hide_likes_view'] ?? 'off') === 'on' ? 'on' : 'off';
        $noComment  = ($_POST['turn_on_off_comment'] ?? 'off') === 'on' ? 'on' : 'off';
        $rawCategoryId = isset($_POST['podcast_category_id']) ? (int) $_POST['podcast_category_id'] : 0;
        $podcastCategoryId = null;
        if ($rawCategoryId > 0 && isset($RL) && method_exists($RL, 'RL_GetPodcastCategoryById')) {
            $categoryRow = $RL->RL_GetPodcastCategoryById($rawCategoryId);
            if ($categoryRow && strtolower((string)($categoryRow['status'] ?? '')) === 'active') {
                $podcastCategoryId = (int) ($categoryRow['id'] ?? 0);
            }
        }

        $allowedVisibility = ['everyone', 'followers', 'subscribers', 'locked'];
        if (!in_array($visibility, $allowedVisibility, true)) {
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_visibility_type')]);
            exit;
        }

        $price = null;
        if ($visibility === 'locked') {
            if ($setPrice !== '') {
                $price = filter_var($setPrice, FILTER_VALIDATE_INT);
                if ($price === false) {
                    echo json_encode(['status' => 'error', 'message' => customLang('price_must_be_an_integer')]);
                    exit;
                }
                if ($price < $minPrice || $price > $maxPrice) {
                    echo json_encode(['status' => 'error', 'message' => customLang('price_range_error')]);
                    exit;
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => customLang('price_required_for_locked_visibility')]);
                exit;
            }
        }

        if ($audioUrl === '') {
            echo json_encode(['status' => 'error', 'message' => customLang('podcast_missing_audio', 'No audio uploaded.')]);
            exit;
        }

        $sourceRel = storage_relative_from_url($audioUrl);
        $sourcePath = $sourceRel !== '' ? storage_resolve_absolute_path($sourceRel) : '';

        if ($sourceRel === '' || !is_file($sourcePath)) {
            $legacyRel = ltrim(str_replace(['..\\', '../'], '', $audioUrl), '/');
            $legacyPath = '../' . $legacyRel;

            if ($sourceRel === '') {
                $sourceRel = $legacyRel;
            }

            if (is_file($legacyPath)) {
                $sourcePath = $legacyPath;
            }
        }

        $storageIsRemote = false;
        try {
            $storageIsRemote = storage_manager()->isRemote();
        } catch (Throwable $__) {
            $storageIsRemote = false;
        }

        if (!$storageIsRemote && ($sourceRel === '' || !is_file($sourcePath))) {
            echo json_encode(['status' => 'error', 'message' => customLang('source_video_not_found')]);
            exit;
        }

        $maxDuration = isset($maximumAudioDuration) ? (int) $maximumAudioDuration : null;
        if ((!$maxDuration || $maxDuration <= 0) && isset($maximumVideoDuration)) {
            $maxDuration = (int) $maximumVideoDuration;
        }
        if (!$maxDuration || $maxDuration <= 0) {
            $maxDuration = 7200;
        } elseif ($maxDuration > 0 && $maxDuration < 100000) {
            $maxDuration = $maxDuration * 60;
        }

        if ($audioDuration === null && !$storageIsRemote && $sourcePath !== '') {
            $ffprobeBinary = dz_resolve_binary(isset($ffprobePath) ? (string) $ffprobePath : null, ['ffprobe']);
            if ($ffprobeBinary) {
                $cmd = escapeshellarg((string) $ffprobeBinary) . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($sourcePath) . ' 2>&1';
                $out = @shell_exec($cmd);
                if (is_string($out)) {
                    $out = trim($out);
                    if ($out !== '' && is_numeric($out)) {
                        $audioDuration = (float) $out;
                    }
                }
            }
        }

        if ($audioDuration !== null && $audioDuration > $maxDuration) {
            echo json_encode(['status' => 'error', 'message' => customLang('podcast_duration_limit', 'Audio exceeds the maximum allowed duration.')]);
            exit;
        }

        $relativeAudio = $sourceRel;
        $shouldPublishToStorage = $storageIsRemote;
        if ($shouldPublishToStorage && $relativeAudio === '' && $storageIsRemote) {
            $relativeAudio = ltrim(str_replace(['..\\', '../'], '', $audioUrl), '/');
        }
        if ($relativeAudio === '') {
            echo json_encode(['status' => 'error', 'message' => customLang('source_video_not_found')]);
            exit;
        }

        $coverPath = '';
        if (isset($_FILES['cover']) && is_uploaded_file($_FILES['cover']['tmp_name'])) {
            $coverFile = $_FILES['cover'];
            $coverSize = (int) ($coverFile['size'] ?? 0);
            $coverTmp  = (string) ($coverFile['tmp_name'] ?? '');
            $coverMime = '';
            if ($coverTmp !== '' && function_exists('finfo_open')) {
                $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $coverMime = (string) @finfo_file($finfo, $coverTmp);
                    @finfo_close($finfo);
                }
            }
            if ($coverMime === '' && function_exists('mime_content_type')) {
                $coverMime = (string) @mime_content_type($coverTmp);
            }
            $coverMime = strtolower(trim($coverMime));
            $allowedCoverMimes = ['image/jpeg', 'image/png', 'image/webp'];

            $maxCoverMb = isset($availableUploadFileSize) ? (float) $availableUploadFileSize : 5.0;
            if ($maxCoverMb <= 0) {
                $maxCoverMb = 5.0;
            }
            $maxCoverBytes = (int) round($maxCoverMb * 1048576);

            if ($coverSize <= 0 || $coverSize > $maxCoverBytes) {
                echo json_encode(['status' => 'error', 'message' => customLang('file_too_large')]);
                exit;
            }
            if ($coverMime !== '' && !in_array($coverMime, $allowedCoverMimes, true)) {
                echo json_encode(['status' => 'error', 'message' => customLang('ui_only_images_allowed')]);
                exit;
            }

            $coverDate = date('Y-m-d');
            $coverDir = dirname(__DIR__, 2) . '/uploads/podcasts/' . $coverDate . '/';
            if (!is_dir($coverDir)) {
                @mkdir($coverDir, 0755, true);
            }
            try {
                $rand = bin2hex(random_bytes(6));
            } catch (Throwable $__) {
                $rand = str_replace('.', '', uniqid('', true));
            }
            $coverExt = strtolower(getExtension((string) $coverFile['name']));
            if ($coverExt === '') {
                $coverExt = 'jpg';
            }
            $coverName = 'cover_' . $userID . '_' . $rand . '.' . $coverExt;
            $coverAbs = rtrim($coverDir, '/') . '/' . $coverName;
            if (!@move_uploaded_file($coverTmp, $coverAbs)) {
                echo json_encode(['status' => 'error', 'message' => customLang('upload_failed')]);
                exit;
            }
            $coverPath = 'uploads/podcasts/' . $coverDate . '/' . $coverName;

            if ($storageIsRemote) {
                try {
                    $publishedCover = storage_publish_relative($coverPath, storage_guess_content_type($coverPath), 'public');
                    $coverPath = $publishedCover->getRemoteKey();
                } catch (Throwable $publishCoverErr) {
                    // leave cover path empty if publish fails; do not abort entire flow
                    $coverPath = '';
                }
            }
        }

        $createdTime = time();
        $approvalStatus = (!empty($postApprovalRequired)) ? 'pending' : 'approved';
        $approvalStatus = $approvalStatus ?: 'approved';
        $approvedAt = $approvalStatus === 'approved' ? $createdTime : null;

        $dbInserted = false;
        if (isset($RL) && is_object($RL) && method_exists($RL, 'RL_InsertCroppedVideo')) {
            $dbInserted = (bool) $RL->RL_InsertCroppedVideo(
                $userID,
                $relativeAudio,
                'podcast',
                $createdTime,
                $postText,
                $visibility,
                $price,
                $noComment,
                $hideLikes,
                $audioDuration !== null ? (int) round($audioDuration) : null,
                $podcastCategoryId,
                null,
                null,
                $approvalStatus,
                null,
                $approvedAt,
                null,
                $postTitle
            );
        }

        $html = '';
        $htmlEncoding = 'none';
        $postIdForJson = 0;
        $coverUrl = '';
        if ($dbInserted) {
            $post = $RL->RL_GetNewPost($userID, $relativeAudio, $createdTime, $userID);
            $postIdForJson = isset($post['post_id']) ? (int) $post['post_id'] : 0;
            if ($coverPath !== '' && $postIdForJson > 0 && method_exists($RL, 'RL_AddPostMedia')) {
                $RL->RL_AddPostMedia($postIdForJson, $coverPath, 'cover');
            }
            if ($postIdForJson > 0 && method_exists($RL, 'RL_AddPostMedia')) {
                $RL->RL_AddPostMedia($postIdForJson, $relativeAudio, 'audio');
            }

            $layoutFile = dirname(__DIR__, 2) . '/themes/' . $currentTheme . '/layouts/newCroppedPost.php';
            if ($post && is_file($layoutFile)) {
                if ($coverPath !== '') {
                    $coverUrl = storage_url($coverPath);
                }
                $post['cover_path'] = $coverPath;
                $post['audio_duration_seconds'] = $audioDuration !== null ? (int) round($audioDuration) : null;

                ob_start();
                include $layoutFile;
                $html = ob_get_clean();
                if ($html !== '') {
                    try {
                        $encoded = base64_encode($html);
                        if ($encoded !== false) {
                            $html = $encoded;
                            $htmlEncoding = 'base64';
                        }
                    } catch (Throwable $__) {
                        $htmlEncoding = 'none';
                    }
                }
            }
        }

        $successMessage = customLang('podcast_upload_success', 'Podcast posted successfully.');
        if ($approvalStatus === 'pending') {
            $successMessage = customLang('reel_upload_pending', 'Post submitted for review. It will be visible after admin approval.');
        }
        $failureMessage = customLang('podcast_upload_failed', 'Unable to save the podcast.');

        echo json_encode([
            'status' => $dbInserted ? 'success' : 'error',
            'message' => $dbInserted ? $successMessage : $failureMessage,
            'audio_url' => storage_url($relativeAudio),
            'cover_url' => $coverUrl,
            'html' => $html,
            'html_encoding' => $htmlEncoding,
            'post_id' => $postIdForJson
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Generate a pixelated preview from a static image.
     *
     * @param string $requestedMode          Desired preview type (off/static/animated)
     * @param string $sourcePath             Absolute path to the uploaded image
     * @param bool   $shouldPublishToStorage Whether the preview should be published via the storage adapter
     *
     * @return array{path:?string,type:?string,absolute:?string,error:?string|null}
     */
    private function buildLockedPreviewFromImage(
        string $requestedMode,
        string $sourcePath,
        bool $shouldPublishToStorage
    ): array {
        if (!is_file($sourcePath)) {
            return ['path' => null, 'type' => null, 'absolute' => null, 'error' => 'locked_preview_generation_failed'];
        }

        $baseDir = rtrim(dirname(__DIR__, 2), '/');
        $dateFolder = date('Y-m-d');
        $relativeDir = 'uploads/locked_previews/' . $dateFolder;
        $absoluteDir = $baseDir . '/' . $relativeDir;

        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            return ['path' => null, 'type' => null, 'absolute' => null, 'error' => 'locked_preview_generation_failed'];
        }

        $rawData = @file_get_contents($sourcePath);
        if ($rawData === false) {
            return ['path' => null, 'type' => null, 'absolute' => null, 'error' => 'locked_preview_generation_failed'];
        }

        $img = @imagecreatefromstring($rawData);
        if (!$img) {
            return ['path' => null, 'type' => null, 'absolute' => null, 'error' => 'locked_preview_generation_failed'];
        }

        $width = imagesx($img);
        $height = imagesy($img);
        if ($width <= 0 || $height <= 0) {
            @imagedestroy($img);
            return ['path' => null, 'type' => null, 'absolute' => null, 'error' => 'locked_preview_generation_failed'];
        }

        $targetWidth = 720;
        if ($width > $targetWidth) {
            $scaled = @imagescale($img, $targetWidth);
            if ($scaled) {
                @imagedestroy($img);
                $img = $scaled;
                $width = imagesx($img);
                $height = imagesy($img);
            }
        }

        $scaleFlag = defined('IMG_NEAREST_NEIGHBOUR') ? IMG_NEAREST_NEIGHBOUR : (defined('IMG_BILINEAR_FIXED') ? IMG_BILINEAR_FIXED : null);

        $downscaleWidth = max(24, (int) round($width * 0.12));
        if ($downscaleWidth > 0 && $downscaleWidth < $width) {
            $ratio = $downscaleWidth / $width;
            $downscaled = $scaleFlag !== null
                ? @imagescale($img, $downscaleWidth, (int) max(1, round($height * $ratio)), $scaleFlag)
                : @imagescale($img, $downscaleWidth, (int) max(1, round($height * $ratio)));
            if ($downscaled) {
                $restored = $scaleFlag !== null
                    ? @imagescale($downscaled, $width, $height, $scaleFlag)
                    : @imagescale($downscaled, $width, $height);
                if ($restored) {
                    @imagedestroy($img);
                    $img = $restored;
                    $width = imagesx($img);
                    $height = imagesy($img);
                }
                @imagedestroy($downscaled);
            }
        }

        if (function_exists('imagefilter') && defined('IMG_FILTER_PIXELATE')) {
            $pixelBlock = max(18, (int) round($width / 18));
            @imagefilter($img, IMG_FILTER_PIXELATE, $pixelBlock, true);
            @imagefilter($img, IMG_FILTER_PIXELATE, $pixelBlock, true);
        }

        $fileBase = pathinfo($sourcePath, PATHINFO_FILENAME);
        if ($fileBase === '') {
            $fileBase = 'preview';
        }
        try {
            $suffix = bin2hex(random_bytes(3));
        } catch (Throwable $__) {
            $suffix = (string) mt_rand(1000, 9999);
        }
        $fileBase .= '_locked_preview_' . $suffix;
        $absolutePath = $absoluteDir . '/' . $fileBase . '.jpg';

        $saved = @imagejpeg($img, $absolutePath, 82);
        @imagedestroy($img);
        if (!$saved || !is_file($absolutePath)) {
            @unlink($absolutePath);
            return ['path' => null, 'type' => null, 'absolute' => null, 'error' => 'locked_preview_generation_failed'];
        }

        $relativePath = ltrim(preg_replace('#/+#', '/', $relativeDir . '/' . basename($absolutePath)), '/');

        if ($shouldPublishToStorage) {
            try {
                $result = storage_publish_relative($relativePath, 'image/jpeg', 'public');
                $relativePath = $result->getRemoteKey();
            } catch (Throwable $__) {
                @unlink($absolutePath);
                return ['path' => null, 'type' => null, 'absolute' => null, 'error' => 'locked_preview_generation_failed'];
            }
        }

        return [
            'path' => $relativePath,
            'type' => 'static',
            'absolute' => $absolutePath,
            'error' => null,
        ];
    }

    /**
     * Generate a pixelated preview asset for locked/subscriber reels.
     *
     * @param string $requestedMode          Desired preview type (off/static/animated)
     * @param string $ffmpegBin              Absolute FFmpeg binary path
     * @param string $videoPath              Absolute path to the processed video
     * @param bool   $shouldPublishToStorage Whether storage_publish_relative should be used
     *
     * @return array{path:?string,type:?string,absolute:?string,error:?string|null}
     */
    private function buildLockedPreviewAsset(
        string $requestedMode,
        string $ffmpegBin,
        string $videoPath,
        bool $shouldPublishToStorage
    ): array {
        $mode = $requestedMode === 'animated' ? 'animated' : 'static';
        $baseDir = rtrim(dirname(__DIR__, 2), '/');
        $dateFolder = date('Y-m-d');
        $relativeDir = 'uploads/locked_previews/' . $dateFolder;
        $absoluteDir = $baseDir . '/' . $relativeDir;

        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            return ['path' => null, 'type' => null, 'absolute' => null, 'error' => 'locked_preview_generation_failed'];
        }

        $fileBase = pathinfo($videoPath, PATHINFO_FILENAME);
        if ($fileBase === '') {
            $fileBase = 'preview';
        }
        try {
            $suffix = bin2hex(random_bytes(3));
        } catch (Throwable $__) {
            $suffix = (string) mt_rand(1000, 9999);
        }
        $fileBase .= '_locked_preview_' . $suffix;
        $extension = $mode === 'animated' ? '.mp4' : '.jpg';
        $absolutePath = $absoluteDir . '/' . $fileBase . $extension;

        // Downscale aggressively then upscale with nearest-neighbour to create heavy pixelation
        $filter = "scale='trunc(iw*0.06/2)*2':'trunc(ih*0.06/2)*2':flags=neighbor,scale=720:-2:flags=neighbor";
        $success = false;

        if ($mode === 'animated') {
            $commands = [
                escapeshellarg($ffmpegBin) . ' -y -ss 0 -i ' . escapeshellarg($videoPath)
                    . ' -t 1.75 -an -vf ' . escapeshellarg($filter)
                    . ' -c:v libx264 -preset veryfast -crf 30 -movflags +faststart '
                    . escapeshellarg($absolutePath) . ' 2>&1',
                escapeshellarg($ffmpegBin) . ' -y -ss 0 -i ' . escapeshellarg($videoPath)
                    . ' -t 1.75 -an -vf ' . escapeshellarg($filter)
                    . ' -c:v h264 -preset veryfast -crf 32 -movflags +faststart '
                    . escapeshellarg($absolutePath) . ' 2>&1',
            ];
            foreach ($commands as $cmd) {
                @\shell_exec($cmd);
                if (is_file($absolutePath) && filesize($absolutePath) > 2048) {
                    $success = true;
                    break;
                }
            }
            if (!$success) {
                @unlink($absolutePath);
                if ($requestedMode === 'animated') {
                    return $this->buildLockedPreviewAsset('static', $ffmpegBin, $videoPath, $shouldPublishToStorage);
                }
                return ['path' => null, 'type' => null, 'absolute' => null, 'error' => 'locked_preview_generation_failed'];
            }
        } else {
            $cmd = escapeshellarg($ffmpegBin) . ' -y -ss 0.35 -i ' . escapeshellarg($videoPath)
                 . ' -vframes 1 -vf ' . escapeshellarg($filter)
                 . ' -q:v 2 ' . escapeshellarg($absolutePath) . ' 2>&1';
            @\shell_exec($cmd);
            $success = is_file($absolutePath) && filesize($absolutePath) > 1024;
            if (!$success) {
                @unlink($absolutePath);
                return ['path' => null, 'type' => null, 'absolute' => null, 'error' => 'locked_preview_generation_failed'];
            }
        }

        $relativePath = ltrim(preg_replace('#/+#', '/', $relativeDir . '/' . basename($absolutePath)), '/');

        if ($shouldPublishToStorage) {
            try {
                $contentType = $mode === 'animated' ? 'video/mp4' : 'image/jpeg';
                $result = storage_publish_relative($relativePath, $contentType, 'public');
                $relativePath = $result->getRemoteKey();
            } catch (Throwable $__) {
                @unlink($absolutePath);
                return ['path' => null, 'type' => null, 'absolute' => null, 'error' => 'locked_preview_generation_failed'];
            }
        }

        return [
            'path' => $relativePath,
            'type' => $mode,
            'absolute' => $absolutePath,
            'error' => null,
        ];
    }
}
