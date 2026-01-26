<?php
// --- CONFIGURAÇÃO E DEBUG ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Tenta carregar a conexão
if (!file_exists('includes/db.php')) {
    die("ERRO CRÍTICO: O arquivo 'includes/db.php' não foi encontrado.");
}

require_once 'includes/db.php'; // Usei require_once para evitar carregar duplicado

try {
    $pdo = getDB();
    // Busca apenas vagas abertas
    $sql = "SELECT * FROM vagas WHERE status = 'aberta' ORDER BY id DESC";
    $stmt = $pdo->query($sql);
    $vagas = $stmt->fetchAll();
} catch (Exception $e) {
    die("ERRO DE BANCO DE DADOS: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vagas Abertas - Cedro Têxtil</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Ícones -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Roboto', sans-serif; background-color: #f8f9fa; }
        .bg-cedro { background-color: #000; } /* Preto Cedro */
        
        .hero-section {
            /* Imagem de fundo genérica de fábrica têxtil - pode trocar depois */
            background: linear-gradient(rgba(0,0,0,0.8), rgba(0,0,0,0.8)), url('https://source.unsplash.com/1600x900/?textile,factory');
            background-size: cover;
            background-position: center;
            color: white;
            padding: 80px 0;
            margin-bottom: 40px;
        }

        .card-vaga {
            border: none;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .card-vaga:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
        }

        .card-body {
            flex: 1;
            padding: 1.5rem;
        }

        .badge-tipo {
            background-color: #f0f2f5;
            color: #1a1a1a;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        .requisitos-box {
            background-color: #f9f9f9;
            border-left: 4px solid #28a745;
            padding: 12px;
            font-size: 0.9rem;
            margin-top: 20px;
            color: #555;
            border-radius: 0 4px 4px 0;
        }

        .btn-candidatar {
            background-color: #28a745;
            color: white;
            border: none;
            font-weight: 700;
            width: 100%;
            padding: 15px;
            border-radius: 0 0 12px 12px;
            text-transform: uppercase;
            font-size: 0.9rem;
            letter-spacing: 0.5px;
            transition: background 0.3s;
        }
        .btn-candidatar:hover { background-color: #218838; color: white; text-decoration: none; }
    </style>
</head>
<body>

    <!-- Barra de Navegação -->
    <nav class="navbar navbar-dark bg-cedro sticky-top shadow">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="index.php">
                <img src="logo-branco.png" alt="Cedro Têxtil" height="35" class="me-2">
            </a>
            <a href="index.php" class="btn btn-outline-light btn-sm rounded-pill px-3">
                <i class="fas fa-arrow-left me-1"></i> Voltar
            </a>
        </div>
    </nav>

    <!-- Cabeçalho (Hero) -->
    <div class="hero-section text-center">
        <div class="container">
            <h1 class="display-4 fw-bold mb-3">Oportunidades de Carreira</h1>
            <p class="lead text-light opacity-75">Faça parte de uma história de mais de 150 anos de inovação e tradição têxtil.</p>
        </div>
    </div>

    <!-- Lista de Vagas -->
    <div class="container mb-5">
        <?php if (count($vagas) > 0): ?>
            
            <div class="row g-4">
                <?php foreach($vagas as $vaga): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card card-vaga">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <span class="badge badge-tipo">Vaga Efetiva</span>
                                    <small class="text-muted" style="font-size: 0.8rem;">
                                        <i class="far fa-calendar-alt"></i> Publicada hoje
                                    </small>
                                </div>

                                <h4 class="card-title fw-bold text-dark mb-3">
                                    <?php echo htmlspecialchars($vaga['titulo']); ?>
                                </h4>

                                <p class="card-text text-secondary mb-4">
                                    <?php echo nl2br(htmlspecialchars(substr($vaga['descricao'], 0, 130))); ?>...
                                </p>

                                <div class="requisitos-box">
                                    <strong class="d-block mb-1 text-dark"><i class="fas fa-check-circle text-success me-1"></i> Requisito Principal:</strong>
                                    <?php 
                                        $reqs = explode("\n", $vaga['requisitos']);
                                        echo htmlspecialchars($reqs[0]);
                                    ?>
                                </div>
                            </div>
                            
                            <!-- Botão no rodapé do card -->
                            <a href="cadastro.php?vaga_id=<?php echo $vaga['id']; ?>" class="btn btn-candidatar">
                                Candidatar-se <i class="fas fa-chevron-right ms-2"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <div class="text-center py-5 bg-white rounded shadow-sm">
                <i class="fas fa-clipboard-list fa-4x text-muted mb-3 opacity-50"></i>
                <h3 class="fw-bold text-secondary">Nenhuma vaga aberta no momento.</h3>
                <p class="text-muted">Mas não se preocupe! Você pode cadastrar seu currículo em nosso banco de talentos.</p>
                <a href="cadastro.php" class="btn btn-dark btn-lg mt-3 px-5 rounded-pill">Cadastrar Currículo Geral</a>
            </div>
        <?php endif; ?>
    </div>

    <footer class="text-center py-4 mt-5 bg-white border-top text-muted small">
        <div class="container">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> Cedro Têxtil. Todos os direitos reservados.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>