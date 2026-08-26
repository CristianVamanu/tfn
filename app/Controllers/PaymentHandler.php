<?php
declare(strict_types=1);

namespace CreatorPulse\App\Controllers;

use DateTime;
use DateTimeZone;
use PDO;
use Reel_Data;
use Throwable;

/**
 * Coordinates wallet, tips, subscription, and purchase payment flows while delegating to PaymentFactory and legacy helpers.
 */
class PaymentHandler
{
    private Reel_Data $repository;

    public function __construct(Reel_Data $repository)
    {
        $this->repository = $repository;
    }

    public function handleSubscriptionsRenewCron(): void
    {
        global $RL, $subscriptionfee, $paymentFeeFixed, $paymentTaxPercent;

        $RL = $this->repository;

        try {
            $isCli = (PHP_SAPI === 'cli');
            $secret = (string) (getenv('SUBSCRIPTIONS_CRON_SECRET') ?: getenv('CRON_SUBSCRIPTIONS_TOKEN') ?: '');
            $secret = trim($secret);

            if ($secret === '') {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'cron_token_not_configured']);
                exit;
            }

            $provided = '';
            if (isset($_GET['token'])) {
                $provided = (string) $_GET['token'];
            } elseif (isset($_POST['token'])) {
                $provided = (string) $_POST['token'];
            }

            if ($provided === '' && !$isCli) {
                $headerToken = (string) ($_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
                if ($headerToken !== '') {
                    $provided = $headerToken;
                }
            }

            if ($provided === '' && !$isCli) {
                $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
                if ($auth !== '' && stripos($auth, 'Bearer ') === 0) {
                    $provided = trim(substr($auth, 7));
                }
            }

            if ($provided === '' && $isCli) {
                $provided = (string) (getenv('SUBSCRIPTIONS_CRON_TOKEN') ?: getenv('CRON_SUBSCRIPTIONS_TOKEN') ?: '');
            }

            if ($provided === '' || !hash_equals($secret, (string) $provided)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'invalid_or_missing_cron_token']);
                exit;
            }

            if (!isset($RL)) { echo json_encode(['status'=>'error','message'=>'DB not available']); exit; }
            $now = time();
            $processed = ['wallet_renewed'=>0, 'wallet_downgraded'=>0, 'other_downgraded'=>0, 'scheduled_downgraded'=>0];

            $subFeePct = isset($subscriptionfee) ? (float)$subscriptionfee : 0.0;
            $feeFixed  = isset($paymentFeeFixed) ? (float)$paymentFeeFixed : 0.0;
            $taxPct    = isset($paymentTaxPercent) ? (float)$paymentTaxPercent : 0.0;

            $rows = method_exists($RL,'RL_GetDueSubscriptions') ? $RL->RL_GetDueSubscriptions($now) : [];
            $downgradeTs = time();
            foreach ($rows as $r) {
                $buyer   = (int)($r['buyer_id'] ?? 0);
                $creator = (int)($r['recipient_id'] ?? 0);
                $prov    = (string)($r['provider'] ?? '');
                $amount  = (float)($r['amount'] ?? 0.0);
                $ival    = (string)($r['plan_interval'] ?? 'monthly');
                $cnt     = (int)($r['interval_count'] ?? 1); if ($cnt <= 0) { $cnt = 1; }
                $curr    = (string)($r['currency'] ?? 'USD');
                if ($buyer <= 0 || $creator <= 0 || $amount <= 0) { continue; }

                if ($prov === 'wallet') {
                    $baseStart = (int)($r['current_period_end'] ?? $now);
                    $res = method_exists($RL,'RL_TryRenewWalletSubscription')
                        ? $RL->RL_TryRenewWalletSubscription($buyer, $creator, $amount, $curr, $ival, $cnt, $baseStart, (float)$feeFixed, (float)$subFeePct, (float)$taxPct)
                        : ['ok'=>false];
                    if (!empty($res['ok'])) { $processed['wallet_renewed']++; }
                    else {
                        if (method_exists($RL,'RL_CancelSubscription')) { $RL->RL_CancelSubscription($buyer, $creator, $downgradeTs, true); }
                        $processed['wallet_downgraded']++;
                    }
                } else {
                    if (method_exists($RL,'RL_CancelSubscription')) { $RL->RL_CancelSubscription($buyer, $creator, $downgradeTs, true); }
                    if (method_exists($RL,'RL_LogSubscriptionRenewAttempt')) {
                        $baseStart = (int)($r['current_period_end'] ?? $now);
                        $RL->RL_LogSubscriptionRenewAttempt($buyer, $creator, $prov, $amount, $curr, $ival, $cnt, $baseStart, 'downgraded', 'provider_not_renewed');
                    }
                    $processed['other_downgraded']++;
                }
            }

            if (method_exists($RL, 'RL_GetCancelledSubscriptionsDueForDowngrade')) {
                $pendingDowngrades = $RL->RL_GetCancelledSubscriptionsDueForDowngrade($now);
                foreach ($pendingDowngrades as $pending) {
                    $buyer   = (int)($pending['buyer_id'] ?? 0);
                    $creator = (int)($pending['recipient_id'] ?? 0);
                    if ($buyer <= 0 || $creator <= 0) { continue; }
                    if (method_exists($RL, 'RL_CancelSubscription')) {
                        $RL->RL_CancelSubscription($buyer, $creator, $downgradeTs, true);
                        $processed['scheduled_downgraded']++;
                    }
                }
            }
            echo json_encode(['status'=>'ok','processed'=>$processed]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        }
        exit;
    }

    public function handleSubscriptionFeesCalculate(): void
    {
        global $loggedIn, $subscriptionfee, $paymentTaxPercent, $currency, $currencys, $currencyFormatSettings;

        try {
            if (!isset($loggedIn) || $loggedIn !== '1') {
                echo json_encode(['status' => 'error', 'message' => customLang('login_required')]);
                exit;
            }

            $platformPercent = isset($subscriptionfee) ? (float) $subscriptionfee : 5.0;
            $taxPercent = isset($paymentTaxPercent) ? (float) $paymentTaxPercent : 0.0;

            if ($platformPercent < 0) { $platformPercent = 0.0; }
            if ($platformPercent > 50) { $platformPercent = 50.0; }
            if ($taxPercent < 0) { $taxPercent = 0.0; }
            if ($taxPercent > 25) { $taxPercent = 25.0; }

            $creatorPercent = 100.0 - ($platformPercent + $taxPercent);
            if ($creatorPercent < 0) { $creatorPercent = 0.0; }

            $currencyCode = isset($currency) ? strtoupper((string) $currency) : 'USD';
            $currencyMap = (isset($currencys) && is_array($currencys)) ? $currencys : [];
            $currencySymbol = isset($currencyMap[$currencyCode]) ? (string) $currencyMap[$currencyCode] : '$';

            $rawAmount = $_GET['amount'] ?? $_POST['amount'] ?? null;
            $amount = is_numeric($rawAmount) ? (float) $rawAmount : 9.99;
            if ($amount < 1) { $amount = 1.0; }
            if ($amount > 1000) { $amount = 1000.0; }

            $platformShare = $amount * ($platformPercent / 100);
            $taxShare = $amount * ($taxPercent / 100);
            $creatorShare = $amount - $platformShare - $taxShare;
            if ($creatorShare < 0) { $creatorShare = 0.0; }

            $formatMoney = static function ($value) use ($currencySymbol, $currencyCode, $currencyFormatSettings): string {
                return dz_format_currency(
                    (float) $value,
                    $currencyCode,
                    $currencySymbol,
                    $currencyFormatSettings ?? null
                );
            };

            $roundedCreator = round($creatorShare, 2);
            $roundedPlatform = round($platformShare, 2);
            $roundedProcessing = round($taxShare, 2);
            $roundedAmount = round($amount, 2);

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'input' => [
                        'amount' => $roundedAmount,
                        'formatted' => dz_format_number($roundedAmount, $currencyCode, $currencyFormatSettings ?? null),
                    ],
                    'amounts' => [
                        'creator' => $formatMoney($roundedCreator),
                        'platform' => $formatMoney($roundedPlatform),
                        'processing' => $formatMoney($roundedProcessing),
                    ],
                    'percentages' => [
                        'creator' => number_format($creatorPercent, 1) . '%',
                        'platform' => number_format($platformPercent, 1) . '%',
                        'processing' => number_format($taxPercent, 1) . '%',
                    ],
                    'raw' => [
                        'creator' => $roundedCreator,
                        'platform' => $roundedPlatform,
                        'processing' => $roundedProcessing,
                        'amount' => $roundedAmount,
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            $RL = $this->repository;
            if (isset($RL) && method_exists($RL, 'logError')) {
                $RL->logError('subscription_fees_calculate failed: ' . $e->getMessage());
            }
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => customLang('server_error')]);
        }
        exit;
    }

    public function handleWalletTopupModal(array $limits): void
    {
        global $currentTheme, $currencys, $currencies, $currency_format_client, $userWallet, $paymentFeePercent, $currency;

        $RL = $this->repository;
        $baseDir = dirname(__DIR__, 2);

        if (empty($limits['enabled'])) {
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('wallet_topup_disabled', 'Wallet top-ups are currently unavailable.')
            ]);
            exit;
        }

        $filePath = $baseDir . '/themes/' . $currentTheme . '/popUps/walletTopup.php';
        if (!is_file($filePath)) {
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('requested_content_not_found', 'Requested content not found.')
            ]);
            exit;
        }

        $cfg = \PaymentFactory::config();
        $globalCurrencyCode = isset($cfg['currency']) ? strtoupper((string) $cfg['currency']) : (isset($currency) ? strtoupper((string) $currency) : 'USD');
        if ($globalCurrencyCode === '') {
            $globalCurrencyCode = isset($currency) && $currency !== '' ? strtoupper((string) $currency) : 'USD';
        }

        $currencySymbol = '';
        // Prefer $currencys, then $currencies (both exist across themes)
        if (isset($currencys) && is_array($currencys)) {
            $symbolCandidate = $currencys[$globalCurrencyCode] ?? '';
            $currencySymbol = is_string($symbolCandidate) ? $symbolCandidate : '';
        }
        if ($currencySymbol === '' && isset($currencies) && is_array($currencies)) {
            $symbolCandidate = $currencies[$globalCurrencyCode] ?? '';
            $currencySymbol = is_string($symbolCandidate) ? $symbolCandidate : '';
        }
        if ($currencySymbol === '' && isset($GLOBALS['currency_format_client']['symbol'])) {
            $clientSymbol = (string) $GLOBALS['currency_format_client']['symbol'];
            if ($clientSymbol !== '') {
                $currencySymbol = $clientSymbol;
            }
        }
        if ($currencySymbol === '') {
            $currencySymbol = $globalCurrencyCode;
        }

        $providerMap = [
            'stripe'      => 'stripe',
            'paypal'      => 'paypal',
            'nowpayments' => 'nowpayments',
            'coinbase'    => 'coinbase',
            'flutterwave' => 'flutterwave',
            'paystack'    => 'paystack',
            'iyzico'      => 'iyzico',
            'payu'        => 'payu',
        ];
        $availableProviders = [];
        $providerCurrencies = [];
        foreach ($providerMap as $alias => $cfgKey) {
            $providerCfg = $cfg[$cfgKey] ?? [];
            if (!empty($providerCfg['enabled'])) {
                if ($alias === 'flutterwave' && empty($providerCfg['secret_key'])) {
                    continue;
                }
                if ($alias === 'paystack' && empty($providerCfg['secret_key'])) {
                    continue;
                }
                if ($alias === 'iyzico' && (empty($providerCfg['api_key']) || empty($providerCfg['secret_key']))) {
                    continue;
                }
                if ($alias === 'payu' && (empty($providerCfg['client_id']) || empty($providerCfg['client_secret']) || empty($providerCfg['signature_key']) || empty($providerCfg['pos_id']))) {
                    continue;
                }
                $availableProviders[] = $alias;
                $providerCurrency = strtoupper((string) ($providerCfg['currency'] ?? $globalCurrencyCode));
                if ($providerCurrency === '') {
                    $providerCurrency = $globalCurrencyCode;
                }
                $providerCurrencies[$alias] = $providerCurrency;
            }
        }

        $providerMinimums = [];
        $providerQuickAmounts = [];
        foreach ($availableProviders as $alias) {
            $providerCurrency = $providerCurrencies[$alias] ?? $globalCurrencyCode;
            if ($providerCurrency === '') {
                $providerCurrency = $globalCurrencyCode;
            }
            $providerMinimums[$alias] = $this->resolveProviderMinimum(
                $limits,
                $alias,
                $providerCurrency
            );
            $precision = $this->resolveProviderPrecision($alias, $providerCurrency);
            $providerQuickAmounts[$alias] = $this->buildProviderQuickAmounts(
                $alias,
                $providerMinimums[$alias],
                (float) ($limits['max'] ?? 0.0),
                $precision
            );
        }

        if (empty($availableProviders)) {
            $baseUrlLocal = isset($base_url) ? (string) $base_url : (isset($GLOBALS['base_url']) ? (string) $GLOBALS['base_url'] : '');
            $baseUrlLocal = $baseUrlLocal !== '' ? rtrim($baseUrlLocal, '/') . '/' : '';
            $manageUrl = $baseUrlLocal . 'admin/index.php?page=settings_payments';

            while (ob_get_level() > 0) { ob_end_clean(); }
            ob_start();

            $dialogId = 'walletTopupProvidersMissing';
            ?>
            <div
                class="popUp-container flex align_items wallet-topup-container"
                data-wallet-topup-missing
                role="dialog"
                aria-modal="true"
                aria-labelledby="<?php echo iN_HelpSecure($dialogId); ?>-title"
            >
                <div class="tips popup-wrapper wallet-topup wallet-topup-missing" id="<?php echo iN_HelpSecure($dialogId); ?>">
                    <button class="tips-close close_pop" type="button" aria-label="<?php echo customLang('close'); ?>">
                        <?php echo render_icon('close', true); ?>
                    </button>
                    <header class="tips-header">
                        <h2 class="tips-title" id="<?php echo iN_HelpSecure($dialogId); ?>-title">
                            <?php echo customLang('wallet_topup_no_provider_title', 'Enable payment providers'); ?>
                        </h2>
                    </header>
                    <div class="wallet-topup-empty-body">
                        <p><?php echo customLang('wallet_topup_no_provider_body', 'Add at least one payment provider in Settings > Payments before enabling wallet top-ups.'); ?></p>
                    </div>
                    <div class="wallet-topup-empty-actions">
                        <a class="upload-btn wallet-topup-manage" href="<?php echo iN_HelpSecure($manageUrl); ?>">
                            <?php echo customLang('wallet_topup_manage_providers', 'Go to payment settings'); ?>
                        </a>
                        <button type="button" class="wallet-topup-cancel close_pop">
                            <?php echo customLang('wallet_topup_not_now', 'Not now'); ?>
                        </button>
                    </div>
                </div>
            </div>
            <?php
            $html = trim(ob_get_clean());

            echo json_encode([
                'status'  => 'error',
                'code'    => 'wallet_topup_no_providers',
                'message' => customLang('wallet_topup_provider_unavailable', 'No payment providers are available right now.'),
                'html'    => $html,
            ]);
            exit;
        }

        $firstProvider = $availableProviders[0] ?? null;
        $quickAmounts = [];
        if ($firstProvider !== null && isset($providerQuickAmounts[$firstProvider])) {
            $quickAmounts = $providerQuickAmounts[$firstProvider];
        }

        $__walletTopupMin = $limits['min'];
        $__walletTopupMax = $limits['max'];
        $__walletTopupQuickAmounts = $quickAmounts;
        $__walletTopupBalance = isset($userWallet) ? (float) $userWallet : 0.0;
        $__walletTopupCurrencyCode = $globalCurrencyCode;
        $__walletTopupCurrencySymbol = $currencySymbol;
        $__walletTopupProviders = $availableProviders;
        $__walletTopupProviderCurrencies = $providerCurrencies;
        $__walletTopupFeePercent = isset($paymentFeePercent) ? (float) $paymentFeePercent : 0.0;
        $__walletTopupProviderMinimums = $providerMinimums;
        $__walletTopupProviderQuickAmounts = $providerQuickAmounts;

        while (ob_get_level() > 0) { ob_end_clean(); }
        ob_start();
        include $filePath;
        $html = trim(ob_get_clean());

        echo json_encode([
            'status' => 'success',
            'html'   => $html
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function handleWalletTopupCreatePayment(array $limits): void
    {
        global $userID, $base_url, $currency, $paymentFeePercent, $paymentFeeFixed, $paymentTaxPercent, $userData;

        $RL = $this->repository;

        if (empty($limits['enabled'])) {
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('wallet_topup_disabled', 'Wallet top-ups are currently unavailable.')
            ]);
            exit;
        }

        $providerRaw = strtolower(trim((string) ($_POST['provider'] ?? '')));
        $allowedProviders = ['stripe', 'paypal', 'nowpayments', 'coinbase', 'flutterwave', 'paystack', 'iyzico', 'payu'];
        if ($providerRaw === '' || $providerRaw === 'wallet' || !in_array($providerRaw, $allowedProviders, true)) {
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('wallet_topup_provider_unavailable', 'No payment providers are available right now.')
            ]);
            exit;
        }

        $config = \PaymentFactory::config();
        $providerKey = $providerRaw === 'coinbase_commerce' ? 'coinbase' : $providerRaw;
        $providerConfig = $config[$providerKey] ?? [];
        $currencyCode = strtoupper((string) ($providerConfig['currency'] ?? ($config['currency'] ?? ($currency ?? 'USD'))));

        $amountPrecision = $this->resolveProviderPrecision($providerRaw, $currencyCode);
        $providerMinimum = $this->resolveProviderMinimum($limits, $providerRaw, $currencyCode);

        $amountInputRaw = trim((string) ($_POST['amount'] ?? ''));
        if ($amountInputRaw === '') {
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('wallet_topup_invalid_amount', 'Please choose an amount within the allowed range.')
            ]);
            exit;
        }
        $normalizedAmountInput = str_replace(',', '.', preg_replace('/[^\d.,]/', '', $amountInputRaw));
        if (!preg_match('/^\d+(?:\.\d{1,' . $amountPrecision . '})?$/', $normalizedAmountInput)) {
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('wallet_topup_invalid_amount', 'Please choose an amount within the allowed range.')
            ]);
            exit;
        }

        $amount = round((float) $normalizedAmountInput, $amountPrecision);
        if ($amount <= 0.0) {
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('wallet_topup_invalid_amount', 'Please choose an amount within the allowed range.')
            ]);
            exit;
        }

        if ($providerMinimum > 0.0 && $amount < $providerMinimum) {
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('wallet_topup_invalid_amount', 'Please choose an amount within the allowed range.')
            ]);
            exit;
        }

        if ($limits['max'] > 0.0 && $amount > $limits['max']) {
            echo json_encode([
                'status'  => 'error',
                'message' => customLang('wallet_topup_invalid_amount', 'Please choose an amount within the allowed range.')
            ]);
            exit;
        }

        $billingData = [
            'first_name' => trim((string) ($_POST['billing_first_name'] ?? '')),
            'last_name'  => trim((string) ($_POST['billing_last_name'] ?? '')),
            'country'    => strtoupper(trim((string) ($_POST['billing_country'] ?? ''))),
            'city'       => trim((string) ($_POST['billing_city'] ?? '')),
            'state'      => trim((string) ($_POST['billing_state'] ?? '')),
            'postcode'   => trim((string) ($_POST['billing_postcode'] ?? '')),
            'address'    => trim((string) ($_POST['billing_address'] ?? '')),
        ];
        if (isset($RL) && method_exists($RL, 'RL_UpdateUserBillingIfEmpty')) {
            $nonEmptyBilling = array_filter($billingData, static fn($v) => $v !== '');
            if (!empty($nonEmptyBilling)) {
                $RL->RL_UpdateUserBillingIfEmpty((int) $userID, $billingData);
            }
        }

        $walletPageUrl = isset($base_url) ? rtrim((string) $base_url, '/') . '/settings?tab=payments' : null;
        if ($walletPageUrl) {
            $GLOBALS['paymentSuccessUrl'] = $walletPageUrl;
            $GLOBALS['paymentCancelUrl'] = $walletPageUrl;
        }

        try {
            if (empty($providerConfig['enabled'])) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => customLang('wallet_topup_provider_unavailable', 'No payment providers are available right now.')
                ]);
                exit;
            }

            $gateway = \PaymentFactory::make($providerRaw);
            $metadata = [
                'type'        => 'wallet_topup',
                'user_id'     => (int) $userID,
                'title'       => (string) customLang('wallet_topup_payment_title', 'Wallet top-up'),
                'description' => (string) customLang('wallet_topup_payment_description', 'Add credits to your wallet.'),
            ];
            $buyerInfo = $this->extractBuyerFields(isset($userData) && is_array($userData) ? $userData : null);
            if ($buyerInfo['email'] !== '') { $metadata['buyer_email'] = $buyerInfo['email']; }
            if ($buyerInfo['name'] !== '') { $metadata['buyer_name'] = $buyerInfo['name']; }
            if ($buyerInfo['username'] !== '') { $metadata['buyer_username'] = $buyerInfo['username']; }
            $this->ensureBuyerEmail($metadata, (int)$userID);
            $walletReference = '';

            if ($providerRaw === 'paypal') {
                try {
                    $walletReference = 'WT' . strtoupper(bin2hex(random_bytes(10)));
                } catch (\Throwable $__) {
                    $walletReference = 'WT' . strtoupper(dechex((int) (microtime(true) * 1000000)));
                }
                if ($walletReference !== '') {
                    $metadata['reference'] = $walletReference;
                }
            }

            if ((
                $providerRaw === 'nowpayments' && $gateway instanceof \NowPaymentsGateway
            ) || (
                $providerRaw === 'coinbase' && $gateway instanceof \CoinbaseGateway
            )) {
                $isNowPayments = $providerRaw === 'nowpayments';
                $payCurrencyEnv = $isNowPayments
                    ? (getenv('NOWPAYMENTS_PAY_CURRENCY') ?: ($_ENV['NOWPAYMENTS_PAY_CURRENCY'] ?? ($_SERVER['NOWPAYMENTS_PAY_CURRENCY'] ?? '')))
                    : (getenv('COINBASE_PAY_CURRENCY') ?: ($_ENV['COINBASE_PAY_CURRENCY'] ?? ($_SERVER['COINBASE_PAY_CURRENCY'] ?? '')));
                $payCurrency = strtoupper((string) ($payCurrencyEnv !== '' ? $payCurrencyEnv : ($providerConfig['pay_currency'] ?? ($providerConfig['currency'] ?? 'BTC'))));
                if ($payCurrency === '') { $payCurrency = 'BTC'; }

                $preferredFiatEnv = $isNowPayments
                    ? (getenv('NOWPAYMENTS_FIAT_CURRENCY') ?: ($_ENV['NOWPAYMENTS_FIAT_CURRENCY'] ?? ($_SERVER['NOWPAYMENTS_FIAT_CURRENCY'] ?? '')))
                    : (getenv('COINBASE_FIAT_CURRENCY') ?: ($_ENV['COINBASE_FIAT_CURRENCY'] ?? ($_SERVER['COINBASE_FIAT_CURRENCY'] ?? '')));
                $preferredFiatEnv = strtoupper((string) $preferredFiatEnv);
                $baseFiat = strtoupper((string) ($config['currency'] ?? ($currency ?? 'USD')));
                $targetCurrency = $preferredFiatEnv !== '' ? $preferredFiatEnv : $baseFiat;
                if ($targetCurrency === '') { $targetCurrency = 'USD'; }
                if ($targetCurrency === $payCurrency) {
                    $targetCurrency = ($preferredFiatEnv !== '' && $preferredFiatEnv !== $payCurrency) ? $preferredFiatEnv : 'USD';
                }

                $originalAmount = $amount;
                $metadata['crypto_amount'] = number_format($originalAmount, 8, '.', '');
                $metadata['crypto_currency'] = $payCurrency;
                $metadata['preferred_fiat'] = $targetCurrency;

                $estimate = null;
                try {
                    $estimate = $gateway->estimateFiatAmount($originalAmount, $payCurrency, $targetCurrency);
                } catch (\Throwable $estimateThrowable) {
                    if (defined('APP_DEBUG') && APP_DEBUG === true && method_exists($RL, 'logError')) {
                        $RL->logError($providerRaw . '_estimate_exception amount=' . $originalAmount . ' pay_currency=' . $payCurrency . ' target=' . $targetCurrency . ' error=' . $estimateThrowable->getMessage());
                    }
                }

                if (is_numeric($estimate) && (float) $estimate > 0.0) {
                    $amount = (float) $estimate;
                    $currencyCode = $targetCurrency;
                    $amountPrecision = $this->resolveProviderPrecision($providerRaw, $currencyCode);
                    if ($amountPrecision < 0) { $amountPrecision = 2; }
                    if ($amountPrecision < 2) { $amountPrecision = 2; }
                    $amount = round($amount, $amountPrecision);
                    $metadata['fiat_currency'] = $currencyCode;
                    $metadata['fiat_amount'] = number_format($amount, max(2, $amountPrecision), '.', '');
                    if ($originalAmount > 0) {
                        $metadata['conversion_rate'] = number_format($amount / $originalAmount, 8, '.', '');
                    }
                } else {
                    $metadata['fiat_currency'] = $targetCurrency;
                    if (defined('APP_DEBUG') && APP_DEBUG === true && method_exists($RL, 'logError')) {
                        $RL->logError($providerRaw . '_estimate_failed amount=' . $originalAmount . ' pay_currency=' . $payCurrency . ' target=' . $targetCurrency);
                    }
                }

                $currencyCode = $currencyCode !== '' ? $currencyCode : $targetCurrency;
            }

            $amountFactor = (int) pow(10, $amountPrecision);
            if ($amountFactor <= 0) { $amountFactor = 100; }

            $response = $gateway->createOneTimePayment($amount, $currencyCode, $metadata);

            $checkoutUrl = (string) ($response['checkout_url'] ?? '');
            if ($checkoutUrl === '') {
                $errorMessage = customLang('wallet_topup_checkout_failed', 'Unable to start top-up checkout. Please try again later.');
                $debugPayload = $response['raw'] ?? null;
                $httpCode = $response['http_code'] ?? null;
                $providerMode = $response['mode'] ?? null;
                if (isset($response['message']) && is_string($response['message']) && $response['message'] !== '') {
                    $errorMessage = $response['message'];
                } elseif (isset($response['error']) && is_string($response['error']) && $response['error'] !== '') {
                    $errorMessage = $response['error'];
                } elseif (isset($response['errors']) && is_array($response['errors'])) {
                    $merged = [];
                    foreach ($response['errors'] as $err) {
                        if (is_string($err) && $err !== '') {
                            $merged[] = $err;
                        } elseif (is_array($err)) {
                            $merged[] = implode(' ', array_filter(array_map('strval', $err)));
                        }
                    }
                    $merged = array_filter($merged);
                    if (!empty($merged)) {
                        $errorMessage = implode(' ', $merged);
                    }
                }
                $payload = [
                    'status'  => 'error',
                    'message' => $errorMessage,
                    'message_text' => $errorMessage,
                ];
                if ($httpCode !== null) {
                    $payload['code'] = $httpCode;
                }
                if ($providerMode !== null) {
                    $payload['provider_mode'] = $providerMode;
                }
                if ($debugPayload !== null) {
                    $payload['debug'] = is_string($debugPayload)
                        ? $debugPayload
                        : json_encode($debugPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                echo json_encode($payload);
                exit;
            }

            $providerReference = (string) ($response['reference'] ?? '');
            $reference = $providerReference;

            if ($providerRaw === 'paypal' && $walletReference !== '') {
                $reference = $walletReference;
                if ($providerReference !== '') {
                    $response['provider_reference'] = $providerReference;
                }
            }

            if ($reference === '') {
                echo json_encode([
                    'status'  => 'error',
                    'message' => customLang('wallet_topup_checkout_failed', 'Unable to start top-up checkout. Please try again later.')
                ]);
                exit;
            }

            $amountMinor = (int) round($amount * $amountFactor);
            if (isset($RL) && method_exists($RL, 'RL_RecordWalletTopup')) {
                $RL->RL_RecordWalletTopup(
                    (int) $userID,
                    $providerRaw,
                    $reference,
                    $amountMinor,
                    $currencyCode,
                    'pending',
                    'checkout_created',
                    (array) $response
                );
            }

            echo json_encode([
                'status'       => 'success',
                'checkout_url' => $checkoutUrl,
                'reference'    => $reference,
                'provider'     => $providerRaw,
                'currency'     => $currencyCode,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if ($message === '') {
                $message = customLang('wallet_topup_checkout_failed', 'Unable to start top-up checkout. Please try again later.');
            }
            echo json_encode([
                'status'  => 'error',
                'message' => $message,
                'message_text' => $message,
            ]);
        }
        exit;
    }

    public function handleWalletBalanceGet(): void
    {
        global $RL, $userID, $currency, $currencys, $currencies, $currency_format_client;

        $RL = $this->repository;

        if (!isset($RL) || !method_exists($RL, 'RL_GetUserDetails')) {
            echo json_encode([
                'status' => 'error',
                'message' => customLang('db_not_available', 'Database connection not available.')
            ]);
            exit;
        }
        $details = $RL->RL_GetUserDetails((int) $userID) ?: [];
        $wallet = isset($details['wallet']) ? (float) $details['wallet'] : 0.0;
        $currencyCode = isset($currency) ? strtoupper((string) $currency) : 'USD';
        $currencySymbol = '';

        // Prefer combined fiat/crypto map, then legacy map, then client format fallback.
        if (isset($currencies) && is_array($currencies) && isset($currencies[$currencyCode])) {
            $candidate = $currencies[$currencyCode];
            $currencySymbol = is_string($candidate) ? trim((string) $candidate) : '';
        }
        if ($currencySymbol === '' && isset($currencys) && is_array($currencys) && isset($currencys[$currencyCode])) {
            $candidate = $currencys[$currencyCode];
            $currencySymbol = is_string($candidate) ? trim((string) $candidate) : '';
        }
        if ($currencySymbol === '' && isset($currency_format_client['symbols'][$currencyCode])) {
            $candidate = $currency_format_client['symbols'][$currencyCode];
            $currencySymbol = is_string($candidate) ? trim((string) $candidate) : '';
        }
        if ($currencySymbol === '' && isset($currency_format_client['symbol'])) {
            $candidate = $currency_format_client['symbol'];
            $currencySymbol = is_string($candidate) ? trim((string) $candidate) : '';
            if ($currencySymbol === $currencyCode) {
                $currencySymbol = '';
            }
        }
        echo json_encode([
            'status'   => 'success',
            'balance'  => $wallet,
            'currency' => $currencyCode,
            'symbol'   => $currencySymbol,
        ]);
        exit;
    }

    public function handleTipsCreatePayment(): void
    {
        global $userID, $RL, $currency, $base_url, $paymentFeePercent, $paymentFeeFixed, $paymentTaxPercent;

        $RL = $this->repository;

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!function_exists('checkCsrfToken') || !checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }

        $provider     = strtolower(trim((string)($_POST['provider'] ?? '')));
        $postId       = (int)($_POST['post_id'] ?? 0);
        $recipientId  = (int)($_POST['recipient_id'] ?? 0);
        $liveId       = (int)($_POST['live_id'] ?? 0);
        $amountInt    = (int)($_POST['amount'] ?? 0);
        $minAmount    = 1; $maxAmount = 500;
        $allowedTipProviders = ['stripe','paypal','nowpayments','coinbase','flutterwave','paystack','iyzico','payu','wallet'];
        if ($provider === '' || !in_array($provider, $allowedTipProviders, true) || $recipientId <= 0 || $amountInt < $minAmount || $amountInt > $maxAmount) {
            echo json_encode(['status'=>'error','message'=>customLang('error_invalid_parameters')]);
            exit;
        }
        if (isset($userID) && (int)$userID === $recipientId) {
            echo json_encode([
                'status'  => 'error',
                'code'    => 'SELF_TIP_NOT_ALLOWED',
                'message' => customLang('pay_tip_self_not_allowed') ?: 'You cannot send a tip to yourself.'
            ]);
            exit;
        }
        if (method_exists($RL, 'RL_HasPendingSubscription') && $RL->RL_HasPendingSubscription((int)$userID, $recipientId)) {
            echo json_encode(['status'=>'error','message'=>customLang('pending_subscription_exists')]);
            exit;
        }

        $bf = [
            'first_name' => trim((string)($_POST['billing_first_name'] ?? '')),
            'last_name'  => trim((string)($_POST['billing_last_name'] ?? '')),
            'country'    => strtoupper(trim((string)($_POST['billing_country'] ?? ''))),
            'city'       => trim((string)($_POST['billing_city'] ?? '')),
            'state'      => trim((string)($_POST['billing_state'] ?? '')),
            'postcode'   => trim((string)($_POST['billing_postcode'] ?? '')),
            'address'    => trim((string)($_POST['billing_address'] ?? '')),
        ];
        $need = array_filter($bf, fn($v) => $v !== '');
        if (empty($need)) {
            echo json_encode(['status'=>'error','message'=>customLang('billing_details_required')]);
            exit;
        }
        if (isset($RL) && method_exists($RL, 'RL_UpdateUserBillingIfEmpty')) {
            $RL->RL_UpdateUserBillingIfEmpty((int)$userID, $bf);
        }

        $cfg = \PaymentFactory::config();
        $providerKey = $provider === 'coinbase_commerce' ? 'coinbase' : $provider;
        $providerCfg = $cfg[$providerKey] ?? [];
        $currencyCode = isset($providerCfg['currency']) && $providerCfg['currency'] !== ''
            ? (string)$providerCfg['currency']
            : ($cfg['currency'] ?? ($currency ?? 'USD'));
        $amount = (float)$amountInt;

        try {
            $ownerUsername = '';
            $ownerDisplay = '';
            if (isset($RL) && $recipientId > 0) {
                $u = $RL->RL_GetUserDetails($recipientId);
                $ownerUsername = is_array($u) ? (string)($u['username'] ?? '') : '';
                $ownerDisplay = is_array($u) ? trim((string)($u['user_fullname'] ?? $ownerUsername)) : '';
                if ($ownerUsername !== '' && isset($base_url)) {
                    if ($liveId > 0) {
                        $GLOBALS['paymentSuccessUrl'] = rtrim((string)$base_url, '/') . '/live/' . $liveId;
                    } elseif ($postId > 0) {
                        $postUrl = rtrim((string)$base_url, '/') . '/posts/' . $postId . '/' . $ownerUsername;
                        $GLOBALS['paymentSuccessUrl'] = $postUrl;
                    } else {
                        $profileUrl = rtrim((string)$base_url, '/') . '/profile/' . $ownerUsername;
                        $GLOBALS['paymentSuccessUrl'] = $profileUrl;
                    }
                }
            }
            if ($provider === 'wallet') {
                if (!isset($RL) || !method_exists($RL, 'RL_TransferWalletForTip')) {
                    echo json_encode(['status'=>'error','message'=>customLang('wallet_tips_not_supported')]);
                    exit;
                }
                $res = $RL->RL_TransferWalletForTip(
                    (int)$userID,
                    $recipientId,
                    $postId,
                    $amount,
                    (string)$currencyCode,
                    isset($paymentFeePercent) ? (float)$paymentFeePercent : 0.0,
                    isset($paymentFeeFixed)   ? (float)$paymentFeeFixed   : 0.0,
                    isset($paymentTaxPercent) ? (float)$paymentTaxPercent : 0.0
                );
                if (!($res['ok'] ?? false)) {
                    $msg = $res['error'] ?? customLang('wallet_transfer_failed');
                    if (isset($res['balance'])) { $msg .= ' Balance: ' . number_format((float)$res['balance'], 2); }
                    echo json_encode(['status'=>'error','message'=>$msg]);
                    exit;
                }
                echo json_encode(['status'=>'success','provider'=>'wallet','reference'=>($res['reference'] ?? ''),'new_balance'=>($res['new_balance'] ?? null)]);
                exit;
            }

            $feePercentVal = isset($paymentFeePercent) ? max(0.0, (float)$paymentFeePercent) : 0.0;
            $feeFixedVal   = isset($paymentFeeFixed) ? max(0.0, (float)$paymentFeeFixed) : 0.0;
            $taxPercentVal = isset($paymentTaxPercent) ? max(0.0, (float)$paymentTaxPercent) : 0.0;

            $feeAmount = round(($amount * ($feePercentVal / 100.0)) + $feeFixedVal, 2);
            $taxAmount = round($amount * ($taxPercentVal / 100.0), 2);
            if ($feeAmount < 0) { $feeAmount = 0.0; }
            if ($taxAmount < 0) { $taxAmount = 0.0; }
            $totalCharge = round($amount + $feeAmount + $taxAmount, 2);
            if ($totalCharge <= 0.0) {
                $totalCharge = round($amount, 2);
            }

            $gw = \PaymentFactory::make($provider);
            $meta = [
                'type'         => 'tip',
                'post_id'      => $postId,
                'recipient_id' => $recipientId,
                'buyer_id'     => (int)$userID,
                'title'        => (string) customLang('tip_payment_title', 'Tip payment'),
                'tip_subtotal' => $amount,
                'tip_fee'      => $feeAmount,
                'tip_tax'      => $taxAmount,
                'tip_total'    => $totalCharge,
                'tip_currency' => (string)$currencyCode,
                'tip_fee_percent' => $feePercentVal,
                'tip_fee_fixed'   => $feeFixedVal,
                'tip_tax_percent' => $taxPercentVal,
            ];
            $buyerInfo = $this->extractBuyerFields(isset($userData) && is_array($userData) ? $userData : null);
            if ($buyerInfo['email'] !== '') { $meta['buyer_email'] = $buyerInfo['email']; }
            if ($buyerInfo['name'] !== '') { $meta['buyer_name'] = $buyerInfo['name']; }
            if ($buyerInfo['username'] !== '') { $meta['buyer_username'] = $buyerInfo['username']; }
            $this->ensureBuyerEmail($meta, (int)$userID);
            $recipientLabel = $ownerDisplay !== '' ? $ownerDisplay : ($ownerUsername !== '' ? '@' . $ownerUsername : '');
            $tipDescriptionTemplate = (string) customLang('tip_payment_description', 'Tip for {recipient}');
            if ($recipientLabel !== '') {
                $meta['description'] = strtr($tipDescriptionTemplate, ['{recipient}' => $recipientLabel]);
            } else {
                $meta['description'] = $tipDescriptionTemplate;
            }
            $resp = $gw->createOneTimePayment($totalCharge, (string)$currencyCode, $meta);
            $checkout = (string)($resp['checkout_url'] ?? '');
            if ($checkout === '') {
                $msg = !empty($resp['error']) ? (customLang('payment_error_prefix') . ' '.$resp['error']) : customLang('provider_no_checkout_url');
                echo json_encode(['status'=>'error','message'=>$msg]);
                exit;
            }
            $reference = (string)($resp['reference'] ?? '');
            if ($reference !== '' && isset($RL) && method_exists($RL, 'RL_RecordTipPayment')) {
                $RL->RL_RecordTipPayment((int)$userID, $recipientId, $postId, $provider, $reference, $totalCharge, (string)$currencyCode, 'pending', 'checkout_created', (array)$resp);
                if (method_exists($RL, 'RL_UpdateTipPaymentAmountsByReference')) {
                    $grossVal = $totalCharge;
                    $feeVal = $feeAmount > 0 ? $feeAmount : 0.0;
                    $taxVal = $taxAmount > 0 ? $taxAmount : 0.0;
                    $RL->RL_UpdateTipPaymentAmountsByReference($provider, $reference, $grossVal, (string)$currencyCode, $feeVal, (string)$currencyCode, $taxVal, $amount);
                }
            }
            echo json_encode([
                'status'        => 'success',
                'checkout_url'  => $checkout,
                'reference'     => $reference,
                'provider'      => $provider,
                'total_amount'  => $totalCharge,
                'fee_amount'    => $feeAmount,
                'tax_amount'    => $taxAmount,
                'currency'      => (string)$currencyCode,
                'subtotal'      => $amount,
            ]);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>customLang('payment_init_failed').' '.$e->getMessage()]);
        }
        exit;
    }

    public function handleSendTip(): void
    {
        global $RL, $currentTheme, $iconPath, $base_url, $userID, $userData, $userWallet;
        global $enableStripe, $enablePaypal, $enableNowpayments, $enableCoinbase, $enableFlutterwave, $enablePaystack, $enableIyziCo, $enablePayu;
        global $globalCurCode, $stripeCurCode, $paypalCurCode, $nowpayCurCode, $coinbaseCurCode, $flutterwaveCurCode, $paystackCurCode, $iyzicoCurCode, $payuCurCode;

        $RL = $this->repository;
        $baseDir = dirname(__DIR__, 2);

        try {
            $tipPostID = (int) ($_POST['post_id'] ?? null);
            if ($tipPostID <= 0) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => customLang('error_invalid_parameters', 'Invalid parameters.'),
                    'code'    => 'INVALID_PARAMETERS',
                ]);
                exit;
            }
                $postData = $RL->RL_GetPostData($tipPostID);

                if ($postData) {
                    $postOwnerID = $postData['post_owner_id'] ?? null;
                    if (isset($userID) && (int)$userID === (int)$postOwnerID) {
                        echo json_encode([
                            'status'  => 'error',
                            'message' => customLang('pay_tip_self_not_allowed') ?: 'You cannot send a tip to yourself.',
                            'code'    => 'SELF_TIP_NOT_ALLOWED',
                        ]);
                        exit;
                    }
                    $userDetails = $RL->RL_GetUserDetails($postOwnerID);
                    $filePath = $baseDir . '/themes/' . $currentTheme . '/popUps/sendTips.php';
                    if (!is_file($filePath)) {
                        echo json_encode([
                            'status'  => 'error',
                            'message' => customLang('requested_content_not_found', 'Requested content not found.'),
                            'code'    => 'NOT_FOUND',
                        ]);
                        exit;
                    }

                    // Resolve enabled payment providers and their operating currencies for the tips modal.
                    if (defined('APP_DEBUG') && APP_DEBUG === true) {
                        error_log('[subscribe_popup] initial toggles: ' . json_encode([
                            'stripe' => $enableStripe,
                            'paypal' => $enablePaypal,
                            'nowpayments' => $enableNowpayments,
                            'coinbase' => $enableCoinbase,
                            'flutterwave' => $enableFlutterwave,
                            'paystack' => $enablePaystack,
                            'payu' => $enablePayu,
                        ]));
                    }

                    $enableStripe = !empty($enableStripe);
                    $enablePaypal = !empty($enablePaypal);
                    $enableNowpayments = !empty($enableNowpayments);
                    $enableCoinbase = !empty($enableCoinbase);
                    $enableFlutterwave = !empty($enableFlutterwave);
                    $enablePaystack = !empty($enablePaystack);
                    $enableIyziCo = !empty($enableIyziCo);
                    $enablePayu = !empty($enablePayu);
                    $globalCurCode = isset($globalCurCode) && $globalCurCode !== '' ? (string) $globalCurCode : 'USD';
                    $stripeCurCode = isset($stripeCurCode) && $stripeCurCode !== '' ? (string) $stripeCurCode : $globalCurCode;
                    $paypalCurCode = isset($paypalCurCode) && $paypalCurCode !== '' ? (string) $paypalCurCode : $globalCurCode;
                    $nowpayCurCode = isset($nowpayCurCode) && $nowpayCurCode !== '' ? (string) $nowpayCurCode : $globalCurCode;
                    $coinbaseCurCode = isset($coinbaseCurCode) && $coinbaseCurCode !== '' ? (string) $coinbaseCurCode : $globalCurCode;
                    $flutterwaveCurCode = isset($flutterwaveCurCode) && $flutterwaveCurCode !== '' ? (string) $flutterwaveCurCode : $globalCurCode;
                    $paystackCurCode = isset($paystackCurCode) && $paystackCurCode !== '' ? (string) $paystackCurCode : $globalCurCode;
                    $iyzicoCurCode = isset($iyzicoCurCode) && $iyzicoCurCode !== '' ? (string) $iyzicoCurCode : $globalCurCode;
                    $payuCurCode = isset($payuCurCode) && $payuCurCode !== '' ? (string) $payuCurCode : $globalCurCode;
                    try {
                        $tipsConfig = \PaymentFactory::config();
                    } catch (Throwable $__) {
                        $tipsConfig = [];
                    }
                    if (!empty($tipsConfig['currency'])) {
                        $globalCurCode = strtoupper((string) $tipsConfig['currency']);
                    } elseif (isset($GLOBALS['currency']) && is_string($GLOBALS['currency']) && $GLOBALS['currency'] !== '') {
                        $globalCurCode = strtoupper((string) $GLOBALS['currency']);
                    }

                    if (defined('APP_DEBUG') && APP_DEBUG === true) {
                        error_log('[subscribe_popup] resolved config: ' . json_encode($tipsConfig));
                    }

                    $stripeCfg = $tipsConfig['stripe'] ?? [];
                    if (!empty($stripeCfg['enabled']) && !empty($stripeCfg['secret_key'])) {
                        $enableStripe = true;
                        $stripeCurCode = strtoupper((string) ($stripeCfg['currency'] ?? $globalCurCode));
                    }

                    $paypalCfg = $tipsConfig['paypal'] ?? [];
                    if (!empty($paypalCfg['enabled']) && !empty($paypalCfg['client_id']) && !empty($paypalCfg['client_secret'])) {
                        $enablePaypal = true;
                        $paypalCurCode = strtoupper((string) ($paypalCfg['currency'] ?? $globalCurCode));
                    }

                    $nowCfg = $tipsConfig['nowpayments'] ?? [];
                    if (!empty($nowCfg['enabled']) && !empty($nowCfg['api_key'])) {
                        $enableNowpayments = true;
                        $nowpayCurCode = strtoupper((string) ($nowCfg['currency'] ?? $globalCurCode));
                    }

                    $coinbaseCfg = $tipsConfig['coinbase'] ?? [];
                    if (!empty($coinbaseCfg['enabled']) && !empty($coinbaseCfg['api_key'])) {
                        $enableCoinbase = true;
                        $coinbaseCurCode = strtoupper((string) ($coinbaseCfg['currency'] ?? $globalCurCode));
                    }
                    $flutterwaveCfg = $tipsConfig['flutterwave'] ?? [];
                    if (!empty($flutterwaveCfg['enabled']) && !empty($flutterwaveCfg['secret_key'])) {
                        $enableFlutterwave = true;
                        $flutterwaveCurCode = strtoupper((string) ($flutterwaveCfg['currency'] ?? $globalCurCode));
                    }
                    $paystackCfg = $tipsConfig['paystack'] ?? [];
                    if (!empty($paystackCfg['enabled']) && !empty($paystackCfg['secret_key'])) {
                        $enablePaystack = true;
                        $paystackCurCode = strtoupper((string) ($paystackCfg['currency'] ?? $globalCurCode));
                    }
                    $iyzicoCfg = $tipsConfig['iyzico'] ?? [];
                    if (!empty($iyzicoCfg['enabled']) && !empty($iyzicoCfg['api_key']) && !empty($iyzicoCfg['secret_key'])) {
                        $enableIyziCo = true;
                        $iyzicoCurCode = strtoupper((string) ($iyzicoCfg['currency'] ?? $globalCurCode));
                    }
                    $payuCfg = $tipsConfig['payu'] ?? [];
                    if (!empty($payuCfg['enabled']) && !empty($payuCfg['client_id']) && !empty($payuCfg['client_secret']) && !empty($payuCfg['signature_key']) && !empty($payuCfg['pos_id'])) {
                        $enablePayu = true;
                        $payuCurCode = strtoupper((string) ($payuCfg['currency'] ?? $globalCurCode));
                    }

                    $tipFeePercentUi = isset($paymentFeePercent) ? (float) $paymentFeePercent : 0.0;
                    $tipFeeFixedUi   = isset($paymentFeeFixed) ? (float) $paymentFeeFixed : 0.0;
                    $tipTaxPercentUi = isset($paymentTaxPercent) ? (float) $paymentTaxPercent : 0.0;
                    if (($tipFeePercentUi <= 0.0 && $tipFeeFixedUi <= 0.0) || $tipTaxPercentUi <= 0.0) {
                        try {
                            if (isset($RL) && method_exists($RL, 'RL_configs')) {
                                $siteCfg = $RL->RL_configs();
                                if ($tipFeePercentUi <= 0.0 && !empty($siteCfg['payment_fee_percent'])) {
                                    $tipFeePercentUi = (float) $siteCfg['payment_fee_percent'];
                                }
                                if ($tipFeeFixedUi <= 0.0 && !empty($siteCfg['payment_fee_fixed'])) {
                                    $tipFeeFixedUi = (float) $siteCfg['payment_fee_fixed'];
                                }
                                if ($tipTaxPercentUi <= 0.0 && !empty($siteCfg['payment_tax_percent'])) {
                                    $tipTaxPercentUi = (float) $siteCfg['payment_tax_percent'];
                                }
                            }
                        } catch (Throwable $__) {
                            // leave defaults
                        }
                    }
                    $paymentFeePercent = $tipFeePercentUi;
                    $paymentFeeFixed   = $tipFeeFixedUi;
                    $paymentTaxPercent = $tipTaxPercentUi;

                    // Ensure buyer context (wallet + profile) is available for the modal.
                    if (!isset($userWallet) || !is_numeric($userWallet) || (float)$userWallet <= 0.0 || !is_array($userData ?? null) || empty($userData)) {
                        try {
                            $viewerDetails = [];
                            if (isset($RL) && method_exists($RL, 'RL_GetUserDetails') && isset($userID) && (int)$userID > 0) {
                                $viewerDetails = $RL->RL_GetUserDetails((int) $userID) ?: [];
                            }
                            if (!empty($viewerDetails)) {
                                $userWallet = isset($viewerDetails['wallet']) ? (float) $viewerDetails['wallet'] : ($userWallet ?? 0.0);
                                if (!isset($userData) || !is_array($userData) || empty($userData)) {
                                    $userData = $viewerDetails;
                                }
                            }
                        } catch (Throwable $__) {
                            // Swallow – modal will fallback to existing globals.
                        }
                    }

                    if (!isset($iconPath) && isset($base_url)) {
                        $iconPath = rtrim($base_url, '/') . '/themes/' . $currentTheme . '/img/';
                    }

                    while (ob_get_level() > 0) { ob_end_clean(); }

                ob_start();
                include $filePath;
                $html = trim(ob_get_clean());

                echo json_encode([
                    'status' => 'success',
                    'html'   => $html,
                    'post_id'=> $postOwnerID
                ], JSON_UNESCAPED_UNICODE);
                exit;
            } else {
                echo json_encode([
                    'status'  => 'error',
                    'message' => customLang('error_invalid_parameters', 'Invalid parameters.'),
                    'code'    => 'INVALID_PARAMETERS',
                ]);
                exit;
            }
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>customLang('server_error')]);
            exit;
        }
    }

    public function handleSendTipUser(): void
    {
        global $RL, $currentTheme, $iconPath, $base_url;
        global $enableStripe, $enablePaypal, $enableNowpayments, $enableCoinbase, $enableFlutterwave, $enablePaystack, $enableIyziCo, $enablePayu;
        global $globalCurCode, $stripeCurCode, $paypalCurCode, $nowpayCurCode, $coinbaseCurCode, $flutterwaveCurCode, $paystackCurCode, $iyzicoCurCode, $payuCurCode;
        global $userID, $userData, $userWallet, $currencies, $currency;
        global $paymentFeePercent, $paymentFeeFixed, $paymentTaxPercent;
        global $billignDataFirstName, $billignDataLastName, $billignDataCountry, $billignDataCity, $billignDataState, $billignDataPostCode, $billignDataAddress;

        $RL = $this->repository;
        $baseDir = dirname(__DIR__, 2);

        try {
            $recipientId = (int)($_POST['recipient_id'] ?? 0);
            $liveId      = (int)($_POST['live_id'] ?? 0);
            if ($recipientId <= 0) {
                echo json_encode(['status'=>'error','message'=>customLang('error_invalid_parameters','Invalid parameters.')]);
                exit;
            }
            if (isset($userID) && (int)$userID === $recipientId) {
                echo json_encode([
                    'status'  => 'error',
                    'code'    => 'SELF_TIP_NOT_ALLOWED',
                    'message' => customLang('pay_tip_self_not_allowed') ?: 'You cannot send a tip to yourself.'
                ]);
                exit;
            }
            $userDetails = $RL->RL_GetUserDetails($recipientId);
            if (!$userDetails || !is_array($userDetails)) {
                echo json_encode(['status'=>'error','message'=>'Recipient not found.']);
                exit;
            }
            $postOwnerID = $recipientId;
            $liveContextId = $liveId;
            if (!isset($iconPath) && isset($base_url)) {
                $iconPath = rtrim($base_url, '/') . '/themes/' . $currentTheme . '/img/';
            }

            // Ensure viewer billing/wallet globals exist so the template can pre-fill fields.
            if (!isset($currencies) || !is_array($currencies)) {
                $currencies = (isset($currencys) && is_array($currencys)) ? $currencys : [];
            }

            $billingFirst = isset($billignDataFirstName) ? (string) $billignDataFirstName : '';
            $billingLast  = isset($billignDataLastName) ? (string) $billignDataLastName : '';
            $billingCountry = isset($billignDataCountry) ? (string) $billignDataCountry : '';
            $billingCity    = isset($billignDataCity) ? (string) $billignDataCity : '';
            $billingState   = isset($billignDataState) ? (string) $billignDataState : '';
            $billingPost    = isset($billignDataPostCode) ? (string) $billignDataPostCode : '';
            $billingAddress = isset($billignDataAddress) ? (string) $billignDataAddress : '';

            if (isset($userID) && (int) $userID > 0 && isset($RL) && method_exists($RL, 'RL_GetUserDetails')) {
                try {
                    $viewerDetails = $RL->RL_GetUserDetails((int) $userID) ?: [];
                    if (!empty($viewerDetails)) {
                        if (!isset($userData) || !is_array($userData) || empty($userData)) {
                            $userData = $viewerDetails;
                        }
                        if (!isset($userWallet) || !is_numeric($userWallet)) {
                            $userWallet = isset($viewerDetails['wallet']) ? (float) $viewerDetails['wallet'] : 0.0;
                        }
                        if ($billingFirst === '' && !empty($viewerDetails['for_billing_first_name'])) {
                            $billingFirst = (string) $viewerDetails['for_billing_first_name'];
                        }
                        if ($billingLast === '' && !empty($viewerDetails['for_billing_last_name'])) {
                            $billingLast = (string) $viewerDetails['for_billing_last_name'];
                        }
                        if ($billingCountry === '' && !empty($viewerDetails['for_billing_country'])) {
                            $billingCountry = strtoupper((string) $viewerDetails['for_billing_country']);
                        }
                        if ($billingCity === '' && !empty($viewerDetails['for_billing_city'])) {
                            $billingCity = (string) $viewerDetails['for_billing_city'];
                        }
                        if ($billingState === '' && !empty($viewerDetails['for_billing_state'])) {
                            $billingState = (string) $viewerDetails['for_billing_state'];
                        }
                        if ($billingPost === '' && !empty($viewerDetails['for_billing_postcode'])) {
                            $billingPost = (string) $viewerDetails['for_billing_postcode'];
                        }
                        if ($billingAddress === '' && !empty($viewerDetails['for_billing_address'])) {
                            $billingAddress = (string) $viewerDetails['for_billing_address'];
                        }
                    }
                } catch (Throwable $__) {
                    // Ignore prefill errors; popup will simply render empty fields.
                }
            }

            if ($billingFirst === '' && isset($userData) && is_array($userData) && !empty($userData['user_fullname'])) {
                $parts = preg_split('~\s+~', trim((string) $userData['user_fullname']), 2);
                if (!empty($parts[0])) {
                    $billingFirst = (string) $parts[0];
                }
                if ($billingLast === '' && !empty($parts[1])) {
                    $billingLast = (string) $parts[1];
                }
            }

            $billignDataFirstName = $billingFirst;
            $billignDataLastName  = $billingLast;
            $billignDataCountry   = $billingCountry;
            $billignDataCity      = $billingCity;
            $billignDataState     = $billingState;
            $billignDataPostCode  = $billingPost;
            $billignDataAddress   = $billingAddress;

            $filePath = $baseDir . '/themes/' . $currentTheme . '/popUps/sendTips.php';
            if (!is_file($filePath)) {
                echo json_encode(['status'=>'error','message'=>'Tips template not found.']);
                exit;
            }
            while (ob_get_level() > 0) { ob_end_clean(); }
            ob_start();
            include $filePath;
            $html = trim(ob_get_clean());
            echo json_encode(['status'=>'success','html'=>$html]);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>customLang('server_error')]);
        }
        exit;
    }

    public function handlePaymentStatus(): void
    {
        global $RL;

        $RL = $this->repository;

        $provider = strtolower(trim((string)($_POST['provider'] ?? '')));
        $reference= (string)($_POST['reference'] ?? '');
        $type     = strtolower(trim((string)($_POST['type'] ?? 'purchase')));
        if ($provider === '' || $reference === '') {
            echo json_encode(['status'=>'error','message'=>customLang('missing_provider_or_reference')]);
            exit;
        }
        $row = null;
        if ($type === 'tip' && method_exists($RL, 'RL_GetTipPaymentByReference')) {
            $row = $RL->RL_GetTipPaymentByReference($provider, $reference);
        } elseif ($type === 'purchase' && method_exists($RL, 'RL_GetPostPurchaseByReference')) {
            $row = $RL->RL_GetPostPurchaseByReference($provider, $reference);
        }
        if (!$row) {
            echo json_encode(['status'=>'success','payment_status'=>'unknown']);
            exit;
        }
        echo json_encode([
            'status' => 'success',
            'payment_status' => (string)($row['status'] ?? 'unknown'),
        ]);
        exit;
    }

    public function handlePurchaseCreatePayment(): void
    {
        global $userID, $RL, $currency, $base_url, $paymentFeePercent, $paymentFeeFixed, $paymentTaxPercent, $userData;

        $RL = $this->repository;

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!function_exists('checkCsrfToken') || !checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }

        $provider     = strtolower(trim((string)($_POST['provider'] ?? '')));
        $postId       = (int)($_POST['post_id'] ?? 0);
        $recipientId  = (int)($_POST['recipient_id'] ?? 0);
        $amountInt    = (int)($_POST['amount'] ?? 0);
        $allowedPurchaseProviders = ['stripe','paypal','nowpayments','coinbase','flutterwave','paystack','iyzico','payu','wallet'];
        if ($provider === '' || !in_array($provider, $allowedPurchaseProviders, true) || $postId <= 0 || $recipientId <= 0 || $amountInt <= 0) {
            echo json_encode(['status'=>'error','message'=>customLang('error_invalid_parameters')]);
            exit;
        }
        if (method_exists($RL, 'hasPurchasedPost') && $RL->hasPurchasedPost((int)$userID, $postId)) {
            echo json_encode(['status'=>'error','message'=>customLang('already_purchased_post')]);
            exit;
        }
        if (method_exists($RL, 'RL_HasPendingPostPurchase') && $RL->RL_HasPendingPostPurchase((int)$userID, $postId)) {
            echo json_encode(['status'=>'error','message'=>customLang('pending_purchase_exists')]);
            exit;
        }
        $postRow = $RL->RL_GetPostData($postId);
        $postPriceRaw = (string)($postRow['post_price'] ?? '0');
        if ($postPriceRaw === '' || !is_numeric($postPriceRaw)) {
            $postPriceRaw = '0';
        }
        $baseAmount = round((float)$postPriceRaw, 2);
        if ($baseAmount <= 0) { echo json_encode(['status'=>'error','message'=>customLang('post_not_purchasable')]); exit; }

        if (method_exists($RL, 'RL_HasPendingSubscription') && $RL->RL_HasPendingSubscription((int)$userID, $recipientId)) {
            echo json_encode(['status'=>'error','message'=>customLang('pending_subscription_exists')]);
            exit;
        }

        $bf = [
            'first_name' => trim((string)($_POST['billing_first_name'] ?? '')),
            'last_name'  => trim((string)($_POST['billing_last_name'] ?? '')),
            'country'    => strtoupper(trim((string)($_POST['billing_country'] ?? ''))),
            'city'       => trim((string)($_POST['billing_city'] ?? '')),
            'state'      => trim((string)($_POST['billing_state'] ?? '')),
            'postcode'   => trim((string)($_POST['billing_postcode'] ?? '')),
            'address'    => trim((string)($_POST['billing_address'] ?? '')),
        ];
        if (empty(array_filter($bf, fn($v)=>$v!==''))) {
            echo json_encode(['status'=>'error','message'=>customLang('billing_details_required')]);
            exit;
        }
        if (isset($RL) && method_exists($RL, 'RL_UpdateUserBillingIfEmpty')) { $RL->RL_UpdateUserBillingIfEmpty((int)$userID, $bf); }

        $postUrl = null;
        $ownerUsername = '';
        if (isset($RL) && $recipientId > 0) {
            $u = $RL->RL_GetUserDetails($recipientId);
            $ownerUsername = is_array($u) ? (string)($u['username'] ?? '') : '';
            if ($ownerUsername !== '' && isset($base_url)) {
                $postUrl = rtrim((string)$base_url, '/') . '/posts/' . $postId . '/' . $ownerUsername;
                $GLOBALS['paymentSuccessUrl'] = $postUrl;
            }
        }

        $cfg = \PaymentFactory::config();
        $providerKey = $provider === 'coinbase_commerce' ? 'coinbase' : $provider;
        $providerCfg = $cfg[$providerKey] ?? [];
        $currencyCode = isset($providerCfg['currency']) && $providerCfg['currency'] !== ''
            ? (string)$providerCfg['currency']
            : ($cfg['currency'] ?? ($currency ?? 'USD'));
        $feePercent    = isset($paymentFeePercent) ? (float)$paymentFeePercent : 0.0;
        $feeFixed      = isset($paymentFeeFixed) ? (float)$paymentFeeFixed : 0.0;
        $taxPercent    = isset($paymentTaxPercent) ? (float)$paymentTaxPercent : 0.0;
        $feeAmount     = round(($baseAmount * ($feePercent / 100.0)) + $feeFixed, 2);
        $taxAmount     = round($baseAmount * ($taxPercent / 100.0), 2);
        $grossAmount   = round($baseAmount + $feeAmount + $taxAmount, 2);
        $netAmount     = $baseAmount;

        try {
            if ($provider === 'wallet') {
                if (!isset($RL) || !method_exists($RL, 'RL_TransferWalletForPurchase')) { echo json_encode(['status'=>'error','message'=>customLang('wallet_purchase_not_supported')]); exit; }
                $res = $RL->RL_TransferWalletForPurchase(
                    (int)$userID,
                    $recipientId,
                    $postId,
                    $netAmount,
                    (string)$currencyCode,
                    $feePercent,
                    $feeFixed,
                    $taxPercent
                );
                if (!($res['ok'] ?? false)) {
                    $msg = $res['error'] ?? customLang('wallet_purchase_failed');
                    if (isset($res['balance'])) { $msg .= ' Balance: ' . number_format((float)$res['balance'], 2); }
                    echo json_encode(['status'=>'error','message'=>$msg]);
                    exit;
                }
                echo json_encode([
                    'status'=>'success',
                    'provider'=>'wallet',
                    'reference'=>($res['reference'] ?? ''),
                    'new_balance'=>($res['new_balance'] ?? null),
                    'redirect_url' => $postUrl
                ]);
                exit;
            }
            $gw = \PaymentFactory::make($provider);
            $productLabel = customLang('pay_checkout_product_label', 'Premium post purchase');
            if (!is_string($productLabel) || $productLabel === '' || $productLabel === 'pay_checkout_product_label') {
                $productLabel = 'Premium post purchase';
            }
            $postTitle = $productLabel;
            $sourceTitle = '';
            if (is_array($postRow)) {
                $sourceTitle = trim((string)($postRow['post_title'] ?? ''));
                if ($sourceTitle === '') {
                    $sourceTitle = mb_substr(trim((string)($postRow['post_text'] ?? '')), 0, 60);
                }
            }
            $ownerHandle = $ownerUsername !== '' ? '@' . $ownerUsername : 'creator';
            $meta = [
                'type'         => 'purchase',
                'post_id'      => $postId,
                'recipient_id' => $recipientId,
                'buyer_id'     => (int)$userID,
                'title'        => $postTitle,
                'description'  => sprintf('Unlock premium post #%d by %s', $postId, $ownerHandle),
                'gross_amount' => number_format($grossAmount, 2, '.', ''),
                'net_amount'   => number_format($netAmount, 2, '.', ''),
                'fee_amount'   => number_format($feeAmount, 2, '.', ''),
                'tax_amount'   => number_format($taxAmount, 2, '.', ''),
                'source_title' => $sourceTitle,
            ];
            $buyerInfo = $this->extractBuyerFields(isset($userData) && is_array($userData) ? $userData : null);
            if ($buyerInfo['email'] !== '') { $meta['buyer_email'] = $buyerInfo['email']; }
            if ($buyerInfo['name'] !== '') { $meta['buyer_name'] = $buyerInfo['name']; }
            if ($buyerInfo['username'] !== '') { $meta['buyer_username'] = $buyerInfo['username']; }
            $this->ensureBuyerEmail($meta, (int)$userID);
            $cryptoCurrency = '';
            if (in_array($provider, ['nowpayments', 'coinbase'], true)) {
                $isNowPayments = $provider === 'nowpayments';
                $payCurrencyEnv = $isNowPayments
                    ? (getenv('NOWPAYMENTS_PAY_CURRENCY') ?: ($_ENV['NOWPAYMENTS_PAY_CURRENCY'] ?? ($_SERVER['NOWPAYMENTS_PAY_CURRENCY'] ?? '')))
                    : (getenv('COINBASE_PAY_CURRENCY') ?: ($_ENV['COINBASE_PAY_CURRENCY'] ?? ($_SERVER['COINBASE_PAY_CURRENCY'] ?? '')));
                $cryptoCurrency = strtoupper((string) $payCurrencyEnv);
                if ($cryptoCurrency !== '' && $cryptoCurrency !== strtoupper((string) $currencyCode)) {
                    $meta['crypto_currency'] = $cryptoCurrency;
                }
                $preferredFiatEnv = $isNowPayments
                    ? (getenv('NOWPAYMENTS_FIAT_CURRENCY') ?: ($_ENV['NOWPAYMENTS_FIAT_CURRENCY'] ?? ($_SERVER['NOWPAYMENTS_FIAT_CURRENCY'] ?? '')))
                    : (getenv('COINBASE_FIAT_CURRENCY') ?: ($_ENV['COINBASE_FIAT_CURRENCY'] ?? ($_SERVER['COINBASE_FIAT_CURRENCY'] ?? '')));
                $preferredFiatEnv = strtoupper((string) $preferredFiatEnv);
                if ($preferredFiatEnv !== '' && $preferredFiatEnv !== strtoupper((string) $currencyCode)) {
                    $meta['preferred_fiat'] = $preferredFiatEnv;
                } else {
                    $meta['preferred_fiat'] = strtoupper((string) $currencyCode);
                }
            } else {
                $cryptoCurrency = '';
            }

            if ($cryptoCurrency === '' && in_array($provider, ['nowpayments', 'coinbase'], true)) {
                $cryptoCurrency = 'BTC';
            }

            if ($cryptoCurrency !== '' && method_exists($gw, 'estimateFiatAmount')) {
                try {
                    $fiatPerUnit = $gw->estimateFiatAmount(1.0, $cryptoCurrency, strtoupper((string) $currencyCode));
                    if (is_numeric($fiatPerUnit) && (float) $fiatPerUnit > 0) {
                        $estimatedCrypto = $grossAmount / (float) $fiatPerUnit;
                        $minCrypto = 0.005;
                        if ($estimatedCrypto < $minCrypto) {
                            $minMessage = customLang('crypto_minimum_amount', 'Selected crypto provider requires a minimum charge of 0.005 {currency}. Please choose another provider or increase the price.');
                            if (strpos($minMessage, '{currency}') !== false) {
                                $minMessage = strtr($minMessage, ['{currency}' => $cryptoCurrency]);
                            }
                            echo json_encode(['status' => 'error', 'message' => $minMessage]);
                            exit;
                        }
                    }
                } catch (Throwable $__) {
                    if (defined('APP_DEBUG') && APP_DEBUG === true && isset($RL) && method_exists($RL, 'logError')) {
                        $RL->logError($provider . '_estimate_crypto_failed amount=' . $grossAmount . ' fiat=' . $currencyCode . ' target=' . $cryptoCurrency . ' error=' . $__->getMessage());
                    }
                }
            }
            $resp = $gw->createOneTimePayment($grossAmount, (string)$currencyCode, $meta);
            $checkout = (string)($resp['checkout_url'] ?? '');
            if ($checkout === '') {
                $primaryError = '';
                if (isset($resp['message']) && is_string($resp['message'])) {
                    $primaryError = $resp['message'];
                } elseif (isset($resp['error']) && is_string($resp['error'])) {
                    $primaryError = $resp['error'];
                } elseif (isset($resp['raw']['message']) && is_string($resp['raw']['message'])) {
                    $primaryError = $resp['raw']['message'];
                }
                $lowerError = strtolower($primaryError);
                $isCryptoMinError = strpos($lowerError, 'less than minimal') !== false
                    || strpos($lowerError, 'minimum amount') !== false
                    || strpos($lowerError, 'low crypto amount') !== false;
                $hasFallbackCurrency = isset($meta['crypto_currency']) && strtoupper((string)$meta['crypto_currency']) === 'USDT';
                if ($provider === 'nowpayments' && $isCryptoMinError && !$hasFallbackCurrency) {
                    $fallbackMeta = $meta;
                    $fallbackMeta['crypto_currency'] = 'USDT';
                    if (!isset($fallbackMeta['preferred_fiat']) || $fallbackMeta['preferred_fiat'] === '') {
                        $fallbackMeta['preferred_fiat'] = strtoupper((string) $currencyCode);
                    }
                    $fallbackResp = $gw->createOneTimePayment($grossAmount, (string)$currencyCode, $fallbackMeta);
                    if (!empty($fallbackResp['checkout_url'])) {
                        $resp = $fallbackResp;
                        $checkout = (string) $fallbackResp['checkout_url'];
                    } else {
                        // Merge fallback error details to aid debugging
                        if (!isset($resp['fallback'])) {
                            $resp['fallback'] = $fallbackResp;
                        }
                    }
                }
            }
            $checkout = (string)($resp['checkout_url'] ?? '');
            if ($checkout === '') { $msg = !empty($resp['error'])?(customLang('payment_error_prefix').' '.$resp['error']):customLang('provider_no_checkout_url'); echo json_encode(['status'=>'error','message'=>$msg]); exit; }
            $reference = (string)($resp['reference'] ?? '');
            if ($reference !== '' && isset($RL) && method_exists($RL, 'RL_RecordPostPurchase')) {
                $RL->RL_RecordPostPurchase((int)$userID, $recipientId, $postId, $provider, $reference, $grossAmount, (string)$currencyCode, 'pending', 'checkout_created', (array)$resp);
                if (method_exists($RL, 'RL_UpdatePostPurchaseAmountsByReference')) {
                    $RL->RL_UpdatePostPurchaseAmountsByReference(
                        $provider,
                        $reference,
                        $grossAmount,
                        (string)$currencyCode,
                        $feeAmount,
                        (string)$currencyCode,
                        $taxAmount,
                        $netAmount
                    );
                }
            }
            echo json_encode(['status'=>'success','checkout_url'=>$checkout,'reference'=>$reference,'provider'=>$provider]);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>customLang('payment_init_failed').' '.$e->getMessage()]);
        }
        exit;
    }

    public function handleAudioRoomTicketCreatePayment(): void
    {
        global $userID, $RL, $currency, $base_url, $paymentFeePercent, $paymentFeeFixed, $paymentTaxPercent, $userData;

        $RL = $this->repository;

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!function_exists('checkCsrfToken') || !checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }

        $buyerId = isset($userID) ? (int) $userID : 0;
        $provider = strtolower(trim((string)($_POST['provider'] ?? '')));
        $roomId = (int)($_POST['room_id'] ?? 0);
        $allowedProviders = ['stripe','paypal','nowpayments','coinbase','flutterwave','paystack','iyzico','payu','wallet'];
        if ($buyerId <= 0 || $roomId <= 0 || $provider === '' || !in_array($provider, $allowedProviders, true)) {
            echo json_encode(['status' => 'error', 'message' => customLang('error_invalid_parameters', 'Invalid parameters.')]);
            exit;
        }
        if (!isset($RL) || !method_exists($RL, 'RL_GetAudioRoomById') || !method_exists($RL, 'RL_RecordAudioRoomTicket')) {
            echo json_encode(['status' => 'error', 'message' => customLang('server_error', 'Server error.')]);
            exit;
        }

        $room = $RL->RL_GetAudioRoomById($roomId);
        if (!$room) {
            echo json_encode(['status' => 'error', 'message' => customLang('audio_room_not_found', 'Audio room not found.')]);
            exit;
        }

        $ownerId = (int)($room['owner_id'] ?? 0);
        $isPaid = (int)($room['is_paid'] ?? 0) === 1;
        $status = strtolower((string)($room['status'] ?? 'created'));
        if (!$isPaid || $ownerId <= 0 || $ownerId === $buyerId) {
            echo json_encode(['status' => 'success', 'redirect_url' => rtrim((string)$base_url, '/') . '/audio-room/' . $roomId]);
            exit;
        }
        if (in_array($status, ['ended', 'cancelled'], true)) {
            echo json_encode(['status' => 'error', 'message' => customLang('audio_room_ended', 'This room has ended.')]);
            exit;
        }
        if (method_exists($RL, 'RL_UserCanViewAudioRoom') && !$RL->RL_UserCanViewAudioRoom($buyerId, $room)) {
            echo json_encode(['status' => 'error', 'message' => customLang('audio_room_audience_restricted', 'This room is not available for your account.')]);
            exit;
        }
        if (method_exists($RL, 'RL_UserHasAudioRoomTicket') && $RL->RL_UserHasAudioRoomTicket($roomId, $buyerId)) {
            echo json_encode(['status' => 'success', 'redirect_url' => rtrim((string)$base_url, '/') . '/audio-room/' . $roomId]);
            exit;
        }

        $baseAmount = round((float)($room['entry_price'] ?? 0), 2);
        if ($baseAmount <= 0) {
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_audio_room_price', 'Invalid audio room price.')]);
            exit;
        }

        $cfg = \PaymentFactory::config();
        $providerKey = $provider === 'coinbase_commerce' ? 'coinbase' : $provider;
        $providerCfg = $cfg[$providerKey] ?? [];
        $currencyCode = isset($providerCfg['currency']) && $providerCfg['currency'] !== ''
            ? strtoupper((string)$providerCfg['currency'])
            : strtoupper((string)($room['currency'] ?? ($cfg['currency'] ?? ($currency ?? 'USD'))));
        if ($currencyCode === '') { $currencyCode = 'USD'; }

        $feePercent = isset($paymentFeePercent) ? max(0.0, (float)$paymentFeePercent) : 0.0;
        $feeFixed = isset($paymentFeeFixed) ? max(0.0, (float)$paymentFeeFixed) : 0.0;
        $taxPercent = isset($paymentTaxPercent) ? max(0.0, (float)$paymentTaxPercent) : 0.0;
        $feeAmount = round(($baseAmount * ($feePercent / 100.0)) + $feeFixed, 2);
        $taxAmount = round($baseAmount * ($taxPercent / 100.0), 2);
        $grossAmount = round($baseAmount + $feeAmount + $taxAmount, 2);
        $netAmount = $baseAmount;
        $roomUrl = rtrim((string)$base_url, '/') . '/audio-room/' . $roomId;
        $GLOBALS['paymentSuccessUrl'] = $roomUrl;
        $GLOBALS['paymentCancelUrl'] = $roomUrl;

        try {
            if ($provider === 'wallet') {
                if (!method_exists($RL, 'RL_TransferWalletForAudioRoomTicket')) {
                    echo json_encode(['status' => 'error', 'message' => customLang('wallet_purchase_not_supported', 'Wallet purchase is not supported.')]);
                    exit;
                }
                $res = $RL->RL_TransferWalletForAudioRoomTicket($buyerId, $roomId, $grossAmount, $netAmount, $feeAmount, $taxAmount, $currencyCode);
                if (empty($res['ok'])) {
                    $msg = (string)($res['error'] ?? customLang('wallet_purchase_failed', 'Wallet purchase failed.'));
                    if (isset($res['balance'])) { $msg .= ' Balance: ' . number_format((float)$res['balance'], 2); }
                    echo json_encode(['status' => 'error', 'message' => $msg]);
                    exit;
                }
                echo json_encode([
                    'status'       => 'success',
                    'provider'     => 'wallet',
                    'reference'    => (string)($res['reference'] ?? ''),
                    'new_balance'  => $res['new_balance'] ?? null,
                    'redirect_url' => $roomUrl,
                ]);
                exit;
            }

            if (empty($providerCfg['enabled'])) {
                echo json_encode(['status' => 'error', 'message' => customLang('wallet_topup_provider_unavailable', 'No payment providers are available right now.')]);
                exit;
            }

            $gateway = \PaymentFactory::make($provider);
            $roomTitle = trim((string)($room['title'] ?? ''));
            if ($roomTitle === '') { $roomTitle = customLang('audio_room_untitled', 'Audio room'); }
            $ownerUsername = (string)($room['username'] ?? '');
            $ownerLabel = $ownerUsername !== '' ? '@' . $ownerUsername : 'creator';
            $meta = [
                'type'          => 'audio_room_ticket',
                'room_id'       => $roomId,
                'recipient_id'  => $ownerId,
                'owner_id'      => $ownerId,
                'buyer_id'      => $buyerId,
                'title'         => (string) customLang('audio_room_ticket_payment_title', 'Audio room ticket'),
                'description'   => sprintf('Ticket for %s by %s', $roomTitle, $ownerLabel),
                'gross_amount'  => number_format($grossAmount, 2, '.', ''),
                'net_amount'    => number_format($netAmount, 2, '.', ''),
                'fee_amount'    => number_format($feeAmount, 2, '.', ''),
                'tax_amount'    => number_format($taxAmount, 2, '.', ''),
                'source_title'  => $roomTitle,
            ];
            $buyerInfo = $this->extractBuyerFields(isset($userData) && is_array($userData) ? $userData : null);
            if ($buyerInfo['email'] !== '') { $meta['buyer_email'] = $buyerInfo['email']; }
            if ($buyerInfo['name'] !== '') { $meta['buyer_name'] = $buyerInfo['name']; }
            if ($buyerInfo['username'] !== '') { $meta['buyer_username'] = $buyerInfo['username']; }
            $this->ensureBuyerEmail($meta, $buyerId);

            $resp = $gateway->createOneTimePayment($grossAmount, $currencyCode, $meta);
            $checkout = (string)($resp['checkout_url'] ?? '');
            if ($checkout === '') {
                $msg = !empty($resp['error']) ? (customLang('payment_error_prefix', 'Payment error:') . ' ' . $resp['error']) : customLang('provider_no_checkout_url', 'Provider did not return a checkout URL.');
                echo json_encode(['status' => 'error', 'message' => $msg]);
                exit;
            }
            $reference = (string)($resp['reference'] ?? '');
            if ($reference !== '') {
                $RL->RL_RecordAudioRoomTicket($roomId, $buyerId, $ownerId, $provider, $reference, $grossAmount, $currencyCode, 'pending', 'checkout_created', (array)$resp);
                if (method_exists($RL, 'RL_UpdateAudioRoomTicketAmountsByReference')) {
                    $RL->RL_UpdateAudioRoomTicketAmountsByReference($provider, $reference, $grossAmount, $currencyCode, $feeAmount, $currencyCode, $taxAmount, $netAmount);
                }
            }
            echo json_encode([
                'status'       => 'success',
                'checkout_url' => $checkout,
                'reference'    => $reference,
                'provider'     => $provider,
                'redirect_url' => $roomUrl,
            ]);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => customLang('payment_init_failed', 'Payment init failed.') . ' ' . $e->getMessage()]);
        }
        exit;
    }

    public function handleAudioRoomTipCreatePayment(): void
    {
        global $userID, $RL, $currency, $base_url, $paymentFeePercent, $paymentFeeFixed, $paymentTaxPercent, $userData;

        $RL = $this->repository;

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!function_exists('checkCsrfToken') || !checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }

        $buyerId = isset($userID) ? (int)$userID : 0;
        $roomId = (int)($_POST['room_id'] ?? 0);
        $provider = strtolower(trim((string)($_POST['provider'] ?? 'wallet')));
        $amountRaw = trim((string)($_POST['amount'] ?? ''));
        $amountRaw = str_replace(',', '.', preg_replace('/[^\d.,]/', '', $amountRaw));
        $amount = is_numeric($amountRaw) ? round((float)$amountRaw, 2) : 0.0;
        $allowedProviders = ['stripe','paypal','nowpayments','coinbase','flutterwave','paystack','iyzico','payu','wallet'];
        if ($buyerId <= 0 || $roomId <= 0 || $amount < 1 || $amount > 500 || !in_array($provider, $allowedProviders, true)) {
            echo json_encode(['status'=>'error','message'=>customLang('error_invalid_parameters', 'Invalid parameters.')]);
            exit;
        }
        if (!isset($RL) || !method_exists($RL, 'RL_GetAudioRoomById') || !method_exists($RL, 'RL_UserCanJoinAudioRoom')) {
            echo json_encode(['status'=>'error','message'=>customLang('server_error', 'Server error.')]);
            exit;
        }
        $access = $RL->RL_UserCanJoinAudioRoom($buyerId, $roomId);
        if (empty($access['ok'])) {
            echo json_encode(['status'=>'error','message'=>(string)($access['reason'] ?? 'not_allowed')]);
            exit;
        }
        $room = (array)($access['room'] ?? []);
        $recipientId = (int)($room['owner_id'] ?? 0);
        if ($recipientId <= 0 || $recipientId === $buyerId) {
            echo json_encode(['status'=>'error','message'=>customLang('pay_tip_self_not_allowed', 'You cannot send a tip to yourself.')]);
            exit;
        }

        $cfg = \PaymentFactory::config();
        $providerKey = $provider === 'coinbase_commerce' ? 'coinbase' : $provider;
        $providerCfg = $cfg[$providerKey] ?? [];
        $currencyCode = isset($providerCfg['currency']) && $providerCfg['currency'] !== ''
            ? strtoupper((string)$providerCfg['currency'])
            : strtoupper((string)($cfg['currency'] ?? ($currency ?? 'USD')));
        if ($currencyCode === '') { $currencyCode = 'USD'; }
        $feePercent = isset($paymentFeePercent) ? max(0.0, (float)$paymentFeePercent) : 0.0;
        $feeFixed = isset($paymentFeeFixed) ? max(0.0, (float)$paymentFeeFixed) : 0.0;
        $taxPercent = isset($paymentTaxPercent) ? max(0.0, (float)$paymentTaxPercent) : 0.0;
        $feeAmount = round(($amount * ($feePercent / 100.0)) + $feeFixed, 2);
        $taxAmount = round($amount * ($taxPercent / 100.0), 2);
        $grossAmount = round($amount + $feeAmount + $taxAmount, 2);
        $roomUrl = rtrim((string)$base_url, '/') . '/audio-room/' . $roomId;
        $GLOBALS['paymentSuccessUrl'] = $roomUrl;
        $GLOBALS['paymentCancelUrl'] = $roomUrl;

        $buyerName = trim((string)($userData['user_fullname'] ?? $userData['username'] ?? ''));
        if ($buyerName === '') { $buyerName = 'User #' . $buyerId; }

        try {
            if ($provider === 'wallet') {
                if (!method_exists($RL, 'RL_TransferWalletForTip')) {
                    echo json_encode(['status'=>'error','message'=>customLang('wallet_tips_not_supported', 'Wallet tips are not supported.')]);
                    exit;
                }
                $res = $RL->RL_TransferWalletForTip($buyerId, $recipientId, 0, $amount, $currencyCode, $feePercent, $feeFixed, $taxPercent);
                if (empty($res['ok'])) {
                    $msg = (string)($res['error'] ?? customLang('wallet_transfer_failed', 'Wallet transfer failed.'));
                    if (isset($res['balance'])) { $msg .= ' Balance: ' . number_format((float)$res['balance'], 2); }
                    echo json_encode(['status'=>'error','message'=>$msg]);
                    exit;
                }
                if (method_exists($RL, 'RL_InsertAudioRoomTipEvent')) {
                    $reference = (string)($res['reference'] ?? '');
                    $paymentRow = $reference !== '' && method_exists($RL, 'RL_GetTipPaymentByReference')
                        ? (array)($RL->RL_GetTipPaymentByReference('wallet', $reference) ?? [])
                        : [];
                    $RL->RL_InsertAudioRoomTipEvent($roomId, $buyerId, $buyerName, $amount, $currencyCode, [
                        'recipient_id' => $recipientId,
                        'provider' => 'wallet',
                        'reference' => $reference,
                        'fee_amount' => $paymentRow['fee_amount'] ?? $feeAmount,
                        'tax_amount' => $paymentRow['tax_amount'] ?? $taxAmount,
                        'net_amount' => $paymentRow['net_amount'] ?? $amount,
                        'status' => $paymentRow['status'] ?? 'succeeded',
                        'credited_at' => $paymentRow['credited_at'] ?? time(),
                        'created_at' => $paymentRow['created_at'] ?? time(),
                    ]);
                }
                if (method_exists($RL, 'RL_InsertAudioRoomMessage')) {
                    $RL->RL_InsertAudioRoomMessage($roomId, $buyerId, '', 'tip', ['buyer'=>$buyerName, 'amount'=>$amount, 'currency'=>$currencyCode]);
                }
                echo json_encode([
                    'status'=>'success',
                    'provider'=>'wallet',
                    'reference'=>(string)($res['reference'] ?? ''),
                    'new_balance'=>$res['new_balance'] ?? null,
                    'amount'=>$amount,
                    'currency'=>$currencyCode,
                    'buyer'=>$buyerName,
                ]);
                exit;
            }

            if (empty($providerCfg['enabled'])) {
                echo json_encode(['status'=>'error','message'=>customLang('wallet_topup_provider_unavailable', 'No payment providers are available right now.')]);
                exit;
            }

            $gateway = \PaymentFactory::make($provider);
            $roomTitle = trim((string)($room['title'] ?? ''));
            if ($roomTitle === '') { $roomTitle = customLang('audio_room_untitled', 'Audio room'); }
            $meta = [
                'type' => 'audio_room_tip',
                'room_id' => $roomId,
                'post_id' => 0,
                'recipient_id' => $recipientId,
                'buyer_id' => $buyerId,
                'title' => (string)customLang('audio_room_tip_payment_title', 'Audio room tip'),
                'description' => sprintf('Tip for %s', $roomTitle),
                'gross_amount' => number_format($grossAmount, 2, '.', ''),
                'net_amount' => number_format($amount, 2, '.', ''),
                'fee_amount' => number_format($feeAmount, 2, '.', ''),
                'tax_amount' => number_format($taxAmount, 2, '.', ''),
                'buyer_name' => $buyerName,
            ];
            $buyerInfo = $this->extractBuyerFields(isset($userData) && is_array($userData) ? $userData : null);
            if ($buyerInfo['email'] !== '') { $meta['buyer_email'] = $buyerInfo['email']; }
            if ($buyerInfo['name'] !== '') { $meta['buyer_name'] = $buyerInfo['name']; }
            if ($buyerInfo['username'] !== '') { $meta['buyer_username'] = $buyerInfo['username']; }
            $this->ensureBuyerEmail($meta, $buyerId);
            $resp = $gateway->createOneTimePayment($grossAmount, $currencyCode, $meta);
            $checkout = (string)($resp['checkout_url'] ?? '');
            if ($checkout === '') {
                $msg = !empty($resp['error']) ? (customLang('payment_error_prefix', 'Payment error:') . ' ' . $resp['error']) : customLang('provider_no_checkout_url', 'Provider did not return a checkout URL.');
                echo json_encode(['status'=>'error','message'=>$msg]);
                exit;
            }
            $reference = (string)($resp['reference'] ?? '');
            if ($reference !== '' && method_exists($RL, 'RL_RecordTipPayment')) {
                $RL->RL_RecordTipPayment($buyerId, $recipientId, 0, $provider, $reference, $grossAmount, $currencyCode, 'pending', 'checkout_created', (array)$resp);
                if (method_exists($RL, 'RL_UpdateTipPaymentAmountsByReference')) {
                    $RL->RL_UpdateTipPaymentAmountsByReference($provider, $reference, $grossAmount, $currencyCode, $feeAmount, $currencyCode, $taxAmount, $amount);
                }
            }
            echo json_encode(['status'=>'success','checkout_url'=>$checkout,'reference'=>$reference,'provider'=>$provider]);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>customLang('payment_init_failed', 'Payment init failed.') . ' ' . $e->getMessage()]);
        }
        exit;
    }

    public function handleSubscriptionCreatePayment(): void
    {
        global $userID, $RL, $currency, $base_url, $subscriptionfee, $paymentFeeFixed, $paymentTaxPercent, $paymentFeePercent, $userData;

        $RL = $this->repository;

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!function_exists('checkCsrfToken') || !checkCsrfToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => customLang('invalid_csrf_token')]);
            exit;
        }

        $provider     = strtolower(trim((string)($_POST['provider'] ?? '')));
        $recipientId  = (int)($_POST['recipient_id'] ?? 0);
        $interval     = strtolower(trim((string)($_POST['plan_interval'] ?? '')));
        $amountFloat  = (float)($_POST['amount'] ?? 0);
        $allowedSubProviders = ['stripe','paypal','flutterwave','paystack','iyzico','payu','wallet'];
        if (!in_array($provider, $allowedSubProviders, true)) {
            echo json_encode(['status' => 'error', 'message' => customLang('wallet_topup_provider_unavailable', 'No payment providers are available right now.')]);
            exit;
        }
        if ($provider === '' || $recipientId <= 0 || $interval === '' || $amountFloat <= 0) {
            echo json_encode(['status'=>'error','message'=>customLang('error_invalid_parameters')]);
            exit;
        }
        if (method_exists($RL, 'RL_HasPendingSubscription') && $RL->RL_HasPendingSubscription((int)$userID, $recipientId)) {
            echo json_encode(['status'=>'error','message'=>customLang('pending_subscription_exists')]);
            exit;
        }

        $bf = [
            'first_name' => trim((string)($_POST['billing_first_name'] ?? '')),
            'last_name'  => trim((string)($_POST['billing_last_name'] ?? '')),
            'country'    => strtoupper(trim((string)($_POST['billing_country'] ?? ''))),
            'city'       => trim((string)($_POST['billing_city'] ?? '')),
            'state'      => trim((string)($_POST['billing_state'] ?? '')),
            'postcode'   => trim((string)($_POST['billing_postcode'] ?? '')),
            'address'    => trim((string)($_POST['billing_address'] ?? '')),
        ];
        if (empty(array_filter($bf, fn($v)=>$v!==''))) {
            echo json_encode(['status'=>'error','message'=>customLang('billing_details_required')]);
            exit;
        }
        if (isset($RL) && method_exists($RL, 'RL_UpdateUserBillingIfEmpty')) { $RL->RL_UpdateUserBillingIfEmpty((int)$userID, $bf); }

        $plans = method_exists($RL, 'RL_GetUserSubscriptionPlans') ? $RL->RL_GetUserSubscriptionPlans($recipientId) : [];
        $map = ['weekly'=>'price_weekly','monthly'=>'price_monthly','halfyear'=>'price_halfyear','yearly'=>'price_yearly'];
        $col = $map[$interval] ?? null;
        $serverPrice = $col && isset($plans[$col]) ? (float)$plans[$col] : 0.0;
        if ($serverPrice <= 0) { echo json_encode(['status'=>'error','message'=>customLang('selected_plan_unavailable')]); exit; }
        $intervalCount = 1;

        $profileUrl = null;
        if (isset($base_url)) {
            $creator = isset($RL) && method_exists($RL, 'RL_GetUserDetails') ? $RL->RL_GetUserDetails($recipientId) : null;
            $uname = is_array($creator) ? ($creator['username'] ?? '') : '';
            if (!empty($uname)) {
                $profileUrl = rtrim((string)$base_url, '/') . '/profile/' . $uname;
                $GLOBALS['paymentSuccessUrl'] = $profileUrl;
            }
        }

        $cfg = \PaymentFactory::config();
        $providerKey = $provider === 'coinbase_commerce' ? 'coinbase' : $provider;
        $providerCfg = $cfg[$providerKey] ?? [];
        $currencyCode = isset($providerCfg['currency']) && $providerCfg['currency'] !== ''
            ? (string)$providerCfg['currency']
            : ($cfg['currency'] ?? ($currency ?? 'USD'));
        if ($provider === 'iyzico') {
            $apiKeyOk = !empty($providerCfg['api_key']);
            $secretOk = !empty($providerCfg['secret_key']);
            if (!$apiKeyOk || !$secretOk) {
                echo json_encode(['status' => 'error', 'message' => customLang('wallet_topup_provider_unavailable', 'No payment providers are available right now.')]);
                exit;
            }
        }
        $subFeePct = isset($subscriptionfee) ? (float)$subscriptionfee : 0.0;
        $feeFixed  = isset($paymentFeeFixed) ? (float)$paymentFeeFixed : 0.0;
        $taxPct    = isset($paymentTaxPercent) ? (float)$paymentTaxPercent : 0.0;
        $platformFee   = round($serverPrice * ($subFeePct/100.0), 2);
        $tax           = round($feeFixed + ($serverPrice * ($taxPct/100.0)), 2);
        $totalPerCycle = round($serverPrice + $platformFee + $tax, 2);
        $amount = (float)$totalPerCycle;
        try {
            if ($provider === 'wallet') {
                if (!isset($RL) || !method_exists($RL, 'RL_TransferWalletForTip')) { echo json_encode(['status'=>'error','message'=>'Wallet subscription not supported.']); exit; }
                $res = $RL->RL_TransferWalletForTip((int)$userID, $recipientId, 0, $serverPrice, (string)$currencyCode,
                    isset($paymentFeePercent)?(float)$paymentFeePercent:0.0,
                    isset($paymentFeeFixed)?(float)$paymentFeeFixed:0.0,
                    isset($paymentTaxPercent)?(float)$paymentTaxPercent:0.0);
                if (!($res['ok'] ?? false)) {
                    $msg = $res['error'] ?? 'Wallet payment failed.';
                    if (isset($res['balance'])) { $msg .= ' Balance: ' . number_format((float)$res['balance'], 2); }
                    echo json_encode(['status'=>'error','message'=>$msg]);
                    exit;
                }
                if (method_exists($RL, 'RL_SetAsSubscriber')) { $RL->RL_SetAsSubscriber((int)$userID, $recipientId, time()); }
                $ref = (string)($res['reference'] ?? ('SUBWALLET-' . (int)$userID . '-' . (int)$recipientId . '-' . time()));
                if (method_exists($RL, 'RL_RecordSubscriptionPayment')) {
                    $RL->RL_RecordSubscriptionPayment((int)$userID, $recipientId, $interval, $intervalCount, 'wallet', $ref, (float)$amount, (string)$currencyCode, 'succeeded', 'wallet_debited', ['source' => 'wallet']);
                }
                try {
                    $startedAt = time();
                    $count = (int)$intervalCount > 0 ? (int)$intervalCount : 1;
                    $next = new DateTime('@' . $startedAt); $next->setTimezone(new DateTimeZone('UTC'));
                    $ival = strtolower((string)$interval);
                    if ($ival === 'weekly' || $ival === 'week') { $next->modify('+' . $count . ' week'); }
                    elseif ($ival === 'yearly' || $ival === 'year') { $next->modify('+' . $count . ' year'); }
                    elseif ($ival === 'halfyear') { $next->modify('+' . (6 * $count) . ' month'); }
                    else { $next->modify('+' . $count . ' month'); }
                    $cpe = $next->getTimestamp();
                    if (method_exists($RL, 'RL_UpdateSubscriptionPeriodByReference')) {
                        $RL->RL_UpdateSubscriptionPeriodByReference('wallet', $ref, (int)$startedAt, (int)$cpe);
                    }
                } catch (Throwable $__) {}
                echo json_encode(['status'=>'success','provider'=>'wallet','reference'=>($res['reference'] ?? ''),'redirect_url'=>$profileUrl]);
                exit;
            }
            $gw = \PaymentFactory::make($provider);
            $meta = ['type'=>'subscription','recipient_id'=>$recipientId,'buyer_id'=>(int)$userID,'interval'=>$interval,'interval_count'=>$intervalCount];
            $buyerInfo = $this->extractBuyerFields(isset($userData) && is_array($userData) ? $userData : null);
            if ($buyerInfo['email'] !== '') { $meta['buyer_email'] = $buyerInfo['email']; }
            if ($buyerInfo['name'] !== '') { $meta['buyer_name'] = $buyerInfo['name']; }
            if ($buyerInfo['username'] !== '') { $meta['buyer_username'] = $buyerInfo['username']; }
            $this->ensureBuyerEmail($meta, (int)$userID);
            if (method_exists($gw, 'createSubscription')) {
                $resp = $gw->createSubscription('Creator Subscription', $amount, (string)$currencyCode, $interval === 'halfyear' ? 'month' : $interval, $interval === 'halfyear' ? 6 : $intervalCount, $meta);
            } else {
                $resp = $gw->createOneTimePayment($amount, (string)$currencyCode, $meta);
            }
            $checkout = (string)($resp['checkout_url'] ?? '');
            if ($checkout === '') { $msg = !empty($resp['error'])?(customLang('payment_error_prefix').' '.$resp['error']):customLang('provider_no_checkout_url'); echo json_encode(['status'=>'error','message'=>$msg]); exit; }
            $reference = (string)($resp['reference'] ?? '');
            if ($reference !== '' && method_exists($RL, 'RL_RecordSubscriptionPayment')) {
                $RL->RL_RecordSubscriptionPayment((int)$userID, $recipientId, $interval, $intervalCount, $provider, $reference, $amount, (string)$currencyCode, 'pending', 'checkout_created', (array)$resp);
                if ($provider === 'paypal' && method_exists($RL, 'RL_UpdateSubscriptionProviderObjectByReference')) {
                    $RL->RL_UpdateSubscriptionProviderObjectByReference('paypal', $reference, $reference);
                }
            }
            echo json_encode(['status'=>'success','checkout_url'=>$checkout,'reference'=>$reference,'provider'=>$provider]);
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>customLang('payment_init_failed').' '.$e->getMessage()]);
        }
        exit;
    }

    public function handleSubscriptionUpdatePayment(): void
    {
        global $userID, $base_url, $RL;

        $RL = $this->repository;

        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!isset($userID) || (int) $userID <= 0) {
                echo json_encode(['status' => 'error', 'message' => customLang('please_login_first', 'Please login first.')]);
                exit;
            }

            $buyerId = (int) $userID;
            $subscriptionIdUpdate = (int) ($_POST['subscription_id'] ?? 0);
            $creatorIdUpdate = (int) ($_POST['creator_id'] ?? 0);
            $db = (isset($RL) && method_exists($RL, 'getDb')) ? $RL->getDb() : null;

            if ($subscriptionIdUpdate > 0 && $db instanceof PDO) {
                $st = $db->prepare('SELECT id, buyer_id, recipient_id FROM i_subscription_payments WHERE id = :id LIMIT 1');
                $st->execute([':id' => $subscriptionIdUpdate]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$row || (int) ($row['buyer_id'] ?? 0) !== $buyerId) {
                    echo json_encode(['status' => 'error', 'message' => customLang('error_invalid_parameters', 'Invalid parameters.')]);
                    exit;
                }
                $creatorIdUpdate = (int) ($row['recipient_id'] ?? 0);
            }

            if ($creatorIdUpdate <= 0 && !($subscriptionIdUpdate > 0)) {
                echo json_encode(['status' => 'error', 'message' => customLang('error_invalid_parameters', 'Invalid parameters.')]);
                exit;
            }

            $redirectBase = isset($base_url) ? rtrim((string) $base_url, '/') : '';
            $redirectUrl = ($redirectBase !== '' ? $redirectBase : '') . '/settings?tab=payments';
            $extraParams = [];
            if ($subscriptionIdUpdate > 0) { $extraParams['subscription_id'] = $subscriptionIdUpdate; }
            if ($creatorIdUpdate > 0) { $extraParams['creator_id'] = $creatorIdUpdate; }
            if ($extraParams) {
                $redirectUrl .= '&' . http_build_query(array_merge(['focus' => 'subscriptions'], $extraParams));
            }

            $message = customLang('settings_subscription_update_redirect', 'Redirecting you to payment settings to update your billing method.');
            echo json_encode(['status' => 'success', 'redirect_url' => $redirectUrl, 'message' => $message]);
            exit;
        } catch (Throwable $e) {
            if (isset($RL) && method_exists($RL, 'logError')) {
                $RL->logError('subscription_update_payment failed: ' . $e->getMessage());
            }
            echo json_encode(['status' => 'error', 'message' => customLang('error_server', 'A server error occurred. Please try again.')]);
            exit;
        }
    }

    public function handleSubscriptionCancel(): void
    {
        global $userID, $RL, $base_url;

        $RL = $this->repository;

        header('Content-Type: application/json; charset=utf-8');
        try {
            $csrfToken = (string) ($_POST['csrf_token'] ?? '');
            if (!function_exists('checkCsrfToken') || !checkCsrfToken($csrfToken)) {
                http_response_code(403);
                echo json_encode([
                    'status'  => 'error',
                    'message' => customLang('invalid_csrf_token'),
                ]);
                exit;
            }

            if (!isset($userID) || (int) $userID <= 0) {
                echo json_encode(['status' => 'error', 'message' => customLang('please_login_first', 'Please login first.')]);
                exit;
            }

            $buyerId = (int) $userID;
            $creatorId = (int) ($_POST['creator_id'] ?? 0);
            $subscriptionId = (int) ($_POST['subscription_id'] ?? 0);
            $provider = '';
            $providerObject = '';
            $statusRaw = '';
            $currentPeriodEnd = 0;
            $db = (isset($RL) && method_exists($RL, 'getDb')) ? $RL->getDb() : null;

            if ($subscriptionId > 0 && $db instanceof PDO) {
                $st = $db->prepare('SELECT id, buyer_id, recipient_id, provider, reference, provider_object_id, status, cancelled_at FROM i_subscription_payments WHERE id = :id LIMIT 1');
                $st->execute([':id' => $subscriptionId]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$row || (int) ($row['buyer_id'] ?? 0) !== $buyerId) {
                    echo json_encode(['status' => 'error', 'message' => customLang('error_invalid_parameters', 'Invalid parameters.')]);
                    exit;
                }
                $creatorId = (int) ($row['recipient_id'] ?? 0);
                $provider = (string) ($row['provider'] ?? '');
                $providerObject = (string) ($row['provider_object_id'] ?? '');
                $statusRaw = strtolower((string) ($row['status'] ?? ''));
                $cancelledAt = (int) ($row['cancelled_at'] ?? 0);
                $currentPeriodEnd = (int) ($row['current_period_end'] ?? 0);
                if (in_array($statusRaw, ['canceled', 'cancelled'], true) && $cancelledAt > 0) {
                    $accessMessage = customLang('settings_subscription_cancel_success', 'Subscription cancelled. You will keep access until the end of the current period.');
                    $accessUntilLabel = '';
                    if ($currentPeriodEnd > 0) {
                        $accessUntilLabel = date('d.m.Y', $currentPeriodEnd);
                        $accessMessage = sprintf(
                            customLang('settings_subscription_cancel_access_until', 'Subscription cancelled. You will keep access until %s.'),
                            $accessUntilLabel
                        );
                    }
                    $response = ['status' => 'success', 'message' => $accessMessage];
                    if ($currentPeriodEnd > 0) {
                        $response['access_until'] = $currentPeriodEnd;
                        $response['access_until_label'] = $accessUntilLabel;
                    }
                    echo json_encode($response);
                    exit;
                }
            }

            if ($creatorId <= 0 || $creatorId === $buyerId) {
                echo json_encode(['status' => 'error', 'message' => customLang('error_invalid_parameters', 'Invalid parameters.')]);
                exit;
            }

            $now = time();
            $providerWarning = null;

            if (isset($RL) && method_exists($RL, 'RL_CancelProviderSubscription')) {
                $providerCancel = $RL->RL_CancelProviderSubscription($buyerId, $creatorId);
                if (is_array($providerCancel) && !($providerCancel['ok'] ?? true) && !empty($providerCancel['error'])) {
                    $providerWarning = (string) $providerCancel['error'];
                }
            } elseif ($provider !== '' && $providerObject !== '') {
                try {
                    $gateway = \PaymentFactory::make($provider);
                    if (method_exists($gateway, 'cancelSubscription')) {
                        $gatewayResult = $gateway->cancelSubscription($providerObject);
                        if (is_array($gatewayResult) && isset($gatewayResult['error']) && $gatewayResult['error'] !== '') {
                            $providerWarning = (string) $gatewayResult['error'];
                        }
                    }
                } catch (Throwable $__) {
                    $providerWarning = $providerWarning ?? 'provider_error';
                }
            }

            $relationCancelled = false;
            if (isset($RL) && method_exists($RL, 'RL_CancelSubscription')) {
                $relationCancelled = $RL->RL_CancelSubscription($buyerId, $creatorId, $now, false);
            }

            if ($db instanceof PDO) {
                if ($subscriptionId > 0) {
                    $up = $db->prepare("UPDATE i_subscription_payments SET status='canceled', event='user_cancelled', cancelled_at=:cancelled_at, updated_at=:updated_at WHERE id=:id AND buyer_id=:buyer");
                    $up->execute([
                        ':cancelled_at' => $now,
                        ':updated_at'   => $now,
                        ':id'          => $subscriptionId,
                        ':buyer'       => $buyerId
                    ]);
                } else {
                    $up = $db->prepare("UPDATE i_subscription_payments SET status='canceled', event='user_cancelled', cancelled_at=:cancelled_at, updated_at=:updated_at WHERE buyer_id=:buyer AND recipient_id=:creator AND (cancelled_at IS NULL OR cancelled_at = 0)");
                    $up->execute([
                        ':cancelled_at' => $now,
                        ':updated_at'   => $now,
                        ':buyer'        => $buyerId,
                        ':creator'      => $creatorId
                    ]);
                }
            }

            $accessUntilLabel = '';
            if ($currentPeriodEnd <= 0 && isset($db) && $db instanceof PDO) {
                try {
                    if ($subscriptionId > 0) {
                        $stFetch = $db->prepare('SELECT current_period_end FROM i_subscription_payments WHERE id = :id LIMIT 1');
                        $stFetch->execute([':id' => $subscriptionId]);
                        $currentPeriodEnd = (int) ($stFetch->fetchColumn() ?: 0);
                    }
                    if ($currentPeriodEnd <= 0) {
                        $stFetch = $db->prepare('SELECT current_period_end FROM i_subscription_payments WHERE buyer_id = :buyer AND recipient_id = :creator ORDER BY id DESC LIMIT 1');
                        $stFetch->execute([':buyer' => $buyerId, ':creator' => $creatorId]);
                        $currentPeriodEnd = (int) ($stFetch->fetchColumn() ?: 0);
                    }
                } catch (Throwable $__) {}
            }
            if ($currentPeriodEnd > 0) {
                $accessUntilLabel = date('d.m.Y', $currentPeriodEnd);
            }

            $message = customLang('settings_subscription_cancel_success', 'Subscription cancelled. You will keep access until the end of the current period.');
            if ($accessUntilLabel !== '') {
                $message = sprintf(
                    customLang('settings_subscription_cancel_access_until', 'Subscription cancelled. You will keep access until %s.'),
                    $accessUntilLabel
                );
            }
            if ($providerWarning) {
                $message = customLang('settings_subscription_cancel_success_partial', 'Subscription cancelled locally. Please double-check your payment provider to ensure billing stops.');
            }

            if (!$relationCancelled && !$providerWarning) {
                $message = customLang('settings_subscription_cancel_success_partial', 'Subscription cancelled locally. Please double-check your payment provider to ensure billing stops.');
            }

            $response = ['status' => 'success', 'message' => $message];
            if ($accessUntilLabel !== '') {
                $response['access_until'] = $currentPeriodEnd;
                $response['access_until_label'] = $accessUntilLabel;
            }
            echo json_encode($response);
            exit;
        } catch (Throwable $e) {
            if (isset($RL) && method_exists($RL, 'logError')) {
                $RL->logError('cancelSubscription failed: ' . $e->getMessage());
            }
            echo json_encode(['status' => 'error', 'message' => customLang('error_server', 'A server error occurred. Please try again.')]);
            exit;
        }
    }

    public function handleSubscribeMeModal(): void
    {
        global $RL, $currentTheme, $iconPath, $base_url;
        global $userID, $userData, $userWallet;
        global $enableStripe, $enablePaypal, $enableNowpayments, $enableCoinbase, $enableFlutterwave, $enablePaystack, $enableIyziCo, $enablePayu;
        global $globalCurCode, $stripeCurCode, $paypalCurCode, $nowpayCurCode, $coinbaseCurCode, $flutterwaveCurCode, $paystackCurCode, $iyzicoCurCode, $payuCurCode;
        global $paymentFeePercent, $paymentFeeFixed, $paymentTaxPercent, $subscriptionfee;

        $RL = $this->repository;
        $baseDir = dirname(__DIR__, 2);

        try {
            $postOwnerID = (int) ($_POST['post_id'] ?? null);
            if ($postOwnerID <= 0) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => customLang('error_invalid_parameters', 'Invalid parameters.'),
                    'code'    => 'INVALID_PARAMETERS',
                ]);
                exit;
            }
            $postData = $RL->RL_GetUserDetails($postOwnerID);

            if ($postData) {
                $userDetails = $RL->RL_GetUserDetails($postOwnerID);
                if (isset($userID) && (int)$userID > 0 && isset($RL) && method_exists($RL, 'RL_GetUserDetails')) {
                    try {
                        $viewerDetails = $RL->RL_GetUserDetails((int) $userID) ?: [];
                        if (!empty($viewerDetails)) {
                            $userWallet = isset($viewerDetails['wallet']) ? (float)$viewerDetails['wallet'] : ($userWallet ?? 0.0);
                            $billignDataFirstName = $viewerDetails['for_billing_first_name'] ?? $billignDataFirstName ?? null;
                            $billignDataLastName  = $viewerDetails['for_billing_last_name'] ?? $billignDataLastName ?? null;
                            $billignDataCountry   = $viewerDetails['for_billing_country'] ?? $billignDataCountry ?? null;
                            $billignDataCity      = $viewerDetails['for_billing_city'] ?? $billignDataCity ?? null;
                            $billignDataState     = $viewerDetails['for_billing_state'] ?? $billignDataState ?? null;
                            $billignDataPostCode  = $viewerDetails['for_billing_postcode'] ?? $billignDataPostCode ?? null;
                            $billignDataAddress   = $viewerDetails['for_billing_address'] ?? $billignDataAddress ?? null;
                            if (!isset($userData) || !is_array($userData) || empty($userData)) {
                                $userData = $viewerDetails;
                            }
                        }
                    } catch (\Throwable $__) {
                        // ignore prefill failure
                    }
                }
                $filePath = $baseDir . '/themes/' . $currentTheme . '/popUps/subscribeMe.php';
                if (!is_file($filePath)) {
                    echo json_encode([
                        'status'  => 'error',
                        'message' => customLang('requested_content_not_found', 'Requested content not found.'),
                        'code'    => 'NOT_FOUND',
                    ]);
                    exit;
                }

                $enableStripe = !empty($enableStripe);
                $enablePaypal = !empty($enablePaypal);
                $enableNowpayments = !empty($enableNowpayments);
                $enableCoinbase = !empty($enableCoinbase);
                $enableFlutterwave = !empty($enableFlutterwave);
                $enablePaystack = !empty($enablePaystack);
                $enableIyziCo = !empty($enableIyziCo);
                $enablePayu = !empty($enablePayu);

                $globalCurCode = isset($globalCurCode) && $globalCurCode !== '' ? (string) $globalCurCode : 'USD';
                $stripeCurCode = isset($stripeCurCode) && $stripeCurCode !== '' ? (string) $stripeCurCode : $globalCurCode;
                $paypalCurCode = isset($paypalCurCode) && $paypalCurCode !== '' ? (string) $paypalCurCode : $globalCurCode;
                $nowpayCurCode = isset($nowpayCurCode) && $nowpayCurCode !== '' ? (string) $nowpayCurCode : $globalCurCode;
                $coinbaseCurCode = isset($coinbaseCurCode) && $coinbaseCurCode !== '' ? (string) $coinbaseCurCode : $globalCurCode;
                $flutterwaveCurCode = isset($flutterwaveCurCode) && $flutterwaveCurCode !== '' ? (string) $flutterwaveCurCode : $globalCurCode;
                $flutterwaveCurCode = isset($flutterwaveCurCode) && $flutterwaveCurCode !== '' ? (string) $flutterwaveCurCode : $globalCurCode;
                $paystackCurCode = isset($paystackCurCode) && $paystackCurCode !== '' ? (string) $paystackCurCode : $globalCurCode;
                $iyzicoCurCode = isset($iyzicoCurCode) && $iyzicoCurCode !== '' ? (string) $iyzicoCurCode : $globalCurCode;
                $payuCurCode = isset($payuCurCode) && $payuCurCode !== '' ? (string) $payuCurCode : $globalCurCode;

                try {
                    $subConfig = \PaymentFactory::config();
                } catch (Throwable $__) {
                    $subConfig = [];
                }

                if (!empty($subConfig['currency'])) {
                    $globalCurCode = strtoupper((string) $subConfig['currency']);
                }

                $stripeCfg = $subConfig['stripe'] ?? [];
                if (!empty($stripeCfg['enabled']) && !empty($stripeCfg['secret_key'])) {
                    $enableStripe = true;
                    $stripeCurCode = strtoupper((string) ($stripeCfg['currency'] ?? $globalCurCode));
                }

                $paypalCfg = $subConfig['paypal'] ?? [];
                if (!empty($paypalCfg['enabled']) && !empty($paypalCfg['client_id']) && !empty($paypalCfg['client_secret'])) {
                    $enablePaypal = true;
                    $paypalCurCode = strtoupper((string) ($paypalCfg['currency'] ?? $globalCurCode));
                }

                $flutterwaveCfg = $subConfig['flutterwave'] ?? [];
                if (!empty($flutterwaveCfg['enabled']) && !empty($flutterwaveCfg['secret_key'])) {
                    $enableFlutterwave = true;
                    $flutterwaveCurCode = strtoupper((string) ($flutterwaveCfg['currency'] ?? $globalCurCode));
                }
                $paystackCfg = $subConfig['paystack'] ?? [];
                if (!empty($paystackCfg['enabled']) && !empty($paystackCfg['secret_key'])) {
                    $enablePaystack = true;
                    $paystackCurCode = strtoupper((string) ($paystackCfg['currency'] ?? $globalCurCode));
                }
                $iyzicoCfg = $subConfig['iyzico'] ?? [];
                if (!empty($iyzicoCfg['enabled']) && !empty($iyzicoCfg['api_key']) && !empty($iyzicoCfg['secret_key'])) {
                    $enableIyziCo = true;
                    $iyzicoCurCode = strtoupper((string) ($iyzicoCfg['currency'] ?? $globalCurCode));
                }
                $payuCfg = $subConfig['payu'] ?? [];
                if (!empty($payuCfg['enabled']) && !empty($payuCfg['client_id']) && !empty($payuCfg['client_secret']) && !empty($payuCfg['signature_key']) && !empty($payuCfg['pos_id'])) {
                    $enablePayu = true;
                    $payuCurCode = strtoupper((string) ($payuCfg['currency'] ?? $globalCurCode));
                }

                // Subscriptions do not currently support crypto providers, keep flags but UI will hide them.

                if (!isset($userWallet) || !is_numeric($userWallet) || (float)$userWallet <= 0.0 || !is_array($userData ?? null) || empty($userData)) {
                    try {
                        if (isset($RL) && method_exists($RL, 'RL_GetUserDetails') && isset($userID) && (int)$userID > 0) {
                            $viewer = $RL->RL_GetUserDetails((int) $userID) ?: [];
                            if (!empty($viewer)) {
                                $userWallet = isset($viewer['wallet']) ? (float)$viewer['wallet'] : ($userWallet ?? 0.0);
                                if (!isset($userData) || !is_array($userData) || empty($userData)) {
                                    $userData = $viewer;
                                }
                            }
                        }
                    } catch (Throwable $__) {
                        // ignore
                    }
                }

                if (!isset($iconPath) && isset($base_url)) {
                    $iconPath = rtrim($base_url, '/') . '/themes/' . $currentTheme . '/img/';
                }

                while (ob_get_level() > 0) { ob_end_clean(); }

                ob_start();
                include $filePath;
                $html = trim(ob_get_clean());

                echo json_encode([
                    'status' => 'success',
                    'html'   => $html,
                    'post_id'=> $postOwnerID
                ], JSON_UNESCAPED_UNICODE);
                exit;
            } else {
                echo json_encode([
                    'status'  => 'error',
                    'message' => customLang('error_invalid_parameters', 'Invalid parameters.'),
                    'code'    => 'INVALID_PARAMETERS',
                ]);
                exit;
            }
        } catch (Throwable $e) {
            if (isset($RL) && method_exists($RL, 'logError')) {
                $RL->logError('handleSubscribeMeModal failed: ' . $e->getMessage());
            }
            echo json_encode([
                'status' => 'error',
                'message' => customLang('server_error', 'A server error occurred. Please try again later.'),
            ]);
            exit;
        }
    }

    public function handlePurchaseModal(): void
    {
        global $RL, $currentTheme, $base_url, $iconPath;
        global $userID, $userData, $userWallet;
        global $enableStripe, $enablePaypal, $enableNowpayments, $enableCoinbase, $enableFlutterwave, $enablePaystack, $enableIyziCo, $enablePayu;
        global $globalCurCode, $stripeCurCode, $paypalCurCode, $nowpayCurCode, $coinbaseCurCode, $flutterwaveCurCode, $paystackCurCode, $iyzicoCurCode, $payuCurCode;
        global $currencies, $currencys, $currency;
        global $paymentFeePercent, $paymentFeeFixed, $paymentTaxPercent, $version;

        $RL = $this->repository;
        $baseDir = dirname(__DIR__, 2);

        try {
            $postId = (int) ($_POST['post_id'] ?? 0);
            if ($postId <= 0) {
                echo json_encode(['status'=>'error','message'=>customLang('error_invalid_parameters','Invalid parameters.'), 'code'=>'INVALID_PARAMETERS']);
                exit;
            }
            $postData = $RL->RL_GetPostData($postId);
            if ($postData) {
                $postOwnerID = (int)($postData['post_owner_id'] ?? 0);
                $userDetails = $RL->RL_GetUserDetails($postOwnerID);

                $billingFirst = isset($billignDataFirstName) ? (string) $billignDataFirstName : '';
                $billingLast  = isset($billignDataLastName) ? (string) $billignDataLastName : '';
                $billingCountry = isset($billignDataCountry) ? (string) $billignDataCountry : '';
                $billingCity    = isset($billignDataCity) ? (string) $billignDataCity : '';
                $billingState   = isset($billignDataState) ? (string) $billignDataState : '';
                $billingPost    = isset($billignDataPostCode) ? (string) $billignDataPostCode : '';
                $billingAddress = isset($billignDataAddress) ? (string) $billignDataAddress : '';

                if (isset($userID) && (int) $userID > 0 && isset($RL) && method_exists($RL, 'RL_GetUserDetails')) {
                    try {
                        $viewerDetails = $RL->RL_GetUserDetails((int) $userID) ?: [];
                        if (!empty($viewerDetails)) {
                            if (!isset($userData) || !is_array($userData) || empty($userData)) {
                                $userData = $viewerDetails;
                            }
                            if (!isset($userWallet) || !is_numeric($userWallet)) {
                                $userWallet = isset($viewerDetails['wallet']) ? (float) $viewerDetails['wallet'] : 0.0;
                            }
                            if ($billingFirst === '' && !empty($viewerDetails['for_billing_first_name'])) {
                                $billingFirst = (string) $viewerDetails['for_billing_first_name'];
                            }
                            if ($billingLast === '' && !empty($viewerDetails['for_billing_last_name'])) {
                                $billingLast = (string) $viewerDetails['for_billing_last_name'];
                            }
                            if ($billingCountry === '' && !empty($viewerDetails['for_billing_country'])) {
                                $billingCountry = strtoupper((string) $viewerDetails['for_billing_country']);
                            }
                            if ($billingCity === '' && !empty($viewerDetails['for_billing_city'])) {
                                $billingCity = (string) $viewerDetails['for_billing_city'];
                            }
                            if ($billingState === '' && !empty($viewerDetails['for_billing_state'])) {
                                $billingState = (string) $viewerDetails['for_billing_state'];
                            }
                            if ($billingPost === '' && !empty($viewerDetails['for_billing_postcode'])) {
                                $billingPost = (string) $viewerDetails['for_billing_postcode'];
                            }
                            if ($billingAddress === '' && !empty($viewerDetails['for_billing_address'])) {
                                $billingAddress = (string) $viewerDetails['for_billing_address'];
                            }
                        }
                    } catch (Throwable $__) {
                        // Ignore prefilling errors
                    }
                }

                if ((string) $billingFirst === '' && isset($userData) && is_array($userData) && !empty($userData['user_fullname'])) {
                    $parts = preg_split('~\s+~', trim((string) $userData['user_fullname']), 2);
                    if (!empty($parts[0])) {
                        $billingFirst = (string) $parts[0];
                    }
                    if ($billingLast === '' && !empty($parts[1])) {
                        $billingLast = (string) $parts[1];
                    }
                }

                $billignDataFirstName = $billingFirst;
                $billignDataLastName  = $billingLast;
                $billignDataCountry   = $billingCountry;
                $billignDataCity      = $billingCity;
                $billignDataState     = $billingState;
                $billignDataPostCode  = $billingPost;
                $billignDataAddress   = $billingAddress;

                if (!isset($currencies) || !is_array($currencies)) {
                    $currencies = (isset($currencys) && is_array($currencys)) ? $currencys : [];
                }

                $globalCurCode = isset($globalCurCode) && $globalCurCode !== '' ? (string) $globalCurCode : ((isset($currency) && $currency !== '') ? (string) $currency : 'USD');
                $stripeCurCode = isset($stripeCurCode) && $stripeCurCode !== '' ? (string) $stripeCurCode : $globalCurCode;
                $paypalCurCode = isset($paypalCurCode) && $paypalCurCode !== '' ? (string) $paypalCurCode : $globalCurCode;
                $nowpayCurCode = isset($nowpayCurCode) && $nowpayCurCode !== '' ? (string) $nowpayCurCode : $globalCurCode;
                $coinbaseCurCode = isset($coinbaseCurCode) && $coinbaseCurCode !== '' ? (string) $coinbaseCurCode : $globalCurCode;
                $paystackCurCode = isset($paystackCurCode) && $paystackCurCode !== '' ? (string) $paystackCurCode : $globalCurCode;
                $flutterwaveCurCode = isset($flutterwaveCurCode) && $flutterwaveCurCode !== '' ? (string) $flutterwaveCurCode : $globalCurCode;
                $iyzicoCurCode = isset($iyzicoCurCode) && $iyzicoCurCode !== '' ? (string) $iyzicoCurCode : $globalCurCode;
                $payuCurCode = isset($payuCurCode) && $payuCurCode !== '' ? (string) $payuCurCode : $globalCurCode;

                $enableStripe = !empty($enableStripe);
                $enablePaypal = !empty($enablePaypal);
                $enableNowpayments = !empty($enableNowpayments);
                $enableCoinbase = !empty($enableCoinbase);
                $enableFlutterwave = !empty($enableFlutterwave);
                $enablePaystack = !empty($enablePaystack);
                $enableIyziCo = !empty($enableIyziCo);
                $enablePayu = !empty($enablePayu);

                try {
                    $paymentsConfig = \PaymentFactory::config();
                } catch (Throwable $__) {
                    $paymentsConfig = [];
                }

                if (!empty($paymentsConfig['currency'])) {
                    $globalCurCode = strtoupper((string) $paymentsConfig['currency']);
                }

                $stripeCfg = $paymentsConfig['stripe'] ?? [];
                if (!empty($stripeCfg['enabled']) && !empty($stripeCfg['secret_key'])) {
                    $enableStripe = true;
                    $stripeCurCode = strtoupper((string) ($stripeCfg['currency'] ?? $globalCurCode));
                } else {
                    $enableStripe = false;
                }

                $paypalCfg = $paymentsConfig['paypal'] ?? [];
                if (!empty($paypalCfg['enabled']) && !empty($paypalCfg['client_id']) && !empty($paypalCfg['client_secret'])) {
                    $enablePaypal = true;
                    $paypalCurCode = strtoupper((string) ($paypalCfg['currency'] ?? $globalCurCode));
                } else {
                    $enablePaypal = false;
                }

                $nowCfg = $paymentsConfig['nowpayments'] ?? [];
                if (!empty($nowCfg['enabled']) && !empty($nowCfg['api_key'])) {
                    $enableNowpayments = true;
                    $nowpayCurCode = strtoupper((string) ($nowCfg['currency'] ?? $globalCurCode));
                } else {
                    $enableNowpayments = false;
                }

                $flutterwaveCfg = $paymentsConfig['flutterwave'] ?? [];
                if (!empty($flutterwaveCfg['enabled']) && !empty($flutterwaveCfg['secret_key'])) {
                    $enableFlutterwave = true;
                    $flutterwaveCurCode = strtoupper((string) ($flutterwaveCfg['currency'] ?? $globalCurCode));
                } else {
                    $enableFlutterwave = false;
                }

                $coinbaseCfg = $paymentsConfig['coinbase'] ?? [];
                if (!empty($coinbaseCfg['enabled']) && !empty($coinbaseCfg['api_key'])) {
                    $enableCoinbase = true;
                    $coinbaseCurCode = strtoupper((string) ($coinbaseCfg['currency'] ?? $globalCurCode));
                } else {
                    $enableCoinbase = false;
                }
                $paystackCfg = $paymentsConfig['paystack'] ?? [];
                if (!empty($paystackCfg['enabled']) && !empty($paystackCfg['secret_key'])) {
                    $enablePaystack = true;
                    $paystackCurCode = strtoupper((string) ($paystackCfg['currency'] ?? $globalCurCode));
                } else {
                    $enablePaystack = false;
                }
                $iyzicoCfg = $paymentsConfig['iyzico'] ?? [];
                if (!empty($iyzicoCfg['enabled']) && !empty($iyzicoCfg['api_key']) && !empty($iyzicoCfg['secret_key'])) {
                    $enableIyziCo = true;
                    $iyzicoCurCode = strtoupper((string) ($iyzicoCfg['currency'] ?? $globalCurCode));
                } else {
                    $enableIyziCo = false;
                }
                $payuCfg = $paymentsConfig['payu'] ?? [];
                if (!empty($payuCfg['enabled']) && !empty($payuCfg['client_id']) && !empty($payuCfg['client_secret']) && !empty($payuCfg['signature_key']) && !empty($payuCfg['pos_id'])) {
                    $enablePayu = true;
                    $payuCurCode = strtoupper((string) ($payuCfg['currency'] ?? $globalCurCode));
                } else {
                    $enablePayu = false;
                }
                $payuCfg = $paymentsConfig['payu'] ?? [];
                if (!empty($payuCfg['enabled']) && !empty($payuCfg['client_id']) && !empty($payuCfg['client_secret']) && !empty($payuCfg['signature_key']) && !empty($payuCfg['pos_id'])) {
                    $enablePayu = true;
                    $payuCurCode = strtoupper((string) ($payuCfg['currency'] ?? $globalCurCode));
                } else {
                    $enablePayu = false;
                }

                if (!isset($paymentFeePercent)) { $paymentFeePercent = 0.0; }
                if (!isset($paymentFeeFixed)) { $paymentFeeFixed = 0.0; }
                if (!isset($paymentTaxPercent)) { $paymentTaxPercent = 0.0; }

                $filePath = $baseDir . '/themes/' . $currentTheme . '/popUps/purchasePost.php';
                if (!is_file($filePath)) {
                    echo json_encode([
                        'status'  => 'error',
                        'message' => customLang('requested_content_not_found', 'Requested content not found.'),
                        'code'    => 'NOT_FOUND',
                    ]);
                    exit;
                }
                if (!isset($iconPath) && isset($base_url)) {
                    $iconPath = rtrim($base_url, '/') . '/themes/' . $currentTheme . '/img/';
                }
                while (ob_get_level() > 0) { ob_end_clean(); }
                ob_start();
                include $filePath;
                $html = trim(ob_get_clean());
                echo json_encode([
                    'status' => 'success',
                    'html'   => $html,
                    'post_id'=> $postOwnerID
                ], JSON_UNESCAPED_UNICODE);
                exit;
            } else {
                echo json_encode([
                    'status'  => 'error',
                    'message' => customLang('error_invalid_parameters', 'Invalid parameters.'),
                    'code'    => 'INVALID_PARAMETERS',
                ]);
                exit;
            }
        } catch (Throwable $e) {
            echo json_encode(['status'=>'error','message'=>customLang('server_error')]);
        }
    }

    private function extractBuyerFields(?array $user): array
    {
        $email = '';
        $name = '';
        $username = '';
        if (is_array($user)) {
            $email = trim((string)($user['contact_email'] ?? $user['user_email'] ?? ''));
            $fullName = trim((string)($user['user_fullname'] ?? ''));
            $username = trim((string)($user['username'] ?? ''));
            if ($fullName !== '') {
                $name = $fullName;
            } elseif ($username !== '') {
                $name = $username;
            }
        }
        return [
            'email'    => $email,
            'name'     => $name,
            'username' => $username,
        ];
    }

    private function ensureBuyerEmail(array &$meta, int $userId): void
    {
        if (!empty($meta['buyer_email'])) {
            return;
        }
        try {
            $userDataGlobal = isset($GLOBALS['userData']) && is_array($GLOBALS['userData']) ? $GLOBALS['userData'] : null;
            $candidate = '';
            if (is_array($userDataGlobal)) {
                $candidate = trim((string)($userDataGlobal['contact_email'] ?? $userDataGlobal['user_email'] ?? ''));
            }
            if ($candidate === '' && isset($GLOBALS['RL']) && method_exists($GLOBALS['RL'], 'RL_GetUserDetails') && $userId > 0) {
                $details = $GLOBALS['RL']->RL_GetUserDetails((int) $userId) ?: [];
                if (is_array($details)) {
                    $candidate = trim((string)($details['contact_email'] ?? $details['user_email'] ?? ''));
                }
            }
            if ($candidate !== '') {
                $meta['buyer_email'] = $candidate;
            }
        } catch (\Throwable $__) {
            // Swallow failures; caller will surface a more direct error if still missing.
        }
    }

    private function resolveProviderPrecision(string $provider, string $currencyCode): int
    {
        $provider = strtolower($provider);
        $currencyCode = strtoupper($currencyCode);
        $cryptoCurrencies = [
            'BTC', 'ETH', 'BCH', 'LTC', 'DOGE', 'XMR', 'SOL', 'ADA', 'MATIC', 'XRP',
            'USDT', 'USDC', 'DAI', 'BNB', 'TRX'
        ];
        if ($currencyCode !== '') {
            return in_array($currencyCode, $cryptoCurrencies, true) ? 8 : 2;
        }
        return in_array($provider, ['nowpayments', 'coinbase'], true) ? 8 : 2;
    }

    private function resolveProviderMinimum(array $limits, string $provider, string $currencyCode): float
    {
        $provider = strtolower($provider);
        $currencyCode = strtoupper($currencyCode);
        $precision = $this->resolveProviderPrecision($provider, $currencyCode);
        if (in_array($provider, ['nowpayments', 'coinbase'], true)) {
            $base = 1 / pow(10, max(1, $precision));
            return max($base, 0.0005);
        }
        return (float) ($limits['min'] ?? 0.0);
    }

    private function buildProviderQuickAmounts(string $provider, float $minAmount, float $maxAmount, int $precision): array
    {
        $provider = strtolower($provider);
        $values = [];
        if (in_array($provider, ['nowpayments', 'coinbase'], true)) {
            $base = $minAmount > 0 ? $minAmount : (1 / pow(10, max(1, $precision)));
            $multipliers = [1, 2, 5, 10, 20, 50];
            foreach ($multipliers as $multiplier) {
                $candidate = $base * $multiplier;
                if ($maxAmount > 0.0 && $candidate > $maxAmount) {
                    break;
                }
                $values[] = round($candidate, $precision);
            }
        } else {
            $presets = [10, 25, 50, 100, 250, 500];
            foreach ($presets as $preset) {
                $candidate = (float) $preset;
                if ($minAmount > 0.0 && $candidate < $minAmount) {
                    continue;
                }
                if ($maxAmount > 0.0 && $candidate > $maxAmount) {
                    continue;
                }
                $values[] = round($candidate, $precision);
            }
        }

        $values = array_values(array_unique(array_filter($values, static function ($v) {
            return $v > 0;
        })));
        return $values;
    }
}
