<?php
session_start();
require_once 'config.php';
require_once 'auth.php';

// Gestione logout - PRIMA di tutto
if (isset($_GET['logout'])) {
    // Log del logout prima di effettuarlo
    if (isLoggedIn()) {
        require_once 'functions.php';
        $db = getDBConnection();
        
        // Log logout nella tabella quote_history
        $user_id = $_SESSION['user_id'];
        $username = mysqli_real_escape_string($db, $_SESSION['username']);
        $ip_address = mysqli_real_escape_string($db, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $user_agent = mysqli_real_escape_string($db, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500));
        
        $changes_json = mysqli_real_escape_string($db, json_encode([
            'message' => 'Logout effettuato',
            'session_duration' => isset($_SESSION['login_time']) ? (time() - $_SESSION['login_time']) : 'unknown',
            'pages_visited' => $_SESSION['pages_visited'] ?? 0
        ]));
        
        $logout_query = "INSERT INTO quote_history 
                         (quote_id, user_id, action, changes, ip_address, user_agent, created_at) 
                         VALUES 
                         (0, $user_id, 'user_logout', '$changes_json', '$ip_address', '$user_agent', NOW())";
        mysqli_query($db, $logout_query);
    }
    
    logout();
    header('Location: index.php?logout_success=1');
    exit;
}

// Se già loggato, redirect a dashboard
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';

// Mostra messaggio logout riuscito
if (isset($_GET['logout_success'])) {
    $success = 'Logout effettuato con successo';
}

// Gestione login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    require_once 'functions.php';
    $db = getDBConnection();
    
    // Prepara dati per il logging
    $ip_address = mysqli_real_escape_string($db, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $user_agent = mysqli_real_escape_string($db, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500));
    $attempted_username = mysqli_real_escape_string($db, $username);
    
    if (empty($username) || empty($password)) {
        $error = 'Inserisci username e password';
        
        // Log tentativo di login con campi vuoti
        $changes_json = mysqli_real_escape_string($db, json_encode([
            'reason' => 'Empty fields',
            'message' => 'Tentativo di login con campi vuoti',
            'attempted_username' => $username,
            'timestamp' => date('Y-m-d H:i:s')
        ]));
        
        $login_fail_query = "INSERT INTO quote_history 
                            (quote_id, user_id, action, changes, ip_address, user_agent, created_at) 
                            VALUES 
                            (0, NULL, 'user_login_failed', '$changes_json', '$ip_address', '$user_agent', NOW())";
        mysqli_query($db, $login_fail_query);
        
    } else {
        if (login($username, $password)) {
            // Login riuscito - Log successo
            $user_id = $_SESSION['user_id'];
            
            // Salva il timestamp del login per calcolare la durata della sessione
            $_SESSION['login_time'] = time();
            $_SESSION['pages_visited'] = 0;
            
            $changes_json = mysqli_real_escape_string($db, json_encode([
                'message' => 'Login effettuato con successo',
                'user_role' => $_SESSION['role'] ?? 'user',
                'session_id' => substr(session_id(), 0, 10),
                'login_timestamp' => date('Y-m-d H:i:s'),
                'browser' => getBrowserInfo($_SERVER['HTTP_USER_AGENT'] ?? '')
            ]));
            
            $login_success_query = "INSERT INTO quote_history 
                                   (quote_id, user_id, action, changes, ip_address, user_agent, created_at) 
                                   VALUES 
                                   (0, $user_id, 'user_login_success', '$changes_json', '$ip_address', '$user_agent', NOW())";
            mysqli_query($db, $login_success_query);
            
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Credenziali non valide';
            
            // Login fallito - Log tentativo non riuscito
            $changes_json = mysqli_real_escape_string($db, json_encode([
                'reason' => 'Invalid credentials',
                'message' => 'Tentativo di login con credenziali non valide',
                'attempted_username' => $username,
                'timestamp' => date('Y-m-d H:i:s'),
                'failed_attempts' => getFailedAttempts($db, $ip_address) + 1
            ]));
            
            $login_fail_query = "INSERT INTO quote_history 
                                (quote_id, user_id, action, changes, ip_address, user_agent, created_at) 
                                VALUES 
                                (0, NULL, 'user_login_failed', '$changes_json', '$ip_address', '$user_agent', NOW())";
            mysqli_query($db, $login_fail_query);
        }
    }
}

// Mostra errore da query string
if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'login_required':
            $error = 'Devi effettuare il login per accedere';
            break;
        case 'access_denied':
            $error = 'Accesso negato: permessi insufficienti';
            break;
        case 'session_expired':
            $error = 'Sessione scaduta, effettua nuovamente il login';
            break;
    }
    
    // Log degli errori di accesso
    if (!empty($error)) {
        require_once 'functions.php';
        $db = getDBConnection();
        
        $ip_address = mysqli_real_escape_string($db, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $user_agent = mysqli_real_escape_string($db, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500));
        
        $changes_json = mysqli_real_escape_string($db, json_encode([
            'error_type' => $_GET['error'],
            'message' => $error,
            'requested_page' => $_SERVER['HTTP_REFERER'] ?? 'unknown',
            'timestamp' => date('Y-m-d H:i:s')
        ]));
        
        $access_error_query = "INSERT INTO quote_history 
                              (quote_id, user_id, action, changes, ip_address, user_agent, created_at) 
                              VALUES 
                              (0, NULL, 'user_access_error', '$changes_json', '$ip_address', '$user_agent', NOW())";
        mysqli_query($db, $access_error_query);
    }
}

// Funzioni helper
function getBrowserInfo($userAgent) {
    if (strpos($userAgent, 'Chrome') !== false) return 'Chrome';
    if (strpos($userAgent, 'Firefox') !== false) return 'Firefox';
    if (strpos($userAgent, 'Safari') !== false) return 'Safari';
    if (strpos($userAgent, 'Edge') !== false) return 'Edge';
    return 'Unknown';
}

function getFailedAttempts($db, $ip) {
    $ip = mysqli_real_escape_string($db, $ip);
    $query = "SELECT COUNT(*) as count FROM quote_history 
              WHERE action = 'user_login_failed' 
              AND ip_address = '$ip' 
              AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)";
    $result = mysqli_query($db, $query);
    $row = mysqli_fetch_assoc($result);
    return $row['count'] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .login-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .logo-icon {
            font-size: 3rem;
            color: #667eea;
            margin-bottom: 1rem;
        }
        .security-notice {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.95);
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 0.8rem;
            color: #666;
            max-width: 280px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border-left: 4px solid #667eea;
        }
        .login-attempts-warning {
            background: rgba(255, 107, 107, 0.9);
            color: white;
            padding: 10px 15px;
            border-radius: 6px;
            margin-bottom: 15px;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-5 col-lg-4">
                <div class="card login-card">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <i class="bi bi-music-note-beamed logo-icon"></i>
                            <h1 class="h3 mb-2"><?php echo SITE_NAME; ?></h1>
                            <p class="text-muted">Accedi al tuo account</p>
                        </div>
                        
                        <?php 
                        // Mostra avviso se ci sono stati troppi tentativi falliti
                        if (!empty($username)) {
                            require_once 'functions.php';
                            $db = getDBConnection();
                            $failed_attempts = getFailedAttempts($db, $_SERVER['REMOTE_ADDR'] ?? '');
                            if ($failed_attempts >= 3) {
                                echo '<div class="login-attempts-warning">
                                        <i class="bi bi-exclamation-triangle"></i> 
                                        Troppi tentativi di login falliti. L\'accesso è monitorato per sicurezza.
                                      </div>';
                            }
                        }
                        ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="index.php" id="loginForm">
                            <div class="mb-3">
                                <label for="username" class="form-label">
                                    <i class="bi bi-person"></i> Username
                                </label>
                                <input type="text" 
                                       class="form-control" 
                                       id="username" 
                                       name="username" 
                                       required 
                                       autofocus
                                       autocomplete="username"
                                       value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                            </div>
                            
                            <div class="mb-4">
                                <label for="password" class="form-label">
                                    <i class="bi bi-lock"></i> Password
                                </label>
                                <div class="input-group">
                                    <input type="password" 
                                           class="form-control" 
                                           id="password" 
                                           name="password" 
                                           autocomplete="current-password"
                                           required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword()">
                                        <i class="bi bi-eye" id="passwordToggleIcon"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100 py-2" id="loginButton">
                                <i class="bi bi-box-arrow-in-right"></i> Accedi
                            </button>
                        </form>
                        
                        <div class="text-center mt-3">
                            <small class="text-muted">
                                <i class="bi bi-shield-check"></i> 
                                Connessione sicura e monitorata
                            </small>
                        </div>
                    </div>
                </div>
                <div class="text-center mt-3">
                    <small class="text-white">© <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. Tutti i diritti riservati.</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Security Notice -->
    <div class="security-notice">
        <div class="d-flex align-items-start">
            <i class="bi bi-shield-lock me-2 mt-1"></i>
            <div>
                <strong>Sicurezza Attiva</strong><br>
                <small>Gli accessi sono monitorati e registrati. IP: <?php echo htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'N/D'); ?></small>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle password visibility
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleIcon = document.getElementById('passwordToggleIcon');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.className = 'bi bi-eye-slash';
            } else {
                passwordField.type = 'password';
                toggleIcon.className = 'bi bi-eye';
            }
        }
        
        // Form submission with loading state
        document.getElementById('loginForm').addEventListener('submit', function() {
            const button = document.getElementById('loginButton');
            const originalText = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i class="bi bi-hourglass-split"></i> Accedendo...';
            
            // Restore button after 3 seconds if still on page
            setTimeout(function() {
                if (button) {
                    button.disabled = false;
                    button.innerHTML = originalText;
                }
            }, 3000);
        });
        
        // Auto-dismiss alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                if (!alert.classList.contains('login-attempts-warning')) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }
            });
        }, 5000);
        
        // Log page view for analytics (non-sensitive)
        console.log('Login page loaded at:', new Date().toLocaleString());
    </script>
</body>
</html>