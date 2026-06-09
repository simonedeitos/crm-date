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

$check_stmt = mysqli_prepare($db, 'SELECT id, cloned_from FROM quotes WHERE id = ?');
if ($check_stmt === false) {
    header('Location: assign_staff.php?error=delete_failed');
    exit;
}

mysqli_stmt_bind_param($check_stmt, 'i', $quote_id);
mysqli_stmt_execute($check_stmt);
$check_result = mysqli_stmt_get_result($check_stmt);
$check = $check_result ? mysqli_fetch_assoc($check_result) : null;
if ($check_result) {
    mysqli_free_result($check_result);
}
mysqli_stmt_close($check_stmt);

if (!$check || empty($check['cloned_from'])) {
    header('Location: assign_staff.php?error=not_cloned');
    exit;
}

$fetchRows = function ($sql, $types, $params, $errorMessage) use ($db) {
    $stmt = mysqli_prepare($db, $sql);
    if ($stmt === false) {
        throw new Exception($errorMessage . ': ' . mysqli_error($db));
    }

    if ($types !== '' && !mysqli_stmt_bind_param($stmt, $types, ...$params)) {
        $prepareError = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new Exception($errorMessage . ': ' . $prepareError);
    }

    if (!mysqli_stmt_execute($stmt)) {
        $executeError = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new Exception($errorMessage . ': ' . $executeError);
    }

    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    if ($result !== false) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
        mysqli_free_result($result);
    }

    mysqli_stmt_close($stmt);

    return $rows;
};

$runStatement = function ($sql, $types, $params, $errorMessage) use ($db) {
    $stmt = mysqli_prepare($db, $sql);
    if ($stmt === false) {
        throw new Exception($errorMessage . ': ' . mysqli_error($db));
    }

    if ($types !== '' && !mysqli_stmt_bind_param($stmt, $types, ...$params)) {
        $prepareError = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new Exception($errorMessage . ': ' . $prepareError);
    }

    if (!mysqli_stmt_execute($stmt)) {
        $executeError = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new Exception($errorMessage . ': ' . $executeError);
    }

    mysqli_stmt_close($stmt);
};

$uploadsRoot = realpath(dirname(__DIR__) . '/uploads');

$deleteFileIfPresent = function ($relativePath) use ($uploadsRoot) {
    if (empty($relativePath) || $uploadsRoot === false) {
        return;
    }

    $normalizedRelativePath = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);
    $fullPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . $normalizedRelativePath;
    if (!file_exists($fullPath)) {
        return;
    }

    $resolvedPath = realpath($fullPath);
    if ($resolvedPath === false) {
        throw new Exception("Percorso file non valido: $relativePath");
    }

    $uploadsPrefix = rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $isWithinUploads = ($resolvedPath === $uploadsRoot) || (strpos($resolvedPath, $uploadsPrefix) === 0);
    if (!$isWithinUploads) {
        throw new Exception("Percorso file non valido: $relativePath");
    }

    if (file_exists($resolvedPath) && !unlink($resolvedPath)) {
        $lastError = error_get_last();
        $errorDetails = isset($lastError['message']) ? ' - ' . $lastError['message'] : '';
        throw new Exception("Errore eliminazione file associato all'evento: $resolvedPath$errorDetails");
    }
};

mysqli_begin_transaction($db);

try {
    $items = $fetchRows('SELECT id FROM quote_items WHERE quote_id = ?', 'i', [$quote_id], 'Errore caricamento items evento');
    foreach ($items as $item) {
        $item_id = (int)$item['id'];

        $mediaFiles = $fetchRows('SELECT file_path FROM quote_item_media WHERE quote_item_id = ?', 'i', [$item_id], 'Errore caricamento media evento');
        foreach ($mediaFiles as $media) {
            $deleteFileIfPresent($media['file_path']);
        }

        $runStatement('DELETE FROM quote_item_media WHERE quote_item_id = ?', 'i', [$item_id], 'Errore eliminazione media evento');
        $runStatement('DELETE FROM quote_item_services WHERE quote_item_id = ?', 'i', [$item_id], 'Errore eliminazione servizi item');
        $runStatement('DELETE FROM quote_item_roles WHERE quote_item_id = ?', 'i', [$item_id], 'Errore eliminazione ruoli item');
    }

    $attachments = $fetchRows('SELECT file_path FROM quote_attachments WHERE quote_id = ?', 'i', [$quote_id], 'Errore caricamento allegati preventivo');
    foreach ($attachments as $attachment) {
        $deleteFileIfPresent($attachment['file_path']);
    }

    $runStatement('DELETE FROM quote_items WHERE quote_id = ?', 'i', [$quote_id], 'Errore eliminazione items evento');
    $runStatement('DELETE FROM quote_dates WHERE quote_id = ?', 'i', [$quote_id], 'Errore eliminazione date evento');
    $runStatement('DELETE FROM quote_staff_assignment WHERE quote_id = ?', 'i', [$quote_id], 'Errore eliminazione staff evento');
    $runStatement('DELETE FROM quote_service_costs WHERE quote_id = ?', 'i', [$quote_id], 'Errore eliminazione costi servizi');
    $runStatement('DELETE FROM quote_attachments WHERE quote_id = ?', 'i', [$quote_id], 'Errore eliminazione allegati preventivo');
    $runStatement('DELETE FROM quote_history WHERE quote_id = ?', 'i', [$quote_id], 'Errore eliminazione storico preventivo');

    if ($quote_action === 'delete') {
        $runStatement('DELETE FROM quotes WHERE id = ?', 'i', [$quote_id], 'Errore eliminazione preventivo');
        $success_msg = 'event_and_quote_deleted';
    } else {
        $runStatement("UPDATE quotes SET status = 'rifiutato' WHERE id = ?", 'i', [$quote_id], 'Errore aggiornamento stato preventivo');
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
