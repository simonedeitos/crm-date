<?php
// Debug temporaneo - rimuovi dopo aver risolto
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'auth.php';
require_once 'functions.php';

requireLogin();

$db = getDBConnection();
$error = '';
$success = '';

// === GESTIONE AZIONI POST ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
            // Creazione nuovo preventivo
            $client_id = (int)$_POST['client_id'];
            $lead_id = !empty($_POST['lead_id']) ? (int)$_POST['lead_id'] : null;
            $quote_number = generateQuoteNumber();
            
            $query = "INSERT INTO quotes (quote_number, client_id, created_by, status, iva_rate, created_at) 
                      VALUES ('$quote_number', $client_id, {$_SESSION['user_id']}, 'inviato', 22, NOW())";
            
            if (mysqli_query($db, $query)) {
                $quote_id = mysqli_insert_id($db);
                
                // Se arriva da una segnalazione, aggiorna la segnalazione
                if ($lead_id) {
                    $update_lead = "UPDATE leads SET 
                                    quote_id = $quote_id, 
                                    status = 'preventivo_inviato',
                                    updated_at = NOW() 
                                    WHERE id = $lead_id";
                    mysqli_query($db, $update_lead);
                }
                
                logQuoteActivity($quote_id, 'created', ['quote_number' => $quote_number]);
                header("Location: quotes.php?id=$quote_id&success=created");
                exit;
            } else {
                $error = "Errore nella creazione del preventivo: " . mysqli_error($db);
            }
            break;
            
        case 'create_with_new_client':
            // Crea prima il cliente
            $company_name = mysqli_real_escape_string($db, trim($_POST['new_company_name'] ?? ''));
            $first_name = mysqli_real_escape_string($db, trim($_POST['new_first_name'] ?? ''));
            $last_name = mysqli_real_escape_string($db, trim($_POST['new_last_name'] ?? ''));
            $email = mysqli_real_escape_string($db, trim($_POST['new_email'] ?? ''));
            $phone = mysqli_real_escape_string($db, trim($_POST['new_phone'] ?? ''));
            
            // Validazione
            if (empty($company_name) && (empty($first_name) || empty($last_name))) {
                $error = "Inserisci il nome dell'azienda oppure nome e cognome del cliente";
                break;
            }
            
            // Inserisci cliente
            $client_query = "INSERT INTO clients (company_name, first_name, last_name, email, phone, created_by, created_at)
                            VALUES ('$company_name', '$first_name', '$last_name', '$email', '$phone', {$_SESSION['user_id']}, NOW())";
            
            if (mysqli_query($db, $client_query)) {
                $new_client_id = mysqli_insert_id($db);
                
                // Ora crea il preventivo
                $quote_number = generateQuoteNumber();
                $quote_query = "INSERT INTO quotes (quote_number, client_id, created_by, status, iva_rate, created_at) 
                               VALUES ('$quote_number', $new_client_id, {$_SESSION['user_id']}, 'inviato', 22, NOW())";
                
                if (mysqli_query($db, $quote_query)) {
                    $quote_id = mysqli_insert_id($db);
                    logQuoteActivity($quote_id, 'created', ['quote_number' => $quote_number]);
                    header("Location: quotes.php?id=$quote_id&success=created_with_client");
                    exit;
                } else {
                    $error = "Cliente creato ma errore nel preventivo: " . mysqli_error($db);
                }
            } else {
                $error = "Errore nella creazione del cliente: " . mysqli_error($db);
            }
            break;
            
        case 'update_status':
            // Aggiornamento stato preventivo
            $quote_id = (int)$_POST['quote_id'];
            $new_status = mysqli_real_escape_string($db, $_POST['status']);

            // VERIFICA PERMESSI
            $check = mysqli_query($db, "SELECT created_by, status, commercial_commission FROM quotes WHERE id = $quote_id");
            $q_data = mysqli_fetch_assoc($check);

            if (!isAdmin() && $q_data['created_by'] != $_SESSION['user_id']) {
                $error = "Non hai i permessi per modificare questo preventivo.";
            } else {
                // Se si sta CONFERMANDO il preventivo
                if ($new_status === 'confermato') {
                    // 1. Verifica che tutti i pacchetti abbiano una data
                    $items_check = mysqli_query($db, "SELECT qi.id, qi.package_name, qi.event_date FROM quote_items qi WHERE qi.quote_id = $quote_id");
                    $missing_dates = [];
                    while ($item = mysqli_fetch_assoc($items_check)) {
                        if (empty($item['event_date'])) {
                            $missing_dates[] = $item['package_name'];
                        }
                    }

                    if (!empty($missing_dates)) {
                        $error = "Impossibile confermare: mancano le date per i pacchetti: " . implode(", ", $missing_dates);
                        break; // Interrompe il case
                    }

                    // 2. CALCOLO AUTOMATICO PROVVIGIONE
                    if ((float)$q_data['commercial_commission'] == 0) {
                        $calc_comm_query = "SELECT SUM(p.commission) as total_commission FROM quote_items qi JOIN packages p ON qi.package_id = p.id WHERE qi.quote_id = $quote_id AND qi.package_id IS NOT NULL";
                        $comm_result = mysqli_query($db, $calc_comm_query);
                        $comm_data = mysqli_fetch_assoc($comm_result);
                        $auto_commission = (float)($comm_data['total_commission'] ?? 0);

                        if ($auto_commission > 0) {
                            mysqli_query($db, "UPDATE quotes SET commercial_commission = $auto_commission, commission_type = 'cost' WHERE id = $quote_id");
                        }
                    }

                    // 3. ATTIVAZIONE AUTOMATICA GRAFICHE
                    $items_services_check = mysqli_query($db, "SELECT quote_item_id, service_name FROM quote_item_services WHERE quote_item_id IN (SELECT id FROM quote_items WHERE quote_id = $quote_id)");
                    $items_with_graphics = [];
                    while ($service = mysqli_fetch_assoc($items_services_check)) {
                        if (stripos($service['service_name'], 'Creatività Grafica') !== false) {
                            $items_with_graphics[] = (int)$service['quote_item_id'];
                        }
                    }
                    if (!empty($items_with_graphics)) {
                        $unique_item_ids = array_unique($items_with_graphics);
                        $ids_string = implode(',', $unique_item_ids);
                        mysqli_query($db, "UPDATE quote_items SET graphics_included = 1 WHERE id IN ($ids_string)");
                    }
                }

                // Procedi con l'aggiornamento dello stato
                $query = "UPDATE quotes SET status = '$new_status', updated_at = NOW() WHERE id = $quote_id";

                if (mysqli_query($db, $query)) {
                    if ($new_status === 'confermato' && $q_data['status'] !== 'confermato') {
                        // Logica aggiuntiva per stato confermato (es. notifica admin)
                    }
                    if ($new_status === 'rifiutato') {
                        // Svuota tutti i dati di assegnazione staff
                        mysqli_query($db, "DELETE FROM quote_staff_assignment WHERE quote_id = $quote_id");
                        mysqli_query($db, "DELETE FROM quote_service_costs WHERE quote_id = $quote_id");
                        // Resetta importi e provvigioni
                        mysqli_query($db, "UPDATE quotes SET 
                            invoice_amount = 0, 
                            extra_amount = 0, 
                            commercial_commission = 0, 
                            deposit_amount = 0,
                            staff_management_status = 'pending'
                            WHERE id = $quote_id");
                    }
                    logQuoteActivity($quote_id, 'status_updated', ['new_status' => $new_status]);
                    header("Location: quotes.php?id=$quote_id&success=status_updated");
                    exit;
                } else {
                    $error = "Errore nell'aggiornamento stato: " . mysqli_error($db);
                }
            }
            break;
            

            
        case 'duplicate':
            // Duplicazione preventivo
            $source_id = (int)$_POST['quote_id'];
            
            // Verifica permessi
            $check = mysqli_query($db, "SELECT * FROM quotes WHERE id = $source_id");
            $source = mysqli_fetch_assoc($check);
            
            if (isAdmin() || $source['created_by'] == $_SESSION['user_id']) {
                $new_quote_number = generateQuoteNumber();
                
                // Copia preventivo base
                $query = "INSERT INTO quotes (quote_number, client_id, created_by, status, subtotal, discount_type, 
                          discount_value, total, iva_rate, iva_amount, total_with_iva, internal_notes, created_at)
                          SELECT '$new_quote_number', client_id, {$_SESSION['user_id']}, 'inviato', subtotal, 
                          discount_type, discount_value, total, iva_rate, iva_amount, total_with_iva, internal_notes, NOW()
                          FROM quotes WHERE id = $source_id";
                
                if (mysqli_query($db, $query)) {
                    $new_quote_id = mysqli_insert_id($db);
                    
                    // Copia items
                    $items_result = mysqli_query($db, "SELECT * FROM quote_items WHERE quote_id = $source_id");
                    while ($item = mysqli_fetch_assoc($items_result)) {
                        $old_item_id = $item['id'];
                        $item_package_id = !empty($item['package_id']) ? $item['package_id'] : 'NULL';
                        $item_package_name = mysqli_real_escape_string($db, $item['package_name']);
                        $item_services = mysqli_real_escape_string($db, $item['services']);
                        
                        // Inserisci nuovo item
                        mysqli_query($db, "INSERT INTO quote_items (quote_id, package_id, package_name, services, price, sort_order)
                                          VALUES ($new_quote_id, $item_package_id, '$item_package_name', 
                                          '$item_services', {$item['price']}, {$item['sort_order']})");
                        
                        $new_item_id = mysqli_insert_id($db);
                        
                        // Copia servizi specifici
                        mysqli_query($db, "INSERT INTO quote_item_services (quote_item_id, service_name, is_mandatory, sort_order)
                                          SELECT $new_item_id, service_name, is_mandatory, sort_order
                                          FROM quote_item_services WHERE quote_item_id = $old_item_id");
                        
                        // Copia ruoli specifici
                        mysqli_query($db, "INSERT INTO quote_item_roles (quote_item_id, role_id, role_name, quantity)
                                          SELECT $new_item_id, role_id, role_name, quantity
                                          FROM quote_item_roles WHERE quote_item_id = $old_item_id");
                    }
                    
                    // Ricalcola totali
                    recalculateQuoteTotals($new_quote_id);
                    
                    logQuoteActivity($new_quote_id, 'duplicated', ['source_id' => $source_id]);
                    
                    header("Location: quotes.php?id=$new_quote_id&success=duplicated");
                    exit;
                } else {
                    $error = "Errore nella duplicazione: " . mysqli_error($db);
                }
            } else {
                $error = "Non hai i permessi per duplicare questo preventivo";
            }
            break;
            
        case 'delete':
            // Eliminazione preventivo (dalla lista)
            $quote_id = (int)$_POST['quote_id'];
            
            // Solo admin può eliminare
            if (isAdmin()) {
                // Ottieni tutti gli item IDs prima di eliminarli
                $items_result = mysqli_query($db, "SELECT id FROM quote_items WHERE quote_id = $quote_id");
                while ($item = mysqli_fetch_assoc($items_result)) {
                    // Elimina servizi e ruoli specifici di ogni item
                    mysqli_query($db, "DELETE FROM quote_item_services WHERE quote_item_id = {$item['id']}");
                    mysqli_query($db, "DELETE FROM quote_item_roles WHERE quote_item_id = {$item['id']}");
                }
                
                // Elimina items e altre relazioni
                mysqli_query($db, "DELETE FROM quote_items WHERE quote_id = $quote_id");
                mysqli_query($db, "DELETE FROM quote_dates WHERE quote_id = $quote_id");
                mysqli_query($db, "DELETE FROM quote_attachments WHERE quote_id = $quote_id");
                mysqli_query($db, "DELETE FROM quote_staff_assignment WHERE quote_id = $quote_id");
                
                // Elimina history
                mysqli_query($db, "DELETE FROM quote_history WHERE quote_id = $quote_id");
                
                // Elimina preventivo
                $query = "DELETE FROM quotes WHERE id = $quote_id";
                if (mysqli_query($db, $query)) {
                    header("Location: quotes.php?success=deleted");
                    exit;
                } else {
                    $error = "Errore nell'eliminazione: " . mysqli_error($db);
                }
            } else {
                $error = "Solo gli admin possono eliminare preventivi";
            }
            break;
            
        case 'delete_quote':
            // Eliminazione preventivo completa (dal dettaglio)
            $quote_id = (int)$_POST['quote_id'];
            
            // Verifica permessi
            $check = mysqli_query($db, "SELECT created_by FROM quotes WHERE id = $quote_id");
            $quote = mysqli_fetch_assoc($check);
            
            if (isAdmin() || $quote['created_by'] == $_SESSION['user_id']) {
                // LOG PRIMA DELL'ELIMINAZIONE (altrimenti foreign key fallisce)
                logQuoteActivity($quote_id, 'deleted');
                
                // Ottieni tutti gli item IDs prima di eliminarli
                $items_result = mysqli_query($db, "SELECT id FROM quote_items WHERE quote_id = $quote_id");
                while ($item = mysqli_fetch_assoc($items_result)) {
                    // Elimina servizi e ruoli specifici di ogni item
                    mysqli_query($db, "DELETE FROM quote_item_services WHERE quote_item_id = {$item['id']}");
                    mysqli_query($db, "DELETE FROM quote_item_roles WHERE quote_item_id = {$item['id']}");
                }
                
                // Elimina items e altre relazioni
                mysqli_query($db, "DELETE FROM quote_items WHERE quote_id = $quote_id");
                mysqli_query($db, "DELETE FROM quote_dates WHERE quote_id = $quote_id");
                
                // Elimina allegati fisici
                $att_result = mysqli_query($db, "SELECT file_path FROM quote_attachments WHERE quote_id = $quote_id");
                while ($att = mysqli_fetch_assoc($att_result)) {
                    if (file_exists($att['file_path'])) {
                        @unlink($att['file_path']);
                    }
                }
                mysqli_query($db, "DELETE FROM quote_attachments WHERE quote_id = $quote_id");
                mysqli_query($db, "DELETE FROM quote_staff_assignment WHERE quote_id = $quote_id");
                
                // Elimina history
                mysqli_query($db, "DELETE FROM quote_history WHERE quote_id = $quote_id");
                
                // Elimina preventivo
                $query = "DELETE FROM quotes WHERE id = $quote_id";
                if (mysqli_query($db, $query)) {
                    header("Location: quotes.php?success=deleted");
                    exit;
                } else {
                    $error = "Errore nell'eliminazione: " . mysqli_error($db);
                }
            } else {
                $error = "Non hai i permessi per eliminare questo preventivo";
            }
            break;
            
        case 'add_note':
            // Aggiunta nota interna
            $quote_id = (int)$_POST['quote_id'];
            $note = mysqli_real_escape_string($db, $_POST['internal_notes']);
            
            $query = "UPDATE quotes SET internal_notes = '$note', updated_at = NOW() WHERE id = $quote_id";
            if (mysqli_query($db, $query)) {
                logQuoteActivity($quote_id, 'note_added');
                header("Location: quotes.php?id=$quote_id&success=note_saved");
                exit;
            } else {
                $error = "Errore: " . mysqli_error($db);
            }
            break;
    }
    
    // === GESTORI FUORI DALLO SWITCH (senza campo 'action') ===
    
    // Aggiungi pacchetto
    if (isset($_POST['add_package'])) {
        $quote_id = (int)($_GET['id'] ?? 0);
        $package_id = (int)($_POST['package_id'] ?? 0);
        $custom_price = !empty($_POST['custom_price']) ? (float)$_POST['custom_price'] : null;
        
        if ($quote_id && $package_id) {
            // Ottieni info pacchetto
            $pkg_result = mysqli_query($db, "SELECT * FROM packages WHERE id = $package_id");
            
            if (!$pkg_result) {
                $error = "Errore query pacchetto: " . mysqli_error($db);
            } else {
                $package = mysqli_fetch_assoc($pkg_result);
                
                if ($package) {
                    $price = $custom_price ?? $package['price'];
                    $package_name = mysqli_real_escape_string($db, $package['name']);
                    
                    // Ottieni il prossimo sort_order
                    $sort_result = mysqli_query($db, "SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM quote_items WHERE quote_id = $quote_id");
                    $sort_row = mysqli_fetch_assoc($sort_result);
                    $next_order = $sort_row['next_order'] ?? 1;
                    
                    // Inserisci item (il campo 'services' è deprecato ma lo teniamo per retrocompatibilità)
                    $query = "INSERT INTO quote_items (quote_id, package_id, package_name, services, price, sort_order)
                              VALUES ($quote_id, $package_id, '$package_name', '', $price, $next_order)";
                    
                    if (mysqli_query($db, $query)) {
                        $new_item_id = mysqli_insert_id($db);
                        
                        // Copia servizi del pacchetto nelle tabelle specifiche del preventivo
                        $services_result = mysqli_query($db, "SELECT * FROM package_services WHERE package_id = $package_id ORDER BY sort_order");
                        $services_text_array = [];
                        while ($service = mysqli_fetch_assoc($services_result)) {
                            $svc_name = mysqli_real_escape_string($db, $service['service_name']);
                            $services_text_array[] = $service['service_name'];
                            mysqli_query($db, "INSERT INTO quote_item_services (quote_item_id, service_name, is_mandatory, sort_order) 
                                              VALUES ($new_item_id, '$svc_name', {$service['is_mandatory']}, {$service['sort_order']})");
                        }
                        // Aggiorna campo 'services' deprecato
                        $services_text = mysqli_real_escape_string($db, implode("\n", $services_text_array));
                        mysqli_query($db, "UPDATE quote_items SET services = '$services_text' WHERE id = $new_item_id");
                        
                        // Copia ruoli del pacchetto nelle tabelle specifiche del preventivo
                        $roles_result = mysqli_query($db, "SELECT psr.*, sr.name FROM package_staff_roles psr 
                                                          JOIN staff_roles sr ON psr.role_id = sr.id 
                                                          WHERE psr.package_id = $package_id");
                        while ($role = mysqli_fetch_assoc($roles_result)) {
                            $role_name = mysqli_real_escape_string($db, $role['name']);
                            mysqli_query($db, "INSERT INTO quote_item_roles (quote_item_id, role_id, role_name, quantity) 
                                              VALUES ($new_item_id, {$role['role_id']}, '$role_name', {$role['quantity']})");
                        }
                        
                        // Ricalcola totali
                        recalculateQuoteTotals($quote_id);
                        logQuoteActivity($quote_id, 'package_added', ['package_name' => $package_name]);
                        
                        header("Location: quotes.php?id=$quote_id&success=package_added");
                        exit;
                    } else {
                        $error = "Errore nell'aggiunta del pacchetto: " . mysqli_error($db);
                    }
                } else {
                    $error = "Pacchetto non trovato";
                }
            }
        } else {
            $error = "Dati mancanti per aggiungere il pacchetto (quote_id: $quote_id, package_id: $package_id)";
        }
    }
    
    // Aggiungi servizio personalizzato
    if (isset($_POST['add_custom_service'])) {
        $quote_id = (int)($_GET['id'] ?? 0);
        $service_name = mysqli_real_escape_string($db, trim($_POST['service_name'] ?? ''));
        $service_price = (float)($_POST['service_price'] ?? 0.0);
        $service_description = mysqli_real_escape_string($db, trim($_POST['service_description'] ?? ''));
        
        // CORREZIONE: Permetti prezzo 0, ma richiedi un nome
        if ($quote_id && !empty($service_name)) {
            // Ottieni il prossimo sort_order
            $sort_result = mysqli_query($db, "SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM quote_items WHERE quote_id = $quote_id");
            $sort_row = mysqli_fetch_assoc($sort_result);
            $next_order = $sort_row['next_order'] ?? 1;
            
            $query = "INSERT INTO quote_items (quote_id, package_id, package_name, services, price, sort_order)
                      VALUES ($quote_id, NULL, '$service_name', '$service_description', $service_price, $next_order)";
            
            if (mysqli_query($db, $query)) {
                recalculateQuoteTotals($quote_id);
                logQuoteActivity($quote_id, 'service_added', ['service_name' => $service_name]);
                
                header("Location: quotes.php?id=$quote_id&success=service_added");
                exit;
            } else {
                $error = "Errore nell'aggiunta del servizio: " . mysqli_error($db);
            }
        } else {
            $error = "Compila tutti i campi obbligatori (Nome servizio)";
        }
    }
    
    // Modifica elemento
    if (isset($_POST['edit_item'])) {
        $quote_id = (int)($_GET['id'] ?? 0);
        $item_id = (int)($_POST['item_id'] ?? 0);
        $item_price = (float)($_POST['item_price'] ?? 0.0);
        
        if ($quote_id && $item_id) {
            // Aggiorna prezzo
            $query = "UPDATE quote_items SET price = $item_price WHERE id = $item_id";
            
            if (mysqli_query($db, $query)) {
                // === GESTIONE SERVIZI SPECIFICI DEL PREVENTIVO ===
                
                // Elimina i servizi esistenti di QUESTO specifico item
                mysqli_query($db, "DELETE FROM quote_item_services WHERE quote_item_id = $item_id");
                
                // Inserisci i nuovi servizi (se presenti)
                $services = [];
                if (isset($_POST['services']) && is_array($_POST['services'])) {
                    $services = array_filter($_POST['services'], function($s) { return trim($s) != ''; });
                    $sort = 0;
                    foreach ($services as $idx => $service_name) {
                        $service_name_safe = mysqli_real_escape_string($db, trim($service_name));
                        $is_mandatory = isset($_POST['mandatory'][$idx]) ? 1 : 0;
                        
                        mysqli_query($db, "INSERT INTO quote_item_services (quote_item_id, service_name, is_mandatory, sort_order) 
                                          VALUES ($item_id, '$service_name_safe', $is_mandatory, $sort)");
                        $sort++;
                    }
                }
                
                // Aggiorna anche il campo services per retrocompatibilità
                $services_text = mysqli_real_escape_string($db, implode("\n", $services));
                mysqli_query($db, "UPDATE quote_items SET services = '$services_text' WHERE id = $item_id");
                
                
                // === GESTIONE RUOLI SPECIFICI DEL PREVENTIVO ===
                // Elimina i ruoli esistenti di QUESTO specifico item
                mysqli_query($db, "DELETE FROM quote_item_roles WHERE quote_item_id = $item_id");
                
                // Inserisci i nuovi ruoli (se presenti)
                if (isset($_POST['roles']) && is_array($_POST['roles'])) {
                    foreach ($_POST['roles'] as $role_id => $quantity) {
                        $role_id = (int)$role_id;
                        $quantity = (int)$quantity;
                        
                        if ($quantity > 0) {
                            // Ottieni il nome del ruolo
                            $role_result = mysqli_query($db, "SELECT name FROM staff_roles WHERE id = $role_id");
                            $role_data = mysqli_fetch_assoc($role_result);
                            $role_name = mysqli_real_escape_string($db, $role_data['name']);
                            
                            mysqli_query($db, "INSERT INTO quote_item_roles (quote_item_id, role_id, role_name, quantity) 
                                              VALUES ($item_id, $role_id, '$role_name', $quantity)");
                        }
                    }
                }
                
                recalculateQuoteTotals($quote_id);
                logQuoteActivity($quote_id, 'item_updated', ['item_id' => $item_id]);
                
                header("Location: quotes.php?id=$quote_id&success=item_updated");
                exit;
            } else {
                $error = "Errore nell'aggiornamento: " . mysqli_error($db);
            }
        } else {
            $error = "Dati mancanti per aggiornare l'elemento";
        }
    }
    
    // Aggiungi/aggiorna location globale
    if (isset($_POST['update_location'])) {
        $quote_id = (int)($_GET['id'] ?? 0);
        $location = mysqli_real_escape_string($db, trim($_POST['event_location'] ?? ''));
        
        if ($quote_id) {
            $query = "UPDATE quotes SET event_location = '$location', updated_at = NOW() WHERE id = $quote_id";
            
            if (mysqli_query($db, $query)) {
                logQuoteActivity($quote_id, 'location_updated', ['location' => $location]);
                header("Location: quotes.php?id=$quote_id&success=location_updated");
                exit;
            } else {
                $error = "Errore nell'aggiornamento della location: " . mysqli_error($db);
            }
        }
    }

    // Aggiorna data/ora pacchetto
    if (isset($_POST['update_item_datetime'])) {
        $quote_id = (int)($_GET['id'] ?? 0);
        $item_id = (int)($_POST['item_id'] ?? 0);
        $event_date = !empty($_POST['event_date']) ? mysqli_real_escape_string($db, $_POST['event_date']) : null;
        $event_time = !empty($_POST['event_time']) ? mysqli_real_escape_string($db, $_POST['event_time']) : null;
        
        if ($quote_id && $item_id) {
            $date_sql = $event_date ? "'$event_date'" : "NULL";
            $time_sql = $event_time ? "'$event_time'" : "NULL";
            
            $query = "UPDATE quote_items SET event_date = $date_sql, event_time = $time_sql WHERE id = $item_id AND quote_id = $quote_id";
            
            if (mysqli_query($db, $query)) {
                logQuoteActivity($quote_id, 'item_datetime_updated', ['item_id' => $item_id]);
                header("Location: quotes.php?id=$quote_id&success=datetime_updated");
                exit;
            } else {
                $error = "Errore nell'aggiornamento data/ora: " . mysqli_error($db);
            }
        }
    }
    
    // Aggiorna sconto
    if (isset($_POST['update_discount'])) {
        $quote_id = (int)($_GET['id'] ?? 0);
        $discount_type = mysqli_real_escape_string($db, $_POST['discount_type'] ?? 'none');
        $discount_value = (float)($_POST['discount_value'] ?? 0);
        
        if ($quote_id) {
            if ($discount_type === 'none') {
                $discount_value = 0;
            }
            
            $query = "UPDATE quotes SET discount_type = '$discount_type', discount_value = $discount_value, updated_at = NOW() 
                      WHERE id = $quote_id";
            
            if (mysqli_query($db, $query)) {
                recalculateQuoteTotals($quote_id);
                logQuoteActivity($quote_id, 'discount_updated', ['type' => $discount_type, 'value' => $discount_value]);
                
                header("Location: quotes.php?id=$quote_id&success=discount_updated");
                exit;
            } else {
                $error = "Errore nell'aggiornamento dello sconto: " . mysqli_error($db);
            }
        }
    }
    
} // Fine POST

// === GESTIONE ELIMINAZIONI VIA GET ===

// Elimina elemento
if (isset($_GET['delete_item'])) {
    $item_id = (int)$_GET['delete_item'];
    $quote_id = (int)($_GET['id'] ?? 0);
    
    if ($quote_id && $item_id) {
        // Elimina servizi e ruoli specifici dell'item
        mysqli_query($db, "DELETE FROM quote_item_services WHERE quote_item_id = $item_id");
        mysqli_query($db, "DELETE FROM quote_item_roles WHERE quote_item_id = $item_id");
        
        // Elimina l'item
        $query = "DELETE FROM quote_items WHERE id = $item_id AND quote_id = $quote_id";
        if (mysqli_query($db, $query)) {
            recalculateQuoteTotals($quote_id);
            logQuoteActivity($quote_id, 'item_deleted', ['item_id' => $item_id]);
            
            header("Location: quotes.php?id=$quote_id&success=item_deleted");
            exit;
        }
    }
}

// Elimina data
if (isset($_GET['delete_date'])) {
    $date_id = (int)$_GET['delete_date'];
    $quote_id = (int)($_GET['id'] ?? 0);
    
    if ($quote_id && $date_id) {
        $query = "DELETE FROM quote_dates WHERE id = $date_id AND quote_id = $quote_id";
        if (mysqli_query($db, $query)) {
            logQuoteActivity($quote_id, 'date_deleted', ['date_id' => $date_id]);
            header("Location: quotes.php?id=$quote_id&success=date_deleted");
            exit;
        }
    }
}

// Gestione upload allegati
if (isset($_FILES['attachment']) && isset($_POST['quote_id'])) {
    $quote_id = (int)$_POST['quote_id'];
    $upload = uploadFile($_FILES['attachment'], $quote_id);
    
    if ($upload['success']) {
        $filename = mysqli_real_escape_string($db, $upload['filename']);
        $original_name = mysqli_real_escape_string($db, $upload['original_name']);
        $filepath = mysqli_real_escape_string($db, $upload['filepath']);
        $filetype = mysqli_real_escape_string($db, $upload['type']);
        $filesize = (int)$upload['size'];
        
        $query = "INSERT INTO quote_attachments (quote_id, filename, original_name, file_path, file_type, file_size, uploaded_by, uploaded_at)
                  VALUES ($quote_id, '$filename', '$original_name', '$filepath', '$filetype', $filesize, {$_SESSION['user_id']}, NOW())";
        
        if (mysqli_query($db, $query)) {
            logQuoteActivity($quote_id, 'attachment_added', ['filename' => $original_name]);
            header("Location: quotes.php?id=$quote_id&success=file_uploaded");
            exit;
        } else {
            $error = "Errore nel salvataggio del file: " . mysqli_error($db);
        }
    } else {
        $error = $upload['error'];
    }
}

// Gestione eliminazione allegato
if (isset($_GET['delete_attachment'])) {
    $attachment_id = (int)$_GET['delete_attachment'];
    $quote_id = (int)($_GET['id'] ?? 0);
    
    // Ottieni info file
    $result = mysqli_query($db, "SELECT * FROM quote_attachments WHERE id = $attachment_id");
    $attachment = mysqli_fetch_assoc($result);
    
    if ($attachment) {
        // Elimina file fisico
        if (file_exists($attachment['file_path'])) {
            @unlink($attachment['file_path']);
        }
        
        // Elimina record
        mysqli_query($db, "DELETE FROM quote_attachments WHERE id = $attachment_id");
        logQuoteActivity($attachment['quote_id'], 'attachment_deleted', ['filename' => $attachment['original_name']]);
        
        header("Location: quotes.php?id=$quote_id&success=file_deleted");
        exit;
    }
}

// === VISTA SINGOLO PREVENTIVO ===
if (isset($_GET['id'])) {
    $quote_id = (int)$_GET['id'];
    
    // Query preventivo con cliente
    $query = "SELECT q.*, c.*, u.username as commercial_name
              FROM quotes q 
              JOIN clients c ON q.client_id = c.id 
              JOIN users u ON q.created_by = u.id
              WHERE q.id = $quote_id";
    
    // Se commerciale, mostra solo i suoi
    if (isCommerciale()) {
        $query .= " AND q.created_by = {$_SESSION['user_id']}";
    }
    
    $result = mysqli_query($db, $query);
    $quote = mysqli_fetch_assoc($result);
    
    if (!$quote) {
        header("Location: quotes.php?error=not_found");
        exit;
    }
    
    // Ottieni items del preventivo
    $items_result = mysqli_query($db, "SELECT * FROM quote_items WHERE quote_id = $quote_id ORDER BY sort_order");
    $items = [];
    while ($row = mysqli_fetch_assoc($items_result)) {
        // Aggiungi servizi e ruoli specifici all'item
        $item_services_res = mysqli_query($db, "SELECT * FROM quote_item_services WHERE quote_item_id = {$row['id']} ORDER BY sort_order");
        $row['specific_services'] = [];
        while ($service = mysqli_fetch_assoc($item_services_res)) {
            $row['specific_services'][] = $service;
        }

        $item_roles_res = mysqli_query($db, "SELECT * FROM quote_item_roles WHERE quote_item_id = {$row['id']}");
        $row['specific_roles'] = [];
        while ($role = mysqli_fetch_assoc($item_roles_res)) {
            $row['specific_roles'][] = $role;
        }

        $items[] = $row;
    }
    
    // Ottieni date eventi
    $dates_result = mysqli_query($db, "SELECT * FROM quote_dates WHERE quote_id = $quote_id ORDER BY event_date");
    $dates = [];
    while ($row = mysqli_fetch_assoc($dates_result)) {
        $dates[] = $row;
    }
    
    // Ottieni allegati
    $attachments_result = mysqli_query($db, "SELECT qa.*, u.username 
                                              FROM quote_attachments qa 
                                              LEFT JOIN users u ON qa.uploaded_by = u.id 
                                              WHERE qa.quote_id = $quote_id 
                                              ORDER BY qa.uploaded_at DESC");
    $attachments = [];
    while ($row = mysqli_fetch_assoc($attachments_result)) {
        $attachments[] = $row;
    }
    
    // Ottieni staff assegnato (solo admin)
    $staff_assignments = [];
    if (isAdmin()) {
        $staff_result = mysqli_query($db, "SELECT qsa.*, s.first_name, s.last_name, sr.name as role_name, qd.event_date
                                            FROM quote_staff_assignment qsa
                                            LEFT JOIN staff s ON qsa.staff_id = s.id
                                            JOIN staff_roles sr ON qsa.role_id = sr.id
                                            LEFT JOIN quote_dates qd ON qsa.quote_date_id = qd.id
                                            WHERE qsa.quote_id = $quote_id");
        while ($row = mysqli_fetch_assoc($staff_result)) {
            $staff_assignments[] = $row;
        }
    }
    
    $pageTitle = 'Preventivo ' . $quote['quote_number'];
    include 'includes/header.php';
    
    // INCLUDE VISTA DETTAGLIO
    include 'includes/quote_detail.php';
    
    include 'includes/footer.php';
    
} else {
    // === LISTA PREVENTIVI ===
    $pageTitle = 'Preventivi';
    
    // Filtri
    $filter_status = $_GET['status'] ?? '';
    $filter_search = $_GET['search'] ?? '';
    
    $query = "SELECT q.*, c.company_name, c.first_name, c.last_name, u.username as created_by_name
              FROM quotes q
              JOIN clients c ON q.client_id = c.id
              JOIN users u ON q.created_by = u.id
              WHERE 1=1";
    
    // Filtro commerciale
    if (isCommerciale()) {
        $query .= " AND q.created_by = {$_SESSION['user_id']}";
    }
    
    // Filtro status
    if ($filter_status) {
        $filter_status = mysqli_real_escape_string($db, $filter_status);
        $query .= " AND q.status = '$filter_status'";
    }
    
    // Filtro ricerca
    if ($filter_search) {
        $search = mysqli_real_escape_string($db, $filter_search);
        $query .= " AND (q.quote_number LIKE '%$search%' 
                    OR c.company_name LIKE '%$search%' 
                    OR c.first_name LIKE '%$search%' 
                    OR c.last_name LIKE '%$search%')";
    }
    
    $query .= " ORDER BY q.created_at DESC";
    
    $result = mysqli_query($db, $query);
    $quotes = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $quotes[] = $row;
    }
    
    // Ottieni lista clienti per nuovo preventivo
    $clients_query = "SELECT * FROM clients";
    
    // SE è commerciale, filtra solo i suoi clienti
    if (isCommerciale()) {
        $clients_query .= " WHERE created_by = {$_SESSION['user_id']}";
    }
    
    $clients_query .= " ORDER BY company_name, last_name, first_name";
    
    $clients_result = mysqli_query($db, $clients_query);
    $clients = [];
    while ($row = mysqli_fetch_assoc($clients_result)) {
        $clients[] = $row;
    }
    
    // Statistiche rapide
    $stats = [
        'total' => count($quotes),
        'inviati' => 0,
        'accettati' => 0,
        'confermati' => 0,
        'revenue' => 0,
        'revenue_no_iva' => 0
    ];
    
    // Query per statistiche totali (non solo filtrate)
    $stats_query = "SELECT status, total_with_iva, total FROM quotes";
    if(isCommerciale()) {
        $stats_query .= " WHERE created_by = {$_SESSION['user_id']}";
    }
    $all_quotes_stats = mysqli_query($db, $stats_query);
    $stats['total'] = mysqli_num_rows($all_quotes_stats);
    while($q_stat = mysqli_fetch_assoc($all_quotes_stats)) {
         if ($q_stat['status'] == 'inviato') $stats['inviati']++;
         if ($q_stat['status'] == 'accettato') $stats['accettati']++;
         if ($q_stat['status'] == 'confermato') {
            $stats['confermati']++;
            $stats['revenue'] += $q_stat['total_with_iva'];
            $stats['revenue_no_iva'] += $q_stat['total'];
        }
    }
    
    include 'includes/header.php';
    ?>
    
    <div class="container-fluid py-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2><i class="bi bi-file-text"></i> Preventivi</h2>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newQuoteModal">
                <i class="bi bi-plus-circle"></i> Nuovo Preventivo
            </button>
        </div>
        
        <!-- Messaggi -->
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible">
                <i class="bi bi-x-circle"></i> <?php echo e($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible">
                <i class="bi bi-check-circle"></i>
                <?php
                $msg = [
                    'created' => 'Preventivo creato!',
                    'created_with_client' => 'Cliente e preventivo creati!',
                    'duplicated' => 'Preventivo duplicato!',
                    'deleted' => 'Preventivo eliminato!',
                    'status_updated' => 'Stato aggiornato!',
                    'note_saved' => 'Nota salvata!',
                    'file_uploaded' => 'File caricato!',
                    'file_deleted' => 'File eliminato!',
                    'package_added' => 'Pacchetto aggiunto con successo!',
                    'service_added' => 'Servizio aggiunto con successo!',
                    'item_updated' => 'Elemento aggiornato!',
                    'item_deleted' => 'Elemento eliminato!',
                    'date_added' => 'Data aggiunta!',
                    'date_deleted' => 'Data eliminata!',
                    'discount_updated' => 'Sconto aggiornato!',
                    'location_updated' => 'Location aggiornata!',
                    'datetime_updated' => 'Data/ora pacchetto aggiornate!'
                ];
                echo $msg[$_GET['success']] ?? 'Operazione completata!';
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Statistiche -->
        <div class="row mb-3">
            <div class="col-md-3">
                <div class="card bg-light">
                    <div class="card-body py-2">
                        <small class="text-muted">Totale Preventivi</small>
                        <h4 class="mb-0"><?php echo $stats['total']; ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body py-2">
                        <small>Inviati</small>
                        <h4 class="mb-0"><?php echo $stats['inviati']; ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-dark">
                    <div class="card-body py-2">
                        <small>Accettati</small>
                        <h4 class="mb-0"><?php echo $stats['accettati']; ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body py-2">
                        <small>Confermati</small>
                        <h4 class="mb-0"><?php echo $stats['confermati']; ?></h4>
                        <small class="d-block mt-1">
                            <strong><?php echo formatPrice($stats['revenue_no_iva']); ?></strong> (+IVA: <?php echo formatPrice($stats['revenue']); ?>)
                        </small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filtri -->
        <div class="card mb-3">
            <div class="card-body py-2">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label small mb-1">Cerca</label>
                        <input type="text" class="form-control form-control-sm" name="search" 
                               placeholder="Numero preventivo o cliente..." 
                               value="<?php echo e($filter_search); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Stato</label>
                        <select class="form-select form-select-sm" name="status">
                            <option value="">Tutti</option>
                            <option value="bozza" <?php echo $filter_status == 'bozza' ? 'selected' : ''; ?>>Bozza</option>
                            <option value="inviato" <?php echo $filter_status == 'inviato' ? 'selected' : ''; ?>>Inviato</option>
                            <option value="accettato" <?php echo $filter_status == 'accettato' ? 'selected' : ''; ?>>Accettato</option>
                            <option value="confermato" <?php echo $filter_status == 'confermato' ? 'selected' : ''; ?>>Confermato</option>
                            <option value="rifiutato" <?php echo $filter_status == 'rifiutato' ? 'selected' : ''; ?>>Rifiutato</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-sm btn-secondary me-1">
                            <i class="bi bi-search"></i> Cerca
                        </button>
                        <a href="quotes.php" class="btn btn-sm btn-outline-secondary me-1">Reset</a>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="toggleInviatiBtn" onclick="toggleInviati()">
                            <i class="bi bi-eye-slash"></i> Nascondi Inviati
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Tabella Preventivi -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>N° Preventivo</th>
                                <th>Cliente</th>
                                <th>Data</th>
                                <th>Stato</th>
                                <th class="text-end">Totale</th>
                                <?php if (isAdmin()): ?>
                                <th>Creato da</th>
                                <?php endif; ?>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($quotes)): ?>
                            <tr>
                                <td colspan="<?php echo isAdmin() ? '7' : '6'; ?>" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox fs-1"></i><br>
                                    Nessun preventivo trovato
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($quotes as $q): 
                                    $client_name = $q['company_name'] ?: trim($q['first_name'] . ' ' . $q['last_name']);
                                ?>
                                <tr>
                                    <td><strong><?php echo e($q['quote_number']); ?></strong></td>
                                    <td><?php echo e($client_name); ?></td>
                                    <td><small><?php echo formatDate($q['created_at']); ?></small></td>
                                    <td><?php echo getStatusBadge($q['status']); ?></td>
                                    <td class="text-end"><strong><?php echo formatPrice($q['total']); ?></strong></td>
                                    <?php if (isAdmin()): ?>
                                    <td><small><?php echo e($q['created_by_name']); ?></small></td>
                                    <?php endif; ?>
                                    <td class="text-end">
                                        <a href="quotes.php?id=<?php echo $q['id']; ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Nuovo Preventivo -->
    <div class="modal fade" id="newQuoteModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <ul class="nav nav-tabs border-0" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="existing-client-tab" data-bs-toggle="tab" data-bs-target="#existingClientTab" type="button" role="tab" aria-controls="existingClientTab" aria-selected="true">
                                <i class="bi bi-person-check"></i> Cliente Esistente
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="new-client-tab" data-bs-toggle="tab" data-bs-target="#newClientTab" type="button" role="tab" aria-controls="newClientTab" aria-selected="false">
                                <i class="bi bi-person-plus"></i> Nuovo Cliente
                            </button>
                        </li>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="tab-content">
                    <!-- Tab Cliente Esistente -->
                    <div class="tab-pane fade show active p-3" id="existingClientTab" role="tabpanel" aria-labelledby="existing-client-tab">
                        <form method="POST">
                            <input type="hidden" name="action" value="create">
                            
                            <?php 
                            // Pre-seleziona cliente se arriva da lead
                            $preselected_client = null;
                            $lead_id_param = null;
                            
                            if (isset($_GET['from_lead']) && isset($_GET['client_id']) && isset($_GET['lead_id'])) {
                                $preselected_client = (int)$_GET['client_id'];
                                $lead_id_param = (int)$_GET['lead_id'];
                            }
                            ?>
                            
                            <?php if ($lead_id_param): ?>
                            <input type="hidden" name="lead_id" value="<?php echo $lead_id_param; ?>">
                            <div class="alert alert-info">
                                <i class="bi bi-megaphone"></i> <strong>Preventivo da Segnalazione</strong><br>
                                <small>Questo preventivo è collegato ad una segnalazione. Lo stato verrà aggiornato automaticamente.</small>
                            </div>
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Seleziona Cliente *</label>
                                <select name="client_id" class="form-select" required>
                                    <option value="">-- Scegli un cliente --</option>
                                    <?php foreach ($clients as $client): 
                                        $name = $client['company_name'] ?: trim($client['first_name'] . ' ' . $client['last_name']);
                                        $selected = ($preselected_client && $preselected_client == $client['id']) ? 'selected' : '';
                                    ?>
                                    <option value="<?php echo $client['id']; ?>" <?php echo $selected; ?>><?php echo e($name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($clients) && isCommerciale()): ?>
                                    <small class="text-danger d-block mt-2">
                                        <i class="bi bi-info-circle"></i> Non hai ancora creato nessun cliente. Usa la scheda "Nuovo Cliente" per iniziare.
                                    </small>
                                <?php endif; ?>
                            </div>
                            <div class="alert alert-info py-2">
                                <small><i class="bi bi-info-circle"></i> Il preventivo verrà creato con stato "Inviato".</small>
                            </div>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary" <?php echo (empty($clients) && isCommerciale()) ? 'disabled' : ''; ?>>
                                    <i class="bi bi-plus"></i> Crea Preventivo
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Tab Nuovo Cliente -->
                    <div class="tab-pane fade p-3" id="newClientTab" role="tabpanel" aria-labelledby="new-client-tab">
                        <form method="POST">
                            <input type="hidden" name="action" value="create_with_new_client">
                            <div class="alert alert-warning py-2">
                                <small><i class="bi bi-info-circle"></i> Compila <strong>Azienda</strong> o <strong>Nome e Cognome</strong></small>
                            </div>
                            
                            <div class="mb-2">
                                <label class="form-label fw-bold">Azienda</label>
                                <input type="text" name="new_company_name" class="form-control" 
                                       placeholder="Nome azienda (opzionale)">
                            </div>
                            <div class="row">
                                <div class="col-6 mb-2">
                                    <label class="form-label fw-bold">Nome</label>
                                    <input type="text" name="new_first_name" class="form-control">
                                </div>
                                <div class="col-6 mb-2">
                                    <label class="form-label fw-bold">Cognome</label>
                                    <input type="text" name="new_last_name" class="form-control">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-6 mb-2">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="new_email" class="form-control">
                                </div>
                                <div class="col-6 mb-2">
                                    <label class="form-label">Telefono</label>
                                    <input type="text" name="new_phone" class="form-control">
                                </div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-plus-circle"></i> Crea Cliente e Preventivo
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (isset($_GET['from_lead']) && isset($_GET['client_id'])): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Apri automaticamente il modal se arriva da segnalazione
        var modal = new bootstrap.Modal(document.getElementById('newQuoteModal'));
        modal.show();
    });
    </script>
    <?php endif; ?>
    
    <script>
    let hideInviati = false;

    function toggleInviati() {
        hideInviati = !hideInviati;
        const btn = document.getElementById('toggleInviatiBtn');
        const rows = document.querySelectorAll('tbody tr');
        
        rows.forEach(row => {
            const badge = row.querySelector('.badge');
            if (badge && badge.textContent.includes('Inviato')) {
                row.style.display = hideInviati ? 'none' : '';
            }
        });
        
        if (hideInviati) {
            btn.className = 'btn btn-sm btn-danger';
            btn.innerHTML = '<i class="bi bi-eye"></i> Mostra Inviati';
        } else {
            btn.className = 'btn btn-sm btn-outline-danger';
            btn.innerHTML = '<i class="bi bi-eye-slash"></i> Nascondi Inviati';
        }
    }
    </script>
    
    <?php
    include 'includes/footer.php';
}
?>