<?php
require_once 'config.php';
require_once 'auth.php';
require_once 'functions.php';

requireLogin();

$db = getDBConnection();
$currentMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$currentYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Calcola primo e ultimo giorno del mese
$firstDay = new DateTime("$currentYear-$currentMonth-01");
$lastDay = clone $firstDay;
$lastDay->modify('last day of this month');

// QUERY CALENDARIO
$sql = "SELECT q.id as quote_id, q.quote_number, q.status, q.created_by, 
        qi.id as item_id, qi.package_name, qi.event_date, qi.event_time,
        q.event_location as location,
        c.first_name, c.last_name, c.company_name, 
        u.username as created_by_name, u.first_name as user_firstname, u.last_name as user_lastname
        FROM quote_items qi
        JOIN quotes q ON qi.quote_id = q.id
        JOIN clients c ON q.client_id = c.id
        JOIN users u ON q.created_by = u.id
        WHERE qi.event_date BETWEEN '" . $firstDay->format('Y-m-d') . "' AND '" . $lastDay->format('Y-m-d') . "'
        AND qi.event_date IS NOT NULL
        AND q.status IN ('inviato', 'accettato', 'confermato')
        ORDER BY qi.event_date, qi.event_time, qi.sort_order";

$result = mysqli_query($db, $sql);
$events = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        // Pulizia nome pacchetto
        $row['package_name_clean'] = preg_replace('/\s*\[.*?\]\s*/', '', $row['package_name']);
        $row['package_name_clean'] = trim($row['package_name_clean']);
        if (empty($row['package_name_clean'])) continue;
        
        $row['is_mine'] = ($row['created_by'] == $_SESSION['user_id']);
        $events[] = $row;
    }
}

// Organizza eventi per data
$eventsByDate = [];
foreach ($events as $event) {
    $date = $event['event_date'];
    if (!isset($eventsByDate[$date])) $eventsByDate[$date] = [];
    $eventsByDate[$date][] = $event;
}

// KPI & Liste per Admin
$kpi = null;
$toAssignList = [];
$graphicsTodoList = [];

if (isAdmin()) {
    // KPI
    $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT SUM(total) as revenue FROM quotes WHERE status = 'confermato'"));
    $revenue = $row['revenue'] ?? 0;
    
    $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) as count FROM quotes WHERE status = 'inviato'"));
    $pending = $row['count'] ?? 0;
    
    $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(DISTINCT qi.id) as count FROM quote_items qi JOIN quotes q ON qi.quote_id = q.id WHERE qi.event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND qi.event_date IS NOT NULL AND q.status IN ('accettato', 'confermato')"));
    $upcoming = $row['count'] ?? 0;
    
    $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) as count FROM quotes q WHERE q.status = 'confermato' AND (q.staff_management_status IS NULL OR q.staff_management_status = 'pending')"));
    $toAssign = $row['count'] ?? 0;
    
    $kpi = compact('revenue', 'pending', 'upcoming', 'toAssign');

    // LISTA 1: STAFF DA ASSEGNARE (Limit 5 per widget)
    $sqlAssign = "SELECT q.*, c.company_name, c.first_name, c.last_name,
            (SELECT MIN(event_date) FROM quote_items WHERE quote_id = q.id AND event_date IS NOT NULL) as first_event_date
            FROM quotes q
            JOIN clients c ON q.client_id = c.id
            WHERE q.status = 'confermato'
            AND (q.staff_management_status IS NULL OR q.staff_management_status = 'pending')
            ORDER BY first_event_date ASC LIMIT 5";
    $resAssign = mysqli_query($db, $sqlAssign);
    while ($row = mysqli_fetch_assoc($resAssign)) $toAssignList[] = $row;

    // LISTA 2: GRAFICHE DA FARE / PUBBLICARE (Limit 5 per widget)
    $sqlGraphics = "SELECT qi.id as item_id, qi.package_name, qi.event_date, 
                    qi.graphics_created, qi.graphics_sponsored,
                    q.quote_number, q.id as quote_id,
                    c.company_name, c.first_name, c.last_name
                    FROM quote_items qi
                    JOIN quotes q ON qi.quote_id = q.id
                    JOIN clients c ON q.client_id = c.id
                    WHERE q.status = 'confermato'
                    AND qi.graphics_included = 1
                    AND (qi.graphics_created = 0 OR qi.graphics_sponsored = 0)
                    ORDER BY qi.event_date ASC LIMIT 5";
    $resGraphics = mysqli_query($db, $sqlGraphics);
    while ($row = mysqli_fetch_assoc($resGraphics)) {
        $row['package_name_clean'] = preg_replace('/\s*\[.*?\]\s*/', '', $row['package_name']);
        $graphicsTodoList[] = $row;
    }
}

$pageTitle = 'Dashboard';
include 'includes/header.php';
?>

<style>
/* Pulsanti Rapidi Orizzontali */
.quick-actions-bar {
    background: #fff;
    padding: 15px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    margin-bottom: 20px;
}
.quick-actions-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.quick-action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.6rem 0.8rem;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    background: white;
    color: #495057;
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 600;
    transition: all 0.2s;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1 1 auto;
}
.quick-action-btn i { margin-right: 6px; font-size: 1rem; }
.quick-action-btn:hover { background: #f8f9fa; border-color: #0d6efd; color: #0d6efd; transform: translateY(-2px); box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
.quick-action-btn.primary { background-color: #e7f1ff; color: #0d6efd; border-color: #cff4fc; }
.quick-action-btn.primary:hover { background-color: #0d6efd; color: #fff; }

/* Calendario */
.calendar-day {
    height: 160px; /* Altezza fissa celle */
    vertical-align: top;
    padding: 0.4rem;
    position: relative;
    overflow: hidden;
    width: 14.28%;
}
.events-container {
    font-size: 0.75rem;
    overflow-y: auto;
    max-height: 130px;
}
.day-number { font-weight: 700; margin-bottom: 0.3rem; font-size: 0.9rem; }

/* Badge Evento nel Calendario */
.event-badge {
    display: block;
    padding: 4px 6px;
    margin-bottom: 4px;
    border-radius: 4px;
    color: white;
    text-decoration: none;
    font-size: 0.75rem;
    border: 1px solid rgba(0,0,0,0.1);
    line-height: 1.2;
    transition: transform 0.1s;
}
.event-confermato { background-color: #198754; border-left: 3px solid #0f5132; }
.event-accettato { background-color: #fd7e14; border-left: 3px solid #c85f06; }
.event-inviato { background-color: #0dcaf0; color: #000 !important; border-left: 3px solid #0aa2c0; }
.event-badge:hover { opacity: 0.95; color: white; transform: scale(1.02); z-index: 5; }

/* Dettagli dentro il badge evento */
.event-details-row { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.event-pkg { font-weight: 700; }
.event-client { font-size: 0.7rem; opacity: 0.9; }
.event-loc { font-size: 0.65rem; font-style: italic; opacity: 0.8; }

/* Liste laterali (Widget) */
.widget-card { margin-bottom: 1.5rem; border: none; shadow-sm; }
.widget-list { max-height: 400px; overflow-y: auto; }
.widget-item { text-decoration: none; color: inherit; padding: 0.75rem 1rem; display: block; border-bottom: 1px solid #eee; transition: background 0.2s; }
.widget-item:hover { background-color: #f8f9fa; }
.widget-item:last-child { border-bottom: none; }
</style>

<div class="container-fluid py-4">
    
    <!-- 1. KPI COUNTERS (Solo Admin) -->
    <?php if ($kpi): ?>
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white h-100 shadow-sm">
                <div class="card-body py-3">
                    <h6 class="card-title opacity-75 mb-1">Revenue Totale (No IVA)</h6>
                    <h3 class="mb-0"><?php echo formatPrice($kpi['revenue']); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white h-100 shadow-sm">
                <div class="card-body py-3">
                    <h6 class="card-title opacity-75 mb-1">Preventivi Inviati</h6>
                    <h3 class="mb-0"><?php echo $kpi['pending']; ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white h-100 shadow-sm">
                <div class="card-body py-3">
                    <h6 class="card-title opacity-75 mb-1">Eventi a 30gg</h6>
                    <h3 class="mb-0"><?php echo $kpi['upcoming']; ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white h-100 shadow-sm">
                <div class="card-body py-3">
                    <h6 class="card-title opacity-75 mb-1">Staff da Assegnare</h6>
                    <h3 class="mb-0">
                        <a href="admin/assign_staff.php" class="text-white text-decoration-none">
                            <?php echo $kpi['toAssign']; ?> <i class="bi bi-arrow-right-circle fs-5 ms-2"></i>
                        </a>
                    </h3>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 2. COMANDI RAPIDI ORIZZONTALI (Fluido) -->
    <div class="quick-actions-bar">
        <h6 class="text-muted text-uppercase small fw-bold mb-3"><i class="bi bi-lightning-charge"></i> Accesso Rapido</h6>
        <div class="quick-actions-grid">
            <?php if (isAdmin()): ?>
                <a href="admin/assign_staff.php" class="quick-action-btn primary"><i class="bi bi-people-fill"></i> Assegna Staff</a>
                <a href="quotes.php" class="quick-action-btn"><i class="bi bi-file-earmark-plus"></i> Preventivi</a>
                <a href="clients.php" class="quick-action-btn"><i class="bi bi-person-plus"></i> Clienti</a>
                <a href="leads.php" class="quick-action-btn"><i class="bi bi-megaphone"></i> Leads</a>
                <a href="admin/packages.php" class="quick-action-btn"><i class="bi bi-box-seam"></i> Pacchetti</a>
                <a href="admin/staff.php" class="quick-action-btn"><i class="bi bi-person-badge"></i> Staff</a>
                <a href="admin/services.php" class="quick-action-btn"><i class="bi bi-wrench"></i> Servizi</a>
                <a href="admin/analytics.php" class="quick-action-btn"><i class="bi bi-graph-up"></i> Analytics</a>
            <?php else: ?>
                <a href="quotes.php" class="quick-action-btn primary"><i class="bi bi-file-earmark-plus"></i> Nuovo Preventivo</a>
                <a href="clients.php" class="quick-action-btn"><i class="bi bi-person-plus"></i> Nuovo Cliente</a>
                <a href="leads.php" class="quick-action-btn"><i class="bi bi-megaphone"></i> Segnalazioni</a>
                <a href="my_stats.php" class="quick-action-btn"><i class="bi bi-bar-chart"></i> Statistiche</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        
        <!-- COLONNA 1: CALENDARIO (LARGA 8/12) -->
        <div class="<?php echo isAdmin() ? 'col-lg-8' : 'col-lg-12'; ?>">
            <div class="card shadow-sm h-100">
                <div class="card-header d-flex justify-content-between align-items-center bg-white py-3">
                    <?php
                    $mesi_it = [1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile', 5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto', 9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre'];
                    ?>
                    <h5 class="mb-0 fw-bold text-primary text-uppercase">
                        <i class="bi bi-calendar-week"></i> <?php echo $mesi_it[$currentMonth] . ' ' . $currentYear; ?>
                    </h5>
                    <div class="btn-group btn-group-sm">
                        <?php
                        $prevM = $currentMonth==1?12:$currentMonth-1; $prevY = $currentMonth==1?$currentYear-1:$currentYear;
                        $nextM = $currentMonth==12?1:$currentMonth+1; $nextY = $currentMonth==12?$currentYear+1:$currentYear;
                        ?>
                        <a href="?month=<?php echo $prevM; ?>&year=<?php echo $prevY; ?>" class="btn btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
                        <a href="?month=<?php echo date('m'); ?>&year=<?php echo date('Y'); ?>" class="btn btn-outline-secondary">Oggi</a>
                        <a href="?month=<?php echo $nextM; ?>&year=<?php echo $nextY; ?>" class="btn btn-outline-secondary"><i class="bi bi-chevron-right"></i></a>
                    </div>
                </div>
                
                <div class="calendar-legend border-bottom px-3 py-2 bg-light d-flex align-items-center gap-3">
                    <strong class="text-muted small text-uppercase">Legenda:</strong>
                    <div class="d-flex align-items-center gap-1"><span class="badge bg-success rounded-circle p-1" style="width:10px;height:10px;"> </span> <small>Confermato</small></div>
                    <div class="d-flex align-items-center gap-1"><span class="badge bg-warning text-dark rounded-circle p-1" style="width:10px;height:10px;background-color:#fd7e14;"> </span> <small>Opzione</small></div>
                    <div class="d-flex align-items-center gap-1"><span class="badge bg-info text-dark rounded-circle p-1" style="width:10px;height:10px;"> </span> <small>Trattativa</small></div>
                </div>
                
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered mb-0">
                            <thead class="table-light text-center small text-uppercase"><tr><th>Lun</th><th>Mar</th><th>Mer</th><th>Gio</th><th>Ven</th><th>Sab</th><th>Dom</th></tr></thead>
                            <tbody>
                            <?php
                            // Logica calendario
                            $calendar = clone $firstDay;
                            $dayOfWeek = (int)$calendar->format('N');
                            if ($dayOfWeek > 1) $calendar->modify('-' . ($dayOfWeek - 1) . ' days');
                            $maxWeeks = 6;
                            
                            for ($w = 0; $w < $maxWeeks; $w++): ?>
                                <tr>
                                <?php for ($d = 0; $d < 7; $d++): 
                                    $dateKey = $calendar->format('Y-m-d');
                                    $isCurrent = (int)$calendar->format('m') === $currentMonth;
                                    $isToday = $dateKey === date('Y-m-d');
                                    $dayEvents = $eventsByDate[$dateKey] ?? [];
                                ?>
                                <td class="calendar-day <?php echo $isCurrent?'':'bg-light text-muted'; ?> <?php echo $isToday?'border border-primary border-2':''; ?>">
                                    <div class="day-number <?php echo $isToday?'text-primary':''; ?>"><?php echo $calendar->format('j'); ?></div>
                                    <?php if (!empty($dayEvents)): ?>
                                        <div class="events-container">
                                        <?php foreach ($dayEvents as $ev): 
                                            $cls = 'event-'.$ev['status'];
                                            $clientName = $ev['company_name'] ?: trim($ev['first_name'] . ' ' . $ev['last_name']);
                                            $tooltip = "Stato: " . ucfirst($ev['status']) . "\nPacchetto: " . $ev['package_name_clean'] . "\nCliente: " . $clientName;
                                        ?>
                                            <a href="quotes.php?id=<?php echo $ev['quote_id']; ?>" class="event-badge <?php echo $cls; ?>" title="<?php echo e($tooltip); ?>" data-bs-toggle="tooltip">
                                                <span class="event-details-row event-pkg"><?php echo e($ev['package_name_clean']); ?></span>
                                                <span class="event-details-row event-client"><?php echo e($clientName); ?></span>
                                                <?php if(!empty($ev['location'])): ?>
                                                    <span class="event-details-row event-loc"><i class="bi bi-geo-alt-fill"></i> <?php echo e($ev['location']); ?></span>
                                                <?php endif; ?>
                                            </a>
                                        <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <?php $calendar->modify('+1 day'); endfor; ?>
                                </tr>
                                <?php if ($calendar->format('m') != $currentMonth && $calendar->format('d') > 7) break; 
                            endfor; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isAdmin()): ?>
        <!-- COLONNA 2: SIDEBAR WIDGETS (STRETTA 4/12) -->
        <div class="col-lg-4">
            
            <!-- 1. STAFF DA ASSEGNARE -->
            <div class="card shadow-sm widget-card">
                <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-people-fill"></i> Staff da Assegnare</h6>
                    <span class="badge bg-white text-danger rounded-pill"><?php echo count($toAssignList); ?></span>
                </div>
                <div class="widget-list">
                    <?php if (empty($toAssignList)): ?>
                        <div class="p-4 text-center text-muted"><i class="bi bi-check-circle-fill fs-3 text-success"></i><br>Tutto lo staff è assegnato!</div>
                    <?php else: foreach ($toAssignList as $item): 
                         $client = $item['company_name'] ?: ($item['first_name'].' '.$item['last_name']); ?>
                        <a href="admin/assign_staff.php?quote_id=<?php echo $item['id']; ?>" class="widget-item">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="fw-bold text-dark">#<?php echo $item['quote_number']; ?> - <?php echo e($client); ?></div>
                                    <small class="text-danger fw-bold"><i class="bi bi-calendar-event"></i> <?php echo formatDate($item['first_event_date']); ?></small>
                                </div>
                                <i class="bi bi-chevron-right text-muted small"></i>
                            </div>
                        </a>
                    <?php endforeach; endif; ?>
                </div>
                <?php if (!empty($toAssignList)): ?>
                <?php endif; ?>
            </div>

            <!-- 2. GRAFICHE DA COMPLETARE -->
            <div class="card shadow-sm widget-card mt-4">
                <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-palette-fill"></i> Creatività Grafiche</h6>
                    <span class="badge bg-dark text-warning rounded-pill"><?php echo count($graphicsTodoList); ?></span>
                </div>
                <div class="widget-list">
                    <?php if (empty($graphicsTodoList)): ?>
                        <div class="p-4 text-center text-muted"><i class="bi bi-check-circle-fill fs-3 text-success"></i><br>Nessuna grafica in sospeso</div>
                    <?php else: foreach ($graphicsTodoList as $gfx): 
                         $client = $gfx['company_name'] ?: ($gfx['first_name'].' '.$gfx['last_name']); ?>
                        <a href="admin/assign_staff.php?quote_id=<?php echo $gfx['quote_id']; ?>" class="widget-item">
                            <!-- NOME COMPLETO SENZA LIMITI -->
                            <div class="fw-bold text-primary mb-1"><?php echo e($gfx['package_name_clean']); ?></div>
                            <div class="small text-muted mb-1"><?php echo e($client); ?></div>
                            <div class="d-flex justify-content-between align-items-end">
                                <small class="text-dark"><i class="bi bi-calendar"></i> <?php echo formatDate($gfx['event_date']); ?></small>
                                <div class="d-flex gap-1">
                                    <?php if(!$gfx['graphics_created']): ?>
                                        <span class="badge bg-danger text-uppercase" style="font-size:0.6rem">Creare Grafiche</span>
                                    <?php endif; ?>
                                    <?php if(!$gfx['graphics_sponsored']): ?>
                                        <span class="badge bg-warning text-dark text-uppercase" style="font-size:0.6rem">Programmare Grafiche</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; endif; ?>
                </div>
            </div>

        </div>
        <?php endif; ?>

    </div>
</div>

<script>
var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
  return new bootstrap.Tooltip(tooltipTriggerEl)
})
</script>

<?php include 'includes/footer.php'; ?>