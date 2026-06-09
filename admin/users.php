<?php
require_once '../config.php';
require_once '../auth.php';
require_once '../functions.php';

requireAdmin();

$db = getDBConnection();
$error = '';
$success = '';

// Gestione azioni
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
            $username = mysqli_real_escape_string($db, $_POST['username']);
            $password = $_POST['password'];
            $role = mysqli_real_escape_string($db, $_POST['role']);
            $first_name = mysqli_real_escape_string($db, $_POST['first_name'] ?? '');
            $last_name = mysqli_real_escape_string($db, $_POST['last_name'] ?? '');
            $email = mysqli_real_escape_string($db, $_POST['email'] ?? '');
            $phone = mysqli_real_escape_string($db, $_POST['phone'] ?? '');
            
            // Verifica username univoco
            $check = mysqli_query($db, "SELECT COUNT(*) as count FROM users WHERE username = '$username'");
            $row = mysqli_fetch_assoc($check);
            
            if ($row['count'] > 0) {
                $error = "Username '$username' già esistente";
            } elseif (strlen($password) < 8) {
                $error = "La password deve essere di almeno 8 caratteri";
            } else {
                $password_hash = password_hash($password, PASSWORD_BCRYPT);
                
                $query = "INSERT INTO users (username, password, role, first_name, last_name, email, phone, is_active, created_at)
                          VALUES ('$username', '$password_hash', '$role', '$first_name', '$last_name', '$email', '$phone', 1, NOW())";
                
                if (mysqli_query($db, $query)) {
                    $success = "Utente '$username' creato con successo!";
                } else {
                    $error = "Errore: " . mysqli_error($db);
                }
            }
            break;
            
        case 'update':
            $user_id = (int)$_POST['user_id'];
            $username = mysqli_real_escape_string($db, $_POST['username']);
            $role = mysqli_real_escape_string($db, $_POST['role']);
            $first_name = mysqli_real_escape_string($db, $_POST['first_name'] ?? '');
            $last_name = mysqli_real_escape_string($db, $_POST['last_name'] ?? '');
            $email = mysqli_real_escape_string($db, $_POST['email'] ?? '');
            $phone = mysqli_real_escape_string($db, $_POST['phone'] ?? '');
            
            // Verifica username univoco (escluso utente corrente)
            $check = mysqli_query($db, "SELECT COUNT(*) as count FROM users WHERE username = '$username' AND id != $user_id");
            $row = mysqli_fetch_assoc($check);
            
            if ($row['count'] > 0) {
                $error = "Username '$username' già esistente";
            } else {
                $query = "UPDATE users SET username = '$username', role = '$role', 
                          first_name = '$first_name', last_name = '$last_name', email = '$email', phone = '$phone'
                          WHERE id = $user_id";
                
                if (mysqli_query($db, $query)) {
                    $success = "Utente aggiornato con successo!";
                } else {
                    $error = "Errore: " . mysqli_error($db);
                }
            }
            break;
            
        case 'change_password':
            $user_id = (int)$_POST['user_id'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];
            
            if ($new_password !== $confirm_password) {
                $error = "Le password non corrispondono";
            } elseif (strlen($new_password) < 8) {
                $error = "La password deve essere di almeno 8 caratteri";
            } else {
                $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
                mysqli_query($db, "UPDATE users SET password = '$password_hash' WHERE id = $user_id");
                $success = "Password aggiornata con successo!";
            }
            break;
            
        case 'toggle_active':
            $user_id = (int)$_POST['user_id'];
            $is_active = (int)$_POST['is_active'];
            
            // Non permettere di disattivare se stesso
            if ($user_id == $_SESSION['user_id']) {
                $error = "Non puoi disattivare il tuo stesso account!";
            } else {
                mysqli_query($db, "UPDATE users SET is_active = $is_active WHERE id = $user_id");
                $success = "Stato utente aggiornato";
            }
            break;
            
        case 'delete':
            $user_id = (int)$_POST['user_id'];
            
            // Non permettere di eliminare se stesso
            if ($user_id == $_SESSION['user_id']) {
                $error = "Non puoi eliminare il tuo stesso account!";
            } else {
                // Verifica se ha clienti/preventivi collegati
                $check_clients = mysqli_query($db, "SELECT COUNT(*) as count FROM clients WHERE created_by = $user_id");
                $clients_count = mysqli_fetch_assoc($check_clients)['count'];
                
                $check_quotes = mysqli_query($db, "SELECT COUNT(*) as count FROM quotes WHERE created_by = $user_id");
                $quotes_count = mysqli_fetch_assoc($check_quotes)['count'];
                
                if ($clients_count > 0 || $quotes_count > 0) {
                    $error = "Impossibile eliminare: l'utente ha $clients_count clienti e $quotes_count preventivi collegati. Disattivalo invece.";
                } else {
                    mysqli_query($db, "DELETE FROM users WHERE id = $user_id");
                    $success = "Utente eliminato con successo";
                }
            }
            break;
    }
}

// Ottieni tutti gli utenti con statistiche
$users_result = mysqli_query($db, "SELECT u.*, 
                                   (SELECT COUNT(*) FROM clients WHERE created_by = u.id) as clients_count,
                                   (SELECT COUNT(*) FROM quotes WHERE created_by = u.id) as quotes_count,
                                   (SELECT COUNT(*) FROM quotes WHERE created_by = u.id AND status = 'confermato') as confirmed_quotes
                                   FROM users u 
                                   ORDER BY u.role DESC, u.username");
$users = [];
while ($row = mysqli_fetch_assoc($users_result)) {
    $users[] = $row;
}

// Filtro per modifica
$edit_user = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $result = mysqli_query($db, "SELECT * FROM users WHERE id = $edit_id");
    $edit_user = mysqli_fetch_assoc($result);
}

$pageTitle = 'Gestione Utenti';
include '../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-person-circle"></i> Gestione Utenti</h2>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal" onclick="resetForm()">
            <i class="bi bi-plus-circle"></i> Nuovo Utente
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
    
    <!-- Statistiche -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <h6>Amministratori</h6>
                    <h2><?php echo count(array_filter($users, function($u) { return $u['role'] === 'admin'; })); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h6>Commerciali</h6>
                    <h2><?php echo count(array_filter($users, function($u) { return $u['role'] === 'commerciale'; })); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h6>Utenti Attivi</h6>
                    <h2><?php echo count(array_filter($users, function($u) { return $u['is_active']; })); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h6>Totale Utenti</h6>
                    <h2><?php echo count($users); ?></h2>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Lista Utenti -->
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Nome Completo</th>
                            <th>Contatti</th>
                            <th>Ruolo</th>
                            <th>Clienti</th>
                            <th>Preventivi</th>
                            <th>Ultimo Login</th>
                            <th>Stato</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr class="<?php echo $user['id'] == $_SESSION['user_id'] ? 'table-active' : ''; ?>">
                            <td>
                                <strong><?php echo e($user['username']); ?></strong>
                                <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                    <span class="badge bg-warning text-dark">Tu</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                $fullname = trim($user['first_name'] . ' ' . $user['last_name']);
                                echo $fullname ?: '<span class="text-muted">-</span>';
                                ?>
                            </td>
                            <td>
                                <?php if ($user['email']): ?>
                                    <i class="bi bi-envelope"></i> <a href="mailto:<?php echo e($user['email']); ?>"><?php echo e($user['email']); ?></a><br>
                                <?php endif; ?>
                                <?php if ($user['phone']): ?>
                                    <i class="bi bi-telephone"></i> <a href="tel:<?php echo e($user['phone']); ?>"><?php echo e($user['phone']); ?></a>
                                <?php endif; ?>
                                <?php if (!$user['email'] && !$user['phone']): ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo getRoleBadge($user['role']); ?></td>
                            <td>
                                <?php if ($user['clients_count'] > 0): ?>
                                    <span class="badge bg-info"><?php echo $user['clients_count']; ?></span>
                                <?php else: ?>
                                    <span class="text-muted">0</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['quotes_count'] > 0): ?>
                                    <span class="badge bg-secondary"><?php echo $user['quotes_count']; ?></span>
                                    <?php if ($user['confirmed_quotes'] > 0): ?>
                                        <span class="badge bg-success"><?php echo $user['confirmed_quotes']; ?> confermati</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">0</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['last_login']): ?>
                                    <small><?php echo formatDateTime($user['last_login']); ?></small>
                                <?php else: ?>
                                    <span class="text-muted">Mai</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $user['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                    <?php echo $user['is_active'] ? 'Attivo' : 'Disattivo'; ?>
                                </span>
                            </td>
                            <td>
                                <a href="?edit=<?php echo $user['id']; ?>" class="btn btn-sm btn-primary" title="Modifica">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <button type="button" class="btn btn-sm btn-warning" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#passwordModal" 
                                        onclick="setPasswordUserId(<?php echo $user['id']; ?>, '<?php echo e($user['username']); ?>')"
                                        title="Cambia Password">
                                    <i class="bi bi-key"></i>
                                </button>
                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <input type="hidden" name="is_active" value="<?php echo $user['is_active'] ? 0 : 1; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" 
                                            title="<?php echo $user['is_active'] ? 'Disattiva' : 'Attiva'; ?>">
                                        <i class="bi bi-<?php echo $user['is_active'] ? 'pause' : 'play'; ?>"></i>
                                    </button>
                                </form>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questo utente?\n\nATTENZIONE: Non potrai eliminare utenti con clienti/preventivi collegati.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Elimina">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal Utente -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="userForm">
                <input type="hidden" name="action" value="<?php echo $edit_user ? 'update' : 'create'; ?>" id="formAction">
                <input type="hidden" name="user_id" value="<?php echo $edit_user['id'] ?? ''; ?>" id="userId">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">
                        <?php echo $edit_user ? 'Modifica Utente' : 'Nuovo Utente'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Username *</label>
                            <input type="text" name="username" class="form-control" required 
                                   value="<?php echo e($edit_user['username'] ?? ''); ?>"
                                   pattern="[a-zA-Z0-9_@.]+" 
                                   title="Solo lettere, numeri, underscore, @ e punto">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Ruolo *</label>
                            <select name="role" class="form-select" required>
                                <option value="commerciale" <?php echo ($edit_user && $edit_user['role'] == 'commerciale') ? 'selected' : ''; ?>>
                                    Commerciale
                                </option>
                                <option value="admin" <?php echo ($edit_user && $edit_user['role'] == 'admin') ? 'selected' : ''; ?>>
                                    Admin
                                </option>
                            </select>
                        </div>
                        
                        <?php if (!$edit_user): ?>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Password *</label>
                            <input type="password" name="password" class="form-control" 
                                   <?php echo $edit_user ? '' : 'required'; ?>
                                   minlength="8"
                                   placeholder="Minimo 8 caratteri">
                            <?php if ($edit_user): ?>
                            <small class="text-muted">Lascia vuoto per non modificare</small>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nome</label>
                            <input type="text" name="first_name" class="form-control" 
                                   value="<?php echo e($edit_user['first_name'] ?? ''); ?>">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Cognome</label>
                            <input type="text" name="last_name" class="form-control" 
                                   value="<?php echo e($edit_user['last_name'] ?? ''); ?>">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" 
                                   value="<?php echo e($edit_user['email'] ?? ''); ?>"
                                   placeholder="email@esempio.it">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Telefono</label>
                            <input type="tel" name="phone" class="form-control" 
                                   value="<?php echo e($edit_user['phone'] ?? ''); ?>"
                                   placeholder="+39 123 456 7890">
                        </div>
                        
                        <?php if ($edit_user): ?>
                        <div class="col-md-12">
                            <div class="alert alert-warning">
                                <i class="bi bi-info-circle"></i> 
                                Per cambiare la password, usa il pulsante <i class="bi bi-key"></i> nella lista utenti.
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Salva Utente
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Cambio Password -->
<div class="modal fade" id="passwordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="user_id" id="passwordUserId">
                
                <div class="modal-header">
                    <h5 class="modal-title">Cambia Password - <span id="passwordUsername"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nuova Password *</label>
                        <input type="password" name="new_password" class="form-control" required minlength="8">
                        <small class="text-muted">Minimo 8 caratteri</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Conferma Password *</label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="8">
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-key"></i> Cambia Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function resetForm() {
    document.getElementById('userForm').reset();
    document.getElementById('formAction').value = 'create';
    document.getElementById('userId').value = '';
    document.getElementById('modalTitle').textContent = 'Nuovo Utente';
}

function setPasswordUserId(userId, username) {
    document.getElementById('passwordUserId').value = userId;
    document.getElementById('passwordUsername').textContent = username;
}

<?php if ($edit_user): ?>
window.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('userModal')).show();
});
<?php endif; ?>
</script>

<?php include '../includes/footer.php'; ?>