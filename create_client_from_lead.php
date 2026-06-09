<?php
require_once 'config.php';
require_once 'auth.php';
require_once 'functions.php';

requireLogin();

$db = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lead_id = (int)$_POST['lead_id'];
    
    // Verifica che la segnalazione esista e che l'utente abbia i permessi
    $check_query = "SELECT assigned_to FROM leads WHERE id = $lead_id";
    $check_result = mysqli_query($db, $check_query);
    $lead = mysqli_fetch_assoc($check_result);
    
    if (!$lead) {
        header("Location: leads.php?error=lead_not_found");
        exit;
    }
    
    // Verifica permessi
    if (!isAdmin() && $lead['assigned_to'] != $_SESSION['user_id'] && $lead['assigned_to'] !== null) {
        header("Location: leads.php?id=$lead_id&error=no_permission");
        exit;
    }
    
    // Validazione: solo first_name è obbligatorio
    $first_name = mysqli_real_escape_string($db, trim($_POST['first_name'] ?? ''));
    
    if (empty($first_name)) {
        header("Location: leads.php?id=$lead_id&error=first_name_required");
        exit;
    }
    
    // Raccolta dati
    $company_name = mysqli_real_escape_string($db, $_POST['company_name'] ?? '');
    $event_name = mysqli_real_escape_string($db, $_POST['event_name'] ?? '');
    $last_name = mysqli_real_escape_string($db, $_POST['last_name'] ?? '');
    $email = mysqli_real_escape_string($db, $_POST['email'] ?? '');
    $phone = mysqli_real_escape_string($db, $_POST['phone'] ?? '');
    $address = mysqli_real_escape_string($db, $_POST['address'] ?? '');
    $vat_number = mysqli_real_escape_string($db, $_POST['vat_number'] ?? '');
    $tax_code = mysqli_real_escape_string($db, $_POST['tax_code'] ?? '');
    $pec = mysqli_real_escape_string($db, $_POST['pec'] ?? '');
    $sdi_code = mysqli_real_escape_string($db, $_POST['sdi_code'] ?? '');
    $notes = mysqli_real_escape_string($db, $_POST['notes'] ?? '');
    
    // Crea il cliente
    $query = "INSERT INTO clients (company_name, event_name, first_name, last_name, email, phone, address, 
              vat_number, tax_code, pec, sdi_code, notes, created_by, created_at) 
              VALUES ('$company_name', '$event_name', '$first_name', '$last_name', '$email', '$phone', 
              '$address', '$vat_number', '$tax_code', '$pec', '$sdi_code', '$notes', {$_SESSION['user_id']}, NOW())";
    
    if (mysqli_query($db, $query)) {
        $client_id = mysqli_insert_id($db);
        
        // Collega il cliente alla segnalazione
        $update_lead = "UPDATE leads SET client_id = $client_id, updated_at = NOW() WHERE id = $lead_id";
        mysqli_query($db, $update_lead);
        
        // Redirect alla creazione preventivo con parametri
        header("Location: quotes.php?client_id=$client_id&lead_id=$lead_id&from_lead=1");
        exit;
    } else {
        $error = mysqli_error($db);
        header("Location: leads.php?id=$lead_id&error=client_creation_failed&details=" . urlencode($error));
        exit;
    }
} else {
    // Metodo non consentito
    header("Location: leads.php");
    exit;
}
?>