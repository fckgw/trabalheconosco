<?php
class EmailHelper {
    private $host = 'smtp.office365.com';
    private $port = 587;
    private $user = 'no-reply@cedro.ind.br';
    private $pass = 'KZofy429';

    public function enviar($destinatario, $nomeDestinatario, $assunto, $mensagem) {
        try {
            // Abre conexão via Socket
            $socket = fsockopen($this->host, $this->port, $errno, $errstr, 15);
            if (!$socket) throw new Exception("Falha na conexão SMTP: $errstr");

            $this->serverCmd($socket, "220"); // Aguarda saudação
            
            // Handshake inicial
            fputs($socket, "EHLO " . $_SERVER['SERVER_NAME'] . "\r\n");
            $this->serverCmd($socket, "250");

            // Inicia criptografia TLS (Obrigatório para Office 365)
            fputs($socket, "STARTTLS\r\n");
            $this->serverCmd($socket, "220");
            
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new Exception("Falha ao iniciar criptografia TLS.");
            }

            // Handshake criptografado
            fputs($socket, "EHLO " . $_SERVER['SERVER_NAME'] . "\r\n");
            $this->serverCmd($socket, "250");

            // Autenticação
            fputs($socket, "AUTH LOGIN\r\n");
            $this->serverCmd($socket, "334");
            fputs($socket, base64_encode($this->user) . "\r\n");
            $this->serverCmd($socket, "334");
            fputs($socket, base64_encode($this->pass) . "\r\n");
            $this->serverCmd($socket, "235");

            // Envio
            fputs($socket, "MAIL FROM: <" . $this->user . ">\r\n");
            $this->serverCmd($socket, "250");
            fputs($socket, "RCPT TO: <" . $destinatario . ">\r\n");
            $this->serverCmd($socket, "250");

            // Cabeçalhos e Corpo
            fputs($socket, "DATA\r\n");
            $this->serverCmd($socket, "354");

            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: RH Cedro <" . $this->user . ">\r\n";
            $headers .= "To: $nomeDestinatario <$destinatario>\r\n";
            $headers .= "Subject: $assunto\r\n";

            fputs($socket, $headers . "\r\n" . $mensagem . "\r\n.\r\n");
            $this->serverCmd($socket, "250");

            // Encerra
            fputs($socket, "QUIT\r\n");
            fclose($socket);

            return true;

        } catch (Exception $e) {
            // Em produção, você pode gravar o erro em log
            // echo $e->getMessage(); 
            return false;
        }
    }

    // Função auxiliar para ler resposta do servidor
    private function serverCmd($socket, $expected) {
        $response = "";
        while (substr($response, 3, 1) != ' ') {
            $response = fgets($socket, 256);
        }
        if (substr($response, 0, 3) != $expected) {
            // Não vamos travar o sistema se o email falhar, apenas retornamos erro
            // throw new Exception("Erro SMTP: $response");
        }
    }
}
?>