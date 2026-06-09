<?php
date_default_timezone_set('Europe/Rome');

$mysqlHostName = "mysql";
$mysqlUserName = "u362062795_crmdate";
$mysqlPassword = "#ciErreEmme.Date-2025!+";
$DbName = "u362062795_crmdate";

echo "<h3>Test Connessione Database</h3>";
echo "Host: $mysqlHostName<br>";
echo "User: $mysqlUserName<br>";
echo "Database: $DbName<br><br>";

$db = mysqli_connect($mysqlHostName, $mysqlUserName, $mysqlPassword, $DbName);

if($db === false){
    echo "❌ <b>ERRORE:</b> " . mysqli_connect_error();
    die();
}

echo "✅ <b>CONNESSIONE RIUSCITA!</b><br><br>";

// Verifica se la tabella users esiste
$result = mysqli_query($db, "SHOW TABLES LIKE 'users'");
if(mysqli_num_rows($result) > 0) {
    echo "✅ Tabella 'users' trovata<br>";
    
    // Conta utenti
    $count = mysqli_query($db, "SELECT COUNT(*) as total FROM users");
    $row = mysqli_fetch_assoc($count);
    echo "👥 Utenti nel database: " . $row['total'] . "<br>";
} else {
    echo "⚠️ Tabella 'users' NON trovata - devi eseguire lo script SQL di creazione!<br>";
}

mysqli_close($db);
?>