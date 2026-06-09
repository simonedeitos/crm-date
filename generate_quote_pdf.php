<?php
// VERSIONE OTTIMIZZATA PER INVIO CLIENTI
error_reporting(0);
ini_set('display_errors', 0);

/**
 * Rimuove tutto il testo tra parentesi quadre [...]
 */
function removeSquareBrackets($text) {
    if (empty($text)) return '';
    $cleaned = preg_replace('/\s*\[.*?\]\s*/', ' ', $text);
    $cleaned = preg_replace('/\s+/', ' ', $cleaned);
    return trim($cleaned);
}

try {
    require_once 'config.php';
    require_once 'auth.php';
    require_once 'functions.php';
    
    $db = getDBConnection();
    $quote_id = (int)($_GET['id'] ?? 0);
    
    if (!$quote_id) die('ID mancante');
    
    // Query base con info utente creatore
    $quote_query = "SELECT q.*, c.*, u.first_name as user_first_name, u.last_name as user_last_name, 
                    u.email as user_email, u.phone as user_phone
                    FROM quotes q 
                    JOIN clients c ON q.client_id = c.id
                    LEFT JOIN users u ON q.created_by = u.id
                    WHERE q.id = $quote_id";
    $result = mysqli_query($db, $quote_query);
    if (!$result || !mysqli_num_rows($result)) die('Preventivo non trovato');
    $quote = mysqli_fetch_assoc($result);
    
    // Items CON LE LORO DATE
    $items_result = mysqli_query($db, "SELECT * FROM quote_items WHERE quote_id = $quote_id ORDER BY sort_order");
    $items = [];
    while ($row = mysqli_fetch_assoc($items_result)) $items[] = $row;
    
    // Raccogli tutte le date UNICHE dai pacchetti
    $all_dates = [];
    foreach ($items as $item) {
        if (!empty($item['event_date'])) {
            $date_key = date('Y-m-d', strtotime($item['event_date']));
            $all_dates[$date_key] = $item['event_date'];
        }
    }
    ksort($all_dates); // Ordina per data
    
    // Formatta le date per visualizzazione compatta
    $formatted_dates = '';
    if (count($all_dates) > 0) {
        $dates_array = array_values($all_dates);
        
        if (count($dates_array) == 1) {
            // Una sola data
            $formatted_dates = date('d/m/Y', strtotime($dates_array[0]));
        } else {
            // Date multiple - raggruppa per mese/anno
            $grouped = [];
            foreach ($dates_array as $d) {
                $month_year = date('m/Y', strtotime($d));
                $day = date('d', strtotime($d));
                if (!isset($grouped[$month_year])) {
                    $grouped[$month_year] = [];
                }
                $grouped[$month_year][] = $day;
            }
            
            $date_parts = [];
            foreach ($grouped as $month_year => $days) {
                if (count($days) == 1) {
                    $date_parts[] = $days[0] . '/' . $month_year;
                } else {
                    $date_parts[] = implode(',', $days) . '/' . $month_year;
                }
            }
            $formatted_dates = implode(' • ', $date_parts);
        }
    } else {
        $formatted_dates = 'Da definire';
    }
    
    // Location globale
    $location = $quote['event_location'] ?? 'Evento';
    
    $client_name = $quote['company_name'] ?: trim($quote['first_name'] . ' ' . $quote['last_name']);
    
    // Cambia stato se richiesto
    if (isset($_GET['change_status']) && $quote['status'] === 'bozza') {
        mysqli_query($db, "UPDATE quotes SET status = 'inviato' WHERE id = $quote_id");
    }
    
} catch (Exception $e) {
    die('Errore: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Preventivo_<?php echo htmlspecialchars($quote['quote_number']); ?></title>
    <style>
        @media print { 
            .no-print { display: none !important; } 
            body { 
                margin: 0;
                overflow: hidden;
            }
            @page { 
                margin: 20mm;
                size: A4;
            }
            html, body {
                height: auto;
                max-height: 100%;
            }
            #pdf-content {
                min-height: auto;
            }
        }
        
        html {
            height: 100%;
        }
        
        body { 
            font-family: 'Segoe UI', Arial, sans-serif; 
            font-size: 12px; 
            margin: 20px; 
            color: #333;
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        #pdf-content {
            display: flex;
            flex-direction: column;
            min-height: calc(100vh - 40px);
        }
        
        .content-wrapper {
            flex: 1;
        }
        
        .footer-wrapper {
            margin-top: auto;
        }
        
        .header { 
            border-bottom: 3px solid #4472C4; 
            padding-bottom: 12px; 
            margin-bottom: 20px; 
        }
        .header table { width: 100%; }
        .company { 
            font-size: 18px; 
            font-weight: bold; 
            color: #4472C4;
            margin-bottom: 8px;
        }
        .client { 
            font-weight: bold; 
            margin-top: 12px;
            font-size: 11px;
            line-height: 1.6;
        }
        .quote-box { 
            background: linear-gradient(135deg, #4472C4 0%, #5b8fd6 100%);
            color: white; 
            padding: 10px 12px;
            text-align: center;
            border-radius: 5px;
            box-shadow: 0 2px 6px rgba(68, 114, 196, 0.3);
        }
        .quote-box h2 { 
            margin: 0 0 8px 0; 
            font-size: 16px;
            letter-spacing: 1px;
        }
        .quote-box table {
            font-size: 10px;
            line-height: 1.4;
        }
        .items { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 25px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .items th { 
            background: #4472C4; 
            color: white; 
            padding: 10px; 
            border: 1px solid #4472C4;
            font-size: 12px;
            letter-spacing: 0.5px;
        }
        .items td { 
            padding: 15px 10px; /* Padding leggermente aumentato */
            border: 1px solid #ddd; 
            vertical-align: top;
            background: white;
        }
        .items tr:nth-child(even) td {
            background: #f8f9fa;
        }
        
        /* Nuovi stili per il layout con immagine */
        .item-main-container {
            width: 100%;
            border-collapse: collapse;
            border: none;
        }
        .item-main-container td {
            border: none !important;
            background: transparent !important;
            padding: 0;
            vertical-align: top;
        }
        .item-logo-cell {
            width: 100px; /* Larghezza fissa per il logo */
            padding-right: 15px !important;
        }
        .item-logo {
            width: 100px;
            height: 100px;
            object-fit: contain; /* Mantiene proporzioni */
            border-radius: 6px;
            border: 1px solid #eee;
            background: white;
            padding: 4px;
        }
        .item-content-cell {
            /* Prende il resto dello spazio */
        }

        .item-title { 
            font-weight: bold; 
            font-size: 14px; /* Aumentato leggermente */
            margin-bottom: 2px;
            color: #4472C4;
        }
        .item-date {
            font-size: 10px;
            color: #666;
            margin-bottom: 8px;
            font-style: italic;
        }
        .item-description {
            font-size: 11px;
            color: #555;
            margin-bottom: 12px;
            font-style: italic;
            line-height: 1.4;
            background: rgba(68, 114, 196, 0.05); /* Sfondo leggerissimo */
            padding: 8px;
            border-radius: 4px;
            border-left: 3px solid #4472C4;
        }

        /* Gestione Totali */
        .totals { 
            float: right; 
            width: 220px;
            margin-top: 30px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
            border-radius: 6px;
            overflow: hidden;
        }
        
        /* Classe per la modalità orizzontale compatta (quando ci sono > 1 item) */
        .totals-compact {
            width: 100%; /* Occupa tutta la larghezza o quasi */
            max-width: 600px; /* Non esagerare */
        }

        .totals table { 
            width: 100%; 
            border-collapse: collapse; 
        }
        .totals td { 
            padding: 6px 12px;
            border-bottom: 1px solid #e0e0e0; 
            text-align: right; 
            font-weight: 600;
            background: white;
            font-size: 11px;
        }
        
        /* Stili specifici per layout verticale (default) */
        .totals:not(.totals-compact) td:first-child {
            text-align: left;
            color: #666;
            font-weight: 500;
        }

        /* Stili specifici per layout orizzontale */
        .totals-compact td {
            text-align: center; /* Centrato per layout orizzontale */
            border-bottom: none; /* Rimuovi bordi interni intermedi */
            padding: 8px;
        }
        .totals-compact .label-row td {
            color: #666;
            font-weight: 500;
            font-size: 10px;
            text-transform: uppercase;
            padding-bottom: 0;
        }
        .totals-compact .value-row td {
            font-size: 12px;
            padding-top: 2px;
            padding-bottom: 8px;
        }
        /* Separatori verticali per layout orizzontale */
        .totals-compact td:not(:last-child) {
            border-right: 1px solid #f0f0f0;
        }

        .final { 
            background: #4472C4 !important;
            border-top: 2px solid #2c5aa0; 
            font-size: 13px;
            font-weight: bold;
        }
        .final td {
            border: none !important;
            padding: 8px 12px;
            color: black !important; /* Testo bianco su sfondo blu */
            text-align: right !important; /* Sempre a destra nella riga finale */
        }
        .totals-compact .final td {
            text-align: right !important;
            padding: 10px 15px;
        }
        
        .footer-note {
            clear: both;
            margin-top: 40px;
            padding: 12px 15px;
            background: #f8f9fa;
            border-left: 4px solid #4472C4;
            font-size: 10px;
            line-height: 1.5;
            border-radius: 4px;
        }
        .footer-note strong {
            color: #4472C4;
            font-weight: 700;
            font-size: 10px;
        }
        
        .footer-contacts {
            clear: both;
            margin-top: 25px;
            padding: 12px 15px;
            background: white;
            border-top: 3px solid #4472C4;
        }
        .footer-contacts table {
            width: 100%;
            border-collapse: collapse;
        }
        .footer-contacts td {
            vertical-align: top;
            padding: 0 10px;
        }
        .footer-left {
            width: 50%;
            border-right: 1px solid #e0e0e0;
        }
        .footer-right {
            width: 50%;
            text-align: right;
        }
        .footer-title {
            font-size: 9px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            font-weight: 600;
        }
        .contact-info {
            font-size: 10px;
            line-height: 1.5;
            color: #333;
        }
        .contact-name {
            font-weight: bold;
            font-size: 11px;
            color: #4472C4;
            margin-bottom: 3px;
        }
        .company-info {
            font-size: 10px;
            line-height: 1.5;
            color: #333;
        }
        .company-name {
            font-weight: bold;
            font-size: 12px;
            color: #4472C4;
            margin-bottom: 3px;
        }
        .iban-code {
            background: #f0f0f0; 
            padding: 3px 8px; 
            border-radius: 3px; 
            font-size: 9px;
            font-family: 'Courier New', monospace;
            display: inline-block;
            margin-top: 3px;
            line-height: 1.3;
        }
        
        .no-print { 
            position: fixed; 
            top: 10px; 
            left: 50%; 
            transform: translateX(-50%); 
            z-index: 1000; 
            background: white; 
            padding: 15px 25px; 
            border: 1px solid #ddd; 
            border-radius: 8px; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.15); 
        }
        .btn { 
            padding: 12px 24px; 
            margin: 0 5px; 
            border: none; 
            border-radius: 6px; 
            cursor: pointer; 
            font-size: 14px; 
            color: white;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-save { 
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
        }
        .btn-close { 
            background: #6c757d; 
        }
        .btn:hover { 
            opacity: 0.9; 
            transform: translateY(-2px); 
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .btn:active { 
            transform: translateY(0); 
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn btn-save" onclick="savePDF()">💾 Salva PDF</button>
        <button class="btn btn-close" onclick="window.close()">✖️ Chiudi</button>
    </div>

    <div id="pdf-content">
        <div class="content-wrapper">
            <!-- HEADER -->
            <div class="header">
                <table>
                    <tr>
                        <td width="65%">
                            <div class="company">Event Format | OKL</div>
                            <div class="client">
                                <?php echo strtoupper($client_name); ?><br>
                                <?php if (!empty($quote['address'])) echo htmlspecialchars($quote['address']) . '<br>'; ?>
                                <?php if (!empty($quote['tax_code'])) echo 'CF: ' . htmlspecialchars($quote['tax_code']); ?>
                                <?php if (!empty($quote['vat_number'])) echo ' | P.IVA: ' . htmlspecialchars($quote['vat_number']); ?>
                            </div>
                        </td>
                        <td width="35%" style="vertical-align: top;">
                            <div class="quote-box">
                                <h2>PREVENTIVO</h2>
                                <table style="width: 100%; color: white;">
                                    <tr><td><strong>Località</strong></td><td style="text-align: right;"><?php echo htmlspecialchars(removeSquareBrackets($location)); ?></td></tr>
                                    <tr><td><strong>Date</strong></td><td style="text-align: right;">
                                        <?php echo htmlspecialchars($formatted_dates); ?>
                                    </td></tr>
                                </table>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- ITEMS -->
            <table class="items">
                <thead>
                    <tr>
                        <th style="width: 75%;">DESCRIZIONE</th>
                        <th style="width: 25%;">IMPONIBILE</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): 
                        $cleanPackageName = removeSquareBrackets($item['package_name']);
                        if (empty($cleanPackageName)) continue;
                        
                        // Formatta data/ora del pacchetto
                        $item_datetime = '';
                        if (!empty($item['event_date'])) {
                            $item_datetime = '📅 ' . date('d/m/Y', strtotime($item['event_date']));
                            if (!empty($item['event_time'])) {
                                $item_datetime .= ' • ⏰ ' . substr($item['event_time'], 0, 5);
                            }
                        }

                        // RECUPERA INFO PACCHETTO (IMMAGINE E DESCRIZIONE)
                        $pkg_image = '';
                        $pkg_description = '';
                        if (!empty($item['package_id'])) {
                            $pkg_query = "SELECT image_path, description FROM packages WHERE id = " . (int)$item['package_id'];
                            $pkg_res = mysqli_query($db, $pkg_query);
                            if ($pkg_res && mysqli_num_rows($pkg_res) > 0) {
                                $pkg_data = mysqli_fetch_assoc($pkg_res);
                                // Verifica che il file immagine esista
                                if (!empty($pkg_data['image_path']) && file_exists($pkg_data['image_path'])) {
                                    $pkg_image = $pkg_data['image_path'];
                                }
                                // Usa la descrizione del pacchetto se presente, altrimenti quella dell'item (se mai popolata diversamente)
                                if (!empty($pkg_data['description'])) {
                                    $pkg_description = $pkg_data['description'];
                                }
                            }
                        }
                    ?>
                    <tr>
                        <td>
                            <!-- TABELLA INTERNA PER LAYOUT IMMAGINE + CONTENUTO -->
                            <table class="item-main-container">
                                <tr>
                                    <?php if ($pkg_image): ?>
                                    <td class="item-logo-cell">
                                        <img src="<?php echo htmlspecialchars($pkg_image); ?>" class="item-logo" alt="Logo">
                                    </td>
                                    <?php endif; ?>
                                    
                                    <td class="item-content-cell">
                                        <!-- TITOLO -->
                                        <div class="item-title"><?php echo htmlspecialchars($cleanPackageName); ?></div>
                                        
                                        <!-- DATA E ORA -->
                                        <?php if ($item_datetime): ?>
                                        <div class="item-date"><?php echo htmlspecialchars($item_datetime); ?></div>
                                        <?php endif; ?>

                                        <!-- DESCRIZIONE PACCHETTO -->
                                        <?php if (!empty($pkg_description)): ?>
                                        <div class="item-description">
                                            <?php echo nl2br(htmlspecialchars($pkg_description)); ?>
                                        </div>
                                        <?php endif; ?>

                                        <!-- TABELLA STAFF E SERVIZI -->
                                        <table style="width: 100%; border: none; border-collapse: collapse;">
                                            <tr>
                                                <td style="width: 35%; vertical-align: top; border: none; padding: 0; padding-right: 15px;">
                                                    <?php
                                                    if (!empty($item['package_id'])) {
                                                        $roles_result = mysqli_query($db, "SELECT psr.quantity, sr.name FROM package_staff_roles psr JOIN staff_roles sr ON psr.role_id = sr.id WHERE psr.package_id = {$item['package_id']}");
                                                        if ($roles_result && mysqli_num_rows($roles_result) > 0) {
                                                            echo "<div style='font-size: 10px; font-weight: bold; margin-bottom: 2px; color: #666;'>STAFF:</div>";
                                                            $hasRoles = false;
                                                            while ($role = mysqli_fetch_assoc($roles_result)) {
                                                                $cleanRoleName = removeSquareBrackets($role['name']);
                                                                if (!empty($cleanRoleName)) {
                                                                    echo "• n. {$role['quantity']} " . htmlspecialchars($cleanRoleName) . "<br>";
                                                                    $hasRoles = true;
                                                                }
                                                            }
                                                        }
                                                    }
                                                    ?>
                                                </td>
                                                <td style="width: 65%; vertical-align: top; border: none; padding: 0; padding-left: 15px;">
                                                    <?php
                                                    if (!empty($item['package_id'])) {
                                                        $services_result = mysqli_query($db, "SELECT service_name FROM package_services WHERE package_id = {$item['package_id']} ORDER BY sort_order");
                                                        if ($services_result && mysqli_num_rows($services_result) > 0) {
                                                            echo "<div style='font-size: 10px; font-weight: bold; margin-bottom: 2px; color: #666;'>SERVIZI INCLUSI:</div>";
                                                            $hasServices = false;
                                                            while ($service = mysqli_fetch_assoc($services_result)) {
                                                                $cleanServiceName = removeSquareBrackets($service['service_name']);
                                                                if (!empty($cleanServiceName)) {
                                                                    echo "• " . htmlspecialchars($cleanServiceName) . "<br>";
                                                                    $hasServices = true;
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        if (!empty($item['services'])) {
                                                            echo "<div style='font-size: 10px; font-weight: bold; margin-bottom: 2px; color: #666;'>SERVIZI:</div>";
                                                            $services = explode("\n", $item['services']);
                                                            foreach ($services as $service) {
                                                                $cleanService = removeSquareBrackets(trim($service));
                                                                if (!empty($cleanService)) {
                                                                    echo "• " . htmlspecialchars($cleanService) . "<br>";
                                                                }
                                                            }
                                                        }
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                        <td style="text-align: center; font-weight: bold; font-size: 13px; color: #333; vertical-align: middle;">
                            <?php echo formatPrice($item['price']); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- TOTALS (LOGICA CONDIZIONALE) -->
            <?php 
            $item_count = count($items);
            if ($item_count >= 2): 
                // VISUALIZZAZIONE COMPATTA ORIZZONTALE
            ?>
            <div class="totals totals-compact">
                <table>
                    <tr class="label-row">
                        <td>Imponibile</td>
                        <?php if ($quote['discount_value'] > 0): ?>
                        <td>Sconto</td>
                        <td>Totale</td>
                        <?php endif; ?>
                        <td>IVA <?php echo $quote['iva_rate']; ?>%</td>
                    </tr>
                    <tr class="value-row">
                        <td><?php echo formatPrice($quote['subtotal']); ?></td>
                        <?php if ($quote['discount_value'] > 0): ?>
                        <td style="color: #dc3545;">-<?php echo formatPrice($quote['subtotal'] - $quote['total']); ?></td>
                        <td><?php echo formatPrice($quote['total']); ?></td>
                        <?php endif; ?>
                        <td><?php echo formatPrice($quote['iva_amount']); ?></td>
                    </tr>
                    <tr class="final">
                        <td colspan="<?php echo ($quote['discount_value'] > 0) ? '4' : '2'; ?>">
                            TOTALE: <?php echo formatPrice($quote['total_with_iva']); ?>
                        </td>
                    </tr>
                </table>
            </div>
            <?php else: 
                // VISUALIZZAZIONE VERTICALE CLASSICA
            ?>
            <div class="totals">
                <table>
                    <tr><td>IMPONIBILE</td><td><?php echo formatPrice($quote['subtotal']); ?></td></tr>
                    <?php if ($quote['discount_value'] > 0): ?>
                    <tr><td>SCONTO <?php echo $quote['discount_type'] === 'percentage' ? '('.$quote['discount_value'].'%)' : ''; ?></td><td style="color: #dc3545;">-<?php echo formatPrice($quote['subtotal'] - $quote['total']); ?></td></tr>
                    <tr><td>TOTALE</td><td><?php echo formatPrice($quote['total']); ?></td></tr>
                    <?php endif; ?>
                    <tr><td>IVA <?php echo $quote['iva_rate']; ?>%</td><td><?php echo formatPrice($quote['iva_amount']); ?></td></tr>
                    <tr class="final">
                        <td>TOTALE</td>
                        <td><?php echo formatPrice($quote['total_with_iva']); ?></td>
                    </tr>
                </table>
            </div>
            <?php endif; ?>

            <div style="clear: both;"></div>
        </div>

        <!-- FOOTER WRAPPER - Ancorato in fondo -->
        <div class="footer-wrapper">
            <!-- FOOTER NOTE -->
            <div class="footer-note">
                <strong>NOTA:</strong> Il presente preventivo ha validità di 7 giorni dalla data di emissione (<?php echo date('d/m/Y', strtotime($quote['created_at'])); ?>) e resta subordinato alla disponibilità.
            </div>

            <!-- FOOTER CONTACTS -->
            <div class="footer-contacts">
                <table>
                    <tr>
                        <td class="footer-left">
                            <div class="footer-title">👤 Il Tuo Referente</div>
                            <div class="contact-info">
                                <div class="contact-name">
                                    <?php 
                                    $referente = trim(($quote['user_first_name'] ?? '') . ' ' . ($quote['user_last_name'] ?? ''));
                                    echo !empty($referente) ? htmlspecialchars($referente) : 'Team Event Format';
                                    ?>
                                </div>
                                <?php if (!empty($quote['user_phone'])): ?>
                                📞 <strong><?php echo htmlspecialchars($quote['user_phone']); ?></strong><br>
                                <?php endif; ?>
                                <?php if (!empty($quote['user_email'])): ?>
                                ✉️ <a href="mailto:<?php echo htmlspecialchars($quote['user_email']); ?>" style="color: #4472C4; text-decoration: none;"><?php echo htmlspecialchars($quote['user_email']); ?></a>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="footer-right">
                            <div class="footer-title">🏢 Dati Aziendali</div>
                            <div class="company-info">
                                <div class="company-name">OKL SRL</div>
                                <span class="iban-code">IT96 I033 9512 9000 5240 5468 130</span>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <script>
    function savePDF() {
        window.print();
    }
    
    <?php if (isset($_GET['auto_print'])): ?>
    window.onload = function() {
        setTimeout(function() {
            window.print();
        }, 500);
    };
    <?php endif; ?>
    </script>
</body>
</html>