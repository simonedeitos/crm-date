<?php
require_once '../config.php';
require_once '../auth.php';
require_once '../functions.php';

requireAdmin();

$db = getDBConnection();
$error = '';
$success = '';

// Ottieni lista di TUTTI gli utenti attivi (per il form)
$users_list = [];
$u_query = mysqli_query($db, "SELECT id, username, first_name, last_name FROM users WHERE is_active = 1 ORDER BY username");
while($u = mysqli_fetch_assoc($u_query)) {
    $users_list[] = $u;
}

// Gestione azioni
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
            $name = mysqli_real_escape_string($db, $_POST['name']);
            $price = (float)$_POST['price'];
            $min_price = (float)$_POST['min_price'];
            $commission = (float)($_POST['commission'] ?? 0);
            $description = mysqli_real_escape_string($db, $_POST['description'] ?? '');
            $has_graphics = isset($_POST['has_graphics']) ? 1 : 0; // NUOVO CAMPO
            
            // Gestione Upload Immagine
            $image_path = null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
                $upload_dir = '../uploads/packages/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
                
                $file_ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                
                if (in_array($file_ext, $allowed)) {
                    $new_filename = uniqid('pkg_') . '.' . $file_ext;
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $new_filename)) {
                        $image_path = 'uploads/packages/' . $new_filename;
                    }
                }
            }
            
            $img_sql_val = $image_path ? "'$image_path'" : "NULL";
            
            $query = "INSERT INTO packages (name, image_path, price, min_price, commission, description, has_graphics, is_active, created_at)
                      VALUES ('$name', $img_sql_val, $price, $min_price, $commission, '$description', $has_graphics, 1, NOW())";
            
            if (mysqli_query($db, $query)) {
                $package_id = mysqli_insert_id($db);
                
                // Salva servizi
                if (isset($_POST['services']) && is_array($_POST['services'])) {
                    foreach ($_POST['services'] as $idx => $service_name) {
                        if (!empty($service_name)) {
                            $service_name = mysqli_real_escape_string($db, $service_name);
                            $is_mandatory = isset($_POST['mandatory'][$idx]) ? 1 : 0;
                            mysqli_query($db, "INSERT INTO package_services (package_id, service_name, is_mandatory, sort_order) 
                                               VALUES ($package_id, '$service_name', $is_mandatory, $idx)");
                        }
                    }
                }
                
                // Salva ruoli
                if (isset($_POST['roles']) && is_array($_POST['roles'])) {
                    foreach ($_POST['roles'] as $role_id => $quantity) {
                        if ($quantity > 0) {
                            mysqli_query($db, "INSERT INTO package_staff_roles (package_id, role_id, quantity) 
                                               VALUES ($package_id, $role_id, $quantity)");
                        }
                    }
                }

                // SALVA VISIBILITÀ UTENTI
                if (isset($_POST['authorized_users']) && is_array($_POST['authorized_users'])) {
                    foreach ($_POST['authorized_users'] as $user_id) {
                        $user_id = (int)$user_id;
                        mysqli_query($db, "INSERT INTO package_users (package_id, user_id) VALUES ($package_id, $user_id)");
                    }
                }
                
                $success = "Pacchetto creato con successo!";
            } else {
                $error = "Errore: " . mysqli_error($db);
            }
            break;
            
        case 'update':
            $package_id = (int)$_POST['package_id'];
            $name = mysqli_real_escape_string($db, $_POST['name']);
            $price = (float)$_POST['price'];
            $min_price = (float)$_POST['min_price'];
            $commission = (float)($_POST['commission'] ?? 0);
            $description = mysqli_real_escape_string($db, $_POST['description'] ?? '');
            $has_graphics = isset($_POST['has_graphics']) ? 1 : 0; // NUOVO CAMPO
            
            // Gestione Upload Immagine (Update)
            $image_sql = "";
            if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
                $upload_dir = '../uploads/packages/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
                
                $file_ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                
                if (in_array($file_ext, $allowed)) {
                    $old_img_query = mysqli_query($db, "SELECT image_path FROM packages WHERE id = $package_id");
                    $old_img = mysqli_fetch_assoc($old_img_query);
                    if ($old_img && $old_img['image_path'] && file_exists('../' . $old_img['image_path'])) {
                        unlink('../' . $old_img['image_path']);
                    }

                    $new_filename = uniqid('pkg_') . '.' . $file_ext;
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $new_filename)) {
                        $image_path = 'uploads/packages/' . $new_filename;
                        $image_sql = ", image_path = '$image_path'";
                    }
                }
            }
            
            $query = "UPDATE packages SET name = '$name' $image_sql, price = $price, min_price = $min_price, 
                      commission = $commission, description = '$description', has_graphics = $has_graphics, updated_at = NOW()
                      WHERE id = $package_id";
            
            if (mysqli_query($db, $query)) {
                // Servizi
                mysqli_query($db, "DELETE FROM package_services WHERE package_id = $package_id");
                if (isset($_POST['services']) && is_array($_POST['services'])) {
                    foreach ($_POST['services'] as $idx => $service_name) {
                        if (!empty($service_name)) {
                            $service_name = mysqli_real_escape_string($db, $service_name);
                            $is_mandatory = isset($_POST['mandatory'][$idx]) ? 1 : 0;
                            mysqli_query($db, "INSERT INTO package_services (package_id, service_name, is_mandatory, sort_order) 
                                               VALUES ($package_id, '$service_name', $is_mandatory, $idx)");
                        }
                    }
                }
                
                // Ruoli
                mysqli_query($db, "DELETE FROM package_staff_roles WHERE package_id = $package_id");
                if (isset($_POST['roles']) && is_array($_POST['roles'])) {
                    foreach ($_POST['roles'] as $role_id => $quantity) {
                        if ($quantity > 0) {
                            mysqli_query($db, "INSERT INTO package_staff_roles (package_id, role_id, quantity) 
                                               VALUES ($package_id, $role_id, $quantity)");
                        }
                    }
                }

                // AGGIORNA VISIBILITÀ UTENTI
                mysqli_query($db, "DELETE FROM package_users WHERE package_id = $package_id");
                if (isset($_POST['authorized_users']) && is_array($_POST['authorized_users'])) {
                    foreach ($_POST['authorized_users'] as $user_id) {
                        $user_id = (int)$user_id;
                        mysqli_query($db, "INSERT INTO package_users (package_id, user_id) VALUES ($package_id, $user_id)");
                    }
                }
                
                $success = "Pacchetto aggiornato con successo!";
            } else {
                $error = "Errore: " . mysqli_error($db);
            }
            break;
            
        case 'toggle_active':
            $package_id = (int)$_POST['package_id'];
            $is_active = (int)$_POST['is_active'];
            mysqli_query($db, "UPDATE packages SET is_active = $is_active WHERE id = $package_id");
            $success = "Stato pacchetto aggiornato";
            break;
            
        case 'delete':
            $package_id = (int)$_POST['package_id'];
            $img_query = mysqli_query($db, "SELECT image_path FROM packages WHERE id = $package_id");
            $img_data = mysqli_fetch_assoc($img_query);
            if ($img_data && $img_data['image_path'] && file_exists('../' . $img_data['image_path'])) {
                unlink('../' . $img_data['image_path']);
            }
            mysqli_query($db, "DELETE FROM packages WHERE id = $package_id");
            $success = "Pacchetto eliminato";
            break;
    }
}

// Ottieni tutti i pacchetti
$packages_result = mysqli_query($db, "SELECT * FROM packages ORDER BY created_at DESC");
$packages = [];
while ($row = mysqli_fetch_assoc($packages_result)) {
    $auth_count_res = mysqli_query($db, "SELECT COUNT(*) as count FROM package_users WHERE package_id = {$row['id']}");
    $auth_count = mysqli_fetch_assoc($auth_count_res)['count'];
    $row['auth_users_count'] = $auth_count;
    
    $packages[] = $row;
}

// Ottieni tutti i ruoli per il form
$roles_result = mysqli_query($db, "SELECT * FROM staff_roles ORDER BY name");
$roles = [];
while ($row = mysqli_fetch_assoc($roles_result)) {
    $roles[] = $row;
}

// Se è richiesto un singolo pacchetto per modifica
$edit_package = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $result = mysqli_query($db, "SELECT * FROM packages WHERE id = $edit_id");
    $edit_package = mysqli_fetch_assoc($result);
    
    // Servizi
    $services_result = mysqli_query($db, "SELECT * FROM package_services WHERE package_id = $edit_id ORDER BY sort_order");
    $edit_package['services'] = [];
    while ($row = mysqli_fetch_assoc($services_result)) {
        $edit_package['services'][] = $row;
    }
    
    // Ruoli
    $roles_result = mysqli_query($db, "SELECT * FROM package_staff_roles WHERE package_id = $edit_id");
    $edit_package['roles'] = [];
    while ($row = mysqli_fetch_assoc($roles_result)) {
        $edit_package['roles'][$row['role_id']] = $row['quantity'];
    }

    // Utenti Autorizzati
    $users_result = mysqli_query($db, "SELECT user_id FROM package_users WHERE package_id = $edit_id");
    $edit_package['authorized_users'] = [];
    while ($row = mysqli_fetch_assoc($users_result)) {
        $edit_package['authorized_users'][] = $row['user_id'];
    }
}

$pageTitle = 'Gestione Pacchetti Format';
include '../includes/header.php';
?>

<style>
    .package-logo-container {
        height: 180px;
        width: 100%;
        background-color: #f8f9fa;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        border-bottom: 1px solid #e9ecef;
    }
    
    .package-logo {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
        padding: 10px;
    }
    
    .no-logo-placeholder {
        color: #adb5bd;
        font-size: 3rem;
    }
    
    .users-list-scroll {
        max-height: 200px;
        overflow-y: auto;
        border: 1px solid #dee2e6;
        padding: 10px;
        border-radius: 4px;
        background: #fff;
    }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-box"></i> Pacchetti Format</h2>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#packageModal" onclick="resetForm()">
            <i class="bi bi-plus-circle"></i> Nuovo Pacchetto
        </button>
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
    
    <!-- Lista Pacchetti -->
    <div class="row">
        <?php foreach ($packages as $package): 
            $services_count = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) as count FROM package_services WHERE package_id = {$package['id']}"))['count'];
            
            $package_roles = [];
            $roles_result = mysqli_query($db, "SELECT sr.name, psr.quantity 
                                               FROM package_staff_roles psr 
                                               JOIN staff_roles sr ON psr.role_id = sr.id 
                                               WHERE psr.package_id = {$package['id']}");
            while ($row = mysqli_fetch_assoc($roles_result)) {
                $package_roles[] = $row;
            }
        ?>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 <?php echo $package['is_active'] ? '' : 'border-secondary'; ?>">
                
                <div class="package-logo-container">
                    <?php if (!empty($package['image_path']) && file_exists('../' . $package['image_path'])): ?>
                        <img src="../<?php echo $package['image_path']; ?>" alt="Logo <?php echo e($package['name']); ?>" class="package-logo">
                    <?php else: ?>
                        <div class="no-logo-placeholder">
                            <i class="bi bi-image"></i>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0"><?php echo e($package['name']); ?></h5>
                        <?php if ($package['auth_users_count'] > 0): ?>
                            <small class="badge bg-warning text-dark" title="Visibile solo a utenti specifici">
                                <i class="bi bi-lock-fill"></i> Ristretto
                            </small>
                        <?php else: ?>
                            <small class="badge bg-info text-dark" title="Visibile a tutti">
                                <i class="bi bi-globe"></i> Pubblico
                            </small>
                        <?php endif; ?>
                        
                        <!-- Badge Grafica -->
                        <?php if (isset($package['has_graphics']) && $package['has_graphics']): ?>
                            <small class="badge bg-primary text-white" title="Grafica inclusa">
                                <i class="bi bi-palette"></i> Grafiche
                            </small>
                        <?php endif; ?>
                    </div>
                    <span class="badge <?php echo $package['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                        <?php echo $package['is_active'] ? 'Attivo' : 'Disattivo'; ?>
                    </span>
                </div>
                <div class="card-body">
                    <?php if ($package['description']): ?>
                    <p class="text-muted small mb-3"><?php echo nl2br(e($package['description'])); ?></p>
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <small class="text-uppercase text-muted fw-bold">Prezzi</small><br>
                        Prezzo: <strong><?php echo formatPrice($package['price']); ?></strong><br>
                        Minimo: <strong><?php echo formatPrice($package['min_price']); ?></strong>
                    </div>
                    
                    <div class="mb-3">
                        <small class="text-uppercase text-muted fw-bold">Servizi (<?php echo $services_count; ?>)</small><br>
                        <?php
                        $services = mysqli_query($db, "SELECT * FROM package_services WHERE package_id = {$package['id']} ORDER BY sort_order LIMIT 5");
                        while ($service = mysqli_fetch_assoc($services)) {
                            echo '<small class="d-block text-truncate">';
                            echo $service['is_mandatory'] ? '<i class="bi bi-lock-fill text-danger"></i> ' : '<i class="bi bi-check-circle text-success"></i> ';
                            echo e($service['service_name']);
                            echo '</small>';
                        }
                        if($services_count > 5) echo '<small class="text-muted">...e altri ' . ($services_count - 5) . '</small>';
                        ?>
                    </div>
                    
                    <?php if (!empty($package_roles)): ?>
                    <div class="mb-3">
                        <small class="text-uppercase text-muted fw-bold">Staff</small><br>
                        <?php foreach ($package_roles as $role): ?>
                            <span class="badge bg-light text-dark border me-1"><?php echo $role['quantity']; ?>x <?php echo e($role['name']); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer d-flex justify-content-between">
                    <div>
                        <a href="?edit=<?php echo $package['id']; ?>" class="btn btn-sm btn-primary">
                            <i class="bi bi-pencil"></i> Modifica
                        </a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questo pacchetto?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="package_id" value="<?php echo $package['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger">
                                <i class="bi bi-trash"></i> Elimina
                            </button>
                        </form>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="toggle_active">
                        <input type="hidden" name="package_id" value="<?php echo $package['id']; ?>">
                        <input type="hidden" name="is_active" value="<?php echo $package['is_active'] ? 0 : 1; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                            <?php echo $package['is_active'] ? 'Disattiva' : 'Attiva'; ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal Pacchetto -->
<div class="modal fade" id="packageModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" id="packageForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="<?php echo $edit_package ? 'update' : 'create'; ?>" id="formAction">
                <input type="hidden" name="package_id" value="<?php echo $edit_package['id'] ?? ''; ?>" id="packageId">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">
                        <?php echo $edit_package ? 'Modifica Pacchetto' : 'Nuovo Pacchetto'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row">
                        <!-- COLONNA SINISTRA: Info base + Logo -->
                        <div class="col-md-4 border-end">
                            <div class="mb-3 text-center">
                                <label class="form-label d-block fw-bold">Logo Pacchetto</label>
                                <div class="border rounded p-2 mb-2 bg-light d-flex align-items-center justify-content-center" style="height: 150px; overflow: hidden;">
                                    <img id="imagePreview" src="<?php echo (!empty($edit_package['image_path'])) ? '../' . $edit_package['image_path'] : ''; ?>" 
                                         style="max-width: 100%; max-height: 100%; display: <?php echo (!empty($edit_package['image_path'])) ? 'block' : 'none'; ?>;" 
                                         alt="Anteprima">
                                    <span id="imagePlaceholder" style="display: <?php echo (!empty($edit_package['image_path'])) ? 'none' : 'block'; ?>; color: #ccc;">
                                        <i class="bi bi-image fs-1"></i>
                                    </span>
                                </div>
                                <input type="file" name="image" class="form-control form-control-sm" accept="image/*" onchange="previewImage(this)">
                                <small class="text-muted">Formati: PNG, JPG, WEBP. Max 2MB.</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Nome Pacchetto *</label>
                                <input type="text" name="name" class="form-control" required 
                                       value="<?php echo e($edit_package['name'] ?? ''); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Prezzo *</label>
                                <input type="number" step="0.01" name="price" class="form-control" required 
                                       value="<?php echo $edit_package['price'] ?? ''; ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Prezzo Minimo *</label>
                                <input type="number" step="0.01" name="min_price" class="form-control" required 
                                       value="<?php echo $edit_package['min_price'] ?? ''; ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Provvigione Commerciale</label>
                                <input type="number" step="0.01" name="commission" class="form-control" 
                                       value="<?php echo $edit_package['commission'] ?? '0'; ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Descrizione</label>
                                <textarea name="description" class="form-control" rows="3"><?php echo e($edit_package['description'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <!-- COLONNA DESTRA: Servizi, Ruoli, Utenti -->
                        <div class="col-md-8">
                            <!-- VISIBILITÀ UTENTI -->
                            <div class="mb-4">
                                <label class="form-label h6 border-bottom pb-2 w-100">Visibilità Pacchetto</label>
                                <p class="small text-muted mb-2">Seleziona gli utenti che possono vedere e utilizzare questo pacchetto. <strong class="text-dark">Se non selezioni nessuno, il pacchetto sarà visibile a tutti.</strong></p>
                                
                                <div class="users-list-scroll">
                                    <?php foreach ($users_list as $user): 
                                        $isChecked = false;
                                        if ($edit_package && isset($edit_package['authorized_users'])) {
                                            $isChecked = in_array($user['id'], $edit_package['authorized_users']);
                                        }
                                    ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="authorized_users[]" 
                                               value="<?php echo $user['id']; ?>" id="user_<?php echo $user['id']; ?>"
                                               <?php echo $isChecked ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="user_<?php echo $user['id']; ?>">
                                            <?php echo e($user['username']); ?> 
                                            <span class="text-muted small">(<?php echo e($user['first_name'] . ' ' . $user['last_name']); ?>)</span>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Servizi -->
                            <div class="mb-4">
                                <label class="form-label h6 border-bottom pb-2 w-100">Servizi Inclusi</label>
                                <div id="servicesContainer" style="max-height: 250px; overflow-y: auto; padding-right: 5px;">
                                    <?php if ($edit_package && !empty($edit_package['services'])): ?>
                                        <?php foreach ($edit_package['services'] as $idx => $service): ?>
                                        <div class="input-group mb-2 service-row">
                                            <span class="input-group-text bg-light border-end-0"><i class="bi bi-grip-vertical text-muted"></i></span>
                                            <input type="text" name="services[]" class="form-control border-start-0 service-input" 
                                                   placeholder="Nome servizio" value="<?php echo e($service['service_name']); ?>" oninput="checkGraphicsService()">
                                            <div class="input-group-text bg-white">
                                                <input class="form-check-input mt-0" type="checkbox" name="mandatory[<?php echo $idx; ?>]" 
                                                       <?php echo $service['is_mandatory'] ? 'checked' : ''; ?>>
                                                <label class="ms-2 small mb-0">Tassativo</label>
                                            </div>
                                            <button type="button" class="btn btn-outline-danger" onclick="removeService(this)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                    <div class="input-group mb-2 service-row">
                                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-grip-vertical text-muted"></i></span>
                                        <input type="text" name="services[]" class="form-control border-start-0 service-input" placeholder="Nome servizio" oninput="checkGraphicsService()">
                                        <div class="input-group-text bg-white">
                                            <input class="form-check-input mt-0" type="checkbox" name="mandatory[0]">
                                            <label class="ms-2 small mb-0">Tassativo</label>
                                        </div>
                                        <button type="button" class="btn btn-outline-danger" onclick="removeService(this)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <button type="button" class="btn btn-sm btn-success mt-2" onclick="addService()">
                                    <i class="bi bi-plus-circle"></i> Aggiungi Servizio
                                </button>
                            </div>
                            
                            <!-- Ruoli Staff -->
                            <div class="mb-4">
                                <label class="form-label h6 border-bottom pb-2 w-100">Ruoli Staff Richiesti</label>
                                <div class="row g-2">
                                    <?php foreach ($roles as $role): ?>
                                    <div class="col-md-6 col-lg-4">
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text col-8 text-truncate" title="<?php echo e($role['name']); ?>">
                                                <?php echo e($role['name']); ?>
                                            </span>
                                            <input type="number" min="0" name="roles[<?php echo $role['id']; ?>]" 
                                                   class="form-control text-center fw-bold" 
                                                   value="<?php echo $edit_package['roles'][$role['id']] ?? '0'; ?>">
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- NUOVO CAMPO: GRAFICHE EVENTO -->
                            <div class="mb-3 bg-light p-3 rounded border">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="hasGraphicsCheck" name="has_graphics" value="1" 
                                           <?php echo (isset($edit_package['has_graphics']) && $edit_package['has_graphics']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold" for="hasGraphicsCheck">Grafiche Evento incluse</label>
                                </div>
                                <small class="text-muted d-block mt-1">Se abilitato, questo pacchetto verrà segnalato come "Grafiche Incluse" nella lista.</small>
                            </div>

                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">Salva Pacchetto</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let serviceIndex = <?php echo $edit_package ? count($edit_package['services']) : 1; ?>;

function addService() {
    const container = document.getElementById('servicesContainer');
    const row = document.createElement('div');
    row.className = 'input-group mb-2 service-row';
    row.innerHTML = `
        <span class="input-group-text bg-light border-end-0"><i class="bi bi-grip-vertical text-muted"></i></span>
        <input type="text" name="services[]" class="form-control border-start-0 service-input" placeholder="Nome servizio" oninput="checkGraphicsService()">
        <div class="input-group-text bg-white">
            <input class="form-check-input mt-0" type="checkbox" name="mandatory[${serviceIndex}]">
            <label class="ms-2 small mb-0">Tassativo</label>
        </div>
        <button type="button" class="btn btn-outline-danger" onclick="removeService(this)">
            <i class="bi bi-trash"></i>
        </button>
    `;
    container.appendChild(row);
    serviceIndex++;
}

function removeService(button) {
    button.closest('.service-row').remove();
    checkGraphicsService(); // Ricontrolla quando rimuovi
}

// Funzione per controllare automaticamente "Grafiche Evento"
function checkGraphicsService() {
    const inputs = document.querySelectorAll('.service-input');
    const graphicsCheckbox = document.getElementById('hasGraphicsCheck');
    let found = false;

    inputs.forEach(input => {
        // Cerca la stringa specifica (case-insensitive)
        if (input.value.toLowerCase().trim() === 'creatività grafiche evento') {
            found = true;
        }
    });

    if (found) {
        graphicsCheckbox.checked = true;
    }
}

// Funzione anteprima immagine
function previewImage(input) {
    const preview = document.getElementById('imagePreview');
    const placeholder = document.getElementById('imagePlaceholder');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
            placeholder.style.display = 'none';
        }
        
        reader.readAsDataURL(input.files[0]);
    } else {
        preview.src = '';
        preview.style.display = 'none';
        placeholder.style.display = 'block';
    }
}

function resetForm() {
    document.getElementById('packageForm').reset();
    document.getElementById('formAction').value = 'create';
    document.getElementById('packageId').value = '';
    document.getElementById('modalTitle').textContent = 'Nuovo Pacchetto';
    
    // Reset immagine
    document.getElementById('imagePreview').src = '';
    document.getElementById('imagePreview').style.display = 'none';
    document.getElementById('imagePlaceholder').style.display = 'block';
    
    // Reset checkbox
    document.getElementById('hasGraphicsCheck').checked = false;
}

<?php if ($edit_package): ?>
// Apri modal in edit mode
window.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('packageModal')).show();
});
<?php endif; ?>
</script>

<?php include '../includes/footer.php'; ?>