<?php

include_once 'includes/inc.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_COOKIE[$cookieName])) {
    $hashCookie = $_COOKIE[$cookieName];

    try {
        // Cookie hash verification
        $stmt = $db->prepare("SELECT * FROM i_sessions WHERE session_key = :session_key");
        $stmt->bindValue(':session_key', $hashCookie, PDO::PARAM_STR);
        $stmt->execute();

        $getDetail = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($getDetail) {
            $theLoginUserID = $getDetail['session_uid'];
            $theLoginHash   = $getDetail['session_key'];

            // Clear cookies securely
            if (function_exists('clearSecureCookie')) {
                clearSecureCookie($cookieName);
                clearSecureCookie('fb_access_token');
            } else {
                setcookie($cookieName, '', time() - 31556926, '/');
                setcookie('fb_access_token', '', time() - 3600, '/');
            }
            unset($_COOKIE[$cookieName]);

            if (isset($RL) && method_exists($RL, 'RL_RevokeSession')) {
                $RL->RL_RevokeSession((int) $theLoginUserID, (string) $theLoginHash);
            } else {
                $deleteStmt = $db->prepare("DELETE FROM i_sessions WHERE session_key = :session_key");
                $deleteStmt->bindValue(':session_key', $theLoginHash, PDO::PARAM_STR);
                $deleteStmt->execute();
                if (isset($db) && $db instanceof PDO) {
                    try {
                        $metaStmt = $db->prepare('DELETE FROM i_session_devices WHERE session_key = :session_key');
                        $metaStmt->bindValue(':session_key', $theLoginHash, PDO::PARAM_STR);
                        $metaStmt->execute();
                    } catch (Throwable $__) {
                    }
                }
            }

            session_destroy();

            header("Location: $base_url");
            exit;
        } else {
            // Clear cookies when invalid hash
            if (function_exists('clearSecureCookie')) {
                clearSecureCookie($cookieName);
                clearSecureCookie('fb_access_token');
            } else {
                setcookie($cookieName, '', time() - 31556926, '/');
                setcookie('fb_access_token', '', time() - 3600, '/');
            }
            unset($_COOKIE[$cookieName]);

            $deleteStmt = $db->prepare("DELETE FROM i_sessions WHERE session_key = :session_key");
            $deleteStmt->bindValue(':session_key', $hashCookie, PDO::PARAM_STR);
            $deleteStmt->execute();
            try {
                $metaStmt = $db->prepare('DELETE FROM i_session_devices WHERE session_key = :session_key');
                $metaStmt->bindValue(':session_key', $hashCookie, PDO::PARAM_STR);
                $metaStmt->execute();
            } catch (Throwable $__) {
            }

            session_destroy();

            header("Location: $base_url");
            exit;
        }
    } catch (PDOException $e) {
        // Display error details in developer mode
        if (APP_DEBUG === true) {
            die('PDO Error: ' . htmlspecialchars($e->getMessage()));
        }

        header("Location: $base_url");
        exit;
    }
} else {
    // No cookie; still perform clean logout
    if (function_exists('clearSecureCookie')) {
        clearSecureCookie($cookieName);
        clearSecureCookie('fb_access_token');
    } else {
        setcookie($cookieName, '', time() - 31556926, '/');
        setcookie('fb_access_token', '', time() - 3600, '/');
    }
    unset($_COOKIE[$cookieName]);

    session_destroy();

    header("Location: $base_url");
    exit;
}
