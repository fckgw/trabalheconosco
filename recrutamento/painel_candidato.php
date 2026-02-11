<?php
session_start();
// --- CONFIGURAÇÃO ---
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'includes/db.php';
require_once 'includes/email.php'; // Para notificar candidatura se desejar

// 1. SEGURANÇA
if (!isset($_SESSION['nivel']) || $_SESSION['nivel'] !== 'candidato') {
    header("Location: index.php");
    exit;
}

$pdo = getDB();
$cand_id = $_SESSION['usuario_id'];
$pagina = $_GET['pag'] ?? 'dashboard';
$msg = "";
$erro = "";

// 2. VERIFICAÇÃO DE TROCA DE SENHA OBRIGATÓRIA
$meus_dados = $pdo->query("SELECT * FROM candidatos WHERE id = $cand_id")->fetch();
if ($meus_dados['trocar_senha'] == 1) {
    header("Location: nova_senha.php");
    exit;
}

// 3. AÇÃO: CANDIDATAR-SE A UMA NOVA VAGA (ONE-CLICK APPLY)
if (isset($_POST['aplicar_vaga'])) {
    $vaga_id = $_POST['vaga_id'];
    
    // Verifica se já não está inscrito (segurança extra)
    $check = $pdo->query("SELECT id FROM aplicacoes WHERE vaga_id = $vaga_id AND candidato_id = $cand_id")->fetch();
    
    if (!$check) {
        $pdo->prepare("INSERT INTO aplicacoes (vaga_id, candidato_id, status) VALUES (?, ?, 'inscrito')")
            ->execute([$vaga_id, $cand_id]);
            
        // Opcional: Enviar e-mail de confirmação
        // $mailer = new EmailHelper();
        // $mailer->enviar($meus_dados['email'], $meus_dados['nome'], "Candidatura Realizada", "Você se candidatou com sucesso.");
        
        $msg = "Candidatura realizada com sucesso! Boa sorte.";
        $pagina = 'historico'; // Redireciona para o histórico para ver a nova vaga
    } else {
        $erro = "Você já se candidatou para esta vaga.";
    }
}

// 4. PROCESSAR TESTE COMPORTAMENTAL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalizar_teste'])) {
    $respostas = $_POST['resp'] ?? [];
    if (count($respostas) > 0) {
        $contagem = array_count_values($respostas);
        arsort($contagem);
        $perfil_vencedor = array_key_first($contagem);
        $pdo->prepare("UPDATE candidatos SET perfil_comportamental = ?, data_teste_perfil = NOW() WHERE id = ?")->execute([$perfil_vencedor, $cand_id]);
        $msg = "Teste realizado com sucesso! Veja seu perfil abaixo.";
        $pagina = 'perfil';
        $meus_dados['perfil_comportamental'] = $perfil_vencedor; // Atualiza visual
        $meus_dados['data_teste_perfil'] = date('Y-m-d H:i:s');
    }
}

// 5. BUSCAR DADOS
// Minhas Aplicações (Histórico)
$sqlApps = "SELECT a.*, v.titulo, v.localizacao, v.setor, v.id as vid 
            FROM aplicacoes a 
            JOIN vagas v ON a.vaga_id = v.id 
            WHERE a.candidato_id = $cand_id 
            ORDER BY a.data_aplicacao DESC";
$aplicacoes = $pdo->query($sqlApps)->fetchAll();

// Cria array de IDs das vagas que eu já tenho (para bloquear botão)
$minhas_vagas_ids = array_column($aplicacoes, 'vid');

// Testes Pendentes
$testes_pendentes = array_filter($aplicacoes, function($app) {
    return $app['status'] == 'teste_pratico';
});

// Vagas Abertas (Mural)
$vagas_abertas = $pdo->query("SELECT * FROM vagas WHERE status = 'aberta' ORDER BY id DESC")->fetchAll();

// Perguntas Perfil
$perguntas = [];
if (empty($meus_dados['perfil_comportamental'])) {
    $perguntas = $pdo->query("SELECT * FROM perguntas_teste ORDER BY ordem ASC, id ASC")->fetchAll();
}

// Dados Dashboard
$total_apps = count($aplicacoes);
$total_testes = count($testes_pendentes);
$ultimo_login = $meus_dados['data_ultimo_login'] ? date('d/m/Y \à\s H:i', strtotime($meus_dados['data_ultimo_login'])) : 'Agora';
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Minha Área - Cedro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body { background: #f4f6f9; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }
        
        /* SIDEBAR (Igual ao Recrutador) */
        .sidebar { min-height: 100vh; background: #1a1a1a; color: #fff; width: 260px; position: fixed; z-index: 1000; transition: 0.3s; }
        .sidebar .brand { padding: 20px; text-align: center; border-bottom: 1px solid #333; background: #000; }
        .sidebar a { color: #aaa; text-decoration: none; padding: 15px 20px; display: block; border-bottom: 1px solid #222; }
        .sidebar a:hover, .sidebar a.active { background: #222; color: #fff; border-left: 4px solid #0d6efd; padding-left: 25px; }
        .sidebar i { width: 25px; margin-right: 10px; }
        
        .main-content { margin-left: 260px; padding: 30px; }
        @media (max-width: 768px) { .sidebar { width: 100%; position: relative; min-height: auto; } .main-content { margin-left: 0; } }

        /* CARDS */
        .card-custom { border: none; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); background: white; margin-bottom: 20px; padding: 20px; }
        .icon-bg { position: absolute; right: 20px; top: 20px; font-size: 3rem; opacity: 0.15; }
        .border-left-primary { border-left: 5px solid #0d6efd; }
        .border-left-success { border-left: 5px solid #198754; }
        .border-left-warning { border-left: 5px solid #ffc107; }
        
        /* BADGES STATUS */
        .st-inscrito { background: #6c757d; }
        .st-entrevista { background: #ffc107; color: #000; }
        .st-teste_pratico { background: #0d6efd; animation: pulse 2s infinite; }
        .st-aprovado { background: #198754; }
        .st-reprovado { background: #dc3545; }
        
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.4); } 70% { box-shadow: 0 0 0 10px rgba(13, 110, 253, 0); } 100% { box-shadow: 0 0 0 0 rgba(13, 110, 253, 0); } }
    </style>
</head>
<body>

    <!-- MENU LATERAL -->
    <nav class="sidebar">
        <div class="brand">
            <img src="logo-branco.png" width="120" alt="Cedro">
            <div class="small mt-2 text-muted">Área do Candidato</div>
        </div>
        
        <div class="p-3 text-center border-bottom border-secondary">
            <div class="rounded-circle bg-secondary d-inline-flex justify-content-center align-items-center mb-2" style="width: 50px; height: 50px;">
                <i class="fas fa-user fa-lg text-white"></i>
            </div>
            <div class="small fw-bold text-white"><?php echo htmlspecialchars($meus_dados['nome']); ?></div>
        </div>

        <a href="?pag=dashboard" class="<?php echo $pagina=='dashboard'?'active':''; ?>"><i class="fas fa-home"></i> Dashboard</a>
        <a href="?pag=mural" class="<?php echo $pagina=='mural'?'active':''; ?>"><i class="fas fa-search-plus"></i> Vagas Abertas</a>
        <a href="?pag=historico" class="<?php echo $pagina=='historico'?'active':''; ?>"><i class="fas fa-briefcase"></i> Minhas Vagas</a>
        <a href="?pag=sala_teste" class="<?php echo $pagina=='sala_teste'?'active':''; ?>">
            <i class="fas fa-laptop-code"></i> Sala de Testes 
            <?php if($total_testes > 0): ?><span class="badge bg-danger ms-2"><?php echo $total_testes; ?></span><?php endif; ?>
        </a>
        <a href="?pag=perfil" class="<?php echo $pagina=='perfil'?'active':''; ?>"><i class="fas fa-id-card"></i> Meu Perfil</a>
        <a href="logout.php" class="text-danger mt-4"><i class="fas fa-sign-out-alt"></i> Sair</a>
    </nav>

    <!-- ÁREA PRINCIPAL -->
    <div class="main-content">
        
        <?php if($msg): ?><div class="alert alert-success shadow-sm"><?php echo $msg; ?></div><?php endif; ?>
        <?php if($erro): ?><div class="alert alert-danger shadow-sm"><?php echo $erro; ?></div><?php endif; ?>

        <!-- 1. DASHBOARD -->
        <?php if($pagina == 'dashboard'): ?>
            <h3 class="mb-4 text-dark fw-bold">Olá, <?php echo explode(' ', $meus_dados['nome'])[0]; ?>!</h3>
            
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="card-custom border-left-primary position-relative">
                        <h6 class="text-primary text-uppercase fw-bold text-muted small">Último Acesso</h6>
                        <h4 class="fw-bold mb-0"><?php echo $ultimo_login; ?></h4>
                        <i class="fas fa-clock icon-bg"></i>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card-custom border-left-success position-relative">
                        <h6 class="text-success text-uppercase fw-bold text-muted small">Candidaturas</h6>
                        <h4 class="fw-bold mb-0"><?php echo $total_apps; ?></h4>
                        <i class="fas fa-file-contract icon-bg"></i>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card-custom border-left-warning position-relative">
                        <h6 class="text-warning text-uppercase fw-bold text-muted small">Testes Pendentes</h6>
                        <h4 class="fw-bold mb-0"><?php echo $total_testes; ?></h4>
                        <i class="fas fa-exclamation-triangle icon-bg"></i>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold m-0">Vagas Recomendadas</h5>
                    <a href="?pag=mural" class="btn btn-sm btn-dark">Ver Todas</a>
                </div>
                <div class="row">
                    <?php foreach(array_slice($vagas_abertas, 0, 3) as $v): 
                        if(in_array($v['id'], $minhas_vagas_ids)) continue; // Não mostra as que já aplicou no dash
                        $salario = ($v['ocultar_salario'] == 1) ? 'A combinar' : $v['faixa_salarial'];
                    ?>
                    <div class="col-md-4">
                        <div class="card-custom h-100 d-flex flex-column">
                            <span class="badge bg-secondary w-auto mb-2 align-self-start"><?php echo $v['setor']; ?></span>
                            <h5 class="fw-bold"><?php echo $v['titulo']; ?></h5>
                            <p class="small text-muted mb-2"><i class="fas fa-map-marker-alt"></i> <?php echo $v['localizacao']; ?></p>
                            <p class="small text-success fw-bold mb-3"><?php echo $salario; ?></p>
                            <form method="POST" class="mt-auto">
                                <input type="hidden" name="aplicar_vaga" value="1">
                                <input type="hidden" name="vaga_id" value="<?php echo $v['id']; ?>">
                                <button class="btn btn-outline-dark w-100 btn-sm">Candidatar-se</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- 2. MURAL DE VAGAS (NOVO) -->
        <?php if($pagina == 'mural'): ?>
            <h3 class="mb-4 text-dark fw-bold">Vagas Disponíveis</h3>
            <div class="row g-4">
                <?php foreach($vagas_abertas as $v): 
                    $ja_inscrito = in_array($v['id'], $minhas_vagas_ids);
                    $salario = ($v['ocultar_salario'] == 1) ? 'A combinar' : $v['faixa_salarial'];
                    $btn_class = $ja_inscrito ? 'btn-secondary disabled' : 'btn-success';
                    $btn_text = $ja_inscrito ? 'Já Inscrito' : 'Candidatar-se Agora';
                ?>
                <div class="col-md-6 col-xl-4">
                    <div class="card-custom h-100 d-flex flex-column">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="badge bg-dark"><?php echo $v['setor']; ?></span>
                            <span class="badge bg-light text-dark border"><?php echo $v['regime_contratacao']; ?></span>
                        </div>
                        <h5 class="fw-bold mb-1"><?php echo $v['titulo']; ?></h5>
                        <p class="text-muted small mb-2"><?php echo $v['localizacao']; ?></p>
                        
                        <div class="bg-light p-2 rounded small mb-3 text-truncate" style="max-height: 60px;">
                            <?php echo strip_tags($v['descricao']); ?>
                        </div>

                        <div class="mt-auto pt-3 border-top d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-success small"><?php echo $salario; ?></span>
                            <?php if(!$ja_inscrito): ?>
                                <form method="POST">
                                    <input type="hidden" name="aplicar_vaga" value="1">
                                    <input type="hidden" name="vaga_id" value="<?php echo $v['id']; ?>">
                                    <button class="btn btn-sm btn-success fw-bold" onclick="return confirm('Deseja se candidatar para <?php echo $v['titulo']; ?>?')">Candidatar-se</button>
                                </form>
                            <?php else: ?>
                                <button class="btn btn-sm btn-secondary" disabled>Já Inscrito</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if(empty($vagas_abertas)): ?>
                    <div class="col-12"><div class="alert alert-info">Nenhuma vaga aberta no momento.</div></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- 3. HISTÓRICO -->
        <?php if($pagina == 'historico'): ?>
            <h3 class="fw-bold mb-4">Minhas Candidaturas</h3>
            <div class="card-custom p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Vaga / Local</th>
                                <th>Data Aplicação</th>
                                <th>Status</th>
                                <th class="text-end">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($aplicacoes as $app): 
                                $lbl = ucfirst(str_replace('_',' ',$app['status']));
                                $cls = 'st-'.$app['status'];
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo $app['titulo']; ?></strong><br>
                                    <small class="text-muted"><?php echo $app['setor']; ?> | <?php echo $app['localizacao']; ?></small>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($app['data_aplicacao'])); ?></td>
                                <td><span class="badge <?php echo $cls; ?> badge-status"><?php echo $lbl; ?></span></td>
                                <td class="text-end">
                                    <?php if($app['status'] == 'teste_pratico'): ?>
                                        <a href="sala_teste.php?app_id=<?php echo $app['id']; ?>" class="btn btn-sm btn-primary fw-bold">Fazer Teste</a>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-light border" disabled>Em Andamento</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- 4. SALA DE TESTES -->
        <?php if($pagina == 'sala_teste'): ?>
            <h3 class="fw-bold mb-4">Sala de Testes Virtuais</h3>
            <?php if($total_testes > 0): ?>
                <div class="row">
                    <?php foreach($testes_pendentes as $tp): ?>
                    <div class="col-md-6">
                        <div class="card-custom border-start border-5 border-primary">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="fw-bold text-primary mb-0"><?php echo $tp['titulo']; ?></h5>
                                <span class="badge bg-danger">Pendente</span>
                            </div>
                            <p class="text-muted mb-2">Você foi convocado para a etapa prática desta vaga.</p>
                            <a href="sala_teste.php?app_id=<?php echo $tp['id']; ?>" class="btn btn-dark w-100 fw-bold">INICIAR TESTE</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-5 bg-white rounded shadow-sm">
                    <i class="fas fa-check-circle fa-4x text-success mb-3 opacity-50"></i>
                    <h4>Tudo em dia!</h4>
                    <p class="text-muted">Nenhum teste pendente.</p>
                    <a href="?pag=mural" class="btn btn-outline-dark mt-3">Ver Vagas</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- 5. PERFIL -->
        <?php if($pagina == 'perfil'): ?>
            <h3 class="fw-bold mb-4">Meu Perfil</h3>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="card-custom text-center">
                        <div class="mb-3"><i class="fas fa-user-circle fa-6x text-secondary"></i></div>
                        <h4><?php echo htmlspecialchars($meus_dados['nome']); ?></h4>
                        <p class="text-muted mb-1"><?php echo $meus_dados['area_interesse']; ?></p>
                        <hr>
                        <div class="text-start small">
                            <p class="mb-2"><strong>CPF:</strong> <?php echo $meus_dados['cpf']; ?></p>
                            <p class="mb-2"><strong>Email:</strong> <?php echo $meus_dados['email']; ?></p>
                            <p class="mb-2"><strong>Celular:</strong> <?php echo $meus_dados['telefone']; ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-8">
                    <div class="card-custom">
                        <h5 class="fw-bold border-bottom pb-2 mb-3">Análise Comportamental</h5>
                        <?php if (!empty($meus_dados['perfil_comportamental'])): ?>
                            <div class="text-center py-4 bg-light rounded border">
                                <h2 class="fw-bold text-primary"><?php echo $meus_dados['perfil_comportamental']; ?></h2>
                                <p class="text-muted mb-0">Teste realizado em: <?php echo date('d/m/Y', strtotime($meus_dados['data_teste_perfil'])); ?></p>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning"><i class="fas fa-exclamation-circle me-2"></i> Realize o teste comportamental.</div>
                            <button class="btn btn-primary w-100 py-3" data-bs-toggle="modal" data-bs-target="#modalTesteComp">INICIAR TESTE</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <!-- MODAL TESTE PERFIL -->
    <?php if (empty($meus_dados['perfil_comportamental'])): ?>
    <div class="modal fade" id="modalTesteComp" data-bs-backdrop="static"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header bg-dark text-white"><h5 class="modal-title">Teste de Perfil</h5></div><div class="modal-body p-4"><form method="POST"><?php foreach($perguntas as $idx => $p): $stmtOp = $pdo->prepare("SELECT * FROM opcoes_teste WHERE pergunta_id = ?"); $stmtOp->execute([$p['id']]); $opcoes = $stmtOp->fetchAll(); shuffle($opcoes); ?><div class="mb-4"><p class="fw-bold mb-2"><?php echo ($idx+1).". ".$p['texto_pergunta']; ?></p><?php foreach($opcoes as $op): ?><div class="form-check"><input class="form-check-input" type="radio" name="resp[<?php echo $p['id']; ?>]" value="<?php echo $op['perfil_associado']; ?>" required><label class="form-check-label"><?php echo $op['texto_opcao']; ?></label></div><?php endforeach; ?></div><?php endforeach; ?><button type="submit" name="finalizar_teste" class="btn btn-success w-100">Finalizar</button></form></div></div></div></div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>