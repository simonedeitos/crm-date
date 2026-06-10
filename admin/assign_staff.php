<?php
// --- CONFIGURAZIONE ---
// Disabilita l'abort dello script
ignore_user_abort(true);
set_time_limit(0);
// ----------------------

require_once '../config.php';
require_once '../auth.php';
require_once '../functions.php';

requireAdmin();

$db = getDBConnection();
$error = '';
$success = '';

// Assicura che la colonna cloned_from esista sulla tabella quotes
$cols = mysqli_query($db, "SHOW COLUMNS FROM quotes LIKE 'cloned_from'");
if (mysqli_num_rows($cols) === 0) {
    mysqli_query($db, "ALTER TABLE quotes ADD COLUMN cloned_from INT NULL DEFAULT NULL");
}

// Gestione errori provenienti da redirect
if (isset($_GET['error'])) {
    $error_map = [
        'client_required' => 'Cliente obbligatorio: seleziona un cliente esistente o inserisci i dati del nuovo cliente.',
        'quote_not_found' => 'Preventivo originale non trovato.',
        'clone_failed' => 'Errore durante la clonazione dell\'evento. Riprova.',
        'not_cloned' => 'Impossibile eliminare: questo non è un evento clonato.',
        'event_not_found' => 'Evento non trovato o già eliminato.',
        'delete_failed' => 'Errore durante l\'eliminazione dell\'evento. Riprova.',
    ];
    $error = $error_map[$_GET['error']] ?? 'Errore imprevisto.';
}

// GESTIONE POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    switch ($action) {
        case 'assign_staff':
            // [CODICE ORIGINALE INVARIATO]
            $quote_id = (int)$_POST['quote_id'];
            $assignments = $_POST['assignments'] ?? [];
            $services = $_POST['services'] ?? [];
            
            mysqli_begin_transaction($db);
            try {
                mysqli_query($db, "DELETE FROM quote_staff_assignment WHERE quote_id = $quote_id");
                mysqli_query($db, "DELETE FROM quote_service_costs WHERE quote_id = $quote_id");
                
                $staff_count = 0; $saved_staff = 0; $saved_services = 0;
                
                if (!empty($assignments)) {
                    foreach ($assignments as $idx => $assignment) {
                        if (empty($assignment['role_id']) || trim($assignment['role_id']) === '') continue;
                        $role_id = (int)$assignment['role_id'];
                        if (empty($assignment['staff_id']) || trim($assignment['staff_id']) === '') continue;
                        $staff_id = (int)$assignment['staff_id'];
                        $cost = (float)($assignment['cost'] ?? 0);
                        $extra = (float)($assignment['extra'] ?? 0);
                        $notes = mysqli_real_escape_string($db, trim($assignment['notes'] ?? ''));
                        $quote_item_id = !empty($assignment['quote_item_id']) ? (int)$assignment['quote_item_id'] : 'NULL';
                        
                        $query = "INSERT INTO quote_staff_assignment (quote_id, quote_item_id, role_id, staff_id, cost, extra, notes, assigned_at, assigned_by) VALUES ($quote_id, $quote_item_id, $role_id, $staff_id, $cost, $extra, '$notes', NOW(), {$_SESSION['user_id']})";
                        if (!mysqli_query($db, $query)) throw new Exception("Errore staff #$idx: " . mysqli_error($db));
                        $saved_staff++;
                        if ($cost > 1) $staff_count++;
                    }
                }
                
                if (!empty($services)) {
                    foreach ($services as $idx => $service) {
                        $service_name = mysqli_real_escape_string($db, trim($service['name'] ?? ''));
                        $service_cost = (float)($service['cost'] ?? 0);
                        $service_extra = (float)($service['extra'] ?? 0);
                        $service_notes = mysqli_real_escape_string($db, trim($service['notes'] ?? ''));
                        $service_quote_item_id = !empty($service['quote_item_id']) ? (int)$service['quote_item_id'] : 'NULL';
                        
                        if (!empty($service_name)) {
                            $service_query = "INSERT INTO quote_service_costs (quote_id, quote_item_id, service_name, cost, extra, notes, created_at) VALUES ($quote_id, $service_quote_item_id, '$service_name', $service_cost, $service_extra, '$service_notes', NOW())";
                            if (!mysqli_query($db, $service_query)) throw new Exception("Errore servizio #$idx: " . mysqli_error($db));
                            $saved_services++;
                        }
                    }
                }
                
                $agibility_query = "SELECT qi.id as package_id, qi.package_name, qi.event_date, COUNT(DISTINCT CASE WHEN qsa.cost > 1 THEN qsa.staff_id END) as staff_count FROM quote_items qi LEFT JOIN quote_staff_assignment qsa ON qi.id = qsa.quote_item_id AND qsa.quote_id = $quote_id WHERE qi.quote_id = $quote_id GROUP BY qi.id, qi.package_name, qi.event_date";
                $agibility_result = mysqli_query($db, $agibility_query);
                if (!$agibility_result) throw new Exception("Errore query agibilità: " . mysqli_error($db));
                while ($pkg = mysqli_fetch_assoc($agibility_result)) {
                    $staff_count_pkg = (int)$pkg['staff_count'];
                    if ($staff_count_pkg > 0) {
                        $pkg_cost = $staff_count_pkg * 20;
                        $agibility_notes = mysqli_real_escape_string($db, 'N. ' . $staff_count_pkg . ' persone');
                        $agibility_query_insert = "INSERT INTO quote_service_costs (quote_id, quote_item_id, service_name, cost, extra, notes, created_at) VALUES ($quote_id, {$pkg['package_id']}, 'OKL SERVIZI - AGIBILITÀ', $pkg_cost, 0, '$agibility_notes', NOW())";
                        if (!mysqli_query($db, $agibility_query_insert)) throw new Exception("Errore agibilità pacchetto {$pkg['package_id']}: " . mysqli_error($db));
                    }
                }
                mysqli_commit($db);
                header("Location: assign_staff.php?quote_id=$quote_id&success=staff_assigned&saved=$saved_staff&services=$saved_services");
                exit;
            } catch (Exception $e) {
                mysqli_rollback($db);
                $error = "Errore durante il salvataggio: " . $e->getMessage();
            }
            break;
            
        case 'update_amounts':
            // [CODICE ORIGINALE INVARIATO]
            $quote_id = (int)$_POST['quote_id'];
            $invoice_amount = (float)$_POST['invoice_amount'];
            $extra_amount = (float)$_POST['extra_amount'];
            if (mysqli_query($db, "UPDATE quotes SET invoice_amount = $invoice_amount, extra_amount = $extra_amount WHERE id = $quote_id")) {
                header("Location: assign_staff.php?quote_id=$quote_id&success=amounts"); exit;
            } else $error = "Errore aggiornamento importi: " . mysqli_error($db);
            break;
            
        case 'update_commission':
            // [CODICE ORIGINALE INVARIATO]
            $quote_id = (int)$_POST['quote_id'];
            $commission = (float)$_POST['commission'];
            $commission_type = mysqli_real_escape_string($db, $_POST['commission_type'] ?? 'cost');
            $no_commission = isset($_POST['no_commission']) ? 1 : 0;
            if ($no_commission) $commission = 0;
            if (mysqli_query($db, "UPDATE quotes SET commercial_commission = $commission, commission_type = '$commission_type', no_commission = $no_commission WHERE id = $quote_id")) {
                header("Location: assign_staff.php?quote_id=$quote_id&success=commission"); exit;
            } else $error = "Errore aggiornamento provvigione: " . mysqli_error($db);
            break;
            
        case 'update_deposit':
            // [CODICE ORIGINALE INVARIATO]
            $quote_id = (int)$_POST['quote_id'];
            $deposit_amount = (float)$_POST['deposit_amount'];
            $deposit_type = mysqli_real_escape_string($db, $_POST['deposit_type'] ?? 'cost');
            if (mysqli_query($db, "UPDATE quotes SET deposit_amount = $deposit_amount, deposit_type = '$deposit_type', deposit_date = NOW() WHERE id = $quote_id")) {
                header("Location: assign_staff.php?quote_id=$quote_id&success=deposit"); exit;
            } else $error = "Errore aggiornamento acconto: " . mysqli_error($db);
            break;
            
        case 'update_staff_status':
            // [CODICE ORIGINALE INVARIATO]
            $quote_id = (int)$_POST['quote_id'];
            $staff_status = mysqli_real_escape_string($db, $_POST['staff_status']);
            if (mysqli_query($db, "UPDATE quotes SET staff_management_status = '$staff_status' WHERE id = $quote_id")) {
                header("Location: assign_staff.php?quote_id=$quote_id&success=status"); exit;
            } else $error = "Errore aggiornamento stato: " . mysqli_error($db);
            break;
            
        case 'update_notes':
            // [CODICE ORIGINALE INVARIATO]
            $quote_id = (int)$_POST['quote_id'];
            $internal_notes = mysqli_real_escape_string($db, trim($_POST['internal_notes'] ?? ''));
            if (mysqli_query($db, "UPDATE quotes SET internal_notes = '$internal_notes' WHERE id = $quote_id")) {
                header("Location: assign_staff.php?quote_id=$quote_id&success=notes"); exit;
            } else $error = "Errore aggiornamento note: " . mysqli_error($db);
            break;

        case 'update_graphics':
            // [CODICE ORIGINALE INVARIATO]
            $quote_id = (int)$_POST['quote_id'];
            $quote_item_id = (int)$_POST['quote_item_id'];
            $graphics_included = isset($_POST['graphics_included']) ? 1 : 0;
            $graphics_created = isset($_POST['graphics_created']) ? 1 : 0;
            $graphics_sponsored = isset($_POST['graphics_sponsored']) ? 1 : 0;
            $query = "UPDATE quote_items SET graphics_included = $graphics_included, graphics_created = $graphics_created, graphics_sponsored = $graphics_sponsored WHERE id = $quote_item_id";
            if (mysqli_query($db, $query)) {
                header("Location: assign_staff.php?quote_id=$quote_id&success=graphics"); exit;
            } else $error = "Errore aggiornamento grafiche: " . mysqli_error($db);
            break;

        // --- GESTIONE UPLOAD A PEZZI (CHUNKED) PER EVITARE TIMEOUT ---
        case 'upload_chunk':
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            
            $quote_id = (int)$_POST['quote_id'];
            $quote_item_id = (int)$_POST['quote_item_id'];
            $fileName = $_POST['file_name'];
            $chunkIndex = (int)$_POST['chunk_index'];
            $totalChunks = (int)$_POST['total_chunks'];
            
            // Cartella Temp
            $upload_dir = '../uploads/events/' . $quote_id . '/';
            $temp_dir = $upload_dir . 'temp/';
            
            if (!file_exists($temp_dir)) {
                if (!mkdir($temp_dir, 0755, true)) {
                    echo json_encode(['status' => 'error', 'message' => 'Errore permessi cartella']); exit;
                }
            }
            
            // Nome file temporaneo univoco per questo upload
            $temp_file_path = $temp_dir . $fileName . '.part';
            
            // Append del chunk
            $chunkData = file_get_contents($_FILES['file_chunk']['tmp_name']);
            if ($chunkIndex === 0) {
                // Primo pezzo: crea/sovrascrive
                file_put_contents($temp_file_path, $chunkData);
            } else {
                // Pezzi successivi: append
                file_put_contents($temp_file_path, $chunkData, FILE_APPEND);
            }
            
            // Se è l'ultimo pezzo, finalizza
            if ($chunkIndex === $totalChunks - 1) {
                $file_ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $final_name = uniqid('media_') . '.' . $file_ext;
                $final_path = $upload_dir . $final_name;
                $db_path = 'uploads/events/' . $quote_id . '/' . $final_name;
                
                if (rename($temp_file_path, $final_path)) {
                    $insert = "INSERT INTO quote_item_media (quote_item_id, file_path, file_name, file_type) 
                               VALUES ($quote_item_id, '$db_path', '" . mysqli_real_escape_string($db, $fileName) . "', '$file_ext')";
                    if (mysqli_query($db, $insert)) {
                         echo json_encode(['status' => 'complete']);
                    } else {
                         echo json_encode(['status' => 'error', 'message' => 'Errore DB']);
                    }
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Errore rinomina file finale']);
                }
            } else {
                echo json_encode(['status' => 'success']);
            }
            exit;
            break;
            
        case 'delete_media':
            // [CODICE ORIGINALE INVARIATO]
            $quote_id = (int)$_POST['quote_id'];
            $media_id = (int)$_POST['media_id'];
            $res = mysqli_query($db, "SELECT file_path FROM quote_item_media WHERE id = $media_id");
            if ($row = mysqli_fetch_assoc($res)) {
                if (file_exists('../' . $row['file_path'])) {
                    unlink('../' . $row['file_path']);
                }
                mysqli_query($db, "DELETE FROM quote_item_media WHERE id = $media_id");
            }
            header("Location: assign_staff.php?quote_id=$quote_id&success=media_deleted");
            exit;
            break;

        case 'delete_event':
            $quote_id = (int)$_POST['quote_id'];
            $quote_action = $_POST['quote_action'] ?? ''; // form values: 'delete' or 'reject' ('reject' maps to DB status 'rifiutato')

            if (!in_array($quote_action, ['delete', 'reject'], true)) {
                header("Location: assign_staff.php?quote_id=$quote_id&error=delete_failed");
                exit;
            }

            $check_query = mysqli_query($db, "SELECT cloned_from FROM quotes WHERE id = $quote_id");
            $check = $check_query ? mysqli_fetch_assoc($check_query) : null;
            if (!$check_query || !$check) {
                header("Location: assign_staff.php?error=event_not_found");
                exit;
            }

            if (empty($check['cloned_from'])) {
                header("Location: assign_staff.php?quote_id=$quote_id&error=not_cloned");
                exit;
            }

            mysqli_begin_transaction($db);
            try {
                $run_query = function($sql) use ($db) {
                    $result = mysqli_query($db, $sql);
                    if ($result === false) {
                        throw new Exception(mysqli_error($db));
                    }
                    return $result;
                };

                $safe_unlink = function($file_path) {
                    if (empty($file_path)) return;
                    $base_dir = realpath(dirname(__DIR__) . '/uploads');
                    if ($base_dir === false) return;
                    $real_path = realpath(dirname(__DIR__) . '/' . ltrim($file_path, '/'));
                    if ($real_path !== false && strpos($real_path, $base_dir) === 0 && file_exists($real_path)) {
                        @unlink($real_path);
                    }
                };

                $delete_quote_data = function($target_quote_id) use ($run_query, $safe_unlink) {
                    $target_quote_id = (int)$target_quote_id;

                    $items_result = $run_query("SELECT id FROM quote_items WHERE quote_id = $target_quote_id");
                    while ($item = mysqli_fetch_assoc($items_result)) {
                        $item_id = (int)$item['id'];
                        $run_query("DELETE FROM quote_item_services WHERE quote_item_id = $item_id");
                        $run_query("DELETE FROM quote_item_roles WHERE quote_item_id = $item_id");

                        $media_res = $run_query("SELECT file_path FROM quote_item_media WHERE quote_item_id = $item_id");
                        while ($media = mysqli_fetch_assoc($media_res)) {
                            $safe_unlink($media['file_path']);
                        }
                        $run_query("DELETE FROM quote_item_media WHERE quote_item_id = $item_id");
                    }

                    $run_query("DELETE FROM quote_items WHERE quote_id = $target_quote_id");
                    $run_query("DELETE FROM quote_staff_assignment WHERE quote_id = $target_quote_id");
                    $run_query("DELETE FROM quote_service_costs WHERE quote_id = $target_quote_id");

                    $att_result = $run_query("SELECT file_path FROM quote_attachments WHERE quote_id = $target_quote_id");
                    while ($att = mysqli_fetch_assoc($att_result)) {
                        $safe_unlink($att['file_path']);
                    }
                    $run_query("DELETE FROM quote_attachments WHERE quote_id = $target_quote_id");
                    $run_query("DELETE FROM quote_history WHERE quote_id = $target_quote_id");
                    $run_query("DELETE FROM quote_dates WHERE quote_id = $target_quote_id");
                    $run_query("DELETE FROM quotes WHERE id = $target_quote_id");
                };

                $cloned_from_id = (int)$check['cloned_from'];
                if ($quote_action === 'delete') {
                    $delete_quote_data($cloned_from_id);
                    $success_msg = 'event_and_quote_deleted';
                } else {
                    $run_query("UPDATE quotes SET status = 'rifiutato' WHERE id = $cloned_from_id");
                    $success_msg = 'event_deleted_quote_rejected';
                }

                $delete_quote_data($quote_id);

                mysqli_commit($db);
                header("Location: assign_staff.php?success=$success_msg");
                exit;
            } catch (Exception $e) {
                mysqli_rollback($db);
                header("Location: assign_staff.php?quote_id=$quote_id&error=delete_failed");
                exit;
            }
            break;
    }
}

// CARICAMENTO DATI (Tutto invariato)
$quote_detail = null;
if (isset($_GET['quote_id'])) {
    $quote_id = (int)$_GET['quote_id'];
    $query = "SELECT q.*, c.company_name, c.first_name, c.last_name, c.phone, c.address, u.username as commercial_name, u.id as commercial_id FROM quotes q JOIN clients c ON q.client_id = c.id JOIN users u ON q.created_by = u.id WHERE q.id = $quote_id AND q.status = 'confermato'";
    $result = mysqli_query($db, $query);
    if (!$result) { $error = "Errore caricamento preventivo: " . mysqli_error($db); } else { $quote_detail = mysqli_fetch_assoc($result); }
    if ($quote_detail) {
        $quote_detail['no_commission'] = $quote_detail['no_commission'] ?? 0;
        if ($quote_detail['commercial_commission'] == 0 && $quote_detail['no_commission'] == 0) {
            $calc_res = mysqli_query($db, "SELECT SUM(p.commission) as total_comm FROM quote_items qi JOIN packages p ON qi.package_id = p.id WHERE qi.quote_id = $quote_id");
            $auto_comm = (float)mysqli_fetch_assoc($calc_res)['total_comm'];
            if ($auto_comm > 0) {
                mysqli_query($db, "UPDATE quotes SET commercial_commission = $auto_comm, commission_type = 'cost' WHERE id = $quote_id");
                $quote_detail['commercial_commission'] = $auto_comm;
                $quote_detail['commission_type'] = 'cost';
            }
        }
        if ($quote_detail['invoice_amount'] == 0 && $quote_detail['extra_amount'] == 0) { $quote_detail['invoice_amount'] = $quote_detail['total']; $quote_detail['extra_amount'] = 0; }
        $quote_detail['staff_management_status'] = $quote_detail['staff_management_status'] ?? 'pending';
        $items_result = mysqli_query($db, "SELECT qi.*, p.has_graphics as pkg_has_graphics FROM quote_items qi LEFT JOIN packages p ON qi.package_id = p.id WHERE qi.quote_id = $quote_id ORDER BY qi.sort_order");
        $quote_detail['items'] = [];
        if ($items_result) {
            while ($row = mysqli_fetch_assoc($items_result)) {
                if (empty($row['public_token'])) {
                    $token = bin2hex(random_bytes(32));
                    mysqli_query($db, "UPDATE quote_items SET public_token = '$token' WHERE id = {$row['id']}");
                    $row['public_token'] = $token;
                }
                $media_res = mysqli_query($db, "SELECT * FROM quote_item_media WHERE quote_item_id = {$row['id']} ORDER BY uploaded_at DESC");
                $row['media'] = [];
                while($m = mysqli_fetch_assoc($media_res)) $row['media'][] = $m;
                $item_roles_result = mysqli_query($db, "SELECT qir.role_id, qir.role_name, qir.quantity FROM quote_item_roles qir WHERE qir.quote_item_id = {$row['id']}");
                $row['roles'] = [];
                while ($role = mysqli_fetch_assoc($item_roles_result)) $row['roles'][] = $role;
                $item_services_result = mysqli_query($db, "SELECT * FROM quote_item_services WHERE quote_item_id = {$row['id']} ORDER BY sort_order");
                $row['services'] = [];
                while ($service = mysqli_fetch_assoc($item_services_result)) $row['services'][] = $service;
                $item_staff_result = mysqli_query($db, "SELECT qsa.*, s.first_name, s.last_name, sr.name as role_name FROM quote_staff_assignment qsa LEFT JOIN staff s ON qsa.staff_id = s.id LEFT JOIN staff_roles sr ON qsa.role_id = sr.id WHERE qsa.quote_item_id = {$row['id']} ORDER BY qsa.id ASC");
                $row['assigned_staff'] = [];
                while ($staff = mysqli_fetch_assoc($item_staff_result)) $row['assigned_staff'][] = $staff;
                $quote_detail['items'][] = $row;
            }
        }
        $assigned_result = mysqli_query($db, "SELECT qsa.*, s.first_name, s.last_name, sr.name as role_name FROM quote_staff_assignment qsa LEFT JOIN staff s ON qsa.staff_id = s.id LEFT JOIN staff_roles sr ON qsa.role_id = sr.id WHERE qsa.quote_id = $quote_id ORDER BY qsa.id ASC");
        $quote_detail['assigned_staff'] = [];
        while ($row = mysqli_fetch_assoc($assigned_result)) $quote_detail['assigned_staff'][] = $row;
        $extra_staff_result = mysqli_query($db, "SELECT qsa.*, s.first_name, s.last_name, sr.name as role_name FROM quote_staff_assignment qsa LEFT JOIN staff s ON qsa.staff_id = s.id LEFT JOIN staff_roles sr ON qsa.role_id = sr.id WHERE qsa.quote_id = $quote_id AND qsa.quote_item_id IS NULL ORDER BY qsa.id ASC");
        $quote_detail['extra_staff'] = [];
        while ($row = mysqli_fetch_assoc($extra_staff_result)) $quote_detail['extra_staff'][] = $row;
        $service_costs_result = mysqli_query($db, "SELECT * FROM quote_service_costs WHERE quote_id = $quote_id ORDER BY service_name");
        $quote_detail['service_costs'] = [];
        while ($row = mysqli_fetch_assoc($service_costs_result)) $quote_detail['service_costs'][] = $row;
        $quote_detail['total_staff_cost'] = array_sum(array_column($quote_detail['assigned_staff'], 'cost'));
        $quote_detail['total_staff_extra'] = array_sum(array_column($quote_detail['assigned_staff'], 'extra'));
        $quote_detail['total_services_cost'] = 0;
        $quote_detail['total_services_extra'] = 0;
        foreach ($quote_detail['service_costs'] as $service_cost) {
            $quote_detail['total_services_cost'] += $service_cost['cost'];
            $quote_detail['total_services_extra'] += $service_cost['extra'];
        }
    }
}
$quotes_to_assign = [];
$show_past = isset($_GET['show_past']) ? true : false;
$five_days_ago = date('Y-m-d', strtotime('-5 days'));
$query = "SELECT 
              q.*, 
              c.company_name, c.first_name, c.last_name,
              -- Calcola il totale come somma di invoice_amount e extra_amount
              (COALESCE(q.invoice_amount, 0) + COALESCE(q.extra_amount, 0)) as final_total,
              (SELECT MIN(event_date) FROM quote_items WHERE quote_id = q.id AND event_date IS NOT NULL) as first_event_date,
              (SELECT COUNT(*) FROM quote_staff_assignment WHERE quote_id = q.id) as assigned_count
          FROM 
              quotes q 
          JOIN 
              clients c ON q.client_id = c.id 
          WHERE 
              q.status = 'confermato'";
if (!$show_past) {
    $query .= " AND (SELECT MIN(event_date) FROM quote_items WHERE quote_id = q.id AND event_date IS NOT NULL) >= '$five_days_ago'";
}
$query .= " ORDER BY first_event_date ASC";
$result = mysqli_query($db, $query);
if ($result) while ($row = mysqli_fetch_assoc($result)) $quotes_to_assign[] = $row;
$all_roles = [];
$roles_result = mysqli_query($db, "SELECT * FROM staff_roles ORDER BY name");
if ($roles_result) while ($row = mysqli_fetch_assoc($roles_result)) $all_roles[] = $row;
$staff_by_role = [];
$staff_result = mysqli_query($db, "SELECT s.*, shr.role_id, sr.name as role_name FROM staff s LEFT JOIN staff_has_roles shr ON s.id = shr.staff_id LEFT JOIN staff_roles sr ON shr.role_id = sr.id WHERE s.is_active = 1 ORDER BY s.last_name, s.first_name");
if ($staff_result) while ($row = mysqli_fetch_assoc($staff_result)) if ($row['role_id']) $staff_by_role[$row['role_id']][] = $row;
$pageTitle = 'Gestione Staff Eventi';
include '../includes/header.php';
?>

<style>
.info-box { background: #e7f3ff; border-left: 4px solid #0d6efd; padding: 12px; margin-bottom: 15px; font-size: 0.9rem; }
.table-striped-custom tbody tr:nth-of-type(odd) { background-color: rgba(0,0,0,.02); }
.package-section { border: 2px solid #0d6efd; border-radius: 8px; padding: 15px; margin-bottom: 20px; background: #f8f9fa; }
.package-header { background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%); color: white; padding: 15px; margin: -15px -15px 15px -15px; border-radius: 6px 6px 0 0; }
.package-datetime-badge { background: rgba(255,255,255,0.2); padding: 8px 12px; border-radius: 6px; display: inline-block; margin-top: 8px; font-size: 0.95rem; }
.package-location-badge { background: rgba(255,255,255,0.15); padding: 6px 10px; border-radius: 4px; display: inline-block; margin-top: 5px; font-size: 0.85rem; }
.global-save-btn { position: fixed; bottom: 30px; right: 30px; z-index: 1000; box-shadow: 0 4px 20px rgba(0,0,0,0.3); animation: pulse 2s infinite; }
@keyframes pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.05); } }
.extra-staff-section { border: 2px solid #ffc107; border-radius: 8px; padding: 15px; margin-bottom: 20px; background: #fff9e6; }
.extra-row { background-color: #fff3cd !important; }
</style>

<div class="container-fluid py-4">
    <h2 class="mb-4"><i class="bi bi-clipboard-check"></i> Gestione Staff Eventi</h2>
    
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle"></i> <strong>ERRORE:</strong> <?php echo e($error); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle"></i> <?php switch ($_GET['success']) { case 'staff_assigned': echo '✅ SALVATO!'; break; case 'amounts': echo '✅ Importi salvati!'; break; case 'commission': echo '✅ Provvigione salvata!'; break; case 'deposit': echo '✅ Acconto salvato!'; break; case 'status': echo '✅ Stato aggiornato!'; break; case 'notes': echo '✅ Note salvate!'; break; case 'graphics': echo '✅ Stato grafiche aggiornato!'; break; case 'media_uploaded': echo '✅ File caricato!'; break; case 'media_deleted': echo '✅ File eliminato!'; break; case 'event_cloned': echo '✅ Evento clonato con successo!'; break; case 'event_deleted': echo '✅ Evento eliminato con successo!'; break; case 'event_and_quote_deleted': echo '✅ Evento e preventivo eliminati con successo'; break; case 'event_deleted_quote_rejected': echo '✅ Evento eliminato, preventivo impostato come rifiutato'; break; default: echo '✅ Operazione completata!'; } ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    
    <?php if ($quote_detail): ?>
    
    <!-- Toolbar superiore -->
    <div class="mb-3">
        <div class="d-flex justify-content-between align-items-center">
            <a href="assign_staff.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Torna alla lista
            </a>

            <div class="d-flex gap-2">
                <?php $is_cloned = !empty($quote_detail['cloned_from']); ?>

                <!-- BOTTONE ELIMINA EVENTO -->
                <?php if ($is_cloned): ?>
                <button type="button" class="btn btn-danger"
                        onclick="openDeleteModal(<?php echo $quote_id; ?>, '<?php echo e($quote_detail['quote_number']); ?>', '<?php echo e($quote_detail['company_name'] ?: trim(($quote_detail['first_name'] ?? '') . ' ' . ($quote_detail['last_name'] ?? ''))); ?>')">
                    <i class="bi bi-trash"></i> Elimina Evento
                </button>
                <?php endif; ?>

                <div class="btn-group">
                    <a href="export_pdf_full.php?quote_id=<?php echo $quote_id; ?>" class="btn btn-primary" target="_blank">
                        <i class="bi bi-file-pdf"></i> PDF Intero
                    </a>
                    <a href="export_pdf_okl.php?quote_id=<?php echo $quote_id; ?>" class="btn btn-success" target="_blank">
                        <i class="bi bi-file-pdf"></i> PDF OKL
                    </a>
                </div>
            </div>
        </div>
    </div>
        
    <div class="row mb-3">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header bg-info text-white py-2"><h6 class="mb-0"><i class="bi bi-person"></i> Cliente</h6></div>
                <div class="card-body py-2">
                    <table class="table table-sm table-borderless mb-0">
                        <?php if ($quote_detail['company_name']): ?><tr><th style="width: 35%;">Ragione Sociale</th><td><?php echo e($quote_detail['company_name']); ?></td></tr><?php endif; ?>
                        <?php if ($quote_detail['first_name'] || $quote_detail['last_name']): ?><tr><th style="width: 35%;">Nome e Cognome</th><td><?php echo e(trim($quote_detail['first_name'] . ' ' . $quote_detail['last_name'])); ?></td></tr><?php endif; ?>
                        <?php if ($quote_detail['phone']): ?><tr><th>Telefono</th><td><?php echo e($quote_detail['phone']); ?></td></tr><?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header bg-secondary text-white py-2"><h6 class="mb-0"><i class="bi bi-geo-alt"></i> Luogo Evento</h6></div>
                <div class="card-body py-2 d-flex align-items-center justify-content-center">
                    <?php if (!empty($quote_detail['event_location'])): ?><p class="mb-0 text-center"><strong class="fs-5"><?php echo e($quote_detail['event_location']); ?></strong></p><?php else: ?><p class="text-muted mb-0">Non specificato</p><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
        
    <div class="row">
        <div class="col-md-4">
            <div class="card mb-3">
                <div class="card-header bg-success text-white"><h6 class="mb-0">Gestione</h6></div>
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><th style="width: 50%;">N° Preventivo</th><td><strong><?php echo e($quote_detail['quote_number']); ?></strong></td></tr>
                        <tr><th>Stato Evento</th><td><?php echo getStatusBadge($quote_detail['status']); ?></td></tr>
                        <tr><th>Venditore</th><td><?php echo e($quote_detail['commercial_name']); ?></td></tr>
                    </table>
                    <hr>
                    <form method="POST" class="mb-3">
                        <input type="hidden" name="action" value="update_staff_status">
                        <input type="hidden" name="quote_id" value="<?php echo $quote_id; ?>">
                        <label class="form-label small fw-bold">Gestione Personale</label>
                        <div class="d-flex gap-2">
                            <select name="staff_status" class="form-select form-select-sm flex-grow-1" required>
                                <option value="pending" <?php echo $quote_detail['staff_management_status'] == 'pending' ? 'selected' : ''; ?>>DA COMPLETARE</option>
                                <option value="completed" <?php echo $quote_detail['staff_management_status'] == 'completed' ? 'selected' : ''; ?>>COMPLETATO</option>
                            </select>
                            <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check"></i></button>
                        </div>
                    </form>
                    <?php if ($quote_detail['staff_management_status'] == 'completed'): ?><div class="alert alert-success py-2 mb-0 text-center"><i class="bi bi-check-circle"></i> <strong>COMPLETATO</strong></div><?php endif; ?>
                    <hr>
                    <form method="POST" class="mt-3">
                        <input type="hidden" name="action" value="update_amounts">
                        <input type="hidden" name="quote_id" value="<?php echo $quote_id; ?>">
                        <div class="mb-2"><label class="form-label small fw-bold">Importo Fattura €</label><input type="number" step="0.01" name="invoice_amount" class="form-control form-control-sm" value="<?php echo $quote_detail['invoice_amount']; ?>" required></div>
                        <div class="mb-2"><label class="form-label small fw-bold">Importo Extra €</label><input type="number" step="0.01" name="extra_amount" class="form-control form-control-sm" value="<?php echo $quote_detail['extra_amount']; ?>" required></div>
                        <button type="submit" class="btn btn-success btn-sm w-100"><i class="bi bi-save"></i> Salva Suddivisione</button>
                    </form>
                </div>
            </div>
            
            <div class="card mb-3">
                <div class="card-header bg-warning"><h6 class="mb-0">Conteggio Spese</h6></div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><th>Costo Persone</th><td class="text-end"><?php echo formatPrice($quote_detail['total_staff_cost']); ?></td></tr>
                        <tr><th>Extra Persone</th><td class="text-end"><?php echo formatPrice($quote_detail['total_staff_extra']); ?></td></tr>
                        <tr><th>Costo Servizi</th><td class="text-end"><?php echo formatPrice($quote_detail['total_services_cost']); ?></td></tr>
                        <tr><th>Extra Servizi</th><td class="text-end"><?php echo formatPrice($quote_detail['total_services_extra']); ?></td></tr>
                        <?php if ($quote_detail['commercial_commission'] > 0 && !$quote_detail['no_commission']): ?>
                        <tr class="text-info border-top"><th><i class="bi bi-cash-coin"></i> Provvigione<br><small>(<?php echo $quote_detail['commission_type'] == 'cost' ? 'Costo' : 'Extra'; ?>)</small></th><td class="text-end text-info"><?php echo formatPrice($quote_detail['commercial_commission']); ?></td></tr>
                        <?php endif; ?>
                        <tr class="table-active border-top border-2"><th>COSTO TOTALE</th><td class="text-end"><?php $total_cost_with_commission = $quote_detail['total_staff_cost'] + $quote_detail['total_services_cost']; $total_extra_with_commission = $quote_detail['total_staff_extra'] + $quote_detail['total_services_extra']; if (!$quote_detail['no_commission']) { if ($quote_detail['commission_type'] == 'cost') $total_cost_with_commission += $quote_detail['commercial_commission']; else $total_extra_with_commission += $quote_detail['commercial_commission']; } $grand_total = $total_cost_with_commission + $total_extra_with_commission; ?><strong><?php echo formatPrice($grand_total); ?></strong></td></tr>
                    </table>
                </div>
            </div>
            
            <div class="card mb-3">
                <div class="card-header bg-success text-white"><h6 class="mb-0">Conteggio Utile</h6></div>
                <div class="card-body">
                    <?php $utile_fattura = $quote_detail['invoice_amount'] - $total_cost_with_commission; $utile_extra = $quote_detail['extra_amount'] - $total_extra_with_commission; ?>
                    <table class="table table-sm mb-0">
                        <tr><th>Utile Fattura</th><td class="text-end"><strong class="<?php echo $utile_fattura < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo formatPrice($utile_fattura); ?></strong></td></tr>
                        <tr><th>Utile Extra</th><td class="text-end"><strong class="<?php echo $utile_extra < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo formatPrice($utile_extra); ?></strong></td></tr>
                    </table>
                </div>
            </div>
            
            <div class="card mb-3 border-info">
                <div class="card-header bg-info text-white"><h6 class="mb-0"><i class="bi bi-cash-coin"></i> Provvigione Commerciale</h6></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_commission">
                        <input type="hidden" name="quote_id" value="<?php echo $quote_id; ?>">
                        <div class="mb-2"><label class="form-label small">Commerciale: <strong><?php echo e($quote_detail['commercial_name']); ?></strong></label></div>
                        <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="noCommissionCheck" name="no_commission" value="1" <?php echo $quote_detail['no_commission'] ? 'checked' : ''; ?>><label class="form-check-label small fw-bold" for="noCommissionCheck">Nessuna Provvigione</label></div>
                        <div class="row g-2 mb-2">
                            <div class="col-7"><label class="form-label small fw-bold">Tipo</label><select name="commission_type" id="commissionTypeSelect" class="form-select form-select-sm" required <?php echo $quote_detail['no_commission'] ? 'disabled' : ''; ?>><option value="cost" <?php echo $quote_detail['commission_type'] == 'cost' ? 'selected' : ''; ?>>Da Costi</option><option value="extra" <?php echo $quote_detail['commission_type'] == 'extra' ? 'selected' : ''; ?>>Da Extra</option></select></div>
                            <div class="col-5"><label class="form-label small fw-bold">Importo €</label><input type="number" step="0.01" name="commission" id="commissionInput" class="form-control form-control-sm" value="<?php echo $quote_detail['commercial_commission']; ?>" required <?php echo $quote_detail['no_commission'] ? 'disabled' : ''; ?>></div>
                        </div>
                        <button type="submit" class="btn btn-info btn-sm w-100"><i class="bi bi-save"></i> Salva</button>
                    </form>
                </div>
            </div>
            
            <div class="card mb-3 border-success">
                <div class="card-header bg-success text-white"><h6 class="mb-0"><i class="bi bi-cash-stack"></i> Acconto</h6></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_deposit">
                        <input type="hidden" name="quote_id" value="<?php echo $quote_id; ?>">
                        <div class="row g-2 mb-2">
                            <div class="col-7"><label class="form-label small fw-bold">Scalato da</label><select name="deposit_type" class="form-select form-select-sm" required><option value="cost" <?php echo ($quote_detail['deposit_type'] ?? 'cost') == 'cost' ? 'selected' : ''; ?>>Costo</option><option value="extra" <?php echo ($quote_detail['deposit_type'] ?? 'cost') == 'extra' ? 'selected' : ''; ?>>Extra</option></select></div>
                            <div class="col-5"><label class="form-label small fw-bold">Importo €</label><input type="number" step="0.01" name="deposit_amount" class="form-control form-control-sm" value="<?php echo $quote_detail['deposit_amount'] ?? 0; ?>" required></div>
                        </div>
                        <button type="submit" class="btn btn-success btn-sm w-100"><i class="bi bi-save"></i> Salva Acconto</button>
                    </form>
                </div>
            </div>

            <?php foreach($quote_detail['items'] as $item): ?>
            <div class="card mb-3 border-primary">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 text-truncate" title="<?php echo e($item['package_name']); ?>"><i class="bi bi-palette"></i> Grafiche: <?php echo e($item['package_name']); ?></h6>
                    <button class="btn btn-sm btn-light py-0 px-2 text-primary" type="button" data-bs-toggle="collapse" data-bs-target="#graphicsCollapse<?php echo $item['id']; ?>"><i class="bi bi-chevron-down"></i></button>
                </div>
                <div id="graphicsCollapse<?php echo $item['id']; ?>" class="collapse show">
                    <div class="card-body">
                        <form method="POST" class="mb-3">
                            <input type="hidden" name="action" value="update_graphics">
                            <input type="hidden" name="quote_id" value="<?php echo $quote_id; ?>">
                            <input type="hidden" name="quote_item_id" value="<?php echo $item['id']; ?>">
                            <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="graphics_included" id="gfxIncl<?php echo $item['id']; ?>" value="1" <?php echo $item['graphics_included'] ? 'checked' : ''; ?>><label class="form-check-label fw-bold small" for="gfxIncl<?php echo $item['id']; ?>">Grafiche Incluse nel pacchetto</label></div>
                            <div class="d-flex justify-content-between mb-2 ps-3 border-start border-3">
                                <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="graphics_created" id="gfxCreated<?php echo $item['id']; ?>" value="1" <?php echo $item['graphics_created'] ? 'checked' : ''; ?>><label class="form-check-label small" for="gfxCreated<?php echo $item['id']; ?>">Create</label></div>
                                <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="graphics_sponsored" id="gfxSponsored<?php echo $item['id']; ?>" value="1" <?php echo $item['graphics_sponsored'] ? 'checked' : ''; ?>><label class="form-check-label small" for="gfxSponsored<?php echo $item['id']; ?>">Programmate</label></div>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm w-100">Aggiorna Stato</button>
                        </form>

                        <hr class="my-2">
                        <label class="small fw-bold mb-1">Materiali & Media</label>
                        <form id="uploadForm<?php echo $item['id']; ?>" class="mb-2" onsubmit="uploadMedia(event, <?php echo $item['id']; ?>, <?php echo $quote_id; ?>)">
                            <div class="input-group input-group-sm">
                                <input type="file" name="media_files[]" id="mediaFile<?php echo $item['id']; ?>" class="form-control" multiple required>
                                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-upload"></i></button>
                            </div>
                            <small class="text-muted" style="font-size: 0.75rem;">Puoi selezionare più file insieme. (Max 300MB/file)</small>
                        </form>

                        <?php if(!empty($item['media'])): ?>
                        <ul class="list-group list-group-flush small mb-2 border rounded" style="max-height: 150px; overflow-y: auto;">
                            <?php foreach($item['media'] as $file): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center p-2">
                                <a href="../<?php echo $file['file_path']; ?>" target="_blank" class="text-truncate text-decoration-none" style="max-width: 180px;" title="<?php echo e($file['file_name']); ?>">
                                    <?php if(in_array($file['file_type'], ['jpg','png','jpeg','webp'])) echo '<i class="bi bi-image"></i> '; else echo '<i class="bi bi-file-earmark"></i> '; ?><?php echo e($file['file_name']); ?>
                                </a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questo file?');">
                                    <input type="hidden" name="action" value="delete_media">
                                    <input type="hidden" name="quote_id" value="<?php echo $quote_id; ?>">
                                    <input type="hidden" name="media_id" value="<?php echo $file['id']; ?>">
                                    <button type="submit" class="btn btn-link text-danger p-0 ms-2"><i class="bi bi-x-circle"></i></button>
                                </form>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>

                        <div class="bg-light p-2 rounded border mt-2">
                            <label class="small fw-bold d-block text-muted mb-1">Link Cliente Pubblico</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control bg-white" readonly value="https://beatitaliano.it/event/<?php echo $item['public_token']; ?>" id="publicLink<?php echo $item['id']; ?>">
                                <button class="btn btn-outline-secondary" onclick="copyLink('publicLink<?php echo $item['id']; ?>')"><i class="bi bi-clipboard"></i></button>
                                <a href="https://beatitaliano.it/event/<?php echo $item['public_token']; ?>" target="_blank" class="btn btn-outline-primary"><i class="bi bi-eye"></i></a>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <div class="card mb-3 border-secondary">
                <div class="card-header bg-secondary text-white"><h6 class="mb-0"><i class="bi bi-sticky"></i> Note Preventivo</h6></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_notes">
                        <input type="hidden" name="quote_id" value="<?php echo $quote_id; ?>">
                        <div class="mb-2"><textarea name="internal_notes" class="form-control form-control-sm" rows="4" placeholder="Inserisci note per questo preventivo..."><?php echo e($quote_detail['internal_notes'] ?? ''); ?></textarea></div>
                        <button type="submit" class="btn btn-secondary btn-sm w-100"><i class="bi bi-save"></i> Salva Note</button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <form method="POST" id="globalForm">
                <input type="hidden" name="action" value="assign_staff">
                <input type="hidden" name="quote_id" value="<?php echo $quote_id; ?>">
                
                <?php $assignment_index = 0; $service_index = 0; $staff_used = []; foreach ($quote_detail['items'] as $pkg_idx => $package): $pkg_staff_cost = 0; $pkg_staff_extra = 0; foreach ($package['assigned_staff'] as $person) { $pkg_staff_cost += (float)$person['cost']; $pkg_staff_extra += (float)$person['extra']; } $pkg_services_cost = 0; $pkg_services_extra = 0; foreach ($quote_detail['service_costs'] as $service) { if ($service['quote_item_id'] == $package['id']) { $pkg_services_cost += (float)$service['cost']; $pkg_services_extra += (float)$service['extra']; } } $pkg_total_cost = $pkg_staff_cost + $pkg_services_cost; $pkg_total_extra = $pkg_staff_extra + $pkg_services_extra; $pkg_grand_total = $pkg_total_cost + $pkg_total_extra; ?>
                
                <div class="package-section">
                    <div class="package-header">
                        <h5 class="mb-0"><i class="bi bi-box-seam"></i> Pacchetto <?php echo ($pkg_idx + 1); ?>: <?php echo e($package['package_name']); ?></h5>
                        <?php if (!empty($package['event_date'])): ?><div class="package-datetime-badge"><i class="bi bi-calendar-event"></i> <strong><?php echo date('d/m/Y', strtotime($package['event_date'])); ?></strong><?php if (!empty($package['event_time'])): ?> • <i class="bi bi-clock"></i> <strong><?php echo substr($package['event_time'], 0, 5); ?></strong><?php endif; ?></div><?php endif; ?>
                        <?php if (!empty($quote_detail['event_location'])): ?><div class="package-location-badge"><i class="bi bi-geo-alt"></i> <?php echo e($quote_detail['event_location']); ?></div><?php endif; ?>
                    </div>
                    
                    <h6 class="text-primary mb-3"><i class="bi bi-people"></i> Ruoli Richiesti</h6>
                    
                    <?php if (!empty($package['roles'])): ?>
                    <div class="info-box"><strong><i class="bi bi-info-circle"></i> Ruoli da assegnare:</strong><?php foreach ($package['roles'] as $pkg_role): ?><span class="badge bg-primary ms-1"><?php echo $pkg_role['quantity']; ?>x <?php echo e($pkg_role['role_name']); ?></span><?php endforeach; ?></div>
                    
                    <div class="table-responsive mb-2">
                        <table class="table table-sm table-striped-custom">
                            <thead class="table-dark"><tr><th style="width: 15%;">Ruolo</th><th style="width: 25%;">Nome/Cognome</th><th style="width: 12%;">Costo €</th><th style="width: 12%;">Extra €</th><th style="width: 20%;">Note</th><th style="width: 16%;"></th></tr></thead>
                            <tbody id="staffTableBody<?php echo $pkg_idx; ?>">
                                <?php foreach ($package['roles'] as $pkg_role): for ($qty = 0; $qty < $pkg_role['quantity']; $qty++): $existing = null; foreach ($package['assigned_staff'] as $idx => $assigned) { if ($assigned['role_id'] == $pkg_role['role_id'] && !in_array('pkg_'.$package['id'].'_'.$idx, $staff_used)) { $existing = $assigned; $staff_used[] = 'pkg_'.$package['id'].'_'.$idx; break; } } ?>
                                <tr>
                                    <td><input type="hidden" name="assignments[<?php echo $assignment_index; ?>][quote_item_id]" value="<?php echo $package['id']; ?>"><select name="assignments[<?php echo $assignment_index; ?>][role_id]" class="form-select form-select-sm" onchange="filterStaffByRole(this, <?php echo $assignment_index; ?>)"><?php foreach ($all_roles as $role): ?><option value="<?php echo $role['id']; ?>" <?php echo $pkg_role['role_id'] == $role['id'] ? 'selected' : ''; ?>><?php echo e($role['name']); ?></option><?php endforeach; ?></select></td>
                                    <td><select name="assignments[<?php echo $assignment_index; ?>][staff_id]" class="form-select form-select-sm staff-select-<?php echo $assignment_index; ?>" onchange="updateCosts(this, <?php echo $assignment_index; ?>)"><option value="">-- Seleziona --</option><?php if (isset($staff_by_role[$pkg_role['role_id']])): foreach ($staff_by_role[$pkg_role['role_id']] as $staff): ?><option value="<?php echo $staff['id']; ?>" data-cost="<?php echo $staff['default_cost']; ?>" data-extra="<?php echo $staff['default_extra']; ?>" <?php echo ($existing && $existing['staff_id'] == $staff['id']) ? 'selected' : ''; ?>><?php echo e($staff['first_name'] . ' ' . $staff['last_name']); ?></option><?php endforeach; endif; ?></select></td>
                                    <td><input type="number" step="0.01" name="assignments[<?php echo $assignment_index; ?>][cost]" class="form-control form-control-sm cost-input-<?php echo $assignment_index; ?> staff-cost-<?php echo $package['id']; ?>" value="<?php echo $existing ? $existing['cost'] : '0'; ?>"></td>
                                    <td><input type="number" step="0.01" name="assignments[<?php echo $assignment_index; ?>][extra]" class="form-control form-control-sm extra-input-<?php echo $assignment_index; ?> staff-extra-<?php echo $package['id']; ?>" value="<?php echo $existing ? $existing['extra'] : '0'; ?>"></td>
                                    <td><input type="text" name="assignments[<?php echo $assignment_index; ?>][notes]" class="form-control form-control-sm" value="<?php echo e($existing['notes'] ?? ''); ?>" placeholder="FATTURA"></td>
                                    <td><button type="button" class="btn btn-sm btn-danger" onclick="removeStaffRow(this)"><i class="bi bi-trash"></i></button></td>
                                </tr>
                                <?php $assignment_index++; endfor; endforeach; foreach ($package['assigned_staff'] as $idx => $assigned): if (in_array('pkg_'.$package['id'].'_'.$idx, $staff_used)) continue; $staff_used[] = 'pkg_'.$package['id'].'_'.$idx; ?>
                                <tr class="extra-row">
                                    <td><input type="hidden" name="assignments[<?php echo $assignment_index; ?>][quote_item_id]" value="<?php echo $package['id']; ?>"><select name="assignments[<?php echo $assignment_index; ?>][role_id]" class="form-select form-select-sm" onchange="filterStaffByRole(this, <?php echo $assignment_index; ?>)"><?php foreach ($all_roles as $role): ?><option value="<?php echo $role['id']; ?>" <?php echo $assigned['role_id'] == $role['id'] ? 'selected' : ''; ?>><?php echo e($role['name']); ?></option><?php endforeach; ?></select></td>
                                    <td><select name="assignments[<?php echo $assignment_index; ?>][staff_id]" class="form-select form-select-sm staff-select-<?php echo $assignment_index; ?>" onchange="updateCosts(this, <?php echo $assignment_index; ?>)"><option value="">-- Seleziona --</option><?php if (isset($staff_by_role[$assigned['role_id']])): foreach ($staff_by_role[$assigned['role_id']] as $staff): ?><option value="<?php echo $staff['id']; ?>" data-cost="<?php echo $staff['default_cost']; ?>" data-extra="<?php echo $staff['default_extra']; ?>" <?php echo ($assigned['staff_id'] == $staff['id']) ? 'selected' : ''; ?>><?php echo e($staff['first_name'] . ' ' . $staff['last_name']); ?></option><?php endforeach; endif; ?></select></td>
                                    <td><input type="number" step="0.01" name="assignments[<?php echo $assignment_index; ?>][cost]" class="form-control form-control-sm cost-input-<?php echo $assignment_index; ?> staff-cost-<?php echo $package['id']; ?>" value="<?php echo $assigned['cost']; ?>"></td>
                                    <td><input type="number" step="0.01" name="assignments[<?php echo $assignment_index; ?>][extra]" class="form-control form-control-sm extra-input-<?php echo $assignment_index; ?> staff-extra-<?php echo $package['id']; ?>" value="<?php echo $assigned['extra']; ?>"></td>
                                    <td><input type="text" name="assignments[<?php echo $assignment_index; ?>][notes]" class="form-control form-control-sm" value="<?php echo e($assigned['notes'] ?? ''); ?>" placeholder="Note"></td>
                                    <td><button type="button" class="btn btn-sm btn-danger" onclick="removeStaffRow(this)"><i class="bi bi-trash"></i></button></td>
                                </tr>
                                <?php $assignment_index++; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <button type="button" class="btn btn-outline-success btn-sm mb-4" onclick="addStaffRowToPackage(<?php echo $pkg_idx; ?>, <?php echo $package['id']; ?>)"><i class="bi bi-person-plus"></i> Aggiungi Persona a questo Pacchetto</button>
                    <?php endif; ?>
                    
                    <h6 class="text-secondary mb-3"><i class="bi bi-wrench"></i> Servizi Inclusi</h6>
                    
                    <?php if (!empty($package['services'])): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead class="table-secondary"><tr><th style="width: 50%;">Descrizione Servizio</th><th style="width: 15%;">Costo €</th><th style="width: 15%;">Extra €</th><th style="width: 20%;">Note</th></tr></thead>
                            <tbody>
                                <?php foreach ($package['services'] as $service): $saved_cost = null; foreach ($quote_detail['service_costs'] as $saved_service) { if ($saved_service['service_name'] == $service['service_name'] && $saved_service['quote_item_id'] == $package['id']) { $saved_cost = $saved_service; break; } } ?>
                                <tr>
                                    <td><strong><?php echo e($service['service_name']); ?></strong><input type="hidden" name="services[<?php echo $service_index; ?>][name]" value="<?php echo e($service['service_name']); ?>"><input type="hidden" name="services[<?php echo $service_index; ?>][quote_item_id]" value="<?php echo $package['id']; ?>"></td>
                                    <td><input type="number" step="0.01" name="services[<?php echo $service_index; ?>][cost]" class="form-control form-control-sm service-cost-<?php echo $package['id']; ?>" value="<?php echo $saved_cost ? $saved_cost['cost'] : '0'; ?>" required></td>
                                    <td><input type="number" step="0.01" name="services[<?php echo $service_index; ?>][extra]" class="form-control form-control-sm service-extra-<?php echo $package['id']; ?>" value="<?php echo $saved_cost ? $saved_cost['extra'] : '0'; ?>"></td>
                                    <td><input type="text" name="services[<?php echo $service_index; ?>][notes]" class="form-control form-control-sm" value="<?php echo $saved_cost ? e($saved_cost['notes']) : ''; ?>" placeholder="Note"></td>
                                </tr>
                                <?php $service_index++; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?><p class="text-muted">Nessun servizio incluso in questo pacchetto</p><?php endif; ?>

                    <div class="card mt-3 border-primary">
                        <div class="card-body py-2" style="background: linear-gradient(135deg, #e3f2fd 0%, #f8f9fa 100%);">
                            <div class="row g-2 text-center">
                                <div class="col-md-2"><div class="border rounded p-2 bg-white"><small class="text-muted d-block">Persone Costo</small><strong class="text-primary pkg-staff-cost-total"><?php echo formatPrice($pkg_staff_cost); ?></strong></div></div>
                                <div class="col-md-2"><div class="border rounded p-2 bg-white"><small class="text-muted d-block">Persone Extra</small><strong class="text-success pkg-staff-extra-total"><?php echo formatPrice($pkg_staff_extra); ?></strong></div></div>
                                <div class="col-md-2"><div class="border rounded p-2 bg-white"><small class="text-muted d-block">OKL - Agibilità</small><strong class="text-info pkg-services-cost-total"><?php echo formatPrice($pkg_services_cost); ?></strong></div></div>
                                <div class="col-md-2"><div class="border rounded p-2 bg-white"><small class="text-muted d-block">Servizi</small><strong class="text-warning pkg-services-extra-total"><?php echo formatPrice($pkg_services_extra); ?></strong></div></div>
                                <div class="col-md-2"><div class="border rounded p-2 bg-white"><small class="text-muted d-block">Tot. Costi</small><strong class="text-primary pkg-total-cost"><?php echo formatPrice($pkg_total_cost); ?></strong></div></div>
                                <div class="col-md-2"><div class="border rounded p-2 bg-light border-2"><small class="text-muted d-block">TOTALE</small><strong class="text-danger fs-6 pkg-grand-total"><?php echo formatPrice($pkg_grand_total); ?></strong></div></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php endforeach; ?>
                
                <?php if (!empty($quote_detail['extra_staff'])): ?>
                <div class="extra-staff-section">
                    <h5 class="mb-3"><i class="bi bi-person-plus-fill"></i> Staff Globale (Non assegnato a pacchetti specifici)</h5>
                    <div class="alert alert-warning py-2 mb-3"><small><i class="bi bi-info-circle"></i> <strong>Queste persone non sono associate a nessun pacchetto specifico.</strong></small></div>
                    <div class="table-responsive mb-2">
                        <table class="table table-sm table-bordered">
                            <thead class="table-warning"><tr><th style="width: 15%;">Ruolo</th><th style="width: 25%;">Nome/Cognome</th><th style="width: 12%;">Costo €</th><th style="width: 12%;">Extra €</th><th style="width: 20%;">Note</th><th style="width: 16%;"></th></tr></thead>
                            <tbody id="extraStaffTableBody">
                                <?php foreach ($quote_detail['extra_staff'] as $assigned): ?>
                                <tr>
                                    <td><select name="assignments[<?php echo $assignment_index; ?>][role_id]" class="form-select form-select-sm" onchange="filterStaffByRole(this, <?php echo $assignment_index; ?>)"><?php foreach ($all_roles as $role): ?><option value="<?php echo $role['id']; ?>" <?php echo $assigned['role_id'] == $role['id'] ? 'selected' : ''; ?>><?php echo e($role['name']); ?></option><?php endforeach; ?></select></td>
                                    <td><select name="assignments[<?php echo $assignment_index; ?>][staff_id]" class="form-select form-select-sm staff-select-<?php echo $assignment_index; ?>" onchange="updateCosts(this, <?php echo $assignment_index; ?>)"><option value="">-- Seleziona --</option><?php if (isset($staff_by_role[$assigned['role_id']])): foreach ($staff_by_role[$assigned['role_id']] as $staff): ?><option value="<?php echo $staff['id']; ?>" data-cost="<?php echo $staff['default_cost']; ?>" data-extra="<?php echo $staff['default_extra']; ?>" <?php echo ($assigned['staff_id'] == $staff['id']) ? 'selected' : ''; ?>><?php echo e($staff['first_name'] . ' ' . $staff['last_name']); ?></option><?php endforeach; endif; ?></select></td>
                                    <td><input type="number" step="0.01" name="assignments[<?php echo $assignment_index; ?>][cost]" class="form-control form-control-sm cost-input-<?php echo $assignment_index; ?>" value="<?php echo $assigned['cost']; ?>"></td>
                                    <td><input type="number" step="0.01" name="assignments[<?php echo $assignment_index; ?>][extra]" class="form-control form-control-sm extra-input-<?php echo $assignment_index; ?>" value="<?php echo $assigned['extra']; ?>"></td>
                                    <td><input type="text" name="assignments[<?php echo $assignment_index; ?>][notes]" class="form-control form-control-sm" value="<?php echo e($assigned['notes'] ?? ''); ?>" placeholder="Note"></td>
                                    <td><button type="button" class="btn btn-sm btn-danger" onclick="removeStaffRow(this)"><i class="bi bi-trash"></i></button></td>
                                </tr>
                                <?php $assignment_index++; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-success btn-sm" onclick="addGlobalStaffRow()"><i class="bi bi-person-plus"></i> Aggiungi Staff Globale</button>
                </div>
                <?php endif; ?>
                
                <?php $has_extra_services = false; foreach ($quote_detail['service_costs'] as $service_cost): $is_from_package = !empty($service_cost['quote_item_id']); if (!$is_from_package && stripos($service_cost['service_name'], 'AGIBILITÀ') === false) { if (!$has_extra_services) { $has_extra_services = true; echo '<div class="card mb-3"><div class="card-header bg-warning"><h6 class="mb-0">Servizi Extra</h6></div><div class="card-body"><table class="table table-sm"><thead><tr><th>Servizio</th><th>Costo</th><th>Extra</th><th>Note</th><th></th></tr></thead><tbody>'; } ?>
                <tr>
                    <td><input type="text" name="services[<?php echo $service_index; ?>][name]" class="form-control form-control-sm" value="<?php echo e($service_cost['service_name']); ?>" required></td>
                    <td><input type="number" step="0.01" name="services[<?php echo $service_index; ?>][cost]" class="form-control form-control-sm" value="<?php echo $service_cost['cost']; ?>" required></td>
                    <td><input type="number" step="0.01" name="services[<?php echo $service_index; ?>][extra]" class="form-control form-control-sm" value="<?php echo $service_cost['extra']; ?>"></td>
                    <td><input type="text" name="services[<?php echo $service_index; ?>][notes]" class="form-control form-control-sm" value="<?php echo e($service_cost['notes']); ?>"></td>
                    <td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
                </tr>
                <?php $service_index++; } endforeach; if ($has_extra_services) echo '</tbody></table></div></div>'; ?>
                
                <button type="submit" class="btn btn-success btn-lg global-save-btn" id="globalSaveBtn"><i class="bi bi-save"></i> SALVA TUTTO</button>
            </form>
        </div>
    </div>
    
    <div class="modal fade" id="uploadProgressModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0"><h5 class="modal-title">Caricamento in corso...</h5></div>
                <div class="modal-body">
                    <div class="progress" style="height: 25px;"><div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width: 0%">0%</div></div>
                    <p class="text-center text-muted small mt-2" id="uploadStatusText">Non chiudere la pagina.</p>
                </div>
            </div>
        </div>
    </div>
        
    <?php else: ?>
    <div class="mb-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Preventivi Confermati da Gestire</h5>
        <?php if (!$show_past): ?>
        <a href="?show_past=1" class="btn btn-outline-secondary">
            <i class="bi bi-calendar-check"></i> Mostra Eventi Passati
        </a>
        <?php else: ?>
        <a href="?" class="btn btn-secondary">
            <i class="bi bi-calendar-x"></i> Nascondi Eventi Passati
        </a>
        <?php endif; ?>
    </div>
    <div class="card">
        <div class="card-header bg-primary text-white"><h5 class="mb-0">Preventivi Confermati da Gestire</h5></div>
        <div class="card-body">
            <?php if (empty($quotes_to_assign)): ?><div class="text-center py-5"><i class="bi bi-inbox" style="font-size: 4rem; color: #ccc;"></i><p class="text-muted mt-3">Nessun preventivo confermato da gestire</p></div><?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light"><tr><th>N° Preventivo</th><th>Cliente</th><th>Prima Data Evento</th><th>Totale</th><th>Stato Gestione</th><th>Azioni</th></tr></thead>
                    <tbody>
                        <?php foreach ($quotes_to_assign as $quote): $client_name = $quote['company_name'] ?: trim($quote['first_name'] . ' ' . $quote['last_name']); $is_completed = ($quote['staff_management_status'] ?? 'pending') == 'completed'; ?>
                        <tr class="<?php echo $is_completed ? 'table-success' : ''; ?>">
                            <td><strong><?php echo e($quote['quote_number']); ?></strong></td><td><?php echo e($client_name); ?></td><td><?php echo $quote['first_event_date'] ? formatDate($quote['first_event_date']) : '-'; ?></td><td><?php echo formatPrice($quote['final_total']); ?></td><td><?php echo $is_completed ? '<span class="badge bg-success">COMPLETATO</span>' : '<span class="badge bg-warning text-dark">DA COMPLETARE</span>'; ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#cloneModal<?php echo $quote['id']; ?>"
                                        title="Clona Evento">
                                    <i class="bi bi-clipboard-plus"></i>
                                </button>
                                <a href="?quote_id=<?php echo $quote['id']; ?>" 
                                   class="btn btn-sm btn-<?php echo $is_completed ? 'success' : 'warning'; ?>">
                                    <i class="bi bi-pencil"></i> Edit
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Modali Clona Evento -->
    <?php foreach ($quotes_to_assign as $quote): ?>
    <div class="modal fade" id="cloneModal<?php echo $quote['id']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-clipboard-plus"></i> Clona Evento: <?php echo e($quote['quote_number']); ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="clone_event.php">
                    <input type="hidden" name="source_quote_id" value="<?php echo $quote['id']; ?>">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> 
                            L'evento verrà clonato con lo stesso staff, servizi, importi e provvigioni.
                            Lo stato staff sarà impostato su "Da Completare".
                        </div>
                        
                        <ul class="nav nav-tabs mb-3" role="tablist">
                            <li class="nav-item">
                                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#existingClient<?php echo $quote['id']; ?>" type="button">
                                    Cliente Esistente
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#newClient<?php echo $quote['id']; ?>" type="button">
                                    Nuovo Cliente
                                </button>
                            </li>
                        </ul>
                        
                        <div class="tab-content">
                            <!-- Tab Cliente Esistente -->
                            <div class="tab-pane fade show active" id="existingClient<?php echo $quote['id']; ?>">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Seleziona Cliente *</label>
                                    <select name="client_id" class="form-select">
                                        <option value="">-- Scegli --</option>
                                        <?php
                                        $clients_result = mysqli_query($db, "SELECT * FROM clients ORDER BY company_name, last_name");
                                        while ($client = mysqli_fetch_assoc($clients_result)):
                                            $client_name = $client['company_name'] ?: trim($client['first_name'] . ' ' . $client['last_name']);
                                        ?>
                                        <option value="<?php echo $client['id']; ?>"><?php echo e($client_name); ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <!-- Tab Nuovo Cliente -->
                            <div class="tab-pane fade" id="newClient<?php echo $quote['id']; ?>">
                                <div class="mb-2">
                                    <label class="form-label fw-bold">Azienda</label>
                                    <input type="text" name="new_company_name" class="form-control" placeholder="Nome azienda">
                                </div>
                                <div class="row">
                                    <div class="col-6 mb-2">
                                        <label class="form-label fw-bold">Nome</label>
                                        <input type="text" name="new_first_name" class="form-control">
                                    </div>
                                    <div class="col-6 mb-2">
                                        <label class="form-label fw-bold">Cognome</label>
                                        <input type="text" name="new_last_name" class="form-control">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-6 mb-2">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="new_email" class="form-control">
                                    </div>
                                    <div class="col-6 mb-2">
                                        <label class="form-label">Telefono</label>
                                        <input type="text" name="new_phone" class="form-control">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Data Evento *</label>
                            <input type="date" name="event_date" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Ora Evento</label>
                            <input type="time" name="event_time" class="form-control">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Location</label>
                            <input type="text" name="event_location" class="form-control" placeholder="Es: Milano, Via Roma 123">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-clipboard-plus"></i> Clona Evento
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <!-- ============================================ -->
    <!-- MODAL ELIMINA EVENTO CLONATO -->
    <!-- ============================================ -->
    <div class="modal fade" id="deleteEventModal" tabindex="-1" aria-labelledby="deleteEventModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteEventModalLabel">
                        <i class="bi bi-exclamation-triangle-fill"></i> Conferma Eliminazione Evento
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <form method="POST" action="assign_staff.php" id="deleteEventForm">
                    <input type="hidden" name="action" value="delete_event">
                    <input type="hidden" name="quote_id" id="modal_quote_id" value="">

                    <div class="modal-body">
                        <!-- Alert warning -->
                        <div class="alert alert-danger mb-3">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>ATTENZIONE!</strong> Questa azione eliminerà l'evento e tutti i dati associati (staff, servizi, media, ecc.).
                        </div>

                        <!-- Info evento -->
                        <div class="bg-light p-3 rounded mb-3">
                            <p class="mb-1"><strong>Preventivo:</strong> <span id="modal_quote_number"></span></p>
                            <p class="mb-0"><strong>Cliente:</strong> <span id="modal_client_name"></span></p>
                        </div>

                        <hr>

                        <!-- Opzioni gestione preventivo -->
                        <p class="fw-bold mb-2">Cosa vuoi fare con il preventivo originale?</p>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="quote_action" id="quoteActionDelete" value="delete" checked>
                            <label class="form-check-label" for="quoteActionDelete">
                                <strong><i class="bi bi-trash text-danger"></i> Elimina anche il preventivo</strong><br>
                                <small class="text-muted">Il preventivo verrà cancellato definitivamente dal database</small>
                            </label>
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="radio" name="quote_action" id="quoteActionReject" value="reject">
                            <label class="form-check-label" for="quoteActionReject">
                                <strong><i class="bi bi-x-circle text-warning"></i> Imposta preventivo come "Rifiutato"</strong><br>
                                <small class="text-muted">Il preventivo rimarrà nel sistema ma con stato "Rifiutato"</small>
                            </label>
                        </div>

                        <hr>

                        <!-- Checkbox conferma finale -->
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="confirmDeleteCheck">
                            <label class="form-check-label fw-bold text-danger" for="confirmDeleteCheck">
                                Confermo di voler eliminare questo evento
                            </label>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x"></i> Annulla
                        </button>
                        <button type="submit" class="btn btn-danger" id="confirmDeleteBtn" disabled>
                            <i class="bi bi-trash-fill"></i> ELIMINA EVENTO
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<script>
const staffByRole = <?php echo json_encode($staff_by_role); ?>;
const allRolesData = <?php echo json_encode($all_roles); ?>;
let globalAssignmentIndex = <?php echo $assignment_index; ?>;
let globalServiceIndex = <?php echo $service_index; ?>;

// --- FUNZIONE UPLOAD CHUNKED (A PEZZI) ---
async function uploadMedia(e, quoteItemId, quoteId) {
    e.preventDefault();
    const fileInput = document.getElementById('mediaFile' + quoteItemId);
    const files = fileInput.files;
    if (files.length === 0) return;

    const modalEl = document.getElementById('uploadProgressModal');
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
    
    const progressBar = document.getElementById('uploadProgressBar');
    const statusText = document.getElementById('uploadStatusText');
    progressBar.style.width = '0%';
    progressBar.innerText = '0%';
    progressBar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-primary';

    // Dimensione Chunk: 2MB (sicuro per quasi tutti i server)
    const CHUNK_SIZE = 2 * 1024 * 1024; 
    let overallTotalSize = 0;
    for (let f of files) overallTotalSize += f.size;
    let overallUploaded = 0;

    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
        
        for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
            const start = chunkIndex * CHUNK_SIZE;
            const end = Math.min(file.size, start + CHUNK_SIZE);
            const chunk = file.slice(start, end);
            
            const formData = new FormData();
            formData.append('action', 'upload_chunk');
            formData.append('quote_id', quoteId);
            formData.append('quote_item_id', quoteItemId);
            formData.append('file_name', file.name);
            formData.append('chunk_index', chunkIndex);
            formData.append('total_chunks', totalChunks);
            formData.append('file_chunk', chunk);
            formData.append('is_ajax', '1');

            try {
                // Upload sincrono del pezzo
                const response = await fetch('assign_staff.php', {
                    method: 'POST',
                    body: formData
                });
                
                if (!response.ok) throw new Error('Errore rete: ' + response.status);
                
                const result = await response.json();
                
                if (result.status === 'error') {
                    throw new Error(result.message);
                }
                
                // Aggiorna progressi
                overallUploaded += chunk.size;
                const percent = Math.min(100, Math.round((overallUploaded / overallTotalSize) * 100));
                progressBar.style.width = percent + '%';
                progressBar.innerText = percent + '%';
                statusText.innerText = `File ${i+1}/${files.length}: ${Math.round(overallUploaded/1024/1024)}MB / ${Math.round(overallTotalSize/1024/1024)}MB`;

            } catch (err) {
                console.error(err);
                alert('Errore caricamento: ' + err.message);
                modal.hide();
                return; // Ferma tutto
            }
        }
    }

    // Tutto finito
    progressBar.className = 'progress-bar bg-success';
    statusText.innerHTML = '<strong>Operazione completata!</strong><br>Aggiorno la pagina...';
    setTimeout(() => location.reload(), 1000);
}

// ============================================
// GESTIONE MODAL ELIMINA EVENTO
// ============================================
function openDeleteModal(quoteId, quoteNumber, clientName) {
    // Popola i campi del modal
    document.getElementById('modal_quote_id').value = quoteId;
    document.getElementById('modal_quote_number').textContent = quoteNumber;
    document.getElementById('modal_client_name').textContent = clientName;

    // Reset checkbox e bottone
    document.getElementById('confirmDeleteCheck').checked = false;
    document.getElementById('confirmDeleteBtn').disabled = true;

    // Reset radio buttons
    document.getElementById('quoteActionDelete').checked = true;

    // Apri modal
    var modal = new bootstrap.Modal(document.getElementById('deleteEventModal'));
    modal.show();
}

document.addEventListener('DOMContentLoaded', function() {
    // Checkbox conferma eliminazione
    const confirmDeleteCheck = document.getElementById('confirmDeleteCheck');
    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
    if (confirmDeleteCheck && confirmDeleteBtn) {
        confirmDeleteCheck.addEventListener('change', function() {
            confirmDeleteBtn.disabled = !this.checked;
        });
    }

    // Reset stato modal all'apertura tramite bootstrap
    const deleteEventModal = document.getElementById('deleteEventModal');
    if (deleteEventModal) {
        deleteEventModal.addEventListener('hidden.bs.modal', function() {
            if (confirmDeleteCheck) confirmDeleteCheck.checked = false;
            if (confirmDeleteBtn) confirmDeleteBtn.disabled = true;
        });
    }

    const noCommCheck = document.getElementById('noCommissionCheck');
    const commInput = document.getElementById('commissionInput');
    const commType = document.getElementById('commissionTypeSelect');
    if(noCommCheck) {
        noCommCheck.addEventListener('change', function() {
            if(this.checked) { commInput.disabled = true; commType.disabled = true; commInput.value = '0.00'; } 
            else { commInput.disabled = false; commType.disabled = false; }
        });
    }
    document.querySelectorAll('input[name*="[cost]"], input[name*="[extra]"]').forEach(input => {
        const pkgId = input.closest('tr')?.querySelector('input[name*="[quote_item_id]"]')?.value;
        if(pkgId) input.addEventListener('input', () => updatePackageTotals(pkgId));
    });
});

function copyLink(elementId) {
    var copyText = document.getElementById(elementId);
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value);
    showToast('Link copiato negli appunti!', 'success');
}

function addStaffRowToPackage(packageIndex, quoteItemId) {
    const tbody = document.getElementById(`staffTableBody${packageIndex}`);
    const currentIndex = globalAssignmentIndex++;
    const tr = document.createElement('tr');
    tr.classList.add('extra-row');
    let roleOptions = '<option value="">-- Seleziona Ruolo --</option>';
    allRolesData.forEach(role => { roleOptions += `<option value="${role.id}">${role.name}</option>`; });
    tr.innerHTML = `<td><input type="hidden" name="assignments[${currentIndex}][quote_item_id]" value="${quoteItemId}"><select name="assignments[${currentIndex}][role_id]" class="form-select form-select-sm" onchange="filterStaffByRole(this, ${currentIndex})">${roleOptions}</select></td><td><select name="assignments[${currentIndex}][staff_id]" class="form-select form-select-sm staff-select-${currentIndex}" onchange="updateCosts(this, ${currentIndex})"><option value="">-- Prima seleziona ruolo --</option></select></td><td><input type="number" step="0.01" name="assignments[${currentIndex}][cost]" class="form-control form-control-sm cost-input-${currentIndex} staff-cost-${quoteItemId}" value="0"></td><td><input type="number" step="0.01" name="assignments[${currentIndex}][extra]" class="form-control form-control-sm extra-input-${currentIndex} staff-extra-${quoteItemId}" value="0"></td><td><input type="text" name="assignments[${currentIndex}][notes]" class="form-control form-control-sm" placeholder="Note opzionali"></td><td><button type="button" class="btn btn-sm btn-danger" onclick="removeStaffRow(this)"><i class="bi bi-trash"></i></button></td>`;
    tbody.appendChild(tr);
    tr.scrollIntoView({ behavior: 'smooth', block: 'center' });
    showToast('✅ Riga aggiunta al pacchetto!', 'success');
    addCalculationListeners(tr, quoteItemId);
}

function addGlobalStaffRow() {
    let tbody = document.getElementById('extraStaffTableBody');
    if (!tbody) {
        const form = document.getElementById('globalForm');
        const packages = document.querySelectorAll('.package-section');
        const lastPackage = packages[packages.length - 1];
        const section = document.createElement('div');
        section.className = 'extra-staff-section';
        section.innerHTML = `<h5 class="mb-3"><i class="bi bi-person-plus-fill"></i> Staff Globale</h5><div class="alert alert-warning py-2 mb-3"><small><strong>Queste persone non sono associate a nessun pacchetto specifico.</strong></small></div><div class="table-responsive mb-2"><table class="table table-sm table-bordered"><thead class="table-warning"><tr><th style="width: 15%;">Ruolo</th><th style="width: 25%;">Nome/Cognome</th><th style="width: 12%;">Costo €</th><th style="width: 12%;">Extra €</th><th style="width: 20%;">Note</th><th style="width: 16%;"></th></tr></thead><tbody id="extraStaffTableBody"></tbody></table></div><button type="button" class="btn btn-success btn-sm" onclick="addGlobalStaffRow()"><i class="bi bi-person-plus"></i> Aggiungi Staff Globale</button>`;
        if (lastPackage) lastPackage.after(section); else form.prepend(section);
        tbody = document.getElementById('extraStaffTableBody');
    }
    const currentIndex = globalAssignmentIndex++;
    const tr = document.createElement('tr');
    let roleOptions = '<option value="">-- Seleziona Ruolo --</option>';
    allRolesData.forEach(role => { roleOptions += `<option value="${role.id}">${role.name}</option>`; });
    tr.innerHTML = `<td><input type="hidden" name="assignments[${currentIndex}][quote_item_id]" value=""><select name="assignments[${currentIndex}][role_id]" class="form-select form-select-sm" onchange="filterStaffByRole(this, ${currentIndex})">${roleOptions}</select></td><td><select name="assignments[${currentIndex}][staff_id]" class="form-select form-select-sm staff-select-${currentIndex}" onchange="updateCosts(this, ${currentIndex})"><option value="">-- Prima seleziona ruolo --</option></select></td><td><input type="number" step="0.01" name="assignments[${currentIndex}][cost]" class="form-control form-control-sm cost-input-${currentIndex}" value="0"></td><td><input type="number" step="0.01" name="assignments[${currentIndex}][extra]" class="form-control form-control-sm extra-input-${currentIndex}" value="0"></td><td><input type="text" name="assignments[${currentIndex}][notes]" class="form-control form-control-sm" placeholder="Note opzionali"></td><td><button type="button" class="btn btn-sm btn-danger" onclick="removeStaffRow(this)"><i class="bi bi-trash"></i></button></td>`;
    tbody.appendChild(tr);
    tr.scrollIntoView({ behavior: 'smooth', block: 'center' });
    showToast('✅ Staff globale aggiunto!', 'success');
}

function removeStaffRow(button) {
    const tr = button.closest('tr');
    const staffSelect = tr.querySelector('select[name*="[staff_id]"]');
    if (!staffSelect || !staffSelect.value) { tr.remove(); showToast('🗑️ Riga rimossa', 'info'); return; }
    if (confirm('⚠️ Rimuovere questa persona?\n\nRICORDA: Devi cliccare "SALVA TUTTO" per confermare la rimozione!')) {
        tr.remove();
        showToast('⚠️ Riga rimossa. RICORDA DI SALVARE!', 'warning');
        const packageId = tr.querySelector('input[name*="[quote_item_id]"]')?.value;
        if (packageId) updatePackageTotals(packageId);
    }
}

function showToast(message, type = 'info') {
    const bgColors = { 'success': 'bg-success', 'danger': 'bg-danger', 'warning': 'bg-warning', 'info': 'bg-info' };
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-white ${bgColors[type]} border-0`;
    toast.setAttribute('role', 'alert');
    toast.style.position = 'fixed';
    toast.style.top = '20px';
    toast.style.right = '20px';
    toast.style.zIndex = '9999';
    toast.innerHTML = `<div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    document.body.appendChild(toast);
    const bsToast = new bootstrap.Toast(toast, { delay: 3000 });
    bsToast.show();
    toast.addEventListener('hidden.bs.toast', () => { toast.remove(); });
}

function filterStaffByRole(selectElement, index) {
    const roleId = selectElement.value;
    const staffSelect = document.querySelector(`.staff-select-${index}`);
    if (!staffSelect) return;
    staffSelect.innerHTML = '<option value="">-- Seleziona --</option>';
    if (staffByRole[roleId]) {
        staffByRole[roleId].forEach(staff => {
            const option = document.createElement('option');
            option.value = staff.id; option.textContent = `${staff.first_name} ${staff.last_name}`;
            option.dataset.cost = staff.default_cost; option.dataset.extra = staff.default_extra;
            staffSelect.appendChild(option);
        });
    }
}

function updateCosts(selectElement, index) {
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const costInput = document.querySelector(`.cost-input-${index}`);
    const extraInput = document.querySelector(`.extra-input-${index}`);
    if (costInput) costInput.value = selectedOption.dataset.cost || 0;
    if (extraInput) extraInput.value = selectedOption.dataset.extra || 0;
    const tr = selectElement.closest('tr');
    const packageId = tr.querySelector('input[name*="[quote_item_id]"]')?.value;
    if (packageId) updatePackageTotals(packageId);
}

function updatePackageTotals(packageId) {
    let totals = {staffCost:0, staffExtra:0, srvCost:0, srvExtra:0};
    document.querySelectorAll(`.staff-cost-${packageId}`).forEach(i => totals.staffCost += parseFloat(i.value)||0);
    document.querySelectorAll(`.staff-extra-${packageId}`).forEach(i => totals.staffExtra += parseFloat(i.value)||0);
    document.querySelectorAll(`.service-cost-${packageId}`).forEach(i => totals.srvCost += parseFloat(i.value)||0);
    document.querySelectorAll(`.service-extra-${packageId}`).forEach(i => totals.srvExtra += parseFloat(i.value)||0);
    
    const pkg = document.querySelector(`input[value="${packageId}"]`)?.closest('.package-section');
    if (pkg) {
        pkg.querySelector('.pkg-staff-cost-total').textContent = formatPrice(totals.staffCost);
        pkg.querySelector('.pkg-staff-extra-total').textContent = formatPrice(totals.staffExtra);
        pkg.querySelector('.pkg-services-cost-total').textContent = formatPrice(totals.srvCost);
        pkg.querySelector('.pkg-services-extra-total').textContent = formatPrice(totals.srvExtra);
        pkg.querySelector('.pkg-total-cost').textContent = formatPrice(totals.staffCost + totals.srvCost);
        pkg.querySelector('.pkg-grand-total').textContent = formatPrice(totals.staffCost + totals.srvCost + totals.staffExtra + totals.srvExtra);
    }
}

function formatPrice(amount) { return new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(amount); }
function addCalculationListeners(tr, packageId) {
    tr.querySelectorAll('input').forEach(input => input.addEventListener('input', () => updatePackageTotals(packageId)));
}

let isSubmitting = false;
document.getElementById('globalForm').addEventListener('submit', function(e) {
    if (isSubmitting) { e.preventDefault(); return false; }
    isSubmitting = true;
    const btn = document.getElementById('globalSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Salvataggio...';
});
</script>

<?php include '../includes/footer.php'; ?>