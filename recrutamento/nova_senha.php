<?php
session_start();
require 'includes/db.php';

// Verifica se está logado
if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}

$erro = "";
$sucesso = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nova1 = $_POST['nova_senha'];
    $nova2 = $_POST['confirmar_senha'];

    if (strlen($nova1) < 6) {
        $erro = "A senha deve ter no mínimo 6 caracteres.";
    } elseif ($nova1 !== $nova2) {
        $erro = "As senhas não coincidem.";
    } else {
        $pdo = getDB();
        $senhaHash = password_hash($nova1, PASSWORD_DEFAULT);
        $tabela = ($_SESSION['nivel'] == 'rh') ? 'usuarios' : 'candidatos';
        
        // Atualiza a senha e remove a flag de trocar_senha
        $stmt = $pdo->prepare("UPDATE $tabela SET senha = ?, trocar_senha = 0 WHERE id = ?");
        
        if ($stmt->execute([$senhaHash, $_SESSION['usuario_id']])) {
            // Redireciona para o painel correto
            if ($_SESSION['nivel'] == 'rh') {
                header("Location: admin.php");
            } else {
                header("Location: painel_candidato.php");
            }
            exit;
        } else {
            $erro = "Erro ao atualizar senha.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Definir Nova Senha</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f4f7f6; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card-senha { width: 100%; max-width: 400px; padding: 2rem; border-radius: 15px; background: white; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .input-group-text { cursor: pointer; background: white; }
        .form-control { border-right: none; }
        .input-group-text { border-left: none; }
    </style>
</head>
<body>

    <div class="card-senha">
        <h4 class="text-center mb-3">Definir Nova Senha</h4>
        <div class="alert alert-warning small">
            <i class="fas fa-lock"></i> Por segurança, você precisa alterar sua senha temporária antes de continuar.
        </div>

        <?php if($erro): ?><div class="alert alert-danger small"><?php echo $erro; ?></div><?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Nova Senha</label>
                <div class="input-group">
                    <input type="password" name="nova_senha" id="senha1" class="form-control" required minlength="6">
                    <span class="input-group-text" onclick="toggleSenha('senha1', 'icon1')"><i class="fas fa-eye" id="icon1"></i></span>
                </div>
            </div>
            
            <div class="mb-4">
                <label class="form-label">Confirmar Nova Senha</label>
                <div class="input-group">
                    <input type="password" name="confirmar_senha" id="senha2" class="form-control" required minlength="6">
                    <span class="input-group-text" onclick="toggleSenha('senha2', 'icon2')"><i class="fas fa-eye" id="icon2"></i></span>
                </div>
            </div>

            <button type="submit" class="btn btn-success w-100">Salvar e Acessar</button>
        </form>
    </div>

    <script>
        function toggleSenha(inputId, iconId) {
            var input = document.getElementById(inputId);
            var icon = document.getElementById(iconId);
            if (input.type === "password") {
                input.type = "text";
                icon.classList.remove("fa-eye");
                icon.classList.add("fa-eye-slash");
            } else {
                input.type = "password";
                icon.classList.remove("fa-eye-slash");
                icon.classList.add("fa-eye");
            }
        }
    </script>
</body>
</html>