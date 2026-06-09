<?php
require_once '../config.php';
require_once '../auth.php';
require_once '../functions.php';

requireAdmin();

$db = getDBConnection();

// Verifica e aggiorna la struttura della tabella se necessario
$check_columns = mysqli_query($db, "SHOW COLUMNS FROM quote_history");
$existing_columns = [];
while ($row = mysqli_fetch_assoc($check_columns)) {
    $existing_columns[] = $row['Field'];
}

// Aggiungi colonne mancanti se necessario
$required_columns = [
    'ip_address' => "ALTER TABLE quote_history ADD COLUMN ip_address varchar(45) DEFAULT NULL",
    'user_agent' => "ALTER TABLE quote_history ADD COLUMN user_agent text DEFAULT NULL",
    'old_values' => "ALTER TABLE quote_history ADD COLUMN old_values longtext DEFAULT NULL",
    'new_values' => "ALTER TABLE quote_history ADD COLUMN new_values longtext DEFAULT NULL"
];

foreach ($required_columns as $column => $alter_query) {
    if (!in_array($column, $existing_columns)) {
        mysqli_query($db, $alter_query);
    }
}

// Filtri
$filter_quote_id = $_GET['quote_id'] ?? '';
$filter_user_id = $_GET['user_id'] ?? '';
$filter_action = $_GET['action'] ?? '';
$filter_ip = $_GET['ip'] ?? '';
$filter_start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$filter_end_date = $_GET['end_date'] ?? date('Y-m-d');

// Sanitize
$filter_quote_id = mysqli_real_escape_string($db, $filter_quote_id);
$filter_user_id = mysqli_real_escape_string($db, $filter_user_id);
$filter_action = mysqli_real_escape_string($db, $filter_action);
$filter_ip = mysqli_real_escape_string($db, $filter_ip);
$filter_start_date = mysqli_real_escape_string($db, $filter_start_date);
$filter_end_date = mysqli_real_escape_string($db, $filter_end_date);

// Query logs - adattata alla struttura esistente
$query = "SELECT qh.*, u.username, u.role, q.quote_number
          FROM quote_history qh
          LEFT JOIN users u ON qh.user_id = u.id
          LEFT JOIN quotes q ON qh.quote_id = q.id AND qh.quote_id > 0
          WHERE DATE(qh.created_at) BETWEEN '$filter_start_date' AND '$filter_end_date'";

if ($filter_quote_id) {
    $query .= " AND qh.quote_id = '$filter_quote_id'";
}

if ($filter_user_id) {
    $query .= " AND qh.user_id = '$filter_user_id'";
}

if ($filter_action) {
    $query .= " AND qh.action = '$filter_action'";
}

if ($filter_ip && in_array('ip_address', $existing_columns)) {
    $query .= " AND qh.ip_address LIKE '%$filter_ip%'";
}

$query .= " ORDER BY qh.created_at DESC LIMIT 1000";

$result = mysqli_query($db, $query);
$logs = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        // Assicurati che tutte le colonne esistano
        $row['ip_address'] = $row['ip_address'] ?? null;
        $row['user_agent'] = $row['user_agent'] ?? null;
        $row['old_values'] = $row['old_values'] ?? null;
        $row['new_values'] = $row['new_values'] ?? null;
        $logs[] = $row;
    }
}

// Ottieni lista utenti per filtro
$users_result = mysqli_query($db, "SELECT id, username FROM users ORDER BY username");
$users = [];
if ($users_result) {
    while ($row = mysqli_fetch_assoc($users_result)) {
        $users[] = $row;
    }
}

// Ottieni lista azioni disponibili
$actions_result = mysqli_query($db, "SELECT DISTINCT action FROM quote_history WHERE action IS NOT NULL ORDER BY action");
$actions = [];
if ($actions_result) {
    while ($row = mysqli_fetch_assoc($actions_result)) {
        $actions[] = $row['action'];
    }
}

// Ottieni IP unici per filtro (solo se la colonna esiste)
$ips = [];
if (in_array('ip_address', $existing_columns)) {
    $ips_result = mysqli_query($db, "SELECT DISTINCT ip_address FROM quote_history WHERE ip_address IS NOT NULL ORDER BY ip_address LIMIT 50");
    if ($ips_result) {
        while ($row = mysqli_fetch_assoc($ips_result)) {
            $ips[] = $row['ip_address'];
        }
    }
}

// Statistiche complete
$stats_query = "SELECT 
                  COUNT(*) as total_logs,
                  COUNT(DISTINCT CASE WHEN quote_id > 0 THEN quote_id END) as affected_quotes,
                  COUNT(DISTINCT user_id) as active_users,
                  SUM(CASE WHEN action = 'user_login_success' THEN 1 ELSE 0 END) as successful_logins,
                  SUM(CASE WHEN action = 'user_login_failed' THEN 1 ELSE 0 END) as failed_logins";

if (in_array('ip_address', $existing_columns)) {
    $stats_query .= ", COUNT(DISTINCT CASE WHEN action LIKE 'user_%' THEN ip_address END) as unique_ips";
} else {
    $stats_query .= ", 0 as unique_ips";
}

$stats_query .= " FROM quote_history
                WHERE DATE(created_at) BETWEEN '$filter_start_date' AND '$filter_end_date'";

$stats_result = mysqli_query($db, $stats_query);
$stats = $stats_result ? mysqli_fetch_assoc($stats_result) : [
    'total_logs' => 0, 
    'affected_quotes' => 0, 
    'active_users' => 0, 
    'successful_logins' => 0, 
    'failed_logins' => 0, 
    'unique_ips' => 0
];

// Statistiche aggiuntive per dashboard
$today_stats_query = "SELECT 
                        SUM(CASE WHEN action = 'user_login_success' AND DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today_logins,
                        SUM(CASE WHEN action = 'user_login_failed' AND DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today_failed,
                        COUNT(DISTINCT CASE WHEN action = 'user_login_success' AND DATE(created_at) = CURDATE() THEN user_id END) as today_unique_users
                      FROM quote_history";
$today_result = mysqli_query($db, $today_stats_query);
$today_stats = $today_result ? mysqli_fetch_assoc($today_result) : ['today_logins' => 0, 'today_failed' => 0, 'today_unique_users' => 0];

$pageTitle = 'Log Sistema - Preventivi & Accessi';
include '../includes/header.php';
?>

<style>
.log-action-badge {
    font-size: 0.8rem;
    padding: 0.25rem 0.5rem;
}
.log-details-btn {
    font-size: 0.8rem;
    padding: 0.25rem 0.5rem;
}
.stats-card {
    transition: transform 0.2s;
    cursor: pointer;
}
.stats-card:hover {
    transform: translateY(-2px);
}
.table-responsive {
    max-height: 65vh;
    overflow-y: auto;
}
.user-badge {
    font-size: 0.75rem;
    padding: 0.2rem 0.4rem;
}
.ip-badge {
    font-family: 'Courier New', monospace;
    font-size: 0.75rem;
    background: #f8f9fa;
    color: #495057;
    padding: 0.2rem 0.4rem;
    border-radius: 3px;
    cursor: pointer;
}
.log-row-user {
    background: rgba(13, 110, 253, 0.05);
}
.log-row-quote {
    background: rgba(25, 135, 84, 0.05);
}
.log-row-system {
    background: rgba(108, 117, 125, 0.05);
}
.activity-indicator {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 5px;
}
.activity-live { background: #28a745; }
.activity-recent { background: #ffc107; }
.activity-old { background: #6c757d; }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-journal-text"></i> Log Sistema</h2>
        <div class="btn-group">
            <button type="button" class="btn btn-outline-primary" onclick="testLog()">
                <i class="bi bi-plus"></i> Test Log
            </button>
            <button type="button" class="btn btn-outline-danger" onclick="clearOldLogs()">
                <i class="bi bi-trash"></i> Pulisci Vecchi
            </button>
        </div>
    </div>
    
    <!-- Alert informazioni struttura -->
    <div class="alert alert-info">
        <h6><i class="bi bi-info-circle"></i> Struttura Tabella</h6>
        <div class="small">
            Colonne disponibili: 
            <?php foreach ($existing_columns as $col): ?>
                <code><?php echo $col; ?></code> 
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Statistiche -->
    <div class="row mb-4">
        <div class="col-lg-2 col-md-4 col-sm-6 mb-2">
            <div class="card bg-primary text-white stats-card h-100">
                <div class="card-body text-center py-3">
                    <i class="bi bi-journal-text fs-4"></i>
                    <h6 class="mt-2 mb-0">Totale Log</h6>
                    <h3 class="mb-1"><?php echo number_format($stats['total_logs']); ?></h3>
                    <small>nel periodo</small>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-2">
            <div class="card bg-success text-white stats-card h-100">
                <div class="card-body text-center py-3">
                    <i class="bi bi-check-circle fs-4"></i>
                    <h6 class="mt-2 mb-0">Login Riusciti</h6>
                    <h3 class="mb-1"><?php echo number_format($stats['successful_logins']); ?></h3>
                    <small>Oggi: <?php echo $today_stats['today_logins']; ?></small>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-2">
            <div class="card bg-danger text-white stats-card h-100">
                <div class="card-body text-center py-3">
                    <i class="bi bi-x-circle fs-4"></i>
                    <h6 class="mt-2 mb-0">Login Falliti</h6>
                    <h3 class="mb-1"><?php echo number_format($stats['failed_logins']); ?></h3>
                    <small>Oggi: <?php echo $today_stats['today_failed']; ?></small>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-2">
            <div class="card bg-info text-white stats-card h-100">
                <div class="card-body text-center py-3">
                    <i class="bi bi-file-text fs-4"></i>
                    <h6 class="mt-2 mb-0">Preventivi</h6>
                    <h3 class="mb-1"><?php echo number_format($stats['affected_quotes']); ?></h3>
                    <small>coinvolti</small>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-2">
            <div class="card bg-warning text-white stats-card h-100">
                <div class="card-body text-center py-3">
                    <i class="bi bi-people fs-4"></i>
                    <h6 class="mt-2 mb-0">Utenti Attivi</h6>
                    <h3 class="mb-1"><?php echo number_format($stats['active_users']); ?></h3>
                    <small>Oggi: <?php echo $today_stats['today_unique_users']; ?></small>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-2">
            <div class="card bg-dark text-white stats-card h-100">
                <div class="card-body text-center py-3">
                    <i class="bi bi-router fs-4"></i>
                    <h6 class="mt-2 mb-0">IP Unici</h6>
                    <h3 class="mb-1"><?php echo number_format($stats['unique_ips']); ?></h3>
                    <small>tracciati</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filtri semplificati -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-funnel"></i> Filtri</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3" id="filterForm">
                <div class="col-md-2">
                    <label class="form-label">Data Inizio</label>
                    <input type="date" name="start_date" class="form-control form-control-sm" value="<?php echo e($filter_start_date); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Data Fine</label>
                    <input type="date" name="end_date" class="form-control form-control-sm" value="<?php echo e($filter_end_date); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">ID Preventivo</label>
                    <input type="number" name="quote_id" class="form-control form-control-sm" value="<?php echo e($filter_quote_id); ?>" placeholder="123">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Utente</label>
                    <select name="user_id" class="form-select form-select-sm">
                        <option value="">Tutti</option>
                        <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>" <?php echo $filter_user_id == $user['id'] ? 'selected' : ''; ?>>
                            <?php echo e($user['username']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Azione</label>
                    <select name="action" class="form-select form-select-sm">
                        <option value="">Tutte</option>
                        <?php foreach ($actions as $action): ?>
                        <option value="<?php echo e($action); ?>" <?php echo $filter_action == $action ? 'selected' : ''; ?>>
                            <?php echo e(ucfirst(str_replace('_', ' ', $action))); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-search"></i> Filtra
                    </button>
                </div>
            </form>
            
            <div class="mt-2">
                <a href="logs.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                <div class="btn-group ms-2">
                    <a href="?start_date=<?php echo date('Y-m-d'); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-sm btn-outline-primary">Oggi</a>
                    <a href="?start_date=<?php echo date('Y-m-d', strtotime('-7 days')); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-sm btn-outline-primary">7gg</a>
                    <a href="?start_date=<?php echo date('Y-m-d', strtotime('-30 days')); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-sm btn-outline-primary">30gg</a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tabella Log -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Storico Attività (<?php echo count($logs); ?> risultati)</h5>
            <small class="text-muted">Max 1000 record</small>
        </div>
        <div class="card-body p-0">
            <?php if (empty($logs)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-journal-x" style="font-size: 3rem; color: #ccc;"></i>
                    <p class="text-muted mt-3">Nessun log trovato</p>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th width="15%">Data/Ora</th>
                            <th width="10%">Preventivo</th>
                            <th width="12%">Utente</th>
                            <th width="15%">Azione</th>
                            <?php if (in_array('ip_address', $existing_columns)): ?>
                            <th width="12%">IP</th>
                            <?php endif; ?>
                            <th>Dettagli</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <?php
                        // Determina la classe CSS per il tipo di log
                        $row_class = '';
                        if (str_starts_with($log['action'], 'user_')) {
                            $row_class = 'log-row-user';
                        } elseif ($log['quote_id'] > 0) {
                            $row_class = 'log-row-quote';
                        } else {
                            $row_class = 'log-row-system';
                        }
                        
                        // Indicatore di attività
                        $time_diff = time() - strtotime($log['created_at']);
                        $activity_class = 'activity-old';
                        if ($time_diff < 300) { // 5 minuti
                            $activity_class = 'activity-live';
                        } elseif ($time_diff < 3600) { // 1 ora
                            $activity_class = 'activity-recent';
                        }
                        ?>
                        <tr class="<?php echo $row_class; ?>">
                            <td>
                                <span class="activity-indicator <?php echo $activity_class; ?>"></span>
                                <small><?php echo date('d/m/Y H:i:s', strtotime($log['created_at'])); ?></small>
                            </td>
                            <td>
                                <?php if ($log['quote_number'] && $log['quote_id'] > 0): ?>
                                    <a href="../quotes.php?id=<?php echo $log['quote_id']; ?>" target="_blank" class="text-decoration-none">
                                        <span class="badge bg-primary"><?php echo e($log['quote_number']); ?></span>
                                    </a>
                                <?php elseif ($log['quote_id'] > 0): ?>
                                    <small class="text-muted">ID: <?php echo $log['quote_id']; ?></small>
                                <?php else: ?>
                                    <small class="text-muted">-</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($log['username']): ?>
                                    <span class="badge bg-<?php echo ($log['role'] ?? '') == 'admin' ? 'danger' : 'secondary'; ?> user-badge">
                                        <?php echo e($log['username']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-light text-dark user-badge">Sistema</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $action_labels = [
                                    'created' => ['label' => 'Creato', 'color' => 'success'],
                                    'updated' => ['label' => 'Modificato', 'color' => 'info'],
                                    'status_changed' => ['label' => 'Stato Cambiato', 'color' => 'warning'],
                                    'staff_assigned' => ['label' => 'Staff Assegnato', 'color' => 'primary'],
                                    'note_added' => ['label' => 'Nota Aggiunta', 'color' => 'info'],
                                    'user_login_success' => ['label' => 'Login OK', 'color' => 'success'],
                                    'user_login_failed' => ['label' => 'Login KO', 'color' => 'danger'],
                                    'user_logout' => ['label' => 'Logout', 'color' => 'info'],
                                ];
                                
                                $action_info = $action_labels[$log['action']] ?? [
                                    'label' => ucfirst(str_replace('_', ' ', $log['action'])), 
                                    'color' => 'secondary'
                                ];
                                ?>
                                <span class="badge bg-<?php echo $action_info['color']; ?> log-action-badge">
                                    <?php echo $action_info['label']; ?>
                                </span>
                            </td>
                            <?php if (in_array('ip_address', $existing_columns)): ?>
                            <td>
                                <?php if ($log['ip_address']): ?>
                                    <span class="ip-badge">
                                        <?php echo e($log['ip_address']); ?>
                                    </span>
                                <?php else: ?>
                                    <small class="text-muted">-</small>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td>
                                <?php if ($log['changes']): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary log-details-btn" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#detailModal<?php echo $log['id']; ?>">
                                        <i class="bi bi-eye"></i> Dettagli
                                    </button>
                                    
                                    <!-- Modal Dettagli -->
                                    <div class="modal fade" id="detailModal<?php echo $log['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-lg">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Log #<?php echo $log['id']; ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="row mb-3">
                                                        <div class="col-md-6">
                                                            <strong>Data:</strong> <?php echo date('d/m/Y H:i:s', strtotime($log['created_at'])); ?>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <strong>Utente:</strong> <?php echo e($log['username'] ?? 'Sistema'); ?>
                                                        </div>
                                                    </div>
                                                    
                                                    <?php
                                                    $changes = json_decode($log['changes'], true);
                                                    if (is_array($changes) && !empty($changes)):
                                                    ?>
                                                    <h6>Dettagli:</h6>
                                                    <div class="table-responsive">
                                                        <table class="table table-sm table-bordered">
                                                            <?php foreach ($changes as $key => $value): ?>
                                                            <tr>
                                                                <td><strong><?php echo e(ucfirst(str_replace('_', ' ', $key))); ?></strong></td>
                                                                <td>
                                                                    <?php 
                                                                    if (is_array($value)) {
                                                                        echo '<pre style="font-size: 0.75rem;">' . e(json_encode($value, JSON_PRETTY_PRINT)) . '</pre>';
                                                                    } else {
                                                                        echo e($value);
                                                                    }
                                                                    ?>
                                                                </td>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                        </table>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function testLog() {
    if (confirm('Creare un log di test?')) {
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=test_log'
        }).then(() => location.reload());
    }
}

function clearOldLogs() {
    if (confirm('Eliminare log più vecchi di 90 giorni?')) {
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=clear_old_logs'
        }).then(() => location.reload());
    }
}
</script>

<?php 
// Gestione azioni AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'test_log':
            $user_id = $_SESSION['user_id'] ?? 1;
            $changes_json = json_encode([
                'message' => 'Log di test',
                'timestamp' => date('Y-m-d H:i:s'),
                'user' => $_SESSION['username'] ?? 'Test'
            ]);
            
            $columns = "quote_id, user_id, action, changes";
            $values = "0, $user_id, 'test', '$changes_json'";
            
            // Aggiungi IP se la colonna esiste
            if (in_array('ip_address', $existing_columns)) {
                $ip = mysqli_real_escape_string($db, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
                $columns .= ", ip_address";
                $values .= ", '$ip'";
            }
            
            $insert_query = "INSERT INTO quote_history ($columns) VALUES ($values)";
            mysqli_query($db, $insert_query);
            exit('OK');
            
        case 'clear_old_logs':
            mysqli_query($db, "DELETE FROM quote_history WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
            exit('OK');
    }
}

include '../includes/footer.php'; 
?>