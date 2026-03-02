<?php
session_start();
// --- CONFIGURAÇÃO ---
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'includes/db.php';

// 1. SEGURANÇA
if (!isset($_SESSION['nivel']) || $_SESSION['nivel'] !== 'candidato') {
    header("Location: index.php");
    exit;
}

$pdo = getDB();
$cand_id = $_SESSION['usuario_id'];
$pagina = $_GET['pag'] ?? 'dashboard';
$msg = "";

// 2. VERIFICAÇÃO DE SENHA
$meus_dados = $pdo->query("SELECT * FROM candidatos WHERE id = $cand_id")->fetch();
if ($meus_dados['trocar_senha'] == 1) {
    header("Location: nova_senha.php");
    exit;
}

// =================================================================================
// AÇÕES DO CANDIDATO
// =================================================================================

// A. CANDIDATURA RÁPIDA (ONE-CLICK)
if (isset($_POST['aplicar_vaga'])) {
    $vid = $_POST['vaga_id'];
    // Verifica se já existe
    $check = $pdo->query("SELECT id FROM aplicacoes WHERE vaga_id = $vid AND candidato_id = $cand_id")->fetch();
    
    if(!$check) {
        $pdo->prepare("INSERT INTO aplicacoes (vaga_id, candidato_id, status) VALUES (?, ?, 'inscrito')")->execute([$vid, $cand_id]);
        $msg = "Candidatura realizada com sucesso! Acompanhe em 'Minhas Vagas'.";
        $pagina = 'historico'; // Leva para o histórico para confirmar visualmente
    }
}

// B. DESISTÊNCIA
if (isset($_POST['desistir_vaga'])) {
    $aid = $_POST['app_id'];
    $pdo->prepare("UPDATE aplicacoes SET status = 'desistencia' WHERE id = ? AND candidato_id = ?")->execute([$aid, $cand_id]);
    $msg = "Você desistiu da vaga.";
}

// C. TESTE COMPORTAMENTAL
if (isset($_POST['finalizar_teste'])) {
    $respostas = $_POST['resp'] ?? [];
    if (count($respostas) > 0) {
        $contagem = array_count_values($respostas); arsort($contagem);
        $perfil = array_key_first($contagem);
        $pdo->prepare("UPDATE candidatos SET perfil_comportamental = ?, data_teste_perfil = NOW() WHERE id = ?")->execute([$perfil, $cand_id]);
        $msg = "Teste realizado! Perfil: $perfil";
        $meus_dados['perfil_comportamental'] = $perfil; // Atualiza visual
        $pagina = 'perfil';
    }
}

// =================================================================================
// BUSCA DE DADOS
// =================================================================================

// 1. Minhas Candidaturas (Histórico)
$sqlApps = "SELECT a.*, v.titulo, v.localizacao, v.setor, v.id as vid 
            FROM aplicacoes a 
            JOIN vagas v ON a.vaga_id = v.id 
            WHERE a.candidato_id = $cand_id 
            ORDER BY a.data_aplicacao DESC";
$aplicacoes = $pdo->query($sqlApps)->fetchAll();

// Array auxiliar com IDs das vagas que já me inscrevi (para bloquear botão no mural)
$ids_inscritos = array_column($aplicacoes, 'vid');

// 2. Vagas Abertas (Mural)
$vagas_abertas = $pdo->query("SELECT * FROM vagas WHERE status = 'aberta' ORDER BY id DESC")->fetchAll();

// 3. Testes Pendentes
$testes_pendentes = array_filter($aplicacoes, function($app) { return $app['status'] == 'teste_pratico'; });

// 4. Totais Dashboard
$total_apps = count($aplicacoes);
$total_testes = count($testes_pendentes);
$ultimo_login = $meus_dados['data_ultimo_login'] ? date('d/m H:i', strtotime($meus_dados['data_ultimo_login'])) : 'Agora';

// 5. Perguntas Perfil (Se precisar)
$perguntas = [];
if (empty($meus_dados['perfil_comportamental'])) {
    $perguntas = $pdo->query("SELECT * FROM perguntas_teste ORDER BY ordem ASC, id ASC")->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"> <meta name="viewport" content="width=device-width, initial-scale=1"> <title>Minha Área - Cedro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body { background: #f4f6f9; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }
        
        /* SIDEBAR IDENTICA AO RECRUTADOR */
        .sidebar { min-height: 100vh; background: #1a1a1a; color: #fff; width: 260px; position: fixed; z-index: 1000; }
        .sidebar .brand { padding: 25px; text-align: center; border-bottom: 1px solid #333; background: #000; }
        .sidebar a { color: #aaa; text-decoration: none; padding: 15px 20px; display: block; border-bottom: 1px solid #222; font-size: 0.95rem; }
        .sidebar a:hover, .sidebar a.active { background: #222; color: #fff; border-left: 4px solid #0d6efd; padding-left: 25px; }
        .sidebar i { width: 25px; margin-right: 10px; }
        
        .main-content { margin-left: 260px; padding: 30px; }
        @media (max-width: 768px) { .sidebar { width: 100%; position: relative; min-height: auto; } .main-content { margin-left: 0; padding: 15px; } }

        /* CARDS & STATUS */
        .card-c { border: none; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); background: white; margin-bottom: 20px; padding: 20px; }
        .icon-bg { position: absolute; right: 20px; top: 20px; font-size: 3rem; opacity: 0.2; }
        
        .badge-st { font-size: 0.8rem; padding: 5px 10px; border-radius: 20px; }
        .st-inscrito { background: #6c757d; color: white; }
        .st-entrevista { background: #ffc107; color: black; }
        .st-teste_pratico { background: #0d6efd; color: white; animation: pulse 2s infinite; }
        .st-aprovado { background: #198754; color: white; }
        .st-reprovado { background: #dc3545; color: white; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.4); } 70% { box-shadow: 0 0 0 10px rgba(13, 110, 253, 0); } }
    </style>
</head>
<body>

    <nav class="sidebar">
        <div class="brand"><img src="logo-branco.png" width="130"></div>
        
        <div class="p-3 text-center border-bottom border-secondary mb-2">
            <div class="rounded-circle bg-secondary d-inline-flex justify-content-center align-items-center mb-2" style="width: 50px; height: 50px;">
                <i class="fas fa-user fa-lg text-white"></i>
            </div>
            <div class="small fw-bold text-white"><?php echo explode(' ', $meus_dados['nome'])[0]; ?></div>
        </div>

        <a href="?pag=dashboard" class="<?=$pagina=='dashboard'?'active':''?>"><i class="fas fa-chart-pie"></i> Dashboard</a>
        <a href="?pag=mural" class="<?=$pagina=='mural'?'active':''?>"><i class="fas fa-search-plus"></i> Vagas Abertas</a>
        <a href="?pag=historico" class="<?=$pagina=='historico'?'active':''?>"><i class="fas fa-briefcase"></i> Minhas Vagas</a>
        <a href="?pag=sala_teste" class="<?=$pagina=='sala_teste'?'active':''?>"><i class="fas fa-laptop-code"></i> Sala de Testes <?php if($total_testes>0):?><span class="badge bg-danger ms-2"><?=$total_testes?></span><?php endif; ?></a>
        <a href="?pag=perfil" class="<?=$pagina=='perfil'?'active':''?>"><i class="fas fa-id-card"></i> Meu Perfil</a>
        <a href="logout.php" class="text-danger mt-4"><i class="fas fa-sign-out-alt"></i> Sair</a>
    </nav>

    <div class="main-content">
        <?php if($msg): ?><div class="alert alert-success shadow-sm"><?=$msg?></div><?php endif; ?>

        <!-- DASHBOARD -->
        <?php if($pagina == 'dashboard'): ?>
            <h3>Olá, <?php echo $meus_dados['nome']; ?></h3>
            <div class="row g-4 mt-2">
                <div class="col-md-4"><div class="card-c border-start border-5 border-primary position-relative"><h6>Último Acesso</h6><h3><?=$ultimo_login?></h3><i class="fas fa-clock icon-bg"></i></div></div>
                <div class="col-md-4"><div class="card-c border-start border-5 border-success position-relative"><h6>Candidaturas</h6><h3><?=$total_apps?></h3><i class="fas fa-file-contract icon-bg"></i></div></div>
                <div class="col-md-4"><div class="card-c border-start border-5 border-warning position-relative"><h6>Testes Pendentes</h6><h3><?=$total_testes?></h3><i class="fas fa-exclamation-triangle icon-bg"></i></div></div>
            </div>

            <div class="card-c mt-4 p-0">
                <div class="p-3 border-bottom d-flex justify-content-between"><strong>Vagas Recentes</strong> <a href="?pag=mural" class="btn btn-sm btn-dark">Ver Todas</a></div>
                <div class="table-responsive"><table class="table table-hover mb-0 align-middle"><thead class="table-light"><tr><th>Vaga</th><th>Data</th><th>Status</th></tr></thead><tbody>
                <?php if($total_apps>0): foreach(array_slice($aplicacoes,0,5) as $app): $cls='st-'.$app['status']; ?>
                <tr><td><strong><?=$app['titulo']?></strong></td><td><?=date('d/m/y',strtotime($app['data_aplicacao']))?></td><td><span class="badge <?=$cls?>"><?=strtoupper(str_replace('_',' ',$app['status']))?></span></td></tr>
                <?php endforeach; else: ?><tr><td colspan="3" class="text-center p-4">Nenhuma candidatura. <a href="?pag=mural">Ver Vagas</a></td></tr><?php endif; ?>
                </tbody></table></div>
            </div>
        <?php endif; ?>

        <!-- MURAL DE VAGAS (NOVO) -->
        <?php if($pagina == 'mural'): ?>
            <h3 class="mb-4">Vagas Disponíveis</h3>
            <div class="row g-4">
                <?php foreach($vagas_abertas as $v): 
                    $ja_inscrito = in_array($v['id'], $ids_inscritos);
                    $salario = ($v['ocultar_salario'] == 1) ? 'A combinar' : $v['faixa_salarial'];
                ?>
                <div class="col-md-6 col-xl-4">
                    <div class="card-c h-100 d-flex flex-column">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="badge bg-dark"><?=$v['setor']?></span>
                            <span class="badge bg-light text-dark border"><?=$v['regime_contratacao']?></span>
                        </div>
                        <h5 class="fw-bold mb-1"><?=$v['titulo']?></h5>
                        <p class="text-muted small mb-2"><i class="fas fa-map-marker-alt"></i> <?=$v['localizacao']?></p>
                        <div class="bg-light p-2 rounded small mb-3 text-truncate"><?=strip_tags($v['descricao'])?></div>
                        
                        <div class="mt-auto pt-3 border-top d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-success small"><?=$salario?></span>
                            <?php if(!$ja_inscrito): ?>
                                <form method="POST">
                                    <input type="hidden" name="aplicar_vaga" value="1"><input type="hidden" name="vaga_id" value="<?=$v['id']?>">
                                    <button class="btn btn-sm btn-success fw-bold" onclick="return confirm('Confirmar candidatura?')">Candidatar-se</button>
                                </form>
                            <?php else: ?>
                                <button class="btn btn-sm btn-secondary" disabled>Já Inscrito</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if(empty($vagas_abertas)): ?><div class="col-12"><div class="alert alert-info">Nenhuma vaga aberta no momento.</div></div><?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- HISTÓRICO -->
        <?php if($pagina == 'historico'): ?>
            <h3 class="mb-4">Minhas Vagas</h3>
            <div class="card-c p-0">
                <table class="table table-hover align-middle mb-0"><thead class="table-dark"><tr><th>Vaga</th><th>Data</th><th>Status</th><th class="text-end">Ação</th></tr></thead><tbody>
                <?php foreach($aplicacoes as $app): $cls='st-'.$app['status']; ?>
                <tr>
                    <td><strong><?=$app['titulo']?></strong><br><small class="text-muted"><?=$app['localizacao']?></small></td>
                    <td><?=date('d/m/Y',strtotime($app['data_aplicacao']))?></td>
                    <td><span class="badge <?=$cls?> badge-st"><?=strtoupper(str_replace('_',' ',$app['status']))?></span></td>
                    <td class="text-end">
                        <?php if($app['status']=='teste_pratico'): ?><a href="sala_teste.php?app_id=<?=$app['id']?>" class="btn btn-sm btn-primary fw-bold">Fazer Teste</a>
                        <?php elseif($app['status']!='desistencia' && $app['status']!='reprovado'): ?>
                        <form method="POST" onsubmit="return confirm('Desistir desta vaga?')" style="display:inline;">
                            <input type="hidden" name="desistir_vaga" value="1"><input type="hidden" name="app_id" value="<?=$app['id']?>">
                            <button class="btn btn-sm btn-outline-danger">Desistir</button>
                        </form>
                        <?php else: ?><span class="text-muted small">-</span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?></tbody></table>
            </div>
        <?php endif; ?>

        <!-- SALA DE TESTES -->
        <?php if($pagina == 'sala_teste'): ?>
            <h3 class="mb-4">Sala de Testes Virtuais</h3>
            <?php if($total_testes > 0): ?>
                <div class="row"><?php foreach($testes_pendentes as $tp): ?>
                    <div class="col-md-6"><div class="card-c border-start border-5 border-primary">
                        <h5 class="text-primary"><?=$tp['titulo']?></h5>
                        <p class="text-muted mb-2">Teste Prático Pendente</p>
                        <a href="sala_teste.php?app_id=<?=$tp['id']?>" class="btn btn-dark w-100">INICIAR TESTE</a>
                    </div></div>
                <?php endforeach; ?></div>
            <?php else: ?><div class="text-center py-5 bg-white rounded shadow-sm"><h4>Nenhum teste pendente.</h4><a href="?pag=mural" class="btn btn-outline-dark mt-2">Ver Vagas</a></div><?php endif; ?>
        <?php endif; ?>

        <!-- PERFIL -->
        <?php if($pagina == 'perfil'): ?>
            <h3 class="mb-4">Meu Perfil</h3>
            <div class="row g-4">
                <div class="col-md-4"><div class="card-c text-center">
                    <i class="fas fa-user-circle fa-5x text-secondary mb-3"></i>
                    <h4><?=$meus_dados['nome']?></h4><p class="text-muted"><?=$meus_dados['area_interesse']?></p>
                    <hr><p class="small text-start"><strong>Email:</strong> <?=$meus_dados['email']?></p>
                </div></div>
                
                <div class="col-md-8"><div class="card-c">
                    <h5 class="border-bottom pb-2 mb-3">Análise Comportamental</h5>
                    <?php if(!empty($meus_dados['perfil_comportamental'])): $p=$meus_dados['perfil_comportamental']; ?>
                        <div class="text-center py-4 bg-light rounded border"><h2 class="text-primary fw-bold"><?=$p?></h2><p class="text-muted mb-0">Teste realizado.</p></div>
                    <?php else: ?>
                        <div class="alert alert-warning">Pendente: Teste Comportamental.</div>
                        <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#modalTesteComp">INICIAR TESTE</button>
                    <?php endif; ?>
                </div></div>
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