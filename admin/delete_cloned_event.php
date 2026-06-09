<?php
require_once '../config.php';
require_once '../auth.php';
require_once '../functions.php';

requireAdmin();

$db = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: assign_staff.php');
    exit;
}

$quote_id = (int)($_POST['quote_id'] ?? 0);
$quote_action = $_POST['quote_action'] ?? 'delete';

if ($quote_id <= 0 || !in_array($quote_action, ['delete', 'reject'], true)) {
    header('Location: assign_staff.php?error=delete_failed');
    exit;
}

$check_query = mysqli_query($db, "SELECT id, cloned_from FROM quotes WHERE id = $quote_id");
$check = $check_query ? mysqli_fetch_assoc($check_query) : null;

if (!$check || empty($check['cloned_from'])) {
    header('Location: assign_staff.php?error=not_cloned');
    exit;
}

$runQuery = function ($sql, $errorMessage) use ($db) {
    $result = mysqli_query($db, $sql);
    if ($result === false) {
        throw new Exception($errorMessage . ': ' . mysqli_error($db));
    }

    return $result;
};

$deleteFileIfPresent = function ($relativePath) {
    $fullPath = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
    if ($relativePath && file_exists($fullPath)) {
        @unlink($fullPath);
    }
};

mysqli_begin_transaction($db);

try {
    $items_result = $runQuery("SELECT id FROM quote_items WHERE quote_id = $quote_id", 'Errore caricamento items evento');
    while ($item = mysqli_fetch_assoc($items_result)) {
        $item_id = (int)$item['id'];

        $media_result = $runQuery("SELECT file_path FROM quote_item_media WHERE quote_item_id = $item_id", 'Errore caricamento media evento');
        while ($media = mysqli_fetch_assoc($media_result)) {
            $deleteFileIfPresent($media['file_path']);
        }

        $runQuery("DELETE FROM quote_item_media WHERE quote_item_id = $item_id", 'Errore eliminazione media evento');
        $runQuery("DELETE FROM quote_item_services WHERE quote_item_id = $item_id", 'Errore eliminazione servizi item');
        $runQuery("DELETE FROM quote_item_roles WHERE quote_item_id = $item_id", 'Errore eliminazione ruoli item');
    }

    $attachments_result = $runQuery("SELECT file_path FROM quote_attachments WHERE quote_id = $quote_id", 'Errore caricamento allegati preventivo');
    while ($attachment = mysqli_fetch_assoc($attachments_result)) {
        $deleteFileIfPresent($attachment['file_path']);
    }

    $runQuery("DELETE FROM quote_items WHERE quote_id = $quote_id", 'Errore eliminazione items evento');
    $runQuery("DELETE FROM quote_dates WHERE quote_id = $quote_id", 'Errore eliminazione date evento');
    $runQuery("DELETE FROM quote_staff_assignment WHERE quote_id = $quote_id", 'Errore eliminazione staff evento');
    $runQuery("DELETE FROM quote_service_costs WHERE quote_id = $quote_id", 'Errore eliminazione costi servizi');
    $runQuery("DELETE FROM quote_attachments WHERE quote_id = $quote_id", 'Errore eliminazione allegati preventivo');
    $runQuery("DELETE FROM quote_history WHERE quote_id = $quote_id", 'Errore eliminazione storico preventivo');

    if ($quote_action === 'delete') {
        $runQuery("DELETE FROM quotes WHERE id = $quote_id", 'Errore eliminazione preventivo');
        $success_msg = 'event_and_quote_deleted';
    } else {
        $runQuery("UPDATE quotes SET status = 'rifiutato' WHERE id = $quote_id", 'Errore aggiornamento stato preventivo');
        $success_msg = 'event_deleted_quote_rejected';
    }

    mysqli_commit($db);

    header("Location: assign_staff.php?success=$success_msg");
    exit;
} catch (Exception $e) {
    mysqli_rollback($db);
    header("Location: assign_staff.php?quote_id=$quote_id&error=delete_failed");
    exit;
}
?>
