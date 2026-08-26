<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/inc.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'method_not_allowed']);
    exit;
}

$csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
if (!checkCsrfToken($csrf)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'invalid_csrf']);
    exit;
}

$honeypotField = 'company_website';
if (!empty($_POST[$honeypotField])) {
    echo json_encode(['status' => 'success', 'message' => 'contact_success', 'ok' => true]);
    exit;
}

$isLoggedIn = isset($loggedIn) && $loggedIn === '1';
$userId = $isLoggedIn ? (int) ($userData['user_id'] ?? 0) : 0;
$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
$ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 512) : '';

$config = [];
try {
    $config = AdminMail::loadSettings($db);
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'smtp_not_configured') {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'contact_error_generic']);
        exit;
    }
}

$allowGuests = isset($config['allow_guest_messages']) ? ((int) $config['allow_guest_messages'] === 1) : true;
$guestRecaptchaRequired = isset($config['guest_recaptcha_required']) ? ((int) $config['guest_recaptcha_required'] === 1) : true;
$maxMessageLength = isset($config['max_message_length']) ? (int) $config['max_message_length'] : 1000;
if ($maxMessageLength < 100) {
    $maxMessageLength = 1000;
}
$rateLimitPerHour = isset($config['rate_limit_per_hour']) ? (int) $config['rate_limit_per_hour'] : 1;
$subjectLabels = [
    'feedback' => (string) ($config['subject_label_feedback'] ?? (customLang('admin_contact_subject_feedback') ?: 'Feedback')),
    'complaint' => (string) ($config['subject_label_complaint'] ?? (customLang('admin_contact_subject_complaint') ?: 'Complaint')),
    'suggestion' => (string) ($config['subject_label_suggestion'] ?? (customLang('admin_contact_subject_suggestion') ?: 'Suggestion')),
    'bug' => (string) ($config['subject_label_bug'] ?? (customLang('admin_contact_subject_bug') ?: 'Bug Report')),
];

if (!$isLoggedIn && !$allowGuests) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'contact_error_guests_disabled']);
    exit;
}

$name = '';
$email = '';
if ($isLoggedIn && $userId > 0) {
    $name = trim((string) ($userData['user_fullname'] ?? $userData['username'] ?? ''));
    $email = trim((string) ($userData['user_email'] ?? ''));
} else {
    $name = trim(strip_tags((string) ($_POST['name'] ?? '')));
    $nameLength = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
    if ($name === '' || $nameLength > 120) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'contact_error_name_required']);
        exit;
    }
    $email = trim((string) ($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'contact_error_invalid_email']);
        exit;
    }
}

$msgType = strtolower(trim((string) ($_POST['msg_type'] ?? 'feedback')));
if (!array_key_exists($msgType, $subjectLabels)) {
    $msgType = 'feedback';
}
$subject = $subjectLabels[$msgType];

$messageRaw = (string) ($_POST['message'] ?? '');
$message = trim(strip_tags($messageRaw));
if ($message === '') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'contact_error_message_required']);
    exit;
}
if (function_exists('mb_substr')) {
    $message = mb_substr($message, 0, $maxMessageLength);
} else {
    $message = substr($message, 0, $maxMessageLength);
}

$recaptchaEnabled = isset($config['recaptcha_enabled']) ? ((int) $config['recaptcha_enabled'] === 1) : false;
$recaptchaSecret = trim((string) ($config['recaptcha_secret_key'] ?? ''));
$shouldVerifyRecaptcha = $recaptchaEnabled && $recaptchaSecret !== '' && (!$isLoggedIn || $guestRecaptchaRequired);
if ($shouldVerifyRecaptcha) {
    $token = isset($_POST['g-recaptcha-response']) ? (string) $_POST['g-recaptcha-response'] : '';
    if ($token === '' || !verifyRecaptchaToken($token, $recaptchaSecret, $ip)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'contact_error_recaptcha']);
        exit;
    }
}

try {
    if ($rateLimitPerHour > 0) {
        if ($ip !== '') {
            $st = $db->prepare('SELECT COUNT(*) FROM i_contact_messages WHERE ip = :ip AND created_at >= (NOW() - INTERVAL 1 HOUR)');
            $st->execute([':ip' => $ip]);
            if ((int) $st->fetchColumn() >= $rateLimitPerHour) {
                http_response_code(429);
                echo json_encode(['status' => 'error', 'message' => 'contact_error_rate_limit']);
                exit;
            }
        }

        if ($isLoggedIn && $userId > 0) {
            $st = $db->prepare('SELECT COUNT(*) FROM i_contact_messages WHERE user_id = :uid AND created_at >= (NOW() - INTERVAL 1 HOUR)');
            $st->execute([':uid' => $userId]);
            if ((int) $st->fetchColumn() >= $rateLimitPerHour) {
                http_response_code(429);
                echo json_encode(['status' => 'error', 'message' => 'contact_error_rate_limit']);
                exit;
            }
        } elseif ($email !== '') {
            $st = $db->prepare('SELECT COUNT(*) FROM i_contact_messages WHERE email = :email AND created_at >= (NOW() - INTERVAL 1 HOUR)');
            $st->execute([':email' => $email]);
            if ((int) $st->fetchColumn() >= $rateLimitPerHour) {
                http_response_code(429);
                echo json_encode(['status' => 'error', 'message' => 'contact_error_rate_limit']);
                exit;
            }
        }
    }

    $insert = $db->prepare('INSERT INTO i_contact_messages (user_id, name, email, subject, message, msg_type, ip, user_agent) VALUES (:uid, :name, :email, :subject, :message, :msg_type, :ip, :ua)');
    $insert->execute([
        ':uid' => $isLoggedIn && $userId > 0 ? $userId : null,
        ':name' => $name,
        ':email' => $email,
        ':subject' => $subject,
        ':message' => $message,
        ':msg_type' => $msgType,
        ':ip' => $ip,
        ':ua' => $userAgent,
    ]);
}
catch (Throwable $e) {
    if (defined('APP_DEBUG') && APP_DEBUG === true) {
        error_log('contact insert failed: ' . $e->getMessage());
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'contact_error_generic']);
    exit;
}

$toEmail = trim((string) ($config['to_email'] ?? ''));
$mailSent = false;
$mailError = '';
$ackSent = false;
$ackError = '';
if ($toEmail !== '' && filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
    try {
        [$mailer, $normalised] = AdminMail::buildMailer($config);
        $mailer->addAddress($toEmail);
        if ($email !== '') {
            try { $mailer->addReplyTo($email, $name ?: $email); } catch (PHPMailerException $__) {}
        }
        $mailer->Subject = '[Contact] ' . $subject;
        $bodyLines = [
            'Message type: ' . ucfirst($msgType),
            'Name: ' . ($name ?: '—'),
            'Email: ' . ($email ?: '—'),
            'IP: ' . ($ip ?: '—'),
            'User Agent: ' . ($userAgent ?: '—'),
            ' ',
            $message,
        ];
        $plainBody = implode("\n", $bodyLines);
        $mailer->Body = nl2br(htmlspecialchars($plainBody, ENT_QUOTES, 'UTF-8'));
        $mailer->AltBody = $plainBody;
        $mailer->send();
        $mailSent = true;
    } catch (Throwable $e) {
        $mailError = $e->getMessage();
        if (defined('APP_DEBUG') && APP_DEBUG === true) {
            error_log('contact mail send failed: ' . $mailError);
        }
    }
}

if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    try {
        [$ackMailer, $ackNormalised] = AdminMail::buildMailer($config);
        $ackMailer->clearAddresses();
        $ackMailer->addAddress($email, $name !== '' ? $name : null);
        $ackMailer->isHTML(true);

        $siteDisplay = isset($siteTitle) && $siteTitle !== '' ? (string) $siteTitle : ($ackNormalised['from_name'] ?: 'Support');
        $ackSubject = customLang('contact_ack_subject') ?: 'We received your message';
        $greetingTemplate = customLang('contact_ack_intro') ?: 'Hello %s,';
        $bodyTemplate = customLang('contact_ack_body') ?: 'Thank you for contacting %s. Our support team has received your message and will get back to you shortly.';
        $noReply = customLang('contact_ack_no_reply') ?: 'This is an automated notification, please do not reply to this email.';
        $signatureTemplate = customLang('contact_ack_signature') ?: '— %s support team';

        $displayName = $name !== '' ? $name : ($email ?: 'there');
        $greeting = sprintf($greetingTemplate, $displayName);
        $bodyLine = sprintf($bodyTemplate, $siteDisplay);
        $signature = sprintf($signatureTemplate, $siteDisplay);

        $ackMailer->Subject = $ackSubject;
        $ackMailer->Body = '<p>' . htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>' . htmlspecialchars($bodyLine, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>' . htmlspecialchars($noReply, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>' . htmlspecialchars($signature, ENT_QUOTES, 'UTF-8') . '</p>';
        $ackMailer->AltBody = $greeting . "\n\n" . $bodyLine . "\n\n" . $noReply . "\n" . $signature;
        $ackMailer->send();
        $ackSent = true;
    } catch (Throwable $e) {
        $ackError = $e->getMessage();
        if (defined('APP_DEBUG') && APP_DEBUG === true) {
            error_log('contact ack mail failed: ' . $ackError);
        }
    }
}

try {
    $log = $db->prepare('INSERT INTO i_mail_logs (event, ok, error, meta, created_at) VALUES (:event, :ok, :error, :meta, CURRENT_TIMESTAMP)');
    $meta = [
        'msg_type' => $msgType,
        'user_id' => $userId,
        'ip' => $ip,
        'email_sent' => $mailSent,
        'ack_sent' => $ackSent,
    ];
    $log->execute([
        ':event' => 'contact_send',
        ':ok' => $mailSent ? 1 : 0,
        ':error' => $mailSent ? null : ($mailError !== '' ? substr($mailError, 0, 500) : null),
        ':meta' => json_encode($meta, JSON_UNESCAPED_SLASHES) ?: null,
    ]);
} catch (Throwable $e) {
    if (defined('APP_DEBUG') && APP_DEBUG === true) {
        error_log('contact log insert failed: ' . $e->getMessage());
    }
}

echo json_encode([
    'status' => 'success',
    'message' => 'contact_success',
    'ok' => true,
]);
exit;

function verifyRecaptchaToken(string $token, string $secret, string $ip): bool
{
    if (!function_exists('curl_init') && !function_exists('file_get_contents')) {
        return false;
    }

    $payload = http_build_query([
        'secret' => $secret,
        'response' => $token,
        'remoteip' => $ip,
    ]);

    $endpoint = 'https://www.google.com/recaptcha/api/siteverify';
    $responseBody = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $responseBody = (string) curl_exec($ch);
        curl_close($ch);
    } else {
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'content' => $payload,
                'timeout' => 10,
            ],
        ];
        $context = stream_context_create($opts);
        $responseBody = @file_get_contents($endpoint, false, $context) ?: '';
    }

    if ($responseBody === '') {
        return false;
    }

    $data = json_decode($responseBody, true);
    if (!is_array($data)) {
        return false;
    }

    return !empty($data['success']);
}
