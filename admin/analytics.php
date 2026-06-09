<?php
require_once '../config.php';
require_once '../auth.php';
require_once '../functions.php';

requireAdmin();

$db = getDBConnection();

// --- GESTIONE FILTRI ---
$current_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$start_date_year = "$current_year-01-01";
$end_date_year = "$current_year-12-31";

// -----------------------------------------------------------------------------
// 1. ANALISI MENSILE (Basata su Invoice + Extra Amount)
// -----------------------------------------------------------------------------
// Logica Corretta e Robusta:
// 1. Prova a distribuire il revenue in modo proporzionale (se subtotal > 0).
// 2. Se il subtotal è 0, distribuisce il revenue in modo equo tra tutti gli item di quel preventivo.
// 3. Raggruppa per mese dell'evento.
$monthly_query = "
    SELECT 
        MONTH(qi.event_date) as month,
        SUM(
            CASE 
                -- Se subtotal è valido (>0), usa la logica proporzionale
                WHEN COALESCE(q.subtotal, 0) > 0 THEN 
                    (COALESCE(q.invoice_amount, 0) + COALESCE(q.extra_amount, 0)) * (qi.price / q.subtotal)
                -- Altrimenti (subtotal è 0 o NULL), dividi equamente il totale tra gli item di quel preventivo
                ELSE 
                    (COALESCE(q.invoice_amount, 0) + COALESCE(q.extra_amount, 0)) / (SELECT COUNT(*) FROM quote_items WHERE quote_id = q.id)
            END
        ) as revenue,
        COUNT(DISTINCT qi.id) as events_count
    FROM quote_items qi
    JOIN quotes q ON qi.quote_id = q.id
    WHERE 
        q.status = 'confermato'
    AND qi.event_date BETWEEN '$start_date_year' AND '$end_date_year'
    GROUP BY MONTH(qi.event_date)
    ORDER BY month ASC;
";
$monthly_result = mysqli_query($db, $monthly_query);

$monthly_data = array_fill(1, 12, ['revenue' => 0, 'events' => 0]);
$total_revenue_year = 0;
$total_events_year = 0;
$max_revenue = 0;

if ($monthly_result) {
    while ($row = mysqli_fetch_assoc($monthly_result)) {
        $m = (int)$row['month'];
        $rev = (float)$row['revenue'];
        
        $monthly_data[$m]['revenue'] += $rev;
        $monthly_data[$m]['events'] += (int)$row['events_count'];
        
        $total_revenue_year += $rev;
        $total_events_year += (int)$row['events_count'];
        
        if ($monthly_data[$m]['revenue'] > $max_revenue) $max_revenue = $monthly_data[$m]['revenue'];
    }
} else {
    // Gestisci l'errore, se necessario
    // error_log("Errore query analytics: " . mysqli_error($db));
}


// -----------------------------------------------------------------------------
// 2. PERFORMANCE STAFF (CORRETTO: Conta Eventi Unici, non Ruoli)
// -----------------------------------------------------------------------------
// COUNT(DISTINCT qsa.quote_item_id) assicura che se ho 3 ruoli nello stesso item, conto 1.
$staff_query = "
    SELECT 
        s.id, s.first_name, s.last_name,
        COUNT(DISTINCT qsa.quote_item_id) as jobs_count,
        SUM(qsa.cost + qsa.extra) as total_earnings
    FROM staff s
    LEFT JOIN quote_staff_assignment qsa ON s.id = qsa.staff_id
    LEFT JOIN quote_items qi ON qsa.quote_item_id = qi.id
    WHERE s.is_active = 1
    AND (qi.event_date BETWEEN '$start_date_year' AND '$end_date_year' OR qi.event_date IS NULL)
    GROUP BY s.id
    HAVING total_earnings > 0 OR jobs_count > 0
    ORDER BY total_earnings DESC
";
$staff_result = mysqli_query($db, $staff_query);
$staff_stats = [];
if ($staff_result) {
    while ($row = mysqli_fetch_assoc($staff_result)) {
        $staff_stats[] = $row;
    }
}

// -----------------------------------------------------------------------------
// 3. PERFORMANCE VENDITORI (Aggiornato con Invoice/Extra/Commission)
// -----------------------------------------------------------------------------
// Calcola il fatturato (Invoice+Extra) e le provvigioni (commercial_commission)
$sales_query = "
    SELECT 
        u.id, u.username, u.first_name, u.last_name,
        COUNT(DISTINCT q.id) as confirmed_quotes,
        SUM(COALESCE(q.invoice_amount, 0) + COALESCE(q.extra_amount, 0)) as total_revenue,
        SUM(COALESCE(q.commercial_commission, 0)) as total_commission
    FROM users u
    JOIN quotes q ON u.id = q.created_by
    WHERE q.status = 'confermato'
    -- Filtra solo i preventivi che hanno almeno un evento nell'anno
    AND EXISTS (
        SELECT 1 FROM quote_items qi 
        WHERE qi.quote_id = q.id 
        AND qi.event_date BETWEEN '$start_date_year' AND '$end_date_year'
    )
    GROUP BY u.id
    ORDER BY total_revenue DESC
";
$sales_result = mysqli_query($db, $sales_query);
$sales_stats = [];
if ($sales_result) {
    while ($row = mysqli_fetch_assoc($sales_result)) {
        $sales_stats[] = $row;
    }
}

$pageTitle = 'Analytics';
include '../includes/header.php';
?>

<style>
    .chart-bar {
        background: linear-gradient(180deg, #0d6efd 0%, #0a58ca 100%);
        border-radius: 4px 4px 0 0;
        transition: height 0.6s ease-out;
        min-height: 2px;
    }
    .chart-bar:hover { opacity: 0.8; }
    .table-compact td, .table-compact th { padding: 0.5rem 0.5rem; font-size: 0.85rem; }
    .month-col { width: 8.33%; text-align: center; }
</style>

<div class="container-fluid py-4 bg-light">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-0 text-dark"><i class="bi bi-graph-up-arrow text-primary"></i> Analytics</h2>
            <small class="text-muted">Report Finanziario <?php echo $current_year; ?></small>
        </div>
        
        <form method="GET" class="card shadow-sm border-0 d-flex flex-row align-items-center p-2">
            <label class="me-2 fw-bold text-muted small text-uppercase">Anno:</label>
            <select name="year" class="form-select form-select-sm border-0 fw-bold text-primary bg-light" style="width: auto;" onchange="this.form.submit()">
                <?php 
                for ($y = date('Y'); $y >= 2024; $y--) {
                    $sel = ($y == $current_year) ? 'selected' : '';
                    echo "<option value='$y' $sel>$y</option>";
                }
                ?>
            </select>
        </form>
    </div>

    <!-- KPI Totali -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100 bg-primary text-white">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-white-50 text-uppercase small fw-bold mb-1">Imponibile Totale</h6>
                        <h2 class="mb-0 fw-bold"><?php echo formatPrice($total_revenue_year); ?></h2>
                        <small class="text-white-50">(Fattura + Extra)</small>
                    </div>
                    <i class="bi bi-wallet2 fs-1 text-white-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100 bg-white">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted text-uppercase small fw-bold mb-1">Eventi Confermati</h6>
                        <h2 class="mb-0 fw-bold text-dark"><?php echo $total_events_year; ?></h2>
                    </div>
                    <i class="bi bi-calendar-check fs-1 text-muted opacity-25"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- SEZIONE SUPERIORE: GRAFICO + TABELLA MESI -->
    <div class="row g-4 mb-4">
        <!-- GRAFICO (Sinistra) -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-bar-chart-fill"></i> Andamento Imponibile</h6>
                </div>
                <div class="card-body d-flex align-items-end justify-content-around pb-0" style="height: 300px;">
                    <?php 
                    $mesi_short = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];
                    foreach($monthly_data as $m => $data): 
                        $height = $max_revenue > 0 ? ($data['revenue'] / $max_revenue * 100) : 0;
                        $display_height = $data['revenue'] > 0 ? max($height, 2) : 1; 
                        $bg_color = $data['revenue'] > 0 ? '' : 'background-color: #f1f3f5; box-shadow: none;';
                    ?>
                    <div class="d-flex flex-column align-items-center" style="width: 7%; height: 100%;">
                        <div class="mb-auto w-100 d-flex flex-column justify-content-end h-100">
                            <?php if($data['revenue'] > 0): ?>
                            <div class="small fw-bold text-dark text-center mb-1" style="font-size: 0.65rem;">
                                <?php echo ($data['revenue'] >= 1000) ? round($data['revenue']/1000, 1).'k' : (int)$data['revenue']; ?>
                            </div>
                            <?php endif; ?>
                            <div class="chart-bar w-100 rounded-top" style="height: <?php echo $display_height; ?>%; <?php echo $bg_color; ?>" 
                                 title="<?php echo formatPrice($data['revenue']); ?>" data-bs-toggle="tooltip"></div>
                        </div>
                        <div class="text-muted small mt-2 fw-bold" style="font-size: 0.7rem;"><?php echo $mesi_short[$m-1]; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ELENCO MESI (Destra) -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-calendar3"></i> Dettaglio Mensile</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                        <table class="table table-hover table-striped table-compact mb-0">
                            <thead class="bg-light sticky-top">
                                <tr>
                                    <th class="ps-3">Mese</th>
                                    <th class="text-end">Imponibile</th>
                                    <th class="text-end pe-3">Eventi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($monthly_data as $m => $data): 
                                    $monthName = DateTime::createFromFormat('!m', $m)->format('F');
                                    $it_months = ['January'=>'Gennaio','February'=>'Febbraio','March'=>'Marzo','April'=>'Aprile','May'=>'Maggio','June'=>'Giugno','July'=>'Luglio','August'=>'Agosto','September'=>'Settembre','October'=>'Ottobre','November'=>'Novembre','December'=>'Dicembre'];
                                    $nome_mese = $it_months[$monthName] ?? $monthName;
                                ?>
                                <tr>
                                    <td class="ps-3 fw-bold text-secondary"><?php echo $nome_mese; ?></td>
                                    <td class="text-end fw-bold <?php echo $data['revenue'] > 0 ? 'text-dark' : 'text-muted'; ?>">
                                        <?php echo formatPrice($data['revenue']); ?>
                                    </td>
                                    <td class="text-end pe-3 font-monospace small"><?php echo $data['events'] > 0 ? $data['events'] : '-'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SEZIONE INFERIORE: STAFF + VENDITORI -->
    <div class="row g-4">
        
        <!-- STAFF (Sinistra) -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-success"><i class="bi bi-people-fill"></i> Staff (Costi)</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="bg-light sticky-top">
                                <tr>
                                    <th class="ps-3">Nominativo</th>
                                    <th class="text-center">Eventi</th>
                                    <th class="text-end pe-3">Totale</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($staff_stats)): ?>
                                <tr><td colspan="4" class="text-center py-3 text-muted">Nessun dato</td></tr>
                                <?php else: ?>
                                    <?php foreach($staff_stats as $staff): ?>
                                    <tr>
                                        <td class="ps-3 fw-bold text-dark">
                                            <?php echo e($staff['first_name'] . ' ' . $staff['last_name']); ?>
                                        </td>
                                        <td class="text-center"><?php echo $staff['jobs_count']; ?></td>
                                        <td class="text-end pe-3 fw-bold text-success"><?php echo formatPrice($staff['total_earnings']); ?></td>
                                        <td class="text-end pe-3">
                                            <a href="staff_detail.php?staff_id=<?php echo $staff['id']; ?>&year=<?php echo $current_year; ?>" 
                                               class="btn btn-xs btn-light border text-secondary" title="Dettaglio">
                                                <i class="bi bi-search"></i>
                                            </a>
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

        <!-- VENDITORI (Destra) -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-warning"><i class="bi bi-briefcase-fill"></i> Venditori (Ricavi)</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="bg-light sticky-top">
                                <tr>
                                    <th class="ps-3">Venditore</th>
                                    <th class="text-end">Imponibile</th>
                                    <th class="text-end pe-3">Provvigioni</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($sales_stats)): ?>
                                <tr><td colspan="4" class="text-center py-3 text-muted">Nessun dato</td></tr>
                                <?php else: ?>
                                    <?php foreach($sales_stats as $seller): ?>
                                    <tr>
                                        <td class="ps-3">
                                            <div class="fw-bold text-dark"><?php echo e($seller['first_name'] . ' ' . $seller['last_name']); ?></div>
                                        </td>
                                        <td class="text-end fw-bold text-primary">
                                            <?php echo formatPrice($seller['total_revenue']); ?>
                                        </td>
                                        <td class="text-end fw-bold text-warning pe-3 text-dark">
                                            <?php echo formatPrice($seller['total_commission']); ?>
                                        </td>
                                        <td class="text-end pe-3">
                                            <a href="commercial_detail.php?user_id=<?php echo $seller['id']; ?>&year=<?php echo $current_year; ?>" 
                                               class="btn btn-xs btn-light border text-secondary" title="Dettaglio">
                                                <i class="bi bi-search"></i>
                                            </a>
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

    </div>
</div>

<script>
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
      return new bootstrap.Tooltip(tooltipTriggerEl)
    })
</script>

<?php include '../includes/footer.php'; ?>