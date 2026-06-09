<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/functions.php';

requireAdmin();

$db = getDBConnection();

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$current_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$start_date = "$current_year-01-01";
$end_date = "$current_year-12-31";

// Recupero info Venditore
$user_query = "SELECT * FROM users WHERE id = $user_id";
$user_result = mysqli_query($db, $user_query);

if (!$user_result || mysqli_num_rows($user_result) === 0) {
    echo "<div class='container py-5'><div class='alert alert-danger'>Venditore non trovato.</div><a href='analytics.php' class='btn btn-secondary'>Indietro</a></div>";
    exit;
}
$user_info = mysqli_fetch_assoc($user_result);

// Query Preventivi Confermati per questo venditore
// Selezioniamo solo quelli che hanno eventi nell'anno corrente
$detail_query = "
    SELECT 
        q.id, q.quote_number, q.created_at, 
        COALESCE(q.invoice_amount, 0) as invoice_amount, 
        COALESCE(q.extra_amount, 0) as extra_amount,
        COALESCE(q.commercial_commission, 0) as commission,
        c.company_name, c.first_name, c.last_name,
        MIN(qi.event_date) as first_event_date
    FROM quotes q
    JOIN clients c ON q.client_id = c.id
    JOIN quote_items qi ON q.id = qi.quote_id
    WHERE q.created_by = $user_id
    AND q.status = 'confermato'
    AND qi.event_date BETWEEN '$start_date' AND '$end_date'
    GROUP BY q.id
    ORDER BY first_event_date DESC
";

$result = mysqli_query($db, $detail_query);
$quotes = [];
$total_revenue = 0;
$total_commissions = 0;

if ($result) {
    while($row = mysqli_fetch_assoc($result)){
        // Calcolo totale per il preventivo
        $row['total_imponibile'] = $row['invoice_amount'] + $row['extra_amount'];
        
        $quotes[] = $row;
        $total_revenue += $row['total_imponibile'];
        $total_commissions += $row['commission'];
    }
}

$pageTitle = 'Dettaglio Venditore';
include '../includes/header.php';
?>

<div class="container-fluid py-4 bg-light">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="analytics.php?year=<?php echo $current_year; ?>" class="text-decoration-none text-muted small"><i class="bi bi-arrow-left"></i> Analytics</a>
            <h2 class="fw-bold text-warning mb-0"><i class="bi bi-briefcase-fill me-2"></i> <?php echo e($user_info['first_name'] . ' ' . $user_info['last_name']); ?></h2>
            <p class="text-muted mb-0">Vendite Anno <?php echo $current_year; ?></p>
        </div>
        
        <div class="d-flex gap-3">
            <div class="bg-white p-2 px-3 rounded shadow-sm border text-end">
                <div class="text-uppercase small fw-bold text-muted">Fatturato Imponibile</div>
                <div class="h4 fw-bold text-primary mb-0"><?php echo formatPrice($total_revenue); ?></div>
            </div>
            <div class="bg-white p-2 px-3 rounded shadow-sm border text-end border-warning border-2">
                <div class="text-uppercase small fw-bold text-muted">Provvigioni Totali</div>
                <div class="h4 fw-bold text-warning mb-0 text-dark"><?php echo formatPrice($total_commissions); ?></div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-file-earmark-check me-2"></i> Preventivi Confermati</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">Data Evento</th>
                            <th>N° Preventivo</th>
                            <th>Cliente</th>
                            <th class="text-end">Imponibile</th>
                            <th class="text-end pe-4">Provvigione</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($quotes)): ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">Nessuna vendita confermata nel periodo.</td></tr>
                        <?php else: ?>
                            <?php foreach($quotes as $q): 
                                $client = $q['company_name'] ?: trim($q['first_name'].' '.$q['last_name']);
                            ?>
                            <tr>
                                <td class="ps-4 text-dark fw-bold"><?php echo date('d/m/Y', strtotime($q['first_event_date'])); ?></td>
                                <td>
                                    <a href="../quotes.php?id=<?php echo $q['id']; ?>" class="text-primary text-decoration-none fw-bold">
                                        <?php echo e($q['quote_number']); ?> <i class="bi bi-box-arrow-up-right small"></i>
                                    </a>
                                </td>
                                <td><?php echo e($client); ?></td>
                                <td class="text-end fw-bold text-primary"><?php echo formatPrice($q['total_imponibile']); ?></td>
                                <td class="text-end pe-4 fw-bold text-warning text-dark"><?php echo formatPrice($q['commission']); ?></td>
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