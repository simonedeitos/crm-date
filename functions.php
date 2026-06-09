<?php
/**
 * Funzioni helper generali
 */

require_once 'config.php';

/**
 * Formatta prezzo
 */
function formatPrice($price) {
    return '€ ' . number_format($price, 2, ',', '.');
}

/**
 * Formatta data
 */
function formatDate($date, $format = 'd/m/Y') {
    if (empty($date)) return '';
    $dt = new DateTime($date);
    return $dt->format($format);
}

/**
 * Formatta data e ora
 */
function formatDateTime($datetime, $format = 'd/m/Y H:i') {
    if (empty($datetime)) return '';
    $dt = new DateTime($datetime);
    return $dt->format($format);
}

/**
 * Escape HTML
 */
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Genera numero preventivo univoco
 */
function generateQuoteNumber() {
    $db = getDBConnection();
    $year = date('Y');
    
    $query = "SELECT COUNT(*) as count FROM quotes WHERE YEAR(created_at) = $year";
    $result = mysqli_query($db, $query);
    $row = mysqli_fetch_assoc($result);
    
    $nextNumber = $row['count'] + 1;
    return 'PREV-' . $year . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
}

/**
 * FUNZIONE FONDAMENTALE: Ricalcola i totali del preventivo
 * Questa funzione viene chiamata ogni volta che si aggiunge/rimuove un item
 */
function recalculateQuoteTotals($quote_id) {
    $db = getDBConnection();
    $quote_id = (int)$quote_id;
    
    // 1. Calcola subtotale sommando tutti gli items
    $result = mysqli_query($db, "SELECT COALESCE(SUM(price), 0) as subtotal FROM quote_items WHERE quote_id = $quote_id");
    $row = mysqli_fetch_assoc($result);
    $subtotal = (float)$row['subtotal'];
    
    // 2. Ottieni info sconto e IVA del preventivo
    $quote_result = mysqli_query($db, "SELECT discount_type, discount_value, iva_rate FROM quotes WHERE id = $quote_id");
    $quote = mysqli_fetch_assoc($quote_result);
    
    $discount_type = $quote['discount_type'] ?? 'none';
    $discount_value = (float)($quote['discount_value'] ?? 0);
    $iva_rate = (float)($quote['iva_rate'] ?? 22);
    
    // 3. Calcola sconto
    $discount_amount = 0;
    if ($discount_type === 'percentage') {
        $discount_amount = ($subtotal * $discount_value) / 100;
    } elseif ($discount_type === 'fixed') {
        $discount_amount = $discount_value;
    }
    
    // 4. Calcola totale imponibile (subtotale - sconto)
    $total = $subtotal - $discount_amount;
    
    // 5. Calcola IVA
    $iva_amount = ($total * $iva_rate) / 100;
    
    // 6. Calcola totale con IVA
    $total_with_iva = $total + $iva_amount;
    
    // 7. Aggiorna il preventivo con i nuovi totali
    $update_query = "UPDATE quotes SET 
                     subtotal = $subtotal,
                     total = $total,
                     iva_amount = $iva_amount,
                     total_with_iva = $total_with_iva,
                     updated_at = NOW()
                     WHERE id = $quote_id";
    
    mysqli_query($db, $update_query);
    
    return [
        'subtotal' => $subtotal,
        'discount_amount' => $discount_amount,
        'total' => $total,
        'iva_amount' => $iva_amount,
        'total_with_iva' => $total_with_iva
    ];
}

/**
 * Calcola totale preventivo (funzione legacy - usa recalculateQuoteTotals)
 */
function calculateQuoteTotal($subtotal, $discountType, $discountValue) {
    if ($discountType === 'percentage' || $discountType === 'percent') {
        return $subtotal - ($subtotal * $discountValue / 100);
    } else {
        return $subtotal - $discountValue;
    }
}

/**
 * Calcola IVA
 */
function calculateIVA($total, $rate = 22) {
    return $total * $rate / 100;
}

/**
 * Upload file sicuro
 */
function uploadFile($file, $quote_id) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Errore upload file'];
    }
    
    // Definisci costanti se non esistono
    if (!defined('MAX_FILE_SIZE')) define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
    if (!defined('ALLOWED_EXTENSIONS')) define('ALLOWED_EXTENSIONS', ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx']);
    if (!defined('UPLOAD_DIR')) define('UPLOAD_DIR', __DIR__ . '/uploads/');
    
    // Verifica dimensione
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'error' => 'File troppo grande (max 10MB)'];
    }
    
    // Verifica estensione
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
        return ['success' => false, 'error' => 'Estensione file non permessa'];
    }
    
    // Genera nome unico
    $filename = uniqid() . '_' . time() . '.' . $ext;
    $filepath = UPLOAD_DIR . $filename;
    
    // Crea directory se non esiste
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    
    // Sposta file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return [
            'success' => true,
            'filename' => $filename,
            'original_name' => $file['name'],
            'filepath' => $filepath,
            'size' => $file['size'],
            'type' => $file['type']
        ];
    }
    
    return ['success' => false, 'error' => 'Errore salvataggio file'];
}

/**
 * Verifica disponibilità staff per data
 */
function checkStaffAvailability($staff_id, $event_date, $exclude_quote_id = null) {
    $db = getDBConnection();
    
    $staff_id = (int)$staff_id;
    $event_date = mysqli_real_escape_string($db, $event_date);
    
    $sql = "SELECT q.quote_number, q.id, qd.event_date 
            FROM quote_staff_assignment qsa
            JOIN quotes q ON qsa.quote_id = q.id
            JOIN quote_dates qd ON qsa.quote_date_id = qd.id
            WHERE qsa.staff_id = $staff_id 
            AND qd.event_date = '$event_date'
            AND q.status IN ('accettato', 'confermato')";
    
    if ($exclude_quote_id) {
        $exclude_quote_id = (int)$exclude_quote_id;
        $sql .= " AND q.id != $exclude_quote_id";
    }
    
    $result = mysqli_query($db, $sql);
    $conflicts = [];
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $conflicts[] = $row;
        }
    }
    
    return $conflicts;
}

/**
 * Log attività su preventivo
 */
function logQuoteActivity($quote_id, $action, $changes = null) {
    $db = getDBConnection();
    
    $quote_id = (int)$quote_id;
    $action = mysqli_real_escape_string($db, $action);
    $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 'NULL';
    $changes_json = $changes ? mysqli_real_escape_string($db, json_encode($changes)) : 'NULL';
    
    $query = "INSERT INTO quote_history (quote_id, user_id, action, changes, created_at)
              VALUES ($quote_id, $user_id, '$action', " . ($changes_json !== 'NULL' ? "'$changes_json'" : 'NULL') . ", NOW())";
    
    mysqli_query($db, $query);
}

/**
 * Ottieni badge colore per stato preventivo
 */
function getStatusBadge($status) {
    $badges = [
        'bozza' => 'secondary',
        'inviato' => 'secondary',
        'accettato' => 'warning',
        'confermato' => 'success',
        'rifiutato' => 'danger'
    ];
    
    $color = $badges[$status] ?? 'secondary';
    $label = ucfirst($status);
    
    // Aggiungi emoji
    $icons = [
        'bozza' => '📝',
        'inviato' => '📤',
        'accettato' => '✅',
        'confermato' => '🎉',
        'rifiutato' => '❌'
    ];
    $icon = $icons[$status] ?? '❓';
    
    return '<span class="badge bg-' . $color . '">' . $icon . ' ' . $label . '</span>';
}

/**
 * Ottieni badge colore per ruolo
 */
function getRoleBadge($role) {
    $badges = [
        'admin' => 'danger',
        'commerciale' => 'primary'
    ];
    
    $color = $badges[$role] ?? 'secondary';
    $label = $role === 'admin' ? 'Admin' : 'Commerciale';
    return '<span class="badge bg-' . $color . '">' . $label . '</span>';
}

/**
 * Converti array servizi in JSON per database
 */
function servicesArrayToJson($services) {
    return json_encode($services);
}

/**
 * Converti JSON servizi da database in array
 */
function servicesJsonToArray($json) {
    return json_decode($json, true) ?? [];
}


/**
 * Ottieni statistiche preventivi per dashboard
 */
function getQuotesStats($user_id = null, $role = null) {
    $db = getDBConnection();
    
    $where = [];
    if ($role === 'commerciale' && $user_id) {
        $where[] = "created_by = $user_id";
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'inviato' THEN 1 ELSE 0 END) as inviati,
                SUM(CASE WHEN status = 'accettato' THEN 1 ELSE 0 END) as accettati,
                SUM(CASE WHEN status = 'confermato' THEN 1 ELSE 0 END) as confermati,
                SUM(CASE WHEN status = 'confermato' THEN total_with_iva ELSE 0 END) as fatturato
              FROM quotes 
              $whereClause";
    
    $result = mysqli_query($db, $query);
    return mysqli_fetch_assoc($result);
}
?>