<?php
session_start(); require 'includes/db.php';
if (!isset($_SESSION['nivel']) || $_SESSION['nivel'] !== 'candidato') { header("Location: index.php"); exit; }
$pdo = getDB(); $id = $_SESSION['usuario_id'];
$apps = $pdo->query("SELECT a.*, v.titulo FROM aplicacoes a JOIN vagas v ON a.vaga_id=v.id WHERE a.candidato_id=$id")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head><title>Minha Área</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark mb-4"><div class="container"><a class="navbar-brand">Portal Candidato</a><a href="logout.php" class="btn btn-outline-light btn-sm">Sair</a></div></nav>
    <div class="container">
        <h4>Minhas Candidaturas</h4>
        <div class="row">
            <?php foreach($apps as $a): ?>
            <div class="col-md-6">
                <div class="card mb-3 shadow-sm">
                    <div class="card-body">
                        <h5><?php echo $a['titulo']; ?></h5>
                        <p>Status: <span class="badge bg-secondary"><?php echo strtoupper($a['status']); ?></span></p>
                        
                        <?php if($a['status']=='teste_pratico'): ?>
                            <div class="alert alert-warning">
                                <strong>Atenção!</strong> Você foi convocado para o Teste Prático.
                                <br>Você terá 60 minutos após clicar no botão.
                                <a href="sala_teste.php?app_id=<?php echo $a['id']; ?>" class="btn btn-primary w-100 mt-2">ENTRAR NA SALA VIRTUAL</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>