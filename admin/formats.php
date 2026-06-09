<?php
require_once '../config.php';
require_once '../auth.php';
require_once '../functions.php';

requireAdmin();

$db = getDBConnection();
$error = '';
$success = '';

// Funzione helper per le icone (definita qui per usarla nel loop)
function getFileIconClass($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'pdf': return 'bi-file-earmark-pdf text-danger';
        case 'doc': case 'docx': return 'bi-file-earmark-word text-primary';
        case 'xls': case 'xlsx': case 'csv': return 'bi-file-earmark-excel text-success';
        case 'ppt': case 'pptx': return 'bi-file-earmark-slides text-warning';
        case 'jpg': case 'jpeg': case 'png': case 'gif': return 'bi-file-earmark-image text-info';
        case 'zip': case 'rar': case '7z': return 'bi-file-earmark-zip text-secondary';
        case 'txt': return 'bi-file-earmark-text text-dark';
        default: return 'bi-file-earmark text-secondary';
    }
}

// GESTIONE POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create_format':
            $name = mysqli_real_escape_string($db, trim($_POST['name']));
            $description = mysqli_real_escape_string($db, trim($_POST['description'] ?? ''));
            
            if (empty($name)) {
                $error = 'Il nome del format è obbligatorio';
                break;
            }
            
            $query = "INSERT INTO formats (name, description, created_by) VALUES ('$name', '$description', {$_SESSION['user_id']})";
            if (mysqli_query($db, $query)) {
                $success = 'Format creato con successo';
            } else {
                $error = 'Errore durante la creazione: ' . mysqli_error($db);
            }
            break;
            
        case 'update_format':
            $format_id = (int)$_POST['format_id'];
            $name = mysqli_real_escape_string($db, trim($_POST['name']));
            $description = mysqli_real_escape_string($db, trim($_POST['description'] ?? ''));
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($name)) {
                $error = 'Il nome del format è obbligatorio';
                break;
            }
            
            $query = "UPDATE formats SET name = '$name', description = '$description', is_active = $is_active WHERE id = $format_id";
            if (mysqli_query($db, $query)) {
                $success = 'Format aggiornato con successo';
            } else {
                $error = 'Errore durante l\'aggiornamento: ' . mysqli_error($db);
            }
            break;
            
        case 'delete_format':
            $format_id = (int)$_POST['format_id'];
            
            // Prima elimina i file dal filesystem
            $files_query = "SELECT file_path FROM format_files WHERE format_id = $format_id";
            $files_result = mysqli_query($db, $files_query);
            while ($file = mysqli_fetch_assoc($files_result)) {
                if (file_exists($file['file_path'])) {
                    unlink($file['file_path']);
                }
            }
            
            // Poi elimina dal database (CASCADE eliminerà anche i file)
            $query = "DELETE FROM formats WHERE id = $format_id";
            if (mysqli_query($db, $query)) {
                $success = 'Format eliminato con successo';
            } else {
                $error = 'Errore durante l\'eliminazione: ' . mysqli_error($db);
            }
            break;
            
        case 'upload_multiple_files':
            $format_id = (int)$_POST['format_id'];
            $file_descriptions = $_POST['file_descriptions'] ?? [];
            
            if (empty($file_descriptions)) {
                $error = 'Nessuna descrizione fornita per i file';
                break;
            }
            
            if (!isset($_FILES['files']) || empty($_FILES['files']['name'])) {
                $error = 'Nessun file selezionato';
                break;
            }
            
            $files = $_FILES['files'];
            $total_files = count($files['name']);
            $uploaded_count = 0;
            $errors_count = 0;
            $upload_errors = [];
            
            // Crea directory se non esiste
            $upload_dir = '../files/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // ESTENSIONI PERMESSE (Whitelist)
            $allowed_extensions = [
                'pdf', 
                'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', // Documenti
                'jpg', 'jpeg', 'png', 'gif', 'svg', // Immagini
                'zip', 'rar', '7z' // Archivi
            ];
            $max_size = 20 * 1024 * 1024; // Aumentato a 20MB
            
            for ($i = 0; $i < $total_files; $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                    $errors_count++;
                    $upload_errors[] = "File " . ($i + 1) . ": Errore nell'upload";
                    continue;
                }
                
                $file_description = mysqli_real_escape_string($db, trim($file_descriptions[$i] ?? ''));
                if (empty($file_description)) {
                    $errors_count++;
                    $upload_errors[] = "File " . ($i + 1) . ": Descrizione mancante";
                    continue;
                }
                
                // VALIDAZIONI
                $file_ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                
                if (!in_array($file_ext, $allowed_extensions)) {
                    $errors_count++;
                    $upload_errors[] = "File " . ($i + 1) . ": Tipo di file non consentito (.$file_ext)";
                    continue;
                }
                
                if ($files['size'][$i] > $max_size) {
                    $errors_count++;
                    $upload_errors[] = "File " . ($i + 1) . ": File troppo grande (massimo 20MB)";
                    continue;
                }
                
                // Genera nome file unico
                $new_filename = 'format_' . $format_id . '_' . time() . '_' . uniqid() . '_' . $i . '.' . $file_ext;
                $file_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($files['tmp_name'][$i], $file_path)) {
                    // Salva nel database
                    $original_name = mysqli_real_escape_string($db, $files['name'][$i]);
                    $file_size = $files['size'][$i];
                    
                    // Ottieni il prossimo sort_order
                    $sort_query = "SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM format_files WHERE format_id = $format_id";
                    $sort_result = mysqli_query($db, $sort_query);
                    $next_order = mysqli_fetch_assoc($sort_result)['next_order'] + $i;
                    
                    $query = "INSERT INTO format_files (format_id, file_name, original_name, file_path, file_size, description, sort_order, created_by) 
                             VALUES ($format_id, '$new_filename', '$original_name', '$file_path', $file_size, '$file_description', $next_order, {$_SESSION['user_id']})";
                    
                    if (mysqli_query($db, $query)) {
                        $uploaded_count++;
                    } else {
                        // Rimuovi file se il salvataggio DB fallisce
                        unlink($file_path);
                        $errors_count++;
                        $upload_errors[] = "File " . ($i + 1) . ": Errore nel salvataggio database";
                    }
                } else {
                    $errors_count++;
                    $upload_errors[] = "File " . ($i + 1) . ": Errore nello spostamento del file";
                }
            }
            
            // Messaggio di risultato
            if ($uploaded_count > 0) {
                $success = "Upload completato: $uploaded_count file caricati con successo";
                if ($errors_count > 0) {
                    $success .= ", $errors_count errori";
                }
            }
            
            if (!empty($upload_errors)) {
                $error = "Errori durante l'upload:\n" . implode("\n", $upload_errors);
            }
            break;
            
        case 'delete_file':
            $file_id = (int)$_POST['file_id'];
            
            // Ottieni il path del file
            $file_query = "SELECT file_path FROM format_files WHERE id = $file_id";
            $file_result = mysqli_query($db, $file_query);
            $file_data = mysqli_fetch_assoc($file_result);
            
            if ($file_data) {
                // Elimina file dal filesystem
                if (file_exists($file_data['file_path'])) {
                    unlink($file_data['file_path']);
                }
                
                // Elimina dal database
                $query = "DELETE FROM format_files WHERE id = $file_id";
                if (mysqli_query($db, $query)) {
                    $success = 'File eliminato con successo';
                } else {
                    $error = 'Errore durante l\'eliminazione: ' . mysqli_error($db);
                }
            } else {
                $error = 'File non trovato';
            }
            break;
    }
}

// Carica tutti i format con i loro file
$formats_query = "SELECT f.*, u.username as creator_name,
                  (SELECT COUNT(*) FROM format_files WHERE format_id = f.id AND is_active = 1) as files_count
                  FROM formats f 
                  LEFT JOIN users u ON f.created_by = u.id 
                  ORDER BY f.created_at DESC";
$formats_result = mysqli_query($db, $formats_query);
$formats = [];
while ($row = mysqli_fetch_assoc($formats_result)) {
    // Carica i file per questo format
    $files_query = "SELECT * FROM format_files WHERE format_id = {$row['id']} ORDER BY sort_order, created_at";
    $files_result = mysqli_query($db, $files_query);
    $row['files'] = [];
    while ($file = mysqli_fetch_assoc($files_result)) {
        $row['files'][] = $file;
    }
    $formats[] = $row;
}

$pageTitle = 'Gestione Materiali Format';
include '../includes/header.php';
?>

<style>
.format-card {
    border: 2px solid #dee2e6;
    border-radius: 8px;
    margin-bottom: 30px;
    background: #f8f9fa;
    height: auto;
    min-height: 400px;
}
.format-header {
    background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
    color: white;
    padding: 15px 20px;
    border-radius: 6px 6px 0 0;
}
.file-item {
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 10px;
    margin-bottom: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.file-item:hover {
    background: #f1f3f4;
}
.upload-zone {
    border: 2px dashed #6c757d;
    border-radius: 8px;
    padding: 6px 15px;
    text-align: center;
    background: #fafafa;
    margin: 10px 0;
    cursor: pointer;
    transition: all 0.3s ease;
    min-height: 50px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.upload-zone:hover {
    border-color: #0d6efd;
    background: #f8f9ff;
}
.upload-zone.dragover {
    border-color: #0d6efd;
    background: #e3f2fd;
    transform: scale(1.02);
}
.upload-zone.has-files {
    border-color: #28a745;
    background: #f8fff8;
}
.upload-zone-content h6 {
    font-size: 0.9rem;
    margin-bottom: 0.3rem;
}
.upload-zone-content p {
    font-size: 0.8rem;
    margin-bottom: 0.3rem;
}
.upload-zone-content small {
    font-size: 0.75rem;
}
.upload-zone-content i {
    font-size: 1.8rem !important;
}
.selected-files-container {
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    margin-top: 15px;
    display: none;
}
.selected-file-item {
    padding: 12px 15px;
    border-bottom: 1px solid #f1f3f4;
    display: flex;
    align-items: center;
    gap: 15px;
}
.selected-file-item:last-child {
    border-bottom: none;
}
.file-preview {
    width: 40px;
    height: 40px;
    background: #e9ecef;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    flex-shrink: 0;
}
.upload-progress {
    margin-top: 15px;
    display: none;
}
.upload-summary {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 6px;
    margin-top: 15px;
    border-left: 4px solid #0d6efd;
}

/* Responsive improvements */
@media (max-width: 768px) {
    .format-card {
        margin-bottom: 20px;
        min-height: auto;
    }
    
    .col-md-6.offset-md-6 {
        margin-left: 0 !important;
    }
}

@media (max-width: 576px) {
    .format-header {
        padding: 12px 15px;
    }
    
    .format-header h4,
    .format-header h5 {
        font-size: 1.1rem;
    }
    
    .btn-group .btn-sm {
        padding: 0.25rem 0.4rem;
        font-size: 0.8rem;
    }
    
    .upload-zone {
        padding: 12px;
        min-height: 70px;
    }
}

.row > [class*="col-"] {
    display: flex;
    flex-direction: column;
}

.format-card {
    flex: 1;
}
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-folder-fill"></i> Gestione Materiali Format</h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newFormatModal">
            <i class="bi bi-plus-circle"></i> Nuovo Format
        </button>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-x-circle"></i> <strong>Errore:</strong> 
            <pre style="margin:0; white-space: pre-wrap;"><?php echo e($error); ?></pre>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle"></i> <strong>Successo:</strong> <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (empty($formats)): ?>
        <div class="text-center py-5">
            <i class="bi bi-folder-x" style="font-size: 4rem; color: #ccc;"></i>
            <p class="text-muted mt-3">Nessun format presente. Crea il primo format!</p>
        </div>
    <?php else: ?>
        
        <div class="row">
            <?php 
            foreach ($formats as $index => $format): 
                if (count($formats) == 1): 
            ?>
                <div class="col-md-6 offset-md-6">
            <?php else: ?>
                <div class="col-md-6">
            <?php endif; ?>
                
                <div class="format-card" data-format-id="<?php echo $format['id']; ?>">
                    <div class="format-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-1"><?php echo e($format['name']); ?></h5>
                                <small>
                                    Creato da <?php echo e($format['creator_name']); ?> il <?php echo date('d/m/Y', strtotime($format['created_at'])); ?>
                                    • <?php echo $format['files_count']; ?> file<?php echo $format['files_count'] != 1 ? 's' : ''; ?>
                                    • Stato: <?php echo $format['is_active'] ? '<span class="badge bg-success">Attivo</span>' : '<span class="badge bg-secondary">Inattivo</span>'; ?>
                                </small>
                                <?php if ($format['description']): ?>
                                <div class="mt-2">
                                    <small><?php echo e($format['description']); ?></small>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="btn-group">
                                <button class="btn btn-light btn-sm" onclick="editFormat(<?php echo $format['id']; ?>, '<?php echo addslashes($format['name']); ?>', '<?php echo addslashes($format['description']); ?>', <?php echo $format['is_active']; ?>)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-danger btn-sm" onclick="deleteFormat(<?php echo $format['id']; ?>, '<?php echo addslashes($format['name']); ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="p-3">
                        <!-- Upload Zone -->
                        <div class="upload-zone" onclick="triggerFileInput(<?php echo $format['id']; ?>)">
                            <div class="upload-zone-content">
                                <i class="bi bi-cloud-arrow-up" style="color: #6c757d;"></i>
                                <h6 class="mt-1 mb-1">Carica file (PDF, Office, Img, Zip)</h6>
                                <p class="mb-1 text-muted">Clicca o trascina qui i file</p>
                                <small class="text-muted">Max 20MB</small>
                            </div>
                        </div>
                        
                        <!-- Hidden File Input (Accetta multipli tipi) -->
                        <input type="file" 
                               id="fileInput<?php echo $format['id']; ?>" 
                               multiple 
                               accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.zip,.rar,.txt" 
                               style="display: none;" 
                               onchange="handleFileSelection(<?php echo $format['id']; ?>, this.files)">
                        
                        <!-- Selected Files Container -->
                        <div id="selectedFilesContainer<?php echo $format['id']; ?>" class="selected-files-container">
                            <div class="p-3 border-bottom">
                                <h6 class="mb-0">
                                    <i class="bi bi-files"></i> 
                                    File selezionati (<span id="fileCount<?php echo $format['id']; ?>">0</span>)
                                </h6>
                            </div>
                            <div id="selectedFilesList<?php echo $format['id']; ?>">
                                <!-- File inseriti dinamicamente -->
                            </div>
                            <div class="p-3 border-top bg-light">
                                <div class="d-flex gap-2">
                                    <button type="button" 
                                            class="btn btn-success" 
                                            id="uploadBtn<?php echo $format['id']; ?>"
                                            onclick="uploadFiles(<?php echo $format['id']; ?>)">
                                        <i class="bi bi-upload"></i> Carica tutti i file
                                    </button>
                                    <button type="button" 
                                            class="btn btn-secondary" 
                                            onclick="clearSelection(<?php echo $format['id']; ?>)">
                                        <i class="bi bi-x-circle"></i> Annulla
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Upload Progress -->
                        <div id="uploadProgress<?php echo $format['id']; ?>" class="upload-progress">
                            <h6><i class="bi bi-hourglass-split"></i> Upload in corso...</h6>
                            <div class="progress">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div>
                            </div>
                        </div>
                        
                        <!-- File List -->
                        <?php if (!empty($format['files'])): ?>
                        <h6 class="mt-4 mb-3">File caricati:</h6>
                        <div id="filesList<?php echo $format['id']; ?>">
                            <?php foreach ($format['files'] as $file): 
                                $iconClass = getFileIconClass($file['file_name']);
                            ?>
                            <div class="file-item" data-file-id="<?php echo $file['id']; ?>">
                                <div class="d-flex align-items-center">
                                    <i class="bi <?php echo $iconClass; ?> fs-4 me-3"></i>
                                    <div>
                                        <strong><?php echo e($file['description']); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <?php echo e($file['original_name']); ?> • <?php echo formatFileSize($file['file_size']); ?>
                                            • <?php echo date('d/m/Y H:i', strtotime($file['created_at'])); ?>
                                        </small>
                                    </div>
                                </div>
                                <div class="btn-group">
                                    <a href="../files/<?php echo e($file['file_name']); ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <button class="btn btn-outline-danger btn-sm" onclick="deleteFile(<?php echo $file['id']; ?>, '<?php echo addslashes($file['description']); ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-3 text-muted">
                            <i class="bi bi-inbox"></i> Nessun file caricato
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modals (Nuovo e Modifica) rimangono identici... -->
<div class="modal fade" id="newFormatModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Nuovo Format</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_format">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome Format *</label>
                        <input type="text" name="name" class="form-control" placeholder="Es: 2000CheStories" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrizione</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Descrizione opzionale del format..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">Crea Format</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editFormatModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Modifica Format</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_format">
                <input type="hidden" name="format_id" id="editFormatId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome Format *</label>
                        <input type="text" name="name" id="editFormatName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrizione</label>
                        <textarea name="description" id="editFormatDescription" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="is_active" id="editFormatActive" class="form-check-input">
                        <label class="form-check-label" for="editFormatActive">Format attivo</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-warning">Aggiorna Format</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const selectedFiles = {};

// Helper per icone JS
function getJsFileIconClass(filename) {
    const ext = filename.split('.').pop().toLowerCase();
    switch (ext) {
        case 'pdf': return 'bi-file-earmark-pdf text-danger';
        case 'doc': case 'docx': return 'bi-file-earmark-word text-primary';
        case 'xls': case 'xlsx': case 'csv': return 'bi-file-earmark-excel text-success';
        case 'ppt': case 'pptx': return 'bi-file-earmark-slides text-warning';
        case 'jpg': case 'jpeg': case 'png': case 'gif': return 'bi-file-earmark-image text-info';
        case 'zip': case 'rar': case '7z': return 'bi-file-earmark-zip text-secondary';
        default: return 'bi-file-earmark text-secondary';
    }
}

function triggerFileInput(formatId) {
    document.getElementById('fileInput' + formatId).click();
}

function handleFileSelection(formatId, files) {
    if (!selectedFiles[formatId]) {
        selectedFiles[formatId] = [];
    }
    selectedFiles[formatId] = selectedFiles[formatId].concat(Array.from(files));
    displaySelectedFiles(formatId);
}

function displaySelectedFiles(formatId) {
    const container = document.getElementById('selectedFilesContainer' + formatId);
    const filesList = document.getElementById('selectedFilesList' + formatId);
    const fileCount = document.getElementById('fileCount' + formatId);
    const uploadZone = document.querySelector(`[data-format-id="${formatId}"] .upload-zone`);
    
    fileCount.textContent = selectedFiles[formatId].length;
    
    if (selectedFiles[formatId].length > 0) {
        uploadZone.classList.add('has-files');
        uploadZone.querySelector('.upload-zone-content').innerHTML = `
            <i class="bi bi-check-circle" style="color: #28a745;"></i>
            <h6 class="mt-1 mb-1 text-success">${selectedFiles[formatId].length} file selezionati</h6>
            <p class="mb-1 text-muted">Clicca per altri file o procedi con l'upload</p>
        `;
    } else {
        uploadZone.classList.remove('has-files');
        uploadZone.querySelector('.upload-zone-content').innerHTML = `
            <i class="bi bi-cloud-arrow-up" style="color: #6c757d;"></i>
            <h6 class="mt-1 mb-1">Carica file</h6>
            <p class="mb-1 text-muted">Clicca o trascina qui i file</p>
            <small class="text-muted">Max 20MB</small>
        `;
    }
    
    filesList.innerHTML = '';
    
    selectedFiles[formatId].forEach((file, index) => {
        const iconClass = getJsFileIconClass(file.name);
        
        const fileItem = document.createElement('div');
        fileItem.className = 'selected-file-item';
        fileItem.innerHTML = `
            <div class="file-preview bg-white">
                <i class="bi ${iconClass} fs-4"></i>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <strong>${escapeHtml(file.name)}</strong>
                    <small class="text-muted">(${formatFileSize(file.size)})</small>
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeFile(${formatId}, ${index})">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
                <input type="text" 
                       class="form-control form-control-sm" 
                       id="fileDescription${formatId}_${index}"
                       placeholder="Descrizione (es. Logo Vettoriale)" 
                       required>
            </div>
        `;
        filesList.appendChild(fileItem);
    });
    
    container.style.display = selectedFiles[formatId].length > 0 ? 'block' : 'none';
}

function removeFile(formatId, index) {
    selectedFiles[formatId].splice(index, 1);
    displaySelectedFiles(formatId);
}

function clearSelection(formatId) {
    selectedFiles[formatId] = [];
    document.getElementById('fileInput' + formatId).value = '';
    displaySelectedFiles(formatId);
}

function uploadFiles(formatId) {
    const files = selectedFiles[formatId];
    if (!files || files.length === 0) {
        alert('Nessun file selezionato');
        return;
    }
    
    const descriptions = [];
    let allValid = true;
    
    for (let i = 0; i < files.length; i++) {
        const descInput = document.getElementById(`fileDescription${formatId}_${i}`);
        const description = descInput.value.trim();
        
        if (!description) {
            descInput.classList.add('is-invalid');
            allValid = false;
        } else {
            descInput.classList.remove('is-invalid');
            descriptions.push(description);
        }
    }
    
    if (!allValid) {
        alert('Inserisci una descrizione per tutti i file selezionati');
        return;
    }
    
    const progressContainer = document.getElementById('uploadProgress' + formatId);
    const progressBar = progressContainer.querySelector('.progress-bar');
    progressContainer.style.display = 'block';
    
    const uploadBtn = document.getElementById('uploadBtn' + formatId);
    uploadBtn.disabled = true;
    uploadBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Caricamento...';
    
    const formData = new FormData();
    formData.append('action', 'upload_multiple_files');
    formData.append('format_id', formatId);
    
    for (let i = 0; i < files.length; i++) {
        formData.append('files[]', files[i]);
        formData.append('file_descriptions[]', descriptions[i]);
    }
    
    const xhr = new XMLHttpRequest();
    
    xhr.upload.addEventListener('progress', function(e) {
        if (e.lengthComputable) {
            const percentComplete = (e.loaded / e.total) * 100;
            progressBar.style.width = percentComplete + '%';
        }
    });
    
    xhr.onload = function() {
        if (xhr.status === 200) {
            window.location.reload();
        } else {
            alert('Errore durante l\'upload');
            progressContainer.style.display = 'none';
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = '<i class="bi bi-upload"></i> Carica tutti i file';
        }
    };
    
    xhr.onerror = function() {
        alert('Errore di rete durante l\'upload');
        progressContainer.style.display = 'none';
        uploadBtn.disabled = false;
        uploadBtn.innerHTML = '<i class="bi bi-upload"></i> Carica tutti i file';
    };
    
    xhr.open('POST', window.location.href);
    xhr.send(formData);
}

document.querySelectorAll('.upload-zone').forEach(zone => {
    const formatCard = zone.closest('[data-format-id]');
    const formatId = formatCard.dataset.formatId;
    
    zone.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('dragover');
    });
    
    zone.addEventListener('dragleave', function() {
        this.classList.remove('dragover');
    });
    
    zone.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
        
        // Rimosso il filtro solo PDF, ora accetta tutto ciò che è nella whitelist
        const files = Array.from(e.dataTransfer.files);
        if (files.length > 0) {
            if (!selectedFiles[formatId]) {
                selectedFiles[formatId] = [];
            }
            selectedFiles[formatId] = selectedFiles[formatId].concat(files);
            displaySelectedFiles(formatId);
        }
    });
});

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatFileSize(size) {
    const units = ['B', 'KB', 'MB', 'GB'];
    let unit = 0;
    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit++;
    }
    return Math.round(size * 100) / 100 + ' ' + units[unit];
}

function editFormat(id, name, description, isActive) {
    document.getElementById('editFormatId').value = id;
    document.getElementById('editFormatName').value = name;
    document.getElementById('editFormatDescription').value = description;
    document.getElementById('editFormatActive').checked = isActive;
    
    const modal = new bootstrap.Modal(document.getElementById('editFormatModal'));
    modal.show();
}

function deleteFormat(id, name) {
    if (confirm(`Sei sicuro di voler eliminare il format "${name}"?\n\nTutti i file associati saranno eliminati definitivamente.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_format">
            <input type="hidden" name="format_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function deleteFile(id, description) {
    if (confirm(`Sei sicuro di voler eliminare il file "${description}"?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_file">
            <input type="hidden" name="file_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php 
function formatFileSize($size) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $unit = 0;
    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }
    return round($size, 2) . ' ' . $units[$unit];
}

include '../includes/footer.php'; 
?>