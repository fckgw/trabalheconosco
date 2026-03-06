<?php
session_start(); 
ini_set('display_errors', 1); error_reporting(E_ALL);

require 'includes/db.php';
require_once 'includes/email.php'; // Carrega classe de email

// 1. SEGURANÇA E BLOQUEIOS
if (!isset($_SESSION['nivel']) || $_SESSION['nivel'] !== 'candidato') { header("Location: index.php"); exit; }

$pdo = getDB(); 
$cand_id = $_SESSION['usuario_id']; 
$app_id = $_GET['app_id'] ?? 0;

// Verifica Senha Obrigatória
$meus_dados = $pdo->query("SELECT trocar_senha FROM candidatos WHERE id = $cand_id")->fetch();
if ($meus_dados['trocar_senha'] == 1) { header("Location: nova_senha.php"); exit; }

// 2. BUSCA APLICAÇÃO E DADOS DO TESTE
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
$questoes = $pdo->query("SELECT * FROM questoes_teste WHERE banco_teste_id = " . $app['banco_teste_id'] . " ORDER BY id ASC")->fetchAll();

if(count($questoes) == 0) die("Erro: O teste não possui questões cadastradas. Contate o RH.");

// 3. LÓGICA DO CRONÔMETRO
if(!$app['data_inicio_teste']) { 
    $pdo->prepare("UPDATE aplicacoes SET data_inicio_teste = NOW() WHERE id = ?")->execute([$app_id]); 
    $pdo->prepare("INSERT INTO logs_testes (aplicacao_id, acao, detalhes) VALUES (?, 'Iniciou', 'Candidato abriu a prova')")->execute([$app_id]);
    $inicio = time(); 
} else { 
    $inicio = strtotime($app['data_inicio_teste']); 
}

$limite = ($testeCfg['tempo_limite'] > 0 ? $testeCfg['tempo_limite'] : 60) * 60;
$restante = $limite - (time() - $inicio);

// Se tempo esgotou
if($restante <= 0) {
    if(empty($app['data_fim_teste'])) {
        $pdo->prepare("UPDATE aplicacoes SET data_fim_teste = NOW(), resultado_teste = CONCAT(COALESCE(resultado_teste,''), ' [FECHADO PELO SISTEMA]'), status = 'aval_ergo' WHERE id = ?")->execute([$app_id]);
        $pdo->prepare("INSERT INTO logs_testes (aplicacao_id, acao, detalhes) VALUES (?, 'Tempo Esgotado', 'Sistema encerrou a prova automaticamente')")->execute([$app_id]);
    }
    echo "<script>alert('Tempo Esgotado! O teste foi encerrado.'); window.location.href='painel_candidato.php';</script>";
    exit;
}

// 4. FINALIZAR PROVA (PROCESSAMENTO POST)
if($_SERVER['REQUEST_METHOD'] == 'POST'){
    $respostas = $_POST['resp'] ?? [];
    $texto_respostas = "";
    
    // Concatena as respostas (Seja texto ou opção selecionada)
    foreach($respostas as $qid => $resp_texto) {
        $texto_respostas .= "[Questão $qid]: " . strip_tags($resp_texto) . " || \n"; 
    }

    // Upload de Arquivo Final (Se houver)
    $arq = "";
    if(isset($_FILES['arq_resp']) && $_FILES['arq_resp']['error'] == 0){
        $ext = pathinfo($_FILES['arq_resp']['name'], PATHINFO_EXTENSION);
        $nm = "Resp_" . $app_id . "_" . time() . "." . $ext;
        if(move_uploaded_file($_FILES['arq_resp']['tmp_name'], "uploads/testes_candidatos/" . $nm)) $arq = $nm;
    }
    
    // Atualiza BD e Muda Status
    $pdo->prepare("UPDATE aplicacoes SET resultado_teste = ?, data_fim_teste = NOW(), status = 'aval_ergo' WHERE id = ?")
        ->execute(["Respostas: $texto_respostas | Arq: $arq", $app_id]);
    
    $pdo->prepare("INSERT INTO logs_testes (aplicacao_id, acao, detalhes) VALUES (?, 'Finalizou', 'Entregue pelo candidato')")->execute([$app_id]);
    
    // --- ENVIO DE E-MAILS ---
    $mailer = new EmailHelper();
    
    // 1. Para o Candidato
    $msgCand = "Olá {$app['nome_cand']},<br><br>Recebemos suas respostas para o teste de <strong>{$app['titulo']}</strong>.<br>Nossa equipe técnica fará a avaliação em breve.<br><br>Atenciosamente,<br>RH Cedro";
    $mailer->enviar($app['email_cand'], $app['nome_cand'], "Confirmação de Teste - Cedro", $msgCand);

    // 2. Para o RH (Notificação)
    $emailRH = 'no-reply@cedro.ind.br'; 
    $msgRH = "<h3>Teste Entregue</h3><p><strong>Candidato:</strong> {$app['nome_cand']}</p><p><strong>Vaga:</strong> {$app['titulo']}</p><p>Acesse o painel para ver as respostas.</p>";
    $mailer->enviar($emailRH, "Equipe RH", "Teste Realizado - {$app['nome_cand']}", $msgRH);

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
        .timer-float { position: fixed; top: 10px; right: 20px; background: #dc3545; color: white; padding: 10px 20px; border-radius: 50px; font-weight: bold; font-size: 1.2rem; z-index: 9999; box-shadow: 0 4px 10px rgba(0,0,0,0.2); }
        .questao-box { background: white; padding: 30px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .enunciado { font-size: 1.1rem; color: #333; margin-bottom: 15px; }
        .enunciado img { max-width: 100%; height: auto; }
        /* Estilo para Radio Button Customizado */
        .option-label { cursor: pointer; padding: 10px; border: 1px solid #ddd; border-radius: 5px; width: 100%; display: block; transition: 0.2s; }
        .option-label:hover { background-color: #f8f9fa; }
        .form-check-input:checked + .option-label { background-color: #e8f0fe; border-color: #0d6efd; color: #0d6efd; font-weight: bold; }
    </style>
</head>
<body oncontextmenu="return false;">

    <div class="timer-float" id="timer">--:--</div>

    <div class="container mt-5">
        <h2 class="text-center mb-4"><?php echo htmlspecialchars($testeCfg['titulo']); ?></h2>
        
        <?php if(!empty($testeCfg['arquivo_anexo'])): ?>
            <div class="alert alert-info text-center shadow-sm">
                <i class="fas fa-paperclip"></i> Este teste possui material de apoio: 
                <a href="uploads/testes_rh/<?php echo $testeCfg['arquivo_anexo']; ?>" target="_blank" class="btn btn-sm btn-primary ms-2">Baixar Arquivo Anexo</a>
            </div>
        <?php endif; ?>

        <form method="POST" id="formProva" enctype="multipart/form-data">
            
            <?php foreach($questoes as $i => $q): ?>
            <div class="questao-box">
                <h5 class="text-primary fw-bold border-bottom pb-2 mb-3">Questão <?php echo $i+1; ?></h5>
                
                <div class="enunciado">
                    <?php echo $q['enunciado']; // Exibe HTML do Editor Rico ?>
                </div>
                
                <div class="mt-3">
                    <?php if ($q['tipo'] == 'multipla_escolha'): 
                        // --- LÓGICA DE MÚLTIPLA ESCOLHA ---
                        $stmtOp = $pdo->prepare("SELECT * FROM questoes_opcoes WHERE questao_id = ? ORDER BY id ASC");
                        $stmtOp->execute([$q['id']]);
                        $opcoes = $stmtOp->fetchAll();
                        
                        // Opcional: Embaralhar opções para evitar cola
                        shuffle($opcoes);
                    ?>
                        <p class="small text-muted fw-bold">Selecione uma alternativa:</p>
                        <?php foreach($opcoes as $opt): ?>
                            <div class="form-check mb-2 ps-0">
                                <input class="form-check-input d-none" type="radio" name="resp[<?php echo $q['id']; ?>]" value="<?php echo htmlspecialchars($opt['texto_opcao']); ?>" id="opt_<?php echo $opt['id']; ?>">
                                <label class="option-label" for="opt_<?php echo $opt['id']; ?>">
                                    <?php echo htmlspecialchars($opt['texto_opcao']); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>

                    <?php else: ?>
                        <!-- --- LÓGICA DISSERTATIVA --- -->
                        <label class="fw-bold mb-1">Sua Resposta:</label>
                        <textarea name="resp[<?php echo $q['id']; ?>]" class="form-control" rows="5" placeholder="Digite sua resposta aqui..."></textarea>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="questao-box bg-light border-top border-3 border-success">
                <h5 class="fw-bold">Anexo Final (Opcional)</h5>
                <p class="small text-muted">Se a prova solicitou o envio de algum arquivo (código, planilha, desenho), anexe abaixo.</p>
                <input type="file" name="arq_resp" class="form-control">
            </div>

            <div class="d-grid gap-2 mt-4 mb-5">
                <button type="submit" class="btn btn-success btn-lg py-3 fw-bold shadow" onclick="return confirm('Tem certeza que deseja finalizar a prova?')">
                    FINALIZAR E ENTREGAR PROVA
                </button>
            </div>
        </form>
    </div>

    <script>
        // CRONÔMETRO
        let timeLeft = <?php echo $restante; ?>;
        
        function updateTimer() {
            if(timeLeft <= 0) {
                document.getElementById('timer').innerText = "00:00";
                alert("TEMPO ESGOTADO! Sua prova será enviada automaticamente.");
                document.getElementById('formProva').submit();
                return;
            }
            
            let h = Math.floor(timeLeft / 3600);
            let m = Math.floor((timeLeft % 3600) / 60);
            let s = timeLeft % 60;
            
            let display = (h > 0 ? h + ":" : "") + (m < 10 ? "0" + m : m) + ":" + (s < 10 ? "0" + s : s);
            document.getElementById('timer').innerText = display;
            timeLeft--;
        }
        
        setInterval(updateTimer, 1000);
        updateTimer();

        // ANTI-COLA (Alerta ao sair da aba)
        window.addEventListener('blur', function() {
            document.title = "⚠️ VOLTE PARA A PROVA!";
        });
        window.addEventListener('focus', function() {
            document.title = "Prova Online";
        });
    </script>
</body>
</html>