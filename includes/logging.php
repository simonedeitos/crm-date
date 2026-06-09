<?php

/**
 * Sistema di logging per tracciare le modifiche ai preventivi
 */
class QuoteLogger {
    
    private $db;
    
    public function __construct($database_connection) {
        $this->db = $database_connection;
    }
    
    /**
     * Log generico di un'azione
     */
    public function log($quote_id, $action, $changes = null, $old_values = null, $new_values = null, $user_id = null) {
        // Usa l'utente della sessione se non specificato
        if ($user_id === null && isset($_SESSION['user_id'])) {
            $user_id = (int)$_SESSION['user_id'];
        }
        
        // Prepara i dati
        $quote_id = (int)$quote_id;
        $action = mysqli_real_escape_string($this->db, $action);
        $changes_json = $changes ? mysqli_real_escape_string($this->db, json_encode($changes)) : null;
        $old_values_json = $old_values ? mysqli_real_escape_string($this->db, json_encode($old_values)) : null;
        $new_values_json = $new_values ? mysqli_real_escape_string($this->db, json_encode($new_values)) : null;
        $ip_address = mysqli_real_escape_string($this->db, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $user_agent = mysqli_real_escape_string($this->db, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500));
        
        $query = "INSERT INTO quote_history 
                  (quote_id, user_id, action, changes, old_values, new_values, ip_address, user_agent, created_at) 
                  VALUES 
                  ($quote_id, " . ($user_id ? $user_id : 'NULL') . ", '$action', " . 
                  ($changes_json ? "'$changes_json'" : 'NULL') . ", " . 
                  ($old_values_json ? "'$old_values_json'" : 'NULL') . ", " . 
                  ($new_values_json ? "'$new_values_json'" : 'NULL') . ", " . 
                  "'$ip_address', '$user_agent', NOW())";
        
        return mysqli_query($this->db, $query);
    }
    
    /**
     * Log creazione preventivo
     */
    public function logCreated($quote_id, $quote_data = null) {
        return $this->log($quote_id, 'created', ['message' => 'Preventivo creato'], null, $quote_data);
    }
    
    /**
     * Log modifica preventivo
     */
    public function logUpdated($quote_id, $old_data, $new_data) {
        $changes = $this->getChanges($old_data, $new_data);
        return $this->log($quote_id, 'updated', $changes, $old_data, $new_data);
    }
    
    /**
     * Log cambio stato
     */
    public function logStatusChanged($quote_id, $old_status, $new_status) {
        $changes = [
            'field' => 'status',
            'from' => $old_status,
            'to' => $new_status
        ];
        return $this->log($quote_id, 'status_changed', $changes);
    }
    
    /**
     * Log assegnazione staff
     */
    public function logStaffAssigned($quote_id, $staff_data) {
        $changes = [
            'staff_count' => count($staff_data),
            'message' => 'Staff assegnato al preventivo'
        ];
        return $this->log($quote_id, 'staff_assigned', $changes, null, $staff_data);
    }
    
    /**
     * Log aggiunta nota
     */
    public function logNoteAdded($quote_id, $note) {
        $changes = [
            'note' => substr($note, 0, 100) . (strlen($note) > 100 ? '...' : ''),
            'note_length' => strlen($note)
        ];
        return $this->log($quote_id, 'note_added', $changes);
    }
    
    /**
     * Log duplicazione preventivo
     */
    public function logDuplicated($original_quote_id, $new_quote_id) {
        $changes = [
            'original_quote_id' => $original_quote_id,
            'new_quote_id' => $new_quote_id,
            'message' => 'Preventivo duplicato'
        ];
        return $this->log($new_quote_id, 'duplicated', $changes);
    }
    
    /**
     * Log eliminazione
     */
    public function logDeleted($quote_id, $quote_data) {
        return $this->log($quote_id, 'deleted', ['message' => 'Preventivo eliminato'], $quote_data, null);
    }
    
    /**
     * Confronta due array e trova le differenze
     */
    private function getChanges($old_data, $new_data) {
        $changes = [];
        
        foreach ($new_data as $key => $new_value) {
            $old_value = $old_data[$key] ?? null;
            
            if ($old_value != $new_value) {
                $changes[$key] = [
                    'from' => $old_value,
                    'to' => $new_value
                ];
            }
        }
        
        return $changes;
    }
}

/**
 * Funzione helper per creare il logger
 */
function getQuoteLogger($db_connection) {
    return new QuoteLogger($db_connection);
}

/**
 * Funzione helper per log rapido
 */
function logQuoteActivity($quote_id, $action, $changes = null, $old_values = null, $new_values = null) {
    global $db;
    if (!$db) {
        $db = getDBConnection();
    }
    
    $logger = new QuoteLogger($db);
    return $logger->log($quote_id, $action, $changes, $old_values, $new_values);
}
?>