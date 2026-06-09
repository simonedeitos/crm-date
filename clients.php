<?php
require_once 'config.php';
require_once 'auth.php';
require_once 'functions.php';

requireLogin();

// DEBUG: Abilita visualizzazione errori
error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = getDBConnection();

// VERIFICA CONNESSIONE DB
if (!$db) {
    die("ERRORE: Connessione database fallita - " . mysqli_connect_error());
}

$error = '';
$success = '';

// Gestione azioni
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
            // Creazione nuovo cliente
            $company_name = mysqli_real_escape_string($db, $_POST['company_name'] ?? '');
            $event_name = mysqli_real_escape_string($db, $_POST['event_name'] ?? '');
            $first_name = mysqli_real_escape_string($db, $_POST['first_name'] ?? '');
            $last_name = mysqli_real_escape_string($db, $_POST['last_name'] ?? '');
            $email = mysqli_real_escape_string($db, $_POST['email'] ?? '');
            $phone = mysqli_real_escape_string($db, $_POST['phone'] ?? '');
            $address = mysqli_real_escape_string($db, $_POST['address'] ?? '');
            $vat_number = mysqli_real_escape_string($db, $_POST['vat_number'] ?? '');
            $tax_code = mysqli_real_escape_string($db, $_POST['tax_code'] ?? '');
            $pec = mysqli_real_escape_string($db, $_POST['pec'] ?? '');
            $sdi_code = mysqli_real_escape_string($db, $_POST['sdi_code'] ?? '');
            $notes = mysqli_real_escape_string($db, $_POST['notes'] ?? '');
            
            // Validazione: almeno company_name O (first_name + last_name)
            if (empty($company_name) && (empty($first_name) || empty($last_name))) {
                $error = "Inserisci il nome dell'azienda oppure nome e cognome del cliente";
            } else {
                $query = "INSERT INTO clients (company_name, event_name, first_name, last_name, email, phone, address, 
                          vat_number, tax_code, pec, sdi_code, notes, created_by, created_at) 
                          VALUES ('$company_name', '$event_name', '$first_name', '$last_name', '$email', '$phone', 
                          '$address', '$vat_number', '$tax_code', '$pec', '$sdi_code', '$notes', {$_SESSION['user_id']}, NOW())";
                
                if (mysqli_query($db, $query)) {
                    $success = "Cliente creato con successo!";
                    $new_client_id = mysqli_insert_id($db);
                } else {
                    $error = "Errore nella creazione del cliente: " . mysqli_error($db);
                }
            }
            break;
            
        case 'update':
            // Aggiornamento cliente
            $client_id = (int)$_POST['client_id'];
            
            // VERIFICA PERMESSI AGGIORNAMENTO
            $check_query = "SELECT created_by FROM clients WHERE id = $client_id";
            $check_result = mysqli_query($db, $check_query);
            $check_data = mysqli_fetch_assoc($check_result);
            
            if (!isAdmin() && $check_data['created_by'] != $_SESSION['user_id']) {
                $error = "Non hai i permessi per modificare questo cliente.";
                break;
            }

            $company_name = mysqli_real_escape_string($db, $_POST['company_name'] ?? '');
            $event_name = mysqli_real_escape_string($db, $_POST['event_name'] ?? '');
            $first_name = mysqli_real_escape_string($db, $_POST['first_name'] ?? '');
            $last_name = mysqli_real_escape_string($db, $_POST['last_name'] ?? '');
            $email = mysqli_real_escape_string($db, $_POST['email'] ?? '');
            $phone = mysqli_real_escape_string($db, $_POST['phone'] ?? '');
            $address = mysqli_real_escape_string($db, $_POST['address'] ?? '');
            $vat_number = mysqli_real_escape_string($db, $_POST['vat_number'] ?? '');
            $tax_code = mysqli_real_escape_string($db, $_POST['tax_code'] ?? '');
            $pec = mysqli_real_escape_string($db, $_POST['pec'] ?? '');
            $sdi_code = mysqli_real_escape_string($db, $_POST['sdi_code'] ?? '');
            $notes = mysqli_real_escape_string($db, $_POST['notes'] ?? '');
            
            if (empty($company_name) && (empty($first_name) || empty($last_name))) {
                $error = "Inserisci il nome dell'azienda oppure nome e cognome del cliente";
            } else {
                $query = "UPDATE clients SET 
                          company_name = '$company_name',
                          event_name = '$event_name',
                          first_name = '$first_name',
                          last_name = '$last_name',
                          email = '$email',
                          phone = '$phone',
                          address = '$address',
                          vat_number = '$vat_number',
                          tax_code = '$tax_code',
                          pec = '$pec',
                          sdi_code = '$sdi_code',
                          notes = '$notes'
                          WHERE id = $client_id";
                
                if (mysqli_query($db, $query)) {
                    $success = "Cliente aggiornato con successo!";
                } else {
                    $error = "Errore nell'aggiornamento: " . mysqli_error($db);
                }
            }
            break;
            
        case 'delete':
            // Eliminazione cliente (solo admin)
            if (!isAdmin()) {
                $error = "Solo gli admin possono eliminare clienti";
            } else {
                $client_id = (int)$_POST['client_id'];
                
                // Verifica se ci sono preventivi collegati
                $check = mysqli_query($db, "SELECT COUNT(*) as count FROM quotes WHERE client_id = $client_id");
                $row = mysqli_fetch_assoc($check);
                
                if ($row['count'] > 0) {
                    $error = "Impossibile eliminare: ci sono {$row['count']} preventivi collegati a questo cliente";
                } else {
                    $query = "DELETE FROM clients WHERE id = $client_id";
                    if (mysqli_query($db, $query)) {
                        $success = "Cliente eliminato con successo";
                    } else {
                        $error = "Errore nell'eliminazione: " . mysqli_error($db);
                    }
                }
            }
            break;
    }
}

// Vista singolo cliente
if (isset($_GET['id'])) {
    $client_id = (int)$_GET['id'];
    
    $query = "SELECT c.*, u.username as created_by_name 
              FROM clients c 
              LEFT JOIN users u ON c.created_by = u.id 
              WHERE c.id = $client_id";
    
    $result = mysqli_query($db, $query);
    $client = mysqli_fetch_assoc($result);
    
    if (!$client) {
        header("Location: clients.php?error=not_found");
        exit;
    }

    // PROTEZIONE ACCESSO DIRETTO
    // Se non è admin e non è il creatore, redirect
    if (!isAdmin() && $client['created_by'] != $_SESSION['user_id']) {
        header("Location: clients.php?error=access_denied");
        exit;
    }
    
    // Ottieni preventivi del cliente con DATE e PACCHETTI
    $quotes_query = "SELECT q.*, 
                 u.first_name as user_first_name, 
                 u.last_name as user_last_name,
                     (SELECT MIN(qd.event_date) FROM quote_dates qd WHERE qd.quote_id = q.id) as event_date,
                     (SELECT qd.location FROM quote_dates qd WHERE qd.quote_id = q.id ORDER BY qd.event_date LIMIT 1) as event_location
                     FROM quotes q
                     JOIN users u ON q.created_by = u.id
                     WHERE q.client_id = $client_id";
    
    // Nota: qui mostriamo tutti i preventivi del cliente perché se sei arrivato qui hai i permessi sul cliente
    $quotes_query .= " ORDER BY q.created_at DESC";
    
    $quotes_result = mysqli_query($db, $quotes_query);
    $quotes = [];
    while ($row = mysqli_fetch_assoc($quotes_result)) {
        // Ottieni pacchetti per questo preventivo
        $packages_query = "SELECT package_name FROM quote_items WHERE quote_id = {$row['id']} ORDER BY id";
        $packages_result = mysqli_query($db, $packages_query);
        $packages = [];
        while ($pkg = mysqli_fetch_assoc($packages_result)) {
            if (!empty($pkg['package_name'])) {
                // Rimuovi testo tra parentesi quadre
                $clean_name = preg_replace('/\[.*?\]/', '', $pkg['package_name']);
                $clean_name = trim($clean_name);
                if (!empty($clean_name)) {
                    $packages[] = $clean_name;
                }
            }
        }
        $row['packages'] = $packages;
        $quotes[] = $row;
    }
    
    $pageTitle = 'Dettaglio Cliente';
    include 'includes/header.php';
    ?>
    
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <a href="clients.php" class="btn btn-outline-secondary mb-2">
                    <i class="bi bi-arrow-left"></i> Torna alla lista
                </a>
                <h2><i class="bi bi-person"></i> <?php echo e($client['company_name'] ?: trim($client['first_name'] . ' ' . $client['last_name'])); ?></h2>
                <?php if ($client['event_name']): ?>
                <p class="text-muted mb-0"><i class="bi bi-calendar-event"></i> Evento: <strong><?php echo e($client['event_name']); ?></strong></p>
                <?php endif; ?>
            </div>
            <div>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editClientModal">
                    <i class="bi bi-pencil"></i> Modifica
                </button>
                <?php if (isAdmin()): ?>
                <button type="button" class="btn btn-danger" onclick="deleteClient(<?php echo $client_id; ?>)">
                    <i class="bi bi-trash"></i> Elimina
                </button>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo e($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo e($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Informazioni Cliente -->
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-info-circle"></i> Informazioni Anagrafiche</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <?php if ($client['company_name']): ?>
                            <tr>
                                <th width="40%">Azienda:</th>
                                <td><strong><?php echo e($client['company_name']); ?></strong></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($client['event_name']): ?>
                            <tr>
                                <th>Nome Evento:</th>
                                <td><strong><?php echo e($client['event_name']); ?></strong></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($client['first_name'] || $client['last_name']): ?>
                            <tr>
                                <th>Nome e Cognome:</th>
                                <td><?php echo e(trim($client['first_name'] . ' ' . $client['last_name'])); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($client['email']): ?>
                            <tr>
                                <th>Email:</th>
                                <td><a href="mailto:<?php echo e($client['email']); ?>"><?php echo e($client['email']); ?></a></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($client['phone']): ?>
                            <tr>
                                <th>Telefono:</th>
                                <td><a href="tel:<?php echo e($client['phone']); ?>"><?php echo e($client['phone']); ?></a></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($client['address']): ?>
                            <tr>
                                <th>Indirizzo:</th>
                                <td><?php echo nl2br(e($client['address'])); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($client['vat_number']): ?>
                            <tr>
                                <th>P. IVA:</th>
                                <td><?php echo e($client['vat_number']); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($client['tax_code']): ?>
                            <tr>
                                <th>Codice Fiscale:</th>
                                <td><?php echo e($client['tax_code']); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($client['pec']): ?>
                            <tr>
                                <th>PEC:</th>
                                <td><a href="mailto:<?php echo e($client['pec']); ?>"><?php echo e($client['pec']); ?></a></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($client['sdi_code']): ?>
                            <tr>
                                <th>Codice SDI:</th>
                                <td><code><?php echo e($client['sdi_code']); ?></code></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Creato da:</th>
                                <td><?php echo e($client['created_by_name'] ?? 'N/D'); ?></td>
                            </tr>
                            <tr>
                                <th>Data Creazione:</th>
                                <td><?php echo formatDateTime($client['created_at']); ?></td>
                            </tr>
                        </table>
                        
                        <?php if ($client['notes']): ?>
                        <div class="mt-3">
                            <strong>Note:</strong>
                            <p class="mb-0 mt-2"><?php echo nl2br(e($client['notes'])); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Preventivi del Cliente -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-file-earmark-text"></i> Preventivi (<?php echo count($quotes); ?>)</h5>
                        <a href="quotes.php?client_id=<?php echo $client_id; ?>" class="btn btn-sm btn-primary">
                            <i class="bi bi-plus"></i> Nuovo Preventivo
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($quotes)): ?>
                            <p class="text-muted text-center py-4">Nessun preventivo trovato per questo cliente</p>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($quotes as $q): ?>
                                <a href="quotes.php?id=<?php echo $q['id']; ?>" class="list-group-item list-group-item-action">
    <div class="d-flex justify-content-between align-items-start">
        <div class="flex-grow-1">
            <div class="d-flex align-items-center mb-2">
                <strong class="me-2"><?php echo e($q['quote_number']); ?></strong>
                <?php echo getStatusBadge($q['status']); ?>
            </div>
            
            <div class="mb-1">
                <small class="text-muted">
                    <?php if ($q['event_date']): ?>
                        <i class="bi bi-calendar-event"></i> <?php echo formatDate($q['event_date']); ?>
                    <?php endif; ?>
                    
                    <?php if ($q['event_location']): ?>
                        <span class="mx-1">|</span>
                        <i class="bi bi-geo-alt"></i> <?php echo e($q['event_location']); ?>
                    <?php endif; ?>
                    
                    <span class="mx-1">|</span>
                    Creato il: <?php echo formatDate($q['created_at']); ?>
                     da: <?php 
                    $seller_name = trim($q['user_first_name'] . ' ' . $q['user_last_name']);
                    echo e($seller_name);
                    ?>
                </small>
            </div>
            
            <?php if (!empty($q['packages'])): ?>
            <small class="text-primary">
                <i class="bi bi-box-seam"></i> <strong>Pacchetto/i:</strong> 
                <?php echo e(implode(', ', $q['packages'])); ?>
            </small>
            <?php endif; ?>
        </div>
        <div class="text-end ms-3">
            <strong class="d-block"><?php echo formatPrice($q['total']); ?></strong>
            <small class="text-muted">senza IVA</small>
        </div>
    </div>
</a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Modifica Cliente -->
    <div class="modal fade" id="editClientModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Modifica Cliente</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Azienda</label>
                                <input type="text" name="company_name" class="form-control" 
                                       value="<?php echo e($client['company_name']); ?>" 
                                       placeholder="Lascia vuoto se cliente privato">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nome Evento</label>
                                <input type="text" name="event_name" class="form-control" 
                                       value="<?php echo e($client['event_name']); ?>"
                                       placeholder="Es: Festa del vino">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nome</label>
                                <input type="text" name="first_name" class="form-control" 
                                       value="<?php echo e($client['first_name']); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Cognome</label>
                                <input type="text" name="last_name" class="form-control" 
                                       value="<?php echo e($client['last_name']); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" 
                                       value="<?php echo e($client['email']); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Telefono</label>
                                <input type="text" name="phone" class="form-control" 
                                       value="<?php echo e($client['phone']); ?>">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Indirizzo</label>
                                <textarea name="address" class="form-control" rows="2"><?php echo e($client['address']); ?></textarea>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">P. IVA</label>
                                <input type="text" name="vat_number" class="form-control" 
                                       value="<?php echo e($client['vat_number']); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Codice Fiscale</label>
                                <input type="text" name="tax_code" class="form-control" 
                                       value="<?php echo e($client['tax_code']); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">PEC <small class="text-muted">(Fatturazione Elettronica)</small></label>
                                <input type="email" name="pec" class="form-control" 
                                       value="<?php echo e($client['pec']); ?>"
                                       placeholder="email@pec.it">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Codice Destinatario SDI <small class="text-muted">(7 caratteri)</small></label>
                                <input type="text" name="sdi_code" class="form-control" 
                                       value="<?php echo e($client['sdi_code']); ?>"
                                       placeholder="Es: XXXXXXX"
                                       maxlength="7"
                                       style="text-transform: uppercase;">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Note</label>
                                <textarea name="notes" class="form-control" rows="3"><?php echo e($client['notes']); ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary">Salva Modifiche</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Form nascosto per eliminazione -->
    <form id="deleteClientForm" method="POST" style="display:none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="client_id" id="deleteClientId">
    </form>
    
    <script>
    function deleteClient(clientId) {
        if (confirm('Sei sicuro di voler eliminare questo cliente?\n\nATTENZIONE: Non potrai eliminare clienti con preventivi collegati.')) {
            document.getElementById('deleteClientId').value = clientId;
            document.getElementById('deleteClientForm').submit();
        }
    }
    </script>
    
    <?php
    include 'includes/footer.php';
    
} else {
    // LISTA CLIENTI
    $pageTitle = 'Clienti';
    
    // Filtri
    $filter_search = $_GET['search'] ?? '';
    $filter_user = isset($_GET['filter_user']) ? (int)$_GET['filter_user'] : 0;
    
    // Lista Utenti per Dropdown Admin
    $users_list = [];
    if (isAdmin()) {
        $u_res = mysqli_query($db, "SELECT id, username, first_name, last_name FROM users ORDER BY username");
        while($u = mysqli_fetch_assoc($u_res)) {
            $users_list[] = $u;
        }
    }
    
    // Query Principale
    $query = "SELECT c.*, u.username as created_by_name, c.created_by,
              (SELECT COUNT(*) FROM quotes WHERE client_id = c.id) as quotes_count
              FROM clients c
              LEFT JOIN users u ON c.created_by = u.id
              WHERE 1=1";
    
    // Applicazione Filtro Ricerca Testo
    if ($filter_search) {
        $search = mysqli_real_escape_string($db, $filter_search);
        $query .= " AND (c.company_name LIKE '%$search%' 
                    OR c.event_name LIKE '%$search%'
                    OR c.first_name LIKE '%$search%' 
                    OR c.last_name LIKE '%$search%'
                    OR c.email LIKE '%$search%'
                    OR c.phone LIKE '%$search%')";
    }
    
    // Applicazione Filtro Utente (Se selezionato o se cliccato "Mostra Miei")
    if ($filter_user > 0) {
        $query .= " AND c.created_by = $filter_user";
    }
    
    $query .= " ORDER BY c.company_name, c.last_name, c.first_name";
    
    $result = mysqli_query($db, $query);
    $clients = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $clients[] = $row;
    }
    
    include 'includes/header.php';
    ?>
    
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-people"></i> Clienti</h2>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newClientModal">
                <i class="bi bi-plus-circle"></i> Nuovo Cliente
            </button>
        </div>
        
        <?php if (isset($_GET['error']) && $_GET['error'] == 'not_found'): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                Cliente non trovato
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['error']) && $_GET['error'] == 'access_denied'): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i> Accesso negato: non puoi visualizzare i dettagli di questo cliente.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo e($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo e($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Filtri -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-2 align-items-center">
                    
                    <!-- BOTTONE RAPIDO: Mostra miei clienti -->
                    <div class="col-md-2">
                        <?php if ($filter_user == $_SESSION['user_id']): ?>
                            <a href="clients.php" class="btn btn-primary w-100">
                                <i class="bi bi-people-fill"></i> Tutti
                            </a>
                        <?php else: ?>
                            <a href="?filter_user=<?php echo $_SESSION['user_id']; ?>" class="btn btn-outline-primary w-100">
                                <i class="bi bi-person-check-fill"></i> Miei Clienti
                            </a>
                        <?php endif; ?>
                    </div>

                    <!-- CAMPO RICERCA CENTRALE -->
                    <div class="col-md-6">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control" name="search" 
                                   placeholder="Cerca per nome, azienda, evento, email..." 
                                   value="<?php echo e($filter_search); ?>">
                        </div>
                    </div>

                    <!-- DROPDOWN ADMIN & BOTTONI -->
                    <div class="col-md-4 d-flex gap-2">
                        <?php if (isAdmin()): ?>
                        <select name="filter_user" class="form-select" onchange="this.form.submit()">
                            <option value="">-- Filtra per Utente --</option>
                            <?php foreach ($users_list as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo ($filter_user == $u['id']) ? 'selected' : ''; ?>>
                                <?php echo e($u['username']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                            <!-- Campo hidden per mantenere il filtro utente se non admin ma filtrato via link -->
                            <?php if ($filter_user > 0): ?>
                                <input type="hidden" name="filter_user" value="<?php echo $filter_user; ?>">
                            <?php endif; ?>
                        <?php endif; ?>

                        <button type="submit" class="btn btn-secondary">Cerca</button>
                        <a href="clients.php" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Tabella Clienti -->
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Contatti</th>
                                <th>P. IVA / CF</th>
                                <th>Preventivi</th>
                                <th>Creato da</th>
                                <th>Data Creazione</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($clients)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted">
                                    Nessun cliente trovato
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($clients as $c): 
                                    $client_name = $c['company_name'] ?: trim($c['first_name'] . ' ' . $c['last_name']);
                                    
                                    // Logica per determinare se l'utente ha accesso completo
                                    $is_mine = ($c['created_by'] == $_SESSION['user_id']);
                                    $has_access = isAdmin() || $is_mine;
                                    
                                    // Classe per la riga se non si ha accesso
                                    $row_class = !$has_access ? 'table-danger' : '';
                                ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td>
                                        <strong><?php echo e($client_name); ?></strong>
                                        <?php if ($c['event_name']): ?>
                                        <br><small class="text-muted"><i class="bi bi-calendar-event"></i> <?php echo e($c['event_name']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($c['company_name'] && ($c['first_name'] || $c['last_name'])): ?>
                                        <br><small class="text-muted"><?php echo e(trim($c['first_name'] . ' ' . $c['last_name'])); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($has_access): ?>
                                            <?php if ($c['email']): ?>
                                            <i class="bi bi-envelope"></i> <?php echo e($c['email']); ?><br>
                                            <?php endif; ?>
                                            <?php if ($c['phone']): ?>
                                            <i class="bi bi-telephone"></i> <?php echo e($c['phone']); ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted"><i class="bi bi-envelope"></i> ***@***.**</span><br>
                                            <span class="text-muted"><i class="bi bi-telephone"></i> *********</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($c['vat_number']): ?>
                                        P.IVA: <?php echo e($c['vat_number']); ?><br>
                                        <?php endif; ?>
                                        <?php if ($c['tax_code']): ?>
                                        CF: <?php echo e($c['tax_code']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo $c['quotes_count']; ?></span>
                                    </td>
                                    <td><?php echo e($c['created_by_name'] ?? 'N/D'); ?></td>
                                    <td><?php echo formatDate($c['created_at']); ?></td>
                                    <td>
                                        <?php if ($has_access): ?>
                                            <a href="clients.php?id=<?php echo $c['id']; ?>" class="btn btn-sm btn-primary">
                                                <i class="bi bi-eye"></i> Dettagli
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-secondary" disabled title="Accesso negato">
                                                <i class="bi bi-slash-circle"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Nuovo Cliente -->
    <div class="modal fade" id="newClientModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <div class="modal-header">
                        <h5 class="modal-title">Nuovo Cliente</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <small><i class="bi bi-info-circle"></i> Compila almeno il campo <strong>Azienda</strong> oppure <strong>Nome e Cognome</strong></small>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Azienda</label>
                                <input type="text" name="company_name" class="form-control" 
                                       placeholder="Nome azienda (lascia vuoto se privato)">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nome Evento</label>
                                <input type="text" name="event_name" class="form-control"
                                       placeholder="Es: Matrimonio, Compleanno, ecc.">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nome</label>
                                <input type="text" name="first_name" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Cognome</label>
                                <input type="text" name="last_name" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Telefono</label>
                                <input type="text" name="phone" class="form-control">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Indirizzo</label>
                                <textarea name="address" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">P. IVA</label>
                                <input type="text" name="vat_number" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Codice Fiscale</label>
                                <input type="text" name="tax_code" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">PEC <small class="text-muted">(Fatturazione Elettronica)</small></label>
                                <input type="email" name="pec" class="form-control" placeholder="email@pec.it">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Codice Destinatario SDI <small class="text-muted">(7 caratteri)</small></label>
                                <input type="text" name="sdi_code" class="form-control" 
                                       placeholder="Es: XXXXXXX"
                                       maxlength="7"
                                       style="text-transform: uppercase;">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Note</label>
                                <textarea name="notes" class="form-control" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary">Crea Cliente</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <?php
    include 'includes/footer.php';
}
?>