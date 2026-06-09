<?php
require_once 'config.php';
require_once 'auth.php';
require_once 'functions.php';

// Logica per servire i dati del lead per il modal di modifica rapida (AJAX)
if (isset($_GET['ajax_get_lead'])) {
    header('Content-Type: application/json');
    $lead_id = (int)$_GET['ajax_get_lead'];
    if ($lead_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'ID non valido']);
        exit;
    }
    
    $db_ajax = getDBConnection();
    $query_ajax = "SELECT * FROM leads WHERE id = $lead_id";
    
    // Un commerciale può vedere solo i lead a lui assegnati o liberi
    if (isCommerciale()) {
        $query_ajax .= " AND (assigned_to = {$_SESSION['user_id']} OR assigned_to IS NULL)";
    }
    
    $result_ajax = mysqli_query($db_ajax, $query_ajax);
    $lead_ajax = mysqli_fetch_assoc($result_ajax);
    
    if ($lead_ajax) {
        echo json_encode(['success' => true, 'lead' => $lead_ajax]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Segnalazione non trovata o permessi insufficienti.']);
    }
    exit; // Termina lo script dopo aver inviato i dati JSON
}


requireLogin();

$db = getDBConnection();
$error = '';
$success = '';

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
            if (!isAdmin()) { $error = "Solo gli admin possono creare segnalazioni"; break; }
            
            $event_name = mysqli_real_escape_string($db, $_POST['event_name'] ?? '');
            $location = mysqli_real_escape_string($db, $_POST['location'] ?? '');
            $period = mysqli_real_escape_string($db, $_POST['period'] ?? '');
            $phone1 = mysqli_real_escape_string($db, $_POST['phone1'] ?? '');
            $phone2 = mysqli_real_escape_string($db, $_POST['phone2'] ?? '');
            $email = mysqli_real_escape_string($db, $_POST['email'] ?? '');
            $link1 = mysqli_real_escape_string($db, $_POST['link1'] ?? '');
            $link2 = mysqli_real_escape_string($db, $_POST['link2'] ?? '');
            $link3 = mysqli_real_escape_string($db, $_POST['link3'] ?? '');
            $instagram = mysqli_real_escape_string($db, $_POST['instagram'] ?? '');
            $facebook = mysqli_real_escape_string($db, $_POST['facebook'] ?? '');
            $notes = mysqli_real_escape_string($db, $_POST['notes'] ?? '');
            $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : 'NULL';
            $status = mysqli_real_escape_string($db, $_POST['status'] ?? 'da_contattare');
            
            $query = "INSERT INTO leads (event_name, location, period, phone1, phone2, email, link1, link2, link3, instagram, facebook, assigned_to, notes, status, created_by, created_at)
                      VALUES ('$event_name', '$location', '$period', '$phone1', '$phone2', '$email', '$link1', '$link2', '$link3', '$instagram', '$facebook', $assigned_to, '$notes', '$status', {$_SESSION['user_id']}, NOW())";
            
            if (mysqli_query($db, $query)) { $success = "Segnalazione creata con successo!"; } 
            else { $error = "Errore nella creazione: " . mysqli_error($db); }
            break;
            
        case 'update':
            $lead_id = (int)$_POST['lead_id'];
            $check_query = "SELECT assigned_to FROM leads WHERE id = $lead_id";
            $check_result = mysqli_query($db, $check_query);
            $check_row = mysqli_fetch_assoc($check_result);
            
            if (!isAdmin() && $check_row['assigned_to'] != $_SESSION['user_id'] && $check_row['assigned_to'] !== null) { $error = "Non hai i permessi per modificare questa segnalazione"; break; }
            
            $event_name = mysqli_real_escape_string($db, $_POST['event_name'] ?? '');
            $location = mysqli_real_escape_string($db, $_POST['location'] ?? '');
            $period = mysqli_real_escape_string($db, $_POST['period'] ?? '');
            $phone1 = mysqli_real_escape_string($db, $_POST['phone1'] ?? '');
            $phone2 = mysqli_real_escape_string($db, $_POST['phone2'] ?? '');
            $email = mysqli_real_escape_string($db, $_POST['email'] ?? '');
            $link1 = mysqli_real_escape_string($db, $_POST['link1'] ?? '');
            $link2 = mysqli_real_escape_string($db, $_POST['link2'] ?? '');
            $link3 = mysqli_real_escape_string($db, $_POST['link3'] ?? '');
            $instagram = mysqli_real_escape_string($db, $_POST['instagram'] ?? '');
            $facebook = mysqli_real_escape_string($db, $_POST['facebook'] ?? '');
            $status = mysqli_real_escape_string($db, $_POST['status'] ?? 'da_contattare');
            $notes = mysqli_real_escape_string($db, $_POST['notes'] ?? '');
            
            $assigned_to_sql = '';
            if (isAdmin()) {
                $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : 'NULL';
                $assigned_to_sql = ", assigned_to = $assigned_to";
            }
            
            $query = "UPDATE leads SET event_name = '$event_name', location = '$location', period = '$period', phone1 = '$phone1', phone2 = '$phone2', email = '$email', link1 = '$link1', link2 = '$link2', link3 = '$link3', instagram = '$instagram', facebook = '$facebook', status = '$status', notes = '$notes', updated_at = NOW() $assigned_to_sql WHERE id = $lead_id";
            
            if (mysqli_query($db, $query)) { $success = "Segnalazione aggiornata con successo!"; } 
            else { $error = "Errore nell'aggiornamento: " . mysqli_error($db); }
            break;
            
        case 'quick_update':
            header('Content-Type: application/json');
            $lead_id = (int)$_POST['lead_id'];
            $check_query = "SELECT assigned_to FROM leads WHERE id = $lead_id";
            $check_result = mysqli_query($db, $check_query);
            $check_row = mysqli_fetch_assoc($check_result);
            
            if (!isAdmin() && $check_row['assigned_to'] != $_SESSION['user_id'] && $check_row['assigned_to'] !== null) { echo json_encode(['success' => false, 'error' => 'Permessi insufficienti']); exit; }
            
            $status = mysqli_real_escape_string($db, $_POST['status'] ?? 'da_contattare');
            $notes = mysqli_real_escape_string($db, $_POST['notes'] ?? '');
            
            $query = "UPDATE leads SET status = '$status', notes = '$notes', updated_at = NOW() WHERE id = $lead_id";
            
            if (mysqli_query($db, $query)) { echo json_encode(['success' => true]); } 
            else { echo json_encode(['success' => false, 'error' => mysqli_error($db)]); }
            exit;
            
        case 'assign_to_me':
            $lead_id = (int)$_POST['lead_id'];
            $check = mysqli_query($db, "SELECT assigned_to FROM leads WHERE id = $lead_id");
            $row = mysqli_fetch_assoc($check);
            
            if ($row['assigned_to'] !== null && !isAdmin()) { $error = "Questa segnalazione è già assegnata"; } 
            else {
                $query = "UPDATE leads SET assigned_to = {$_SESSION['user_id']}, status = 'contattato', updated_at = NOW() WHERE id = $lead_id";
                if (mysqli_query($db, $query)) { $success = "Segnalazione assegnata a te!"; } 
                else { $error = "Errore nell'assegnazione: " . mysqli_error($db); }
            }
            break;
            
        case 'delete':
            if (!isAdmin()) { $error = "Solo gli admin possono eliminare segnalazioni"; } 
            else {
                $lead_id = (int)$_POST['lead_id'];
                $query = "DELETE FROM leads WHERE id = $lead_id";
                if (mysqli_query($db, $query)) { 
                    header("Location: leads.php?success=deleted");
                    exit;
                } 
                else { $error = "Errore nell'eliminazione: " . mysqli_error($db); }
            }
            break;
    }
}

function getLeadStatusBadge($status) {
    $badges = ['da_contattare' => '<span class="badge bg-warning text-dark">Da Contattare</span>', 'contattato' => '<span class="badge bg-info">Contattato</span>', 'non_interessato' => '<span class="badge bg-secondary">Non Interessato</span>', 'interessato' => '<span class="badge bg-success">Interessato</span>', 'altro' => '<span class="badge bg-dark">Altro</span>', 'preventivo_inviato' => '<span class="badge bg-primary">Preventivo Inviato</span>'];
    return $badges[$status] ?? $status;
}

if (isset($_GET['id'])) {
    // VISTA SINGOLA / MODIFICA SEGNALAZIONE
    $lead_id = (int)$_GET['id'];
    $query = "SELECT l.*, u1.first_name as assigned_first_name, u1.last_name as assigned_last_name, u2.first_name as creator_first_name, u2.last_name as creator_last_name, c.company_name, c.first_name as client_first_name, c.last_name as client_last_name, q.quote_number FROM leads l LEFT JOIN users u1 ON l.assigned_to = u1.id LEFT JOIN users u2 ON l.created_by = u2.id LEFT JOIN clients c ON l.client_id = c.id LEFT JOIN quotes q ON l.quote_id = q.id WHERE l.id = $lead_id";
    if (isCommerciale()) { $query .= " AND (l.assigned_to = {$_SESSION['user_id']} OR l.assigned_to IS NULL)"; }
    $result = mysqli_query($db, $query);
    $lead = mysqli_fetch_assoc($result);
    if (!$lead) { header("Location: leads.php?error=not_found"); exit; }
    $users = [];
    if (isAdmin()) { $users_result = mysqli_query($db, "SELECT id, first_name, last_name, role FROM users WHERE is_active = 1 ORDER BY first_name, last_name"); while ($row = mysqli_fetch_assoc($users_result)) { $users[] = $row; } }
    $pageTitle = 'Dettaglio Segnalazione';
    include 'includes/header.php';
    ?>
    <div class="container-fluid py-4">
        <form method="POST">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="lead_id" value="<?php echo $lead_id; ?>">
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <a href="leads.php" class="btn btn-outline-secondary mb-3"><i class="bi bi-arrow-left"></i> Torna alla lista</a>
                    <h2><i class="bi bi-pencil-square"></i> Modifica Segnalazione</h2>
                    <p class="text-muted mb-0"><?php echo e($lead['event_name'] ?: 'Segnalazione #' . $lead_id); ?></p>
                </div>
                <div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Salva Modifiche</button>
                    <?php if (isAdmin()): ?><button type="button" class="btn btn-danger" onclick="deleteLead(<?php echo $lead_id; ?>)"><i class="bi bi-trash"></i> Elimina</button><?php endif; ?>
                </div>
            </div>

            <?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show"><?php echo e($error); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
            <?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><?php echo e($success); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
            
            <div class="row">
                <div class="col-lg-8">
                    <div class="card mb-4">
                        <div class="card-header"><h5 class="mb-0">Dettagli Segnalazione</h5></div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label">Nome Evento</label><input type="text" name="event_name" class="form-control" value="<?php echo e($lead['event_name']); ?>"></div>
                                <div class="col-md-6"><label class="form-label">Località</label><input type="text" name="location" class="form-control" value="<?php echo e($lead['location']); ?>"></div>
                                <div class="col-md-6"><label class="form-label">Periodo Evento</label><input type="text" name="period" class="form-control" value="<?php echo e($lead['period']); ?>" placeholder="Es: Estate 2025"></div>
                                <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?php echo e($lead['email']); ?>"></div>
                                <div class="col-md-6"><label class="form-label">Telefono 1</label><input type="text" name="phone1" class="form-control" value="<?php echo e($lead['phone1']); ?>"></div>
                                <div class="col-md-6"><label class="form-label">Telefono 2</label><input type="text" name="phone2" class="form-control" value="<?php echo e($lead['phone2']); ?>"></div>
                                <div class="col-md-6"><label class="form-label">Instagram</label><input type="url" name="instagram" class="form-control" value="<?php echo e($lead['instagram']); ?>" placeholder="https://instagram.com/..."></div>
                                <div class="col-md-6"><label class="form-label">Facebook</label><input type="url" name="facebook" class="form-control" value="<?php echo e($lead['facebook']); ?>" placeholder="https://facebook.com/..."></div>
                                <div class="col-md-4"><label class="form-label">Link 1</label><input type="url" name="link1" class="form-control" value="<?php echo e($lead['link1']); ?>" placeholder="https://..."></div>
                                <div class="col-md-4"><label class="form-label">Link 2</label><input type="url" name="link2" class="form-control" value="<?php echo e($lead['link2']); ?>" placeholder="https://..."></div>
                                <div class="col-md-4"><label class="form-label">Link 3</label><input type="url" name="link3" class="form-control" value="<?php echo e($lead['link3']); ?>" placeholder="https://..."></div>
                                <div class="col-12"><label class="form-label">Note</label><textarea name="notes" class="form-control" rows="4"><?php echo e($lead['notes']); ?></textarea></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card mb-4">
                        <div class="card-header"><h5 class="mb-0">Stato e Assegnazione</h5></div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Stato</label>
                                <select name="status" class="form-select" required>
                                    <option value="da_contattare" <?php echo $lead['status'] == 'da_contattare' ? 'selected' : ''; ?>>Da Contattare</option>
                                    <option value="contattato" <?php echo $lead['status'] == 'contattato' ? 'selected' : ''; ?>>Contattato</option>
                                    <option value="interessato" <?php echo $lead['status'] == 'interessato' ? 'selected' : ''; ?>>Interessato</option>
                                    <option value="preventivo_inviato" <?php echo $lead['status'] == 'preventivo_inviato' ? 'selected' : ''; ?>>Preventivo Inviato</option>
                                    <option value="non_interessato" <?php echo $lead['status'] == 'non_interessato' ? 'selected' : ''; ?>>Non Interessato</option>
                                    <option value="altro" <?php echo $lead['status'] == 'altro' ? 'selected' : ''; ?>>Altro</option>
                                </select>
                            </div>
                            <?php if (isAdmin()): ?>
                            <div class="mb-3">
                                <label class="form-label">Assegna a</label>
                                <select name="assigned_to" class="form-select">
                                    <option value="">Nessuno (Libero)</option>
                                    <?php foreach ($users as $u): ?>
                                    <option value="<?php echo $u['id']; ?>" <?php echo $lead['assigned_to'] == $u['id'] ? 'selected' : ''; ?>>
                                        <?php echo e(trim($u['first_name'] . ' ' . $u['last_name'])); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php else: ?>
                            <div class="mb-3">
                                <label class="form-label">Assegnato a</label>
                                <p class="form-control-plaintext">
                                <?php if ($lead['assigned_to']): ?>
                                    <i class="bi bi-person-check text-success"></i> <?php echo e(trim($lead['assigned_first_name'])); ?>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Libero</span>
                                <?php endif; ?>
                                </p>
                            </div>
                            <?php endif; ?>
                            <hr>
                            <small class="text-muted">Creato da: <?php echo e(trim($lead['creator_first_name'])); ?> il <?php echo formatDate($lead['created_at']); ?></small><br>
                             <?php if ($lead['updated_at']): ?>
                            <small class="text-muted">Aggiornato il: <?php echo formatDateTime($lead['updated_at']); ?></small>
                             <?php endif; ?>
                        </div>
                    </div>

                    <div class="card mb-4">
                        <div class="card-header"><h5 class="mb-0">Azioni Rapide</h5></div>
                        <div class="card-body text-center">
                            <?php if (!$lead['client_id']): ?>
                                <button type="button" class="btn btn-success w-100 mb-2" data-bs-toggle="modal" data-bs-target="#createClientModal">
                                    <i class="bi bi-person-plus"></i> Crea Cliente da Segnalazione
                                </button>
                            <?php endif; ?>
                            <?php if ($lead['client_id'] && !$lead['quote_id']): ?>
                                <a href="quotes.php?client_id=<?php echo $lead['client_id']; ?>&lead_id=<?php echo $lead_id; ?>" class="btn btn-info w-100">
                                    <i class="bi bi-file-earmark-text"></i> Crea Preventivo per Cliente
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Modal Crea Cliente (RIPRISTINATO) -->
    <div class="modal fade" id="createClientModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="create_client_from_lead.php">
                    <input type="hidden" name="lead_id" value="<?php echo $lead_id; ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Crea Cliente da Segnalazione</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <small><i class="bi bi-info-circle"></i> I dati verranno pre-compilati dalla segnalazione. <strong>Solo il Nome è obbligatorio.</strong></small>
                        </div>
                        <div class="row">
                            <div class="col-md-12 mb-3"><label class="form-label">Azienda / Organizzazione</label><input type="text" name="company_name" class="form-control" placeholder="Lascia vuoto se cliente privato"></div>
                            <div class="col-md-12 mb-3"><label class="form-label">Nome Evento</label><input type="text" name="event_name" class="form-control" value="<?php echo e($lead['event_name']); ?>"></div>
                            <div class="col-md-6 mb-3"><label class="form-label">Nome <span class="text-danger">*</span></label><input type="text" name="first_name" class="form-control" required></div>
                            <div class="col-md-6 mb-3"><label class="form-label">Cognome</label><input type="text" name="last_name" class="form-control"></div>
                            <div class="col-md-6 mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?php echo e($lead['email']); ?>"></div>
                            <div class="col-md-6 mb-3"><label class="form-label">Telefono</label><input type="text" name="phone" class="form-control" value="<?php echo e($lead['phone1']); ?>"></div>
                            <div class="col-md-12 mb-3"><label class="form-label">Indirizzo</label><textarea name="address" class="form-control" rows="2"><?php echo e($lead['location']); ?></textarea></div>
                            <div class="col-md-6 mb-3"><label class="form-label">P. IVA</label><input type="text" name="vat_number" class="form-control"></div>
                            <div class="col-md-6 mb-3"><label class="form-label">Codice Fiscale</label><input type="text" name="tax_code" class="form-control"></div>
                            <div class="col-md-6 mb-3"><label class="form-label">PEC</label><input type="email" name="pec" class="form-control" placeholder="email@pec.it"></div>
                            <div class="col-md-6 mb-3"><label class="form-label">Codice SDI</label><input type="text" name="sdi_code" class="form-control" placeholder="XXXXXXX" maxlength="7" style="text-transform: uppercase;"></div>
                            <div class="col-md-12 mb-3"><label class="form-label">Note</label><textarea name="notes" class="form-control" rows="3"><?php echo e($lead['notes']); ?></textarea></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-success">Crea Cliente e Continua</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <form id="deleteLeadForm" method="POST" style="display:none;"><input type="hidden" name="action" value="delete"><input type="hidden" name="lead_id" id="deleteLeadId" value="<?php echo $lead_id; ?>"></form>
    <script>function deleteLead(leadId) { if (confirm('Sei sicuro di voler eliminare questa segnalazione? L\'azione è irreversibile.')) { document.getElementById('deleteLeadForm').submit(); } }</script>
    <?php
    include 'includes/footer.php';

} else {
    // LISTA SEGNALAZIONI
    $pageTitle = 'Segnalazioni';
    $filter_status = $_GET['status'] ?? '';
    $filter_assigned = $_GET['assigned'] ?? '';
    $filter_search = $_GET['search'] ?? '';
    $sort_by = $_GET['sort'] ?? 'created_at';
    $sort_order = $_GET['order'] ?? 'desc';

    function getSortLink($column, $text, $current_sort, $current_order) {
        $order = ($current_sort === $column && $current_order === 'asc') ? 'desc' : 'asc';
        $icon = $current_sort === $column ? ($order === 'desc' ? ' <i class="bi bi-sort-up"></i>' : ' <i class="bi bi-sort-down"></i>') : '';
        $params = http_build_query(array_merge($_GET, ['sort' => $column, 'order' => $order]));
        return "<a href=\"?{$params}\" class=\"text-dark text-decoration-none\">{$text}{$icon}</a>";
    }

    $query = "SELECT l.*, u1.first_name as assigned_first_name, u2.first_name as creator_first_name FROM leads l LEFT JOIN users u1 ON l.assigned_to = u1.id LEFT JOIN users u2 ON l.created_by = u2.id WHERE 1=1";
    
    if (isCommerciale()) { $query .= " AND (l.assigned_to = {$_SESSION['user_id']} OR l.assigned_to IS NULL)"; }
    if ($filter_status) { $query .= " AND l.status = '" . mysqli_real_escape_string($db, $filter_status) . "'"; }
    if ($filter_assigned === 'me') { $query .= " AND l.assigned_to = {$_SESSION['user_id']}"; } 
    elseif ($filter_assigned === 'free') { $query .= " AND l.assigned_to IS NULL"; } 
    elseif ($filter_assigned === 'assigned') { $query .= " AND l.assigned_to IS NOT NULL"; }
    if ($filter_search) { $search = mysqli_real_escape_string($db, $filter_search); $query .= " AND (l.event_name LIKE '%$search%' OR l.location LIKE '%$search%' OR l.phone1 LIKE '%$search%' OR l.email LIKE '%$search%')"; }
    
    $valid_sorts = ['event_name', 'created_at'];
    $order_clause = in_array($sort_by, $valid_sorts) && in_array(strtolower($sort_order), ['asc', 'desc']) ? "l.$sort_by $sort_order" : "l.created_at DESC";
    
    $query .= " ORDER BY CASE l.status WHEN 'da_contattare' THEN 1 WHEN 'contattato' THEN 2 WHEN 'interessato' THEN 3 WHEN 'preventivo_inviato' THEN 4 WHEN 'altro' THEN 5 WHEN 'non_interessato' THEN 6 ELSE 7 END, $order_clause";

    $result = mysqli_query($db, $query);
    $leads = [];
    while ($row = mysqli_fetch_assoc($result)) { $leads[] = $row; }
    
    $users = [];
    if (isAdmin()) { $users_result = mysqli_query($db, "SELECT id, first_name, last_name FROM users WHERE is_active = 1 ORDER BY first_name"); while ($row = mysqli_fetch_assoc($users_result)) { $users[] = $row; } }
    
    include 'includes/header.php';
    ?>
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-megaphone"></i> Segnalazioni</h2>
            <?php if (isAdmin()): ?><button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newLeadModal"><i class="bi bi-plus-circle"></i> Nuova Segnalazione</button><?php endif; ?>
        </div>
        
        <?php if (isset($_GET['error']) && $_GET['error'] == 'not_found'): ?><div class="alert alert-danger alert-dismissible fade show">Segnalazione non trovata.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if (isset($_GET['success']) && $_GET['success'] == 'deleted'): ?><div class="alert alert-warning alert-dismissible fade show">Segnalazione eliminata.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show"><?php echo e($error); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><?php echo e($success); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        
        <div class="card mb-4"><div class="card-body"><form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3"><label class="form-label">Stato</label><select name="status" class="form-select"><option value="">Tutti</option><option value="da_contattare" <?php echo $filter_status == 'da_contattare' ? 'selected' : ''; ?>>Da Contattare</option><option value="contattato" <?php echo $filter_status == 'contattato' ? 'selected' : ''; ?>>Contattato</option><option value="interessato" <?php echo $filter_status == 'interessato' ? 'selected' : ''; ?>>Interessato</option><option value="non_interessato" <?php echo $filter_status == 'non_interessato' ? 'selected' : ''; ?>>Non Interessato</option><option value="preventivo_inviato" <?php echo $filter_status == 'preventivo_inviato' ? 'selected' : ''; ?>>Preventivo Inviato</option><option value="altro" <?php echo $filter_status == 'altro' ? 'selected' : ''; ?>>Altro</option></select></div>
            <div class="col-md-3"><label class="form-label">Assegnazione</label><select name="assigned" class="form-select"><option value="">Tutte</option><option value="me" <?php echo $filter_assigned == 'me' ? 'selected' : ''; ?>>Mie</option><option value="free" <?php echo $filter_assigned == 'free' ? 'selected' : ''; ?>>Libere</option><option value="assigned" <?php echo $filter_assigned == 'assigned' ? 'selected' : ''; ?>>Assegnate</option></select></div>
            <div class="col-md-4"><label class="form-label">Cerca</label><input type="text" class="form-control" name="search" placeholder="Nome evento, località, contatti..." value="<?php echo e($filter_search); ?>"></div>
            <div class="col-md-2"><button type="submit" class="btn btn-secondary w-100 mb-2"><i class="bi bi-search"></i> Cerca</button><a href="leads.php" class="btn btn-outline-secondary w-100"><i class="bi bi-arrow-clockwise"></i> Reset</a></div>
        </form></div></div>
        
        <div class="card"><div class="card-body p-0"><div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light"><tr>
                    <th style="width: 30%;"><?php echo getSortLink('event_name', 'Evento / Nota', $sort_by, $sort_order); ?></th>
                    <th style="width: 15%;">Contatti</th>
                    <th style="width: 15%;">Stato</th>
                    <th style="width: 15%;">Assegnato</th>
                    <th style="width: 15%;"><?php echo getSortLink('created_at', 'Creato', $sort_by, $sort_order); ?></th>
                    <th style="width: 10%;">Azioni</th>
                </tr></thead>
                <tbody>
                    <?php if (empty($leads)): ?>
                    <tr><td colspan="6" class="text-center text-muted p-4">Nessuna segnalazione trovata.</td></tr>
                    <?php else: foreach ($leads as $l): ?>
                        <tr class="align-middle">
                            <td>
                                <a href="leads.php?id=<?php echo $l['id']; ?>" class="fw-bold text-dark text-decoration-none"><?php echo e($l['event_name'] ?: 'N/D'); ?></a>
                                <div class="text-muted small"><?php echo e($l['location'] ?: ''); ?> <?php echo $l['location'] && $l['period'] ? ' / ' : ''; ?> <?php echo e($l['period'] ?: ''); ?></div>
                                <?php if ($l['notes']): ?>
                                    <div class="p-2 mt-2 bg-light border rounded small fst-italic">
                                        <i class="bi bi-sticky text-muted"></i> <?php echo nl2br(e($l['notes'])); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><small>
                                <?php if ($l['phone1']): ?><i class="bi bi-telephone text-muted"></i> <?php echo e($l['phone1']); ?><br><?php endif; ?>
                                <?php if ($l['email']): ?><i class="bi bi-envelope text-muted"></i> <?php echo e($l['email']); ?><?php endif; ?>
                            </small></td>
                            <td><?php echo getLeadStatusBadge($l['status']); ?></td>
                            <td>
                                <?php if ($l['assigned_to']): ?>
                                    <small><i class="bi bi-person-check text-success"></i> <?php echo e(trim($l['assigned_first_name'])); ?></small>
                                <?php else: ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="assign_to_me">
                                        <input type="hidden" name="lead_id" value="<?php echo $l['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-light border" title="Assegna a me">
                                            <span class="badge bg-secondary me-1">Libero</span> 🤏
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td><small><?php echo formatDate($l['created_at']); ?><br><i><?php echo e(trim($l['creator_first_name'])); ?></i></small></td>
                            <td class="text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="openQuickEdit(<?php echo $l['id']; ?>, '<?php echo e($l['status']); ?>', `<?php echo e(addslashes($l['notes'])); ?>`)" title="Modifica rapida"><i class="bi bi-pencil-square"></i></button>
                                <a href="leads.php?id=<?php echo $l['id']; ?>" class="btn btn-sm btn-outline-primary" title="Modifica/Dettagli"><i class="bi bi-pencil"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div></div></div>
    </div>
    
    <!-- Modal Modifica Rapida Stato e Nota - CON PREVIEW DATI ALLARGATA -->
    <div class="modal fade" id="quickEditModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Modifica Rapida Segnalazione</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <!-- Colonna Sinistra: Preview Dati Lead -->
                        <div class="col-md-7">
                            <div class="card bg-light mb-3">
                                <div class="card-header bg-primary text-white"><h6 class="mb-0"><i class="bi bi-info-circle"></i> Informazioni Evento</h6></div>
                                <div class="card-body p-3">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr><th width="35%" class="text-muted">Nome Evento:</th><td id="previewEventName" class="fw-bold fs-6">---</td></tr>
                                        <tr><th class="text-muted">Località:</th><td id="previewLocation" class="fs-6">---</td></tr>
                                        <tr><th class="text-muted">Periodo:</th><td id="previewPeriod" class="fs-6">---</td></tr>
                                    </table>
                                </div>
                            </div>
                            <div class="card bg-light mb-3">
                                <div class="card-header bg-info text-white"><h6 class="mb-0"><i class="bi bi-telephone"></i> Contatti</h6></div>
                                <div class="card-body p-3">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tr><th width="35%" class="text-muted">Telefono 1:</th><td id="previewPhone1" class="fs-6">---</td></tr>
                                        <tr><th class="text-muted">Telefono 2:</th><td id="previewPhone2" class="fs-6">---</td></tr>
                                        <tr><th class="text-muted">Email:</th><td id="previewEmail" class="fs-6">---</td></tr>
                                        <tr><th class="text-muted">Instagram:</th><td id="previewInstagram" class="fs-6">---</td></tr>
                                        <tr><th class="text-muted">Facebook:</th><td id="previewFacebook" class="fs-6">---</td></tr>
                                    </table>
                                </div>
                            </div>
                            <div class="card bg-light" id="previewLinksCard" style="display: none;">
                                <div class="card-header bg-secondary text-white"><h6 class="mb-0"><i class="bi bi-link-45deg"></i> Link Utili</h6></div>
                                <div class="card-body p-3"><div id="previewLink1" class="mb-2"></div><div id="previewLink2" class="mb-2"></div><div id="previewLink3"></div></div>
                            </div>
                        </div>
                        <!-- Colonna Destra: Form Modifica -->
                        <div class="col-md-5">
                            <div class="card border-warning">
                                <div class="card-header bg-warning"><h6 class="mb-0"><i class="bi bi-pencil"></i> Modifica</h6></div>
                                <div class="card-body">
                                    <input type="hidden" id="quickEditLeadId">
                                    <div class="mb-3"><label class="form-label fw-bold"><i class="bi bi-flag"></i> Stato</label>
                                        <select id="quickEditStatus" class="form-select">
                                            <option value="da_contattare">⚠️ Da Contattare</option>
                                            <option value="contattato">📞 Contattato</option>
                                            <option value="interessato">✅ Interessato</option>
                                            <option value="preventivo_inviato">📨 Preventivo Inviato</option>
                                            <option value="non_interessato">❌ Non Interessato</option>
                                            <option value="altro">📝 Altro</option>
                                        </select>
                                    </div>
                                    <div class="mb-3"><label class="form-label fw-bold"><i class="bi bi-sticky"></i> Nota</label>
                                        <textarea id="quickEditNotes" class="form-control" rows="8" placeholder="Aggiungi informazioni, aggiornamenti..."></textarea>
                                        <small class="form-text text-muted"><i class="bi bi-lightbulb"></i> Annota data/ora della chiamata e esito.</small>
                                    </div>
                                    <div class="alert alert-info py-2 px-3 small"><i class="bi bi-info-circle"></i> Le modifiche verranno salvate immediatamente.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Annulla</button>
                    <button type="button" class="btn btn-primary btn-lg" onclick="saveQuickEdit(event)"><i class="bi bi-save"></i> Salva Modifiche</button>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (isAdmin()): ?>
    <!-- Modal Nuova Segnalazione (CODICE RIPRISTINATO) -->
    <div class="modal fade" id="newLeadModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <div class="modal-header">
                        <h5 class="modal-title">Crea Nuova Segnalazione</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label">Nome Evento</label><input type="text" name="event_name" class="form-control"></div>
                            <div class="col-md-6"><label class="form-label">Località</label><input type="text" name="location" class="form-control"></div>
                            <div class="col-md-6"><label class="form-label">Periodo Evento</label><input type="text" name="period" class="form-control" placeholder="Es: Estate 2025"></div>
                            <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
                            <div class="col-md-6"><label class="form-label">Telefono 1</label><input type="text" name="phone1" class="form-control"></div>
                            <div class="col-md-6"><label class="form-label">Telefono 2</label><input type="text" name="phone2" class="form-control"></div>
                            <div class="col-md-6"><label class="form-label">Instagram</label><input type="url" name="instagram" class="form-control" placeholder="https://instagram.com/..."></div>
                            <div class="col-md-6"><label class="form-label">Facebook</label><input type="url" name="facebook" class="form-control" placeholder="https://facebook.com/..."></div>
                            <div class="col-md-4"><label class="form-label">Link 1</label><input type="url" name="link1" class="form-control" placeholder="https://..."></div>
                            <div class="col-md-4"><label class="form-label">Link 2</label><input type="url" name="link2" class="form-control" placeholder="https://..."></div>
                            <div class="col-md-4"><label class="form-label">Link 3</label><input type="url" name="link3" class="form-control" placeholder="https://..."></div>
                            <div class="col-12"><label class="form-label">Note</label><textarea name="notes" class="form-control" rows="3"></textarea></div>
                            <div class="col-md-6">
                                <label class="form-label">Stato</label>
                                <select name="status" class="form-select">
                                    <option value="da_contattare" selected>Da Contattare</option>
                                    <option value="contattato">Contattato</option>
                                    <option value="interessato">Interessato</option>
                                    <option value="preventivo_inviato">Preventivo Inviato</option>
                                    <option value="non_interessato">Non Interessato</option>
                                    <option value="altro">Altro</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Assegna a</label>
                                <select name="assigned_to" class="form-select">
                                    <option value="">Nessuno (Libero)</option>
                                    <?php foreach ($users as $u): ?>
                                    <option value="<?php echo $u['id']; ?>">
                                        <?php echo e(trim($u['first_name'] . ' ' . $u['last_name'])); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary">Crea Segnalazione</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    let quickEditModal;
    let currentQuickEditLeadId = null;

    document.addEventListener('DOMContentLoaded', function () {
        quickEditModal = new bootstrap.Modal(document.getElementById('quickEditModal'));
    });

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function openQuickEdit(leadId, status, notes) {
        currentQuickEditLeadId = leadId;
        document.getElementById('quickEditLeadId').value = leadId;
        document.getElementById('quickEditStatus').value = status;
        document.getElementById('quickEditNotes').value = notes.replace(/\\/g, '');

        fetch(`leads.php?ajax_get_lead=${leadId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const lead = data.lead;
                    
                    document.getElementById('previewEventName').textContent = lead.event_name || '---';
                    document.getElementById('previewLocation').textContent = lead.location || '---';
                    document.getElementById('previewPeriod').textContent = lead.period || '---';
                    
                    document.getElementById('previewPhone1').innerHTML = lead.phone1 ? `<a href="tel:${escapeHtml(lead.phone1)}">${escapeHtml(lead.phone1)}</a>` : '---';
                    document.getElementById('previewPhone2').innerHTML = lead.phone2 ? `<a href="tel:${escapeHtml(lead.phone2)}">${escapeHtml(lead.phone2)}</a>` : '---';
                    document.getElementById('previewEmail').innerHTML = lead.email ? `<a href="mailto:${escapeHtml(lead.email)}">${escapeHtml(lead.email)}</a>` : '---';
                    document.getElementById('previewInstagram').innerHTML = lead.instagram ? `<a href="${escapeHtml(lead.instagram)}" target="_blank">Apri</a>` : '---';
                    document.getElementById('previewFacebook').innerHTML = lead.facebook ? `<a href="${escapeHtml(lead.facebook)}" target="_blank">Apri</a>` : '---';
                    
                    const hasLinks = lead.link1 || lead.link2 || lead.link3;
                    const linksCard = document.getElementById('previewLinksCard');
                    linksCard.style.display = hasLinks ? 'block' : 'none';
                    document.getElementById('previewLink1').innerHTML = lead.link1 ? `<i class="bi bi-link-45deg"></i> <a href="${escapeHtml(lead.link1)}" target="_blank">${escapeHtml(lead.link1)}</a>` : '';
                    document.getElementById('previewLink2').innerHTML = lead.link2 ? `<i class="bi bi-link-45deg"></i> <a href="${escapeHtml(lead.link2)}" target="_blank">${escapeHtml(lead.link2)}</a>` : '';
                    document.getElementById('previewLink3').innerHTML = lead.link3 ? `<i class="bi bi-link-45deg"></i> <a href="${escapeHtml(lead.link3)}" target="_blank">${escapeHtml(lead.link3)}</a>` : '';
                }
            })
            .catch(error => { console.error('Errore nel caricamento dei dati:', error); });
        
        quickEditModal.show();
    }
    
    function saveQuickEdit(event) {
        if (!currentQuickEditLeadId) return;
        
        const status = document.getElementById('quickEditStatus').value;
        const notes = document.getElementById('quickEditNotes').value;
        
        const formData = new FormData();
        formData.append('action', 'quick_update');
        formData.append('lead_id', currentQuickEditLeadId);
        formData.append('status', status);
        formData.append('notes', notes);
        
        const saveBtn = event.target;
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Salvataggio...';
        
        fetch('leads.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Errore: ' + (data.error || 'Impossibile salvare le modifiche'));
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="bi bi-save"></i> Salva Modifiche';
                }
            })
            .catch(error => {
                alert('Errore di rete: ' + error);
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="bi bi-save"></i> Salva Modifiche';
            });
    }
    </script>
    <?php
    include 'includes/footer.php';
}
?>