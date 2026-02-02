<?php
session_start(); require 'includes/db.php';
if (!isset($_SESSION['nivel']) || $_SESSION['nivel'] !== 'candidato') { header("Location: index.php"); exit; }
$pdo = getDB(); $cand_id = $_SESSION['usuario_id']; $app_id = $_GET['app_id'];

// Busca dados
$app = $pdo->query("SELECT a.*, v.titulo, v.teste_pratico, v.arquivo_teste FROM aplicacoes a JOIN vagas v ON a.vaga_id=v.id WHERE a.id=$app_id AND a.candidato_id=$cand_id")->fetch();
if(!$app || $app['status']!='teste_pratico') die("Acesso negado.");

// Cronômetro (Inicia se não existir)
if(!$app['data_inicio_teste']) { $pdo->query("UPDATE aplicacoes SET data_inicio_teste=NOW() WHERE id=$app_id"); $inicio=time(); } 
else { $inicio = strtotime($app['data_inicio_teste']); }

$limite = 60*60; // 1 hora
$restante = $limite - (time() - $inicio);
if($restante <= 0) die("Tempo esgotado!");

// Salvar
if($_SERVER['REQUEST_METHOD']=='POST'){
    $resp = $_POST['resposta'];
    $arq = "";
    if(isset($_FILES['arq_resp']) && $_FILES['arq_resp']['error']==0){
        $nm = "Resp_".$app_id."_".time().".zip";
        move_uploaded_file($_FILES['arq_resp']['tmp_name'], "uploads/testes_candidatos/".$nm);
        $arq = $nm;
    }
    $pdo->prepare("UPDATE aplicacoes SET resultado_teste=?, data_fim_teste=NOW(), status='aval_ergo' WHERE id=?")->execute(["$resp | Arq: $arq", $app_id]);
    header("Location: painel_candidato.php");
}
?>
<!DOCTYPE html>
<html>
<head><title>Teste Prático</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light">
<div class="container mt-5">
    <div class="card shadow">
        <div class="card-header bg-dark text-white d-flex justify-content-between">
            <h4>Teste: <?php echo $app['titulo']; ?></h4>
            <h4 id="timer" class="text-warning">00:00</h4>
        </div>
        <div class="card-body">
            <h5>Instruções:</h5>
            <p><?php echo nl2br($app['teste_pratico']); ?></p>
            <?php if($app['arquivo_teste']): ?>
                <a href="uploads/testes_rh/<?php echo $app['arquivo_teste']; ?>" class="btn btn-outline-primary mb-3">Baixar Arquivo do Teste</a>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <label>Sua Resposta (Texto/Código):</label>
                <textarea name="resposta" class="form-control mb-3" rows="10"></textarea>
                <label>Anexar Arquivo (ZIP):</label>
                <input type="file" name="arq_resp" class="form-control mb-3">
                <button class="btn btn-success w-100">Finalizar Teste</button>
            </form>
        </div>
    </div>
</div>
<script>
let t = <?php echo $restante; ?>;
setInterval(()=>{ 
    t--; 
    let m=Math.floor(t/60), s=t%60; 
    document.getElementById('timer').innerText = m+":"+(s<10?"0"+s:s);
    if(t<=0) location.reload(); 
},1000);
</script>
</body>
</html>