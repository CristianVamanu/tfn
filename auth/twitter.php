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

if (!social_login_is_enabled('twitter', $config)) {
    $fail('social_login_provider_disabled', 'Twitter login is disabled.');
}

$clientId = trim((string)($config['twitter']['client_id'] ?? ''));
$clientSecret = trim((string)($config['twitter']['client_secret'] ?? ''));
if ($clientId === '') {
    $fail('social_login_missing_credentials', 'Twitter login is not fully configured.');
}

if (isset($_GET['error'])) {
    $error = (string)$_GET['error'];
    if ($error === 'access_denied') {
        $fail('social_login_cancelled', 'You cancelled the login process.');
    }
    $fail('social_login_error_default', 'Unable to complete Twitter login.');
}

$redirectUri = social_login_redirect_uri('twitter');

$generateVerifier = static function (): string {
    $bytes = random_bytes(32);
    $verifier = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    if (strlen($verifier) < 43) {
        $verifier .= str_repeat('A', 43 - strlen($verifier));
    }
    if (strlen($verifier) > 128) {
        $verifier = substr($verifier, 0, 128);
    }
    return $verifier;
};

$buildChallenge = static function (string $verifier): string {
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
};

$scope = 'tweet.read users.read offline.access email';

if (!isset($_GET['code'])) {
    $state = social_login_generate_state('twitter');
    $verifier = $generateVerifier();
    social_login_store_code_verifier('twitter', $verifier);
    $challenge = $buildChallenge($verifier);

    $params = [
        'response_type'         => 'code',
        'client_id'             => $clientId,
        'redirect_uri'          => $redirectUri,
        'scope'                 => $scope,
        'state'                 => $state,
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
    ];

    $authUrl = 'https://twitter.com/i/oauth2/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    header('Location: ' . $authUrl);
    exit;
}

$state = isset($_GET['state']) ? (string)$_GET['state'] : '';
if (!social_login_validate_state('twitter', $state)) {
    $fail('social_login_invalid_state', 'The login session expired. Please try again.');
}

$codeVerifier = social_login_take_code_verifier('twitter');
if ($codeVerifier === null || $codeVerifier === '') {
    $fail('social_login_session_expired', 'The login session expired. Please try again.');
}

$code = (string)$_GET['code'];
if ($code === '') {
    $fail('social_login_error_default', 'Missing authorization code.');
}

$tokenParams = [
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'redirect_uri'  => $redirectUri,
    'code_verifier' => $codeVerifier,
    'client_id'     => $clientId,
];

$headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
if ($clientSecret !== '') {
    $headers['Authorization'] = 'Basic ' . base64_encode($clientId . ':' . $clientSecret);
}

$tokenResponse = social_login_http_request(
    'POST',
    'https://api.twitter.com/2/oauth2/token',
    http_build_query($tokenParams, '', '&', PHP_QUERY_RFC3986),
    $headers
);

if ((int)$tokenResponse['status'] !== 200) {
    $fail('social_login_token_error', 'Unable to verify Twitter credentials.');
}

$tokenData = json_decode($tokenResponse['body'], true);
if (!is_array($tokenData) || empty($tokenData['access_token'])) {
    $fail('social_login_token_error', 'Unable to verify Twitter credentials.');
}

$userResponse = social_login_http_request(
    'GET',
    'https://api.twitter.com/2/users/me?user.fields=profile_image_url',
    '',
    ['Authorization' => 'Bearer ' . $tokenData['access_token']]
);

if ((int)$userResponse['status'] !== 200) {
    $fail('social_login_profile_error', 'Unable to fetch Twitter profile information.');
}

$user = json_decode($userResponse['body'], true);
if (!is_array($user) || !isset($user['data']['id'])) {
    $fail('social_login_profile_error', 'Unable to fetch Twitter profile information.');
}

$data = $user['data'];
$profile = [
    'email'    => isset($data['email']) ? (string)$data['email'] : null,
    'name'     => isset($data['name']) ? (string)$data['name'] : 'Twitter User',
    'username' => isset($data['username']) ? (string)$data['username'] : 'twitter_' . substr((string)$data['id'], 0, 8),
];

$result = social_login_finalize($db, 'twitter', (string)$data['id'], $profile);
if (($result['status'] ?? '') !== 'success') {
    $fail('social_login_finalize_error', 'Failed to create account from Twitter profile.');
}

social_login_flash('success', (string)(customLang('social_login_success') ?: 'Logged in successfully.'));
header('Location: ' . $baseRedirect);
exit;
