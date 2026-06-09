<?php
/**
 * Vista dettaglio preventivo con gestione completa di servizi, ruoli e allegati
 * Versione aggiornata che usa quote_item_services e quote_item_roles
 */

// Il preventivo dovrebbe essere già caricato dalla pagina principale
$client_name = $quote['company_name'] ?: trim($quote['first_name'] . ' ' . $quote['last_name']);

// --- FIX PERMESSI ---
// Recupera ID utente corrente e creatore assicurandosi che siano validi
$current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$creator_id = isset($quote['created_by']) ? (int)$quote['created_by'] : 0;

// L'utente può modificare se è Admin OPPURE se è il creatore del preventivo
$can_edit = isAdmin() || ($current_user_id > 0 && $current_user_id === $creator_id);
// --------------------

// --- FILTRO PACCHETTI DISPONIBILI ---
// Admin: Vede tutto
// User: Vede pacchetti pubblici (nessuna restrizione) OR pacchetti assegnati a lui
$packages_query = "SELECT DISTINCT p.* FROM packages p ";

if (!isAdmin()) {
    // Se non è admin, controlla i permessi
    $packages_query .= "LEFT JOIN package_users pu ON p.id = pu.package_id 
                        WHERE p.is_active = 1 
                        AND (
                            -- Pacchetti pubblici (nessun utente assegnato)
                            (SELECT COUNT(*) FROM package_users WHERE package_id = p.id) = 0
                            OR 
                            -- Pacchetti assegnati all'utente corrente
                            pu.user_id = $current_user_id
                        )";
} else {
    // Admin vede tutto
    $packages_query .= "WHERE p.is_active = 1";
}

$packages_query .= " ORDER BY p.name";

$packages_result = mysqli_query($db, $packages_query);
$available_packages = [];

if ($packages_result) {
    while ($row = mysqli_fetch_assoc($packages_result)) {
        // Ottieni servizi del pacchetto
        $services_result = mysqli_query($db, "SELECT * FROM package_services WHERE package_id = {$row['id']} ORDER BY sort_order");
        $row['services'] = [];
        while ($service = mysqli_fetch_assoc($services_result)) {
            $row['services'][] = $service;
        }
        
        // Ottieni ruoli del pacchetto
        $roles_result = mysqli_query($db, "SELECT psr.*, sr.name as role_name FROM package_staff_roles psr 
                                           JOIN staff_roles sr ON psr.role_id = sr.id 
                                           WHERE psr.package_id = {$row['id']}");
        $row['roles'] = [];
        while ($role = mysqli_fetch_assoc($roles_result)) {
            $row['roles'][] = $role;
        }
        
        $available_packages[] = $row;
    }
}

// Ottieni tutti i ruoli disponibili
$all_roles = [];
$all_roles_result = mysqli_query($db, "SELECT * FROM staff_roles ORDER BY name");
if ($all_roles_result) {
    while ($row = mysqli_fetch_assoc($all_roles_result)) {
        $all_roles[] = $row;
    }
}

// Determina quale form mostrare
$show_add_package = isset($_GET['add_package']);
$show_add_service = isset($_GET['add_service']);
$show_add_date = isset($_GET['add_date']);
$show_discount = isset($_GET['discount']);
$show_edit_item = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

// Se stiamo modificando un elemento, ottieni i suoi dati completi
$edit_item = null;
if ($show_edit_item) {
    $result = mysqli_query($db, "SELECT * FROM quote_items WHERE id = $show_edit_item");
    if ($result) {
        $edit_item = mysqli_fetch_assoc($result);
        
        if ($edit_item) {
            // Ottieni servizi SPECIFICI di questo item dal preventivo
            $services_result = mysqli_query($db, "SELECT * FROM quote_item_services WHERE quote_item_id = {$edit_item['id']} ORDER BY sort_order");
            $edit_item['item_services'] = [];
            while ($service = mysqli_fetch_assoc($services_result)) {
                $edit_item['item_services'][] = $service;
            }
            
            // Ottieni ruoli SPECIFICI di questo item dal preventivo
            $roles_result = mysqli_query($db, "SELECT * FROM quote_item_roles WHERE quote_item_id = {$edit_item['id']}");
            $edit_item['item_roles'] = [];
            while ($role = mysqli_fetch_assoc($roles_result)) {
                $edit_item['item_roles'][$role['role_id']] = $role['quantity'];
            }
            
            // Fallback: se non ci sono servizi nelle tabelle specifiche, usa il campo services
            if (empty($edit_item['item_services']) && !empty($edit_item['services'])) {
                $services = explode("\n", $edit_item['services']);
                foreach ($services as $idx => $service_name) {
                    if (trim($service_name)) {
                        $edit_item['item_services'][] = [
                            'service_name' => trim($service_name),
                            'is_mandatory' => 0,
                            'sort_order' => $idx
                        ];
                    }
                }
            }
        }
    }
}

// Prepara gli items con dettagli completi (servizi e ruoli SPECIFICI del preventivo)
$items_with_details = [];
if (!empty($items)) {
    foreach ($items as $item) {
        // Servizi SPECIFICI di questo preventivo
        $services_result = mysqli_query($db, "SELECT * FROM quote_item_services WHERE quote_item_id = {$item['id']} ORDER BY sort_order");
        $item['item_services'] = [];
        while ($service = mysqli_fetch_assoc($services_result)) {
            $item['item_services'][] = $service;
        }
        
        // Ruoli SPECIFICI di questo preventivo
        $roles_result = mysqli_query($db, "SELECT * FROM quote_item_roles WHERE quote_item_id = {$item['id']}");
        $item['item_roles'] = [];
        while ($role = mysqli_fetch_assoc($roles_result)) {
            $item['item_roles'][] = $role;
        }
        
        // Fallback: se non ci sono servizi nelle tabelle specifiche, usa il campo services
        if (empty($item['item_services']) && !empty($item['services'])) {
            $services = explode("\n", $item['services']);
            foreach ($services as $idx => $service_name) {
                if (trim($service_name)) {
                    $item['item_services'][] = [
                        'service_name' => trim($service_name),
                        'is_mandatory' => 0,
                        'sort_order' => $idx
                    ];
                }
            }
        }
        
        $items_with_details[] = $item;
    }
}
?>

<!-- CSS Compatto + Servizi + Ruoli -->
<style>
.card { margin-bottom: 0.75rem !important; }
.card-header { padding: 0.5rem 0.75rem !important; font-size: 0.9rem !important; }
.card-body { padding: 0.75rem !important; }
h2 { font-size: 1.5rem !important; }
h5 { font-size: 1rem !important; }
h6 { font-size: 0.85rem !important; margin-bottom: 0 !important; }
.table { font-size: 0.85rem !important; margin-bottom: 0 !important; }
.table td, .table th { padding: 0.4rem !important; }
.btn-sm { padding: 0.2rem 0.5rem !important; font-size: 0.8rem !important; }
.badge { font-size: 0.75rem !important; }
.breadcrumb { font-size: 0.85rem !important; padding: 0.5rem 0 !important; margin-bottom: 0.5rem !important; }
small { font-size: 0.75rem !important; }
.list-group-item { padding: 0.5rem !important; }
.alert { padding: 0.75rem !important; margin-bottom: 0.75rem !important; }
.form-control, .form-select { font-size: 0.85rem !important; padding: 0.375rem 0.5rem !important; }
.service-item { padding-left: 0.5rem; border-left: 2px solid #0d6efd; margin-bottom: 0.3rem; }
.role-item { padding-left: 0.5rem; border-left: 2px solid #198754; margin-bottom: 0.3rem; }
.mandatory-service { border-left-color: #dc3545 !important; }
.service-row, .role-row { margin-bottom: 0.5rem; }
.package-preview { background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 0.375rem; padding: 1rem; margin-bottom: 1rem; }
</style>

<div class="container-fluid py-3">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="quotes.php"><i class="bi bi-house"></i> Preventivi</a></li>
            <li class="breadcrumb-item active"><?php echo e($quote['quote_number']); ?></li>
        </ol>
    </nav>
    
    <?php
    // Verifica se questo preventivo è collegato ad una segnalazione
    $lead_check = mysqli_query($db, "SELECT l.*, u.first_name, u.last_name 
                                      FROM leads l
                                      LEFT JOIN users u ON l.assigned_to = u.id
                                      WHERE l.quote_id = $quote_id");
    $linked_lead = mysqli_fetch_assoc($lead_check);
    
    if ($linked_lead): ?>
    <div class="alert alert-info alert-dismissible fade show">
        <div class="d-flex align-items-center">
            <i class="bi bi-megaphone fs-4 me-3"></i>
            <div class="flex-grow-1">
                <strong><i class="bi bi-link-45deg"></i> Preventivo da Segnalazione</strong><br>
                <small>
                    <?php if ($linked_lead['event_name']): ?>
                    Evento: <strong><?php echo e($linked_lead['event_name']); ?></strong>
                    <?php endif; ?>
                    <?php if ($linked_lead['location']): ?>
                    • Località: <?php echo e($linked_lead['location']); ?>
                    <?php endif; ?>
                    <?php if ($linked_lead['assigned_to']): ?>
                    • Assegnato a: <strong><?php echo e(trim($linked_lead['first_name'] . ' ' . $linked_lead['last_name'])); ?></strong>
                    <?php endif; ?>
                </small>
            </div>
            <a href="leads.php?id=<?php echo $linked_lead['id']; ?>" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-arrow-right"></i> Vai alla Segnalazione
            </a>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <!-- Header con PDF Export -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="mb-1"><i class="bi bi-file-text"></i> <?php echo e($quote['quote_number']); ?></h2>
            <small class="text-muted">Creato il <?php echo formatDateTime($quote['created_at']); ?></small>
        </div>
        <div class="d-flex align-items-center">
            <?php echo getStatusBadge($quote['status']); ?>
            <button type="button" class="btn btn-danger btn-sm ms-2" onclick="exportToPDF()">
                <i class="bi bi-file-earmark-pdf"></i> Esporta PDF
            </button>
        </div>
    </div>
    
    <!-- Messaggi -->
    <?php if (isset($error) && !empty($error)): ?>
    <div class="alert alert-danger alert-dismissible">
        <i class="bi bi-x-circle"></i> <?php echo e($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible">
        <i class="bi bi-check"></i>
        <?php
        $msg = [
            'package_added' => 'Pacchetto aggiunto!',
            'service_added' => 'Servizio aggiunto!',
            'item_updated' => 'Elemento aggiornato!',
            'item_deleted' => 'Elemento rimosso!',
            'date_added' => 'Data aggiunta!',
            'date_deleted' => 'Data eliminata!',
            'discount_updated' => 'Sconto aggiornato!',
            'location_updated' => 'Location aggiornata!',
            'datetime_updated' => 'Data e ora pacchetto aggiornate!'
        ];
        echo $msg[$_GET['success']] ?? 'Operazione completata!';
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <div class="row">
        <!-- COLONNA SINISTRA -->
        <div class="col-lg-4">
            <!-- Cliente -->
            <div class="card border-primary">
                <div class="card-header bg-primary text-white d-flex justify-content-between">
                    <h6><i class="bi bi-person"></i> Cliente</h6>
                    <a href="clients.php?id=<?php echo $quote['client_id']; ?>" class="text-white" target="_blank">
                        <i class="bi bi-box-arrow-up-right"></i>
                    </a>
                </div>
                <div class="card-body">
                    <strong class="d-block mb-2"><?php echo e($client_name); ?></strong>
                    <?php if ($quote['email']): ?>
                    <div class="mb-1"><i class="bi bi-envelope text-primary"></i> <small><?php echo e($quote['email']); ?></small></div>
                    <?php endif; ?>
                    <?php if ($quote['phone']): ?>
                    <div><i class="bi bi-phone text-success"></i> <small><?php echo e($quote['phone']); ?></small></div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Location Evento (Globale) -->
            <div class="card border-warning">
                <div class="card-header bg-warning d-flex justify-content-between">
                    <h6><i class="bi bi-geo-alt"></i> Location Evento</h6>
                    <?php if ($can_edit && !isset($_GET['edit_location'])): ?>
                    <a href="?id=<?php echo $quote_id; ?>&edit_location=1" class="text-dark"><i class="bi bi-pencil"></i></a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (isset($_GET['edit_location']) && $can_edit): ?>
                    <form method="POST" action="quotes.php?id=<?php echo $quote_id; ?>">
                        <input type="hidden" name="update_location" value="1">
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Location</label>
                            <input type="text" name="event_location" class="form-control form-control-sm" 
                                   value="<?php echo e($quote['event_location'] ?? ''); ?>" 
                                   placeholder="Es: Milano, Via Roma 123">
                        </div>
                        <button type="submit" class="btn btn-warning btn-sm w-100 mb-1">Salva</button>
                        <a href="?id=<?php echo $quote_id; ?>" class="btn btn-secondary btn-sm w-100">Annulla</a>
                    </form>
                    <?php else: ?>
                    <?php if ($quote['event_location']): ?>
                    <p class="mb-0"><strong><i class="bi bi-geo"></i> <?php echo e($quote['event_location']); ?></strong></p>
                    <?php else: ?>
                    <p class="text-muted text-center mb-0"><small>Nessuna location specificata</small></p>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Totali -->
            <div class="card border-warning">
                <div class="card-header bg-warning">
                    <h6><i class="bi bi-calculator"></i> Totali</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm table-borderless">
                        <tr>
                            <td><small>Subtotale:</small></td>
                            <td class="text-end"><strong><?php echo formatPrice($quote['subtotal']); ?></strong></td>
                        </tr>
                        <?php if ($quote['discount_value'] > 0): ?>
                        <tr class="text-danger">
                            <td><small>Sconto:</small></td>
                            <td class="text-end">
                                <small>
                                <?php 
                                if ($quote['discount_type'] === 'percentage') {
                                    echo "- " . $quote['discount_value'] . "%";
                                } else {
                                    echo "- " . formatPrice($quote['discount_value']);
                                }
                                ?>
                                </small>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td><small>Totale:</small></td>
                            <td class="text-end"><strong><?php echo formatPrice($quote['total']); ?></strong></td>
                        </tr>
                        <tr>
                            <td><small>IVA (<?php echo $quote['iva_rate']; ?>%):</small></td>
                            <td class="text-end"><small><?php echo formatPrice($quote['iva_amount']); ?></small></td>
                        </tr>
                        <tr class="table-success border-top">
                            <td><strong><small>TOT. IVA INCL.:</small></strong></td>
                            <td class="text-end">
                                <h5 class="mb-0 text-success"><?php echo formatPrice($quote['total_with_iva']); ?></h5>
                            </td>
                        </tr>
                    </table>
                    
                    <?php if ($can_edit): ?>
                    <?php if (!$show_discount): ?>
                    <a href="?id=<?php echo $quote_id; ?>&discount=1" class="btn btn-warning btn-sm w-100">
                        <i class="bi bi-percent"></i> Sconto
                    </a>
                    <?php else: ?>
                    <div class="card bg-light border-warning">
                        <div class="card-body">
                            <form method="POST" action="quotes.php?id=<?php echo $quote_id; ?>">
                                <input type="hidden" name="update_discount" value="1">
                                <div class="mb-2">
                                    <select name="discount_type" class="form-select form-select-sm">
                                        <option value="none">Nessuno</option>
                                        <option value="percentage" <?php echo $quote['discount_type'] == 'percentage' ? 'selected' : ''; ?>>%</option>
                                        <option value="fixed" <?php echo $quote['discount_type'] == 'fixed' ? 'selected' : ''; ?>>€</option>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <input type="number" step="0.01" name="discount_value" class="form-control form-control-sm" 
                                           value="<?php echo $quote['discount_value']; ?>">
                                </div>
                                <button type="submit" class="btn btn-warning btn-sm w-100 mb-1">Applica</button>
                                <a href="?id=<?php echo $quote_id; ?>" class="btn btn-secondary btn-sm w-100">Annulla</a>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Azioni -->
            <?php if ($can_edit): ?>
            <!-- Cambio Stato -->
            <div class="card border-info">
                <div class="card-header bg-info text-white">
                    <h6><i class="bi bi-lightning"></i> Stato</h6>
                </div>
                <div class="card-body">
                    <form method="POST" action="quotes.php?id=<?php echo $quote_id; ?>" onsubmit="return validateStatusChange(event)">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="quote_id" value="<?php echo $quote_id; ?>">
                        <div class="input-group input-group-sm">
                            <select name="status" class="form-select" id="statusSelect">
                                <option value="bozza" <?php echo $quote['status'] == 'bozza' ? 'selected' : ''; ?>>Bozza</option>
                                <option value="inviato" <?php echo $quote['status'] == 'inviato' ? 'selected' : ''; ?>>Inviato</option>
                                <option value="accettato" <?php echo $quote['status'] == 'accettato' ? 'selected' : ''; ?>>Accettato</option>
                                <option value="confermato" <?php echo $quote['status'] == 'confermato' ? 'selected' : ''; ?>>
                                    Confermato
                                </option>
                                <option value="rifiutato" <?php echo $quote['status'] == 'rifiutato' ? 'selected' : ''; ?>>Rifiutato</option>
                            </select>
                            <button type="submit" class="btn btn-success"><i class="bi bi-check"></i></button>
                        </div>
                    </form>
                    
                    <?php 
                    // Controlla se tutti i pacchetti hanno una data
                    $items_without_date = [];
                    foreach ($items_with_details as $item) {
                        if (empty($item['event_date'])) {
                            $items_without_date[] = $item['package_name'];
                        }
                    }
                    
                    if (!empty($items_without_date) && count($items_with_details) > 0): 
                    ?>
                    <div class="alert alert-warning mt-2 mb-0 py-2">
                        <small>
                            <i class="bi bi-exclamation-triangle"></i> 
                            <strong>Attenzione!</strong> 
                            <?php echo count($items_without_date); ?> pacchetto/i senza data:
                            <ul class="mb-0 mt-1 ps-3">
                                <?php foreach ($items_without_date as $pkg_name): ?>
                                <li><small><?php echo e($pkg_name); ?></small></li>
                                <?php endforeach; ?>
                            </ul>
                            <strong>Assegna le date per poter confermare.</strong>
                        </small>
                    </div>
                    <?php elseif (empty($items_with_details)): ?>
                    <div class="alert alert-warning mt-2 mb-0 py-2">
                        <small><i class="bi bi-exclamation-triangle"></i> <strong>Aggiungi almeno un pacchetto</strong> per poter confermare</small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            

            <script>
            document.getElementById('bringOwnConsole').addEventListener('change', function() {
                const quoteId = <?php echo $quote['id']; ?>;
                const isChecked = this.checked;
                const indicator = document.getElementById('console-status-indicator');

                // Mostra un feedback visivo
                indicator.innerHTML = '<span class="spinner-border spinner-border-sm text-primary" role="status"></span>';

                const formData = new FormData();
                formData.append('action', 'update_console_flag');
                formData.append('quote_id', quoteId);
                if (isChecked) {
                    formData.append('bring_own_console', '1');
                }

                fetch('ajax_handler.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Successo: mostra un'icona di spunta e poi la rimuove
                        indicator.innerHTML = '<i class="bi bi-check-circle-fill text-success fs-5"></i>';
                    } else {
                        // Errore: mostra un'icona di errore e poi la rimuove
                        indicator.innerHTML = '<i class="bi bi-x-circle-fill text-danger fs-5"></i>';
                        alert('Errore: ' + data.error); // Mostra l'errore
                    }
                })
                .catch(error => {
                    indicator.innerHTML = '<i class="bi bi-x-circle-fill text-danger fs-5"></i>';
                    alert('Errore di comunicazione con il server.');
                    console.error('Fetch error:', error);
                })
                .finally(() => {
                    // Rimuovi l'indicatore dopo un breve ritardo
                    setTimeout(() => {
                        indicator.innerHTML = '';
                    }, 2000);
                });
            });
            </script>
            
            <!-- Duplica Preventivo -->
            <div class="card border-secondary">
                <div class="card-body p-2">
                    <form method="POST" action="quotes.php?id=<?php echo $quote_id; ?>">
                        <input type="hidden" name="action" value="duplicate">
                        <input type="hidden" name="quote_id" value="<?php echo $quote_id; ?>">
                        <button type="submit" class="btn btn-outline-secondary btn-sm w-100" onclick="return confirm('Duplicare questo preventivo?')">
                            <i class="bi bi-files"></i> Duplica Preventivo
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Elimina Preventivo -->
            <div class="card border-danger">
                <div class="card-body p-2">
                    <button type="button" class="btn btn-outline-danger btn-sm w-100" onclick="deleteQuote()">
                        <i class="bi bi-trash"></i> Elimina Preventivo
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- COLONNA DESTRA -->
        <div class="col-lg-8">
            <!-- Pacchetti e Servizi -->
            <div class="card">
                <div class="card-header bg-white d-flex justify-content-between">
                    <h6><i class="bi bi-box"></i> Pacchetti e Servizi <span class="badge bg-secondary"><?php echo count($items_with_details); ?></span></h6>
                    <?php if ($can_edit && !$show_add_package && !$show_add_service && !$show_edit_item): ?>
                    <div class="btn-group btn-group-sm">
                        <a href="?id=<?php echo $quote_id; ?>&add_package=1" class="btn btn-primary">Pacchetto</a>
                        <a href="?id=<?php echo $quote_id; ?>&add_service=1" class="btn btn-success">Servizio</a>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    
                    <!-- Form Aggiungi Pacchetto -->
                    <?php if ($show_add_package && $can_edit): ?>
                    <div class="card bg-light border-primary mb-3">
                        <div class="card-header bg-primary text-white">
                            <h6 class="mb-0">Aggiungi Pacchetto</h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($available_packages)): ?>
                            <div class="alert alert-warning mb-2">
                                <small><i class="bi bi-exclamation-triangle"></i> Nessun pacchetto disponibile. 
                                <?php if (isAdmin()): ?>
                                <a href="admin/packages.php" target="_blank">Creane uno</a>
                                <?php else: ?>
                                Contatta un amministratore per creare pacchetti.
                                <?php endif; ?>
                                </small>
                            </div>
                            <a href="?id=<?php echo $quote_id; ?>" class="btn btn-secondary btn-sm w-100">Torna Indietro</a>
                            <?php else: ?>
                            <form method="POST" action="quotes.php?id=<?php echo $quote_id; ?>">
                                <input type="hidden" name="add_package" value="1">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Seleziona Pacchetto *</label>
                                    <select name="package_id" class="form-select form-select-sm" required onchange="loadPackageDetails(this.value)">
                                        <option value="">-- Scegli un pacchetto --</option>
                                        <?php foreach ($available_packages as $pkg): ?>
                                        <option value="<?php echo $pkg['id']; ?>" data-price="<?php echo $pkg['price']; ?>">
                                            <?php echo e($pkg['name']); ?> - <?php echo formatPrice($pkg['price']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Prezzo Personalizzato</label>
                                    <input type="number" step="0.01" name="custom_price" class="form-control form-control-sm" 
                                           placeholder="Lascia vuoto per usare il prezzo del pacchetto">
                                </div>
                                
                                <!-- Anteprima servizi e ruoli del pacchetto -->
                                <div id="packagePreview" class="d-none">
                                    <div class="package-preview">
                                        <h6 class="text-primary mb-2"><i class="bi bi-eye"></i> Anteprima Pacchetto</h6>
                                        
                                        <div class="row">
                                            <div class="col-md-8">
                                                <strong class="small">Servizi Inclusi:</strong>
                                                <div id="servicesPreview"></div>
                                            </div>
                                            <div class="col-md-4">
                                                <strong class="small">Ruoli Richiesti:</strong>
                                                <div id="rolesPreview"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary btn-sm w-100 mb-1">
                                    <i class="bi bi-plus-circle"></i> Aggiungi Pacchetto
                                </button>
                                <a href="?id=<?php echo $quote_id; ?>" class="btn btn-secondary btn-sm w-100">Annulla</a>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Form Servizio Custom -->
                    <?php if ($show_add_service && $can_edit): ?>
                    <div class="card bg-light border-success mb-3">
                        <div class="card-header bg-success text-white">
                            <h6 class="mb-0">Aggiungi Servizio Personalizzato</h6>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="quotes.php?id=<?php echo $quote_id; ?>">
                                <input type="hidden" name="add_custom_service" value="1">
                                <div class="mb-2">
                                    <label class="form-label small fw-bold">Nome Servizio *</label>
                                    <input type="text" name="service_name" class="form-control form-control-sm" required placeholder="Nome del servizio personalizzato">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-bold">Prezzo *</label>
                                    <input type="number" step="0.01" name="service_price" class="form-control form-control-sm" required placeholder="0.00">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-bold">Descrizione</label>
                                    <textarea name="service_description" class="form-control form-control-sm" rows="3" placeholder="Descrizione dettagliata del servizio"></textarea>
                                </div>
                                
                                <button type="submit" class="btn btn-success btn-sm w-100 mb-1">
                                    <i class="bi bi-plus-circle"></i> Aggiungi Servizio
                                </button>
                                <a href="?id=<?php echo $quote_id; ?>" class="btn btn-secondary btn-sm w-100">Annulla</a>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- FORM MODIFICA ELEMENTO -->
                    <?php if ($show_edit_item && $edit_item && $can_edit): ?>
                    <div class="card bg-light border-warning mb-3">
                        <div class="card-header bg-warning">
                            <h6 class="mb-0">
                                <i class="bi bi-pencil"></i> Modifica: <?php echo e($edit_item['package_name']); ?>
                                <?php if ($edit_item['package_id']): ?>
                                <span class="badge bg-primary ms-2">Pacchetto</span>
                                <?php else: ?>
                                <span class="badge bg-success ms-2">Custom</span>
                                <?php endif; ?>
                            </h6>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="quotes.php?id=<?php echo $quote_id; ?>">
                                <input type="hidden" name="edit_item" value="1">
                                <input type="hidden" name="item_id" value="<?php echo $edit_item['id']; ?>">
                                
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Prezzo Personalizzato *</label>
                                    <input type="number" step="0.01" name="item_price" class="form-control form-control-sm" 
                                           required value="<?php echo $edit_item['price']; ?>" min="0">
                                    <small class="text-muted">
                                        <?php if ($edit_item['package_id']): ?>
                                        Prezzo originale pacchetto: <?php 
                                        $orig_pkg = array_filter($available_packages, function($p) use ($edit_item) { 
                                            return $p['id'] == $edit_item['package_id']; 
                                        });
                                        if ($orig_pkg) {
                                            $orig_pkg = array_values($orig_pkg)[0];
                                            echo formatPrice($orig_pkg['price']);
                                        }
                                        ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                
                                <!-- Servizi -->
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Servizi Inclusi</label>
                                    <div id="editServicesContainer">
                                        <?php if (!empty($edit_item['item_services'])): ?>
                                            <?php foreach ($edit_item['item_services'] as $idx => $service): ?>
                                            <div class="service-row">
                                                <div class="input-group mb-2">
                                                    <input type="text" name="services[]" class="form-control form-control-sm" 
                                                           value="<?php echo e($service['service_name']); ?>" placeholder="Nome servizio">
                                                    <div class="input-group-text">
                                                        <input type="checkbox" name="mandatory[<?php echo $idx; ?>]" 
                                                               <?php echo $service['is_mandatory'] ? 'checked' : ''; ?>>
                                                        <small class="ms-1">Obblig.</small>
                                                    </div>
                                                    <button type="button" class="btn btn-sm btn-danger" onclick="removeServiceRow(this)">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                        <div class="service-row">
                                            <div class="input-group mb-2">
                                                <input type="text" name="services[]" class="form-control form-control-sm" placeholder="Nome servizio">
                                                <div class="input-group-text">
                                                    <input type="checkbox" name="mandatory[0]">
                                                    <small class="ms-1">Obblig.</small>
                                                </div>
                                                <button type="button" class="btn btn-sm btn-danger" onclick="removeServiceRow(this)">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="addEditServiceRow()">
                                        <i class="bi bi-plus"></i> Aggiungi Servizio
                                    </button>
                                </div>
                                
                                <!-- Ruoli -->
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Ruoli Richiesti</label>
                                    <div class="row">
                                        <?php foreach ($all_roles as $role): ?>
                                        <div class="col-md-6 mb-2">
                                            <div class="input-group input-group-sm">
                                                <span class="input-group-text"><?php echo e($role['name']); ?></span>
                                                <input type="number" min="0" name="roles[<?php echo $role['id']; ?>]" 
                                                       class="form-control" 
                                                       value="<?php echo $edit_item['item_roles'][$role['id']] ?? 0; ?>">
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-warning btn-sm w-100 mb-1">
                                    <i class="bi bi-check-circle"></i> Salva Modifiche
                                </button>
                                <a href="?id=<?php echo $quote_id; ?>" class="btn btn-secondary btn-sm w-100">Annulla</a>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Lista Items con Servizi, Ruoli e Data/Ora -->
                    <?php if (empty($items_with_details)): ?>
                    <div class="alert alert-info text-center mb-0">
                        <i class="bi bi-info-circle"></i>
                        <strong>Nessun elemento nel preventivo</strong><br>
                        <small>Aggiungi un pacchetto o servizio personalizzato per iniziare.</small>
                    </div>
                    <?php else: ?>
                    <?php foreach ($items_with_details as $idx => $item): 
                        $hasDate = !empty($item['event_date']);
                        $cardBorderClass = $hasDate ? 
                            ($item['package_id'] ? 'border-primary' : 'border-success') : 
                            'border-danger';
                    ?>
                    <div class="card mb-3 border <?php echo $cardBorderClass; ?> <?php echo !$hasDate ? 'missing-date-warning' : ''; ?>">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div>
                                <?php if (!$hasDate): ?>
                                <span class="badge bg-danger me-2">
                                    <i class="bi bi-exclamation-triangle"></i> MANCA DATA
                                </span>
                                <?php endif; ?>
                                <h6 class="mb-0 d-inline">
                                    <span class="badge bg-secondary me-2"><?php echo $idx + 1; ?></span>
                                    <?php echo e($item['package_name']); ?>
                                    <?php if ($item['package_id']): ?>
                                    <span class="badge bg-primary ms-2">Pacchetto</span>
                                    <?php else: ?>
                                    <span class="badge bg-success ms-2">Servizio Custom</span>
                                    <?php endif; ?>
                                </h6>
                                <!-- Data e Ora del Pacchetto -->
                                <?php if ($item['event_date'] || $item['event_time']): ?>
                                <div class="mt-1">
                                    <small class="text-muted">
                                        <?php if ($item['event_date']): ?>
                                        <i class="bi bi-calendar-event"></i> <?php echo formatDate($item['event_date']); ?>
                                        <?php endif; ?>
                                        <?php if ($item['event_time']): ?>
                                        • <i class="bi bi-clock"></i> <?php echo substr($item['event_time'], 0, 5); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <?php else: ?>
                                <div class="mt-1">
                                    <small>
                                        <i class="bi bi-calendar-x"></i> <strong>Nessuna data assegnata - Clicca 📅 per impostare</strong>
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex align-items-center">
                                <h5 class="mb-0 text-primary me-3"><?php echo formatPrice($item['price']); ?></h5>
                                <?php if ($can_edit): ?>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-info"  
                                            data-bs-toggle="modal" 
                                            data-bs-target="#datetimeModal<?php echo $item['id']; ?>"
                                            title="Imposta data/ora">
                                        <i class="bi bi-calendar-plus"></i>
                                    </button>
                                    <a href="?id=<?php echo $quote_id; ?>&edit=<?php echo $item['id']; ?>" 
                                       class="btn btn-outline-warning" title="Modifica elemento">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="?id=<?php echo $quote_id; ?>&delete_item=<?php echo $item['id']; ?>" 
                                       class="btn btn-outline-danger"
                                       onclick="return confirm('Eliminare questo elemento dal preventivo?\n\nElemento: <?php echo e($item['package_name']); ?>\nPrezzo: <?php echo formatPrice($item['price']); ?>')" title="Elimina elemento">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <!-- Servizi -->
                                <div class="col-md-8">
                                    <h6 class="text-primary mb-2"><i class="bi bi-list-check"></i> Servizi Inclusi</h6>
                                    <?php if (!empty($item['item_services'])): ?>
                                    <?php foreach ($item['item_services'] as $service): ?>
                                    <div class="service-item <?php echo $service['is_mandatory'] ? 'mandatory-service' : ''; ?>">
                                        <small>
                                            <?php if ($service['is_mandatory']): ?>
                                            <i class="bi bi-lock-fill text-danger" title="Servizio obbligatorio"></i>
                                            <?php else: ?>
                                            <i class="bi bi-check2 text-success" title="Servizio incluso"></i>
                                            <?php endif; ?>
                                            <?php echo e($service['service_name']); ?>
                                        </small>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php else: ?>
                                    <small class="text-muted"><i class="bi bi-info-circle"></i> Nessun servizio specificato</small>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Ruoli -->
                                <div class="col-md-4">
                                    <h6 class="text-success mb-2"><i class="bi bi-people"></i> Ruoli Richiesti</h6>
                                    <?php if (!empty($item['item_roles'])): ?>
                                    <?php foreach ($item['item_roles'] as $role): ?>
                                    <div class="role-item mb-1">
                                        <span class="badge bg-info">
                                            <?php echo $role['quantity']; ?>x <?php echo e($role['role_name']); ?>
                                        </span>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php else: ?>
                                    <small class="text-muted"><i class="bi bi-info-circle"></i> Nessun ruolo specificato</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Modal Data/Ora per questo Pacchetto -->
                    <div class="modal fade" id="datetimeModal<?php echo $item['id']; ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header bg-info text-white">
                                    <h6 class="modal-title mb-0">
                                        <i class="bi bi-calendar-plus"></i> Data e Ora: <?php echo e($item['package_name']); ?>
                                    </h6>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <form method="POST" action="quotes.php?id=<?php echo $quote_id; ?>">
                                    <input type="hidden" name="update_item_datetime" value="1">
                                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                    <div class="modal-body">
                                        <div class="alert alert-info py-2">
                                            <small><i class="bi bi-info-circle"></i> <strong>Location:</strong> 
                                            <?php echo $quote['event_location'] ? e($quote['event_location']) : 'Non specificata'; ?>
                                            </small>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Data Evento *</label>
                                            <input type="date" name="event_date" class="form-control" 
                                                   value="<?php echo $item['event_date'] ?? ''; ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Ora Evento</label>
                                            <input type="time" name="event_time" class="form-control" 
                                                   value="<?php echo $item['event_time'] ?? ''; ?>">
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                                        <button type="submit" class="btn btn-info">
                                            <i class="bi bi-save"></i> Salva Data/Ora
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <!-- Riepilogo Totale -->
                    <div class="card bg-light border-warning">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="mb-0"><i class="bi bi-calculator"></i> SUBTOTALE ELEMENTI:</h6>
                                <h5 class="mb-0 text-primary"><?php echo formatPrice($quote['subtotal']); ?></h5>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Allegati -->
            <div class="card">
                <div class="card-header">
                    <h6><i class="bi bi-paperclip"></i> Allegati <span class="badge bg-secondary"><?php echo count($attachments); ?></span></h6>
                </div>
                <div class="card-body">
                    <?php if ($can_edit): ?>
                    <form method="POST" enctype="multipart/form-data" class="mb-2">
                        <input type="hidden" name="quote_id" value="<?php echo $quote_id; ?>">
                        <div class="input-group input-group-sm">
                            <input type="file" name="attachment" class="form-control" required>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Carica</button>
                        </div>
                    </form>
                    <?php endif; ?>
                    
                    <?php if (empty($attachments)): ?>
                    <p class="text-muted text-center mb-0"><small><i class="bi bi-info-circle"></i> Nessun allegato caricato</small></p>
                    <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($attachments as $att): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <?php
                                // Correggi il path degli allegati
                                $corrected_path = str_replace('/home/u362062795/domains/beatitaliano.it/public_html/crm/', '/crm/', $att['file_path']);
                                ?>
                                <a href="<?php echo e($corrected_path); ?>" target="_blank" class="text-decoration-none">
                                    <i class="bi bi-file-earmark"></i> <?php echo e($att['original_name']); ?>
                                </a>
                            </div>
                            <?php if ($can_edit): ?>
                            <a href="?id=<?php echo $quote_id; ?>&delete_attachment=<?php echo $att['id']; ?>" 
                               class="btn btn-sm btn-outline-danger"
                               onclick="return confirm('Eliminare questo allegato?')" title="Elimina allegato">
                                <i class="bi bi-trash"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Note -->
            <div class="card">
                <div class="card-header">
                    <h6><i class="bi bi-sticky"></i> Note Interne</h6>
                </div>
                <div class="card-body">
                    <?php if ($can_edit): ?>
                    <form method="POST" action="quotes.php?id=<?php echo $quote_id; ?>">
                        <input type="hidden" name="action" value="add_note">
                        <input type="hidden" name="quote_id" value="<?php echo $quote_id; ?>">
                        <textarea name="internal_notes" class="form-control form-control-sm mb-2" rows="4" placeholder="Aggiungi note interne per questo preventivo..."><?php echo e($quote['internal_notes'] ?? ''); ?></textarea>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-save"></i> Salva Note
                        </button>
                    </form>
                    <?php else: ?>
                    <div class="bg-light p-2 rounded">
                        <small><?php echo nl2br(e($quote['internal_notes'] ?? 'Nessuna nota disponibile')); ?></small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal per conferma cambio stato -->
<div class="modal fade" id="statusChangeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Conferma Esportazione PDF</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Il preventivo è attualmente in stato <strong><?php echo ucfirst($quote['status']); ?></strong>.</p>
                <?php if ($quote['status'] === 'bozza'): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    Vuoi cambiare lo stato da <strong>Bozza</strong> a <strong>Inviato</strong> prima di generare il PDF?
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="changeStatusCheck" checked>
                    <label class="form-check-label" for="changeStatusCheck">
                        Sì, cambia lo stato a "Inviato"
                    </label>
                </div>
                <?php else: ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle"></i>
                    Il preventivo verrà esportato mantenendo lo stato attuale.
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="button" class="btn btn-danger" onclick="proceedWithPDF()">
                    <i class="bi bi-file-earmark-pdf"></i> Genera PDF
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal per conferma eliminazione -->
<div class="modal fade" id="deleteQuoteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">⚠️ Conferma Eliminazione</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>ATTENZIONE: Questa azione è irreversibile!</strong>
                </div>
                
                <p>Stai per eliminare definitivamente il preventivo:</p>
                <div class="bg-light p-3 rounded mb-3">
                    <strong><?php echo e($quote['quote_number']); ?></strong><br>
                    Cliente: <?php echo e($client_name); ?><br>
                    Totale: <?php echo formatPrice($quote['total_with_iva']); ?><br>
                    Stato: <?php echo ucfirst($quote['status']); ?>
                </div>
                
                <p>Verranno eliminati anche:</p>
                <ul>
                    <li><?php echo count($items_with_details); ?> elementi (pacchetti/servizi)</li>
                    <li><?php echo count($dates); ?> date evento</li>
                    <li><?php echo count($attachments); ?> allegati</li>
                    <li>Tutte le note interne</li>
                </ul>
                
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" id="confirmDelete" required>
                    <label class="form-check-label text-danger" for="confirmDelete">
                        <strong>Confermo di voler eliminare definitivamente questo preventivo</strong>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="button" class="btn btn-danger" onclick="proceedWithDelete()" id="confirmDeleteBtn" disabled>
                    <i class="bi bi-trash"></i> Elimina Definitivamente
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Dati pacchetti per JavaScript
const packagesData = <?php echo json_encode($available_packages); ?>;
let serviceIndex = 1;
let editServiceIndex = <?php echo $edit_item && !empty($edit_item['item_services']) ? count($edit_item['item_services']) : 1; ?>;

// Funzione per esportare in PDF
function exportToPDF() {
    const modal = new bootstrap.Modal(document.getElementById('statusChangeModal'));
    modal.show();
}

function proceedWithPDF() {
    const changeStatus = document.getElementById('changeStatusCheck') ? document.getElementById('changeStatusCheck').checked : false;
    const currentStatus = '<?php echo $quote['status']; ?>';
    
    // Costruisci URL per PDF
    let pdfUrl = 'generate_quote_pdf.php?id=<?php echo $quote_id; ?>';
    
    // Se è bozza e l'utente vuole cambiare stato
    if (currentStatus === 'bozza' && changeStatus) {
        pdfUrl += '&change_status=1';
    }
    
    // Apri PDF in nuova finestra
    window.open(pdfUrl, '_blank');
    
    // Chiudi modal
    bootstrap.Modal.getInstance(document.getElementById('statusChangeModal')).hide();
    
    // Se abbiamo cambiato lo stato, ricarica la pagina dopo un po'
    if (currentStatus === 'bozza' && changeStatus) {
        setTimeout(() => {
            window.location.reload();
        }, 1000);
    }
}

// Funzione per eliminare il preventivo
function deleteQuote() {
    const modal = new bootstrap.Modal(document.getElementById('deleteQuoteModal'));
    modal.show();
}

function proceedWithDelete() {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'quotes.php?id=<?php echo $quote_id; ?>';
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'delete_quote';
    
    const quoteInput = document.createElement('input');
    quoteInput.type = 'hidden';
    quoteInput.name = 'quote_id';
    quoteInput.value = '<?php echo $quote_id; ?>';
    
    form.appendChild(actionInput);
    form.appendChild(quoteInput);
    document.body.appendChild(form);
    form.submit();
}

// Abilita/disabilita bottone conferma eliminazione
document.getElementById('confirmDelete').addEventListener('change', function() {
    document.getElementById('confirmDeleteBtn').disabled = !this.checked;
});

// Validazione cambio stato
function validateStatusChange(event) {
    const selectedStatus = document.getElementById('statusSelect').value;
    
    // Se non sta cercando di confermare, permetti
    if (selectedStatus !== 'confermato') {
        return true;
    }
    
    // Controlla se ci sono pacchetti senza data
    const itemsWithoutDate = <?php echo json_encode($items_without_date ?? []); ?>;
    const totalItems = <?php echo count($items_with_details); ?>;
    
    // Blocca se ci sono pacchetti senza data
    if (itemsWithoutDate.length > 0) {
        event.preventDefault();
        
        let packagesList = itemsWithoutDate.map(name => `  • ${name}`).join('\n');
        
        alert(`❌ IMPOSSIBILE CONFERMARE

I seguenti pacchetti non hanno una data assegnata:

${packagesList}

Clicca sull'icona 📅 accanto a ogni pacchetto per assegnare data e ora.`);
        
        return false;
    }
    
    // Blocca se non ci sono pacchetti
    if (totalItems === 0) {
        event.preventDefault();
        alert('❌ IMPOSSIBILE CONFERMARE\n\nDevi aggiungere almeno un pacchetto al preventivo prima di poter confermare.');
        return false;
    }
    
    // Conferma cambio stato importante
    return confirm(`✅ Confermare il preventivo?

Questo stato indica che il preventivo è stato accettato e confermato dal cliente.

Tutti i pacchetti hanno una data assegnata.`);
}

// Carica dettagli pacchetto nella preview
function loadPackageDetails(packageId) {
    const preview = document.getElementById('packagePreview');
    const servicesPreview = document.getElementById('servicesPreview');
    const rolesPreview = document.getElementById('rolesPreview');
    
    if (!packageId) {
        preview.classList.add('d-none');
        return;
    }
    
    const pkg = packagesData.find(p => p.id == packageId);
    if (!pkg) return;
    
    // Mostra servizi
    servicesPreview.innerHTML = '';
    if (pkg.services && pkg.services.length > 0) {
        pkg.services.forEach(service => {
            const div = document.createElement('div');
            div.className = 'service-item' + (service.is_mandatory == 1 ? ' mandatory-service' : '');
            div.innerHTML = `<small>
                ${service.is_mandatory == 1 ? '<i class="bi bi-lock-fill text-danger" title="Obbligatorio"></i>' : '<i class="bi bi-check2 text-success" title="Incluso"></i>'}
                ${service.service_name}
            </small>`;
            servicesPreview.appendChild(div);
        });
    } else {
        servicesPreview.innerHTML = '<small class="text-muted">Nessun servizio definito</small>';
    }
    
    // Mostra ruoli
    rolesPreview.innerHTML = '';
    if (pkg.roles && pkg.roles.length > 0) {
        pkg.roles.forEach(role => {
            const div = document.createElement('div');
            div.className = 'role-item mb-1';
            div.innerHTML = `<span class="badge bg-info">${role.quantity}x ${role.role_name}</span>`;
            rolesPreview.appendChild(div);
        });
    } else {
        rolesPreview.innerHTML = '<small class="text-muted">Nessun ruolo definito</small>';
    }
    
    preview.classList.remove('d-none');
    
    // Aggiorna placeholder prezzo
    const priceInput = document.querySelector('input[name="custom_price"]');
    if (priceInput && !priceInput.value) {
        priceInput.placeholder = `Prezzo pacchetto: €${parseFloat(pkg.price).toFixed(2)}`;
    }
}

// Funzioni per gestire i servizi nel form di modifica
function addEditServiceRow() {
    const container = document.getElementById('editServicesContainer');
    const div = document.createElement('div');
    div.className = 'service-row';
    div.innerHTML = `
        <div class="input-group mb-2">
            <input type="text" name="services[]" class="form-control form-control-sm" placeholder="Nome servizio">
            <div class="input-group-text">
                <input type="checkbox" name="mandatory[${editServiceIndex}]">
                <small class="ms-1">Obblig.</small>
            </div>
            <button type="button" class="btn btn-sm btn-danger" onclick="removeServiceRow(this)">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    `;
    container.appendChild(div);
    editServiceIndex++;
}

function removeServiceRow(button) {
    button.closest('.service-row').remove();
}

// Debug
document.addEventListener('DOMContentLoaded', function() {
    console.log('Quote detail page loaded successfully');
    console.log('Available packages:', packagesData.length);
    console.log('Current user can edit:', <?php echo $can_edit ? 'true' : 'false'; ?>);
    console.log('Current user is admin:', <?php echo isAdmin() ? 'true' : 'false'; ?>);
    console.log('Has dates:', <?php echo empty($dates) ? 'false' : 'true'; ?>);
});
</script>