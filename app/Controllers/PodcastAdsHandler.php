<?php
declare(strict_types=1);

namespace CreatorPulse\App\Controllers;

use Reel_Data;
use Throwable;

class PodcastAdsHandler
{
    private Reel_Data $repository;

    public function __construct(Reel_Data $repository)
    {
        $this->repository = $repository;
    }

    private function isCreatorApproved(): bool
    {
        $status = isset($GLOBALS['creatorStatus']) ? strtolower((string) $GLOBALS['creatorStatus']) : '';
        return $status === 'approved';
    }

    private function requireCreatorApproval(): bool
    {
        if ($this->isCreatorApproved()) {
            return true;
        }

        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => customLang(
                'creator_access_required_desc',
                'Only approved creators can view this section. Submit your creator application to unlock it.'
            ),
        ]);
        return false;
    }

    private function normalizeUrl(string $url): ?string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        if (!filter_var($trimmed, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = @parse_url($trimmed);
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        return $trimmed;
    }

    private function parseDateValue(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $ts = @strtotime($value);
        if ($ts === false || $ts <= 0) {
            return null;
        }
        return (int) $ts;
    }

    public function handleCreate(): void
    {
        global $loggedIn, $userID, $availableUploadFileSize, $base_url;

        $repo = $this->repository;
        header('Content-Type: application/json; charset=utf-8');

        $uid = isset($userID) ? (int) $userID : 0;
        if ($loggedIn !== '1' || $uid <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            return;
        }

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            return;
        }

        if (!$this->requireCreatorApproval()) {
            return;
        }

        if (!method_exists($repo, 'RL_CreatePodcastAd')) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => customLang('hero_ads_error', 'Unable to submit your request right now.')]);
            return;
        }

        $pendingCount = $repo->RL_CountPodcastAdsByStatus($uid, 'pending');
        if ($pendingCount > 0) {
            http_response_code(429);
            echo json_encode([
                'status' => 'error',
                'message' => customLang('hero_ads_awaiting_approval', 'You already have a request awaiting approval.') . ' (pending_exists)'
            ]);
            return;
        }

        $recentCount = $repo->RL_CountPodcastAdsSince($uid, time() - 3600);
        if ($recentCount >= 3) {
            http_response_code(429);
            echo json_encode([
                'status' => 'error',
                'message' => customLang('hero_ads_invalid', 'You have reached the submission limit. Please try again later.') . ' (rate_limit)'
            ]);
            return;
        }

        $title = trim((string) ($_POST['hero_ad_title'] ?? ''));
        $subtitle = trim((string) ($_POST['hero_ad_subtitle'] ?? ''));
        $podcastId = isset($_POST['podcast_post_id']) ? (int) $_POST['podcast_post_id'] : 0;

        if ($title === '' || $podcastId <= 0) {
            http_response_code(422);
            echo json_encode([
                'status' => 'error',
                'message' => customLang('hero_ads_invalid', 'Please select a podcast and title.') . ' (missing_title_or_podcast)'
            ]);
            return;
        }

        $paymentId = isset($_POST['podcast_ad_payment_id']) ? (int) $_POST['podcast_ad_payment_id'] : 0;
        $paymentToken = trim((string) ($_POST['podcast_ad_payment_token'] ?? ''));
        if ($paymentId <= 0 || $paymentToken === '') {
            http_response_code(402);
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('hero_ads_payment_required', 'Please complete payment before submitting.')
            ]);
            return;
        }

        $paymentRow = $repo->RL_ConsumePodcastAdPayment($paymentId, $uid, $paymentToken);
        if (!$paymentRow) {
            http_response_code(402);
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('hero_ads_payment_invalid', 'Payment could not be verified.')
            ]);
            return;
        }

        $packageSnapshot = [];
        if (isset($paymentRow['package_snapshot']) && is_array($paymentRow['package_snapshot'])) {
            $packageSnapshot = $paymentRow['package_snapshot'];
        } elseif (!empty($paymentRow['package_snapshot']) && is_string($paymentRow['package_snapshot'])) {
            $decoded = json_decode((string) $paymentRow['package_snapshot'], true);
            if (is_array($decoded)) {
                $packageSnapshot = $decoded;
            }
        }

        $packageId = (int) ($paymentRow['package_id'] ?? ($packageSnapshot['package_id'] ?? 0));
        $premiumSelected = !empty($paymentRow['premium_selected']) || (!empty($packageSnapshot['premium_selected']));
        $targetingSelected = !empty($paymentRow['targeting_selected']) || (!empty($packageSnapshot['targeting_selected']));
        $dailyCap = isset($paymentRow['daily_cap']) ? (int) $paymentRow['daily_cap'] : (isset($packageSnapshot['daily_cap']) ? (int) $packageSnapshot['daily_cap'] : null);
        if ($dailyCap !== null && $dailyCap <= 0) {
            $dailyCap = null;
        }
        $totalCap = isset($paymentRow['total_cap']) ? (int) $paymentRow['total_cap'] : (isset($packageSnapshot['total_cap']) ? (int) $packageSnapshot['total_cap'] : null);
        if ($totalCap !== null && $totalCap <= 0) {
            $totalCap = null;
        }
        $durationDays = isset($paymentRow['duration_days']) ? (int) $paymentRow['duration_days'] : (isset($packageSnapshot['duration_days']) ? (int) $packageSnapshot['duration_days'] : 7);
        if ($durationDays <= 0) {
            $durationDays = 7;
        }
        $budgetAmount = isset($paymentRow['amount']) ? (float) $paymentRow['amount'] : (float) ($packageSnapshot['amount'] ?? 0.0);
        $currency = (string) ($paymentRow['currency'] ?? ($packageSnapshot['currency'] ?? ''));
        if ($currency === '') {
            $currency = isset($GLOBALS['currency']) && is_string($GLOBALS['currency']) ? (string) $GLOBALS['currency'] : 'USD';
        }

        $startAt = time();
        $endAt = $durationDays > 0 ? $startAt + ($durationDays * 86400) - 1 : null;

        $postData = $this->resolvePodcastPost($podcastId, $uid);
        if ($postData === null) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => customLang('hero_ads_invalid', 'Podcast not found.') . ' (podcast_missing)'
            ]);
            return;
        }
        $postUrl = rtrim((string) $base_url, '/') . '/posts/' . $podcastId;
        if (!empty($postData['username'])) {
            $postUrl .= '/' . $postData['username'];
        }

        $primaryLabel = customLang('hero_ads_listen_now', 'Listen now');
        $secondaryLabel = customLang('hero_ads_see_podcast', 'See podcast');

        $coverPath = $this->handleCoverUpload($availableUploadFileSize, $uid);
        if ($coverPath === null) {
            return;
        }

        $payload = [
            'title'               => $title,
            'subtitle'            => $subtitle,
            'podcast_post_id'     => $podcastId,
            'cta_primary_label'   => $primaryLabel,
            'cta_primary_url'     => $postUrl,
            'cta_secondary_label' => $secondaryLabel,
            'cta_secondary_url'   => $postUrl,
            'cover_path'          => $coverPath,
            'status'              => 'pending',
            'start_at'            => $startAt,
            'end_at'              => $endAt,
            'created_at'          => time(),
            'package_id'          => $packageId > 0 ? $packageId : null,
            'payment_id'          => $paymentId,
            'is_premium'          => $premiumSelected ? 1 : 0,
            'daily_cap'           => $dailyCap,
            'total_cap'           => $totalCap,
            'budget_amount'       => $budgetAmount,
            'currency'            => $currency,
            'targeting_meta'      => ['targeting_selected' => $targetingSelected],
        ];

        $adId = $repo->RL_CreatePodcastAd($uid, $payload);
        if ($adId <= 0) {
            $repo->RL_ResetPodcastAdPayment($paymentId, $uid);
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => customLang('hero_ads_error', 'Unable to submit your request right now.') . ' (db_insert_failed)']);
            return;
        }

        $repo->RL_AttachPodcastAdPaymentToAd($paymentId, $uid, $adId);

        echo json_encode([
            'status'  => 'success',
            'message' => customLang('hero_ads_success', 'Submitted for review.'),
            'data'    => [
                'id'     => $adId,
                'status' => 'pending',
            ],
        ]);
    }

    public function handleListPackages(): void
    {
        global $loggedIn, $userID;
        header('Content-Type: application/json; charset=utf-8');
        $uid = isset($userID) ? (int) $userID : 0;
        if ($loggedIn !== '1' || $uid <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            return;
        }
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            return;
        }

        if (!$this->requireCreatorApproval()) {
            return;
        }

        $packages = [];
        $lang = isset($GLOBALS['currentLang']) && is_string($GLOBALS['currentLang'])
            ? strtolower((string) $GLOBALS['currentLang'])
            : null;
        try {
            if (method_exists($this->repository, 'RL_ListPodcastAdPackages')) {
                $packages = $this->repository->RL_ListPodcastAdPackages('active', $lang);
            }
        } catch (Throwable $__) {
            $packages = [];
        }

        if (!$packages) {
            $currency = isset($GLOBALS['currency']) && is_string($GLOBALS['currency'])
                ? (string) $GLOBALS['currency']
                : (isset($GLOBALS['default_currency']) ? (string) $GLOBALS['default_currency'] : 'USD');
            $seedPackages = $this->buildSeedPackages($currency);

            // Attempt to persist seeds; ignore failures but still return seeds for UI.
            if (method_exists($this->repository, 'RL_SavePodcastAdPackage')) {
                foreach ($seedPackages as $pkg) {
                    try {
                        $this->repository->RL_SavePodcastAdPackage($pkg, null);
                    } catch (Throwable $__) {
                        // ignore
                    }
                }
                try {
                    $packages = $this->repository->RL_ListPodcastAdPackages('active');
                } catch (Throwable $__) {
                    $packages = [];
                }
            }

            if (!$packages) {
                $packages = $seedPackages;
            }
        }

        $normalized = [];
        foreach ($packages as $pkg) {
            $normalized[] = [
                'id'                 => (int) ($pkg['id'] ?? 0),
                'name'               => trim((string) ($pkg['name'] ?? '')),
                'description'        => trim((string) ($pkg['description'] ?? '')),
                'price'              => (float) ($pkg['price'] ?? 0),
                'currency'           => (string) ($pkg['currency'] ?? 'USD'),
                'duration_days'      => (int) ($pkg['duration_days'] ?? 0),
                'daily_cap'          => isset($pkg['daily_cap']) ? (int) $pkg['daily_cap'] : null,
                'total_cap'          => isset($pkg['total_cap']) ? (int) $pkg['total_cap'] : null,
                'premium_multiplier' => isset($pkg['premium_multiplier']) ? (float) $pkg['premium_multiplier'] : 1.0,
                'targeting_fee'      => isset($pkg['targeting_fee']) ? (float) $pkg['targeting_fee'] : 0.0,
            ];
        }

        echo json_encode([
            'status'   => 'success',
            'packages' => $normalized,
        ]);
    }

    private function buildSeedPackages(string $currency): array
    {
        $currency = $currency !== '' ? strtoupper($currency) : 'USD';
        $now = time();
        return [
            [
                'name'               => 'Starter 7d',
                'description'        => 'Hero slot for 7 days, balanced reach.',
                'price'              => 15.00,
                'currency'           => $currency,
                'duration_days'      => 7,
                'daily_cap'          => 800,
                'total_cap'          => 4000,
                'premium_multiplier' => 2.00,
                'targeting_fee'      => 3.00,
                'status'             => 'active',
                'sort_order'         => 10,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'name'               => 'Growth 14d',
                'description'        => 'Extended visibility with higher caps.',
                'price'              => 25.00,
                'currency'           => $currency,
                'duration_days'      => 14,
                'daily_cap'          => 1500,
                'total_cap'          => 9000,
                'premium_multiplier' => 2.25,
                'targeting_fee'      => 4.00,
                'status'             => 'active',
                'sort_order'         => 20,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
            [
                'name'               => 'Pro 30d',
                'description'        => 'Maximum runway and premium reach.',
                'price'              => 45.00,
                'currency'           => $currency,
                'duration_days'      => 30,
                'daily_cap'          => 2200,
                'total_cap'          => 16000,
                'premium_multiplier' => 2.50,
                'targeting_fee'      => 5.00,
                'status'             => 'active',
                'sort_order'         => 30,
                'created_at'         => $now,
                'updated_at'         => $now,
            ],
        ];
    }

    public function handleStartPayment(): void
    {
        global $loggedIn, $userID;
        header('Content-Type: application/json; charset=utf-8');
        $uid = isset($userID) ? (int) $userID : 0;
        if ($loggedIn !== '1' || $uid <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            return;
        }
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            return;
        }

        if (!$this->requireCreatorApproval()) {
            return;
        }

        $packageId = isset($_POST['package_id']) ? (int) $_POST['package_id'] : 0;
        $provider = strtolower(trim((string) ($_POST['provider'] ?? 'wallet')));
        $premiumSelected = isset($_POST['premium_selected']) && (string) $_POST['premium_selected'] !== '' ? (bool) $_POST['premium_selected'] : false;
        $targetingSelected = isset($_POST['targeting_selected']) && (string) $_POST['targeting_selected'] !== '' ? (bool) $_POST['targeting_selected'] : false;

        if ($packageId <= 0) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => customLang('hero_ads_payment_required', 'Please complete payment before submitting.')]);
            return;
        }

        $purchase = $this->repository->RL_PurchasePodcastAdPackage($uid, $packageId, $provider, $premiumSelected, $targetingSelected);
        if (empty($purchase['ok'])) {
            $error = (string) ($purchase['error'] ?? '');
            $message = customLang('hero_ads_payment_failed', 'Unable to start payment.');
            $code = 400;
            if ($error === 'insufficient_funds') {
                $message = customLang('hero_ads_payment_insufficient', 'Not enough balance. Please top up your wallet.');
                $code = 402;
            } elseif ($error === 'provider_unavailable') {
                $message = customLang('hero_ads_payment_unavailable', 'Payment provider unavailable.');
            } elseif ($error === 'package_unavailable') {
                $message = customLang('hero_ads_payment_package_missing', 'Selected package is not available.');
                $code = 404;
            }
            http_response_code($code);
            echo json_encode(['status' => 'error', 'message' => $message]);
            return;
        }

        echo json_encode([
            'status'      => 'success',
            'payment_id'  => (int) ($purchase['payment_id'] ?? 0),
            'payment_token' => (string) ($purchase['token'] ?? ''),
            'amount'      => (float) ($purchase['amount'] ?? 0),
            'currency'    => (string) ($purchase['currency'] ?? ''),
            'package'     => $purchase['package'] ?? [],
            'balance'     => isset($purchase['balance']) ? (float) $purchase['balance'] : null,
        ]);
    }

    public function handleListMyPodcasts(): void
    {
        global $loggedIn, $userID;
        header('Content-Type: application/json; charset=utf-8');

        $uid = isset($userID) ? (int) $userID : 0;
        if ($loggedIn !== '1' || $uid <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            return;
        }
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            return;
        }

        if (!$this->requireCreatorApproval()) {
            return;
        }

        $items = [];
        try {
            if (method_exists($this->repository, 'RL_ProfileGetPodcastsList')) {
                $items = $this->repository->RL_ProfileGetPodcastsList($uid, 50, null);
            }
        } catch (Throwable $__) {
            $items = [];
        }

        $out = [];
        foreach ($items as $row) {
            $id = (int) ($row['post_id'] ?? 0);
            if ($id <= 0) { continue; }
            $out[] = [
                'id'       => $id,
                'title'    => trim((string) ($row['title'] ?? '')),
                'cover'    => (string) ($row['cover_url'] ?? ''),
                'duration' => isset($row['duration']) ? (int) $row['duration'] : null,
                'created'  => isset($row['created']) ? (int) $row['created'] : null,
            ];
        }

        echo json_encode(['status' => 'success', 'podcasts' => $out]);
    }

    public function handleDelete(): void
    {
        global $loggedIn, $userID;

        header('Content-Type: application/json; charset=utf-8');
        $uid = isset($userID) ? (int) $userID : 0;
        if ($loggedIn !== '1' || $uid <= 0) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
            return;
        }
        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            return;
        }

        if (!$this->requireCreatorApproval()) {
            return;
        }
        $adId = isset($_POST['ad_id']) ? (int) $_POST['ad_id'] : 0;
        if ($adId <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => customLang('hero_ads_invalid', 'Invalid request.')]);
            return;
        }
        $row = $this->repository->RL_GetPodcastAdById($adId);
        if (!$row || (int) ($row['created_by'] ?? 0) !== $uid) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('hero_ads_invalid', 'Invalid request.')]);
            return;
        }
        $status = strtolower((string) ($row['status'] ?? ''));
        if ($status === 'approved') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => customLang('hero_ads_invalid', 'Approved items cannot be deleted.')]);
            return;
        }
        $deleted = $this->repository->RL_DeletePodcastAd($adId);
        if (!$deleted) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => customLang('hero_ads_error', 'Unable to delete the request.')]);
            return;
        }

        echo json_encode(['status' => 'success', 'message' => customLang('hero_ads_deleted', 'Request deleted.')]);
    }

    public function handleTrackClick(): void
    {
        global $base_url;

        $adId = isset($_GET['ad_id']) ? (int) $_GET['ad_id'] : 0;
        $which = isset($_GET['which']) ? strtolower((string) $_GET['which']) : 'primary';
        $csrfToken = (string) ($_GET['csrf_token'] ?? '');

        if ($adId <= 0 || !checkCsrfToken($csrfToken)) {
            http_response_code(400);
            echo 'Invalid request';
            return;
        }

        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $hostOk = $referer === '';
        if ($referer !== '' && $base_url !== '') {
            $refHost = parse_url($referer, PHP_URL_HOST);
            $baseHost = parse_url((string) $base_url, PHP_URL_HOST);
            if ($refHost && $baseHost && strcasecmp((string) $refHost, (string) $baseHost) === 0) {
                $hostOk = true;
            }
        }
        if (!$hostOk) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }

        $row = $this->repository->RL_GetPodcastAdById($adId);
        if (!$row || strtolower((string) ($row['status'] ?? '')) !== 'approved') {
            $this->redirectSafe($base_url ?? '/');
            return;
        }

        $now = time();
        $startAt = isset($row['start_at']) ? (int) $row['start_at'] : null;
        $endAt = isset($row['end_at']) ? (int) $row['end_at'] : null;
        if (($startAt && $startAt > $now) || ($endAt && $endAt < $now)) {
            $this->redirectSafe($base_url ?? '/');
            return;
        }

        $target = $which === 'secondary'
            ? (string) ($row['cta_secondary_url'] ?? '')
            : (string) ($row['cta_primary_url'] ?? '');
        if ($target === '' && $which === 'secondary') {
            $target = (string) ($row['cta_primary_url'] ?? '');
        }

        $targetUrl = $this->normalizeUrl($target) ?? (string) ($base_url ?? '/');

        if (!isset($_SESSION['podcast_ad_clicks']) || !is_array($_SESSION['podcast_ad_clicks'])) {
            $_SESSION['podcast_ad_clicks'] = [];
        }
        $cooldownSeconds = 10;
        $last = $_SESSION['podcast_ad_clicks'][$adId][$which] ?? 0;
        if (!is_numeric($last)) {
            $last = 0;
        }
        if (($now - (int) $last) >= $cooldownSeconds) {
            $this->repository->RL_RecordPodcastAdClick($adId, $which === 'secondary' ? 'secondary' : 'primary');
            $_SESSION['podcast_ad_clicks'][$adId][$which] = $now;
        }

        $this->redirectSafe($targetUrl);
    }

    private function redirectSafe(string $url): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Location: ' . $url, true, 302);
        exit;
    }

    private function resolvePodcastPost(int $postId, int $ownerId): ?array
    {
        if ($postId <= 0) {
            return null;
        }
        $row = null;
        $listHit = null;

        try {
            $pdo = $this->repository->getDb();
            $baseSql = "SELECT p.post_id, p.post_owner_id, p.post_text, p.post_file, p.post_type, u.username
                        FROM i_user_posts p
                        INNER JOIN i_users u ON u.user_id = p.post_owner_id
                        WHERE p.post_id = :pid";

            // First try enforcing ownership and podcast type
            $stmt = $pdo->prepare($baseSql . " AND p.post_owner_id = :uid AND p.post_type = 'podcast' LIMIT 1");
            $stmt->bindValue(':pid', $postId, \PDO::PARAM_INT);
            $stmt->bindValue(':uid', $ownerId, \PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                // Relax to owner-only regardless of type (just in case type is inconsistent)
                $stmt = $pdo->prepare($baseSql . " AND p.post_owner_id = :uid LIMIT 1");
                $stmt->bindValue(':pid', $postId, \PDO::PARAM_INT);
                $stmt->bindValue(':uid', $ownerId, \PDO::PARAM_INT);
                $stmt->execute();
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            }

            if (!$row) {
                // Relax to any podcast with this ID
                $stmt = $pdo->prepare($baseSql . " AND p.post_type = 'podcast' LIMIT 1");
                $stmt->bindValue(':pid', $postId, \PDO::PARAM_INT);
                $stmt->execute();
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            }

            if (!$row) {
                // Final DB fallback: any post with this ID (ownership still checked later)
                $stmt = $pdo->prepare($baseSql . " LIMIT 1");
                $stmt->bindValue(':pid', $postId, \PDO::PARAM_INT);
                $stmt->execute();
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            }
        } catch (Throwable $__) {
            $row = null;
        }

        if (!$row && method_exists($this->repository, 'RL_GetPostData')) {
            $data = $this->repository->RL_GetPostData($postId);
            if (is_array($data)) {
                $row = $data + ['username' => ''];
            }
        }

        if ((!$row || strtolower((string) ($row['post_type'] ?? '')) !== 'podcast') && method_exists($this->repository, 'RL_ProfileGetPodcastsList')) {
            try {
                $list = $this->repository->RL_ProfileGetPodcastsList($ownerId, 200, null);
                foreach ($list as $item) {
                    if ((int) ($item['post_id'] ?? 0) === $postId) {
                        $listHit = $item;
                        if (!$row) {
                            $row = [
                                'post_id'       => $postId,
                                'post_owner_id' => $ownerId,
                                'post_text'     => $item['title'] ?? '',
                                'post_file'     => $item['audio_url'] ?? '',
                                'post_type'     => 'podcast',
                                'username'      => '',
                            ];
                        }
                        break;
                    }
                }
            } catch (\Throwable $__) {
                // ignore
            }
        }

        if (!$row) {
            return null;
        }

        if ((int) ($row['post_owner_id'] ?? 0) !== $ownerId) {
            return null;
        }

        $type = strtolower((string) ($row['post_type'] ?? ''));
        $isPodcast = $type === 'podcast' || $type === 'audio' || $listHit !== null;
        if (!$isPodcast) {
            return null;
        }

        $cover = '';
        if (isset($row['cover_path']) && (string) $row['cover_path'] !== '') {
            $cover = (string) $row['cover_path'];
        }
        if ($cover === '' && isset($listHit['cover_url'])) {
            $cover = (string) $listHit['cover_url'];
        }
        if ($cover === '' && method_exists($this->repository, 'RL_GetPostCoverPath')) {
            $cover = (string) $this->repository->RL_GetPostCoverPath($postId);
        }

        return [
            'id'       => (int) $row['post_id'],
            'username' => (string) ($row['username'] ?? ''),
            'cover'    => $cover,
            'title'    => trim((string) ($row['post_text'] ?? ($listHit['title'] ?? ''))),
        ];
    }

    private function handleCoverUpload($availableUploadFileSize, int $ownerId): ?string
    {
        if (!isset($_FILES['cover']) || !is_uploaded_file($_FILES['cover']['tmp_name'])) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => customLang('hero_ads_cover_required', 'A cover image is required.')]);
            return null;
        }

        $cover = $_FILES['cover'];
        $coverSize = (int) ($cover['size'] ?? 0);
        $coverTmp = (string) ($cover['tmp_name'] ?? '');
        $maxMb = isset($availableUploadFileSize) ? (float) $availableUploadFileSize : 5.0;
        if ($maxMb <= 0) {
            $maxMb = 5.0;
        }
        $maxBytes = (int) round($maxMb * 1048576);

        if ($coverSize <= 0 || $coverSize > $maxBytes) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => customLang('file_too_large')]);
            return null;
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string) @finfo_file($finfo, $coverTmp);
                @finfo_close($finfo);
            }
        }
        if ($mime === '' && function_exists('mime_content_type')) {
            $mime = (string) @mime_content_type($coverTmp);
        }
        $mime = strtolower(trim($mime));
        if ($mime === 'image/jpg') {
            $mime = 'image/jpeg';
        }
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowedMimes, true)) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => customLang('ui_only_images_allowed')]);
            return null;
        }

        $coverExt = 'jpg';
        if ($mime === 'image/png') {
            $coverExt = 'png';
        } elseif ($mime === 'image/webp') {
            $coverExt = 'webp';
        }

        $today = date('Y-m-d');
        $targetDir = dirname(__DIR__, 2) . '/uploads/podcast_ads/' . $today . '/';
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => customLang('hero_ads_error', 'Unable to save your cover right now.')]);
            return null;
        }

        try {
            $rand = bin2hex(random_bytes(8));
        } catch (Throwable $__) {
            $rand = str_replace('.', '', uniqid('pad_', true));
        }
        $fileName = 'pad_' . $ownerId . '_' . $rand . '.' . $coverExt;
        $dest = rtrim($targetDir, '/') . '/' . $fileName;
        if (!@move_uploaded_file($coverTmp, $dest)) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => customLang('upload_failed')]);
            return null;
        }

        $coverPath = 'uploads/podcast_ads/' . $today . '/' . $fileName;

        try {
            if (function_exists('storage_manager') && storage_manager()->isRemote()) {
                try {
                    $published = storage_publish_relative($coverPath, storage_guess_content_type($coverPath), 'public');
                    $coverPath = $published->getRemoteKey();
                } catch (Throwable $__) {
                    // Keep local path if publish fails
                }
            }
        } catch (Throwable $__) {
            // ignore storage capability errors
        }

        return $coverPath;
    }
}
