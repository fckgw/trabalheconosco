<?php
class Database {
    private static $instance = null;

    public static function getConnection() {
        if (!self::$instance) {
            try {
                // Lista de IPs locais (XAMPP/WAMP)
                $whitelist = array('127.0.0.1', '::1', 'localhost');

                // Se estiver no XAMPP (Local)
                if(in_array($_SERVER['REMOTE_ADDR'], $whitelist)){
                    $host = 'localhost';
                    $db   = 'sistema_rh';
                    $user = 'root';
                    $pass = ''; 
                } 
                // Se estiver na Locaweb Homologação
                else {
                //Base de Homologação
                $host = 'recrutamento.mysql.dbaas.com.br';
                $db   = 'recrutamento';
                $user = 'recrutamento';
                $pass = 'BDSoft@1020';
                }
                
                self::$instance = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
                self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                
            } catch (PDOException $e) {
                // Em produção, evite mostrar o erro real
                die("Erro de conexão (Ambiente: " . (in_array($_SERVER['REMOTE_ADDR'], $whitelist) ? "Local" : "Produção") . ")");
            }
        }
        return self::$instance;
    }
}