<?php
declare(strict_types=1);

namespace CreatorPulse\App\Controllers;

use CreatorPulse\Services\QrRenderer;
use PDO;
use Reel_Data;
use Throwable;

/**
 * Handles user registration, profile updates, and account security flows while preserving legacy request behaviour.
 */
class UserHandler
{
    private Reel_Data $repository;

    public function __construct(Reel_Data $repository)
    {
        $this->repository = $repository;
    }

    public function handleTwoFactorQr(): void
    {
        try {
            $token = (string) ($_GET['token'] ?? '');
            $token = trim($token);
            if ($token === '') {
                if (function_exists('ob_get_length') && ob_get_length()) {
                    @ob_end_clean();
                }
                http_response_code(404);
                header_remove('Content-Type');
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Not found';
                exit;
            }

            if (!isset($_SESSION['twofactor_qr']) || !is_array($_SESSION['twofactor_qr'])) {
                $_SESSION['twofactor_qr'] = [];
            }

            $now = time();
            foreach ($_SESSION['twofactor_qr'] as $storedToken => $payload) {
                $createdAt = isset($payload['created']) ? (int) $payload['created'] : 0;
                if ($createdAt <= 0 || ($createdAt + 600) < $now) {
                    unset($_SESSION['twofactor_qr'][$storedToken]);
                }
            }

            $payload = $_SESSION['twofactor_qr'][$token] ?? null;
            if (!is_array($payload) || empty($payload['otpauth'])) {
                if (function_exists('ob_get_length') && ob_get_length()) {
                    @ob_end_clean();
                }
                http_response_code(404);
                header_remove('Content-Type');
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Not found';
                exit;
            }

            $created = isset($payload['created']) ? (int) $payload['created'] : 0;
            if ($created <= 0 || ($created + 600) < $now) {
                unset($_SESSION['twofactor_qr'][$token]);
                if (function_exists('ob_get_length') && ob_get_length()) {
                    @ob_end_clean();
                }
                http_response_code(410);
                header_remove('Content-Type');
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Expired';
                exit;
            }

            $otpauth = (string) $payload['otpauth'];
            $dpr = isset($_GET['dpr']) ? (float) $_GET['dpr'] : 1.0;
            if (!is_finite($dpr)) {
                $dpr = 1.0;
            }
            $dpr = max(1.0, min($dpr, 3.0));
            $size = (int) round(300 * $dpr);
            if ($size < 300) {
                $size = 300;
            }

            $png = QrRenderer::renderPng($otpauth, $size, 4);

            if (ob_get_length()) {
                ob_end_clean();
            }
            header_remove('Content-Type');
            header('Content-Type: image/png');
            header('Cache-Control: no-store');
            header('Pragma: no-cache');
            header('Expires: 0');
            echo $png;
            exit;
        } catch (Throwable $e) {
            if (function_exists('ob_get_length') && ob_get_length()) {
                @ob_end_clean();
            }
            http_response_code(500);
            header_remove('Content-Type');
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Server error';
            exit;
        }
    }

    public function handleRegisterAccount(): void
    {
        global $loggedIn, $RL, $cookieName, $base_url, $siteData;

        $RL = $this->repository;

        try {
            if ($loggedIn === '1') {
                echo json_encode([
                    'status' => 'error',
                    'message' => customLang('already_logged_in'),
                ]);
                exit;
            }

            $csrfToken = (string) ($_POST['csrf_token'] ?? '');
            if (!checkCsrfToken($csrfToken)) {
                echo json_encode([
                    'status' => 'error',
                    'message' => customLang('invalid_csrf_token'),
                ]);
                exit;
            }

            $username = trim((string) ($_POST['username'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
            $userPhone = trim((string) ($_POST['user_phone'] ?? ''));
            $autoUsernameSetting = strtolower((string)($siteData['register_auto_username'] ?? 'open'));
            $autoUsernameEnabled = $autoUsernameSetting !== 'close';
            $phoneEnabledSetting = strtolower((string)($siteData['register_phone_enabled'] ?? 'open'));
            $phoneEnabled = $phoneEnabledSetting !== 'close';

            if ($email === '' || $password === '' || $confirmPassword === '') {
                echo json_encode([
                    'status' => 'error',
                    'message' => customLang('please_fill_all_fields'),
                ]);
                exit;
            }

            $autoGeneratedUsername = false;
            if ($username === '' && !$autoUsernameEnabled) {
                $usernameMessage = customLang('register_username_required');
                if (!is_string($usernameMessage) || $usernameMessage === '' || $usernameMessage === 'register_username_required') {
                    $usernameMessage = customLang('please_fill_all_fields');
                }
                echo json_encode([
                    'status' => 'error',
                    'message' => $usernameMessage,
                ]);
                exit;
            }

            if ($username === '' && $autoUsernameEnabled) {
                if (!isset($RL) || !is_object($RL) || !method_exists($RL, 'getDb')) {
                    echo json_encode(['status' => 'error', 'message' => customLang('db_not_available')]);
                    exit;
                }
                $db = $RL->getDb();
                $seed = $email !== '' ? $email : 'member';
                if (function_exists('social_login_generate_username')) {
                    $username = social_login_generate_username($db, $seed);
                } else {
                    $username = $seed;
                }
                $username = substr($username, 0, 32);
                $username = preg_replace('/[^A-Za-z0-9_]/', '_', $username) ?? '';
                $username = trim($username, '_');
                if ($username === '') {
                    $username = 'member';
                }
                $autoGeneratedUsername = true;
            }

            $usernameCheck = true;
            if (function_exists('validateUsername')) {
                $usernameCheck = validateUsername(
                    $username,
                    3,
                    '3',
                    32,
                    '32',
                    customLang('username_invalid_length'),
                    customLang('username_letters_numbers_only'),
                    customLang('username_no_spaces'),
                    customLang('username_invalid_characters')
                );
            } elseif (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
                $usernameCheck = customLang('invalid_username');
            }

            if ($usernameCheck !== true) {
                echo json_encode(['status' => 'error', 'message' => $usernameCheck]);
                exit;
            }

            if (function_exists('validateEmail') && !validateEmail($email, false)) {
                echo json_encode([
                    'status' => 'error',
                    'message' => customLang('invalid_email'),
                ]);
                exit;
            }

            if ($password !== $confirmPassword) {
                echo json_encode([
                    'status' => 'error',
                    'message' => customLang('settings_security_error_password_mismatch'),
                ]);
                exit;
            }

            $passwordLength = strlen($password);
            if ($passwordLength < 8 || $passwordLength > 72) {
                echo json_encode([
                    'status' => 'error',
                    'message' => customLang('admin_fake_generator_invalid_password'),
                ]);
                exit;
            }

            if (
                !preg_match('/[A-Z]/', $password) ||
                !preg_match('/[a-z]/', $password) ||
                !preg_match('/[0-9]/', $password)
            ) {
                echo json_encode([
                    'status' => 'error',
                    'message' => customLang('settings_security_error_password_weak'),
                ]);
                exit;
            }

            if (!$phoneEnabled) {
                $userPhone = '';
            } elseif ($userPhone !== '') {
                $cleanPhone = preg_replace('/[^0-9+]/', '', $userPhone);
                if (!is_string($cleanPhone) || !preg_match('/^\+?[0-9]{8,20}$/', $cleanPhone)) {
                    echo json_encode(['status' => 'error', 'message' => customLang('register_phone_invalid')]);
                    exit;
                }
                $userPhone = $cleanPhone;
            }

            if (!isset($db)) {
                if (!isset($RL) || !is_object($RL) || !method_exists($RL, 'getDb')) {
                    echo json_encode(['status' => 'error', 'message' => customLang('db_not_available')]);
                    exit;
                }
                $db = $RL->getDb();
            }

            $usernameStmt = $db->prepare('SELECT COUNT(*) FROM i_users WHERE username = :username');
            $usernameStmt->bindValue(':username', $username, PDO::PARAM_STR);
            $usernameStmt->execute();
            if ((int) ($usernameStmt->fetchColumn() ?: 0) > 0) {
                if ($autoGeneratedUsername) {
                    $base = $username;
                    $suffix = 1;
                    while (true) {
                        $candidate = substr($base, 0, max(1, 32 - strlen((string) $suffix) - 1)) . '_' . $suffix;
                        $usernameStmt->execute([':username' => $candidate]);
                        if ((int) ($usernameStmt->fetchColumn() ?: 0) === 0) {
                            $username = $candidate;
                            break;
                        }
                        $suffix++;
                        if ($suffix > 5000) {
                            $username = $candidate . '_' . bin2hex(random_bytes(2));
                            break;
                        }
                    }
                } else {
                    echo json_encode(['status' => 'error', 'message' => customLang('username_taken')]);
                    exit;
                }
            }

            $emailStmt = $db->prepare('SELECT COUNT(*) FROM i_users WHERE user_email = :email');
            $emailStmt->bindValue(':email', $email, PDO::PARAM_STR);
            $emailStmt->execute();
            if ((int) ($emailStmt->fetchColumn() ?: 0) > 0) {
                echo json_encode(['status' => 'error', 'message' => customLang('email_already_registered')]);
                exit;
            }

            $db->beginTransaction();

            $columns = function_exists('social_login_user_columns') ? social_login_user_columns($db) : [];
            $insertData = [];
            $setColumn = static function (string $column, $value) use (&$insertData, $columns): void {
                if ($columns === [] || in_array($column, $columns, true)) {
                    $insertData[$column] = $value;
                }
            };

            $now = time();
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $setColumn('username', $username);
            $setColumn('user_fullname', $username);
            $setColumn('user_email', $email);
            if ($userPhone !== '') {
                $setColumn('user_phone', $userPhone);
            }
            $setColumn('user_password', $passwordHash);
            $setColumn('user_type', 1);
            $setColumn('user_mode', 'user');
            $setColumn('last_login_time', $now);
            $setColumn('user_avatar', 'uploads/user_avatars/default.jpeg');
            $setColumn('verified_status', 0);
            $setColumn('subscrition_status', 'passive');
            $setColumn('wallet', '0');
            $setColumn('earned', '0');
            $setColumn('who_can_send_message', 'everyone');
            $setColumn('subscription_status', 'close');
            $setColumn('is_banned', 0);
            $setColumn('is_fake', 0);
            $setColumn('created_at', $now);
            $setColumn('updated_at', $now);

            if ($insertData === []) {
                $db->rollBack();
                echo json_encode(['status' => 'error', 'message' => customLang('db_not_available')]);
                exit;
            }

            $columnsSql = implode(', ', array_keys($insertData));
            $placeholdersSql = implode(', ', array_map(static fn(string $col): string => ':' . $col, array_keys($insertData)));

            $insertStmt = $db->prepare('INSERT INTO i_users (' . $columnsSql . ') VALUES (' . $placeholdersSql . ')');
            foreach ($insertData as $column => $value) {
                if ($value === null) {
                    $insertStmt->bindValue(':' . $column, null, PDO::PARAM_NULL);
                } else {
                    $insertStmt->bindValue(':' . $column, $value);
                }
            }
            $insertStmt->execute();

            $userId = (int) $db->lastInsertId();
            if ($userId <= 0) {
                $db->rollBack();
                echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
                exit;
            }

            $sessionKey = bin2hex(random_bytes(32));
            $sessionStmt = $db->prepare('INSERT INTO i_sessions (session_uid, session_key, session_time) VALUES (:uid, :skey, :stime)');
            $sessionStmt->bindValue(':uid', $userId, PDO::PARAM_INT);
            $sessionStmt->bindValue(':skey', $sessionKey, PDO::PARAM_STR);
            $sessionStmt->bindValue(':stime', $now, PDO::PARAM_INT);
            $sessionStmt->execute();

            $db->commit();

            $_SESSION['iuid'] = $userId;

            if (function_exists('setSecureCookie')) {
                setSecureCookie($cookieName, $sessionKey, 31556926);
            } else {
                setcookie($cookieName, $sessionKey, time() + 31556926, '/');
            }

            if (isset($RL) && method_exists($RL, 'RL_TouchSessionDevice')) {
                $RL->RL_TouchSessionDevice($userId, $sessionKey, [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ]);
            }

            $redirect = isset($base_url) ? rtrim((string) $base_url, '/') . '/' : '/';
            $successMessage = customLang('social_login_success');
            if ($autoGeneratedUsername) {
                $successMessage = strtr(
                    customLang('register_username_generated'),
                    ['{username}' => $username]
                );
            }

            echo json_encode([
                'status' => 'success',
                'message' => $successMessage,
                'redirect' => $redirect,
            ]);
            exit;
        } catch (Throwable $e) {
            if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
                $db->rollBack();
            }

            if (defined('APP_DEBUG') && APP_DEBUG) {
                echo json_encode(['status' => 'error', 'message' => 'Exception: ' . $e->getMessage()]);
            } else {
                echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
            }
            exit;
        }
    }

    public function handleForgotPasswordRequest(): void
    {
        global $loggedIn, $RL, $base_url, $siteName;

        $RL = $this->repository;

        $respond = static function (array $payload): void {
            echo json_encode($payload);
            exit;
        };

        try {
            $csrfToken = (string) ($_POST['csrf_token'] ?? '');
            if (!checkCsrfToken($csrfToken)) {
                $respond([
                    'status' => 'error',
                    'message' => customLang('invalid_csrf_token'),
                ]);
            }

            $email = trim((string) ($_POST['email'] ?? ''));
            if ($email === '') {
                $respond([
                    'status' => 'error',
                    'message' => customLang('forgot_error_invalid_email', 'Please provide a valid email address.'),
                ]);
            }

            $successMessage = customLang('forgot_success_message', 'If an account exists for that email, we\'ll send a reset link shortly.');

            if ($loggedIn === '1') {
                if (function_exists('social_login_flash')) {
                    \social_login_flash('success', (string) $successMessage);
                }
                $respond([
                    'status' => 'success',
                    'message' => $successMessage,
                    'redirect' => 'forgot_password_sent',
                ]);
            }

            $user = $this->repository->RL_FindUserByEmail($email);

            if (!$user) {
                if (function_exists('social_login_flash')) {
                    \social_login_flash('success', (string) $successMessage);
                }
                $respond([
                    'status' => 'success',
                    'message' => $successMessage,
                    'redirect' => 'forgot_password_sent',
                ]);
            }

            $create = $this->repository->RL_CreatePasswordResetRequest(
                (int) $user['user_id'],
                (string) $user['user_email'],
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
            );

            if (empty($create['ok'])) {
                if (($create['error'] ?? '') === 'RATE_LIMIT') {
                    $respond([
                        'status' => 'error',
                        'message' => customLang('forgot_error_rate_limit', 'Too many reset requests. Please try again later.'),
                    ]);
                }

                $respond([
                    'status' => 'error',
                    'message' => customLang('server_error'),
                ]);
            }

            $token = (string) $create['token'];
            $resetLink = rtrim((string) $base_url, '/') . '/reset_password?' . http_build_query([
                'uid' => (int) $user['user_id'],
                'token' => $token,
            ]);

            $siteLabel = (string) ($siteName ?? ($GLOBALS['siteTitle'] ?? customLang('sitename', 'Site')));
            $recipientName = trim((string) ($user['user_fullname'] ?: $user['username']));
            $recipientDisplay = $recipientName !== '' ? $recipientName : customLang('there', 'there');

            $subject = strtr(
                customLang('mail_reset_subject', 'Reset your {{sitename}} password'),
                [
                    '{{sitename}}' => $siteLabel,
                    '{{resetLink}}' => $resetLink,
                    '{{username}}' => $recipientDisplay,
                ]
            );

            $greeting = strtr(
                customLang('mail_reset_greeting', 'Hi {{username}},'),
                [
                    '{{sitename}}' => $siteLabel,
                    '{{resetLink}}' => $resetLink,
                    '{{username}}' => $recipientDisplay,
                ]
            );

            $bodyText = strtr(
                customLang('mail_reset_body', 'We received a request to reset your password for {{sitename}}. Click the button below to choose a new password.'),
                [
                    '{{sitename}}' => $siteLabel,
                    '{{resetLink}}' => $resetLink,
                    '{{username}}' => $recipientDisplay,
                ]
            );

            $buttonLabel = customLang('mail_reset_button', 'Reset Password');

            $ignoreText = strtr(
                customLang('mail_reset_ignore', 'If you did not request this change, you can safely ignore this email.'),
                [
                    '{{sitename}}' => $siteLabel,
                    '{{resetLink}}' => $resetLink,
                    '{{username}}' => $recipientDisplay,
                ]
            );

            $htmlBody = '<p>' . htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p>' . htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p style="text-align:center;margin:24px 0;">'
                . '<a href="' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '" '
                . 'style="background-color:#4c6ef5;color:#ffffff;padding:12px 24px;border-radius:6px;text-decoration:none;display:inline-block;">'
                . htmlspecialchars($buttonLabel, ENT_QUOTES, 'UTF-8')
                . '</a></p>'
                . '<p>' . htmlspecialchars($ignoreText, ENT_QUOTES, 'UTF-8') . '</p>';

            $plainBody = $greeting . "\n\n"
                . $bodyText . "\n\n"
                . $buttonLabel . ': ' . $resetLink . "\n\n"
                . $ignoreText;

            try {
                $db = $this->repository->getDb();
                $settings = \AdminMail::loadSettings($db);
                [$mailer] = \AdminMail::buildMailer($settings);
                $mailer->clearAllRecipients();
                $mailer->addAddress((string) $user['user_email'], $recipientName !== '' ? $recipientName : null);
                $mailer->Subject = $subject;
                $mailer->isHTML(true);
                $mailer->Body = $htmlBody;
                $mailer->AltBody = $plainBody;
                $mailer->send();
            } catch (\Throwable $mailException) {
                if (!\AdminMail::isConfigurationError($mailException) && method_exists($this->repository, 'logError')) {
                    $this->repository->logError('Password reset mail failed: ' . $mailException->getMessage());
                }
            }

            if (function_exists('social_login_flash')) {
                \social_login_flash('success', (string) $successMessage);
            }

            $respond([
                'status' => 'success',
                'message' => $successMessage,
                'redirect' => 'forgot_password_sent',
            ]);
        } catch (Throwable $e) {
            if (method_exists($this->repository, 'logError')) {
                $this->repository->logError('handleForgotPasswordRequest failed: ' . $e->getMessage());
            }
            $respond([
                'status' => 'error',
                'message' => customLang('server_error'),
            ]);
        }
    }

    public function handleResetPasswordSubmit(): void
    {
        global $RL, $cookieName;

        $RL = $this->repository;

        $respond = static function (array $payload): void {
            echo json_encode($payload);
            exit;
        };

        try {
            $csrfToken = (string) ($_POST['csrf_token'] ?? '');
            if (!checkCsrfToken($csrfToken)) {
                $respond([
                    'status' => 'error',
                    'message' => customLang('invalid_csrf_token'),
                ]);
            }

            $userId = (int) ($_POST['uid'] ?? 0);
            $token = trim((string) ($_POST['token'] ?? ''));
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

            if ($userId <= 0 || $token === '' || $newPassword === '' || $confirmPassword === '') {
                $respond([
                    'status' => 'error',
                    'message' => customLang('please_fill_all_fields'),
                ]);
            }

            if ($newPassword !== $confirmPassword) {
                $respond([
                    'status' => 'error',
                    'message' => customLang('reset_password_error_mismatch', 'Passwords do not match.'),
                ]);
            }

            $resetData = $this->repository->RL_FindValidPasswordReset($userId, $token);
            if (!$resetData) {
                $respond([
                    'status' => 'error',
                    'message' => customLang('reset_password_error_token', 'The reset link is invalid or has expired.'),
                ]);
            }

            if (!$this->repository->RL_IsPasswordAcceptable($newPassword)) {
                $respond([
                    'status' => 'error',
                    'message' => customLang('reset_password_error_strength', 'Please choose a stronger password.'),
                ]);
            }

            $currentSessionKey = null;
            if (isset($cookieName) && isset($_COOKIE[$cookieName]) && is_string($_COOKIE[$cookieName])) {
                $currentSessionKey = (string) $_COOKIE[$cookieName];
            }

            $result = $this->repository->RL_CompletePasswordReset(
                (int) $resetData['id'],
                $userId,
                $newPassword,
                $currentSessionKey
            );

            if (empty($result['ok'])) {
                $error = (string) ($result['error'] ?? 'ERROR');
                if ($error === 'WEAK_PASSWORD') {
                    $respond([
                        'status' => 'error',
                        'message' => customLang('reset_password_error_strength', 'Please choose a stronger password.'),
                    ]);
                }
                if ($error === 'TOKEN_INVALID' || $error === 'RESET_NOT_FOUND') {
                    $respond([
                        'status' => 'error',
                        'message' => customLang('reset_password_error_token', 'The reset link is invalid or has expired.'),
                    ]);
                }
                $respond([
                    'status' => 'error',
                    'message' => customLang('server_error'),
                ]);
            }

            $successMessage = customLang('reset_password_success', 'Your password has been updated. You can now sign in with your new password.');
            if (function_exists('social_login_flash')) {
                \social_login_flash('success', (string) $successMessage);
            }

            $respond([
                'status' => 'success',
                'message' => $successMessage,
                'redirect' => 'login',
            ]);
        } catch (Throwable $e) {
            if (method_exists($this->repository, 'logError')) {
                $this->repository->logError('handleResetPasswordSubmit failed: ' . $e->getMessage());
            }
            $respond([
                'status' => 'error',
                'message' => customLang('server_error'),
            ]);
        }
    }

    public function handleUpdateProfile(): void
    {
        global $RL, $userID, $availableUploadFileSize, $base_url;

        $RL = $this->repository;

        try {
            if (!isset($RL) || !is_object($RL) || !method_exists($RL, 'getDb')) {
                echo json_encode(['status'=>'error','message'=>customLang('db_not_available')]); exit;
            }
            $db = $RL->getDb();

            $uid = isset($userID) ? (int)$userID : 0;
            if ($uid <= 0) { echo json_encode(['status'=>'error','message'=>customLang('login_required')]); exit; }

            $csrfToken = (string) ($_POST['csrf_token'] ?? '');
            if (!function_exists('checkCsrfToken') || !checkCsrfToken($csrfToken)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
                exit;
            }

            $fullname = trim((string)($_POST['user_fullname'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            $bioRaw   = (string)($_POST['about_me'] ?? '');
            $email    = trim((string)($_POST['contact_email'] ?? ''));
            $phoneRaw = trim((string)($_POST['user_phone'] ?? ''));

            $valUser = true;
            if ($username !== '') {
                if (function_exists('validateUsername')) {
                    $valUser = validateUsername(
                        $username,
                        3,
                        '3',
                        32,
                        '32',
                        customLang('username_invalid_length','Invalid username length.'),
                        customLang('username_letters_numbers_only','Only letters, numbers and underscore.'),
                        customLang('username_no_spaces','No spaces allowed.'),
                        customLang('username_invalid_characters','Invalid characters.')
                    );
                } else {
                    if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) { $valUser = customLang('invalid_username','Invalid username.'); }
                }
            }
            if ($valUser !== true) { echo json_encode(['status'=>'error','message'=>$valUser]); exit; }

            if ($email !== '' && function_exists('validateEmail') && !validateEmail($email, false)) {
                echo json_encode(['status'=>'error','message'=>customLang('invalid_email')]); exit;
            }

            $phone = '';
            if ($phoneRaw !== '') {
                $phone = preg_replace('/[^0-9+]/', '', $phoneRaw);
                if ($phone === '' || !preg_match('/^\\+?[0-9]{8,20}$/', $phone)) {
                    echo json_encode(['status'=>'error','message'=>customLang('invalid_phone', 'Please enter a valid phone number with country code.')]);
                    exit;
                }
            }

            if ($username !== '') {
                $q = $db->prepare('SELECT COUNT(*) FROM i_users WHERE LOWER(username) = LOWER(:u) AND user_id <> :id');
                $q->bindValue(':u', $username, PDO::PARAM_STR);
                $q->bindValue(':id', $uid, PDO::PARAM_INT);
                $q->execute();
                if ((int)$q->fetchColumn() > 0) {
                    echo json_encode(['status'=>'error','message'=>customLang('username_taken','This username is already taken.')]); exit;
                }
            }

            $bio = trim(strip_tags($bioRaw));
            if (mb_strlen($bio, 'UTF-8') > 300) { $bio = mb_substr($bio, 0, 300, 'UTF-8'); }

            $set = [];
            $params = [':id' => $uid];
            if ($fullname !== '') { $set[] = 'user_fullname = :fn'; $params[':fn'] = $fullname; }
            if ($username !== '') { $set[] = 'username = :un'; $params[':un'] = $username; }
            $set[] = 'about_me = :bio'; $params[':bio'] = $bio;
            if ($email !== '') { $set[] = 'user_email = :em'; $params[':em'] = $email; }
            if ($phone !== '') {
                $set[] = 'user_phone = :ph';
                $params[':ph'] = $phone;
                $set[] = 'user_phone_verified_at = :phv';
                $params[':phv'] = time();
            } else {
                $set[] = 'user_phone = NULL';
                $set[] = 'user_phone_verified_at = NULL';
            }

            $updatedAvatar = null; $updatedCover = null;
            $baseDir = dirname(__DIR__, 2);
            $uploadsRoot = $baseDir . '/uploads/';
            $imgMimesAvatar = ['image/jpeg', 'image/png', 'image/webp'];
            $maxMbSetting = isset($availableUploadFileSize) ? (float) $availableUploadFileSize : 5.0;
            if ($maxMbSetting <= 0) { $maxMbSetting = 5.0; }
            $maxBytes = (int) round($maxMbSetting * 1048576);
            $finfo = function_exists('finfo_open') ? @finfo_open(FILEINFO_MIME_TYPE) : null;
            $shouldPublishToStorage = false;
            try {
                $shouldPublishToStorage = storage_manager()->isRemote();
            } catch (Throwable $__) {
                $shouldPublishToStorage = false;
            }

            if (isset($_FILES['avatar']) && (int) $_FILES['avatar']['error'] === UPLOAD_ERR_OK && !empty($_FILES['avatar']['tmp_name']) && is_uploaded_file($_FILES['avatar']['tmp_name'])) {
                $tmp = $_FILES['avatar']['tmp_name'];
                $size = (int) ($_FILES['avatar']['size'] ?? 0);
                if ($size <= 0 || $size > $maxBytes) {
                    echo json_encode(['status' => 'error', 'message' => customLang('file_too_large')]);
                    exit;
                }
                $mime = '';
                if ($finfo) { $mime = (string) @finfo_file($finfo, $tmp); }
                if ($mime === '' && function_exists('mime_content_type')) { $mime = (string) @mime_content_type($tmp); }
                if ($mime === '' || !in_array($mime, $imgMimesAvatar, true)) {
                    echo json_encode(['status' => 'error', 'message' => customLang('invalid_file_format')]);
                    exit;
                }
                $imgInfo = @getimagesize($tmp);
                if ($imgInfo === false) {
                    echo json_encode(['status' => 'error', 'message' => customLang('invalid_file_format')]);
                    exit;
                }
                $imgType = (int) ($imgInfo[2] ?? 0);
                $w = (int) ($imgInfo[0] ?? 0);
                $h = (int) ($imgInfo[1] ?? 0);
                if ($w <= 0 || $h <= 0) {
                    echo json_encode(['status' => 'error', 'message' => customLang('invalid_file_format')]);
                    exit;
                }

                $src = null;
                if ($imgType === IMAGETYPE_JPEG) { $src = @imagecreatefromjpeg($tmp); }
                elseif ($imgType === IMAGETYPE_PNG) { $src = @imagecreatefrompng($tmp); }
                elseif ($imgType === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) { $src = @imagecreatefromwebp($tmp); }
                if (!$src) { echo json_encode(['status' => 'error', 'message' => customLang('invalid_file_format')]); exit; }

                $dst = @imagecreatetruecolor($w, $h);
                if ($dst) {
                    $white = imagecolorallocate($dst, 255, 255, 255);
                    @imagefilledrectangle($dst, 0, 0, $w, $h, $white);
                    @imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
                } else { $dst = $src; }
                @imagedestroy($src);

                $dir = $uploadsRoot . 'user_avatars/';
                if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
                try { $rand = bin2hex(random_bytes(8)); } catch (Throwable $__) { $rand = str_replace('.', '', uniqid('av_', true)); }
                $name = 'avatar_' . $uid . '_' . $rand . '.jpg';
                $dest = $dir . $name;
                $saved = @imagejpeg($dst, $dest, 92);
                @imagedestroy($dst);
                if (!$saved || !is_file($dest)) {
                    if (method_exists($RL,'logError')) { $RL->logError('update_profile: avatar save failed: '.$dest); }
                    echo json_encode(['status' => 'error', 'message' => customLang('upload_failed')]);
                    exit;
                }
                $avatarRelativePath = 'uploads/user_avatars/' . $name;
                if ($shouldPublishToStorage) {
                    try {
                        $result = storage_publish_relative($avatarRelativePath, 'image/jpeg', 'public');
                        $avatarRelativePath = $result->getRemoteKey();
                    } catch (Throwable $publishError) {
                        if (function_exists('error_log')) {
                            error_log('[Storage] avatar publish failed: ' . $publishError->getMessage());
                        }
                    }
                }
                $updatedAvatar = $avatarRelativePath;
                $set[] = 'user_avatar = :av'; $params[':av'] = $updatedAvatar;
            }

            if (isset($_FILES['cover']) && (int) $_FILES['cover']['error'] === UPLOAD_ERR_OK && !empty($_FILES['cover']['tmp_name']) && is_uploaded_file($_FILES['cover']['tmp_name'])) {
                $tmp = $_FILES['cover']['tmp_name'];
                $size = (int) ($_FILES['cover']['size'] ?? 0);
                if ($size <= 0 || $size > $maxBytes) {
                    echo json_encode(['status' => 'error', 'message' => customLang('file_too_large')]);
                    exit;
                }
                $mime = '';
                if ($finfo) { $mime = (string) @finfo_file($finfo, $tmp); }
                if ($mime === '' && function_exists('mime_content_type')) { $mime = (string) @mime_content_type($tmp); }
                if ($mime === '' || !in_array($mime, $imgMimesAvatar, true)) {
                    echo json_encode(['status' => 'error', 'message' => customLang('invalid_file_format')]);
                    exit;
                }
                $imgInfo = @getimagesize($tmp);
                if ($imgInfo === false) {
                    echo json_encode(['status' => 'error', 'message' => customLang('invalid_file_format')]);
                    exit;
                }
                $imgType = (int) ($imgInfo[2] ?? 0);
                $w = (int) ($imgInfo[0] ?? 0);
                $h = (int) ($imgInfo[1] ?? 0);
                if ($w <= 0 || $h <= 0) {
                    echo json_encode(['status' => 'error', 'message' => customLang('invalid_file_format')]);
                    exit;
                }

                $src = null;
                if ($imgType === IMAGETYPE_JPEG) { $src = @imagecreatefromjpeg($tmp); }
                elseif ($imgType === IMAGETYPE_PNG) { $src = @imagecreatefrompng($tmp); }
                elseif ($imgType === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) { $src = @imagecreatefromwebp($tmp); }
                if (!$src) { echo json_encode(['status' => 'error', 'message' => customLang('invalid_file_format')]); exit; }
                $dst = @imagecreatetruecolor($w, $h);
                if ($dst) {
                    $white = imagecolorallocate($dst, 255, 255, 255);
                    @imagefilledrectangle($dst, 0, 0, $w, $h, $white);
                    @imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
                } else { $dst = $src; }
                @imagedestroy($src);

                $dir = $uploadsRoot . 'user_covers/';
                if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
                try { $rand = bin2hex(random_bytes(8)); } catch (Throwable $__) { $rand = str_replace('.', '', uniqid('cv_', true)); }
                $name = 'cover_' . $uid . '_' . $rand . '.jpg';
                $dest = $dir . $name;
                $saved = @imagejpeg($dst, $dest, 90);
                @imagedestroy($dst);
                if (!$saved || !is_file($dest)) {
                    if (method_exists($RL,'logError')) { $RL->logError('update_profile: cover save failed: '.$dest); }
                    echo json_encode(['status' => 'error', 'message' => customLang('upload_failed')]);
                    exit;
                }
                $coverRelativePath = 'uploads/user_covers/' . $name;
                if ($shouldPublishToStorage) {
                    try {
                        $result = storage_publish_relative($coverRelativePath, 'image/jpeg', 'public');
                        $coverRelativePath = $result->getRemoteKey();
                    } catch (Throwable $publishError) {
                        if (function_exists('error_log')) {
                            error_log('[Storage] cover publish failed: ' . $publishError->getMessage());
                        }
                    }
                }
                $updatedCover = $coverRelativePath;
                $set[] = 'user_cover = :cv'; $params[':cv'] = $updatedCover;
            }

            if (empty($set)) { echo json_encode(['status'=>'error','message'=>customLang('nothing_to_update','Nothing to update.')]); exit; }

            $sql = 'UPDATE i_users SET ' . implode(', ', $set) . ' WHERE user_id = :id LIMIT 1';
            $st = $db->prepare($sql);
            $ok = $st->execute($params);

            if (!$ok) { echo json_encode(['status'=>'error','message'=>customLang('update_failed','Update failed.')]); exit; }

            $resp = [ 'status' => 'success', 'message' => customLang('profile_saved','Profile updated.'), 'data' => [] ];
            $base = rtrim((string)$base_url, '/');
            if ($updatedAvatar) { $resp['data']['avatar'] = $updatedAvatar; $resp['data']['avatar_url'] = $base . '/' . ltrim($updatedAvatar, '/'); }
            if ($updatedCover)  { $resp['data']['cover']  = $updatedCover;  $resp['data']['cover_url']  = $base . '/' . ltrim($updatedCover, '/'); }
            if ($fullname !== '') { $resp['data']['user_fullname'] = $fullname; }
            if ($username !== '') { $resp['data']['username'] = $username; }
            if ($email !== '')    { $resp['data']['contact_email'] = $email; $resp['data']['user_email'] = $email; }
            $resp['data']['about_me'] = $bio;

            echo json_encode($resp);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>customLang('server_error')]);
        }
        exit;
    }

    public function handleSettingsUpdateLanguage(): void
    {
        global $userID, $RL;

        $RL = $this->repository;

        try {
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

            $language = isset($_POST['language']) ? strtolower(trim((string) $_POST['language'])) : '';
            $languageOptions = function_exists('discoverAvailableLanguages') ? discoverAvailableLanguages() : [];
            if (empty($languageOptions)) {
                $languageOptions = ['eng'];
            }

            if ($language === '' || !in_array($language, $languageOptions, true)) {
                echo json_encode(['status' => 'error', 'message' => customLang('language_not_available', 'Language not available.')]);
                exit;
            }

            if (!isset($RL) || !method_exists($RL, 'RL_UpdateUserPreferences')) {
                echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
                exit;
            }

            $update = $RL->RL_UpdateUserPreferences($uid, ['language' => $language]);
            if (empty($update['ok'])) {
                $message = isset($update['error']) ? (string) $update['error'] : customLang('server_error');
                echo json_encode(['status' => 'error', 'message' => $message]);
                exit;
            }

            echo json_encode([
                'status' => 'success',
                'message' => customLang('language_switch_success', 'Language updated.'),
                'data' => [
                    'preferences' => $update['preferences'],
                ],
            ]);
        } catch (Throwable $e) {
            if (isset($RL) && method_exists($RL, 'logError')) {
                $RL->logError('settings_update_language failed: ' . $e->getMessage());
            }
            echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
        }
        exit;
    }

    public function handleSettingsUpdatePreferences(): void
    {
        global $userID, $RL;

        $RL = $this->repository;

        try {
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

            if (!isset($RL) || !method_exists($RL, 'RL_UpdateUserPreferences')) {
                echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
                exit;
            }

            $boolFields = [
                'feed_autoplay',
                'feed_muted',
                'notify_push',
                'notify_email',
                'notify_sms',
            ];

            $payload = [];
            foreach ($boolFields as $field) {
                $payload[$field] = isset($_POST[$field]) && $_POST[$field] !== '';
            }

            $language = isset($_POST['language']) ? trim((string) $_POST['language']) : '';
            $languageOptions = [];
            $langDir = realpath(dirname(__DIR__, 2) . '/langs');
            if ($langDir && is_dir($langDir)) {
                foreach (scandir($langDir) as $entry) {
                    if ($entry === '.' || $entry === '..') { continue; }
                    if (preg_match('/^(\w+)\.php$/', $entry, $m)) {
                        $languageOptions[] = $m[1];
                    }
                }
            }
            if ($language !== '' && in_array($language, $languageOptions, true)) {
                $payload['language'] = $language;
            }

            $update = $RL->RL_UpdateUserPreferences($uid, $payload);
            if (empty($update['ok'])) {
                $message = isset($update['error']) ? (string) $update['error'] : customLang('server_error');
                echo json_encode(['status' => 'error', 'message' => $message]);
                exit;
            }

            echo json_encode([
                'status' => 'success',
                'message' => customLang('settings_preferences_saved', 'Preferences updated.'),
                'data' => [
                    'preferences' => $update['preferences'],
                ],
            ]);
        } catch (Throwable $e) {
            if (isset($RL) && method_exists($RL, 'logError')) {
                $RL->logError('settings_update_preferences failed: ' . $e->getMessage());
            }
            echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
        }
        exit;
    }

    public function handleSettingsUpdatePassword(): void
    {
        global $userID, $RL;

        $RL = $this->repository;

        try {
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

            $current = (string) ($_POST['current_password'] ?? '');
            $new = (string) ($_POST['new_password'] ?? '');
            $confirm = (string) ($_POST['confirm_password'] ?? '');

            if ($current === '' || $new === '' || $confirm === '') {
                echo json_encode(['status' => 'error', 'message' => customLang('settings_security_error_required', 'Please complete all password fields.')]);
                exit;
            }

            if (!hash_equals($new, $confirm)) {
                echo json_encode(['status' => 'error', 'message' => customLang('settings_security_error_password_mismatch', 'New password fields do not match.')]);
                exit;
            }

            if (!isset($RL) || !method_exists($RL, 'RL_UpdateUserPassword')) {
                echo json_encode(['status' => 'error', 'message' => customLang('settings_security_generic_error', 'Unable to update password right now.')]);
                exit;
            }

            $res = $RL->RL_UpdateUserPassword($uid, $current, $new, true);
            if (empty($res['ok'])) {
                $code = (string) ($res['error'] ?? 'UNKNOWN');
                $errorMap = [
                    'INVALID_CURRENT_PASSWORD' => customLang('settings_security_error_current_password', 'Current password is incorrect.'),
                    'PASSWORD_UNCHANGED' => customLang('settings_security_error_password_unchanged', 'New password must differ from the current password.'),
                    'WEAK_PASSWORD' => customLang('settings_security_error_password_weak', 'Choose a stronger password with upper and lower case letters and numbers or symbols.'),
                    'RATE_LIMIT' => customLang('settings_security_error_password_rate', 'Too many password changes. Please wait before trying again.'),
                    'DB_ERROR' => customLang('settings_security_generic_error', 'Unable to update password right now.'),
                    'INVALID_USER' => customLang('settings_security_generic_error', 'Unable to update password right now.'),
                ];
                $message = $errorMap[$code] ?? customLang('settings_security_generic_error', 'Unable to update password right now.');
                echo json_encode(['status' => 'error', 'message' => $message]);
                exit;
            }

            echo json_encode(['status' => 'success', 'message' => customLang('settings_security_password_updated', 'Password updated successfully.')]);
            exit;
        } catch (Throwable $e) {
            if (isset($RL) && method_exists($RL, 'logError')) {
                $RL->logError('settings_update_password failed: ' . $e->getMessage());
            }
            echo json_encode(['status' => 'error', 'message' => customLang('settings_security_generic_error', 'Unable to update password right now.')]);
            exit;
        }
    }

    public function handleSettingsSecurityPrepareTwoFactor(): void
    {
        global $userID, $RL, $siteName;

        $RL = $this->repository;

        try {
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

            if (!isset($RL) || !method_exists($RL, 'RL_PrepareTwoFactorSecret')) {
                echo json_encode(['status' => 'error', 'message' => customLang('settings_security_generic_error', 'Unable to prepare two-factor authentication.')]);
                exit;
            }

            $res = $RL->RL_PrepareTwoFactorSecret($uid);
            if (empty($res['ok'])) {
                $code = (string) ($res['error'] ?? 'UNKNOWN');
                $message = $code === 'ALREADY_ENABLED'
                    ? customLang('settings_security_2fa_already_enabled', 'Two-factor authentication is already enabled.')
                    : customLang('settings_security_generic_error', 'Unable to prepare two-factor authentication.');
                echo json_encode(['status' => 'error', 'message' => $message]);
                exit;
            }

            $secretRaw = (string) ($res['secret'] ?? '');
            $issuerSource = isset($siteName) && is_string($siteName) ? trim((string) $siteName) : '';
            if ($issuerSource === '') {
                $issuerSource = 'DizzyReel';
            }
            $issuerSans = preg_replace('/\s+/', '', $issuerSource ?: 'DizzyReel');
            if ($issuerSans === '') {
                $issuerSans = 'DizzyReel';
            }
            $accountLabel = 'user';
            if (isset($RL) && method_exists($RL, 'RL_GetUserDetails')) {
                try {
                    $details = $RL->RL_GetUserDetails($uid);
                    if (is_array($details)) {
                        if (!empty($details['user_email'])) {
                            $accountLabel = (string) $details['user_email'];
                        } elseif (!empty($details['username'])) {
                            $accountLabel = (string) $details['username'];
                        }
                    }
                } catch (Throwable $__) {
                }
            }

            $accountLabel = trim((string) $accountLabel);
            if ($accountLabel === '') {
                $accountLabel = 'user';
            }

            $secret = null;
            if (isset($RL) && method_exists($RL, 'RL_NormalizeTotpSecret')) {
                $secret = $RL->RL_NormalizeTotpSecret($secretRaw);
            }
            $secretCandidate = strtoupper((string) ($secret ?? $secretRaw));
            $secretCandidate = preg_replace('/[^A-Z2-7=]/', '', $secretCandidate);
            if (!is_string($secretCandidate) || $secretCandidate === '' || !preg_match('/^[A-Z2-7]+=*$/', $secretCandidate) || strlen($secretCandidate) < 16) {
                echo json_encode(['status' => 'error', 'message' => customLang('settings_security_generic_error', 'Unable to prepare two-factor authentication.')]);
                exit;
            }
            $secret = $secretCandidate;

            $label = rawurlencode($issuerSans . ':' . $accountLabel);
            $otpauth = 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . $issuerSans;

            if (!isset($_SESSION['twofactor_qr']) || !is_array($_SESSION['twofactor_qr'])) {
                $_SESSION['twofactor_qr'] = [];
            }
            $now = time();
            foreach ($_SESSION['twofactor_qr'] as $storedToken => $payload) {
                $createdAt = isset($payload['created']) ? (int) $payload['created'] : 0;
                if ($createdAt <= 0 || ($createdAt + 600) < $now) {
                    unset($_SESSION['twofactor_qr'][$storedToken]);
                }
            }
            $qrToken = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
            $_SESSION['twofactor_qr'][$qrToken] = [
                'otpauth' => $otpauth,
                'created' => $now,
            ];

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'secret' => $secret,
                    'issuer' => $issuerSource,
                    'account' => $accountLabel,
                    'otpauth' => $otpauth,
                    'qr_token' => $qrToken,
                ],
            ]);
            exit;
        } catch (Throwable $e) {
            if (isset($RL) && method_exists($RL, 'logError')) {
                $RL->logError('settings_security_prepare_2fa failed: ' . $e->getMessage());
            }
            echo json_encode(['status' => 'error', 'message' => customLang('settings_security_generic_error', 'Unable to prepare two-factor authentication.')]);
            exit;
        }
    }

    public function handleSettingsSecurityEnableTwoFactor(): void
    {
        global $userID, $RL;

        $RL = $this->repository;

        try {
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

            $secret = (string) ($_POST['secret'] ?? '');
            $code = (string) ($_POST['code'] ?? '');
            if ($secret === '' || $code === '') {
                echo json_encode(['status' => 'error', 'message' => customLang('settings_security_error_required', 'Please complete all required fields.')]);
                exit;
            }

            if (!isset($RL) || !method_exists($RL, 'RL_EnableTwoFactor')) {
                echo json_encode(['status' => 'error', 'message' => customLang('settings_security_generic_error', 'Unable to enable two-factor authentication.')]);
                exit;
            }

            $res = $RL->RL_EnableTwoFactor($uid, $secret, $code, true);
            if (empty($res['ok'])) {
                $code = (string) ($res['error'] ?? 'UNKNOWN');
                $errorMap = [
                    'ALREADY_ENABLED' => customLang('settings_security_2fa_already_enabled', 'Two-factor authentication is already enabled.'),
                    'INVALID_SECRET' => customLang('settings_security_2fa_invalid_secret', 'Authenticator secret is invalid.'),
                    'INVALID_CODE' => customLang('settings_security_2fa_invalid_code', 'Enter a valid 6-digit code.'),
                    'CODE_MISMATCH' => customLang('settings_security_2fa_mismatch', 'We could not verify that code. Try a new code from your authenticator app.'),
                    'SECRET_MISMATCH' => customLang('settings_security_2fa_secret_mismatch', 'The verification attempt did not match the pending secret. Restart setup.'),
                    'DB_ERROR' => customLang('settings_security_generic_error', 'Unable to enable two-factor authentication.'),
                ];
                $message = $errorMap[$code] ?? customLang('settings_security_generic_error', 'Unable to enable two-factor authentication.');
                echo json_encode(['status' => 'error', 'message' => $message]);
                exit;
            }

            $codes = isset($res['codes']) && is_array($res['codes']) ? array_values($res['codes']) : [];
            echo json_encode([
                'status' => 'success',
                'message' => customLang('settings_security_2fa_enabled', 'Two-factor authentication is now enabled.'),
                'data' => ['codes' => $codes],
            ]);
            exit;
        } catch (Throwable $e) {
            if (isset($RL) && method_exists($RL, 'logError')) {
                $RL->logError('settings_security_enable_2fa failed: ' . $e->getMessage());
            }
            echo json_encode(['status' => 'error', 'message' => customLang('settings_security_generic_error', 'Unable to enable two-factor authentication.')]);
            exit;
        }
    }

    public function handleSettingsSecurityDisableTwoFactor(): void
    {
        global $userID, $RL;

        $RL = $this->repository;

        try {
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

            $code = (string) ($_POST['code'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            if ($code === '' && $password === '') {
                echo json_encode(['status' => 'error', 'message' => customLang('settings_security_disable_requires_code', 'Provide a code or your password to disable two-factor authentication.')]);
                exit;
            }

            if (!isset($RL) || !method_exists($RL, 'RL_DisableTwoFactor')) {
                echo json_encode(['status' => 'error', 'message' => customLang('settings_security_generic_error', 'Unable to disable two-factor authentication.')]);
                exit;
            }

            $res = $RL->RL_DisableTwoFactor($uid, $code, $password);
            if (empty($res['ok'])) {
                $code = (string) ($res['error'] ?? 'UNKNOWN');
                $errorMap = [
                    'NOT_ENABLED' => customLang('settings_security_2fa_not_enabled', 'Two-factor authentication is already disabled.'),
                    'VERIFICATION_FAILED' => customLang('settings_security_disable_failed', 'Verification failed. Double-check your code or password.'),
                    'DB_ERROR' => customLang('settings_security_generic_error', 'Unable to disable two-factor authentication.'),
                ];
                $message = $errorMap[$code] ?? customLang('settings_security_generic_error', 'Unable to disable two-factor authentication.');
                echo json_encode(['status' => 'error', 'message' => $message]);
                exit;
            }

            echo json_encode(['status' => 'success', 'message' => customLang('settings_security_2fa_disabled', 'Two-factor authentication has been disabled.')]);
            exit;
        } catch (Throwable $e) {
            if (isset($RL) && method_exists($RL, 'logError')) {
                $RL->logError('settings_security_disable_2fa failed: ' . $e->getMessage());
            }
            echo json_encode(['status' => 'error', 'message' => customLang('settings_security_generic_error', 'Unable to disable two-factor authentication.')]);
            exit;
        }
    }

    public function handleSettingsSecurityGenerateCodes(): void
    {
        global $userID, $RL;

        $RL = $this->repository;

        try {
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

            if (!isset($RL) || !method_exists($RL, 'RL_GenerateRecoveryCodes')) {
                echo json_encode(['status' => 'error', 'message' => customLang('settings_security_generic_error', 'Unable to regenerate recovery codes.')]);
                exit;
            }

            $res = $RL->RL_GenerateRecoveryCodes($uid, 10);
            if (empty($res['ok'])) {
                $code = (string) ($res['error'] ?? 'UNKNOWN');
                $errorMap = [
                    'RATE_LIMIT' => customLang('settings_security_codes_rate_limit', 'You can regenerate recovery codes a few times per hour. Try again later.'),
                    'DB_ERROR' => customLang('settings_security_generic_error', 'Unable to regenerate recovery codes.'),
                ];
                $message = $errorMap[$code] ?? customLang('settings_security_generic_error', 'Unable to regenerate recovery codes.');
                echo json_encode(['status' => 'error', 'message' => $message]);
                exit;
            }

            $codes = isset($res['codes']) && is_array($res['codes']) ? array_values($res['codes']) : [];
            echo json_encode([
                'status' => 'success',
                'message' => customLang('settings_security_codes_regenerated', 'New recovery codes generated.'),
                'data' => ['codes' => $codes],
            ]);
            exit;
        } catch (Throwable $e) {
            if (isset($RL) && method_exists($RL, 'logError')) {
                $RL->logError('settings_security_generate_codes failed: ' . $e->getMessage());
            }
            echo json_encode(['status' => 'error', 'message' => customLang('settings_security_generic_error', 'Unable to regenerate recovery codes.')]);
            exit;
        }
    }

    public function handleSettingsSecurityRevokeSession(): void
    {
        global $userID, $RL;

        $RL = $this->repository;

        try {
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

            $sessionKey = (string) ($_POST['session_key'] ?? '');
            if ($sessionKey === '') {
                echo json_encode(['status' => 'error', 'message' => customLang('settings_security_session_invalid', 'Session identifier is missing.')]);
                exit;
            }

            if (!isset($RL) || !method_exists($RL, 'RL_RevokeSession')) {
                echo json_encode(['status' => 'error', 'message' => customLang('settings_security_generic_error', 'Unable to revoke session.')]);
                exit;
            }

            $RL->RL_RevokeSession($uid, $sessionKey);
            echo json_encode(['status' => 'success', 'message' => customLang('settings_security_session_revoked', 'Session ended.')]);
            exit;
        } catch (Throwable $e) {
            if (isset($RL) && method_exists($RL, 'logError')) {
                $RL->logError('settings_security_revoke_session failed: ' . $e->getMessage());
            }
            echo json_encode(['status' => 'error', 'message' => customLang('settings_security_generic_error', 'Unable to revoke session.')]);
            exit;
        }
    }

    public function handleSettingsSecurityRevokeOthers(): void
    {
        global $userID, $RL;

        $RL = $this->repository;

        try {
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

            $currentKey = '';
            if (isset($GLOBALS['cookieName'])) {
                $cookieKey = (string) $GLOBALS['cookieName'];
                $currentKey = isset($_COOKIE[$cookieKey]) ? (string) $_COOKIE[$cookieKey] : '';
            }

            if (!isset($RL) || !method_exists($RL, 'RL_RevokeOtherSessions')) {
                echo json_encode(['status' => 'error', 'message' => customLang('settings_security_generic_error', 'Unable to revoke sessions.')]);
                exit;
            }

            $count = $RL->RL_RevokeOtherSessions($uid, $currentKey !== '' ? $currentKey : null);
            echo json_encode([
                'status' => 'success',
                'message' => customLang('settings_security_sessions_revoked', 'Other sessions have been signed out.'),
                'data' => ['count' => $count],
            ]);
            exit;
        } catch (Throwable $e) {
            if (isset($RL) && method_exists($RL, 'logError')) {
                $RL->logError('settings_security_revoke_others failed: ' . $e->getMessage());
            }
            echo json_encode(['status' => 'error', 'message' => customLang('settings_security_generic_error', 'Unable to revoke sessions.')]);
            exit;
        }
    }
}
