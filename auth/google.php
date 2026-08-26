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

if (!social_login_is_enabled('google', $config)) {
    $fail('social_login_provider_disabled', 'Google login is disabled.');
}

$clientId = trim((string)($config['google']['client_id'] ?? ''));
$clientSecret = trim((string)($config['google']['client_secret'] ?? ''));
if ($clientId === '' || $clientSecret === '') {
    $fail('social_login_missing_credentials', 'Google login is not fully configured.');
}

if (isset($_GET['error'])) {
    $error = (string)$_GET['error'];
    if ($error === 'access_denied') {
        $fail('social_login_cancelled', 'You cancelled the login process.');
    }
    $fail('social_login_error_default', 'Unable to complete Google login.');
}

$redirectUri = social_login_redirect_uri('google');

if (!isset($_GET['code'])) {
    $state = social_login_generate_state('google');
    $params = [
        'client_id'     => $clientId,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'prompt'        => 'select_account',
        'access_type'   => 'online',
    ];
    $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    header('Location: ' . $authUrl);
    exit;
}

$state = isset($_GET['state']) ? (string)$_GET['state'] : '';
if (!social_login_validate_state('google', $state)) {
    $fail('social_login_invalid_state', 'The login session expired. Please try again.');
}

$code = (string)$_GET['code'];
if ($code === '') {
    $fail('social_login_error_default', 'Missing authorization code.');
}

$tokenBody = http_build_query([
    'code'          => $code,
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code',
], '', '&', PHP_QUERY_RFC3986);

$tokenResponse = social_login_http_request(
    'POST',
    'https://oauth2.googleapis.com/token',
    $tokenBody,
    ['Content-Type' => 'application/x-www-form-urlencoded']
);

if ((int)$tokenResponse['status'] !== 200) {
    $fail('social_login_token_error', 'Unable to verify Google credentials.');
}

$tokenData = json_decode($tokenResponse['body'], true);
if (!is_array($tokenData) || empty($tokenData['access_token'])) {
    $fail('social_login_token_error', 'Unable to verify Google credentials.');
}

$userResponse = social_login_http_request(
    'GET',
    'https://openidconnect.googleapis.com/v1/userinfo',
    '',
    ['Authorization' => 'Bearer ' . $tokenData['access_token']]
);

if ((int)$userResponse['status'] !== 200) {
    $fail('social_login_profile_error', 'Unable to fetch Google profile information.');
}

$user = json_decode($userResponse['body'], true);
if (!is_array($user) || empty($user['sub'])) {
    $fail('social_login_profile_error', 'Unable to fetch Google profile information.');
}

$profile = [
    'email'    => isset($user['email']) ? (string)$user['email'] : null,
    'name'     => isset($user['name']) ? (string)$user['name'] : ((isset($user['given_name']) ? (string)$user['given_name'] : 'Google User')),
    'username' => isset($user['email']) ? (string)$user['email'] : ((isset($user['given_name']) ? (string)$user['given_name'] : 'google_user')),
];

$result = social_login_finalize($db, 'google', (string)$user['sub'], $profile);
if (($result['status'] ?? '') !== 'success') {
    $fail('social_login_finalize_error', 'Failed to create account from Google profile.');
}

social_login_flash('success', (string)(customLang('social_login_success') ?: 'Logged in successfully.'));
header('Location: ' . $baseRedirect);
exit;
