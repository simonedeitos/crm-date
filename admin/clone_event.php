<?php
require_once '../config.php';
require_once '../auth.php';
require_once '../functions.php';

requireAdmin();

$db = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $source_quote_id = (int)$_POST['source_quote_id'];
    $event_date = mysqli_real_escape_string($db, $_POST['event_date']);
    $event_time = !empty($_POST['event_time']) ? mysqli_real_escape_string($db, $_POST['event_time']) : null;
    $event_location = mysqli_real_escape_string($db, trim($_POST['event_location'] ?? ''));
    $current_user_id = (int)$_SESSION['user_id'];

    // Gestione cliente
    $client_id = null;
    if (!empty($_POST['client_id'])) {
        $client_id = (int)$_POST['client_id'];
    } else {
        // Crea nuovo cliente
        $company = mysqli_real_escape_string($db, trim($_POST['new_company_name'] ?? ''));
        $fname = mysqli_real_escape_string($db, trim($_POST['new_first_name'] ?? ''));
        $lname = mysqli_real_escape_string($db, trim($_POST['new_last_name'] ?? ''));
        $email = mysqli_real_escape_string($db, trim($_POST['new_email'] ?? ''));
        $phone = mysqli_real_escape_string($db, trim($_POST['new_phone'] ?? ''));

        if (empty($company) && (empty($fname) || empty($lname))) {
            header("Location: assign_staff.php?error=client_required");
            exit;
        }

        mysqli_query($db, "INSERT INTO clients (company_name, first_name, last_name, email, phone, created_by, created_at) 
                          VALUES ('$company', '$fname', '$lname', '$email', '$phone', $current_user_id, NOW())");
        $client_id = mysqli_insert_id($db);
    }

    // Carica preventivo originale
    $source_query = mysqli_query($db, "SELECT * FROM quotes WHERE id = $source_quote_id");
    $source_quote = mysqli_fetch_assoc($source_query);

    if (!$source_quote) {
        header("Location: assign_staff.php?error=quote_not_found");
        exit;
    }

    // Assicura che la colonna cloned_from esista sulla tabella quotes
    $cols = mysqli_query($db, "SHOW COLUMNS FROM quotes LIKE 'cloned_from'");
    if (mysqli_num_rows($cols) === 0) {
        mysqli_query($db, "ALTER TABLE quotes ADD COLUMN cloned_from INT NULL DEFAULT NULL");
    }

    mysqli_begin_transaction($db);

    try {
        // 1. Crea nuovo preventivo CONFERMATO
        $new_quote_number = generateQuoteNumber();
        $invoice_amount = (float)$source_quote['invoice_amount'];
        $extra_amount = (float)$source_quote['extra_amount'];
        $commercial_commission = (float)$source_quote['commercial_commission'];
        $commission_type = mysqli_real_escape_string($db, $source_quote['commission_type']);
        $deposit_amount = (float)$source_quote['deposit_amount'];
        $deposit_type = mysqli_real_escape_string($db, $source_quote['deposit_type']);

        $insert_quote = "INSERT INTO quotes (
            quote_number, client_id, created_by, status, 
            subtotal, discount_type, discount_value, total, 
            iva_rate, iva_amount, total_with_iva,
            invoice_amount, extra_amount, 
            commercial_commission, commission_type,
            deposit_amount, deposit_type,
            event_location, staff_management_status,
            cloned_from,
            created_at
        ) VALUES (
            '$new_quote_number', $client_id, $current_user_id, 'confermato',
            {$source_quote['subtotal']}, '{$source_quote['discount_type']}', {$source_quote['discount_value']}, 
            {$source_quote['total']}, {$source_quote['iva_rate']}, {$source_quote['iva_amount']}, 
            {$source_quote['total_with_iva']},
            $invoice_amount, $extra_amount,
            $commercial_commission, '$commission_type',
            $deposit_amount, '$deposit_type',
            '$event_location', 'pending',
            $source_quote_id,
            NOW()
        )";

        if (!mysqli_query($db, $insert_quote)) {
            throw new Exception("Errore creazione preventivo: " . mysqli_error($db));
        }
        $new_quote_id = mysqli_insert_id($db);

        // 2. Clona items (pacchetti)
        $items_result = mysqli_query($db, "SELECT * FROM quote_items WHERE quote_id = $source_quote_id");
        while ($item = mysqli_fetch_assoc($items_result)) {
            $old_item_id = $item['id'];
            $package_id = $item['package_id'] ? $item['package_id'] : 'NULL';
            $package_name = mysqli_real_escape_string($db, $item['package_name']);
            $services = mysqli_real_escape_string($db, $item['services']);
            $time_sql = $event_time ? "'$event_time'" : 'NULL';

            // Clona item con data/ora specificata; reset grafica creata/sponsorizzata
            $insert_item = "INSERT INTO quote_items (
                quote_id, package_id, package_name, services, price, sort_order,
                event_date, event_time, graphics_included, graphics_created, graphics_sponsored
            ) VALUES (
                $new_quote_id, $package_id, '$package_name', '$services', 
                {$item['price']}, {$item['sort_order']},
                '$event_date', $time_sql, {$item['graphics_included']}, 0, 0
            )";

            if (!mysqli_query($db, $insert_item)) {
                throw new Exception("Errore clonazione item: " . mysqli_error($db));
            }
            $new_item_id = mysqli_insert_id($db);

            // Clona servizi item
            if (!mysqli_query($db, "INSERT INTO quote_item_services (quote_item_id, service_name, is_mandatory, sort_order)
                              SELECT $new_item_id, service_name, is_mandatory, sort_order
                              FROM quote_item_services WHERE quote_item_id = $old_item_id")) {
                throw new Exception("Errore clonazione servizi item: " . mysqli_error($db));
            }

            // Clona ruoli item
            if (!mysqli_query($db, "INSERT INTO quote_item_roles (quote_item_id, role_id, role_name, quantity)
                              SELECT $new_item_id, role_id, role_name, quantity
                              FROM quote_item_roles WHERE quote_item_id = $old_item_id")) {
                throw new Exception("Errore clonazione ruoli item: " . mysqli_error($db));
            }
        }

        // 3. Clona staff assignments (stato "pending")
        $staff_result = mysqli_query($db, "SELECT * FROM quote_staff_assignment WHERE quote_id = $source_quote_id");
        while ($staff = mysqli_fetch_assoc($staff_result)) {
            $quote_item_id = $staff['quote_item_id'] ? $staff['quote_item_id'] : 'NULL';
            $notes = mysqli_real_escape_string($db, $staff['notes']);

            if (!mysqli_query($db, "INSERT INTO quote_staff_assignment (
                quote_id, quote_item_id, role_id, staff_id, cost, extra, notes, assigned_at, assigned_by
            ) VALUES (
                $new_quote_id, $quote_item_id, {$staff['role_id']}, {$staff['staff_id']},
                {$staff['cost']}, {$staff['extra']}, '$notes', NOW(), $current_user_id
            )")) {
                throw new Exception("Errore clonazione staff: " . mysqli_error($db));
            }
        }

        // 4. Clona servizi costi
        $services_result = mysqli_query($db, "SELECT * FROM quote_service_costs WHERE quote_id = $source_quote_id");
        while ($service = mysqli_fetch_assoc($services_result)) {
            $service_name = mysqli_real_escape_string($db, $service['service_name']);
            $service_notes = mysqli_real_escape_string($db, $service['notes']);
            $quote_item_id = $service['quote_item_id'] ? $service['quote_item_id'] : 'NULL';

            if (!mysqli_query($db, "INSERT INTO quote_service_costs (
                quote_id, quote_item_id, service_name, cost, extra, notes, created_at
            ) VALUES (
                $new_quote_id, $quote_item_id, '$service_name', {$service['cost']}, {$service['extra']}, 
                '$service_notes', NOW()
            )")) {
                throw new Exception("Errore clonazione servizi costi: " . mysqli_error($db));
            }
        }

        mysqli_commit($db);

        // Redirect al nuovo evento
        header("Location: assign_staff.php?quote_id=$new_quote_id&success=event_cloned");
        exit;

    } catch (Exception $e) {
        mysqli_rollback($db);
        header("Location: assign_staff.php?error=clone_failed");
        exit;
    }
} else {
    header("Location: assign_staff.php");
    exit;
}
?>
