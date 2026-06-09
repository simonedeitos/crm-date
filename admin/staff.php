<?php
require_once '../config.php';
require_once '../auth.php';
require_once '../functions.php';

requireAdmin();

$db = getDBConnection();
$error = '';
$success = '';

// Gestione azioni RUOLI
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['role_action'])) {
    $action = $_POST['role_action'];
    
    switch ($action) {
        case 'create_role':
            $name = mysqli_real_escape_string($db, $_POST['role_name']);
            $description = mysqli_real_escape_string($db, $_POST['role_description'] ?? '');
            
            $query = "INSERT INTO staff_roles (name, description, created_at) 
                      VALUES ('$name', '$description', NOW())";
            
            if (mysqli_query($db, $query)) {
                header('Location: staff.php?success=role_created');
                exit;
            } else {
                $error = "Errore: " . mysqli_error($db);
            }
            break;
            
        case 'update_role':
            $role_id = (int)$_POST['role_id'];
            $name = mysqli_real_escape_string($db, $_POST['role_name']);
            $description = mysqli_real_escape_string($db, $_POST['role_description'] ?? '');
            
            $query = "UPDATE staff_roles SET name = '$name', description = '$description' WHERE id = $role_id";
            
            if (mysqli_query($db, $query)) {
                header('Location: staff.php?success=role_updated&tab=roles');
                exit;
            } else {
                $error = "Errore: " . mysqli_error($db);
            }
            break;
            
        case 'delete_role':
            $role_id = (int)$_POST['role_id'];
            
            // Verifica se il ruolo è utilizzato
            $check = mysqli_query($db, "SELECT COUNT(*) as count FROM staff_has_roles WHERE role_id = $role_id");
            $row = mysqli_fetch_assoc($check);
            
            if ($row['count'] > 0) {
                header('Location: staff.php?error=role_in_use&count=' . $row['count'] . '&tab=roles');
                exit;
            } else {
                mysqli_query($db, "DELETE FROM staff_roles WHERE id = $role_id");
                header('Location: staff.php?success=role_deleted&tab=roles');
                exit;
            }
            break;
    }
}

// Gestione azioni STAFF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['staff_action'])) {
    $action = $_POST['staff_action'];
    
    switch ($action) {
        case 'create_staff':
            $first_name = mysqli_real_escape_string($db, $_POST['first_name']);
            $last_name = mysqli_real_escape_string($db, $_POST['last_name']);
            $phone = mysqli_real_escape_string($db, $_POST['phone'] ?? '');
            $email = mysqli_real_escape_string($db, $_POST['email'] ?? '');
            $default_cost = (float)$_POST['default_cost'];
            $default_extra = (float)$_POST['default_extra'];
            
            $query = "INSERT INTO staff (first_name, last_name, phone, email, default_cost, default_extra, is_active, created_at)
                      VALUES ('$first_name', '$last_name', '$phone', '$email', $default_cost, $default_extra, 1, NOW())";
            
            if (mysqli_query($db, $query)) {
                $staff_id = mysqli_insert_id($db);
                
                // Assegna ruoli
                if (isset($_POST['roles']) && is_array($_POST['roles'])) {
                    foreach ($_POST['roles'] as $role_id) {
                        mysqli_query($db, "INSERT INTO staff_has_roles (staff_id, role_id) VALUES ($staff_id, $role_id)");
                    }
                }
                
                header('Location: staff.php?success=staff_created');
                exit;
            } else {
                $error = "Errore: " . mysqli_error($db);
            }
            break;
            
        case 'update_staff':
            $staff_id = (int)$_POST['staff_id'];
            $first_name = mysqli_real_escape_string($db, $_POST['first_name']);
            $last_name = mysqli_real_escape_string($db, $_POST['last_name']);
            $phone = mysqli_real_escape_string($db, $_POST['phone'] ?? '');
            $email = mysqli_real_escape_string($db, $_POST['email'] ?? '');
            $default_cost = (float)$_POST['default_cost'];
            $default_extra = (float)$_POST['default_extra'];
            
            $query = "UPDATE staff SET first_name = '$first_name', last_name = '$last_name', 
                      phone = '$phone', email = '$email', default_cost = $default_cost, default_extra = $default_extra
                      WHERE id = $staff_id";
            
            if (mysqli_query($db, $query)) {
                // Aggiorna ruoli
                mysqli_query($db, "DELETE FROM staff_has_roles WHERE staff_id = $staff_id");
                if (isset($_POST['roles']) && is_array($_POST['roles'])) {
                    foreach ($_POST['roles'] as $role_id) {
                        mysqli_query($db, "INSERT INTO staff_has_roles (staff_id, role_id) VALUES ($staff_id, $role_id)");
                    }
                }
                
                header('Location: staff.php?success=staff_updated');
                exit;
            } else {
                $error = "Errore: " . mysqli_error($db);
            }
            break;
            
        case 'toggle_active':
            $staff_id = (int)$_POST['staff_id'];
            $is_active = (int)$_POST['is_active'];
            mysqli_query($db, "UPDATE staff SET is_active = $is_active WHERE id = $staff_id");
            header('Location: staff.php?success=status_updated');
            exit;
            break;
            
        case 'delete_staff':
            $staff_id = (int)$_POST['staff_id'];
            
            // Verifica se lo staff è assegnato a eventi
            $check = mysqli_query($db, "SELECT COUNT(*) as count FROM quote_staff_assignment WHERE staff_id = $staff_id");
            $row = mysqli_fetch_assoc($check);
            
            if ($row['count'] > 0) {
                header('Location: staff.php?error=staff_assigned&count=' . $row['count']);
                exit;
            } else {
                mysqli_query($db, "DELETE FROM staff WHERE id = $staff_id");
                header('Location: staff.php?success=staff_deleted');
                exit;
            }
            break;
    }
}

// Gestione messaggi da query string
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'staff_created':
            $success = "Membro dello staff creato con successo!";
            break;
        case 'staff_updated':
            $success = "Staff aggiornato con successo!";
            break;
        case 'staff_deleted':
            $success = "Staff eliminato con successo!";
            break;
        case 'status_updated':
            $success = "Stato aggiornato con successo!";
            break;
        case 'role_created':
            $success = "Ruolo creato con successo!";
            break;
        case 'role_updated':
            $success = "Ruolo aggiornato con successo!";
            break;
        case 'role_deleted':
            $success = "Ruolo eliminato con successo!";
            break;
    }
}

if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'staff_assigned':
            $count = $_GET['count'] ?? 0;
            $error = "Impossibile eliminare: questa persona è assegnata a $count eventi";
            break;
        case 'role_in_use':
            $count = $_GET['count'] ?? 0;
            $error = "Impossibile eliminare: ci sono $count persone con questo ruolo assegnato";
            break;
    }
}

// Ottieni tutti i ruoli
$roles_result = mysqli_query($db, "SELECT r.*, 
                                   (SELECT COUNT(*) FROM staff_has_roles WHERE role_id = r.id) as staff_count
                                   FROM staff_roles r 
                                   ORDER BY r.name");
$roles = [];
while ($row = mysqli_fetch_assoc($roles_result)) {
    $roles[] = $row;
}

// Ottieni tutto lo staff con ruoli
$staff_result = mysqli_query($db, "SELECT s.*, 
                                   GROUP_CONCAT(sr.name SEPARATOR ', ') as roles_names,
                                   GROUP_CONCAT(sr.id) as roles_ids
                                   FROM staff s
                                   LEFT JOIN staff_has_roles shr ON s.id = shr.staff_id
                                   LEFT JOIN staff_roles sr ON shr.role_id = sr.id
                                   GROUP BY s.id
                                   ORDER BY s.last_name, s.first_name");
$staff_members = [];
while ($row = mysqli_fetch_assoc($staff_result)) {
    $staff_members[] = $row;
}

// Filtro per modifica
$edit_staff = null;
if (isset($_GET['edit_staff'])) {
    $edit_id = (int)$_GET['edit_staff'];
    $result = mysqli_query($db, "SELECT * FROM staff WHERE id = $edit_id");
    $edit_staff = mysqli_fetch_assoc($result);
    
    // Ottieni ruoli assegnati
    $roles_result = mysqli_query($db, "SELECT role_id FROM staff_has_roles WHERE staff_id = $edit_id");
    $edit_staff['assigned_roles'] = [];
    while ($row = mysqli_fetch_assoc($roles_result)) {
        $edit_staff['assigned_roles'][] = $row['role_id'];
    }
}

$edit_role = null;
if (isset($_GET['edit_role'])) {
    $edit_id = (int)$_GET['edit_role'];
    $result = mysqli_query($db, "SELECT * FROM staff_roles WHERE id = $edit_id");
    $edit_role = mysqli_fetch_assoc($result);
}

// Determina quale tab attivare
$active_tab = 'staffTab';
if (isset($_GET['tab']) && $_GET['tab'] === 'roles') {
    $active_tab = 'rolesTab';
}

$pageTitle = 'Gestione Staff e Ruoli';
include '../includes/header.php';
?>

<div class="container-fluid py-4">
    <h2 class="mb-4"><i class="bi bi-person-badge"></i> Gestione Staff e Ruoli</h2>
    
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
    
    <!-- Tab Navigation -->
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'staffTab' ? 'active' : ''; ?>" data-bs-toggle="tab" href="#staffTab">
                <i class="bi bi-people"></i> Staff (<?php echo count($staff_members); ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'rolesTab' ? 'active' : ''; ?>" data-bs-toggle="tab" href="#rolesTab">
                <i class="bi bi-tags"></i> Ruoli (<?php echo count($roles); ?>)
            </a>
        </li>
    </ul>
    
    <div class="tab-content">
        <!-- TAB STAFF -->
        <div class="tab-pane fade <?php echo $active_tab === 'staffTab' ? 'show active' : ''; ?>" id="staffTab">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4>Lista Staff</h4>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#staffModal" onclick="resetStaffForm()">
                    <i class="bi bi-plus-circle"></i> Aggiungi Persona
                </button>
            </div>
            
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Contatti</th>
                                    <th>Ruoli</th>
                                    <th>Costo Default</th>
                                    <th>Extra Default</th>
                                    <th>Stato</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($staff_members)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">Nessun membro dello staff</td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($staff_members as $member): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo e($member['first_name'] . ' ' . $member['last_name']); ?></strong>
                                        </td>
                                        <td>
                                            <?php if ($member['email']): ?>
                                            <i class="bi bi-envelope"></i> <?php echo e($member['email']); ?><br>
                                            <?php endif; ?>
                                            <?php if ($member['phone']): ?>
                                            <i class="bi bi-telephone"></i> <?php echo e($member['phone']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($member['roles_names']): ?>
                                                <?php foreach (explode(', ', $member['roles_names']) as $role): ?>
                                                    <span class="badge bg-info"><?php echo e($role); ?></span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Nessun ruolo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo formatPrice($member['default_cost']); ?></td>
                                        <td><?php echo formatPrice($member['default_extra']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $member['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo $member['is_active'] ? 'Attivo' : 'Disattivo'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="?edit_staff=<?php echo $member['id']; ?>" class="btn btn-sm btn-primary">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="staff_action" value="toggle_active">
                                                <input type="hidden" name="staff_id" value="<?php echo $member['id']; ?>">
                                                <input type="hidden" name="is_active" value="<?php echo $member['is_active'] ? 0 : 1; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                    <i class="bi bi-<?php echo $member['is_active'] ? 'pause' : 'play'; ?>"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questo membro?');">
                                                <input type="hidden" name="staff_action" value="delete_staff">
                                                <input type="hidden" name="staff_id" value="<?php echo $member['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
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
        
        <!-- TAB RUOLI -->
        <div class="tab-pane fade <?php echo $active_tab === 'rolesTab' ? 'show active' : ''; ?>" id="rolesTab">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4>Gestione Ruoli</h4>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#roleModal" onclick="resetRoleForm()">
                    <i class="bi bi-plus-circle"></i> Nuovo Ruolo
                </button>
            </div>
            
            <div class="row">
                <?php foreach ($roles as $role): ?>
                <div class="col-md-4 mb-3">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title"><?php echo e($role['name']); ?></h5>
                            <?php if ($role['description']): ?>
                            <p class="card-text text-muted"><?php echo e($role['description']); ?></p>
                            <?php endif; ?>
                            <p class="mb-0">
                                <span class="badge bg-info"><?php echo $role['staff_count']; ?> persone</span>
                            </p>
                        </div>
                        <div class="card-footer d-flex justify-content-between">
                            <a href="?edit_role=<?php echo $role['id']; ?>&tab=roles" class="btn btn-sm btn-primary">
                                <i class="bi bi-pencil"></i> Modifica
                            </a>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questo ruolo?');">
                                <input type="hidden" name="role_action" value="delete_role">
                                <input type="hidden" name="role_id" value="<?php echo $role['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger">
                                    <i class="bi bi-trash"></i> Elimina
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal Staff -->
<div class="modal fade" id="staffModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="staffForm">
                <input type="hidden" name="staff_action" value="<?php echo $edit_staff ? 'update_staff' : 'create_staff'; ?>" id="staffFormAction">
                <input type="hidden" name="staff_id" value="<?php echo $edit_staff['id'] ?? ''; ?>" id="staffId">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="staffModalTitle">
                        <?php echo $edit_staff ? 'Modifica Staff' : 'Nuova Persona'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nome *</label>
                            <input type="text" name="first_name" class="form-control" required 
                                   value="<?php echo e($edit_staff['first_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Cognome</label>
                            <input type="text" name="last_name" class="form-control" 
                                   value="<?php echo e($edit_staff['last_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" 
                                   value="<?php echo e($edit_staff['email'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Telefono</label>
                            <input type="text" name="phone" class="form-control" 
                                   value="<?php echo e($edit_staff['phone'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Costo Default (in fattura) *</label>
                            <input type="number" step="0.01" name="default_cost" class="form-control" required 
                                   value="<?php echo $edit_staff['default_cost'] ?? '0'; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Extra Default (in nero) *</label>
                            <input type="number" step="0.01" name="default_extra" class="form-control" required 
                                   value="<?php echo $edit_staff['default_extra'] ?? '0'; ?>">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label"><strong>Ruoli Assegnati</strong></label>
                            <div class="row">
                                <?php foreach ($roles as $role): ?>
                                <div class="col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="roles[]" 
                                               value="<?php echo $role['id']; ?>" 
                                               id="role<?php echo $role['id']; ?>"
                                               <?php echo ($edit_staff && in_array($role['id'], $edit_staff['assigned_roles'])) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="role<?php echo $role['id']; ?>">
                                            <?php echo e($role['name']); ?>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">Salva</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Ruolo -->
<div class="modal fade" id="roleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="roleForm">
                <input type="hidden" name="role_action" value="<?php echo $edit_role ? 'update_role' : 'create_role'; ?>" id="roleFormAction">
                <input type="hidden" name="role_id" value="<?php echo $edit_role['id'] ?? ''; ?>" id="roleId">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="roleModalTitle">
                        <?php echo $edit_role ? 'Modifica Ruolo' : 'Nuovo Ruolo'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome Ruolo *</label>
                        <input type="text" name="role_name" class="form-control" required 
                               value="<?php echo e($edit_role['name'] ?? ''); ?>" 
                               placeholder="es: DJ, Vocalist, Ballerina">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrizione</label>
                        <textarea name="role_description" class="form-control" rows="3"><?php echo e($edit_role['description'] ?? ''); ?></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">Salva Ruolo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function resetStaffForm() {
    document.getElementById('staffForm').reset();
    document.getElementById('staffFormAction').value = 'create_staff';
    document.getElementById('staffId').value = '';
    document.getElementById('staffModalTitle').textContent = 'Nuova Persona';
}

function resetRoleForm() {
    document.getElementById('roleForm').reset();
    document.getElementById('roleFormAction').value = 'create_role';
    document.getElementById('roleId').value = '';
    document.getElementById('roleModalTitle').textContent = 'Nuovo Ruolo';
}

<?php if ($edit_staff): ?>
window.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('staffModal')).show();
});
<?php endif; ?>

<?php if ($edit_role): ?>
window.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('roleModal')).show();
});
<?php endif; ?>
</script>

<?php include '../includes/footer.php'; ?>