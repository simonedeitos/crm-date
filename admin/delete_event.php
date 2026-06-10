<?php
require_once '../config.php';
require_once '../auth.php';
require_once '../functions.php';

requireAdmin();

$db = getDBConnection();
$error = '';
$success = '';
$run_select = function($sql, $types = '', ...$params) use ($db) {
    $stmt = mysqli_prepare($db, $sql);
    if (!$stmt) {
        throw new Exception(mysqli_error($db));
    }
    if ($types !== '' && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception(mysqli_stmt_error($stmt));
    }
    $result = mysqli_stmt_get_result($stmt);
    mysqli_stmt_close($stmt);
    return $result;
};

$run_exec = function($sql, $types = '', ...$params) use ($db) {
    $stmt = mysqli_prepare($db, $sql);
    if (!$stmt) {
        throw new Exception(mysqli_error($db));
    }
    if ($types !== '' && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception(mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
};

// Carica quote_id da GET
$quote_id = isset($_GET['quote_id']) ? (int)$_GET['quote_id'] : 0;

if ($quote_id <= 0) {
    header("Location: assign_staff.php?error=invalid_quote");
    exit;
}

// Carica l'evento
$result = $run_select(
    "SELECT q.*, c.company_name, c.first_name, c.last_name 
     FROM quotes q 
     JOIN clients c ON q.client_id = c.id 
     WHERE q.id = ?",
    'i',
    $quote_id
);
$quote = mysqli_fetch_assoc($result);

if (!$quote) {
    header("Location: assign_staff.php?error=quote_not_found");
    exit;
}

// Verifica che sia un evento clonato
if (empty($quote['cloned_from'])) {
    header("Location: assign_staff.php?error=not_cloned_event");
    exit;
}

$client_name = $quote['company_name'] ?: trim($quote['first_name'] . ' ' . $quote['last_name']);

// GESTIONE POST - ELIMINAZIONE
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $quote_action = $_POST['quote_action'] ?? '';
    $confirm = isset($_POST['confirm_delete']);
    
    if (!$confirm) {
        $error = "Devi confermare l'eliminazione spuntando la casella.";
    } elseif (!in_array($quote_action, ['delete', 'reject'])) {
        $error = "Seleziona un'opzione per il preventivo originale.";
    } else {
        $cloned_from = (int)$quote['cloned_from'];
        
        mysqli_begin_transaction($db);
        
        try {
            // Helper per eliminare file in modo sicuro
            $safe_unlink = function($file_path) {
                $base_dir = realpath(dirname(__DIR__) . '/uploads');
                if ($base_dir === false) return;
                $real_path = realpath(dirname(__DIR__) . '/' . $file_path);
                if ($real_path !== false && strpos($real_path, $base_dir) === 0 && file_exists($real_path)) {
                    unlink($real_path);
                }
            };
            
            // Funzione per eliminare completamente un preventivo
            $delete_quote_completely = function($q_id) use ($run_select, $run_exec, $safe_unlink) {
                // Elimina media files
                $item_ids_res = $run_select("SELECT id FROM quote_items WHERE quote_id = ?", 'i', $q_id);
                while ($item_row = mysqli_fetch_assoc($item_ids_res)) {
                    $iid = (int)$item_row['id'];
                    $media_res = $run_select("SELECT file_path FROM quote_item_media WHERE quote_item_id = ?", 'i', $iid);
                    while ($mrow = mysqli_fetch_assoc($media_res)) {
                        $safe_unlink($mrow['file_path']);
                    }
                    $run_exec("DELETE FROM quote_item_media WHERE quote_item_id = ?", 'i', $iid);
                    $run_exec("DELETE FROM quote_item_services WHERE quote_item_id = ?", 'i', $iid);
                    $run_exec("DELETE FROM quote_item_roles WHERE quote_item_id = ?", 'i', $iid);
                }
                
                // Elimina attachments
                $att_res = $run_select("SELECT file_path FROM quote_attachments WHERE quote_id = ?", 'i', $q_id);
                while ($att_row = mysqli_fetch_assoc($att_res)) {
                    $safe_unlink($att_row['file_path']);
                }
                
                // Elimina tutte le tabelle correlate
                $run_exec("DELETE FROM quote_items WHERE quote_id = ?", 'i', $q_id);
                $run_exec("DELETE FROM quote_staff_assignment WHERE quote_id = ?", 'i', $q_id);
                $run_exec("DELETE FROM quote_service_costs WHERE quote_id = ?", 'i', $q_id);
                $run_exec("DELETE FROM quote_attachments WHERE quote_id = ?", 'i', $q_id);
                $run_exec("DELETE FROM quote_history WHERE quote_id = ?", 'i', $q_id);
                $run_exec("DELETE FROM quote_dates WHERE quote_id = ?", 'i', $q_id);
                $run_exec("DELETE FROM quotes WHERE id = ?", 'i', $q_id);
            };
            
            // 1. Elimina l'evento clonato
            $delete_quote_completely($quote_id);
            
            // 2. Gestisci il preventivo originale
            if ($quote_action === 'delete') {
                $delete_quote_completely($cloned_from);
                $success_msg = "event_and_original_deleted";
            } else {
                $run_exec("UPDATE quotes SET status = 'rifiutato' WHERE id = ?", 'i', $cloned_from);
                $success_msg = "event_deleted_original_rejected";
            }
            
            mysqli_commit($db);
            header("Location: assign_staff.php?success=$success_msg");
            exit;
            
        } catch (Exception $e) {
            mysqli_rollback($db);
            $error = "Errore durante l'eliminazione: " . $e->getMessage();
        }
    }
}

$pageTitle = 'Elimina Evento';
include '../includes/header.php';
?>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    <h4 class="mb-0">
                        <i class="bi bi-exclamation-triangle-fill"></i> Conferma Eliminazione Evento
                    </h4>
                </div>
                <div class="card-body">
                    
                    <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-x-circle"></i> <?php echo e($error); ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>ATTENZIONE!</strong> Stai per eliminare l'evento:
                    </div>
                    
                    <div class="bg-light p-3 rounded mb-4">
                        <table class="table table-sm table-borderless mb-0">
                            <tr>
                                <th style="width: 40%;">Preventivo:</th>
                                <td><strong><?php echo e($quote['quote_number']); ?></strong></td>
                            </tr>
                            <tr>
                                <th>Cliente:</th>
                                <td><?php echo e($client_name); ?></td>
                            </tr>
                            <tr>
                                <th>Totale:</th>
                                <td><?php echo formatPrice($quote['total_with_iva']); ?></td>
                            </tr>
                        </table>
                    </div>
                    
                    <form method="POST">
                        
                        <div class="mb-4">
                            <label class="form-label fw-bold">
                                Cosa fare con il preventivo originale associato?
                            </label>
                            
                            <div class="card mb-2">
                                <div class="card-body">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="quote_action" 
                                               id="action_reject" value="reject" required>
                                        <label class="form-check-label" for="action_reject">
                                            <i class="bi bi-x-circle text-warning"></i>
                                            <strong>Imposta come "Rifiutato"</strong><br>
                                            <small class="text-muted">Il preventivo originale verrà marcato come rifiutato ma conservato nel database</small>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="card">
                                <div class="card-body">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="quote_action" 
                                               id="action_delete" value="delete" required>
                                        <label class="form-check-label" for="action_delete">
                                            <i class="bi bi-trash text-danger"></i>
                                            <strong>Elimina definitivamente</strong><br>
                                            <small class="text-muted">Il preventivo originale verrà eliminato completamente dal database</small>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-danger">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="confirm_delete" 
                                       id="confirm_delete" required>
                                <label class="form-check-label fw-bold" for="confirm_delete">
                                    Confermo di voler eliminare questo evento
                                </label>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <a href="assign_staff.php?quote_id=<?php echo $quote_id; ?>" 
                               class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Annulla
                            </a>
                            <button type="submit" class="btn btn-danger btn-lg">
                                <i class="bi bi-trash"></i> ELIMINA EVENTO
                            </button>
                        </div>
                        
                    </form>
                    
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
