<?php
require_once 'config.php';
require_once 'auth.php';
require_once 'functions.php';

requireLogin();

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'ID mancante']);
    exit;
}

$db = getDBConnection();
$lead_id = (int)$_GET['id'];

// Verifica permessi
$query = "SELECT l.* FROM leads l WHERE l.id = $lead_id";

if (isCommerciale()) {
    $query .= " AND (l.assigned_to = {$_SESSION['user_id']} OR l.assigned_to IS NULL)";
}

$result = mysqli_query($db, $query);

if ($row = mysqli_fetch_assoc($result)) {
    echo json_encode([
        'success' => true,
        'lead' => [
            'event_name' => $row['event_name'],
            'location' => $row['location'],
            'period' => $row['period'],
            'phone1' => $row['phone1'],
            'phone2' => $row['phone2'],
            'email' => $row['email'],
            'instagram' => $row['instagram'],
            'facebook' => $row['facebook']
        ]
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Lead non trovato o permessi insufficienti']);
}
?>