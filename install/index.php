<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/polyfills.php';

session_start();

$step = isset($_GET['step']) ? (string)$_GET['step'] : 'welcome';
$allowFinish = ($step === 'finish');

// Installer lock: prevent re-entry once completed
$__installLock = __DIR__ . '/.lock';
if (is_file($__installLock) && !$allowFinish) {
    header('HTTP/1.1 403 Forbidden');
    echo '<!doctype html><meta charset="utf-8"><title>Installer Locked</title>';
    echo '<h1>Installer Locked</h1><p>The installer is locked. Remove the <code>install/</code> folder to disable it completely.</p>';
    exit;
}

// Basic page rendering
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function base_url_guess(): string {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path  = trim((string) dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    if ($path !== '') {
        $segments = preg_split('#/+?#', $path, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!empty($segments)) {
            $last = strtolower((string) end($segments));
            if ($last === 'install' || $last === 'installa') {
                array_pop($segments);
            }
        }
        $path = implode('/', $segments);
    }
    $suffix = $path !== '' ? '/' . $path . '/' : '/';
    return rtrim($proto . '://' . $host . $suffix, '/') . '/';
}

if ($step === 'save-db' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim((string)($_POST['db_host'] ?? 'localhost'));
    $dbName = trim((string)($_POST['db_name'] ?? ''));
    $dbUser = trim((string)($_POST['db_user'] ?? ''));
    $dbPass = (string)($_POST['db_pass'] ?? '');
    if ($dbHost === '' || $dbName === '' || $dbUser === '') {
        $_SESSION['err'] = 'Please fill DB host, name and user.';
        header('Location: ?step=db'); exit;
    }
    try {
        $dsn = 'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $_SESSION['db'] = ['host'=>$dbHost,'name'=>$dbName,'user'=>$dbUser,'pass'=>$dbPass,'debug'=>!empty($_POST['debug_errors'])];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $appDebug = !empty($_POST['debug_errors']) ? '1' : '0';
        $envPath = dirname(__DIR__) . '/.env';

        $parseEnvFile = static function (string $path): array {
            if (!is_file($path) || !is_readable($path)) {
                return [];
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $map = [];

            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || $trimmed[0] === '#' || str_starts_with($trimmed, ';')) {
                    continue;
                }

                $parts = explode('=', $trimmed, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                $key = trim($parts[0]);
                $value = trim($parts[1]);
                if ($key === '') {
                    continue;
                }
                $map[$key] = $value;
            }

            return $map;
        };

        $generateSecret = static function (): string {
            $bytes = null;
            try {
                $bytes = random_bytes(32);
            } catch (Throwable $e) {
                if (function_exists('openssl_random_pseudo_bytes')) {
                    $bytes = openssl_random_pseudo_bytes(32);
                }
                if ($bytes === false || $bytes === null) {
                    $bytes = uniqid('', true);
                }
            }

            return 'base64:' . base64_encode($bytes);
        };

        $existingEnv = $parseEnvFile($envPath);
        $appKey = $existingEnv['APP_KEY'] ?? $generateSecret();
        $storageKey = $existingEnv['STORAGE_ENCRYPTION_KEY'] ?? $generateSecret();

        $envValues = [
            'APP_DEBUG' => $appDebug,
            'APP_KEY' => $appKey,
            'STORAGE_ENCRYPTION_KEY' => $storageKey,
            'DB_HOST' => $dbHost,
            'DB_DATABASE' => $dbName,
            'DB_USERNAME' => $dbUser,
            'DB_PASSWORD' => str_replace(["\n", "\r"], '', $dbPass),
        ];

        if (isset($existingEnv['BASE_URL']) && $existingEnv['BASE_URL'] !== '') {
            $envValues['BASE_URL'] = $existingEnv['BASE_URL'];
        }

        $envLines = [];
        foreach ($envValues as $key => $value) {
            $value = str_replace(["\r", "\n"], '', (string) $value);
            $envLines[] = $key . '=' . $value;
        }

        $envContent = implode("\n", $envLines) . "\n";

        $ok = file_put_contents($envPath, $envContent, LOCK_EX);
        if ($ok === false && defined('APP_DEBUG') && APP_DEBUG) { error_log('install: file_put_contents() failed'); }

        // Update connect.php placeholders with provided credentials for legacy config expectations
        $connectPath = dirname(__DIR__) . '/includes/connect.php';
        if (is_file($connectPath) && is_writable($connectPath)) {
            $connectContents = file_get_contents($connectPath);
            if ($connectContents !== false) {
                $escapedHost = str_replace("'", "\\'", $dbHost);
                $escapedName = str_replace("'", "\\'", $dbName);
                $escapedUser = str_replace("'", "\\'", $dbUser);
                $escapedPass = str_replace("'", "\\'", $dbPass);

                $patterns = [
                    "/define\('DB_SERVER',\s*'[^']*'\);/" => "define('DB_SERVER', '" . $escapedHost . "');",
                    "/define\('DB_USERNAME',\s*'[^']*'\);/" => "define('DB_USERNAME', '" . $escapedUser . "');",
                    "/define\('DB_PASSWORD',\s*'[^']*'\);/" => "define('DB_PASSWORD', '" . $escapedPass . "');",
                    "/define\('DB_DATABASE',\s*'[^']*'\);/" => "define('DB_DATABASE', '" . $escapedName . "');",
                ];

                $updatedConnect = preg_replace(array_keys($patterns), array_values($patterns), $connectContents);
                if ($updatedConnect !== null) {
                    file_put_contents($connectPath, $updatedConnect, LOCK_EX);
                }
            }
        }
        header('Location: ?step=owner'); exit;
    } catch (Throwable $e) {
        $_SESSION['err'] = 'DB connection failed: ' . $e->getMessage();
        header('Location: ?step=db'); exit;
    }
}

if ($step === 'save-owner' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($email === '' || $password === '') {
        if ($email !== '') { $_SESSION['owner_prefill'] = ['email' => $email]; }
        $_SESSION['err'] = 'Please provide email and password for the owner admin.';
        header('Location: ?step=owner'); exit;
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        if ($email !== '') { $_SESSION['owner_prefill'] = ['email' => $email]; }
        $_SESSION['err'] = 'Please enter a valid email address.';
        header('Location: ?step=owner'); exit;
    }
    $pwdLength = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
    if ($pwdLength < 8) {
        if ($email !== '') { $_SESSION['owner_prefill'] = ['email' => $email]; }
        $_SESSION['err'] = 'Password must be at least 8 characters long.';
        header('Location: ?step=owner'); exit;
    }
    $_SESSION['owner'] = ['email'=>$email,'password'=>$password];
    unset($_SESSION['owner_prefill']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    header('Location: ?step=install'); exit;
}

if ($step === 'install') {
    $db = $_SESSION['db'] ?? null;
    $owner = $_SESSION['owner'] ?? null;
    if (!$db || !$owner) { header('Location: ?step=welcome'); exit; }
    try {
        $dsn = 'mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Import schema (CREATE/ALTER only) from installer database folder
        $schemaFile = __DIR__ . '/database/schema.sql';
        if (!is_file($schemaFile)) {
            throw new RuntimeException('Schema file not found at install/database/schema.sql');
        }
        $sql = file_get_contents($schemaFile) ?: '';
        $buf = '';
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $buf .= $ch;
            if ($ch === ';') {
                $stmt = trim($buf);
                $buf = '';
                if ($stmt === '') { continue; }

                $normalized = ltrim($stmt);
                // Strip leading SQL comments so CREATE/ALTER detection works
                while ($normalized !== '') {
                    if (str_starts_with($normalized, '--') || str_starts_with($normalized, '#')) {
                        $newlinePos = strpos($normalized, "\n");
                        if ($newlinePos === false) { $normalized = ''; break; }
                        $normalized = ltrim(substr($normalized, $newlinePos + 1));
                        continue;
                    }
                    if (str_starts_with($normalized, '/*')) {
                        $endPos = strpos($normalized, '*/');
                        if ($endPos === false) { $normalized = ''; break; }
                        $normalized = ltrim(substr($normalized, $endPos + 2));
                        continue;
                    }
                    break;
                }

                if ($normalized === '') { continue; }

                $head = strtoupper(substr($normalized, 0, 12));
                $isCreateOrAlter = str_starts_with($head, 'CREATE TABLE') || str_starts_with($head, 'ALTER TABLE');
                $isSiteConfigSeed = preg_match('/^INSERT\s+INTO\s+`?i_site_configurations`?/i', $normalized) === 1;
                $isIconSeed = preg_match('/^INSERT\s+INTO\s+`?i_icons`?/i', $normalized) === 1;
                $isLandingItemSeed = preg_match('/^INSERT\s+INTO\s+`?i_landing_items`?/i', $normalized) === 1;
                $isLandingSectionSeed = preg_match('/^INSERT\s+INTO\s+`?i_landing_sections`?/i', $normalized) === 1;
                $isLandingPageSeed = preg_match('/^INSERT\s+INTO\s+`?i_landing_pages`?/i', $normalized) === 1;
                $isPageContentSeed = preg_match('/^INSERT\s+INTO\s+`?i_page_content`?/i', $normalized) === 1;
                $isPagesSeed = preg_match('/^INSERT\s+INTO\s+`?i_pages`?/i', $normalized) === 1;
                $isMailSettingsSeed = preg_match('/^INSERT\s+INTO\s+`?i_mail_settings`?/i', $normalized) === 1;
                $isLanguagesSeed = preg_match('/^INSERT\s+INTO\s+`?i_languages`?/i', $normalized) === 1;
                $isWordsSeed = preg_match('/^INSERT\s+INTO\s+`?i_words`?/i', $normalized) === 1;
                if ($isCreateOrAlter || $isSiteConfigSeed || $isIconSeed || $isLandingItemSeed || $isLandingSectionSeed || $isLandingPageSeed || $isPageContentSeed || $isPagesSeed || $isMailSettingsSeed || $isLanguagesSeed || $isWordsSeed) {
                    try { $pdo->exec($normalized); } catch (Throwable $__) {}
                }
            }
        }

        // Ensure required runtime tables exist even if the schema import skipped them.
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `i_sessions` (
                    `session_id` int NOT NULL AUTO_INCREMENT,
                    `session_uid` int DEFAULT NULL,
                    `session_key` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
                    `session_time` int NOT NULL DEFAULT '1605484800',
                    PRIMARY KEY (`session_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
            );
        } catch (Throwable $__) {
            // ignore if creation fails; subsequent operations will surface the error
        }

        // Ensure the default site configuration exists even if the seed INSERT failed silently.
        try {
            $siteConfigCount = (int) ($pdo->query('SELECT COUNT(*) FROM i_site_configurations')->fetchColumn() ?: 0);
        } catch (Throwable $__) {
            $siteConfigCount = 0;
        }

        if ($siteConfigCount === 0) {
            $siteConfigSeed = <<<'SQL'
INSERT INTO `i_site_configurations` (
  `id`,
  `maintenance`,
  `site_name`,
  `site_title`,
  `site_description`,
  `site_keywords`,
  `logo_white`,
  `logo_dark`,
  `logo_mobile_dark`,
  `logo_mobile_white`,
  `site_theme`,
  `site_language`,
  `script_version`,
  `premium_post_price_minimum`,
  `premium_post_price_maximum`,
  `available_video_extensions`,
  `available_file_upload_size`,
  `maximum_video_duration`,
  `message_scroll_limit`,
  `page_scroll_limit`,
  `stripe_status`,
  `stripe_currency`,
  `payments_currency`,
  `paypal_status`,
  `paypal_env`,
  `paypal_currency`,
  `nowpayment_status`,
  `now_payment_currency`,
  `coinbase_status`,
  `coinbase_currency`,
  `flutterwave_status`,
  `flutterwave_currency`,
  `flutterwave_public_key`,
  `flutterwave_secret_key`,
  `flutterwave_encryption_key`,
  `flutterwave_secret_hash`,
  `google_status`,
  `facebook_status`,
  `twitter_status`,
  `agora_region`,
  `agora_token_expire_seconds`,
  `agora_enable_rtm`,
  `agora_allow_tokenless`,
  `guest_feed_mode`,
  `payment_fee_percent`,
  `payment_fee_fixed`,
  `payment_tax_percent`,
  `subscription_fee`,
  `live_streaming_enabled`,
  `live_chat_enabled`,
  `onesignal_enabled`,
  `onesignal_auto_prompt`,
  `wallet_topup_status`,
  `wallet_topup_minimum`,
  `wallet_topup_maximum`
) VALUES (
  1,
  'off',
  'CreatorPulse',
  'CreatorPulse – Monetize Short-Form Creators | Subscription, Paywall & Live Video PHP Platform',
  'CreatorPulse is an end-to-end short-form video platform for launching subscription and pay-per-view communities under your own brand. Creators publish 7–14 second clips, run live sessions, and sell premium drops while fans unlock content via wallets, recurring plans, or tips.',
  'CreatorPulse, short video monetization, subscription video platform, pay-per-view reels, creator paywall, live video tips, premium short clips, fan subscriptions, creator wallet payouts, video membership site',
  'uploads/logo/logo-logo_white-6a62c44c80909fd1d372c563f3e5cde9.png',
  'uploads/logo/logo-dark.png',
  'uploads/logo/logo-mobile-dark.png',
  'uploads/logo/logo-mobile-white.png',
  'default',
  'eng',
  '1.0',
  '1.00',
  '500.00',
  'mp4,MP4,mp3,MP3,mpg,mov,m4v,avi,flv,mpeg,MPEG',
  '5120',
  '17',
  '5',
  '10',
  'close',
  'USD',
  'USD',
  'close',
  'sandbox',
  'USD',
  'close',
  'BTC',
  'close',
  'BTC',
  'close',
  'USD',
  NULL,
  NULL,
  NULL,
  NULL,
  'close',
  'close',
  'close',
  'GLOBAL',
  7200,
  1,
  '0',
  'admin_only',
  0.00,
  5.00,
  2.00,
  '5',
  1,
  1,
  0,
  1,
  'open',
  11.00,
  1000.00
);
SQL;
            try { $pdo->exec($siteConfigSeed); } catch (Throwable $__) {}
        }

        // Seed owner admin (#1)
        $email = $owner['email'];
        $username = preg_replace('/[^a-z0-9_]+/i', '', explode('@', $email)[0] ?? 'admin');
        if ($username === '') { $username = 'admin'; }
        $hash = password_hash($owner['password'], PASSWORD_DEFAULT);
        $creatorStatusTimestamp = time();
        $pdo->exec('DELETE FROM i_users WHERE user_id = 1');
        $insUser = $pdo->prepare('INSERT INTO i_users (user_id, username, user_fullname, user_email, user_password, user_type, user_mode, verified_status, subscrition_status, wallet, earned, who_can_send_message, subscription_status, creator_status, creator_status_updated_at) VALUES (1, :u, :f, :e, :p, 3, "admin", 1, "passive", "0", "0", "everyone", "close", "approved", :creator_ts)');
        $insUser->execute([
            ':u' => $username,
            ':f' => 'Owner Admin',
            ':e' => $email,
            ':p' => $hash,
            ':creator_ts' => $creatorStatusTimestamp,
        ]);

        // Seed friends/self-follow entry for owner admin
        $pdo->exec('DELETE FROM i_friends WHERE fr_id = 1');
        $insFriend = $pdo->prepare('INSERT INTO i_friends (fr_id, fr_one, fr_two, fr_time, fr_status) VALUES (1, 1, 1, :t, :status)');
        $insFriend->execute([
            ':t' => time(),
            ':status' => 'me',
        ]);
        // Save BASE_URL to .env using detected base URL
        $baseUrl = base_url_guess();
        $envPath = dirname(__DIR__) . '/.env';
        if (is_file($envPath) && is_writable($envPath)) {
            $content = file_get_contents($envPath) ?: '';
            if (!str_contains($content, 'BASE_URL=')) {
                $content .= "BASE_URL=" . $baseUrl . "\n";
            } else {
                $content = preg_replace('/^BASE_URL=.*$/m', 'BASE_URL=' . $baseUrl, $content);
            }
            $ok = file_put_contents($envPath, $content, LOCK_EX);
            if ($ok === false && defined('APP_DEBUG') && APP_DEBUG) { error_log('install: file_put_contents() failed'); }
        }
        // Create installer lock; finish step will remain accessible for this session only
        $ok = file_put_contents(__DIR__ . '/.lock', (string) time());
        if ($ok === false && defined('APP_DEBUG') && APP_DEBUG) { error_log('install: file_put_contents() failed'); }

        $_SESSION['allow_finish'] = true;
        unset($_SESSION['db'], $_SESSION['owner']);
        $_SESSION['ok'] = 'Installation completed.';
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        header('Location: ?step=finish'); exit;
    } catch (Throwable $e) {
        $_SESSION['err'] = 'Install failed: ' . $e->getMessage();
        header('Location: ?step=owner'); exit;
    }
}


// Views
$GLOBALS['__installerSteps'] = [
    'welcome' => 'Welcome',
    'db'      => 'Database',
    'owner'   => 'Owner Admin',
    'finish'  => 'Finish',
];
$installerLogoRel = '../uploads/logo/logo-mobile-dark.png';
$installerLogoPath = __DIR__ . '/../uploads/logo/logo-mobile-dark.png';
$GLOBALS['__installerLogoSrc'] = is_file($installerLogoPath) ? $installerLogoRel : null;

function render_install_page(string $step, array $view): void
{
    $steps = $GLOBALS['__installerSteps'];
    if (!isset($steps[$step])) {
        $step = 'welcome';
    }
    $order = array_keys($steps);
    $currentIndex = array_search($step, $order, true);
    if ($currentIndex === false) {
        $currentIndex = 0;
    }
    $totalSteps = count($order);
    $progressPercent = $totalSteps > 1 ? (int) round($currentIndex / ($totalSteps - 1) * 100) : 0;
    $pageTitle = $view['page_title'] ?? ($steps[$step] . ' · CreatorPulse Installer');
    $headline = $view['headline'] ?? ($steps[$step] ?? 'CreatorPulse Installer');
    $subtitle = $view['subtitle'] ?? '';
    $body = $view['body'] ?? '';
    $footerNote = array_key_exists('footer_note', $view) ? $view['footer_note'] : 'Need help? Check the docs/ folder or contact support.';
    $badge = $view['badge'] ?? ('Step ' . ($currentIndex + 1) . ' of ' . $totalSteps);
    $lead = $view['lead'] ?? '';

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($pageTitle) . '</title>';
    echo '<style>';
    echo <<<'CSS'
*,
*::before,
*::after {
  box-sizing: border-box;
}
body {
  margin: 0;
  font-family: "Inter","Segoe UI",system-ui,-apple-system,"Helvetica Neue",Arial,sans-serif;
  background: radial-gradient(circle at top right, rgba(79,70,229,0.18), transparent 42%), radial-gradient(circle at bottom left, rgba(14,165,233,0.12), transparent 45%), linear-gradient(180deg, #f8fafc 0%, #e2e8f0 100%);
  color: #0f172a;
  min-height: 100vh;
  display: flex;
  justify-content: center;
  padding: clamp(24px, 6vw, 64px) 18px;
}
.cp-shell {
  width: min(960px, 100%);
  display: flex;
  flex-direction: column;
  gap: 24px;
}
.cp-hero {
  background: rgba(248, 250, 252, 0.8);
  border-radius: 28px;
  padding: clamp(24px, 4vw, 40px);
  box-shadow: 0 24px 60px rgba(15, 23, 42, 0.08);
  backdrop-filter: blur(12px);
  border: 1px solid rgba(148, 163, 184, 0.22);
}
.cp-brand {
  display: flex;
  align-items: center;
  gap: 18px;
  margin-bottom: 24px;
}
.cp-logo {
  width: clamp(64px, 8vw, 104px);
  height: clamp(64px, 8vw, 104px);
  border-radius: 20px;
  background: linear-gradient(135deg, #4f46e5, #db2777);
  display: grid;
  place-items: center;
  color: #fff;
  font-weight: 600;
  font-size: clamp(18px, 3vw, 26px);
  letter-spacing: 0.04em;
  box-shadow: 0 18px 38px rgba(79, 70, 229, 0.35);
  overflow: hidden;
}
.cp-logo.has-img {
  background: #ffffff;
  border: 1px solid rgba(148, 163, 184, 0.4);
  box-shadow: 0 20px 40px rgba(15, 23, 42, 0.12);
  padding: clamp(8px, 1.4vw, 14px);
}
.cp-logo img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
.cp-logo span {
  display: inline-block;
}
.cp-brand__text {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.cp-brand__text h1 {
  font-size: clamp(24px, 4vw, 30px);
  letter-spacing: -0.03em;
  margin: 0;
}
.cp-brand__text p {
  margin: 0;
  font-size: 15px;
  color: #475569;
}
.cp-progress {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.cp-progress__bar {
  position: relative;
  height: 6px;
  background: rgba(148, 163, 184, 0.35);
  border-radius: 999px;
  overflow: hidden;
}
.cp-progress__indicator {
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, #7c3aed, #2563eb);
  transform-origin: left center;
  transition: width 0.4s ease;
}
.cp-progress__steps {
  list-style: none;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 12px;
  padding: 0;
  margin: 0;
}
.cp-progress__step {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 14px;
  color: #64748b;
  font-weight: 500;
}
.cp-progress__step::before {
  content: "";
  width: 14px;
  height: 14px;
  border-radius: 50%;
  border: 2px solid rgba(148, 163, 184, 0.55);
  background: #f8fafc;
  transition: all 0.3s ease;
}
.cp-progress__step.is-active {
  color: #0f172a;
}
.cp-progress__step.is-active::before {
  border-color: #4f46e5;
  box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.15);
}
.cp-progress__step.is-complete {
  color: #0f172a;
}
.cp-progress__step.is-complete::before {
  background: linear-gradient(135deg, #10b981, #14b8a6);
  border-color: transparent;
  box-shadow: 0 0 0 4px rgba(20, 184, 166, 0.15);
}
.cp-card {
  background: #ffffff;
  border-radius: 28px;
  padding: clamp(26px, 4vw, 44px);
  box-shadow: 0 28px 60px rgba(15, 23, 42, 0.11);
  border: 1px solid rgba(148, 163, 184, 0.18);
}
.cp-card__intro {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 28px;
}
.cp-badge {
  align-self: flex-start;
  background: rgba(79, 70, 229, 0.12);
  color: #3730a3;
  border-radius: 999px;
  padding: 6px 16px;
  font-size: 13px;
  font-weight: 600;
  letter-spacing: 0.04em;
}
.cp-card__headline {
  font-size: clamp(24px, 4vw, 32px);
  letter-spacing: -0.04em;
  margin: 0;
}
.cp-card__summary {
  margin: 0;
  font-size: 16px;
  color: #475569;
  max-width: 52ch;
}
.cp-lead {
  margin: 8px 0 0;
  font-size: 16px;
  line-height: 1.6;
  color: #1e293b;
}
.cp-card__content {
  display: flex;
  flex-direction: column;
  gap: 24px;
}
.cp-alert {
  padding: 14px 18px;
  border-radius: 14px;
  font-size: 15px;
  display: flex;
  gap: 12px;
  align-items: flex-start;
  line-height: 1.45;
}
.cp-alert--error {
  background: rgba(248, 113, 113, 0.12);
  color: #b91c1c;
  border: 1px solid rgba(248, 113, 113, 0.3);
}
.cp-alert--success {
  background: rgba(34, 197, 94, 0.12);
  color: #047857;
  border: 1px solid rgba(34, 197, 94, 0.3);
}
.cp-checklist {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 8px;
}
.cp-checklist li {
  position: relative;
  padding-left: 28px;
  font-size: 15px;
  color: #334155;
  line-height: 1.55;
}
.cp-checklist li::before {
  content: "";
  position: absolute;
  left: 0;
  top: 8px;
  width: 16px;
  height: 16px;
  border-radius: 6px;
  background: linear-gradient(135deg, #22c55e, #0ea5e9);
  box-shadow: 0 6px 12px rgba(14, 165, 233, 0.25);
  mask: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="white"><path d="M12.97 4.97a.75.75 0 0 0-1.06-1.06L6.75 9.06 4.59 6.9a.75.75 0 1 0-1.06 1.06l2.66 2.66a.75.75 0 0 0 1.06 0l5.72-5.65Z"/></svg>') center/12px 12px no-repeat;
}
.cp-form {
  display: flex;
  flex-direction: column;
  gap: 20px;
}
.cp-form__grid {
  display: grid;
  gap: 18px;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
}
.cp-field {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.cp-label {
  font-weight: 600;
  font-size: 14px;
  color: #1e293b;
  letter-spacing: 0.01em;
}
.cp-input {
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.55);
  padding: 12px 14px;
  font-size: 15px;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.cp-input:focus {
  outline: none;
  border-color: #6366f1;
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.18);
}
.cp-input::placeholder {
  color: rgba(148, 163, 184, 0.9);
}
.cp-help {
  font-size: 13px;
  color: #64748b;
  line-height: 1.5;
}
.cp-checkbox {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 14px;
  color: #475569;
}
.cp-checkbox input {
  width: 18px;
  height: 18px;
  accent-color: #4f46e5;
}
.cp-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  justify-content: flex-end;
}
.cp-actions--spread {
  justify-content: space-between;
  align-items: center;
}
.cp-btn {
  border: none;
  border-radius: 14px;
  padding: 12px 20px;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  transition: transform 0.2s ease, box-shadow 0.2s ease;
  display: inline-flex;
  align-items: center;
  gap: 10px;
  text-decoration: none;
}
.cp-btn--primary {
  background-image: linear-gradient(135deg, #6366f1, #4338ca);
  color: #fff;
  box-shadow: 0 16px 30px rgba(79, 70, 229, 0.35);
}
.cp-btn--primary:hover {
  transform: translateY(-1px);
  box-shadow: 0 18px 36px rgba(79, 70, 229, 0.4);
}
.cp-btn--ghost {
  background: rgba(148, 163, 184, 0.15);
  color: #334155;
}
.cp-btn--ghost:hover {
  transform: translateY(-1px);
  background: rgba(148, 163, 184, 0.28);
}
.cp-btn--danger {
  background: linear-gradient(135deg, #f97316, #ef4444);
  color: #fff;
  box-shadow: 0 14px 28px rgba(239, 68, 68, 0.32);
}
.cp-btn--danger:hover {
  transform: translateY(-1px);
}
.cp-footer {
  text-align: center;
  font-size: 13px;
  color: #64748b;
  margin-top: -8px;
}
.cp-footer a {
  color: #4338ca;
  text-decoration: none;
  font-weight: 600;
}
.cp-footer a:hover {
  text-decoration: underline;
}
.cp-card__content form {
  margin: 0;
}
.cp-actions .cp-btn[type=submit] {
  border: none;
}
@media (max-width: 640px) {
  body {
    padding: 32px 14px;
  }
  .cp-hero, .cp-card {
    border-radius: 22px;
  }
  .cp-progress__steps {
    grid-template-columns: repeat(2, minmax(120px, 1fr));
  }
  .cp-actions {
    justify-content: flex-start;
  }
}
CSS;
    echo '</style></head><body><div class="cp-shell">';
    $logoSrc = $GLOBALS['__installerLogoSrc'] ?? null;
    $logoMarkup = $logoSrc ? '<img src="' . h($logoSrc) . '" alt="CreatorPulse logo">' : '<span>CP</span>';
    $logoClass = 'cp-logo' . ($logoSrc ? ' has-img' : '');
    echo '<header class="cp-hero">';
    echo '<div class="cp-brand"><div class="' . $logoClass . '">' . $logoMarkup . '</div><div class="cp-brand__text"><h1>CreatorPulse Installer</h1><p>Guided setup to launch your creator membership platform.</p></div></div>';
    echo '<div class="cp-progress">';
    echo '<div class="cp-progress__bar"><span class="cp-progress__indicator" style="width:' . $progressPercent . '%"></span></div>';
    echo '<ul class="cp-progress__steps">';
    foreach ($order as $idx => $slug) {
        $label = $steps[$slug];
        $classes = 'cp-progress__step';
        if ($idx < $currentIndex) {
            $classes .= ' is-complete';
        } elseif ($idx === $currentIndex) {
            $classes .= ' is-active';
        }
        echo '<li class="' . $classes . '">' . h($label) . '</li>';
    }
    echo '</ul></div></header>';
    echo '<main class="cp-card"><div class="cp-card__intro">';
    if ($badge !== '') {
        echo '<span class="cp-badge">' . h($badge) . '</span>';
    }
    echo '<h2 class="cp-card__headline">' . h($headline) . '</h2>';
    if ($subtitle !== '') {
        echo '<p class="cp-card__summary">' . h($subtitle) . '</p>';
    }
    if ($lead !== '') {
        echo '<p class="cp-lead">' . h($lead) . '</p>';
    }
    echo '</div><div class="cp-card__content">' . $body . '</div></main>';
    if ($footerNote !== false) {
        echo '<footer class="cp-footer">' . h((string) $footerNote) . '</footer>';
    }
    echo '</div></body></html>';
}

// Handle delete-installer action at finish step (before rendering final view)
if ($step === 'finish' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete-installer') {
    if (empty($_SESSION['allow_finish'])) {
        $_SESSION['err'] = 'Installer is already locked. Please reload this page or remove the install/ folder manually.';
        header('Location: ?step=finish');
        exit;
    }
    unset($_SESSION['allow_finish']);
    // Create a temporary cleanup script at project root to remove install/ then self-delete
    $token = bin2hex(random_bytes(16));
    $_SESSION['installer_cleanup_token'] = $token;
    $cleanupPath = dirname(__DIR__) . '/installer_cleanup.php';
    $script = <<<'PHP'
<?php
declare(strict_types=1);
session_start();
function rrmdir(string $dir): bool {
    if (!is_dir($dir)) { return true; }
    $items = scandir($dir) ?: [];
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') { continue; }
        $p = $dir . DIRECTORY_SEPARATOR . $it;
        if (is_dir($p)) { rrmdir($p); } else { @chmod($p, 0664); @unlink($p); }
    }
    @chmod($dir, 0775);
    return @rmdir($dir);
}
$ok = false; $msg = '';
if (!isset($_SESSION['installer_cleanup_token']) || !isset($_GET['t']) || $_GET['t'] !== $_SESSION['installer_cleanup_token']) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>Forbidden</title><p>Forbidden.</p>';
    exit;
}
$root = __DIR__;
$installDir = $root . '/install';
if (is_dir($installDir)) {
    $ok = rrmdir($installDir);
    if (!$ok) { $msg = 'Could not remove install directory (insufficient permissions).'; }
}
// Remove self
@unlink(__FILE__);
echo '<!doctype html><meta charset="utf-8"><title>Cleanup</title>';
if ($ok) { echo '<p>Installer folder deleted.</p>'; }
else { echo '<p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'; }
echo '<p><a href="index.php">Return to site</a></p>';
PHP;
    $ok = file_put_contents($cleanupPath, $script);
    if ($ok === false && defined('APP_DEBUG') && APP_DEBUG) { error_log('install: file_put_contents() failed'); }
    header('Location: ../installer_cleanup.php?t=' . $token);
    exit;
}

if ($step === 'welcome') {
    $totalSteps = count($GLOBALS['__installerSteps']);
    ob_start();
    ?>
    <ul class="cp-checklist">
      <li>Have your MySQL or MariaDB credentials ready (host, database, user, password).</li>
      <li>Confirm PHP extensions such as PDO and cURL are enabled on your server.</li>
      <li>Decide which email address will manage the CreatorPulse dashboard.</li>
      <li>After installing, remove the <code>install/</code> folder for security.</li>
    </ul>
    <div class="cp-actions">
      <a class="cp-btn cp-btn--ghost" href="../">Cancel</a>
      <a class="cp-btn cp-btn--primary" href="?step=db">Start Setup</a>
    </div>
    <?php
    $body = ob_get_clean();
    render_install_page('welcome', [
        'page_title' => 'Welcome · CreatorPulse Installer',
        'headline' => 'Welcome to CreatorPulse',
        'subtitle' => 'We will guide you through connecting the database and creating your owner admin.',
        'lead' => 'A few details are all you need to launch your creator platform.',
        'badge' => 'Step 1 of ' . $totalSteps,
        'body' => $body,
        'footer_note' => 'CreatorPulse Installer v1.0',
    ]);
    exit;
}

if ($step === 'db') {
    $totalSteps = count($GLOBALS['__installerSteps']);
    $error = $_SESSION['err'] ?? '';
    unset($_SESSION['err']);
    $db = $_SESSION['db'] ?? ['host'=>'localhost','name'=>'','user'=>'','pass'=>'','debug'=>false];
    $debugChecked = !empty($db['debug']);
    ob_start();
    if ($error !== '') {
        echo '<div class="cp-alert cp-alert--error">' . h($error) . '</div>';
    }
    ?>
    <form method="post" action="?step=save-db" class="cp-form">
      <div class="cp-form__grid">
        <label class="cp-field">
          <span class="cp-label">Database Host</span>
          <input class="cp-input" type="text" name="db_host" value="<?= h((string)($db['host'] ?? '')); ?>" required placeholder="localhost">
          <span class="cp-help">Use <code>localhost</code>, an IP address, or the hostname provided by your host.</span>
        </label>
        <label class="cp-field">
          <span class="cp-label">Database Name</span>
          <input class="cp-input" type="text" name="db_name" value="<?= h((string)($db['name'] ?? '')); ?>" required placeholder="creatorpulse">
          <span class="cp-help">CreatorPulse will create tables in this database.</span>
        </label>
        <label class="cp-field">
          <span class="cp-label">Database User</span>
          <input class="cp-input" type="text" name="db_user" value="<?= h((string)($db['user'] ?? '')); ?>" required placeholder="db_user">
          <span class="cp-help">Ensure this user has CREATE and ALTER permissions.</span>
        </label>
        <label class="cp-field">
          <span class="cp-label">Database Password</span>
          <input class="cp-input" type="password" name="db_pass" value="<?= h((string)($db['pass'] ?? '')); ?>" placeholder="••••••••">
          <span class="cp-help">Leave blank only if the DB user has no password (not recommended).</span>
        </label>
      </div>
      <label class="cp-checkbox">
        <input type="checkbox" name="debug_errors" value="1"<?= $debugChecked ? ' checked' : ''; ?>>
        <span>Show detailed installation errors (for troubleshooting only).</span>
      </label>
      <div class="cp-actions">
        <a class="cp-btn cp-btn--ghost" href="?step=welcome">Back</a>
        <button class="cp-btn cp-btn--primary" type="submit">Save &amp; Continue</button>
      </div>
    </form>
    <?php
    $body = ob_get_clean();
    render_install_page('db', [
        'page_title' => 'Database Setup · CreatorPulse Installer',
        'headline' => 'Connect Your Database',
        'subtitle' => 'Provide MySQL credentials so CreatorPulse can create the required tables.',
        'lead' => 'These credentials are saved to your .env file and used on every request.',
        'badge' => 'Step 2 of ' . $totalSteps,
        'body' => $body,
    ]);
    exit;
}

if ($step === 'owner') {
    $totalSteps = count($GLOBALS['__installerSteps']);
    $error = $_SESSION['err'] ?? '';
    unset($_SESSION['err']);
    $prefill = $_SESSION['owner_prefill']['email'] ?? '';
    unset($_SESSION['owner_prefill']);
    ob_start();
    if ($error !== '') {
        echo '<div class="cp-alert cp-alert--error">' . h($error) . '</div>';
    }
    ?>
    <form method="post" action="?step=save-owner" class="cp-form">
      <div class="cp-form__grid">
        <label class="cp-field">
          <span class="cp-label">Owner Email</span>
          <input class="cp-input" type="text" name="email" value="<?= h((string)$prefill); ?>" required placeholder="owner@yourdomain.com">
          <span class="cp-help">This becomes the primary administrator account for CreatorPulse.</span>
        </label>
        <label class="cp-field">
          <span class="cp-label">Owner Password</span>
          <input class="cp-input" type="password" name="password" required placeholder="Minimum 8 characters">
          <span class="cp-help">Use a strong password. You can add more admins later.</span>
        </label>
      </div>
      <div class="cp-actions">
        <a class="cp-btn cp-btn--ghost" href="?step=db">Back</a>
        <button class="cp-btn cp-btn--primary" type="submit">Create Owner &amp; Install</button>
      </div>
    </form>
    <?php
    $body = ob_get_clean();
    render_install_page('owner', [
        'page_title' => 'Owner Account · CreatorPulse Installer',
        'headline' => 'Create the Owner Admin',
        'subtitle' => 'This account receives full access to the CreatorPulse dashboard.',
        'lead' => 'We will use these credentials to seed the owner user (ID #1).',
        'badge' => 'Step 3 of ' . $totalSteps,
        'body' => $body,
    ]);
    exit;
}

if ($step === 'finish') {
    $totalSteps = count($GLOBALS['__installerSteps']);
    $error = $_SESSION['err'] ?? '';
    $ok = $_SESSION['ok'] ?? '';
    unset($_SESSION['err'], $_SESSION['ok']);
    ob_start();
    if ($ok !== '') {
        echo '<div class="cp-alert cp-alert--success">' . h($ok) . '</div>';
    }
    if ($error !== '') {
        echo '<div class="cp-alert cp-alert--error">' . h($error) . '</div>';
    }
    ?>
    <ul class="cp-checklist">
      <li>Keep your <code>.env</code> credentials safe and do not commit them to version control.</li>
      <li>Delete the <code>install/</code> folder to prevent re-running the installer.</li>
      <li>Review the “General → Site Settings” section to customise branding and URLs.</li>
    </ul>
    <form method="post" action="?step=finish" class="cp-form" onsubmit="return confirm('Delete the installer folder now?');">
      <input type="hidden" name="action" value="delete-installer">
      <div class="cp-actions cp-actions--spread">
        <span class="cp-help">We will attempt to remove the installer directory automatically.</span>
        <button class="cp-btn cp-btn--danger" type="submit">Delete Installer Folder</button>
      </div>
    </form>
    <div class="cp-actions">
      <a class="cp-btn cp-btn--primary" href="../index.php">Launch CreatorPulse</a>
      <a class="cp-btn cp-btn--ghost" href="../admin/">Go to Admin Panel</a>
    </div>
    <?php
    $body = ob_get_clean();
    render_install_page('finish', [
        'page_title' => 'Installation Complete · CreatorPulse Installer',
        'headline' => 'CreatorPulse is ready!',
        'subtitle' => 'Your database is seeded and the owner admin has been created.',
        'lead' => 'Take a moment to secure the installer and explore your new dashboard.',
        'badge' => 'Step 4 of ' . $totalSteps,
        'body' => $body,
    ]);
    session_write_close();
    exit;
}
