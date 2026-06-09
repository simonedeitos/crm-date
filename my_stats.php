<?php
// DEBUG MODE
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'auth.php';
require_once 'functions.php';

requireCommerciale(); // Solo commerciali possono accedere

$db = getDBConnection();
$user_id = $_SESSION['user_id'];

// Filtri
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : null; // null = tutto l'anno

// Ottieni info utente
$user_query = mysqli_query($db, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);

/**
 * LOGICA DATA EVENTO:
 * Usiamo una subquery (o una join logica) per determinare la data di riferimento per ogni preventivo.
 * La data di riferimento è:
 * 1. La data minima presente in quote_items (event_date)
 * 2. Se null, la data minima presente in quote_dates (tabella legacy se usata)
 * 3. Se null, la data di creazione del preventivo (created_at)
 */

// Costruiamo una vista temporanea virtuale per le date
$date_reference_sql = "
    COALESCE(
        (SELECT MIN(event_date) FROM quote_items WHERE quote_id = q.id AND event_date IS NOT NULL),
        (SELECT MIN(event_date) FROM quote_dates WHERE quote_id = q.id),
        q.created_at
    )
";

// Condizioni WHERE base
$where_conditions = ["q.status = 'confermato'", "q.created_by = $user_id"];

// Filtro Anno (sulla data evento calcolata)
$where_conditions[] = "YEAR($date_reference_sql) = $selected_year";

// Filtro Mese (sulla data evento calcolata)
if ($selected_month) {
    $where_conditions[] = "MONTH($date_reference_sql) = $selected_month";
}

$where_sql = implode(" AND ", $where_conditions);

// STATISTICHE GENERALI
$stats_query = "SELECT 
    COUNT(*) as total_quotes,
    SUM(q.total) as total_revenue_no_iva,
    SUM(q.total_with_iva) as total_revenue_with_iva,
    SUM(q.commercial_commission) as total_commission,
    AVG(q.total) as avg_quote_value
FROM quotes q
WHERE $where_sql";

$stats_result = mysqli_query($db, $stats_query);
if (!$stats_result) {
    die("Errore query stats: " . mysqli_error($db));
}
$stats = mysqli_fetch_assoc($stats_result);

// PREVENTIVI PER STATO (Qui manteniamo la creazione o cambiamo logica? Di solito lo stato si guarda sul totale. 
// Per coerenza con il resto, filtriamo anche qui per Data Evento, ma solo per quelli confermati ha senso.
// Per vedere il lavoro svolto, forse è meglio vedere TUTTI i preventivi dell'anno basandosi sulla creazione?
// Manteniamo la logica 'data evento' per coerenza con la revenue visualizzata sopra)

// NOTA: Se un preventivo non è confermato, spesso non ha una data evento. 
// Quindi per il grafico 'Per Stato' usiamo ancora created_at per bozze/inviati, 
// ma per 'Confermato' usiamo la logica event_date. Questo è complesso in SQL puro.
// Semplifichiamo: Il grafico 'Per Stato' mostra il volume di lavoro GENERATO nell'anno (created_at).
$status_query = "SELECT 
    status,
    COUNT(*) as count,
    SUM(total) as revenue
FROM quotes
WHERE created_by = $user_id AND YEAR(created_at) = $selected_year
GROUP BY status";

$status_result = mysqli_query($db, $status_query);
$by_status = [];
while ($row = mysqli_fetch_assoc($status_result)) {
    $by_status[$row['status']] = $row;
}

// PREVENTIVI CONFERMATI PER MESE (per grafico REVENUE)
// Qui usiamo rigorosamente la data evento
$monthly_query = "SELECT 
    MONTH($date_reference_sql) as month,
    COUNT(*) as count,
    SUM(q.total) as revenue,
    SUM(q.commercial_commission) as commission
FROM quotes q
WHERE q.created_by = $user_id 
  AND q.status = 'confermato' 
  AND YEAR($date_reference_sql) = $selected_year
GROUP BY MONTH($date_reference_sql)
ORDER BY month";

$monthly_result = mysqli_query($db, $monthly_query);
$monthly_data = array_fill(1, 12, ['count' => 0, 'revenue' => 0, 'commission' => 0]);
while ($row = mysqli_fetch_assoc($monthly_result)) {
    $monthly_data[$row['month']] = $row;
}

// DETTAGLIO PREVENTIVI CONFERMATI (Tabella in basso)
$details_query = "SELECT 
    q.*,
    c.company_name,
    c.first_name,
    c.last_name,
    $date_reference_sql as computed_event_date
FROM quotes q
JOIN clients c ON q.client_id = c.id
WHERE $where_sql
ORDER BY computed_event_date ASC";

$details_result = mysqli_query($db, $details_query);
$quote_details = [];
while ($row = mysqli_fetch_assoc($details_result)) {
    $quote_details[] = $row;
}

// TOP 5 CLIENTI (per revenue su data evento)
$top_clients_query = "SELECT 
    c.id,
    c.company_name,
    c.first_name,
    c.last_name,
    COUNT(q.id) as quote_count,
    SUM(q.total) as total_revenue
FROM quotes q
JOIN clients c ON q.client_id = c.id
WHERE q.created_by = $user_id 
  AND q.status = 'confermato' 
  AND YEAR($date_reference_sql) = $selected_year
GROUP BY c.id
ORDER BY total_revenue DESC
LIMIT 5";

$top_clients_result = mysqli_query($db, $top_clients_query);
$top_clients = [];
while ($row = mysqli_fetch_assoc($top_clients_result)) {
    $top_clients[] = $row;
}

// ANNI DISPONIBILI per filtro (Basato su data evento dei confermati + data creazione per gli altri)
// Per semplicità nel filtro, prendiamo gli anni in cui ci sono preventivi confermati (per evento)
$years_result = mysqli_query($db, "
    SELECT DISTINCT YEAR($date_reference_sql) as year 
    FROM quotes q 
    WHERE q.created_by = $user_id AND q.status = 'confermato'
    UNION 
    SELECT DISTINCT YEAR(created_at) as year
    FROM quotes 
    WHERE created_by = $user_id
    ORDER BY year DESC
");
$available_years = [];
while ($row = mysqli_fetch_assoc($years_result)) {
    if ($row['year'] > 0) { // Filtra anni nulli o zero
        $available_years[] = $row['year'];
    }
}
// Fallback se array vuoto
if (empty($available_years)) {
    $available_years[] = date('Y');
}

$pageTitle = 'Le Mie Statistiche';
include 'includes/header.php';
?>

<style>
.stat-card {
    border-left: 4px solid;
    transition: transform 0.2s;
}
.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
.stat-number {
    font-size: 2rem;
    font-weight: bold;
}
.chart-container {
    position: relative;
    height: 300px;
}
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="bi bi-graph-up"></i> Le Mie Statistiche</h2>
            <p class="text-muted mb-0">Commerciale: <strong><?php echo e($user['username']); ?></strong></p>
        </div>
        
        <!-- Filtri -->
        <form method="GET" class="d-flex gap-2">
            <select name="year" class="form-select form-select-sm" style="min-width: 100px;" onchange="this.form.submit()">
                <?php foreach ($available_years as $year): ?>
                <option value="<?php echo $year; ?>" <?php echo $year == $selected_year ? 'selected' : ''; ?>>
                    <?php echo $year; ?>
                </option>
                <?php endforeach; ?>
            </select>
            
            <select name="month" class="form-select form-select-sm" style="min-width: 180px;" onchange="this.form.submit()">
                <option value="">Tutto l'anno</option>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?php echo $m; ?>" <?php echo $m == $selected_month ? 'selected' : ''; ?>>
                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                </option>
                <?php endfor; ?>
            </select>
            
            <?php if ($selected_month): ?>
            <a href="?year=<?php echo $selected_year; ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-x"></i> Reset
            </a>
            <?php endif; ?>
        </form>
    </div>
    
    <!-- PERIODO SELEZIONATO -->
    <div class="alert alert-info mb-4">
        <i class="bi bi-calendar-check"></i>
        <strong>Periodo Analisi (Data Evento):</strong> 
        <?php 
        if ($selected_month) {
            echo date('F', mktime(0, 0, 0, $selected_month, 1)) . " $selected_year";
        } else {
            echo "Anno $selected_year";
        }
        ?>
    </div>
    
    <!-- KPI CARDS -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card stat-card border-primary">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1"><small>Preventivi Confermati</small></p>
                            <h3 class="stat-number text-primary mb-0"><?php echo $stats['total_quotes'] ?? 0; ?></h3>
                        </div>
                        <i class="bi bi-check-circle-fill text-primary" style="font-size: 2.5rem; opacity: 0.3;"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card stat-card border-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1"><small>Revenue (Data Evento)</small></p>
                            <h3 class="stat-number text-success mb-0"><?php echo formatPrice($stats['total_revenue_no_iva'] ?? 0); ?></h3>
                        </div>
                        <i class="bi bi-cash-stack text-success" style="font-size: 2.5rem; opacity: 0.3;"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card stat-card border-warning">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1"><small>Provvigioni Totali</small></p>
                            <h3 class="stat-number text-warning mb-0"><?php echo formatPrice($stats['total_commission'] ?? 0); ?></h3>
                        </div>
                        <i class="bi bi-coin text-warning" style="font-size: 2.5rem; opacity: 0.3;"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card stat-card border-info">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1"><small>Valore Medio Preventivo</small></p>
                            <h3 class="stat-number text-info mb-0"><?php echo formatPrice($stats['avg_quote_value'] ?? 0); ?></h3>
                        </div>
                        <i class="bi bi-calculator text-info" style="font-size: 2.5rem; opacity: 0.3;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- PREVENTIVI PER STATO -->
    <div class="row mb-4">
        <div class="col-md-8">
            <!-- GRAFICO MENSILE -->
            <div class="card h-100">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0"><i class="bi bi-bar-chart"></i> Revenue per Mese Evento (<?php echo $selected_year; ?>)</h6>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <!-- BREAKDOWN PER STATO -->
            <div class="card mb-3">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0"><i class="bi bi-pie-chart"></i> Preventivi Creati <?php echo $selected_year; ?></h6>
                </div>
                <div class="card-body">
                    <small class="text-muted d-block mb-2">Basato su data creazione</small>
                    <?php
                    $status_labels = [
                        'bozza' => ['label' => 'Bozza', 'color' => 'secondary'],
                        'inviato' => ['label' => 'Inviati', 'color' => 'info'],
                        'accettato' => ['label' => 'Accettati', 'color' => 'warning'],
                        'confermato' => ['label' => 'Confermati', 'color' => 'success']
                    ];
                    
                    foreach ($status_labels as $status => $info):
                        $data = $by_status[$status] ?? ['count' => 0, 'revenue' => 0];
                    ?>
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                        <div>
                            <span class="badge bg-<?php echo $info['color']; ?>"><?php echo $info['label']; ?></span>
                        </div>
                        <h4 class="mb-0 text-<?php echo $info['color']; ?>"><?php echo $data['count']; ?></h4>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- TOP CLIENTI -->
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h6 class="mb-0"><i class="bi bi-trophy"></i> Top 5 Clienti (Evento <?php echo $selected_year; ?>)</h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($top_clients)): ?>
                    <p class="text-center text-muted py-3 mb-0">Nessun dato disponibile</p>
                    <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($top_clients as $idx => $client): 
                            $client_name = $client['company_name'] ?: trim($client['first_name'] . ' ' . $client['last_name']);
                        ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <span class="badge bg-warning text-dark me-2">#<?php echo $idx + 1; ?></span>
                                <strong><?php echo e($client_name); ?></strong>
                                <br>
                                <small class="text-muted"><?php echo $client['quote_count']; ?> eventi</small>
                            </div>
                            <h6 class="mb-0 text-success"><?php echo formatPrice($client['total_revenue']); ?></h6>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- DETTAGLIO PREVENTIVI CON PROVVIGIONI -->
    <div class="card">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-table"></i> Dettaglio Eventi Confermati</h6>
            <button class="btn btn-sm btn-light" onclick="exportToExcel()">
                <i class="bi bi-file-earmark-excel"></i> Esporta Excel
            </button>
        </div>
        <div class="card-body p-0">
            <?php if (empty($quote_details)): ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox" style="font-size: 3rem; color: #ccc;"></i>
                <p class="text-muted mt-3">Nessun evento confermato nel periodo selezionato</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="quotesTable">
                    <thead class="table-light">
                        <tr>
                            <th>Data Evento</th>
                            <th>N° Preventivo</th>
                            <th>Cliente</th>
                            <th>Data Creazione</th>
                            <th class="text-end">Totale (no IVA)</th>
                            <th class="text-end">Provvigione</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quote_details as $q): 
                            $client_name = $q['company_name'] ?: trim($q['first_name'] . ' ' . $q['last_name']);
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo formatDate($q['computed_event_date']); ?></strong>
                            </td>
                            <td><?php echo e($q['quote_number']); ?></td>
                            <td><?php echo e($client_name); ?></td>
                            <td><small class="text-muted"><?php echo formatDate($q['created_at']); ?></small></td>
                            <td class="text-end"><strong><?php echo formatPrice($q['total']); ?></strong></td>
                            <td class="text-end">
                                <strong class="text-success"><?php echo formatPrice($q['commercial_commission']); ?></strong>
                            </td>
                            <td>
                                <a href="quotes.php?id=<?php echo $q['id']; ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <th colspan="4" class="text-end">TOTALI PERIODO:</th>
                            <th class="text-end"><?php echo formatPrice($stats['total_revenue_no_iva']); ?></th>
                            <th class="text-end text-warning"><?php echo formatPrice($stats['total_commission']); ?></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Dati per grafico mensile
const monthlyData = <?php echo json_encode(array_values($monthly_data)); ?>;
const monthLabels = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];

// Grafico andamento mensile
const ctx = document.getElementById('monthlyChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: monthLabels,
        datasets: [
            {
                label: 'Revenue (€)',
                data: monthlyData.map(m => m.revenue),
                backgroundColor: 'rgba(25, 135, 84, 0.7)',
                borderColor: 'rgba(25, 135, 84, 1)',
                borderWidth: 1,
                yAxisID: 'y'
            },
            {
                label: 'Provvigioni (€)',
                data: monthlyData.map(m => m.commission),
                backgroundColor: 'rgba(255, 193, 7, 0.7)',
                borderColor: 'rgba(255, 193, 7, 1)',
                borderWidth: 1,
                yAxisID: 'y'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '€' + value.toLocaleString('it-IT');
                    }
                }
            }
        },
        plugins: {
            legend: {
                position: 'top',
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': €' + context.parsed.y.toLocaleString('it-IT', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    }
                }
            }
        }
    }
});

// Funzione esportazione Excel
function exportToExcel() {
    const table = document.getElementById('quotesTable');
    let csv = [];
    
    // Headers
    const headers = [];
    table.querySelectorAll('thead th').forEach(th => {
        headers.push(th.textContent.trim());
    });
    csv.push(headers.join(';'));
    
    // Rows
    table.querySelectorAll('tbody tr').forEach(tr => {
        const row = [];
        tr.querySelectorAll('td').forEach((td, idx) => {
            if (idx < headers.length - 1) { // Escludi ultima colonna (azioni)
                row.push('"' + td.textContent.trim().replace(/"/g, '""') + '"');
            }
        });
        csv.push(row.join(';'));
    });
    
    // Download
    const csvContent = '\uFEFF' + csv.join('\n'); // UTF-8 BOM
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'statistiche_evento_<?php echo $selected_year . ($selected_month ? "_" . str_pad($selected_month, 2, "0", STR_PAD_LEFT) : ""); ?>.csv';
    link.click();
}
</script>

<?php include 'includes/footer.php'; ?>