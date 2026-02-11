<?php
require_once 'includes/db.php'; $id = $_GET['id']??0;
$pdo = getDB(); $v = $pdo->query("SELECT * FROM vagas WHERE id=$id")->fetch();
if(!$v) die("Vaga não encontrada.");
$salario = ($v['ocultar_salario'] == 1) ? 'A combinar' : $v['faixa_salarial'];
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"> <title><?php echo $v['titulo']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style> body{background:#f8f9fa;} .header-vaga{background:white;padding:40px 0;border-bottom:1px solid #ddd;margin-bottom:30px;} </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark"><div class="container"><a class="navbar-brand"><img src="logo-branco.png" height="30"></a><a href="vagas.php" class="btn btn-outline-light btn-sm">Voltar</a></div></nav>
    
    <div class="header-vaga text-center">
        <div class="container">
            <span class="badge bg-secondary mb-2"><?php echo $v['setor']; ?></span>
            <h1 class="fw-bold"><?php echo $v['titulo']; ?></h1>
            <p class="text-muted"><i class="fas fa-map-marker-alt"></i> <?php echo $v['localizacao']; ?> | <i class="fas fa-clock"></i> Publicada em <?php echo date('d/m/Y', strtotime($v['created_at'])); ?></p>
        </div>
    </div>

    <div class="container mb-5">
        <div class="row">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm p-4 mb-4">
                    <h5 class="border-bottom pb-2 mb-3">Descrição</h5>
                    <p><?php echo nl2br($v['descricao']); ?></p>
                    <h5 class="border-bottom pb-2 mb-3 mt-4">Requisitos</h5>
                    <p><?php echo nl2br($v['requisitos']); ?></p>
                    
                    <?php if($v['exclusivo_pcd']): ?>
                        <div class="alert alert-primary mt-3"><i class="fas fa-wheelchair"></i> <strong>Vaga Exclusiva para PCD</strong></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm p-4">
                    <h5 class="text-center mb-4">Resumo</h5>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between"><span>Salário</span> <strong><?php echo $salario; ?></strong></li>
                        <li class="list-group-item d-flex justify-content-between"><span>Regime</span> <strong><?php echo $v['regime_contratacao']; ?></strong></li>
                        <li class="list-group-item d-flex justify-content-between"><span>Vagas</span> <strong><?php echo $v['quantidade_vagas']; ?></strong></li>
                    </ul>
                    <a href="cadastro.php?vaga_id=<?php echo $v['id']; ?>" class="btn btn-success btn-lg w-100 mt-4">QUERO ME CANDIDATAR</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>