<?php
require_once '../config.php';
require_once '../auth.php';
require_once '../functions.php';

requireAdmin();

$db = getDBConnection();
$quote_id = (int)($_GET['quote_id'] ?? 0);

if (!$quote_id) die('ID preventivo mancante');

// Ottieni dati preventivo
$query = "SELECT q.*, c.company_name, c.first_name, c.last_name, c.phone, c.email, c.pec, 
          c.address, c.tax_code, c.vat_number, c.sdi_code,
          u.first_name as user_first_name, u.last_name as user_last_name
          FROM quotes q
          JOIN clients c ON q.client_id = c.id
          JOIN users u ON q.created_by = u.id
          WHERE q.id = $quote_id AND q.status = 'confermato'";

$result = mysqli_query($db, $query);
$quote = mysqli_fetch_assoc($result);

if (!$quote) die('Preventivo non trovato');

// Ottieni PACCHETTI con le loro date
$packages_result = mysqli_query($db, "SELECT * FROM quote_items WHERE quote_id = $quote_id ORDER BY event_date, sort_order");
$packages = [];
while ($row = mysqli_fetch_assoc($packages_result)) {
    $clean_name = preg_replace('/\[.*?\]/', '', $row['package_name']);
    $clean_name = trim($clean_name);
    if (!empty($clean_name)) {
        $row['clean_name'] = $clean_name;
        $packages[] = $row;
    }
}

if (empty($packages)) die('Nessun pacchetto trovato');

// Ottieni staff assegnato PER PACCHETTO
$staff_by_package = [];
foreach ($packages as $pkg) {
    $staff_result = mysqli_query($db, "SELECT qsa.*, s.first_name, s.last_name, sr.name as role_name
                                       FROM quote_staff_assignment qsa
                                       LEFT JOIN staff s ON qsa.staff_id = s.id
                                       LEFT JOIN staff_roles sr ON qsa.role_id = sr.id
                                       WHERE qsa.quote_item_id = {$pkg['id']}
                                       ORDER BY sr.name, s.last_name");
    $staff_by_package[$pkg['id']] = [];
    while ($row = mysqli_fetch_assoc($staff_result)) {
        $staff_by_package[$pkg['id']][] = $row;
    }
}

// Ottieni TUTTI i servizi per pacchetto (incluso agibilità)
$services_result = mysqli_query($db, "SELECT * FROM quote_service_costs WHERE quote_id = $quote_id ORDER BY service_name");
$all_services = [];
while ($row = mysqli_fetch_assoc($services_result)) {
    $all_services[] = $row;
}

// CALCOLA TOTALE GENERALE (Costo + Extra) di TUTTI i pacchetti per proporzione provvigione
$grand_total_all_packages = 0;
$package_totals = []; // Salva i totali per ogni pacchetto

foreach ($packages as $pkg) {
    $pkg_staff = $staff_by_package[$pkg['id']] ?? [];
    $pkg_total_cost = 0;
    $pkg_total_extra = 0;
    
    foreach ($pkg_staff as $p) {
        $pkg_total_cost += (float)$p['cost'];
        $pkg_total_extra += (float)$p['extra'];
    }
    
    // Aggiungi servizi di QUESTO PACCHETTO
    foreach ($all_services as $service) {
        if ($service['quote_item_id'] == $pkg['id']) {
            $pkg_total_cost += (float)$service['cost'];
            $pkg_total_extra += (float)$service['extra'];
        }
    }
    
    $package_totals[$pkg['id']] = $pkg_total_cost + $pkg_total_extra;
    $grand_total_all_packages += $package_totals[$pkg['id']];
}

// Formatta nome cliente
if ($quote['company_name']) {
    $client_name = $quote['company_name'];
} else {
    $client_name = trim($quote['first_name'] . ' ' . $quote['last_name']);
}

// Nome venditore
$seller_name = trim($quote['user_first_name'] . ' ' . $quote['user_last_name']);

$total_pages = count($packages);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Scheda Evento - <?php echo e($quote['quote_number']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 8.5pt; padding: 8mm; line-height: 1.3; }
        h1 { text-align: center; font-size: 15pt; margin-bottom: 12px; }
        h2 { font-size: 11pt; margin: 12px 0 6px 0; border-bottom: 2px solid #333; padding-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 8pt; }
        th, td { border: 1px solid #666; padding: 3px 5px; text-align: left; }
        th { background: #e0e0e0; font-weight: bold; }
        .section-title { background: #333; color: white; padding: 3px 6px; margin: 8px 0 4px 0; font-weight: bold; font-size: 9.5pt; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .bold { font-weight: bold; }
        .totals { background: #f0f0f0; font-weight: bold; }
        .small { font-size: 7.5pt; }
        .highlight { background: #ffffcc; }
        .page-break { page-break-after: always; }
        .package-badge { 
            background: #198754; 
            color: white; 
            padding: 8px 12px; 
            border-radius: 4px; 
            display: inline-block; 
            margin-bottom: 8px;
            font-size: 10pt;
            font-weight: bold;
        }
        @media print {
            body { padding: 0; }
            @page { margin: 8mm; size: A4 portrait; }
            .page-break { page-break-after: always; }
        }
    </style>
</head>
<body>

<?php 
foreach ($packages as $pkg_idx => $package): 
    $is_last_page = ($pkg_idx == $total_pages - 1);
    
    // Staff di QUESTO PACCHETTO
    $staff_portion = $staff_by_package[$package['id']] ?? [];
    
    // Servizi di QUESTO PACCHETTO SPECIFICO
    $services_portion = [];
    $agibility_cost_this_package = 0;

    foreach ($all_services as $service) {
        if ($service['quote_item_id'] == $package['id']) {
            if (stripos($service['service_name'], 'AGIBILITÀ') !== false) {
                $agibility_cost_this_package = $service['cost'];
            } else {
                $services_portion[] = $service;
            }
        }
    }
    
    // Calcola totali STAFF di questo pacchetto
    $total_staff_cost = 0;
    $total_staff_extra = 0;
    $staff_with_cost = 0; // Per agibilità
    foreach ($staff_portion as $person) {
        $total_staff_cost += (float)$person['cost'];
        $total_staff_extra += (float)$person['extra'];
        if ((float)$person['cost'] > 1) {
            $staff_with_cost++;
        }
    }
    
    // Calcola totali SERVIZI di questo pacchetto
    $total_services_cost = $agibility_cost_this_package; // Inizia con agibilità
    $total_services_extra = 0;
    foreach ($services_portion as $service) {
        $total_services_cost += (float)$service['cost'];
        $total_services_extra += (float)$service['extra'];
    }
    
    // IMPORTO FATTURA = TOTALE COSTO di questo pacchetto
    $invoice_this_package = $total_staff_cost + $total_services_cost;
    
    // IMPORTO EXTRA = TOTALE EXTRA di questo pacchetto
    $extra_this_package = $total_staff_extra + $total_services_extra;
    
    $grand_total_cost = $total_staff_cost + $total_services_cost;
    $grand_total_extra = $total_staff_extra + $total_services_extra;
    
    // ACCONTO: Diviso equamente tra i pacchetti
    $deposit_this_package = ($quote['deposit_amount'] ?? 0) / $total_pages;
    
    // PROVVIGIONE: Proporzionale al (Costo + Extra) di questo pacchetto
    $this_package_total = $grand_total_cost + $grand_total_extra;
    $commission_percentage = $grand_total_all_packages > 0 ? ($this_package_total / $grand_total_all_packages) : 0;
    $commission_this_package = ($quote['commercial_commission'] ?? 0) * $commission_percentage;
?>

    <h1>Scheda Evento</h1>
    
    <!-- Badge Pacchetto -->
    <div style="text-align: center; margin-bottom: 10px;">
        <span class="package-badge">
            📦 <?php echo e($package['clean_name']); ?>
            <?php if ($total_pages > 1): ?>
            (Pagina <?php echo $pkg_idx + 1; ?> di <?php echo $total_pages; ?>)
            <?php endif; ?>
        </span>
    </div>
    
    <!-- ID, Data e Ora -->
    <div class="section-title">ID, Data e Ora</div>
    <table>
        <tr>
            <th style="width: 8%;">ID</th>
            <th style="width: 18%;">Data</th>
            <th style="width: 12%;">Ora</th>
            <th style="width: 62%;">Luogo Evento</th>
        </tr>
        <tr>
            <td class="text-center"><?php echo $quote_id; ?></td>
            <td><?php echo $package['event_date'] ? date('d/m/Y', strtotime($package['event_date'])) : 'Da definire'; ?></td>
            <td class="text-center"><?php echo $package['event_time'] ? substr($package['event_time'], 0, 5) : '-'; ?></td>
            <td><strong><?php echo e($quote['event_location'] ?? 'Non specificato'); ?></strong></td>
        </tr>
    </table>
    
    <!-- Pacchetto -->
    <div class="section-title">Pacchetto</div>
    <table>
        <tr>
            <td><strong><?php echo e($package['clean_name']); ?></strong></td>
        </tr>
    </table>
    
    <!-- Cliente -->
    <div class="section-title">Cliente</div>
    <table>
        <tr>
            <th style="width: 40%;">Nome/Ragione Sociale</th>
            <th style="width: 30%;">Telefono</th>
            <th style="width: 30%;">Email</th>
        </tr>
        <tr>
            <td><strong><?php echo e($client_name); ?></strong></td>
            <td>
                <?php 
                $phone_label = '';
                if (!empty($quote['first_name']) || !empty($quote['last_name'])) {
                    $phone_label = trim($quote['first_name'] . ' ' . $quote['last_name']);
                } else {
                    $phone_label = $quote['company_name'];
                }
                ?>
                <strong><?php echo e($phone_label); ?>:</strong><br>
                <?php echo e($quote['phone']); ?>
            </td>
            <td class="small"><?php echo e($quote['email']); ?></td>
        </tr>
    </table>
    
    <?php if ($quote['address']): ?>
    <table>
        <tr>
            <th style="width: 100%;">Indirizzo</th>
        </tr>
        <tr>
            <td><?php echo e($quote['address']); ?></td>
        </tr>
    </table>
    <?php endif; ?>
    
    <table>
        <tr>
            <th style="width: 25%;">Codice Fiscale</th>
            <th style="width: 25%;">Partita IVA</th>
            <th style="width: 25%;">PEC</th>
            <th style="width: 25%;">Codice SDI</th>
        </tr>
        <tr>
            <td class="small"><?php echo e($quote['tax_code']) ?: '-'; ?></td>
            <td class="small"><?php echo e($quote['vat_number']) ?: '-'; ?></td>
            <td class="small"><?php echo e($quote['pec']) ?: '-'; ?></td>
            <td class="text-center"><?php echo e($quote['sdi_code']) ?: '-'; ?></td>
        </tr>
    </table>
    
    <!-- Gestione -->
    <div class="section-title">Gestione</div>
    <table>
        <tr>
            <th style="width: 14%;">Stato Evento</th>
            <th style="width: 18%;">Gestione Personale</th>
            <th style="width: 17%;">Importo Fattura</th>
            <th style="width: 17%;">Importo Extra</th>
            <th style="width: 17%;">Totale Preventivo</th>
            <th style="width: 17%;">Venditore</th>
        </tr>
        <tr>
            <td class="text-center bold"><?php echo strtoupper($quote['status']); ?></td>
            <td class="text-center bold"><?php echo strtoupper($quote['staff_management_status'] ?? 'PENDING'); ?></td>
            <td class="text-right highlight bold"><?php echo formatPrice($invoice_this_package); ?> + IVA</td>
            <td class="text-right"><?php echo formatPrice($extra_this_package); ?></td>
            <td class="text-right bold"><?php echo formatPrice($quote['total']); ?></td>
            <td><?php echo e($seller_name); ?></td>
        </tr>
    </table>
    
    <?php
// CALCOLA IVA CORRETTA: solo sul COSTO di questo pacchetto, non sull'extra
$imponibile_this_package = $invoice_this_package; // Solo costo
$iva_rate = (float)$quote['iva_rate'];
$iva_this_package = $imponibile_this_package * ($iva_rate / 100);
$total_with_iva_this_package = $imponibile_this_package + $iva_this_package;
$total_cost_plus_extra_no_iva = $imponibile_this_package + $extra_this_package; // Costo + Extra (senza IVA)
?>
<table>
    <tr>
        <th style="width: 16%;">Imponibile</th>
        <th style="width: 12%;">IVA <?php echo $quote['iva_rate']; ?>%</th>
        <th style="width: 18%;">Totale con IVA</th>
        <th style="width: 18%;">Extra</th>
        <th style="width: 18%;">Totale (Costo+Extra)</th>
        <th style="width: 18%;">Totale Finale</th>
    </tr>
    <tr class="highlight">
        <td class="text-right bold"><?php echo formatPrice($imponibile_this_package); ?></td>
        <td class="text-right bold"><?php echo formatPrice($iva_this_package); ?></td>
        <td class="text-right bold"><?php echo formatPrice($total_with_iva_this_package); ?></td>
        <td class="text-right bold"><?php echo formatPrice($extra_this_package); ?></td>
        <td class="text-right bold"><?php echo formatPrice($total_cost_plus_extra_no_iva); ?></td>
        <td class="text-right bold" style="font-size: 9.5pt; background: #d0e8ff;"><?php echo formatPrice($total_with_iva_this_package + $extra_this_package); ?></td>
    </tr>
</table>
    
    <?php if (($quote['deposit_amount'] ?? 0) > 0): ?>
    <table>
        <tr>
            <th style="width: 25%;">Acconto Versato (Questo Evento)</th>
            <th style="width: 15%;">Tipo Acconto</th>
            <th style="width: 30%;">Data Registrazione</th>
            <th style="width: 30%;">Note Acconto</th>
        </tr>
        <tr>
            <td class="text-right bold"><?php echo formatPrice($deposit_this_package); ?></td>
            <td class="text-center"><?php echo $quote['deposit_type'] == 'cost' ? 'COSTO' : 'EXTRA'; ?></td>
            <td class="text-center"><?php echo date('d/m/Y H:i', strtotime($quote['deposit_date'])); ?></td>
            <td class="small">
                Scalato da <?php echo $quote['deposit_type'] == 'cost' ? 'Utile Fattura' : 'Utile Extra'; ?>
                <?php if ($total_pages > 1): ?>
                <br><strong>Acconto Totale: <?php echo formatPrice($quote['deposit_amount']); ?></strong> (diviso in <?php echo $total_pages; ?> eventi)
                <?php endif; ?>
            </td>
        </tr>
    </table>
    <?php endif; ?>
    
    <?php if (($quote['commercial_commission'] ?? 0) > 0): ?>
    <table>
        <tr>
            <th style="width: 25%;">Provvigione Commerciale (Questo Evento)</th>
            <th style="width: 15%;">Tipo</th>
            <th style="width: 60%;">Note</th>
        </tr>
        <tr>
            <td class="text-right bold"><?php echo formatPrice($commission_this_package); ?></td>
            <td class="text-center"><?php echo $quote['commission_type'] == 'cost' ? 'COSTO' : 'EXTRA'; ?></td>
            <td class="small">
                Sottratta da <?php echo $quote['commission_type'] == 'cost' ? 'Utile Fattura' : 'Utile Extra'; ?>
                <?php if ($total_pages > 1): ?>
                <br><strong>Provvigione Totale: <?php echo formatPrice($quote['commercial_commission']); ?></strong>
                <br>Proporzionale al valore di questo evento (<?php echo number_format($commission_percentage * 100, 1); ?>% del totale)
                <?php endif; ?>
            </td>
        </tr>
    </table>
    <?php endif; ?>
    
    <!-- Elenco Persone -->
    <h2>Elenco Persone</h2>
    <table>
        <tr>
            <th style="width: 6%;">ID</th>
            <th style="width: 18%;">Nome</th>
            <th style="width: 18%;">Cognome</th>
            <th style="width: 11%;">Costo €</th>
            <th style="width: 11%;">Extra €</th>
            <th style="width: 24%;">Note</th>
            <th style="width: 12%;">Ruolo</th>
        </tr>
        <?php foreach ($staff_portion as $person): ?>
        <tr>
            <td class="text-center"><?php echo $person['staff_id']; ?></td>
            <td><strong><?php echo e($person['first_name']); ?></strong></td>
            <td><strong><?php echo e($person['last_name']); ?></strong></td>
            <td class="text-right"><?php echo formatPrice($person['cost']); ?></td>
            <td class="text-right"><?php echo formatPrice($person['extra']); ?></td>
            <td class="small"><?php echo e($person['notes']); ?></td>
            <td class="small"><?php echo e($person['role_name']); ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="totals">
            <td colspan="3" class="text-right">TOTALE PERSONE</td>
            <td class="text-right"><?php echo formatPrice($total_staff_cost); ?></td>
            <td class="text-right"><?php echo formatPrice($total_staff_extra); ?></td>
            <td colspan="2"></td>
        </tr>
    </table>
    
    <!-- Elenco Servizi -->
    <h2>Elenco Servizi</h2>
    <table>
        <tr>
            <th style="width: 6%;">ID</th>
            <th style="width: 50%;">Descrizione</th>
            <th style="width: 13%;">Costo €</th>
            <th style="width: 13%;">Extra €</th>
            <th style="width: 18%;">Note</th>
        </tr>
        <?php 
        $idx = 1;
        foreach ($services_portion as $service): 
        ?>
        <tr>
            <td class="text-center"><?php echo $idx++; ?></td>
            <td><strong><?php echo e($service['service_name']); ?></strong></td>
            <td class="text-right"><?php echo formatPrice($service['cost']); ?></td>
            <td class="text-right"><?php echo formatPrice($service['extra']); ?></td>
            <td class="small"><?php echo e($service['notes']); ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if ($agibility_cost_this_package > 0): ?>
        <tr>
            <td class="text-center"><?php echo $idx++; ?></td>
            <td><strong>OKL SERVIZI - AGIBILITÀ</strong></td>
            <td class="text-right"><?php echo formatPrice($agibility_cost_this_package); ?></td>
            <td class="text-right">€ 0,00</td>
            <td class="small">N. <?php echo $staff_with_cost; ?> persone</td>
        </tr>
        <?php endif; ?>
        <tr class="totals">
            <td colspan="2" class="text-right">TOTALE SERVIZI</td>
            <td class="text-right"><?php echo formatPrice($total_services_cost); ?></td>
            <td class="text-right"><?php echo formatPrice($total_services_extra); ?></td>
            <td></td>
        </tr>
        <tr class="totals" style="background: #d0d0d0;">
            <td colspan="2" class="text-right bold">TOTALE GENERALE</td>
            <td class="text-right bold"><?php echo formatPrice($grand_total_cost); ?></td>
            <td class="text-right bold"><?php echo formatPrice($grand_total_extra); ?></td>
            <td></td>
        </tr>
    </table>

<?php if (!$is_last_page): ?>
<div class="page-break"></div>
<?php endif; ?>

<?php endforeach; ?>
    
    <script>
    window.onload = function() {
        setTimeout(function() {
            window.print();
        }, 500);
    };
    </script>
</body>
</html>