<?php
session_start();
require 'includes/db.php';

if (!isset($_SESSION['nivel']) || $_SESSION['nivel'] !== 'candidato') { header("Location: index.php"); exit; }
$pdo = getDB(); $cand_id = $_SESSION['usuario_id'];
$pagina = $_GET['pag'] ?? 'dashboard';
$msg = ""; 

// UPLOAD DE FOTO
if (isset($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] == 0) {
    $ext = pathinfo($_FILES['foto_perfil']['name'], PATHINFO_EXTENSION);
    $nome_foto = "Foto_" . $cand_id . "_" . time() . "." . $ext;
    if(move_uploaded_file($_FILES['foto_perfil']['tmp_name'], "uploads/fotos/" . $nome_foto)) {
        $pdo->prepare("UPDATE candidatos SET foto_perfil = ? WHERE id = ?")->execute([$nome_foto, $cand_id]);
        $msg = "Foto atualizada com sucesso!";
    }
}

// DADOS
$candidato = $pdo->query("SELECT * FROM candidatos WHERE id = $cand_id")->fetch();
$apps = $pdo->query("SELECT a.*, v.titulo, v.id as vid, v.localizacao, v.faixa_salarial, v.descricao FROM aplicacoes a JOIN vagas v ON a.vaga_id = v.id WHERE a.candidato_id = $cand_id ORDER BY a.data_aplicacao DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"> <meta name="viewport" content="width=device-width, initial-scale=1"> <title>Portal Candidato</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>body{background:#f4f6f9} .sidebar{min-height:100vh;background:#000;color:#fff;width:260px;position:fixed} .main-content{margin-left:260px;padding:30px} .foto-perfil{width:100px;height:100px;object-fit:cover;border-radius:50%;border:3px solid #fff;box-shadow:0 2px 5px rgba(0,0,0,0.2)}</style>
</head>
<body>
    <nav class="sidebar p-3">
        <div class="text-center mb-4">
            <!-- FOTO DO CANDIDATO -->
            <?php $foto = $candidato['foto_perfil'] ? "uploads/fotos/".$candidato['foto_perfil'] : "https://via.placeholder.com/100"; ?>
            <img src="<?=$foto?>" class="foto-perfil mb-2">
            <h6 class="mb-0"><?=$candidato['nome']?></h6>
            <small class="text-muted">Candidato</small>
            <form method="POST" enctype="multipart/form-data" class="mt-2">
                <label class="btn btn-sm btn-outline-light" style="font-size:0.7rem">
                    Alterar Foto <input type="file" name="foto_perfil" hidden onchange="this.form.submit()">
                </label>
            </form>
        </div>
        <a href="?pag=dashboard" class="d-block text-white text-decoration-none py-2 border-bottom border-secondary"><i class="fas fa-home me-2"></i> Dashboard</a>
        <a href="editar_perfil.php" class="d-block text-white text-decoration-none py-2 border-bottom border-secondary"><i class="fas fa-user-edit me-2"></i> Editar Meus Dados</a>
        <a href="logout.php" class="d-block text-danger text-decoration-none py-2 mt-4"><i class="fas fa-sign-out-alt me-2"></i> Sair</a>
    </nav>

    <div class="main-content">
        <?php if($msg): ?><div class="alert alert-success"><?=$msg?></div><?php endif; ?>
        
        <?php if($pagina == 'dashboard'): ?>
            <h3>Minhas Candidaturas</h3>
            <div class="row g-3">
                <?php foreach($apps as $a): ?>
                <div class="col-md-12">
                    <div class="card shadow-sm border-0">
                        <div class="card-body d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="fw-bold"><?=$a['titulo']?></h5>
                                <p class="mb-1 text-muted small"><i class="fas fa-map-marker-alt"></i> <?=$a['localizacao']?></p>
                                <span class="badge bg-primary"><?=strtoupper($a['status'])?></span>
                            </div>
                            <div class="text-end">
                                <!-- CORREÇÃO: Link do Teste -->
                                <?php if($a['status'] == 'teste_pratico'): ?>
                                    <a href="sala_teste.php?app_id=<?=$a['id']?>" class="btn btn-danger fw-bold pulse"><i class="fas fa-play"></i> INICIAR TESTE</a>
                                <?php endif; ?>
                                <button class="btn btn-outline-dark btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#modalVaga<?=$a['vid']?>">Ver Detalhes Vaga</button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- MODAL DETALHES DA VAGA -->
                    <div class="modal fade" id="modalVaga<?=$a['vid']?>"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5><?=$a['titulo']?></h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">
                        <p><strong>Salário:</strong> <?=$a['faixa_salarial']?></p>
                        <p><strong>Descrição:</strong><br><?=nl2br($a['descricao'])?></p>
                    </div></div></div></div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>