<?php
// ARQUIVO: includes/db.php

function getDB() {
    try {
        // DETECÇÃO DE AMBIENTE (Local vs Locaweb)
        $whitelist = array('127.0.0.1', '::1', 'localhost');
        
        if(in_array($_SERVER['REMOTE_ADDR'], $whitelist)){
            // AMBIENTE LOCAL
            $host = 'localhost';
            $db   = 'sistema_rh';
            $user = 'root';
            $pass = ''; 
        } else {
            //Base de Homologação
                $host = 'recrutamento.mysql.dbaas.com.br';
                $db   = 'recrutamento';
                $user = 'recrutamento';
                $pass = 'BDSoft@1020';
        }

        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        return $pdo;

    } catch (PDOException $e) {
        die("ERRO DE CONEXÃO: " . $e->getMessage());
    }
}
?>