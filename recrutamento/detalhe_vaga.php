<?php
// --- CONFIGURAÇÃO ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'includes/db.php';

$vaga_id = $_GET['id'] ?? null;

if (!$vaga_id) {
    header("Location: vagas.php");
    exit;
}

try {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM vagas WHERE id = ? AND status = 'aberta'");
    $stmt->execute([$vaga_id]);
    $vaga = $stmt->fetch();

    if (!$vaga) {
        die("<h3>Vaga não encontrada ou já encerrada. <a href='vagas.php'>Voltar</a></h3>");
    }
} catch (Exception $e) {
    die("Erro ao carregar vaga.");
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($vaga['titulo']); ?> - Detalhes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .bg-cedro { background-color: #000; }
        
        .header-vaga {
            background: white;
            padding: 40px 0;
            border-bottom: 1px solid #ddd;
            margin-bottom: 30px;
        }
        
        .card-detalhe {
            background: white;
            border: none;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        
        .titulo-secao {
            font-size: 1.1rem;
            font-weight: 700;
            color: #333;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 20px;
            margin-top: 10px;
        }
        
        .info-box {
            background: #f1f3f5;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            height: 100%;
        }
        .info-box i { font-size: 1.5rem; color: #000; margin-bottom: 10px; }
        .info-box span { display: block; font-size: 0.9rem; color: #555; }
        .info-box strong { font-size: 1rem; color: #000; }

        .btn-apply {
            background-color: #28a745;
            color: white;
            font-weight: bold;
            padding: 15px 40px;
            border-radius: 50px;
            font-size: 1.2rem;
            transition: 0.3s;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }
        .btn-apply:hover { background-color: #218838; transform: translateY(-2px); color: white; }
    </style>
</head>
<body>

    <nav class="navbar navbar-dark bg-cedro sticky-top">
        <div class="container">
            <a class="navbar-brand" href="index.php"><img src="logo-branco.png" height="30"></a>
            <a href="vagas.php" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-left"></i> Voltar para Lista</a>
        </div>
    </nav>

    <!-- CABEÇALHO -->
    <div class="header-vaga">
        <div class="container text-center">
            <span class="badge bg-dark mb-2"><?php echo htmlspecialchars($vaga['setor'] ?? 'Geral'); ?></span>
            <h1 class="fw-bold mb-3"><?php echo htmlspecialchars($vaga['titulo']); ?></h1>
            <p class="text-muted">
                <i class="fas fa-map-marker-alt me-1"></i> <?php echo htmlspecialchars($vaga['localizacao']); ?> 
                <span class="mx-3">|</span> 
                <i class="far fa-clock me-1"></i> Publicada em <?php echo date('d/m/Y', strtotime($vaga['created_at'])); ?>
            </p>
        </div>
    </div>

    <div class="container mb-5">
        <div class="row">
            
            <!-- COLUNA PRINCIPAL -->
            <div class="col-lg-8">
                <div class="card-detalhe">
                    <h4 class="titulo-secao">Descrição da Vaga</h4>
                    <p style="white-space: pre-line; color: #555; line-height: 1.6;">
                        <?php echo nl2br(htmlspecialchars($vaga['descricao'])); ?>
                    </p>

                    <h4 class="titulo-secao mt-5">Requisitos e Qualificações</h4>
                    <p style="white-space: pre-line; color: #555; line-height: 1.6;">
                        <?php echo nl2br(htmlspecialchars($vaga['requisitos'])); ?>
                    </p>

                    <?php if(!empty($vaga['teste_pratico'])): ?>
                        <div class="alert alert-warning mt-4">
                            <i class="fas fa-exclamation-triangle me-2"></i> <strong>Atenção:</strong> 
                            Esta vaga exige teste prático: <?php echo htmlspecialchars($vaga['teste_pratico']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- BARRA LATERAL -->
            <div class="col-lg-4">
                <div class="card-detalhe">
                    <h5 class="mb-4 text-center">Resumo da Oportunidade</h5>
                    
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="info-box">
                                <i class="fas fa-money-bill-wave"></i>
                                <span>Faixa Salarial</span>
                                <strong><?php echo htmlspecialchars($vaga['faixa_salarial']); ?></strong>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="info-box">
                                <i class="fas fa-file-contract"></i>
                                <span>Contrato</span>
                                <strong><?php echo htmlspecialchars($vaga['regime_contratacao']); ?></strong>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="info-box">
                                <i class="fas fa-users"></i>
                                <span>Vagas</span>
                                <strong><?php echo htmlspecialchars($vaga['quantidade_vagas']); ?></strong>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="info-box">
                                <i class="fas fa-user-clock"></i>
                                <span>Idade</span>
                                <strong><?php echo $vaga['idade_min'] . ' a ' . $vaga['idade_max']; ?> anos</strong>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">

                    <div class="d-grid">
                        <a href="cadastro.php?vaga_id=<?php echo $vaga['id']; ?>" class="btn-apply text-center">
                            QUERO ME CANDIDATAR
                        </a>
                        <small class="text-muted text-center mt-3">
                            Ao clicar, você será redirecionado para o cadastro do currículo.
                        </small>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <footer class="text-center py-4 text-muted small">
        &copy; <?php echo date('Y'); ?> Cedro Têxtil.
    </footer>

</body>
</html>