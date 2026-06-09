<?php
/**
 * CRM DATE - Gestionale Format Musicali
 * File di configurazione principale
 */

date_default_timezone_set('Europe/Rome');

// Configurazione Database - CORRETTE PER HOSTINGER
define('DB_HOST', 'mysql');  // ✅ NON auth-db941.hstgr.io
define('DB_PORT', '3306');
define('DB_NAME', 'u362062795_crmdate');
define('DB_USER', 'u362062795_crmdate');
define('DB_PASS', '#ciErreEmme.Date-2025!+');

// Configurazione applicazione
define('SITE_NAME', 'CRM DATE');
define('BASE_URL', 'https://beatitaliano.it/crm'); // ⚠️ Modifica con il tuo URL reale
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('MAX_FILE_SIZE', 10485760); // 10MB
define('ALLOWED_EXTENSIONS', ['pdf', 'jpg', 'jpeg', 'png', 'gif']);

// IVA default
define('DEFAULT_IVA_RATE', 22.00);

// Timezone
date_default_timezone_set('Europe/Rome');

// Connessione Database usando MySQLi (come nel tuo esempio)
function getDBConnection() {
    static $conn = null;
    
    if ($conn === null) {
        $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        // Check connection
        if($conn === false){
            die("<br><br><br><br><b>Non posso connettermi al Database:</b><br><br> " . mysqli_connect_error());
        }
        
        // Imposta charset UTF-8
        mysqli_set_charset($conn, "utf8mb4");
    }
    
    return $conn;
}

// Funzione helper per mysqli_result (come nel tuo codice)
function mysqli_result($res, $row=0, $col=0){
    $numrows = mysqli_num_rows($res);
    if ($numrows && $row <= ($numrows-1) && $row >=0){
        mysqli_data_seek($res,$row);
        $resrow = (is_numeric($col)) ? mysqli_fetch_row($res) : mysqli_fetch_assoc($res);
        if (isset($resrow[$col])){
            return $resrow[$col];
        }
    }
    return false;
}

// Avvia sessione se non già avviata
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>