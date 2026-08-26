<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/polyfills.php';

// Redirect to installer if first-time setup has not been completed yet.
if (PHP_SAPI !== 'cli') {
    $installDir = __DIR__ . '/install';
    $lockFile   = $installDir . '/.lock';
    $envFile    = __DIR__ . '/.env';

    if (is_dir($installDir) && (!is_file($lockFile) || !is_file($envFile))) {
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (!is_string($requestPath)) {
            $requestPath = '';
        }
        $scriptDir   = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $scriptDir   = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
        $installBase = ($scriptDir === '' ? '' : $scriptDir) . '/install/';

        if (!str_starts_with($requestPath, $installBase)) {
            header('Location: ' . $installBase . 'index.php', true, 302);
            exit;
        }
    }
}

require_once __DIR__ . '/includes/inc.php';

$theme     = 'default';
$themePath = __DIR__ . "/themes/{$theme}/";

$isLoggedIn   = (isset($loggedIn) && $loggedIn === '1');
$scriptPath   = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$requestPath  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scriptPathNormalized = rtrim($scriptPath, '/');
$routeSegment = $requestPath;
if ($scriptPathNormalized !== '' && str_starts_with($routeSegment, $scriptPathNormalized)) {
    $routeSegment = substr($routeSegment, strlen($scriptPathNormalized));
}
$routeSegment = trim($routeSegment, '/');

/** Route patterns and simple page map */
$postRoutePattern    = '~^posts/(\d+)(?:/([A-Za-z0-9_]+))?$~';
$liveRoutePattern    = '~^live/(\d+)$~';
$audioRoomRoutePattern = '~^audio-room/(\d+)$~';
$ebookRoutePattern   = '~^ebook/([A-Za-z0-9-]+)$~';
$embedRoutePattern   = '~^embed/(\d+)$~';
$profileRoutePattern = '~^profile/([A-Za-z0-9_]+)(?:\/(reels|images|podcasts|premiums|subscribers|followers|following))?$~';
$invoiceRoutePattern = '~^invoice/(subscription|tip|purchase|ebook|audio-room-ticket|audio-room-tip)/(\d+)$~';
$pageMap             = [
    'explore'        => 'explore.php',
    'images'         => 'images.php',
    'reels'          => 'reels.php',
    'lives'          => 'lives.php',
    'audio-rooms'    => 'audio_rooms.php',
    'ebooks'         => 'ebooks.php',
    'my-library'     => 'ebook_library.php',
    'messages'       => 'messages.php',
    'podcasts'       => 'podcasts.php',
    'our_creators'   => 'our_creators.php',
    'bookmarks'      => 'bookmarks.php',
    'premium'        => 'premium.php',
    'activity'       => 'activity.php',
    'settings'       => 'settings.php',
    'advertisements' => 'advertisements.php',
    'contact'        => 'contact.php',
];
$disabledSimpleRoutes = [];
if (empty($enableImagePosts)) {
    unset($pageMap['images']);
    $disabledSimpleRoutes[] = 'images';
}
if (empty($enablePodcastPosts)) {
    unset($pageMap['podcasts']);
    $disabledSimpleRoutes[] = 'podcasts';
}
if (empty($enableVideoPosts)) {
    unset($pageMap['reels']);
    $disabledSimpleRoutes[] = 'reels';
}
if (empty($enableAds)) {
    unset($pageMap['advertisements']);
    $disabledSimpleRoutes[] = 'advertisements';
}

$page           = null;
$layoutFileName = null;

/** URL -> page + layout selection (single location) */
if (in_array($routeSegment, $disabledSimpleRoutes, true)) {
    $page           = '404';
    $layoutFileName = null;
} elseif ($routeSegment === '' || $routeSegment === 'index' || $routeSegment === 'index.php') {
    if ($isLoggedIn) {
        $page           = 'main';
        $layoutFileName = 'contents.php';
    } else {
        $landingTheme = 'welcome_default';
        if (isset($RL) && method_exists($RL, 'RL_GetActiveLandingTheme')) {
            $landingTheme = $RL->RL_GetActiveLandingTheme();
        }

        if ($landingTheme === 'guest_main') {
            $page           = 'guest_main';
            $layoutFileName = 'guest_contents.php';
        } else {
            $page           = 'welcome';
            $layoutFileName = 'contents.php';
        }
    }
} elseif (preg_match($postRoutePattern, $routeSegment, $m)) {
    // /posts/{id}/{username?}
    $page             = 'main';
    $_GET['post_id']  = $m[1];
    $_GET['username'] = $m[2] ?? '';
    $layoutFileName   = 'singlePost.php';
} elseif (preg_match($liveRoutePattern, $routeSegment, $m)) {
    // /live/{id}
    $page            = 'main';
    $_GET['live_id'] = $m[1];
    $layoutFileName  = 'live.php';
} elseif (preg_match($audioRoomRoutePattern, $routeSegment, $m)) {
    // /audio-room/{id}
    $page                  = 'main';
    $_GET['audio_room_id'] = $m[1];
    $layoutFileName        = 'audio_room.php';
} elseif (preg_match($ebookRoutePattern, $routeSegment, $m)) {
    // /ebook/{slug}
    $page                = 'main';
    $_GET['ebook_slug']  = $m[1];
    $layoutFileName      = 'ebook_detail.php';
} elseif (preg_match($embedRoutePattern, $routeSegment, $m)) {
    // /embed/{post_id}
    $page            = 'embed';
    $_GET['post_id'] = $m[1];
} elseif (preg_match($profileRoutePattern, $routeSegment, $m)) {
    // /profile/{username}[/{tab}]
    $page             = 'main';
    $_GET['username'] = $m[1];
    $tabRaw           = isset($m[2]) ? strtolower($m[2]) : '';
    // Map path segment to internal filter values
    switch ($tabRaw) {
        case 'reels':$_GET['tab'] = !empty($enableVideoPosts) ? 'reels' : 'all';
            break;
        case 'images':$_GET['tab'] = !empty($enableImagePosts) ? 'images' : 'all';
            break;
        case 'podcasts':$_GET['tab'] = 'podcasts';
            break;
        case 'premiums':$_GET['tab'] = 'premium';
            break;
        case 'subscribers':$_GET['tab'] = 'subscriber';
            break;
        case 'followers':$_GET['tab'] = 'followers';
            break;
        case 'following':$_GET['tab'] = 'following';
            break;
        default: $_GET['tab'] = 'all';
            break;
    }
    $layoutFileName = 'profile.php';
} elseif (preg_match($invoiceRoutePattern, $routeSegment, $m)) {
    // /invoice/{type}/{id} → render standalone document (no site chrome)
    $_GET['invoice_type'] = str_replace('-', '_', $m[1]);
    $_GET['invoice_id']   = $m[2];
    require_once __DIR__ . '/themes/default/layouts/invoice.php';
    exit;
} elseif ($routeSegment === 'guest') {
    $page           = 'guest_main';
    $layoutFileName = 'guest_contents.php';
} elseif (isset($pageMap[$routeSegment])) {
    // /explore, /images and similar simple pages
    $page           = 'main';
    $layoutFileName = $pageMap[$routeSegment];
} elseif (str_starts_with($routeSegment, 'auth/')) {
    $provider = strtolower(substr($routeSegment, 5));
    $authFile = __DIR__ . '/auth/' . $provider . '.php';
    if (is_file($authFile)) {
        require_once $authFile;
        exit;
    }
    $page = '404';
} else {
    // Legacy behavior: /xyz => themes/default/xyz.php
    $page = basename($routeSegment, '.php');
}

/** Access control (single location) */
if ($isLoggedIn === true) {
    if (in_array($page, ['login', 'register'], true)) {
        $page           = 'main';
        $layoutFileName = $layoutFileName ?? 'contents.php';
    }
} else {
    // Guest-accessible pages (theme root)
    $guestAllowedPages = [
        'index', 'login', 'register', 'forgot_password', 'forgot_password_sent', 'reset_password',
        'privacy-policy', 'terms-of-use', '404', 'welcome', 'guest_main',
        // Expose the 'main' shell to guests only for specific layouts
        'main',
    ];

    // Layouts exposed to guests under the main.php shell:
    $mainGuestLayouts = ['singlePost.php', 'explore.php', 'reels.php', 'images.php', 'bookmarks.php', 'profile.php', 'settings.php', 'contact.php', 'audio_rooms.php', 'audio_room.php', 'ebooks.php', 'ebook_detail.php'];
    if (empty($enableVideoPosts)) {
        $mainGuestLayouts = array_values(array_diff($mainGuestLayouts, ['reels.php']));
    }
    if (empty($enableImagePosts)) {
        $mainGuestLayouts = array_values(array_diff($mainGuestLayouts, ['images.php']));
    }

    if ($page === 'main' && in_array($layoutFileName, $mainGuestLayouts, true)) {
        // allow access
    } elseif (!in_array($page, $guestAllowedPages, true)) {
        $page           = 'index';
        $layoutFileName = null;
    }
}

$pageFile = $themePath . $page . '.php';

/** If we're using main.php, pass the selected layout into scope */
if ($page === 'main') {
    $layoutFileName = $layoutFileName ?? 'contents.php';
}

if (file_exists($pageFile)) {
    require_once $pageFile;
} else {
    http_response_code(404);
    require_once $themePath . '404.php';
}
