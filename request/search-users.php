<?php
declare(strict_types=1);
include '../includes/inc.php';
if (defined('APP_DEBUG') && APP_DEBUG === true) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}
header('Content-Type: application/json');

$csrfToken = (string) ($_POST['csrf_token'] ?? '');
if (!checkCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'invalid_csrf',
        'message_text' => customLang('invalid_csrf_token')
    ]);
    exit;
}

$keyword = isset($_POST['user_search']) ? trim($_POST['user_search']) : '';
$userID  = $loggedIn === '1' ? (int)$userData['user_id'] : 0;
$creatorOnly = isset($_POST['creator_only']) && (int) $_POST['creator_only'] === 1;

if (empty($keyword)) {
    echo json_encode([
        'status' => 'success',
        'html' => ''
    ]);
    exit;
}

$users = $RL->RL_SearchUser($keyword, $userID, $creatorOnly);
$hashtags = $creatorOnly || !method_exists($RL, 'RL_SearchHashtags') ? [] : $RL->RL_SearchHashtags($keyword, 6);
$posts = $creatorOnly || !method_exists($RL, 'RL_SearchPosts') ? [] : $RL->RL_SearchPosts($keyword, $userID, 6);

ob_start();
if (!empty($users) || !empty($hashtags) || !empty($posts)) {
    if (!empty($users)) {
        echo '<div class="search-result-section-title">' . iN_HelpSecure(customLang('search_section_users', 'Users')) . '</div>';
    }
    foreach ($users as $user) {
        ?>
        <?php
            $avatarRel = isset($user['avatar']) ? (string)$user['avatar'] : '';
            if (function_exists('storage_resolve_media_url')) {
                $avatarUrl = storage_resolve_media_url($avatarRel, $base_url ?? '');
            } else {
                $avatarUrl = (string)($base_url ?? '') . ltrim($avatarRel, '/');
            }
        ?>
        <a href="<?php echo iN_HelpSecure($base_url.'profile/'.$user['username']);?>" class="search-result-item">
            <img class="search-result-avatar" src="<?php echo iN_HelpSecure($avatarUrl); ?>" alt="<?php echo iN_HelpSecure($user['username']); ?>">
            <div class="search-result-info">
                <div class="search-result-username">
                    <?php echo iN_HelpSecure($user['username']); ?>
                    <?php if($user['verified_status'] == 1){ ?>
                    <span class="verified-badge">
                        <?php echo renderVerifiedBadge($iconPath . 'verified.svg');?>
                    </span>
                    <?php } ?>
                </div>
                <div class="search-result-fullname">
                    <?php echo iN_HelpSecure($user['fullname']); ?>
                    <?php if (!empty($user['relationship'])) { ?>
                        <span class="search-result-dot">&middot;</span>
                        <span class="search-result-meta"><?php echo iN_HelpSecure($user['relationship']); ?></span>
                    <?php } ?>
                </div>
            </div>
        </a>
        <?php
    }
    if (!empty($hashtags)) {
        echo '<div class="search-result-section-title">' . iN_HelpSecure(customLang('search_section_hashtags', 'Hashtags')) . '</div>';
        foreach ($hashtags as $tag) {
            $tagText = (string) ($tag['display_tag'] ?? $tag['tag'] ?? '');
            $tagSlug = (string) ($tag['tag'] ?? $tagText);
            ?>
            <a href="<?php echo iN_HelpSecure(rtrim($base_url, '/') . '/explore?tag=' . rawurlencode($tagSlug)); ?>" class="search-result-item">
                <span class="search-result-avatar search-result-avatar--icon">#</span>
                <div class="search-result-info">
                    <div class="search-result-username">#<?php echo iN_HelpSecure($tagText); ?></div>
                    <div class="search-result-fullname">
                        <?php echo iN_HelpSecure(strtr(customLang('search_hashtag_posts_count', '{count} posts'), ['{count}' => (int) ($tag['use_count'] ?? 0)])); ?>
                    </div>
                </div>
            </a>
            <?php
        }
    }
    if (!empty($posts)) {
        echo '<div class="search-result-section-title">' . iN_HelpSecure(customLang('search_section_posts', 'Posts')) . '</div>';
        foreach ($posts as $post) {
            $thumbRel = isset($post['thumb']) ? (string) $post['thumb'] : '';
            $thumbUrl = '';
            if ($thumbRel !== '') {
                if (function_exists('storage_resolve_media_url')) {
                    $thumbUrl = storage_resolve_media_url($thumbRel, $base_url ?? '');
                } else {
                    $thumbUrl = (string)($base_url ?? '') . ltrim($thumbRel, '/');
                }
            }
            $postTitle = trim((string) ($post['title'] ?? ''));
            if ($postTitle === '') {
                $postTitle = customLang('search_post_fallback_title', 'Post');
            }
            $username = (string) ($post['username'] ?? '');
            ?>
            <a href="<?php echo iN_HelpSecure(rtrim($base_url, '/') . '/posts/' . (int) $post['post_id'] . ($username !== '' ? '/' . rawurlencode($username) : '')); ?>" class="search-result-item">
                <?php if ($thumbUrl !== '') { ?>
                    <img class="search-result-avatar search-result-thumb" src="<?php echo iN_HelpSecure($thumbUrl); ?>" alt="<?php echo iN_HelpSecure($postTitle); ?>">
                <?php } else { ?>
                    <span class="search-result-avatar search-result-avatar--icon"><?php echo render_icon('post', false); ?></span>
                <?php } ?>
                <div class="search-result-info">
                    <div class="search-result-username"><?php echo iN_HelpSecure($postTitle); ?></div>
                    <div class="search-result-fullname">
                        <?php echo iN_HelpSecure('@' . $username); ?>
                        <?php if (!empty($post['excerpt'])) { ?>
                            <span class="search-result-dot">&middot;</span>
                            <span class="search-result-meta"><?php echo iN_HelpSecure((string) $post['excerpt']); ?></span>
                        <?php } ?>
                    </div>
                </div>
            </a>
            <?php
        }
    }
} else {
    echo '<div class="no-search-result flex align_items_justify_content flexBoxColumn">'
     . renderVerifiedBadge($iconPath . 'not_founded.svg')
     . customLang('search_no_results_all', 'No matching results found.') .
     '</div>';
}
$html = ob_get_clean();

echo json_encode([
    'status' => 'success',
    'html' => base64_encode($html)
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;
