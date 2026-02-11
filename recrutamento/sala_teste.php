<?php
session_start(); 
ini_set('display_errors', 1); error_reporting(E_ALL);
require 'includes/db.php';
require_once 'includes/email.php'; // Carrega classe de email

// 1. Segurança e Troca de Senha
if (!isset($_SESSION['nivel']) || $_SESSION['nivel'] !== 'candidato') { header("Location: index.php"); exit; }

$pdo = getDB(); 
$cand_id = $_SESSION['usuario_id']; 
$app_id = $_GET['app_id'] ?? 0;

// Verifica Senha
$meus_dados = $pdo->query("SELECT trocar_senha FROM candidatos WHERE id = $cand_id")->fetch();
if ($meus_dados['trocar_senha'] == 1) { header("Location: nova_senha.php"); exit; }

// 2. Busca Aplicação e Vaga
$sql = "SELECT a.*, v.titulo, v.banco_teste_id, c.nome as nome_cand, c.email as email_cand 
        FROM aplicacoes a 
        JOIN vagas v ON a.vaga_id = v.id 
        JOIN candidatos c ON a.candidato_id = c.id
        WHERE a.id = ? AND a.candidato_id = ?";
$stmt = $pdo->prepare($sql); 
$stmt->execute([$app_id, $cand_id]);
$app = $stmt->fetch();

if(!$app || $app['status'] != 'teste_pratico' || empty($app['banco_teste_id'])) die("Acesso negado ao teste.");

$testeCfg = $pdo->query("SELECT * FROM banco_testes WHERE id = " . $app['banco_teste_id'])->fetch();
$questoes = $pdo->query("SELECT * FROM questoes_teste WHERE banco_teste_id = " . $app['banco_teste_id'])->fetchAll();

if(count($questoes) == 0) die("Erro: Teste sem questões. Contate o RH.");

// 3. Cronômetro
if(!$app['data_inicio_teste']) { 
    $pdo->prepare("UPDATE aplicacoes SET data_inicio_teste = NOW() WHERE id = ?")->execute([$app_id]); 
    $pdo->prepare("INSERT INTO logs_testes (aplicacao_id, acao, detalhes) VALUES (?, 'Iniciou', 'Candidato abriu a prova')")->execute([$app_id]);
    $inicio = time(); 
} else { 
    $inicio = strtotime($app['data_inicio_teste']); 
}

$limite = ($testeCfg['tempo_limite'] > 0 ? $testeCfg['tempo_limite'] : 60) * 60;
$restante = $limite - (time() - $inicio);

// Tempo Esgotado
if($restante <= 0) {
    if(empty($app['data_fim_teste'])) {
        $pdo->prepare("UPDATE aplicacoes SET data_fim_teste = NOW(), resultado_teste = CONCAT(COALESCE(resultado_teste,''), ' [FECHADO PELO SISTEMA]'), status = 'aval_ergo' WHERE id = ?")->execute([$app_id]);
        $pdo->prepare("INSERT INTO logs_testes (aplicacao_id, acao, detalhes) VALUES (?, 'Tempo Esgotado', 'Sistema encerrou a prova automaticamente')")->execute([$app_id]);
    }
    echo "<script>alert('Tempo Esgotado! O teste foi encerrado.'); window.location.href='painel_candidato.php';</script>";
    exit;
}

// 4. FINALIZAR PROVA (POST)
if($_SERVER['REQUEST_METHOD'] == 'POST'){
    $respostas = $_POST['resp'] ?? [];
    $texto_respostas = "";
    foreach($respostas as $qid => $resp_texto) { $texto_respostas .= "[Q$qid]: " . strip_tags($resp_texto) . " || "; }

    $arq = "";
    if(isset($_FILES['arq_resp']) && $_FILES['arq_resp']['error'] == 0){
        $ext = pathinfo($_FILES['arq_resp']['name'], PATHINFO_EXTENSION);
        $nm = "Resp_" . $app_id . "_" . time() . "." . $ext;
        if(move_uploaded_file($_FILES['arq_resp']['tmp_name'], "uploads/testes_candidatos/" . $nm)) $arq = $nm;
    }
    
    // Atualiza BD
    $pdo->prepare("UPDATE aplicacoes SET resultado_teste = ?, data_fim_teste = NOW(), status = 'aval_ergo' WHERE id = ?")
        ->execute(["Respostas: $texto_respostas | Arq: $arq", $app_id]);
    
    $pdo->prepare("INSERT INTO logs_testes (aplicacao_id, acao, detalhes) VALUES (?, 'Finalizou', 'Entregue pelo candidato')")->execute([$app_id]);
    
    // --- ENVIO DE E-MAILS ---
    $mailer = new EmailHelper();
    
    // 1. Para o Candidato
    $msgCand = "Olá {$app['nome_cand']},<br><br>Recebemos suas respostas para o teste de <strong>{$app['titulo']}</strong>.<br>Nossa equipe técnica fará a avaliação em breve.<br><br>Atenciosamente,<br>RH Cedro";
    $mailer->enviar($app['email_cand'], $app['nome_cand'], "Confirmação de Teste - Cedro", $msgCand);

    // 2. Para o RH (Cópia)
    // Envia para o e-mail padrão do sistema ou um específico de RH
    $emailRH = 'no-reply@cedro.ind.br'; 
    $msgRH = "<h3>Novo Teste Entregue</h3><p><strong>Candidato:</strong> {$app['nome_cand']}</p><p><strong>Vaga:</strong> {$app['titulo']}</p><p>Acesse o painel administrativo para ver as respostas e dar o parecer técnico.</p>";
    $mailer->enviar($emailRH, "Equipe RH", "Alerta: Teste Realizado - {$app['nome_cand']}", $msgRH);

    echo "<script>alert('Prova enviada com sucesso! Você recebeu uma confirmação por e-mail.'); window.location.href='painel_candidato.php';</script>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"> <title>Prova Online</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { user-select: none; background: #eee; padding-bottom: 100px; }
        .timer-float { position: fixed; top: 10px; right: 20px; background: #dc3545; color: white; padding: 10px 20px; border-radius: 50px; font-weight: bold; font-size: 1.2rem; z-index: 9999; }
        .questao-box { background: white; padding: 30px; border-radius: 8px; margin-bottom: 20px; }
        .enunciado img { max-width: 100%; height: auto; }
    </style>
</head>
<body oncontextmenu="return false;">
    <div class="timer-float" id="timer">--:--</div>
    <div class="container mt-5">
        <h2 class="text-center mb-4"><?php echo htmlspecialchars($testeCfg['titulo']); ?></h2>
        <?php if(!empty($testeCfg['arquivo_anexo'])): ?>
            <div class="alert alert-info text-center"><a href="uploads/testes_rh/<?php echo $testeCfg['arquivo_anexo']; ?>" target="_blank" class="btn btn-sm btn-primary">Baixar Arquivo Anexo</a></div>
        <?php endif; ?>

        <form method="POST" id="formProva" enctype="multipart/form-data">
            <?php foreach($questoes as $i => $q): ?>
            <div class="questao-box">
                <h5 class="text-primary border-bottom pb-2">Questão <?php echo $i+1; ?></h5>
                <div class="mb-3"><?php echo $q['enunciado']; ?></div>
                <textarea name="resp[<?php echo $q['id']; ?>]" class="form-control" rows="5" placeholder="Sua resposta..."></textarea>
            </div>
            <?php endforeach; ?>
            <div class="questao-box bg-light"><label class="fw-bold">Anexo Final (Opcional):</label><input type="file" name="arq_resp" class="form-control"></div>
            <button type="submit" class="btn btn-success btn-lg w-100 py-3 fw-bold" onclick="return confirm('Finalizar?')">ENTREGAR PROVA</button>
        </form>
    </div>
    <script>
        let timeLeft = <?php echo $restante; ?>;
        setInterval(() => {
            timeLeft--;
            if(timeLeft <= 0) { document.getElementById('formProva').submit(); }
            let m = Math.floor(timeLeft / 60); let s = timeLeft % 60;
            document.getElementById('timer').innerText = (m<10?"0"+m:m) + ":" + (s<10?"0"+s:s);
        }, 1000);
        window.addEventListener('blur', () => { document.title = "⚠️ VOLTE!"; });
    </script>
</body>
</html>