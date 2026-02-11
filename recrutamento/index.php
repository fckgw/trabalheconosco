<?php
session_start();
ini_set('display_errors', 0); // Ocultar erros em produção
error_reporting(E_ALL);

require 'includes/db.php';
require_once 'includes/validacoes.php';
require_once 'includes/email.php'; // Necessário para enviar a nova senha

$erro = "";
$sucesso = "";

$pdo = getDB();

// --- 1. PROCESSAR LOGIN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'login') {
    $login = $_POST['login'];
    $senha = $_POST['senha'];

    // TENTA COMO RH (USUARIOS)
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $login]);
    $rh = $stmt->fetch();

    // TENTA COMO CANDIDATO (CANDIDATOS)
    $cpfLimpo = Validador::limpar($login);
    $stmt2 = $pdo->prepare("SELECT * FROM candidatos WHERE cpf = :cpf LIMIT 1");
    $stmt2->execute([':cpf' => $cpfLimpo]);
    $cand = $stmt2->fetch();

    if ($rh) {
        // Verifica Senha RH (Pode ser texto puro ou hash, ideal é hash)
        // Adaptar se suas senhas antigas não forem hash
        $senhaValida = (password_verify($senha, $rh['senha']) || $senha === $rh['senha']);
        
        if ($senhaValida) {
            $_SESSION['usuario_id'] = $rh['id'];
            $_SESSION['nome'] = $rh['nome'];
            $_SESSION['nivel'] = 'rh';
            
            // Verifica se precisa trocar senha
            if ($rh['trocar_senha'] == 1) {
                header("Location: nova_senha.php");
            } else {
                header("Location: admin.php");
            }
            exit;
        }
     } elseif ($cand) {
        // Verifica Senha Candidato
        if (password_verify($senha, $cand['senha'])) {
            $_SESSION['usuario_id'] = $cand['id'];
            $_SESSION['nome'] = $cand['nome'];
            $_SESSION['nivel'] = 'candidato';

            // --- NOVO: GRAVA ÚLTIMO LOGIN ---
            $pdo->prepare("UPDATE candidatos SET data_ultimo_login = NOW() WHERE id = ?")->execute([$cand['id']]);
            // --------------------------------

            // Verifica se precisa trocar senha
            if ($cand['trocar_senha'] == 1) {
                header("Location: nova_senha.php");
            } else {
                header("Location: painel_candidato.php");
            }
            exit;
        }
    }

    $erro = "Login ou senha incorretos.";
}

// --- 2. PROCESSAR ESQUECI A SENHA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'recuperar') {
    $tipo = $_POST['tipo_recuperacao']; // 'rh' ou 'candidato'
    $dado = $_POST['dado_recuperacao']; // Email ou CPF
    
    $nova_senha = substr(str_shuffle("0123456789abcdef"), 0, 6);
    $nova_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
    $mailer = new EmailHelper();
    $enviado = false;

    if ($tipo == 'rh') {
        // Recuperação RH (Por E-mail)
        $stmt = $pdo->prepare("SELECT id, nome, email FROM usuarios WHERE email = ?");
        $stmt->execute([$dado]);
        $user = $stmt->fetch();

        if ($user) {
            // Atualiza senha e obriga troca
            $pdo->prepare("UPDATE usuarios SET senha = ?, trocar_senha = 1 WHERE id = ?")->execute([$nova_hash, $user['id']]);
            
            $corpo = "Olá {$user['nome']},<br>Sua senha foi resetada.<br>Nova Senha Temporária: <b>$nova_senha</b><br>Acesse o sistema e troque sua senha.";
            $enviado = $mailer->enviar($user['email'], $user['nome'], "Recuperação de Senha - Cedro", $corpo);
        } else {
            $erro = "E-mail de recrutador não encontrado.";
        }

    } elseif ($tipo == 'candidato') {
        // Recuperação Candidato (Por CPF)
        $cpf = Validador::limpar($dado);
        $stmt = $pdo->prepare("SELECT id, nome, email FROM candidatos WHERE cpf = ?");
        $stmt->execute([$cpf]);
        $user = $stmt->fetch();

        if ($user) {
            // Atualiza senha e obriga troca
            $pdo->prepare("UPDATE candidatos SET senha = ?, trocar_senha = 1 WHERE id = ?")->execute([$nova_hash, $user['id']]);
            
            $corpo = "Olá {$user['nome']},<br>Recebemos uma solicitação de nova senha.<br>Sua Senha Temporária: <b>$nova_senha</b><br>Faça login para definir uma nova senha.";
            $enviado = $mailer->enviar($user['email'], $user['nome'], "Nova Senha - Cedro Têxtil", $corpo);
        } else {
            $erro = "CPF não encontrado em nossa base.";
        }
    }

    if ($enviado) {
        $sucesso = "Uma nova senha temporária foi enviada para o e-mail cadastrado.";
    } elseif (!$erro) {
        $erro = "Erro ao enviar e-mail. Tente novamente.";
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acesso ao Portal - Cedro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
    
    <style>
        body { background: #f4f7f6; height: 100vh; display: flex; align-items: center; justify-content: center; font-family: 'Segoe UI', sans-serif; }
        .card-login { width: 100%; max-width: 400px; padding: 2rem; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); background: white; }
        .bg-cedro { background-color: #000; color: white; }
        .btn-cedro { background-color: #000; color: white; font-weight: bold; }
        .btn-cedro:hover { background-color: #333; color: white; }
        .input-group-text { cursor: pointer; background: white; border-left: none; }
        .form-control-password { border-right: none; }
    </style>
</head>
<body>

    <div class="card-login">
        <div class="text-center mb-4">
            <img src="logo-branco.png" style="background:black; padding:10px; border-radius:5px;" height="50">
            <h5 class="mt-3 text-muted">Portal de Vagas</h5>
        </div>

        <?php if($erro): ?><div class="alert alert-danger text-center small"><?php echo $erro; ?></div><?php endif; ?>
        <?php if($sucesso): ?><div class="alert alert-success text-center small"><?php echo $sucesso; ?></div><?php endif; ?>

        <form method="POST">
            <input type="hidden" name="acao" value="login">
            
            <div class="mb-3">
                <label class="form-label">Login</label>
                <input type="text" name="login" class="form-control" placeholder="E-mail (RH) ou CPF (Candidato)" required>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Senha</label>
                <div class="input-group">
                    <input type="password" name="senha" id="senhaInput" class="form-control form-control-password" placeholder="••••••" required>
                    <span class="input-group-text" onclick="toggleSenha()">
                        <i class="fas fa-eye" id="eyeIcon"></i>
                    </span>
                </div>
                <div class="text-end mt-1">
                    <a href="#" data-bs-toggle="modal" data-bs-target="#modalEsqueci" class="small text-decoration-none text-muted">Esqueceu a senha?</a>
                </div>
            </div>

            <button type="submit" class="btn btn-cedro w-100 btn-lg">Entrar</button>
        </form>
        
        <div class="text-center mt-4 pt-3 border-top">
            <p class="small text-muted mb-2">Ainda não tem cadastro?</p>
            <a href="vagas.php" class="btn btn-outline-dark btn-sm w-100">Ver vagas disponíveis</a>
        </div>
    </div>

    <!-- MODAL ESQUECI A SENHA -->
    <div class="modal fade" id="modalEsqueci" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title">Recuperar Acesso</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <ul class="nav nav-pills nav-fill mb-3" id="pills-tab" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="pills-cand-tab" data-bs-toggle="pill" data-bs-target="#pills-cand" type="button">Sou Candidato</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="pills-rh-tab" data-bs-toggle="pill" data-bs-target="#pills-rh" type="button">Sou Recrutador</button>
                        </li>
                    </ul>
                    
                    <div class="tab-content">
                        <!-- CANDIDATO (CPF) -->
                        <div class="tab-pane fade show active" id="pills-cand">
                            <form method="POST">
                                <input type="hidden" name="acao" value="recuperar">
                                <input type="hidden" name="tipo_recuperacao" value="candidato">
                                <p class="small text-muted">Informe seu CPF. Enviaremos uma nova senha para o e-mail cadastrado.</p>
                                <div class="mb-3">
                                    <label>CPF</label>
                                    <input type="text" name="dado_recuperacao" class="form-control cpf-mask" placeholder="000.000.000-00" required>
                                </div>
                                <button type="submit" class="btn btn-success w-100">Recuperar Senha</button>
                            </form>
                        </div>
                        
                        <!-- RH (EMAIL) -->
                        <div class="tab-pane fade" id="pills-rh">
                            <form method="POST">
                                <input type="hidden" name="acao" value="recuperar">
                                <input type="hidden" name="tipo_recuperacao" value="rh">
                                <p class="small text-muted">Informe seu e-mail corporativo.</p>
                                <div class="mb-3">
                                    <label>E-mail</label>
                                    <input type="email" name="dado_recuperacao" class="form-control" placeholder="nome@cedro.ind.br" required>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">Recuperar Senha</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function(){
            $('.cpf-mask').mask('000.000.000-00');
        });

        function toggleSenha() {
            var input = document.getElementById("senhaInput");
            var icon = document.getElementById("eyeIcon");
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