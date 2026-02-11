<?php
ini_set('display_errors', 1); error_reporting(E_ALL); require_once 'includes/db.php';
$pdo = getDB(); $vagas = $pdo->query("SELECT * FROM vagas WHERE status='aberta' ORDER BY id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"> <title>Vagas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style> body{background:#f8f9fa;} .card-vaga{border:none;border-radius:12px;box-shadow:0 4px 15px rgba(0,0,0,0.05);transition:0.3s;} .card-vaga:hover{transform:translateY(-5px);} </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark mb-5"><div class="container"><a class="navbar-brand"><img src="logo-branco.png" height="30"></a><a href="index.php" class="btn btn-outline-light btn-sm">Login</a></div></nav>
    <div class="container">
        <h2 class="text-center mb-5 fw-bold">Oportunidades Abertas</h2>
        <?php if(count($vagas)>0): ?>
        <div class="row g-4">
            <?php foreach($vagas as $v): 
                $salario = ($v['ocultar_salario'] == 1) ? 'A combinar' : $v['faixa_salarial'];
                $regime = $v['regime_contratacao'];
                $cor = match($regime) { 'CLT'=>'bg-primary','Estágio'=>'bg-info','Temporário'=>'bg-warning text-dark', default=>'bg-secondary' };
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="card card-vaga h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between mb-3"><span class="badge <?php echo $cor; ?>"><?php echo $regime; ?></span><small class="text-muted"><?php echo $v['localizacao']; ?></small></div>
                        <h5 class="card-title fw-bold"><?php echo htmlspecialchars($v['titulo']); ?></h5>
                        <p class="text-muted small"><?php echo $v['setor']; ?></p>
                        <p class="card-text text-secondary mb-4 flex-grow-1"><?php echo nl2br(substr($v['descricao'], 0, 100)); ?>...</p>
                        <div class="border-top pt-3 d-flex justify-content-between align-items-center">
                            <strong class="text-success small"><i class="fas fa-money-bill-wave"></i> <?php echo $salario; ?></strong>
                            <a href="detalhe_vaga.php?id=<?php echo $v['id']; ?>" class="btn btn-outline-dark btn-sm fw-bold">VER DETALHES</a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?><div class="text-center py-5"><h4>Nenhuma vaga no momento.</h4><a href="cadastro.php" class="btn btn-dark">Banco de Talentos</a></div><?php endif; ?>
    </div>
</body>
</html>