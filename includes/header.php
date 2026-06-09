<?php
if (!defined('SITE_NAME')) {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../auth.php';
    require_once __DIR__ . '/../functions.php';
}

requireLogin();

$currentUser = getCurrentUser();
$isAdminUser = isAdmin();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?><?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="<?php echo BASE_URL; ?>/assets/style.css" rel="stylesheet">
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?php echo BASE_URL; ?>/dashboard.php">
                <i class="bi bi-music-note-beamed"></i> <?php echo SITE_NAME; ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/dashboard.php">
                            <i class="bi bi-calendar3"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'leads.php' ? 'active' : ''; ?>" href="/crm/leads.php">
                            <i class="bi bi-megaphone"></i> Segnalazioni
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/quotes.php">
                            <i class="bi bi-file-earmark-text"></i> Preventivi
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/clients.php">
                            <i class="bi bi-people"></i> Clienti
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/crm/materiale.php">
                            <i class="bi bi-download"></i> Materiali
                        </a>
                    </li>
                    
                    <?php if ($isAdminUser): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/assign_staff.php">
                            <i class="bi bi-clipboard-check"></i> Assegna Staff
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-gear"></i> Amministrazione
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="adminDropdown">
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/admin/packages.php">
                                <i class="bi bi-box"></i> Pacchetti Format
                            </a></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/admin/staff.php">
                                <i class="bi bi-person-badge"></i> Staff & Ruoli
                            </a></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/admin/services.php">
                                <i class="bi bi-list-check"></i> Servizi
                            </a></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/admin/users.php">
                                <i class="bi bi-person-circle"></i> Utenti
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/admin/formats.php">
                                <i class="bi bi-folder-fill"></i> Gestione Materiali
                            </a></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/admin/analytics.php">
                                <i class="bi bi-graph-up"></i> Analytics
                            </a></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/admin/logs.php">
                                <i class="bi bi-journal-text"></i> Log Attività
                            </a></li>
                        </ul>
                    </li>
                    <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/my_stats.php">
                            <i class="bi bi-graph-up"></i> Le Mie Statistiche
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
                
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i> 
                            <?php echo e($currentUser['username']); ?>
                            <?php echo getRoleBadge($currentUser['role']); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/index.php?logout=1">
                                <i class="bi bi-box-arrow-right"></i> Logout
                            </a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main>