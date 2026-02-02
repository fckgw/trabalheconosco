<?php
session_start();
require 'includes/db.php';
require_once 'includes/validacoes.php';

$erro = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = $_POST['login']; // Pode ser Email ou CPF
    $senha = $_POST['senha'];
    $pdo = getDB();

    // 1. TENTA LOGAR COMO RH (Tabela usuarios)
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $login]);
    $rh = $stmt->fetch();

    if ($rh && $senha === $rh['senha']) { // Em produção use password_verify
        $_SESSION['usuario_id'] = $rh['id'];
        $_SESSION['nome'] = $rh['nome'];
        $_SESSION['nivel'] = 'rh';
        header("Location: admin.php");
        exit;
    }

    // 2. SE NÃO FOR RH, TENTA COMO CANDIDATO (Tabela candidatos - CPF)
    $cpfLimpo = Validador::limpar($login);
    $stmt = $pdo->prepare("SELECT * FROM candidatos WHERE cpf = :cpf LIMIT 1");
    $stmt->execute([':cpf' => $cpfLimpo]);
    $cand = $stmt->fetch();

    if ($cand && password_verify($senha, $cand['senha'])) {
        $_SESSION['usuario_id'] = $cand['id'];
        $_SESSION['nome'] = $cand['nome'];
        $_SESSION['nivel'] = 'candidato';
        header("Location: painel_candidato.php");
        exit;
    }

    $erro = "Login ou senha inválidos.";
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Acesso ao Portal - Cedro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
    <style>
        body { background: #f4f7f6; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card-login { width: 100%; max-width: 400px; padding: 2rem; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); background: white; }
        .bg-cedro { background-color: #000; color: white; }
    </style>
</head>
<body>
    <div class="card-login">
        <div class="text-center mb-4">
            <img src="logo-branco.png" style="background:black; padding:10px; border-radius:5px;" height="50">
            <h5 class="mt-3 text-muted">Portal de Vagas</h5>
        </div>

        <?php if($erro): ?><div class="alert alert-danger"><?php echo $erro; ?></div><?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Login (E-mail Corporativo ou CPF)</label>
                <input type="text" name="login" class="form-control" placeholder="exemplo@cedro.ind.br ou CPF" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Senha</label>
                <input type="password" name="senha" class="form-control" placeholder="••••••" required>
            </div>
            <button type="submit" class="btn bg-cedro w-100 btn-lg">Entrar</button>
        </form>
        <div class="text-center mt-3">
            <a href="vagas.php" class="text-decoration-none text-muted small">Ver vagas disponíveis</a>
        </div>
    </div>
</body>
</html>