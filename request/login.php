<?php
header('Content-Type: application/json');
include "../includes/inc.php";

$languageCode = 'eng';
if (isset($currentLang) && is_string($currentLang) && trim($currentLang) !== '') {
    $languageCode = trim($currentLang);
}

$translate = static function (string $key) use ($RL, $languageCode) {
    $word = $RL->RL_GetWord($languageCode, $key);
    if ($word === 'no' || $word === '') {
        $fallback = customLang($key);
        return $fallback !== '' ? $fallback : $key;
    }
    return $word;
};

if ($loggedIn === '0') {
    if (isset($_POST['my_username'], $_POST['lg_password'], $_POST['csrf_token'])) {
        $username = trim($_POST['my_username']);
        $password = $_POST['lg_password'];
        $csrfToken = $_POST['csrf_token'];

        if (!checkCsrfToken($csrfToken)) {
            echo json_encode(['status' => 'error', 'message' => 'invalid_csrf', 'message_text' => customLang('invalid_csrf_token')]);
            exit;
        }

        if (is_value_empty($username) || is_value_empty($password)) {
            echo json_encode(['status' => 'error', 'message' => $translate('please_fill_all_fields')]);
            exit;
        }

        try {
            $stmt = $db->prepare("SELECT * FROM i_users WHERE username = :username OR user_email = :useremail");
            $stmt->bindValue(':username', $username, PDO::PARAM_STR);
            $stmt->bindValue(':useremail', $username, PDO::PARAM_STR);
            $stmt->execute();

            $uData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($uData) {
                if (password_verify($password, $uData['user_password'])) {
                    $userID     = $uData['user_id'];
                    $time       = time();
                    // session_key column is VARCHAR(64); use 64-char hex token
                    $secureHash = bin2hex(random_bytes(32));

                    $updateStmt = $db->prepare("UPDATE i_users SET last_login_time = :time WHERE user_id = :user_id");
                    $updateStmt->bindValue(':time', $time, PDO::PARAM_INT);
                    $updateStmt->bindValue(':user_id', $userID, PDO::PARAM_INT);
                    $updateStmt->execute();

                    // Set secure cookie with proper flags
                    if (function_exists('setSecureCookie')) {
                        setSecureCookie($cookieName, $secureHash, 31556926);
                    } else {
                        setcookie($cookieName, $secureHash, time() + 31556926, '/');
                    }

                    $sessionInsert = $db->prepare("INSERT INTO i_sessions (session_uid, session_key, session_time) VALUES (:uid, :hash, :stime)");
                    $sessionInsert->bindValue(':uid', $userID, PDO::PARAM_INT);
                    $sessionInsert->bindValue(':hash', $secureHash, PDO::PARAM_STR);
                    $sessionInsert->bindValue(':stime', $time, PDO::PARAM_INT);
                    $sessionInsert->execute();

                    $_SESSION['iuid'] = $userID;

                    if (isset($RL) && method_exists($RL, 'RL_TouchSessionDevice')) {
                        $RL->RL_TouchSessionDevice($userID, $secureHash, [
                            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                        ]);
                    }

                    echo json_encode(['status' => 'success']);
                    exit;
                } else {
                    echo json_encode(['status' => 'error', 'message' => $translate('incorrect_password')]);
                    exit;
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => $translate('user_not_found')]);
                exit;
            }
        } catch (PDOException $e) {
            $error = APP_DEBUG === true ? 'PDO Error: ' . htmlspecialchars($e->getMessage()) : 'Database error';
            echo json_encode(['status' => 'error', 'message' => $error]);
            exit;
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => $translate('please_fill_all_fields')]);
        exit;
    }
}
