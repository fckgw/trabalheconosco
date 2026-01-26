<?php
session_start();
require 'includes/db.php';

$erro_login = "";

// Lógica de Login do Recrutador
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'login') {
    $email = $_POST['email'];
    $senha = $_POST['senha'];

    $pdo = getDB();
    // Verifica na tabela de usuários (Recrutadores/Admin)
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    // OBS: Em produção use password_verify(). Aqui comparamos direto para teste rápido.
    // Se você já cadastrou senhas com hash, mude para: if ($user && password_verify($senha, $user['senha']))
    if ($user && $senha === $user['senha']) { 
        $_SESSION['usuario_id'] = $user['id'];
        $_SESSION['usuario_nome'] = $user['nome'];
        $_SESSION['perfil'] = $user['perfil'];
        header("Location: admin.php"); // Envia para o painel (vamos criar depois)
        exit;
    } else {
        $erro_login = "E-mail ou senha incorretos.";
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal de Talentos - Cedro Têxtil</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Ícones (FontAwesome) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Nosso CSS Personalizado -->
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-light">

    <!-- Barra Superior -->
    <nav class="navbar navbar-dark bg-black shadow-sm">
        <div class="container justify-content-center">
            <a class="navbar-brand" href="#">
                <!-- LOGO DA EMPRESA -->
                <img src="logo-branco.png" alt="Cedro Têxtil" height="50">
            </a>
        </div>
    </nav>

    <div class="container main-container">
        <div class="row justify-content-center align-items-center" style="min-height: 80vh;">
            
            <!-- Coluna da Esquerda: Área do Candidato -->
            <div class="col-md-5 mb-4">
                <div class="card shadow-lg border-0 h-100">
                    <div class="card-body p-5 text-center">
                        <div class="icon-box mb-4 text-success">
                            <i class="fas fa-user-check fa-4x"></i>
                        </div>
                        <h2 class="fw-bold text-dark">Sou Candidato</h2>
                        <p class="text-muted mt-3">
                            Busca novos desafios na Cedro Têxtil? 
                            Confira nossas oportunidades abertas e cadastre seu currículo agora mesmo.
                        </p>
                        <div class="d-grid gap-2 mt-4">
                            <a href="vagas.php" class="btn btn-success btn-lg rounded-pill">
                                <i class="fas fa-search me-2"></i> Ver Vagas Abertas
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Divisor visível apenas no Desktop -->
            <div class="col-md-1 d-none d-md-flex justify-content-center">
                <div class="vr" style="height: 200px; width: 2px; background-color: #ddd;"></div>
            </div>

            <!-- Coluna da Direita: Login RH -->
            <div class="col-md-5 mb-4">
                <div class="card shadow border-0">
                    <div class="card-header bg-white border-0 text-center pt-4">
                        <h4 class="fw-bold text-secondary">Área do Recrutador</h4>
                    </div>
                    <div class="card-body p-4">
                        
                        <?php if(!empty($erro_login)): ?>
                            <div class="alert alert-danger text-center"><?php echo $erro_login; ?></div>
                        <?php endif; ?>

                        <form action="index.php" method="POST">
                            <input type="hidden" name="acao" value="login">
                            
                            <div class="mb-3">
                                <label class="form-label text-muted">E-mail Corporativo</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-envelope"></i></span>
                                    <input type="email" name="email" class="form-control" placeholder="seunome@cedro.com.br" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label text-muted">Senha</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-lock"></i></span>
                                    <input type="password" name="senha" class="form-control" placeholder="••••••••" required>
                                </div>
                            </div>

                            <div class="d-grid mt-4">
                                <button type="submit" class="btn btn-dark btn-lg">Entrar no Sistema</button>
                            </div>
                            
                            <div class="text-center mt-3">
                                <a href="#" class="small text-muted text-decoration-none">Esqueceu a senha?</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Rodapé -->
    <footer class="text-center py-3 text-muted small">
        &copy; <?php echo date('Y'); ?> Cedro Têxtil. Todos os direitos reservados.
    </footer>

    <!-- Scripts do Bootstrap -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>