<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/inc.php';

$config = social_login_get_config($siteData ?? []);
$baseRedirect = isset($base_url) ? (string)$base_url : '/';
$loginRedirect = $baseRedirect . 'login';

$fail = static function (string $messageKey, string $fallback) use ($loginRedirect): void {
    $message = customLang($messageKey);
    if (!is_string($message) || $message === '') {
        $message = $fallback;
    }
    social_login_flash('error', (string)$message);
    header('Location: ' . $loginRedirect);
    exit;
};

if (!social_login_is_enabled('facebook', $config)) {
    $fail('social_login_provider_disabled', 'Facebook login is disabled.');
}

$appId = trim((string)($config['facebook']['client_id'] ?? ''));
$appSecret = trim((string)($config['facebook']['client_secret'] ?? ''));
if ($appId === '' || $appSecret === '') {
    $fail('social_login_missing_credentials', 'Facebook login is not fully configured.');
}

if (isset($_GET['error'])) {
    $errorReason = isset($_GET['error_reason']) ? (string)$_GET['error_reason'] : '';
    if ($errorReason === 'user_denied') {
        $fail('social_login_cancelled', 'You cancelled the login process.');
    }
    $fail('social_login_error_default', 'Unable to complete Facebook login.');
}

$redirectUri = social_login_redirect_uri('facebook');

if (!isset($_GET['code'])) {
    $state = social_login_generate_state('facebook');
    $params = [
        'client_id'     => $appId,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => 'email,public_profile',
        'state'         => $state,
    ];
    $authUrl = 'https://www.facebook.com/v19.0/dialog/oauth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    header('Location: ' . $authUrl);
    exit;
}

$state = isset($_GET['state']) ? (string)$_GET['state'] : '';
if (!social_login_validate_state('facebook', $state)) {
    $fail('social_login_invalid_state', 'The login session expired. Please try again.');
}

$code = (string)$_GET['code'];
if ($code === '') {
    $fail('social_login_error_default', 'Missing authorization code.');
}

$tokenBody = http_build_query([
    'client_id'     => $appId,
    'client_secret' => $appSecret,
    'redirect_uri'  => $redirectUri,
    'code'          => $code,
], '', '&', PHP_QUERY_RFC3986);

$tokenResponse = social_login_http_request(
    'GET',
    'https://graph.facebook.com/v19.0/oauth/access_token?' . $tokenBody
);

if ((int)$tokenResponse['status'] !== 200) {
    $fail('social_login_token_error', 'Unable to verify Facebook credentials.');
}

$tokenData = json_decode($tokenResponse['body'], true);
if (!is_array($tokenData) || empty($tokenData['access_token'])) {
    $fail('social_login_token_error', 'Unable to verify Facebook credentials.');
}

$userFields = http_build_query([
    'fields'        => 'id,name,email,picture.width(400)',
    'access_token'  => $tokenData['access_token'],
], '', '&', PHP_QUERY_RFC3986);
$userResponse = social_login_http_request(
    'GET',
    'https://graph.facebook.com/v19.0/me?' . $userFields
);

if ((int)$userResponse['status'] !== 200) {
    $fail('social_login_profile_error', 'Unable to fetch Facebook profile information.');
}

$user = json_decode($userResponse['body'], true);
if (!is_array($user) || empty($user['id'])) {
    $fail('social_login_profile_error', 'Unable to fetch Facebook profile information.');
}

$profile = [
    'email'    => isset($user['email']) ? (string)$user['email'] : null,
    'name'     => isset($user['name']) ? (string)$user['name'] : 'Facebook User',
    'username' => isset($user['email']) ? (string)$user['email'] : 'facebook_' . substr((string)$user['id'], 0, 8),
];

$result = social_login_finalize($db, 'facebook', (string)$user['id'], $profile);
if (($result['status'] ?? '') !== 'success') {
    $fail('social_login_finalize_error', 'Failed to create account from Facebook profile.');
}

social_login_flash('success', (string)(customLang('social_login_success') ?: 'Logged in successfully.'));
header('Location: ' . $baseRedirect);
exit;
