<?php
/**
 * Sistema di autenticazione e gestione sessioni
 */

require_once 'config.php';

/**
 * Verifica se l'utente è autenticato
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Verifica se l'utente è admin
 */
function isAdmin() {
    return isLoggedIn() && $_SESSION['user_role'] === 'admin';
}

/**
 * Verifica se l'utente è commerciale
 */
function isCommerciale() {
    return isLoggedIn() && $_SESSION['user_role'] === 'commerciale';
}

function requireCommerciale() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
    
    // Sia commerciali che admin possono accedere
    if (!isCommerciale() && !isAdmin()) {
        header('Location: index.php?error=access_denied');
        exit;
    }
}

/**
 * Richiede autenticazione - redirect a login se non autenticato
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /index.php?error=login_required');
        exit;
    }
}

/**
 * Richiede ruolo admin
 */
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: /dashboard.php?error=access_denied');
        exit;
    }
}

/**
 * Login utente
 */
function login($username, $password) {
    $db = getDBConnection();
    
    // Escape input per sicurezza
    $username = mysqli_real_escape_string($db, $username);
    
    $query = "SELECT * FROM users WHERE username = '$username' AND is_active = 1";
    $result = mysqli_query($db, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);
        
        if (password_verify($password, $user['password'])) {
            // Imposta sessione
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
            
            // Aggiorna ultimo login
            $updateQuery = "UPDATE users SET last_login = NOW() WHERE id = " . $user['id'];
            mysqli_query($db, $updateQuery);
            
            return true;
        }
    }
    
    return false;
}

/**
 * Logout utente
 */
function logout() {
    session_unset();
    session_destroy();
    header('Location: /index.php');
    exit;
}

/**
 * Ottieni dati utente corrente
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $db = getDBConnection();
    $userId = $_SESSION['user_id'];
    
    $query = "SELECT * FROM users WHERE id = $userId";
    $result = mysqli_query($db, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        return mysqli_fetch_assoc($result);
    }
    
    return null;
}
?>