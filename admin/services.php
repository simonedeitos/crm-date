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
            $name = mysqli_real_escape_string($db, $_POST['name']);
            $description = mysqli_real_escape_string($db, $_POST['description'] ?? '');
            $default_cost = (float)$_POST['default_cost'];
            $default_extra = (float)$_POST['default_extra'];
            
            $query = "INSERT INTO services (name, description, default_cost, default_extra, is_active, created_at)
                      VALUES ('$name', '$description', $default_cost, $default_extra, 1, NOW())";
            
            if (mysqli_query($db, $query)) {
                $success = "Servizio '$name' creato con successo!";
            } else {
                $error = "Errore: " . mysqli_error($db);
            }
            break;
            
        case 'update':
            $service_id = (int)$_POST['service_id'];
            $name = mysqli_real_escape_string($db, $_POST['name']);
            $description = mysqli_real_escape_string($db, $_POST['description'] ?? '');
            $default_cost = (float)$_POST['default_cost'];
            $default_extra = (float)$_POST['default_extra'];
            
            $query = "UPDATE services SET name = '$name', description = '$description', 
                      default_cost = $default_cost, default_extra = $default_extra
                      WHERE id = $service_id";
            
            if (mysqli_query($db, $query)) {
                $success = "Servizio aggiornato con successo!";
            } else {
                $error = "Errore: " . mysqli_error($db);
            }
            break;
            
        case 'toggle_active':
            $service_id = (int)$_POST['service_id'];
            $is_active = (int)$_POST['is_active'];
            mysqli_query($db, "UPDATE services SET is_active = $is_active WHERE id = $service_id");
            $success = "Stato servizio aggiornato";
            break;
            
        case 'delete':
            $service_id = (int)$_POST['service_id'];
            
            // Verifica se il servizio è utilizzato nei pacchetti
            $check = mysqli_query($db, "SELECT COUNT(*) as count FROM package_services 
                                        WHERE service_name IN (SELECT name FROM services WHERE id = $service_id)");
            $row = mysqli_fetch_assoc($check);
            
            if ($row['count'] > 0) {
                $error = "Impossibile eliminare: questo servizio è utilizzato in {$row['count']} pacchetti";
            } else {
                mysqli_query($db, "DELETE FROM services WHERE id = $service_id");
                $success = "Servizio eliminato con successo";
            }
            break;
    }
}

// Ottieni tutti i servizi
$services_result = mysqli_query($db, "SELECT * FROM services ORDER BY name");
$services = [];
while ($row = mysqli_fetch_assoc($services_result)) {
    $services[] = $row;
}

// Filtro per modifica
$edit_service = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $result = mysqli_query($db, "SELECT * FROM services WHERE id = $edit_id");
    $edit_service = mysqli_fetch_assoc($result);
}

$pageTitle = 'Gestione Servizi';
include '../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-list-check"></i> Gestione Servizi</h2>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#serviceModal" onclick="resetForm()">
            <i class="bi bi-plus-circle"></i> Nuovo Servizio
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
    
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Nome Servizio</th>
                            <th>Descrizione</th>
                            <th>Costo Default</th>
                            <th>Extra Default</th>
                            <th>Totale Default</th>
                            <th>Stato</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($services)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">Nessun servizio disponibile</td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($services as $service): ?>
                            <tr>
                                <td>
                                    <strong><?php echo e($service['name']); ?></strong>
                                </td>
                                <td>
                                    <?php if ($service['description']): ?>
                                        <small class="text-muted"><?php echo e($service['description']); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo formatPrice($service['default_cost']); ?></td>
                                <td><?php echo formatPrice($service['default_extra']); ?></td>
                                <td>
                                    <strong><?php echo formatPrice($service['default_cost'] + $service['default_extra']); ?></strong>
                                </td>
                                <td>
                                    <span class="badge <?php echo $service['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo $service['is_active'] ? 'Attivo' : 'Disattivo'; ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="?edit=<?php echo $service['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="service_id" value="<?php echo $service['id']; ?>">
                                        <input type="hidden" name="is_active" value="<?php echo $service['is_active'] ? 0 : 1; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" 
                                                title="<?php echo $service['is_active'] ? 'Disattiva' : 'Attiva'; ?>">
                                            <i class="bi bi-<?php echo $service['is_active'] ? 'pause' : 'play'; ?>"></i>
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questo servizio?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="service_id" value="<?php echo $service['id']; ?>">
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
    
    <!-- Info Card -->
    <div class="row mt-4">
        <div class="col-md-4">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h6>Totale Servizi</h6>
                    <h2><?php echo count($services); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h6>Servizi Attivi</h6>
                    <h2><?php echo count(array_filter($services, function($s) { return $s['is_active']; })); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h6>Costo Medio Servizi</h6>
                    <h2>
                        <?php 
                        $avg = count($services) > 0 ? array_sum(array_column($services, 'default_cost')) / count($services) : 0;
                        echo formatPrice($avg); 
                        ?>
                    </h2>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Servizio -->
<div class="modal fade" id="serviceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="serviceForm">
                <input type="hidden" name="action" value="<?php echo $edit_service ? 'update' : 'create'; ?>" id="formAction">
                <input type="hidden" name="service_id" value="<?php echo $edit_service['id'] ?? ''; ?>" id="serviceId">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">
                        <?php echo $edit_service ? 'Modifica Servizio' : 'Nuovo Servizio'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Nome Servizio *</label>
                            <input type="text" name="name" class="form-control" required 
                                   value="<?php echo e($edit_service['name'] ?? ''); ?>"
                                   placeholder="es: Luci, Audio, Foto, Video, Animazione">
                        </div>
                        
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Descrizione</label>
                            <textarea name="description" class="form-control" rows="3" 
                                      placeholder="Descrizione dettagliata del servizio"><?php echo e($edit_service['description'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Costo Default (in fattura) *</label>
                            <div class="input-group">
                                <span class="input-group-text">€</span>
                                <input type="number" step="0.01" name="default_cost" class="form-control" required 
                                       value="<?php echo $edit_service['default_cost'] ?? '0'; ?>"
                                       placeholder="0.00">
                            </div>
                            <small class="text-muted">Importo che andrà in fattura</small>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Extra Default (in nero) *</label>
                            <div class="input-group">
                                <span class="input-group-text">€</span>
                                <input type="number" step="0.01" name="default_extra" class="form-control" required 
                                       value="<?php echo $edit_service['default_extra'] ?? '0'; ?>"
                                       placeholder="0.00">
                            </div>
                            <small class="text-muted">Importo extra non fatturato</small>
                        </div>
                        
                        <div class="col-md-12">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> 
                                <strong>Nota:</strong> Questi sono i valori di default. Potranno essere modificati singolarmente per ogni preventivo.
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Salva Servizio
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function resetForm() {
    document.getElementById('serviceForm').reset();
    document.getElementById('formAction').value = 'create';
    document.getElementById('serviceId').value = '';
    document.getElementById('modalTitle').textContent = 'Nuovo Servizio';
}

<?php if ($edit_service): ?>
window.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('serviceModal')).show();
});
<?php endif; ?>
</script>

<?php include '../includes/footer.php'; ?>