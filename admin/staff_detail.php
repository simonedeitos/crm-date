<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/functions.php';

requireAdmin();

$db = getDBConnection();

// Parametri Input
$staff_id = isset($_GET['staff_id']) ? (int)$_GET['staff_id'] : 0;
$current_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Date filtro
$filter_start_date = !empty($_GET['start_date']) ? $_GET['start_date'] : "$current_year-01-01";
$filter_end_date = !empty($_GET['end_date']) ? $_GET['end_date'] : "$current_year-12-31";
$filter_start_date = mysqli_real_escape_string($db, $filter_start_date);
$filter_end_date = mysqli_real_escape_string($db, $filter_end_date);

// Info staff
$staff_query = "SELECT * FROM staff WHERE id = $staff_id";
$staff_result = mysqli_query($db, $staff_query);

if (!$staff_result || mysqli_num_rows($staff_result) === 0) {
    echo "<div class='container py-5'><div class='alert alert-danger'>Staff non trovato.</div><a href='analytics.php' class='btn btn-secondary'>Indietro</a></div>";
    exit;
}
$staff_info = mysqli_fetch_assoc($staff_result);

// Query Dettaglio (Raw Data) - ORDINAMENTO CORRETTO
$detail_query = "SELECT 
                    qi.event_date,
                    qi.event_time,
                    qi.package_name,
                    q.id as quote_id,
                    q.quote_number,
                    q.event_location,
                    c.company_name,
                    c.first_name,
                    c.last_name,
                    sr.name as role_name,
                    qsa.cost,
                    qsa.extra,
                    qsa.notes,
                    (qsa.cost + qsa.extra) as total
                 FROM quote_staff_assignment qsa
                 INNER JOIN quote_items qi ON qsa.quote_item_id = qi.id
                 INNER JOIN quotes q ON qi.quote_id = q.id
                 INNER JOIN clients c ON q.client_id = c.id
                 LEFT JOIN staff_roles sr ON qsa.role_id = sr.id
                 WHERE qsa.staff_id = $staff_id
                 AND qi.event_date BETWEEN '$filter_start_date' AND '$filter_end_date'
                 ORDER BY qi.event_date ASC, qi.event_time ASC"; // CAMBIATO DA DESC AD ASC

$detail_result = mysqli_query($db, $detail_query);

// --- LOGICA DI RAGGRUPPAMENTO ---
$grouped_events = [];
$monthly_stats = [];
$total_year_cost = 0;
$total_year_extra = 0;
$total_year_overall = 0;

if ($detail_result) {
    while ($row = mysqli_fetch_assoc($detail_result)) {
        // 1. Calcoli Totali Generali
        $total_year_cost += $row['cost'];
        $total_year_extra += $row['extra'];
        $total_year_overall += $row['total'];

        // 2. Calcoli Mensili
        $month_key = date('Y-m', strtotime($row['event_date']));
        if (!isset($monthly_stats[$month_key])) {
            $monthly_stats[$month_key] = [
                'label' => date('F Y', strtotime($row['event_date'])),
                'events_count' => 0,
                'total' => 0
            ];
        }
        // Incrementiamo il contatore eventi solo se è un nuovo evento (univoco per data e preventivo)
        $unique_event_key = $row['event_date'] . '_' . $row['quote_id']; // Modificato per garantire l'ordinamento
        
        $monthly_stats[$month_key]['total'] += $row['total'];

        // 3. Raggruppamento per Visualizzazione (Chiave: Data + QuoteID)
        // Usiamo questa chiave per accorpare più ruoli nello stesso evento
        if (!isset($grouped_events[$unique_event_key])) {
            $grouped_events[$unique_event_key] = [
                'date' => $row['event_date'],
                'time' => $row['event_time'],
                'client' => $row['company_name'] ?: trim($row['first_name'] . ' ' . $row['last_name']),
                'location' => $row['event_location'],
                'package' => $row['package_name'],
                'quote_number' => $row['quote_number'],
                'roles' => [], // Qui metteremo i ruoli multipli
                'event_total' => 0
            ];
            // Incremento contatore eventi mensile solo quando creo il gruppo
            $monthly_stats[$month_key]['events_count']++;
        }

        // Aggiungo il ruolo alla lista dell'evento
        $grouped_events[$unique_event_key]['roles'][] = [
            'role_name' => $row['role_name'],
            'cost' => $row['cost'],
            'extra' => $row['extra'],
            'total' => $row['total'],
            'notes' => $row['notes']
        ];
        
        $grouped_events[$unique_event_key]['event_total'] += $row['total'];
    }
}

// RIORDINA L'ARRAY FINALE PER SICUREZZA
ksort($grouped_events, SORT_STRING);
ksort($monthly_stats, SORT_STRING); // Ordina anche le statistiche mensili

// Export CSV (Semplificato per lista piatta)
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Staff_' . preg_replace('/[^A-Za-z0-9]/', '', $staff_info['last_name']) . '_' . $current_year . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['Data', 'Cliente', 'Evento', 'Ruolo', 'Base', 'Extra', 'Totale', 'Note'], ';');
    
    // Rieseguo query o uso array, qui uso array raggruppato appiattendolo
    foreach ($grouped_events as $ev) {
        foreach ($ev['roles'] as $role) {
            fputcsv($output, [
                date('d/m/Y', strtotime($ev['date'])),
                $ev['client'],
                $ev['package'],
                $role['role_name'],
                number_format($role['cost'], 2, ',', ''),
                number_format($role['extra'], 2, ',', ''),
                number_format($role['total'], 2, ',', ''),
                $role['notes']
            ], ';');
        }
    }
    fclose($output);
    exit;
}

$pageTitle = 'Dettaglio Staff: ' . $staff_info['first_name'];
include '../includes/header.php';
?>

<div class="container-fluid py-4 bg-light">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="analytics.php?year=<?php echo $current_year; ?>" class="text-decoration-none text-muted small mb-1">
                <i class="bi bi-arrow-left"></i> Analytics
            </a>
            <h2 class="fw-bold text-dark mb-0">
                <i class="bi bi-person-badge-fill text-success me-2"></i>
                <?php echo e($staff_info['first_name'] . ' ' . $staff_info['last_name']); ?>
            </h2>
        </div>
        
        <div class="d-flex gap-2">
            <form method="GET" class="card shadow-sm border-0 px-2 py-1 d-flex justify-content-center">
                <input type="hidden" name="staff_id" value="<?php echo $staff_id; ?>">
                <select name="year" class="form-select form-select-sm border-0 fw-bold text-primary" onchange="this.form.submit()">
                    <?php 
                    $curr = date('Y');
                    for ($y = $curr + 1; $y >= 2024; $y--) {
                        $selected = ($y == $current_year) ? 'selected' : '';
                        echo "<option value='$y' $selected>$y</option>";
                    }
                    ?>
                </select>
            </form>
            
            <a href="?staff_id=<?php echo $staff_id; ?>&year=<?php echo $current_year; ?>&start_date=<?php echo $filter_start_date; ?>&end_date=<?php echo $filter_end_date; ?>&export=csv" 
               class="btn btn-success shadow-sm">
                <i class="bi bi-file-earmark-excel"></i> CSV
            </a>
        </div>
    </div>

    <!-- Cards Totali -->
    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
                <div class="card-body">
                    <h6 class="text-muted text-uppercase small fw-bold mb-2">Guadagno Totale</h6>
                    <h2 class="mb-0 fw-bold text-success"><?php echo formatPrice($total_year_overall); ?></h2>
                    <div class="small mt-1 text-muted">
                        Base: <?php echo formatPrice($total_year_cost); ?> + Extra: <?php echo formatPrice($total_year_extra); ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <!-- TABELLA MESI -->
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-calendar3"></i> Riepilogo Mensile</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0 text-center small">
                            <thead class="bg-light">
                                <tr>
                                    <?php foreach ($monthly_stats as $stat): 
                                        // Traduzione mese veloce
                                        $mesi_it = ['January'=>'Gen','February'=>'Feb','March'=>'Mar','April'=>'Apr','May'=>'Mag','June'=>'Giu','July'=>'Lug','August'=>'Ago','September'=>'Set','October'=>'Ott','November'=>'Nov','December'=>'Dic'];
                                        $mese_en = explode(' ', $stat['label'])[0];
                                        $mese = $mesi_it[$mese_en] ?? $mese_en;
                                    ?>
                                    <th><?php echo $mese; ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <?php foreach ($monthly_stats as $stat): ?>
                                    <td class="fw-bold <?php echo $stat['total'] > 0 ? 'text-dark' : 'text-muted'; ?>">
                                        <?php echo $stat['total'] > 0 ? formatPrice($stat['total']) : '-'; ?>
                                    </td>
                                    <?php endforeach; ?>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TABELLA DETTAGLIATA RAGGRUPPATA -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold"><i class="bi bi-list-check me-2"></i> Dettaglio Attività</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th class="ps-4" style="width: 15%;">Evento</th>
                            <th style="width: 30%;">Info Cliente</th>
                            <th style="width: 55%;">Dettaglio Ruoli</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($grouped_events)): ?>
                            <tr>
                                <td colspan="3" class="text-center py-5 text-muted">
                                    Nessuna attività trovata per questo periodo.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($grouped_events as $event): ?>
                            <tr>
                                <!-- Colonna 1: Data e Ora -->
                                <td class="ps-4 bg-white">
                                    <div class="fw-bold text-dark fs-6"><?php echo date('d/m/Y', strtotime($event['date'])); ?></div>
                                    <div class="text-muted small"><i class="bi bi-clock"></i> <?php echo substr($event['time'], 0, 5); ?></div>
                                </td>

                                <!-- Colonna 2: Cliente e Location -->
                                <td class="bg-white">
                                    <div class="fw-bold text-primary"><?php echo e($event['client']); ?></div>
                                    <div class="small text-muted mb-1"><?php echo e($event['package']); ?></div>
                                    <div class="small text-secondary"><i class="bi bi-geo-alt"></i> <?php echo e($event['location']); ?></div>
                                    <div class="badge bg-light text-dark border mt-1">#<?php echo $event['quote_number']; ?></div>
                                </td>

                                <!-- Colonna 3: Lista Ruoli (Nested Table per allineamento perfetto) -->
                                <td class="p-0">
                                    <table class="table table-sm table-borderless mb-0 w-100">
                                        <?php foreach ($event['roles'] as $idx => $role): ?>
                                        <tr class="<?php echo $idx > 0 ? 'border-top' : ''; ?>">
                                            <td class="ps-3 align-middle" style="width: 30%;">
                                                <span class="badge bg-info bg-opacity-10 text-dark border border-info">
                                                    <?php echo e($role['role_name']); ?>
                                                </span>
                                            </td>
                                            <td class="align-middle text-end text-muted small" style="width: 20%;">
                                                Base: <?php echo formatPrice($role['cost']); ?>
                                            </td>
                                            <td class="align-middle text-end text-muted small" style="width: 20%;">
                                                Extra: <?php echo formatPrice($role['extra']); ?>
                                            </td>
                                            <td class="align-middle text-end pe-3 fw-bold text-success" style="width: 20%;">
                                                <?php echo formatPrice($role['total']); ?>
                                            </td>
                                        </tr>
                                        <?php if(!empty($role['notes'])): ?>
                                        <tr>
                                            <td colspan="4" class="ps-3 pb-2 pt-0 small text-muted fst-italic">
                                                Note: <?php echo e($role['notes']); ?>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php endforeach; ?>
                                        
                                        <!-- Totale Evento -->
                                        <?php if (count($event['roles']) > 1): ?>
                                        <tr class="border-top bg-light">
                                            <td colspan="3" class="text-end fw-bold small py-2">TOTALE EVENTO:</td>
                                            <td class="text-end fw-bold text-success pe-3 py-2">
                                                <?php echo formatPrice($event['event_total']); ?>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
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

<?php include '../includes/footer.php'; ?>