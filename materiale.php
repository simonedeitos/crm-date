<?php
require_once 'config.php';
require_once 'auth.php';
require_once 'functions.php';

requireLogin(); // Solo login necessario, non admin

$db = getDBConnection();

// Carica tutti i format attivi con i loro file attivi
$formats_query = "SELECT f.*, u.username as creator_name,
                  (SELECT COUNT(*) FROM format_files WHERE format_id = f.id AND is_active = 1) as files_count
                  FROM formats f 
                  LEFT JOIN users u ON f.created_by = u.id 
                  WHERE f.is_active = 1
                  ORDER BY f.name";

$formats_result = mysqli_query($db, $formats_query);
$formats = [];
while ($row = mysqli_fetch_assoc($formats_result)) {
    // Carica i file attivi per questo format
    $files_query = "SELECT * FROM format_files 
                   WHERE format_id = {$row['id']} AND is_active = 1 
                   ORDER BY sort_order, created_at";
    $files_result = mysqli_query($db, $files_query);
    $row['files'] = [];
    while ($file = mysqli_fetch_assoc($files_result)) {
        $row['files'][] = $file;
    }
    
    // Includi solo format che hanno almeno un file
    if (!empty($row['files'])) {
        $formats[] = $row;
    }
}

$pageTitle = 'Materiali Format';
include 'includes/header.php';
?>

<style>
.format-card {
    border: 2px solid #e9ecef;
    border-radius: 12px;
    margin-bottom: 30px;
    overflow: hidden;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
    transition: all 0.3s ease;
    height: auto;
    min-height: 300px;
}
.format-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
}
.format-header {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
    padding: 20px 25px;
    position: relative;
}
.format-header::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: rgba(255, 255, 255, 0.3);
}
.file-download-item {
    background: #fff;
    border-bottom: 1px solid #f1f3f4;
    padding: 15px 25px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: background-color 0.2s ease;
}
.file-download-item:hover {
    background: #f8f9fa;
}
.file-download-item:last-child {
    border-bottom: none;
}
.file-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 15px;
    flex-shrink: 0;
    font-size: 1.2rem;
    color: white;
}
/* Colori specifici per tipo file */
.icon-pdf { background-color: #dc3545; }
.icon-word { background-color: #0d6efd; }
.icon-excel { background-color: #198754; }
.icon-image { background-color: #6f42c1; }
.icon-zip { background-color: #ffc107; color: #000; }
.icon-default { background-color: #6c757d; }

.download-btn {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    border: none;
    color: white;
    padding: 8px 16px;
    border-radius: 6px;
    text-decoration: none;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}
.download-btn:hover {
    background: linear-gradient(135deg, #0056b3 0%, #004085 100%);
    color: white;
    transform: translateY(-1px);
}
.stats-badge {
    background: rgba(255, 255, 255, 0.2);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.85em;
    margin-left: 10px;
}
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6c757d;
}
.empty-state i {
    font-size: 4rem;
    margin-bottom: 20px;
    opacity: 0.5;
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
        padding: 15px 20px;
    }
    .file-download-item {
        padding: 12px 20px;
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
    }
    .file-icon {
        margin-right: 0;
        margin-bottom: 10px;
        align-self: center;
    }
    .download-btn {
        align-self: center;
        justify-self: center;
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
        <h2><i class="bi bi-download"></i> Materiali Format</h2>
        <div class="text-muted"></div>
    </div>

    <?php if (empty($formats)): ?>
        <div class="empty-state">
            <i class="bi bi-folder-x"></i>
            <h4>Nessun materiale disponibile</h4>
            <p class="text-muted">Non ci sono format con materiali disponibili al momento.<br>Contatta l'amministratore per maggiori informazioni.</p>
        </div>
    <?php else: ?>
        
        <div class="row">
            <div class="col-12">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    <strong>Informazione:</strong> Qui puoi scaricare tutti i materiali organizzati per format. 
                    Clicca su "Download" per scaricare il file sul tuo dispositivo.
                </div>
            </div>
        </div>
        
        <div class="row">
            <?php 
            foreach ($formats as $index => $format): 
                // Se c'è solo 1 format, mettilo nella colonna di destra
                if (count($formats) == 1): 
            ?>
                <div class="col-md-6 offset-md-6">
            <?php else: ?>
                <div class="col-md-6">
            <?php endif; ?>
                
                <div class="format-card">
                    <div class="format-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="mb-1">
                                    <i class="bi bi-folder-fill me-2"></i>
                                    <?php echo e($format['name']); ?>
                                </h4>
                                <?php if ($format['description']): ?>
                                <p class="mb-0 opacity-75"><?php echo e($format['description']); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="text-end">
                                <span class="stats-badge">
                                    <?php echo count($format['files']); ?> file<?php echo count($format['files']) != 1 ? 's' : ''; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="files-container">
                        <?php foreach ($format['files'] as $file): 
                            // Determina icona e classe colore
                            $fileInfo = getFileIconClass($file['file_name']);
                        ?>
                        <div class="file-download-item">
                            <div class="d-flex align-items-center flex-grow-1">
                                <div class="file-icon <?php echo $fileInfo['class']; ?>">
                                    <i class="<?php echo $fileInfo['icon']; ?>"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <h6 class="mb-1"><?php echo e($file['description']); ?></h6>
                                    <small class="text-muted">
                                        <?php echo e($file['original_name']); ?> • 
                                        <?php echo formatFileSize($file['file_size']); ?> • 
                                        Aggiornato il <?php echo date('d/m/Y', strtotime($file['created_at'])); ?>
                                    </small>
                                </div>
                            </div>
                            <div class="ms-3">
                                <a href="files/<?php echo e($file['file_name']); ?>" 
                                   class="download-btn" 
                                   download="<?php echo e($file['original_name']); ?>"
                                   onclick="trackDownload('<?php echo e($format['name']); ?>', '<?php echo e($file['description']); ?>')">
                                    <i class="bi bi-download"></i>
                                    Download
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="row mt-4">
            <div class="col-12">
                <div class="card border-light">
                    <div class="card-body text-center">
                        <h6 class="card-title">
                            <i class="bi bi-info-circle text-primary"></i>
                            Hai bisogno di assistenza?
                        </h6>
                        <p class="card-text text-muted">
                            Se non trovi il materiale che stai cercando o hai problemi con il download, 
                            contatta l'amministratore del sistema.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function trackDownload(formatName, fileName) {
    console.log(`Download: ${formatName} - ${fileName}`);
}

// Animazione di caricamento per i download
document.querySelectorAll('.download-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const originalText = this.innerHTML;
        this.innerHTML = '<i class="bi bi-hourglass-split"></i> Download...';
        this.style.pointerEvents = 'none';
        
        setTimeout(() => {
            this.innerHTML = originalText;
            this.style.pointerEvents = 'auto';
        }, 2000);
    });
});
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

function getFileIconClass($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    switch ($ext) {
        case 'pdf':
            return ['icon' => 'bi bi-file-earmark-pdf', 'class' => 'icon-pdf'];
        case 'doc':
        case 'docx':
            return ['icon' => 'bi bi-file-earmark-word', 'class' => 'icon-word'];
        case 'xls':
        case 'xlsx':
        case 'csv':
            return ['icon' => 'bi bi-file-earmark-excel', 'class' => 'icon-excel'];
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'gif':
        case 'webp':
            return ['icon' => 'bi bi-file-earmark-image', 'class' => 'icon-image'];
        case 'zip':
        case 'rar':
            return ['icon' => 'bi bi-file-earmark-zip', 'class' => 'icon-zip'];
        default:
            return ['icon' => 'bi bi-file-earmark', 'class' => 'icon-default'];
    }
}

include 'includes/footer.php'; 
?>