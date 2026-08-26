<?php
// Unified payment webhook handler for all providers and flows (ads + tips)
// Public endpoint: no CSRF, no login required

include_once __DIR__ . '/../includes/inc.php';
require_once __DIR__ . '/../includes/payments/PaymentFactory.php';
require_once __DIR__ . '/../includes/helpers/webhooks_helper.php';
header('Content-Type: application/json; charset=utf-8');

$functionsErrorPath = dirname(__DIR__) . '/includes/functions_error.txt';
$logFunctionError = static function (string $message) use ($functionsErrorPath): void {
    $line = date('c') . ' ' . $message;
    @file_put_contents($functionsErrorPath, $line . "\n", FILE_APPEND);
};

$headers = function_exists('getallheaders') ? getallheaders() : [];
$payload = Webhooks::rawBody(); // use raw php://input without re-encoding

$nowpaymentsTracePath = dirname(__DIR__) . '/includes/nowpayments_trace.log';
$nowpaymentsTraceEnabled = defined('APP_DEBUG') && APP_DEBUG === true;
$logNowpaymentsTrace = static function (string $message) use ($nowpaymentsTracePath, $nowpaymentsTraceEnabled): void {
    if (!$nowpaymentsTraceEnabled) {
        return;
    }
    $line = date('c') . ' ' . $message;
    @file_put_contents($nowpaymentsTracePath, $line . "\n", FILE_APPEND);
};

$coinbaseTracePath = dirname(__DIR__) . '/includes/coinbase_trace.log';
$coinbaseTraceEnabled = defined('APP_DEBUG') && APP_DEBUG === true;
$logCoinbaseTrace = static function (string $message) use ($coinbaseTracePath, $coinbaseTraceEnabled): void {
    if (!$coinbaseTraceEnabled) {
        return;
    }
    $line = date('c') . ' ' . $message;
    @file_put_contents($coinbaseTracePath, $line . "\n", FILE_APPEND);
};

// Always log entry point so we can confirm that the webhook endpoint is reached (debug mode only).
$logNowpaymentsTrace('webhook_in remote=' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ' len=' . strlen((string)$payload));

if (!function_exists('resolve_paypal_reference')) {
    function resolve_paypal_reference(array $resource): string
    {
        $ref = '';
        if (isset($resource['custom_id'])) {
            $ref = trim((string) $resource['custom_id']);
        }
        if ($ref === '' && isset($resource['purchase_units']) && is_array($resource['purchase_units'])) {
            $firstUnit = $resource['purchase_units'][0] ?? null;
            if (is_array($firstUnit) && isset($firstUnit['invoice_id'])) {
                $ref = trim((string) $firstUnit['invoice_id']);
            }
        }
        if ($ref === '' && isset($resource['invoice_id'])) {
            $ref = trim((string) $resource['invoice_id']);
        }
        if ($ref === '' && isset($resource['supplementary_data']['related_ids']['order_id'])) {
            $ref = trim((string) $resource['supplementary_data']['related_ids']['order_id']);
        }

        return $ref;
    }
}

try {
    $provider = strtolower(trim((string)($_GET['provider'] ?? $_POST['provider'] ?? '')));

    if ($provider === '' && isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/request/webhooks.php/') !== false) {
        $tail = preg_replace('#^.*?/request/webhooks\\.php/#', '', (string) $_SERVER['REQUEST_URI']);
        $candidate = strtolower(trim(strtok((string) $tail, '?&/')));
        if ($candidate !== '') {
            $provider = $candidate;
        }
    }

    if ($provider === '') {
        $headerMap = [];
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                $headerMap[strtolower((string) $key)] = (string) (is_array($value) ? reset($value) : $value);
            }
        }
        if (!empty($headerMap['stripe-signature'])) {
            $provider = 'stripe';
        } elseif (!empty($headerMap['paypal-transmission-id'])) {
            $provider = 'paypal';
        } elseif (!empty($headerMap['x-nowpayments-sig'])) {
            $provider = 'nowpayments';
        } elseif (!empty($headerMap['x-cc-webhook-signature'])) {
            $provider = 'coinbase';
        } elseif (!empty($headerMap['verif-hash'])) {
            $provider = 'flutterwave';
        } elseif (!empty($headerMap['x-paystack-signature'])) {
            $provider = 'paystack';
        } elseif (!empty($headerMap['x-iyzi-signature']) || !empty($headerMap['x-iyzico-signature'])) {
            $provider = 'iyzico';
        } elseif (!empty($headerMap['openpayu-signature']) || !empty($headerMap['openpayu_signature'])) {
            $provider = 'payu';
        }
    }

    if ($provider === '' && $payload !== '') {
        $payloadSample = strtolower(substr($payload, 0, 256));
        if (strpos($payloadSample, 'stripe') !== false) {
            $provider = 'stripe';
        } elseif (strpos($payloadSample, 'paypal') !== false) {
            $provider = 'paypal';
        } elseif (strpos($payloadSample, 'nowpayments') !== false) {
            $provider = 'nowpayments';
        } elseif (strpos($payloadSample, 'coinbase') !== false) {
            $provider = 'coinbase';
        } elseif (strpos($payloadSample, 'flutterwave') !== false) {
            $provider = 'flutterwave';
        } elseif (strpos($payloadSample, 'paystack') !== false) {
            $provider = 'paystack';
        } elseif (strpos($payloadSample, 'iyzico') !== false || strpos($payloadSample, 'iyzi') !== false) {
            $provider = 'iyzico';
        } elseif (strpos($payloadSample, 'payu') !== false) {
            $provider = 'payu';
        }
    }

    if ($provider === '') {
        $logFunctionError('location=provider_detection error=missing_provider');
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'missing_provider', 'message_text' => customLang('missing_provider')]);
        exit;
    }

    if ($provider === 'nowpayments') {
        $logNowpaymentsTrace('provider_detected=nowpayments headers=' . json_encode($headers, JSON_UNESCAPED_UNICODE));
    }
    if ($provider === 'coinbase' || $provider === 'coinbase_commerce') {
        $logCoinbaseTrace('provider_detected=coinbase headers=' . json_encode($headers, JSON_UNESCAPED_UNICODE));
    }

    // Debug trace for inbound webhook (no secrets)
    if (Webhooks::isDebugEnabled()) {
        $sigKeys = ['Stripe-Signature','X-Nowpayments-Sig','X-CC-Webhook-Signature','Paypal-Transmission-Id'];
        $presentSigs = [];
        foreach ($sigKeys as $k) { if (!empty($headers[$k])) { $presentSigs[] = $k; } }
        $line = date('c') . " WEBHOOK_IN provider=$provider sigs=[" . implode(',', $presentSigs) . "] payload_len=" . strlen($payload);
        Webhooks::debugLog($line);
    }

    if ($provider === 'paypal') {
        $paymentsConfig = PaymentFactory::config();
        $resolvedWebhookId = (string) ($paymentsConfig['paypal']['webhook_id'] ?? '');
        $dbWebhookId = '';
        $dbLookupError = null;
        $headerWebhookId = '';
        if (is_array($headers)) {
            foreach ($headers as $hk => $hv) {
                if (strtolower((string) $hk) === 'paypal-webhook-id') {
                    $headerWebhookId = is_array($hv) ? (string) reset($hv) : (string) $hv;
                    break;
                }
            }
        }

        if (isset($RL) && method_exists($RL, 'RL_configs')) {
            try {
                $siteConfigRow = (array) $RL->RL_configs();
                $dbWebhookId = (string) ($siteConfigRow['paypal_webhook_id'] ?? '');
            } catch (Throwable $configError) {
                $dbLookupError = $configError->getMessage();
            }
        } else {
            $dbLookupError = 'repository_unavailable';
        }

        if ($dbLookupError !== null) {
            $logFunctionError('location=paypal_webhook_id_check result=Yanlış sebep=' . $dbLookupError);
        } elseif ($dbWebhookId === '') {
            $logFunctionError('location=paypal_webhook_id_check result=Yanlış sebep=bos');
        } elseif ($resolvedWebhookId === $dbWebhookId) {
            $logFunctionError('location=paypal_webhook_id_check result=Doğru değer=' . $resolvedWebhookId);
        } else {
            $logFunctionError('location=paypal_webhook_id_check result=Yanlış resolved=' . $resolvedWebhookId . ' db=' . $dbWebhookId);
        }

        if ($headerWebhookId !== '') {
            if (stripos($headerWebhookId, 'WH-') === 0) {
                $logFunctionError('paypal_header_id=WH kullanım WH-... ile işlem yapılmıştır (' . $headerWebhookId . ')');
            } elseif ($headerWebhookId === $resolvedWebhookId) {
                $logFunctionError('paypal_header_id=Webhook id ile işlem yapılmıştır (' . $headerWebhookId . ')');
            } else {
                $logFunctionError('paypal_header_id bilinmeyen değer=' . $headerWebhookId);
            }
        } else {
            $decodedPayload = json_decode($payload, true);
            $eventIdForLog = is_array($decodedPayload) ? (string) ($decodedPayload['id'] ?? '') : '';
            if ($eventIdForLog !== '') {
                $logFunctionError('paypal_header_id=boş event_id=' . $eventIdForLog . ' (WH-... ile işlem yapılmıştır)');
            } else {
                $logFunctionError('paypal_header_id ve event_id belirlenemedi');
            }
        }

        if (Webhooks::isDebugEnabled()) {
            $decodedPayload = json_decode($payload, true);
            $eventIdForLog = '';
            if (is_array($decodedPayload)) {
                $eventIdForLog = (string) ($decodedPayload['id'] ?? '');
            }
            $logSummary = [
                'event_id'          => $eventIdForLog,
                'payload_length'    => strlen((string) $payload),
                'webhook_id_header' => $headerWebhookId !== '' ? 'present' : 'missing',
            ];
            Webhooks::debugLog(date('c') . ' PAYPAL_WEBHOOK_META ' . json_encode($logSummary, JSON_UNESCAPED_UNICODE));
        }
    }

    // For Stripe, verify the webhook signature against raw body before any parsing.
    if ($provider === 'stripe') {
        require_once __DIR__ . '/../includes/payments/StripeGateway.php';
        // Extract Stripe-Signature header case-insensitively
        $sigHeader = (string) (Headers::get('Stripe-Signature') ?? '');
        // Secret priority: ENV first, then DB/admin fallback
        $whSecret = (string) (getenv('STRIPE_WEBHOOK_SECRET') ?: ($_ENV['STRIPE_WEBHOOK_SECRET'] ?? ($_SERVER['STRIPE_WEBHOOK_SECRET'] ?? '')));
        if ($whSecret === '' && isset($stripeWebhookSecret)) { $whSecret = (string) $stripeWebhookSecret; }
        if (!StripeGateway::verifyStripeWebhook((string) $payload, (string) $sigHeader, (string) $whSecret)) {
            if (Webhooks::isDebugEnabled()) {
                Webhooks::debugLog(date('c') . ' STRIPE_WH early_reject provider=stripe len=' . strlen((string)$payload));
            }
            $logFunctionError('location=stripe_signature_validation error=invalid_signature');
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'invalid_signature', 'message_text' => customLang('invalid_signature')]);
            exit;
        }
    }

    $gw = PaymentFactory::make($provider);
    $ver = $gw->verifyWebhook(is_array($headers) ? $headers : [], (string) $payload);
    if (!($ver['valid'] ?? false)) {
        $payloadDigest = hash('sha256', (string) $payload);
        if ($provider === 'nowpayments') {
            $sigHeaderPresent = 'missing';
            if (is_array($headers)) {
                foreach ($headers as $hk => $hv) {
                    if (strcasecmp((string) $hk, 'x-nowpayments-sig') === 0) {
                        $sigHeaderPresent = 'present';
                        break;
                    }
                }
            }
            $logNowpaymentsTrace(
                'verify_fail error='
                . (isset($ver['error']) ? (string) $ver['error'] : 'invalid_signature')
                . ' signature=' . $sigHeaderPresent
                . ' payload_sha256=' . $payloadDigest
            );
        }
        if (Webhooks::isDebugEnabled()) {
            $errDetail = isset($ver['error']) ? (string) $ver['error'] : 'unknown';
            $headerSnapshot = [];
            if (is_array($headers)) {
                foreach ($headers as $hk => $hv) {
                    $headerSnapshot[strtolower((string) $hk)] = is_array($hv) ? implode(',', $hv) : (string) $hv;
                }
            }
            $serverSnapshot = [];
            foreach ($_SERVER as $sk => $sv) {
                if (stripos((string) $sk, 'PAYPAL') !== false) {
                    $serverSnapshot[$sk] = is_array($sv) ? implode(',', $sv) : (string) $sv;
                }
            }
            $paypalConfig = [];
            try {
                $paypalConfig = PaymentFactory::config()['paypal'] ?? [];
            } catch (Throwable $__) {
                $paypalConfig = [];
            }
            $envSnapshot = [
                'PAYPAL_CLIENT_ID'     => getenv('PAYPAL_CLIENT_ID') ?: ($_ENV['PAYPAL_CLIENT_ID'] ?? ($_SERVER['PAYPAL_CLIENT_ID'] ?? '')),
                'PAYPAL_WEBHOOK_ID'    => getenv('PAYPAL_WEBHOOK_ID') ?: ($_ENV['PAYPAL_WEBHOOK_ID'] ?? ($_SERVER['PAYPAL_WEBHOOK_ID'] ?? '')),
                'PAYPAL_ENV'           => getenv('PAYPAL_ENV') ?: ($_ENV['PAYPAL_ENV'] ?? ($_SERVER['PAYPAL_ENV'] ?? '')),
            ];
            $debugContext = [
                'provider' => $provider,
                'error'    => $errDetail,
                'header_paypal_webhook_id' => $headerSnapshot['paypal-webhook-id'] ?? '',
                'header_transmission_id'   => $headerSnapshot['paypal-transmission-id'] ?? '',
                'header_transmission_time' => $headerSnapshot['paypal-transmission-time'] ?? '',
                'header_auth_algo'         => $headerSnapshot['paypal-auth-algo'] ?? '',
                'paypal_headers'           => $headerSnapshot,
                'paypal_server'            => $serverSnapshot,
                'paypal_config'            => $paypalConfig,
                'paypal_env'               => $envSnapshot,
                'payload_length'          => strlen((string) $payload),
                'payload_sha256'          => $payloadDigest,
            ];
            Webhooks::debugLog(
                date('c') . ' WEBHOOK_VERIFY_FAIL provider=' . $provider . ' error=' . $errDetail . ' ctx=' . json_encode($debugContext, JSON_UNESCAPED_UNICODE)
            );
        }
        $errorLabel = isset($ver['error']) ? (string) $ver['error'] : 'invalid_signature';
        $logFunctionError('location=verify_webhook provider=' . $provider . ' error=' . $errorLabel);
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'invalid_signature', 'message_text' => customLang('invalid_signature')]);
        exit;
    }

    if ($provider === 'paypal') {
        $rawArr = isset($ver['raw']) && is_array($ver['raw']) ? $ver['raw'] : [];
        $eventType = isset($rawArr['event_type']) ? (string) $rawArr['event_type'] : '';
        if (in_array($eventType, ['PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.SALE.COMPLETED'], true)) {
            $resource = isset($rawArr['resource']) && is_array($rawArr['resource']) ? $rawArr['resource'] : [];
            $existingRef = (string) ($ver['reference'] ?? '');
            if ($existingRef === '') {
                $resolvedRef = resolve_paypal_reference($resource);
                if ($resolvedRef !== '') {
                    $ver['reference'] = $resolvedRef;
                }
            }
            $ver['event'] = 'payment_success';
        } elseif (in_array($eventType, ['PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED'], true)) {
            if (Webhooks::isDebugEnabled()) {
                $resource = isset($rawArr['resource']) && is_array($rawArr['resource']) ? $rawArr['resource'] : [];
                $logRef = resolve_paypal_reference($resource);
                Webhooks::debugLog(date('c') . ' PAYPAL_WH capture_notice event=' . $eventType . ' ref=' . $logRef);
            }
        }
    }

    // Stripe: whitelist event types
    if ($provider === 'stripe') {
        if (!class_exists('StripeGateway')) { require_once __DIR__ . '/../includes/payments/StripeGateway.php'; }
        $rawArr = isset($ver['raw']) && is_array($ver['raw']) ? $ver['raw'] : [];
        $type   = isset($rawArr['type']) ? (string)$rawArr['type'] : '';
        if ($type === '' || !in_array($type, StripeGateway::allowedEventTypes(), true)) {
            if (Webhooks::isDebugEnabled()) {
                Webhooks::debugLog(date('c') . ' STRIPE_WH ignored event type=' . $type);
            }
            echo json_encode(['status' => 'ok']);
            exit;
        }
    }

    // Coinbase: whitelist event types before any state changes
    if ($provider === 'coinbase' || $provider === 'coinbase_commerce') {
        // Allowed types are defined in CoinbaseGateway for single source of truth
        if (!class_exists('CoinbaseGateway')) { require_once __DIR__ . '/../includes/payments/CoinbaseGateway.php'; }
        $rawArr = isset($ver['raw']) && is_array($ver['raw']) ? $ver['raw'] : [];
        $type   = isset($rawArr['event']['type']) ? (string)$rawArr['event']['type'] : '';
        if ($type === '' || !in_array($type, CoinbaseGateway::allowedEventTypes(), true)) {
            if (Webhooks::isDebugEnabled()) {
                Webhooks::debugLog(date('c') . ' COINBASE_WH ignored event type=' . $type);
            }
            echo json_encode(['status' => 'ok']);
            exit;
        }
    }

    if ($provider === 'flutterwave') {
        $eventType = strtolower((string)($ver['event'] ?? ''));
        $isAllowed = ($eventType === 'charge.completed' || $eventType === 'transfer.successful' || str_starts_with($eventType, 'subscription.'));
        if (!$isAllowed) {
            echo json_encode(['status' => 'ok']);
            exit;
        }
        if (in_array($eventType, ['charge.completed', 'transfer.successful', 'subscription.renewed', 'subscription.activated'], true)) {
            $event = 'payment_success';
            $ver['event'] = 'payment_success';
        }
    }

    // PayPal: whitelist subscription event types we consume
    if ($provider === 'paypal') {
        if (!class_exists('PayPalGateway')) { require_once __DIR__ . '/../includes/payments/PayPalGateway.php'; }
        $rawArr = isset($ver['raw']) && is_array($ver['raw']) ? $ver['raw'] : [];
        $type   = isset($rawArr['event_type']) ? (string)$rawArr['event_type'] : '';
        // If it's a subscription event, allow only whitelisted ones
        if (strpos($type, 'BILLING.SUBSCRIPTION.') === 0) {
            if (!in_array($type, PayPalGateway::allowedSubscriptionEventTypes(), true)) {
                if (Webhooks::isDebugEnabled()) {
                    Webhooks::debugLog(date('c') . ' PAYPAL_WH ignored event type=' . $type);
                }
                echo json_encode(['status' => 'ok']);
                exit;
            }
        }
    }

    // Helper closure to resolve currency precision consistently with checkout logic (crypto → 8 decimals).
    $resolvePrecision = static function (string $provider, string $currencyCode): int {
        $provider = strtolower(trim($provider));
        $currencyCode = strtoupper(trim($currencyCode));
        $cryptoCurrencies = [
            'BTC', 'ETH', 'BCH', 'LTC', 'DOGE', 'XMR', 'SOL', 'ADA', 'MATIC', 'XRP',
            'USDT', 'USDC', 'DAI', 'BNB', 'TRX'
        ];
        if ($currencyCode !== '') {
            return in_array($currencyCode, $cryptoCurrencies, true) ? 8 : 2;
        }
        return in_array($provider, ['nowpayments', 'coinbase'], true) ? 8 : 2;
    };

    // NOWPayments: whitelist statuses we consume (finished only)
    if ($provider === 'nowpayments') {
        if (!class_exists('NowPaymentsGateway')) { require_once __DIR__ . '/../includes/payments/NowPaymentsGateway.php'; }
        $rawArr = isset($ver['raw']) && is_array($ver['raw']) ? $ver['raw'] : [];
        $status = isset($rawArr['payment_status']) ? strtolower((string)$rawArr['payment_status']) : '';
        $logNowpaymentsTrace('verify_status status=' . ($status !== '' ? $status : 'empty') . ' reference=' . ($ver['reference'] ?? '')); 
        if ($status === '' || !in_array($status, NowPaymentsGateway::allowedStatuses(), true)) {
            if (Webhooks::isDebugEnabled()) {
                Webhooks::debugLog(date('c') . ' NOWPAY_WH ignored status=' . $status);
            }
            echo json_encode(['status' => 'ok']);
            exit;
        }

        $invoiceId     = (string)($rawArr['invoice_id'] ?? '');
        $paymentId     = (string)($rawArr['payment_id'] ?? '');
        $orderId       = (string)($rawArr['order_id'] ?? '');
        $priceAmount   = (string)($rawArr['price_amount'] ?? '');
        $payAmount     = (string)($rawArr['pay_amount'] ?? '');
        $actuallyPaid  = (string)($rawArr['actually_paid'] ?? '');
        $priceCurrency = strtoupper((string)($rawArr['price_currency'] ?? ''));
        $payCurrency   = strtoupper((string)($rawArr['pay_currency'] ?? ''));
        $metaFromWebhook = isset($ver['metadata']) && is_array($ver['metadata']) ? $ver['metadata'] : [];
        $metaFiatCurrency = strtoupper((string)($metaFromWebhook['fiat_currency'] ?? ''));
        $metaFiatAmount   = (string)($metaFromWebhook['fiat_amount'] ?? '');
        $metaCurrencyFallback = strtoupper((string)($metaFromWebhook['currency'] ?? ''));
        if ($metaFiatAmount !== '' && !is_numeric($metaFiatAmount)) {
            $metaFiatAmount = '';
        }
        if (($metaFiatAmount === '' || (float)$metaFiatAmount <= 0.0) && $metaFiatCurrency === '' && isset($gw) && method_exists($gw, 'estimateFiatAmount')) {
            $configAll = [];
            try { $configAll = PaymentFactory::config(); } catch (Throwable $__) { $configAll = []; }
            $preferredFiatEnv = strtoupper((string) (getenv('NOWPAYMENTS_FIAT_CURRENCY') ?: ($_ENV['NOWPAYMENTS_FIAT_CURRENCY'] ?? ($_SERVER['NOWPAYMENTS_FIAT_CURRENCY'] ?? ''))));
            $targetCurrency = strtoupper((string)($metaFromWebhook['preferred_fiat'] ?? ''));
            if ($targetCurrency === '') {
                $targetCurrency = $preferredFiatEnv !== '' ? $preferredFiatEnv : strtoupper((string)($configAll['currency'] ?? 'USD'));
            }
            if ($targetCurrency === '') { $targetCurrency = 'USD'; }
            $payAmountNumeric = null;
            if ($payAmount !== '' && is_numeric($payAmount)) {
                $payAmountNumeric = (float) $payAmount;
            } elseif ($actuallyPaid !== '' && is_numeric($actuallyPaid)) {
                $payAmountNumeric = (float) $actuallyPaid;
            }
            if ($payAmountNumeric === null && $priceAmount !== '' && is_numeric($priceAmount)) {
                $payAmountNumeric = (float) $priceAmount;
            }
            $payCurrencyCode = $payCurrency !== '' ? $payCurrency : '';
            if ($payCurrencyCode === '' && $priceCurrency !== '') {
                $payCurrencyCode = $priceCurrency;
            }
            if ($targetCurrency === '' || ($payCurrencyCode !== '' && strtoupper($payCurrencyCode) === $targetCurrency)) {
                $targetCurrency = $preferredFiatEnv !== '' && $preferredFiatEnv !== strtoupper($payCurrencyCode) ? $preferredFiatEnv : 'USD';
            }
            if ($payAmountNumeric !== null && $payCurrencyCode !== '' && $targetCurrency !== '' && strtoupper($payCurrencyCode) !== $targetCurrency) {
                try {
                    $estimateResult = $gw->estimateFiatAmount($payAmountNumeric, strtoupper($payCurrencyCode), $targetCurrency);
                } catch (Throwable $estimateError) {
                    $estimateResult = null;
                    $logNowpaymentsTrace('estimate_webhook_failed ref=' . $referenceVal . ' error=' . $estimateError->getMessage());
                }
                if (is_numeric($estimateResult) && (float) $estimateResult > 0.0) {
                    $metaFiatAmount = (string) number_format((float) $estimateResult, 8, '.', '');
                    $metaFiatCurrency = $targetCurrency;
                    $logNowpaymentsTrace('estimate_webhook_ok ref=' . $referenceVal . ' pay_amount=' . $payAmountNumeric . ' pay_currency=' . strtoupper($payCurrencyCode) . ' fiat_amount=' . $metaFiatAmount . ' fiat_currency=' . $metaFiatCurrency);
                }
            }
        }
        if ($priceCurrency !== '') {
            $cur = $priceCurrency;
        }
        if ($metaFiatCurrency !== '') {
            $cur = $metaFiatCurrency;
        }
        $envReported   = (string)($rawArr['environment'] ?? '');
        $referenceVal  = (string)($ver['reference'] ?? ($orderId !== '' ? $orderId : $invoiceId));

        $logFunctionError(
            sprintf(
                'nowpayments_webhook status=%s invoice=%s payment_id=%s reference=%s pay_amount=%s pay_currency=%s env=%s',
                $status !== '' ? $status : 'unknown',
                $invoiceId !== '' ? $invoiceId : 'none',
                $paymentId !== '' ? $paymentId : 'none',
                $referenceVal !== '' ? $referenceVal : 'none',
                $payAmount !== '' ? $payAmount : $actuallyPaid,
                $payCurrency !== '' ? $payCurrency : $priceCurrency,
                $envReported !== '' ? $envReported : 'n/a'
            )
        );

        if (Webhooks::isDebugEnabled()) {
            $debugContext = [
                'status'         => $status,
                'invoice_id'     => $invoiceId,
                'payment_id'     => $paymentId,
                'order_id'       => $orderId,
                'reference'      => $referenceVal,
                'price_amount'   => $priceAmount,
                'price_currency' => $priceCurrency,
                'pay_amount'     => $payAmount,
                'pay_currency'   => $payCurrency,
                'actually_paid'  => $actuallyPaid,
                'environment'    => $envReported,
                'payload_len'    => strlen((string)$payload),
            ];
            Webhooks::debugLog(date('c') . ' NOWPAY_WH ' . json_encode($debugContext, JSON_UNESCAPED_UNICODE));
        }
    }

    if ($provider === 'coinbase' || $provider === 'coinbase_commerce') {
        if (!class_exists('CoinbaseGateway')) { require_once __DIR__ . '/../includes/payments/CoinbaseGateway.php'; }
        $rawArr = isset($ver['raw']) && is_array($ver['raw']) ? $ver['raw'] : [];
        $eventArr = isset($rawArr['event']) && is_array($rawArr['event']) ? $rawArr['event'] : [];
        $eventType = strtolower((string)($eventArr['type'] ?? ''));
        $dataArr = isset($eventArr['data']) && is_array($eventArr['data']) ? $eventArr['data'] : [];
        $chargeId = (string)($dataArr['id'] ?? '');
        $chargeCode = (string)($dataArr['code'] ?? '');
        $pricing = isset($dataArr['pricing']) && is_array($dataArr['pricing']) ? $dataArr['pricing'] : [];
        $pricingLocal = isset($pricing['local']) && is_array($pricing['local']) ? $pricing['local'] : [];
        $pricingSubtotal = isset($pricing['subtotal']) && is_array($pricing['subtotal']) ? $pricing['subtotal'] : [];
        $pricingTotal = isset($pricing['total']) && is_array($pricing['total']) ? $pricing['total'] : [];
        $payments = isset($dataArr['payments']) && is_array($dataArr['payments']) ? $dataArr['payments'] : [];

        $metaFromWebhook = isset($ver['metadata']) && is_array($ver['metadata']) ? $ver['metadata'] : [];
        if (isset($dataArr['metadata']) && is_array($dataArr['metadata'])) {
            foreach ($dataArr['metadata'] as $mk => $mv) {
                if ($mv === null || is_array($mv)) {
                    continue;
                }
                if (!isset($metaFromWebhook[$mk]) && $mv !== '') {
                    $metaFromWebhook[$mk] = (string) $mv;
                }
            }
        }

        $coinbaseFiatCurrency = strtoupper((string)($metaFromWebhook['fiat_currency']
            ?? ($pricingLocal['currency']
                ?? ($pricingSubtotal['currency']
                    ?? ($pricingTotal['currency'] ?? '')))));
        $coinbaseFiatAmount = (string)($metaFromWebhook['fiat_amount']
            ?? ($pricingLocal['amount']
                ?? ($pricingSubtotal['amount']
                    ?? ($pricingTotal['amount'] ?? ''))));
        if ($coinbaseFiatAmount !== '' && !is_numeric($coinbaseFiatAmount)) {
            $coinbaseFiatAmount = '';
        }

        $coinbasePayCurrency = strtoupper((string)($metaFromWebhook['crypto_currency'] ?? ''));
        $coinbasePayAmount = (string)($metaFromWebhook['crypto_amount'] ?? '');
        if ($coinbasePayAmount !== '' && !is_numeric($coinbasePayAmount)) {
            $coinbasePayAmount = '';
        }
        $coinbaseActuallyPaid = '';
        if (is_array($payments)) {
            foreach ($payments as $paymentEntry) {
                if (!is_array($paymentEntry)) {
                    continue;
                }
                $valueArr = isset($paymentEntry['value']) && is_array($paymentEntry['value']) ? $paymentEntry['value'] : [];
                $netArr = isset($paymentEntry['net']) && is_array($paymentEntry['net']) ? $paymentEntry['net'] : [];
                $candidateAmount = null;
                $candidateCurrency = '';
                if (isset($netArr['amount']) && is_numeric($netArr['amount'])) {
                    $candidateAmount = (string) $netArr['amount'];
                    $candidateCurrency = strtoupper((string)($netArr['crypto'] ?? ''));
                } elseif (isset($valueArr['amount']) && is_numeric($valueArr['amount'])) {
                    $candidateAmount = (string) $valueArr['amount'];
                    $candidateCurrency = strtoupper((string)($valueArr['crypto'] ?? ''));
                }
                if ($candidateAmount !== null && $candidateCurrency !== '') {
                    $coinbasePayAmount = $candidateAmount;
                    $coinbasePayCurrency = $candidateCurrency;
                    $coinbaseActuallyPaid = $candidateAmount;
                }
            }
        }

        $preferredFiat = strtoupper((string)($metaFromWebhook['preferred_fiat'] ?? ''));
        $configCurrency = '';
        try {
            $configCurrency = (string) (PaymentFactory::config()['currency'] ?? '');
        } catch (Throwable $__) {
            $configCurrency = '';
        }
        $targetCurrency = $coinbaseFiatCurrency !== '' ? $coinbaseFiatCurrency : ($preferredFiat !== '' ? $preferredFiat : strtoupper($configCurrency));
        if ($targetCurrency === '') { $targetCurrency = 'USD'; }
        if ($targetCurrency === $coinbasePayCurrency && $preferredFiat !== '' && $preferredFiat !== $coinbasePayCurrency) {
            $targetCurrency = $preferredFiat;
        }

        $referenceVal = (string)($ver['reference'] ?? ($chargeId !== '' ? $chargeId : $chargeCode));

        if (($coinbaseFiatAmount === '' || (float) $coinbaseFiatAmount <= 0.0)
            && $coinbasePayAmount !== '' && is_numeric($coinbasePayAmount)
            && $coinbasePayCurrency !== '' && isset($gw) && method_exists($gw, 'estimateFiatAmount')
            && strtoupper($coinbasePayCurrency) !== $targetCurrency) {
            try {
                $estimateResult = $gw->estimateFiatAmount((float) $coinbasePayAmount, $coinbasePayCurrency, $targetCurrency);
            } catch (Throwable $estimateError) {
                $estimateResult = null;
                $logCoinbaseTrace('estimate_webhook_failed ref=' . $referenceVal . ' error=' . $estimateError->getMessage());
            }
            if (is_numeric($estimateResult) && (float) $estimateResult > 0.0) {
                $coinbaseFiatAmount = (string) number_format((float) $estimateResult, 8, '.', '');
                $coinbaseFiatCurrency = $targetCurrency;
                $logCoinbaseTrace('estimate_webhook_ok ref=' . $referenceVal . ' pay_amount=' . $coinbasePayAmount . ' pay_currency=' . $coinbasePayCurrency . ' fiat_amount=' . $coinbaseFiatAmount . ' fiat_currency=' . $coinbaseFiatCurrency);
            }
        }

        if ($coinbaseFiatCurrency !== '') {
            $cur = $coinbaseFiatCurrency;
        } elseif ($coinbasePayCurrency !== '') {
            $cur = $coinbasePayCurrency;
        }

        $logFunctionError(sprintf(
            'coinbase_webhook type=%s charge=%s code=%s reference=%s pay_amount=%s pay_currency=%s fiat_amount=%s fiat_currency=%s',
            $eventType !== '' ? $eventType : 'unknown',
            $chargeId !== '' ? $chargeId : 'none',
            $chargeCode !== '' ? $chargeCode : 'none',
            $referenceVal !== '' ? $referenceVal : 'none',
            $coinbasePayAmount !== '' ? $coinbasePayAmount : ($coinbaseActuallyPaid !== '' ? $coinbaseActuallyPaid : 'n/a'),
            $coinbasePayCurrency !== '' ? $coinbasePayCurrency : 'n/a',
            $coinbaseFiatAmount !== '' ? $coinbaseFiatAmount : 'n/a',
            $coinbaseFiatCurrency !== '' ? $coinbaseFiatCurrency : 'n/a'
        ));

        if ($eventType !== '') {
            $logCoinbaseTrace('verify_status type=' . $eventType . ' reference=' . $referenceVal);
        }
        if (Webhooks::isDebugEnabled()) {
            $debugContext = [
                'type'          => $eventType,
                'charge_id'     => $chargeId,
                'code'          => $chargeCode,
                'reference'     => $referenceVal,
                'pay_amount'    => $coinbasePayAmount,
                'pay_currency'  => $coinbasePayCurrency,
                'fiat_amount'   => $coinbaseFiatAmount,
                'fiat_currency' => $coinbaseFiatCurrency,
                'payload_len'   => strlen((string) $payload),
            ];
            Webhooks::debugLog(date('c') . ' COINBASE_WH ' . json_encode($debugContext, JSON_UNESCAPED_UNICODE));
        }
    }

    // Idempotency: prevent duplicate processing per provider+event_id
    try {
        $eventId = '';
        $raw = $ver['raw'] ?? null;
        if (is_array($raw)) {
            if (isset($raw['id']) && is_string($raw['id'])) { $eventId = $raw['id']; }
            elseif (isset($raw['event']['id']) && is_string($raw['event']['id'])) { $eventId = $raw['event']['id']; }
        }
        if ($eventId === '') { $eventId = (string)($ver['reference'] ?? ''); }
        if ($eventId !== '') {
            $first = Webhooks::markProcessed($provider, $eventId);
            if (!$first) {
                // Duplicate delivery: acknowledge without reprocessing
                if (Webhooks::isDebugEnabled()) {
                    Webhooks::debugLog(date('c') . ' WEBHOOK_DUP provider=' . $provider . ' event_id=' . $eventId);
                }
                echo json_encode(['status' => 'ok']);
                exit;
            }
        }
    } catch (Throwable $__) {
        if (Webhooks::isDebugEnabled()) {
            Webhooks::debugLog(date('c') . ' WEBHOOK_IDEMP_ERR provider=' . $provider);
        }
    }

    $event = (string)($ver['event'] ?? '');
    $ref   = (string)($ver['reference'] ?? '');
    $meta  = (array)($ver['metadata'] ?? []);
    $raw   = (array)($ver['raw'] ?? []);

    if ($provider === 'paypal' && $ref === '') {
        $resource = isset($raw['resource']) && is_array($raw['resource']) ? $raw['resource'] : [];
        $fallbackRef = resolve_paypal_reference($resource);
        if ($fallbackRef !== '') {
            $ver['reference'] = $ref = $fallbackRef;
        }
    }

    if ($provider === 'paypal' && strtoupper($event) === 'CHECKOUT.ORDER.APPROVED') {
        $internalRef = $ref;
        $orderId = (string) ($meta['provider_reference']
            ?? ($meta['order_id'] ?? '')
            ?? ($raw['resource']['id'] ?? ($raw['id'] ?? '')));
        if ($orderId === '' && $internalRef !== '') {
            $orderId = $internalRef;
        }
        $shouldCapture = true;
        if ($internalRef !== '' && isset($RL) && method_exists($RL, 'RL_GetWalletTopupByReference')) {
            try {
                $existingTopup = $RL->RL_GetWalletTopupByReference('paypal', $internalRef);
                if (is_array($existingTopup) && in_array($existingTopup['status'] ?? '', ['succeeded', 'completed', 'approved'], true)) {
                    $shouldCapture = false;
                    $event = 'payment_success';
                    $ver['event'] = 'payment_success';
                }
            } catch (Throwable $__) {
                // ignore
            }
        }

        if ($shouldCapture && $orderId !== '' && $gw instanceof PayPalGateway) {
            try {
                $capture = $gw->captureOrder($orderId);
                $ver['raw']['capture_response'] = $capture;
                $captureStatus = strtoupper((string)($capture['status'] ?? ''));
                $captures = $capture['purchase_units'][0]['payments']['captures'] ?? [];
                $firstCapture = is_array($captures) && isset($captures[0]) ? $captures[0] : [];
                $firstCaptureStatus = strtoupper((string)($firstCapture['status'] ?? ''));
                if (Webhooks::isDebugEnabled()) {
                    $captureSummary = [
                        'order'          => $orderId,
                        'status'         => $captureStatus,
                        'capture_status' => $firstCaptureStatus,
                        'capture_id'     => isset($firstCapture['id']) ? (string) $firstCapture['id'] : '',
                    ];
                    Webhooks::debugLog(date('c') . ' PAYPAL_CAPTURE_RESPONSE ' . json_encode($captureSummary, JSON_UNESCAPED_UNICODE));
                }
                if (in_array('COMPLETED', [$captureStatus, $firstCaptureStatus], true)) {
                    $ver['event'] = $event = 'payment_success';
                    $ref = $internalRef !== '' ? $internalRef : $orderId;
                    $ver['reference'] = $ref;
                    if (!empty($firstCapture)) {
                        $ver['raw']['resource'] = $firstCapture;
                    }
                    $meta['order_id'] = $orderId;
                    if (!empty($firstCapture['id'])) {
                        $meta['capture_id'] = (string)$firstCapture['id'];
                    }
                    $ver['metadata'] = $meta;
                    $raw = $ver['raw'];
                } else {
                    if (isset($RL) && method_exists($RL, 'logError')) {
                        $RL->logError('paypal_capture_incomplete order=' . $orderId . ' status=' . $captureStatus . ' capture_status=' . $firstCaptureStatus);
                    }
                }
            } catch (Throwable $captureError) {
                if (isset($RL) && method_exists($RL, 'logError')) {
                    $RL->logError('paypal_capture_exception order=' . $orderId . ' error=' . $captureError->getMessage());
                }
                if (Webhooks::isDebugEnabled()) {
                    Webhooks::debugLog(date('c') . ' PAYPAL_CAPTURE_EXCEPTION order=' . $orderId . ' error=' . $captureError->getMessage());
                }
            }
        }
    }

    // Try to resolve what this payment is for
    $scope = (string)($meta['type'] ?? '');
    if ($scope === '') {
        // Heuristics
        if (isset($meta['ad_id'])) { $scope = 'advertisement'; }
        elseif (isset($meta['ebook_id'])) { $scope = 'ebook'; }
        elseif (isset($meta['post_id']) || isset($meta['recipient_id']) || isset($meta['buyer_id'])) { $scope = 'tip'; }
    }

    $walletTopupRow = null;
    if (($scope === '' || $scope === 'wallet_topup') && $ref !== '' && isset($RL) && method_exists($RL, 'RL_GetWalletTopupByReference')) {
        try {
            $walletTopupRow = $RL->RL_GetWalletTopupByReference($provider, $ref);
        } catch (Throwable $__) {
            $walletTopupRow = null;
        }
        if (is_array($walletTopupRow)) {
            $scope = 'wallet_topup';
        }
    }

    // Parse optional amounts from provider payloads
    $gross = null; $cur = null; $fee = null; $feeCur = null; $tax = null; $net = null;
    if ($provider === 'paypal') {
        $res = $raw['resource'] ?? [];
        $srb = $res['seller_receivable_breakdown'] ?? [];

        $capturePayload = isset($raw['capture']) && is_array($raw['capture']) ? $raw['capture'] : null;
        $primaryCapture = null;
        if ($capturePayload !== null) {
            if (isset($capturePayload['purchase_units'][0]['payments']['captures'][0]) && is_array($capturePayload['purchase_units'][0]['payments']['captures'][0])) {
                $primaryCapture = $capturePayload['purchase_units'][0]['payments']['captures'][0];
            } elseif (isset($capturePayload['seller_receivable_breakdown']) && is_array($capturePayload['seller_receivable_breakdown'])) {
                $primaryCapture = $capturePayload;
            }
        }

        if (empty($srb) && isset($raw['capture_breakdown']) && is_array($raw['capture_breakdown'])) {
            $srb = $raw['capture_breakdown'];
        } elseif (empty($srb) && isset($primaryCapture['seller_receivable_breakdown']) && is_array($primaryCapture['seller_receivable_breakdown'])) {
            $srb = $primaryCapture['seller_receivable_breakdown'];
        }

        if (!empty($srb)) {
            $gross = isset($srb['gross_amount']['value']) ? (float) $srb['gross_amount']['value'] : $gross;
            $cur = $srb['gross_amount']['currency_code'] ?? $cur;
            $fee = isset($srb['paypal_fee']['value']) ? (float) $srb['paypal_fee']['value'] : $fee;
            $feeCur = $srb['paypal_fee']['currency_code'] ?? $feeCur ?? $cur;
            $net = isset($srb['net_amount']['value']) ? (float) $srb['net_amount']['value'] : $net;
        }

        if ($primaryCapture !== null) {
            if ($gross === null && isset($primaryCapture['amount']['value'])) {
                $gross = (float) $primaryCapture['amount']['value'];
            }
            if ($cur === null && isset($primaryCapture['amount']['currency_code'])) {
                $cur = (string) $primaryCapture['amount']['currency_code'];
            }
        }

        if ($gross === null && isset($res['amount']['value'])) {
            $gross = (float) $res['amount']['value'];
            if ($cur === null && isset($res['amount']['currency_code'])) {
                $cur = (string) $res['amount']['currency_code'];
            }
        }

        if ($cur !== null) {
            $cur = strtoupper((string) $cur);
            if ($feeCur === null) {
                $feeCur = $cur;
            }
        }
    } elseif ($provider === 'stripe') {
        $obj = $raw['data']['object'] ?? [];
        if (isset($obj['amount_total'])) { $gross = ((float)$obj['amount_total']) / 100.0; }
        if (isset($obj['currency'])) { $cur = strtoupper((string)$obj['currency']); }
        if (isset($obj['total_details']['amount_tax'])) { $tax = ((float)$obj['total_details']['amount_tax']) / 100.0; }
        // Fee requires Stripe Balance txn; not available here
    }

    $metaSubtotal = (isset($meta['tip_subtotal']) && is_numeric($meta['tip_subtotal'])) ? (float)$meta['tip_subtotal'] : null;
    $metaFee = (isset($meta['tip_fee']) && is_numeric($meta['tip_fee'])) ? (float)$meta['tip_fee'] : null;
    $metaTax = (isset($meta['tip_tax']) && is_numeric($meta['tip_tax'])) ? (float)$meta['tip_tax'] : null;
    $metaTotal = (isset($meta['tip_total']) && is_numeric($meta['tip_total'])) ? (float)$meta['tip_total'] : null;
    $metaCurrency = isset($meta['tip_currency']) && is_string($meta['tip_currency']) ? strtoupper($meta['tip_currency']) : null;

    if ($gross === null && $metaTotal !== null) { $gross = $metaTotal; }
    if ($cur === null && $metaCurrency !== null) { $cur = $metaCurrency; }
    if ($fee === null && $metaFee !== null) { $fee = $metaFee; }
    if ($tax === null && $metaTax !== null) { $tax = $metaTax; }
    if ($net === null && $metaSubtotal !== null) { $net = $metaSubtotal; }
    if ($feeCur === null && $cur !== null) { $feeCur = $cur; }

    // Act on event
    if ($event === 'payment_success') {
        if (Webhooks::isDebugEnabled()) {
            $scopeDbg = $scope ?: 'unknown';
            $extra = '';
            if ($scopeDbg === 'advertisement') { $extra = ' ad_id=' . (int)($meta['ad_id'] ?? 0); }
            if ($scopeDbg === 'tip') {
                $extra = ' post_id='.(int)($meta['post_id'] ?? 0).' recipient_id='.(int)($meta['recipient_id'] ?? 0).' buyer_id='.(int)($meta['buyer_id'] ?? 0);
            }
            Webhooks::debugLog(date('c') . " WEBHOOK_OK provider=$provider scope=$scopeDbg event=$event ref=$ref$extra");
        }
        if ($scope === 'advertisement') {
            $adId = (int)($meta['ad_id'] ?? 0);
            if ($ref !== '' && isset($RL)) {
                if (method_exists($RL, 'RL_UpdateAdPaymentAmountsByReference')) {
                    $RL->RL_UpdateAdPaymentAmountsByReference($provider, $ref, $gross, $cur, $fee, $feeCur, $tax, $net);
                }
                if (method_exists($RL, 'RL_UpdateAdPaymentStatusByReference')) {
                    $RL->RL_UpdateAdPaymentStatusByReference($provider, $ref, 'succeeded', $event, (array)$ver);
                }
            }
            if ($adId > 0 && isset($RL) && method_exists($RL, 'RL_UpdateAdvertisementStatus')) {
                $RL->RL_UpdateAdvertisementStatus($adId, 'active');
            }
        } elseif ($scope === 'tip') {
            if ($ref !== '' && isset($RL)) {
                $currencyRaw = $cur ?? $metaCurrency ?? null;
                $currencyVal = ($currencyRaw === null || $currencyRaw === '') ? null : strtoupper((string)$currencyRaw);
                $notifyCurrency = $currencyVal ?? 'USD';
                $grossVal = $gross ?? $metaTotal ?? 0.0;
                $feeVal = $fee ?? $metaFee ?? 0.0;
                $taxVal = $tax ?? $metaTax ?? 0.0;
                $netVal = $net ?? $metaSubtotal ?? $grossVal;
                if (method_exists($RL, 'RL_UpdateTipPaymentAmountsByReference')) {
                    $RL->RL_UpdateTipPaymentAmountsByReference($provider, $ref, $grossVal, $currencyVal, $feeVal, $currencyVal, $taxVal, $netVal);
                }
                if (method_exists($RL, 'RL_UpdateTipPaymentStatusByReference')) {
                    $RL->RL_UpdateTipPaymentStatusByReference($provider, $ref, 'succeeded', $event, (array)$ver);
                }
                if (method_exists($RL, 'RL_CreditRecipientForTipByReference')) {
                    $RL->RL_CreditRecipientForTipByReference($provider, $ref);
                }
                // Notifications for recipient and payer
                $buyer = isset($meta['buyer_id']) ? (int)$meta['buyer_id'] : 0;
                $recip = isset($meta['recipient_id']) ? (int)$meta['recipient_id'] : 0;
                $post  = isset($meta['post_id']) ? (int)$meta['post_id'] : 0;
                $notifyAmount = $net ?? $gross ?? $metaSubtotal ?? null;
                if ($buyer > 0 && $recip > 0 && $notifyAmount !== null) {
                    if (method_exists($RL, 'RL_CreateTipPaymentNotification')) {
                        $RL->RL_CreateTipPaymentNotification($buyer, $recip, $post, (float)$notifyAmount, $notifyCurrency, $ref);
                    }
                    if (method_exists($RL, 'RL_CreateTipReceiptNotification')) {
                        $receiptAmt = $metaSubtotal ?? $notifyAmount;
                        $RL->RL_CreateTipReceiptNotification($buyer, $recip, $post, (float)$receiptAmt, $notifyCurrency, $ref);
                    }
                }
            }
        } elseif ($scope === 'audio_room_tip') {
            if ($ref !== '' && isset($RL)) {
                $alreadyCredited = false;
                if (method_exists($RL, 'RL_GetTipPaymentByReference')) {
                    $tipBefore = $RL->RL_GetTipPaymentByReference($provider, $ref);
                    $alreadyCredited = is_array($tipBefore) && (int)($tipBefore['credited_at'] ?? 0) > 0;
                }
                if (method_exists($RL, 'RL_UpdateTipPaymentAmountsByReference')) {
                    $RL->RL_UpdateTipPaymentAmountsByReference($provider, $ref, $gross, $cur, $fee, $feeCur, $tax, $net);
                }
                if (method_exists($RL, 'RL_UpdateTipPaymentStatusByReference')) {
                    $RL->RL_UpdateTipPaymentStatusByReference($provider, $ref, 'succeeded', $event, (array)$ver);
                }
                if (method_exists($RL, 'RL_CreditRecipientForTipByReference')) {
                    $RL->RL_CreditRecipientForTipByReference($provider, $ref);
                }
                if (!$alreadyCredited) {
                    $roomIdMeta = isset($meta['room_id']) ? (int)$meta['room_id'] : 0;
                    $buyer = isset($meta['buyer_id']) ? (int)$meta['buyer_id'] : 0;
                    $buyerName = trim((string)($meta['buyer_name'] ?? $meta['buyer_username'] ?? ''));
                    $tipAmount = $net ?? $gross ?? null;
                    $tipCurrency = (string)($cur ?? ($meta['tip_currency'] ?? 'USD'));
                    if ($roomIdMeta > 0 && $tipAmount !== null) {
                        if (method_exists($RL, 'RL_InsertAudioRoomTipEvent')) {
                            $RL->RL_InsertAudioRoomTipEvent($roomIdMeta, $buyer, $buyerName, (float)$tipAmount, $tipCurrency, [
                                'recipient_id' => isset($meta['recipient_id']) ? (int)$meta['recipient_id'] : 0,
                                'provider' => $provider,
                                'reference' => $ref,
                                'fee_amount' => $fee,
                                'tax_amount' => $tax,
                                'net_amount' => $net ?? $tipAmount,
                                'status' => 'succeeded',
                                'credited_at' => time(),
                                'created_at' => time(),
                            ]);
                        }
                        if (method_exists($RL, 'RL_InsertAudioRoomMessage')) {
                            $RL->RL_InsertAudioRoomMessage($roomIdMeta, $buyer, '', 'tip', ['buyer'=>$buyerName, 'amount'=>(float)$tipAmount, 'currency'=>$tipCurrency]);
                        }
                    }
                }
            }
        } elseif ($scope === 'purchase') {
            if ($ref !== '' && isset($RL)) {
                if (method_exists($RL, 'RL_UpdatePostPurchaseAmountsByReference')) {
                    $RL->RL_UpdatePostPurchaseAmountsByReference($provider, $ref, $gross, $cur, $fee, $feeCur, $tax, $net);
                }
                if (method_exists($RL, 'RL_UpdatePostPurchaseStatusByReference')) {
                    $RL->RL_UpdatePostPurchaseStatusByReference($provider, $ref, 'succeeded', $event, (array)$ver);
                }
                if (method_exists($RL, 'RL_CreditRecipientForPurchaseByReference')) {
                    $RL->RL_CreditRecipientForPurchaseByReference($provider, $ref);
                }
                // Notification to recipient
                $buyer = isset($meta['buyer_id']) ? (int)$meta['buyer_id'] : 0;
                $recip = isset($meta['recipient_id']) ? (int)$meta['recipient_id'] : 0;
                $post  = isset($meta['post_id']) ? (int)$meta['post_id'] : 0;
                $notifyAmount = $net ?? $gross ?? null;
                if ($buyer > 0 && $recip > 0 && $notifyAmount !== null && method_exists($RL, 'RL_CreatePostPurchaseNotification')) {
                    $RL->RL_CreatePostPurchaseNotification($buyer, $recip, $post, (float)$notifyAmount, (string)($cur ?? ''), $ref);
                }
            }
        } elseif ($scope === 'ebook') {
            if ($ref !== '' && isset($RL)) {
                if (method_exists($RL, 'RL_UpdateEbookPurchaseAmountsByReference')) {
                    $RL->RL_UpdateEbookPurchaseAmountsByReference($provider, $ref, $gross, $cur, $fee, $feeCur, $tax, $net);
                }
                if (method_exists($RL, 'RL_UpdateEbookPurchaseStatusByReference')) {
                    $RL->RL_UpdateEbookPurchaseStatusByReference($provider, $ref, 'succeeded', $event, (array)$ver);
                }
                if (method_exists($RL, 'RL_CreditRecipientForEbookPurchaseByReference')) {
                    $RL->RL_CreditRecipientForEbookPurchaseByReference($provider, $ref);
                }
            }
        } elseif ($scope === 'audio_room_ticket') {
            if ($ref !== '' && isset($RL)) {
                if (method_exists($RL, 'RL_UpdateAudioRoomTicketAmountsByReference')) {
                    $RL->RL_UpdateAudioRoomTicketAmountsByReference($provider, $ref, $gross, $cur, $fee, $feeCur, $tax, $net);
                }
                if (method_exists($RL, 'RL_UpdateAudioRoomTicketStatusByReference')) {
                    $RL->RL_UpdateAudioRoomTicketStatusByReference($provider, $ref, 'succeeded', $event, (array)$ver);
                }
                if (method_exists($RL, 'RL_CreditRecipientForAudioRoomTicketByReference')) {
                    $RL->RL_CreditRecipientForAudioRoomTicketByReference($provider, $ref);
                }
            }
        } elseif ($scope === 'subscription') {
            if ($ref !== '' && isset($RL)) {
                // Map provider-specific subscription events to internal statuses
                $statusToSet = 'succeeded'; // default for activation/renew success
                if ($provider === 'paypal') {
                    $etype = (string)($ver['raw']['event_type'] ?? '');
                    if ($etype === 'BILLING.SUBSCRIPTION.CANCELLED') { $statusToSet = 'canceled'; }
                    elseif ($etype === 'BILLING.SUBSCRIPTION.EXPIRED') { $statusToSet = 'expired'; }
                    elseif ($etype === 'BILLING.SUBSCRIPTION.SUSPENDED') { $statusToSet = 'suspended'; }
                    elseif ($etype === 'BILLING.SUBSCRIPTION.RE-ACTIVATED' || $etype === 'BILLING.SUBSCRIPTION.ACTIVATED') { $statusToSet = 'succeeded'; }
                    // UPDATED → keep last known status; log event only
                }
                $cancelStatuses = ['canceled', 'cancelled', 'expired', 'suspended'];
                $cancelledAt = in_array($statusToSet, $cancelStatuses, true) ? time() : null;
                if (method_exists($RL, 'RL_UpdateSubscriptionStatusByReference')) {
                    $RL->RL_UpdateSubscriptionStatusByReference($provider, $ref, $statusToSet, $event, (array)$ver, $cancelledAt);
                }
                // Promote to subscriber only on activation/reactivation (best effort)
                $buyer = isset($meta['buyer_id']) ? (int)$meta['buyer_id'] : 0;
                $recip = isset($meta['recipient_id']) ? (int)$meta['recipient_id'] : 0;
                if ($statusToSet === 'succeeded') {
                    if ($buyer > 0 && $recip > 0 && method_exists($RL, 'RL_SetAsSubscriber')) {
                        $RL->RL_SetAsSubscriber($buyer, $recip, time());
                    }
                }
                // Store provider-side subscription id if present
                try {
                    if ($provider === 'stripe') {
                        $session = $ver['raw']['data']['object'] ?? [];
                        $subId = is_array($session) ? (string)($session['subscription'] ?? '') : '';
                        if ($subId !== '' && method_exists($RL, 'RL_UpdateSubscriptionProviderObjectByReference')) {
                            $RL->RL_UpdateSubscriptionProviderObjectByReference('stripe', $ref, $subId);
                        }
                    } elseif ($provider === 'paypal') {
                        $resource = $ver['raw']['resource'] ?? [];
                        $subId = '';
                        if (is_array($resource)) {
                            if (!empty($resource['id'])) { $subId = (string)$resource['id']; }
                            elseif (!empty($resource['billing_agreement_id'])) { $subId = (string)$resource['billing_agreement_id']; }
                        }
                        if ($subId !== '' && method_exists($RL, 'RL_UpdateSubscriptionProviderObjectByReference')) {
                            $RL->RL_UpdateSubscriptionProviderObjectByReference('paypal', $ref, $subId);
                        }
                    }
                } catch (Throwable $__) {}
                // Set started_at and current_period_end
                try {
                    $startedAt = time();
                    $count = isset($meta['interval_count']) ? (int)$meta['interval_count'] : 1;
                    if ($count <= 0) { $count = 1; }
                    $ival = strtolower((string)($meta['interval'] ?? 'monthly'));
                    $next = new DateTime('@' . $startedAt); $next->setTimezone(new DateTimeZone('UTC'));
                    if ($ival === 'weekly' || $ival === 'week') { $next->modify('+' . $count . ' week'); }
                    elseif ($ival === 'yearly' || $ival === 'year') { $next->modify('+' . $count . ' year'); }
                    elseif ($ival === 'halfyear') { $next->modify('+' . (6 * $count) . ' month'); }
                    else { $next->modify('+' . $count . ' month'); }
                    $cpe = $next->getTimestamp();
                    if (method_exists($RL, 'getDb')) {
                        $db = $RL->getDb();
                        $st = $db->prepare("UPDATE i_subscription_payments SET started_at = :sa, current_period_end = :cpe, updated_at = :t WHERE provider = :prov AND reference = :ref");
                        $st->execute([':sa'=>$startedAt, ':cpe'=>$cpe, ':t'=>time(), ':prov'=>$provider, ':ref'=>$ref]);
                    }
                } catch (Throwable $__) {}
                // Notification
                $notifyAmount = $net ?? $gross ?? null;
                if ($buyer > 0 && $recip > 0 && $notifyAmount !== null && method_exists($RL, 'RL_CreateSubscriptionPaymentNotification')) {
                    $RL->RL_CreateSubscriptionPaymentNotification($buyer, $recip, (float)$notifyAmount, (string)($cur ?? ''), $ref);
                }
            }
        } elseif ($scope === 'wallet_topup') {
            if (!isset($RL) || !method_exists($RL, 'RL_CreditWalletTopupByReference') || $ref === '') {
                if (isset($RL) && method_exists($RL, 'logError')) {
                    $RL->logError('wallet_topup_webhook_missing_dependency provider=' . $provider . ' ref=' . $ref);
                }
            } else {
                if (!is_array($walletTopupRow)) {
                    try {
                        $walletTopupRow = $RL->RL_GetWalletTopupByReference($provider, $ref);
                    } catch (Throwable $__) {
                        $walletTopupRow = null;
                    }
                }

                if (!is_array($walletTopupRow)) {
                    if (method_exists($RL, 'logError')) {
                        $RL->logError('wallet_topup_webhook_row_missing provider=' . $provider . ' ref=' . $ref);
                    }
                } else {
                    $rawPayload = is_array($ver['raw'] ?? null) ? $ver['raw'] : [];

                    $amountMinorUpdate = null;
                    $netMinorUpdate = null;
                    $feeMinorUpdate = null;
                    $taxMinorUpdate = null;

                    if ($provider === 'stripe' && is_array($rawPayload)) {
                        $session = $rawPayload['data']['object'] ?? [];
                        if (isset($session['amount_total'])) {
                            $amountMinorUpdate = (int) $session['amount_total'];
                        }
                        if (isset($session['total_details']['amount_tax'])) {
                            $taxMinorUpdate = (int) $session['total_details']['amount_tax'];
                        }
                        if (isset($session['amount_total']) && $taxMinorUpdate !== null) {
                            $netMinorUpdate = (int) $session['amount_total'] - $taxMinorUpdate;
                        }
                    } elseif ($provider === 'paypal') {
                        $res = $rawPayload['resource'] ?? [];
                        $srb = $res['seller_receivable_breakdown'] ?? [];
                        $grossVal = $srb['gross_amount']['value'] ?? null;
                        $netVal = $srb['net_amount']['value'] ?? null;
                        $feeVal = $srb['paypal_fee']['value'] ?? null;
                        $taxVal = $res['transaction_fee']['value'] ?? null;
                        $convertMinor = static function ($value): ?int {
                            if ($value === null || $value === '') {
                                return null;
                            }
                            if (!is_numeric($value)) {
                                return null;
                            }
                            return (int) round(((float) $value) * 100);
                        };
                        $amountMinorUpdate = $convertMinor($grossVal) ?? $amountMinorUpdate;
                        $netMinorUpdate = $convertMinor($netVal) ?? $netMinorUpdate;
                        $feeMinorUpdate = $convertMinor($feeVal) ?? $feeMinorUpdate;
                        $taxMinorUpdate = $convertMinor($taxVal) ?? $taxMinorUpdate;
    } elseif ($provider === 'coinbase' || $provider === 'coinbase_commerce') {
        $cbFiatCurrency = '';
                        if (isset($coinbaseFiatCurrency) && $coinbaseFiatCurrency !== '') {
                            $cbFiatCurrency = strtoupper((string) $coinbaseFiatCurrency);
                        }
                        $cbFiatAmount = '';
                        if (isset($coinbaseFiatAmount) && $coinbaseFiatAmount !== '') {
                            $cbFiatAmount = (string) $coinbaseFiatAmount;
                        }
                        $cbPayCurrency = '';
                        if (isset($coinbasePayCurrency) && $coinbasePayCurrency !== '') {
                            $cbPayCurrency = strtoupper((string) $coinbasePayCurrency);
                        }
                        $cbPayAmount = '';
                        if (isset($coinbasePayAmount) && $coinbasePayAmount !== '') {
                            $cbPayAmount = (string) $coinbasePayAmount;
                        }
                        $precisionCurrency = $cbFiatCurrency;
                        if ($precisionCurrency === '' && is_array($walletTopupRow) && !empty($walletTopupRow['currency'])) {
                            $precisionCurrency = strtoupper((string) $walletTopupRow['currency']);
                        }
                        $pricingLocal = $rawPayload['event']['data']['pricing']['local'] ?? [];
                        if ($precisionCurrency === '' && isset($pricingLocal['currency']) && $pricingLocal['currency'] !== '') {
                            $precisionCurrency = strtoupper((string) $pricingLocal['currency']);
                        }
                        if ($precisionCurrency === '' && $cbPayCurrency !== '') {
                            $precisionCurrency = $cbPayCurrency;
                        }
                        $cbPrecision = $resolvePrecision($provider, $precisionCurrency);
                        if ($cbPrecision < 0) { $cbPrecision = 0; }
                        $cbFactor = (int) round(pow(10, $cbPrecision));
                        if ($cbFactor <= 0) { $cbFactor = 100; }
                        $amountSource = null;
                        if ($cbFiatAmount !== '' && is_numeric($cbFiatAmount)) {
                            $amountSource = (float) $cbFiatAmount;
                            if ($cbFiatCurrency !== '') {
                                $cur = $cbFiatCurrency;
                            }
                        } elseif (isset($pricingLocal['amount']) && is_numeric($pricingLocal['amount'])) {
                            $amountSource = (float) $pricingLocal['amount'];
                            if ($cbFiatCurrency === '' && isset($pricingLocal['currency']) && $pricingLocal['currency'] !== '') {
                                $cur = strtoupper((string) $pricingLocal['currency']);
                            }
                        }
                        if ($amountSource !== null) {
                            $amountMinorUpdate = (int) round($amountSource * $cbFactor);
                            $netMinorUpdate = $amountMinorUpdate;
                        } else {
                            $fallbackAmount = null;
                            $fallbackCurrency = $cbPayCurrency;
                            if ($cbPayAmount !== '' && is_numeric($cbPayAmount)) {
                                $fallbackAmount = (float) $cbPayAmount;
                            } else {
                                $paymentEntries = $rawPayload['event']['data']['payments'] ?? [];
                                if (is_array($paymentEntries)) {
                                    foreach ($paymentEntries as $paymentEntry) {
                                        if (!is_array($paymentEntry)) {
                                            continue;
                                        }
                                        $valueCandidate = null;
                                        if (isset($paymentEntry['net']) && is_array($paymentEntry['net'])) {
                                            $valueCandidate = $paymentEntry['net'];
                                        } elseif (isset($paymentEntry['value']) && is_array($paymentEntry['value'])) {
                                            $valueCandidate = $paymentEntry['value'];
                                        }
                                        if (is_array($valueCandidate) && isset($valueCandidate['amount']) && is_numeric($valueCandidate['amount'])) {
                                            $fallbackAmount = (float) $valueCandidate['amount'];
                                            $fallbackCurrency = strtoupper((string)($valueCandidate['crypto'] ?? $fallbackCurrency));
                                        }
                                    }
                                }
                            }
                            if ($fallbackAmount !== null) {
                                $fallbackPrecision = $resolvePrecision($provider, $fallbackCurrency !== '' ? $fallbackCurrency : $precisionCurrency);
                                if ($fallbackPrecision < 0) { $fallbackPrecision = 0; }
                                $fallbackFactor = (int) round(pow(10, $fallbackPrecision));
                                if ($fallbackFactor <= 0) { $fallbackFactor = 100; }
                                $amountMinorUpdate = (int) round($fallbackAmount * $fallbackFactor);
                                $netMinorUpdate = $amountMinorUpdate;
                                if (($cur === '' || $cur === null) && $fallbackCurrency !== '') {
                                    $cur = $fallbackCurrency;
                                }
                            }
                        }
                        $logCoinbaseTrace(
                            'amount_convert precision=' . $cbPrecision
                            . ' factor=' . $cbFactor
                            . ' currency=' . ($precisionCurrency !== '' ? $precisionCurrency : 'n/a')
                            . ' fiat_amount=' . ($cbFiatAmount !== '' ? $cbFiatAmount : 'n/a')
                            . ' pay_amount=' . ($cbPayAmount !== '' ? $cbPayAmount : 'n/a')
                        );
                    } elseif ($provider === 'nowpayments') {
                        $precisionCurrency = '';
                        if ($metaFiatCurrency !== '') {
                            $precisionCurrency = $metaFiatCurrency;
                        } elseif ($priceCurrency !== '') {
                            $precisionCurrency = $priceCurrency;
                        } elseif (is_array($walletTopupRow) && !empty($walletTopupRow['currency'])) {
                            $precisionCurrency = (string) $walletTopupRow['currency'];
                        } elseif ($payCurrency !== '') {
                            $precisionCurrency = $payCurrency;
                        }
                        $npPrecision = $resolvePrecision($provider, $precisionCurrency);
                        if ($npPrecision < 0) { $npPrecision = 0; }
                        $npFactor = (int) round(pow(10, $npPrecision));
                        if ($npFactor <= 0) { $npFactor = 100; }
                        $logNowpaymentsTrace(
                            'amount_convert precision=' . $npPrecision
                            . ' factor=' . $npFactor
                            . ' currency=' . ($precisionCurrency !== '' ? $precisionCurrency : 'n/a')
                            . ' price_amount=' . ($priceAmount !== '' ? $priceAmount : 'n/a')
                            . ' actually_paid=' . ($actuallyPaid !== '' ? $actuallyPaid : 'n/a')
                            . ' meta_fiat_amount=' . ($metaFiatAmount !== '' ? $metaFiatAmount : 'n/a')
                        );
                        $amountSource = null;
                        if ($metaFiatAmount !== '' && is_numeric($metaFiatAmount)) {
                            $amountSource = (float) $metaFiatAmount;
                        } elseif ($priceAmount !== '' && is_numeric($priceAmount)) {
                            $amountSource = (float) $priceAmount;
                        }

                        if ($amountSource !== null) {
                            $amountMinorUpdate = (int) round($amountSource * $npFactor);
                            $netMinorUpdate = $amountMinorUpdate;
                        } else {
                            $fallbackValue = null;
                            if ($payAmount !== '' && is_numeric($payAmount)) {
                                $fallbackValue = (float) $payAmount;
                            } elseif ($actuallyPaid !== '' && is_numeric($actuallyPaid)) {
                                $fallbackValue = (float) $actuallyPaid;
                            }
                            if ($fallbackValue !== null) {
                                $fallbackPrecision = $resolvePrecision($provider, $payCurrency !== '' ? $payCurrency : $precisionCurrency);
                                if ($fallbackPrecision < 0) { $fallbackPrecision = 0; }
                                $fallbackFactor = (int) round(pow(10, $fallbackPrecision));
                                if ($fallbackFactor <= 0) { $fallbackFactor = 100; }
                                $amountMinorUpdate = (int) round($fallbackValue * $fallbackFactor);
                                $netMinorUpdate = $amountMinorUpdate;
                                if ($payCurrency !== '') {
                                    $precisionCurrency = $payCurrency;
                                    if ($cur === '') {
                                        $cur = $payCurrency;
                                    }
                                }
                            }
                        }
                    }

                    if ($amountMinorUpdate === null && $gross !== null) {
                        $amountMinorUpdate = (int) round(((float) $gross) * 100);
                    }
                    if ($netMinorUpdate === null && $net !== null) {
                        $netMinorUpdate = (int) round(((float) $net) * 100);
                    }
                    if ($feeMinorUpdate === null && $fee !== null) {
                        $feeMinorUpdate = (int) round(((float) $fee) * 100);
                    }
                    if ($taxMinorUpdate === null && $tax !== null) {
                        $taxMinorUpdate = (int) round(((float) $tax) * 100);
                    }

                    $currencyCode = $cur !== '' ? $cur : ($walletTopupRow['currency'] ?? ($metaCurrencyFallback !== '' ? $metaCurrencyFallback : ''));
                    if (is_string($currencyCode) && $currencyCode !== '') {
                        $currencyCode = strtoupper($currencyCode);
                    } else {
                        $currencyCode = (string) ($walletTopupRow['currency'] ?? '');
                    }

                    if (method_exists($RL, 'RL_UpdateWalletTopupAmountsByReference')) {
                        $amountsUpdated = $RL->RL_UpdateWalletTopupAmountsByReference(
                            $provider,
                            $ref,
                            $amountMinorUpdate,
                            $feeMinorUpdate,
                            $taxMinorUpdate,
                            $netMinorUpdate,
                            $currencyCode !== '' ? $currencyCode : null
                        );
                        if ($amountsUpdated === false && method_exists($RL, 'logError')) {
                            $RL->logError('wallet_topup_amount_update_failed provider=' . $provider . ' ref=' . $ref);
                        }
                    }

                    $statusUpdated = false;
                    if (method_exists($RL, 'RL_UpdateWalletTopupStatusByReference')) {
                        $statusUpdated = $RL->RL_UpdateWalletTopupStatusByReference(
                            $provider,
                            $ref,
                            'succeeded',
                            $event,
                            (array) $ver
                        );
                        if (!$statusUpdated && method_exists($RL, 'logError')) {
                            $RL->logError('wallet_topup_status_update_failed provider=' . $provider . ' ref=' . $ref);
                        }
                    }

                    if (!$statusUpdated && isset($RL) && method_exists($RL, 'getDb')) {
                        try {
                            $db = $RL->getDb();
                            $fallbackStmt = $db->prepare(
                                "UPDATE i_wallet_topups
                                 SET status = :st,
                                     event = :ev,
                                     updated_at = :t
                                 WHERE provider = :prov AND reference = :ref"
                            );
                            $fallbackStmt->execute([
                                ':st' => 'succeeded',
                                ':ev' => $event,
                                ':t' => time(),
                                ':prov' => $provider,
                                ':ref' => $ref,
                            ]);
                            $statusUpdated = $fallbackStmt->rowCount() > 0 || $statusUpdated;
                        } catch (Throwable $fallbackError) {
                            if (method_exists($RL, 'logError')) {
                                $RL->logError(
                                    'wallet_topup_status_fallback_exception provider='
                                    . $provider . ' ref=' . $ref . ' error=' . $fallbackError->getMessage()
                                );
                            }
                        }
                    }

                    if (method_exists($RL, 'RL_GetWalletTopupByReference')) {
                        try {
                            $latestTopup = $RL->RL_GetWalletTopupByReference($provider, $ref);
                        } catch (Throwable $__) {
                            $latestTopup = null;
                        }
                        if (is_array($latestTopup)) {
                            $walletTopupRow = $latestTopup;
                            if ($currencyCode === '') {
                                $currencyCode = (string) ($walletTopupRow['currency'] ?? '');
                            }
                        }
                    }

                    $currentStatus = strtolower((string) ($walletTopupRow['status'] ?? ''));
                    $successStatuses = ['succeeded', 'completed', 'approved'];
                    $canCredit = in_array($currentStatus, $successStatuses, true);

                    if (!$canCredit && method_exists($RL, 'logError')) {
                        $RL->logError(
                            'wallet_topup_status_not_ready provider='
                            . $provider . ' ref=' . $ref . ' status=' . ($walletTopupRow['status'] ?? 'unknown')
                        );
                    }

                    $credited = false;
                    if ($canCredit) {
                        try {
                            $credited = $RL->RL_CreditWalletTopupByReference($provider, $ref);
                        } catch (Throwable $creditError) {
                            $credited = false;
                            if (method_exists($RL, 'logError')) {
                                $RL->logError('wallet_topup_credit_exception provider=' . $provider . ' ref=' . $ref . ' error=' . $creditError->getMessage());
                            }
                        }

                        if (!$credited && method_exists($RL, 'logError')) {
                            $RL->logError('wallet_topup_credit_failed provider=' . $provider . ' ref=' . $ref);
                        }
                    }

                    if ($credited && method_exists($RL, 'RL_GetWalletTopupByReference')) {
                        try {
                            $postCreditRow = $RL->RL_GetWalletTopupByReference($provider, $ref);
                            if (is_array($postCreditRow)) {
                                $walletTopupRow = $postCreditRow;
                            }
                        } catch (Throwable $__) {
                            // ignore refresh errors; use last known row
                        }
                    }

                    if ($credited && method_exists($RL, 'RL_CreateWalletTopupNotification')) {
                        $notificationMinor = $netMinorUpdate
                            ?? $amountMinorUpdate
                            ?? (int) ($walletTopupRow['net_minor'] ?? 0);
                        if ($notificationMinor <= 0) {
                            $notificationMinor = (int) ($walletTopupRow['amount_minor'] ?? 0);
                        }
                        $notificationAmount = $notificationMinor > 0 ? ($notificationMinor / 100) : null;
                        $userId = (int) ($walletTopupRow['user_id'] ?? 0);
                        $notificationCurrency = $currencyCode !== ''
                            ? $currencyCode
                            : (string) ($walletTopupRow['currency'] ?? '');

                        if ($userId > 0 && $notificationAmount !== null) {
                            try {
                                $RL->RL_CreateWalletTopupNotification($userId, (float) $notificationAmount, $notificationCurrency, $ref);
                            } catch (Throwable $notifyError) {
                                if (method_exists($RL, 'logError')) {
                                    $RL->logError('wallet_topup_notification_failed ref=' . $ref . ' error=' . $notifyError->getMessage());
                                }
                            }
                        }
                    }
                }
            }
        } else {
            // Unknown scope; best-effort: try both updates (safe no-ops if not found)
            if ($ref !== '' && isset($RL)) {
                if (method_exists($RL, 'RL_UpdateAdPaymentStatusByReference')) { $RL->RL_UpdateAdPaymentStatusByReference($provider, $ref, 'succeeded', $event, (array)$ver); }
                if (method_exists($RL, 'RL_UpdateTipPaymentStatusByReference')) { $RL->RL_UpdateTipPaymentStatusByReference($provider, $ref, 'succeeded', $event, (array)$ver); }
            }
        }
    }
    elseif ($provider === 'flutterwave') {
        $data = isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : [];
        if (isset($data['amount']) && is_numeric($data['amount'])) {
            $gross = (float)$data['amount'];
        }
        if (isset($data['currency'])) {
            $cur = strtoupper((string)$data['currency']);
        }
        if (isset($data['app_fee']) && is_numeric($data['app_fee'])) {
            $fee = (float)$data['app_fee'];
            $feeCur = $cur;
        }
        if (isset($data['charged_amount']) && is_numeric($data['charged_amount'])) {
            $chargedAmount = (float)$data['charged_amount'];
            if ($fee !== null) {
                $net = $chargedAmount - (float)$fee;
            } else {
                $net = $chargedAmount;
            }
        }
    }

    // Handle non-payment_success subscription lifecycle events
    if ($provider === 'paypal') {
        $rawArr = isset($ver['raw']) && is_array($ver['raw']) ? $ver['raw'] : [];
        $type   = isset($rawArr['event_type']) ? (string)$rawArr['event_type'] : '';
        if (strpos($type, 'BILLING.SUBSCRIPTION.') === 0) {
            $statusToSet = null;
            if ($type === 'BILLING.SUBSCRIPTION.CANCELLED') { $statusToSet = 'canceled'; }
            elseif ($type === 'BILLING.SUBSCRIPTION.EXPIRED') { $statusToSet = 'expired'; }
            elseif ($type === 'BILLING.SUBSCRIPTION.SUSPENDED') { $statusToSet = 'suspended'; }
            elseif ($type === 'BILLING.SUBSCRIPTION.RE-ACTIVATED' || $type === 'BILLING.SUBSCRIPTION.ACTIVATED') { $statusToSet = 'succeeded'; }
            if ($statusToSet !== null && $ref !== '' && isset($RL)) {
                $cancelStatuses = ['canceled', 'cancelled', 'expired', 'suspended'];
                $forceDowngradeStatuses = ['expired', 'suspended'];
                $cancelledAt = in_array($statusToSet, $cancelStatuses, true) ? time() : null;

                if (method_exists($RL, 'RL_UpdateSubscriptionStatusByReference')) {
                    $RL->RL_UpdateSubscriptionStatusByReference('paypal', $ref, $statusToSet, $event, (array)$ver, $cancelledAt);
                }

                if (in_array($statusToSet, $forceDowngradeStatuses, true)) {
                    $buyer = isset($meta['buyer_id']) ? (int)$meta['buyer_id'] : 0;
                    $recip = isset($meta['recipient_id']) ? (int)$meta['recipient_id'] : 0;
                    if ($buyer > 0 && $recip > 0 && method_exists($RL, 'RL_CancelSubscription')) {
                        $RL->RL_CancelSubscription($buyer, $recip, time(), true);
                    }
                }
            }
        }
    }
    if ($provider === 'stripe') {
        $rawArr = isset($ver['raw']) && is_array($ver['raw']) ? $ver['raw'] : [];
        $type   = isset($rawArr['type']) ? (string)$rawArr['type'] : '';
        if ($type === 'customer.subscription.deleted' || $type === 'customer.subscription.updated') {
            $obj = $rawArr['data']['object'] ?? [];
            $subId = is_array($obj) ? (string)($obj['id'] ?? '') : '';
            $stripeStatus = is_array($obj) ? (string)($obj['status'] ?? '') : '';
            $inactiveStatuses = ['canceled', 'unpaid', 'incomplete_expired'];
            $statusToSet = $stripeStatus !== '' ? $stripeStatus : ($type === 'customer.subscription.deleted' ? 'canceled' : 'succeeded');
            if ($type === 'customer.subscription.deleted' && $statusToSet === 'succeeded') {
                $statusToSet = 'canceled';
            }
            $cancelledAt = in_array($statusToSet, $inactiveStatuses, true) ? time() : null;
            $forceDowngrade = ($type === 'customer.subscription.deleted') || in_array($statusToSet, ['unpaid', 'incomplete_expired'], true);

            if ($subId !== '' && isset($RL)) {
                if (method_exists($RL, 'RL_UpdateSubscriptionStatusByProviderObject')) {
                    $RL->RL_UpdateSubscriptionStatusByProviderObject('stripe', $subId, $statusToSet, $event, (array)$ver, $cancelledAt);
                }
                if ($forceDowngrade && method_exists($RL, 'RL_GetSubscriptionByProviderObject') && method_exists($RL, 'RL_CancelSubscription')) {
                    $row = $RL->RL_GetSubscriptionByProviderObject('stripe', $subId);
                    if (is_array($row)) {
                        $buyer = (int)($row['buyer_id'] ?? 0);
                        $recip = (int)($row['recipient_id'] ?? 0);
                        if ($buyer > 0 && $recip > 0) { $RL->RL_CancelSubscription($buyer, $recip, time(), true); }
                    }
                }
            }
        }
    }

    if ($provider === 'flutterwave') {
        $rawArr = isset($ver['raw']) && is_array($ver['raw']) ? $ver['raw'] : [];
        $type   = strtolower((string)($rawArr['event'] ?? ''));
        if (str_starts_with($type, 'subscription.') && $ref !== '') {
            $statusToSet = null;
            if (in_array($type, ['subscription.cancelled', 'subscription.terminated'], true)) {
                $statusToSet = 'canceled';
            } elseif (in_array($type, ['subscription.renewed', 'subscription.activated', 'subscription.completed'], true)) {
                $statusToSet = 'succeeded';
            }
            if ($statusToSet !== null && isset($RL) && method_exists($RL, 'RL_UpdateSubscriptionStatusByReference')) {
                $cancelledAt = in_array($type, ['subscription.cancelled', 'subscription.terminated'], true) ? time() : null;
                $RL->RL_UpdateSubscriptionStatusByReference('flutterwave', $ref, $statusToSet, $event, (array)$ver, $cancelledAt);
            }
        }
    }

    if ($provider === 'iyzico') {
        $rawArr = isset($ver['raw']) && is_array($ver['raw']) ? $ver['raw'] : [];
        $type   = strtolower((string)($rawArr['iyziEventType'] ?? ($rawArr['eventType'] ?? ($rawArr['event'] ?? ''))));
        if (str_starts_with($type, 'subscription.') && $ref !== '') {
            $statusToSet = null;
            if (in_array($type, ['subscription.cancelled', 'subscription.canceled', 'subscription.terminated'], true)) {
                $statusToSet = 'canceled';
            } elseif (in_array($type, ['subscription.renewed', 'subscription.activated', 'subscription.completed'], true)) {
                $statusToSet = 'succeeded';
            }
            if ($statusToSet !== null && isset($RL) && method_exists($RL, 'RL_UpdateSubscriptionStatusByReference')) {
                $cancelledAt = in_array($statusToSet, ['canceled', 'cancelled'], true) ? time() : null;
                $RL->RL_UpdateSubscriptionStatusByReference('iyzico', $ref, $statusToSet, $event, (array)$ver, $cancelledAt);
            }
        }
    }

    echo json_encode(['status' => 'ok']);
    exit;
} catch (Throwable $e) {
    $locationInfo = $e->getFile() . ':' . $e->getLine();
    $logFunctionError(
        'location=exception_handler provider='
        . (isset($provider) && $provider !== '' ? $provider : 'unknown')
        . ' error=' . $e->getMessage()
        . ' at=' . $locationInfo
    );
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'server_error', 'message_text' => customLang('server_error')]);
    exit;
}
