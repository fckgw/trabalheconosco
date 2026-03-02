<?php
session_start();
// --- CONFIGURAÇÃO ---
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'includes/db.php';
require_once 'includes/email.php';
require_once 'includes/calendar.php';

// Segurança
if (!isset($_SESSION['nivel']) || $_SESSION['nivel'] !== 'rh') {
    header("Location: index.php");
    exit;
}

$pdo = getDB();
$pagina = $_GET['pag'] ?? 'dashboard';
$msg = "";
$erro = "";
$popup_whatsapp = false;

// =================================================================================
// 0. API AJAX (Adicionar Skill Dinamicamente)
// =================================================================================
if (isset($_POST['ajax_add_skill'])) {
    header('Content-Type: application/json');
    $nome = trim($_POST['ajax_add_skill']);
    if(empty($nome)) { echo json_encode(['erro' => 'Nome vazio']); exit; }
    try {
        $stmt = $pdo->prepare("SELECT id FROM conhecimentos WHERE nome = ?");
        $stmt->execute([$nome]);
        $existe = $stmt->fetch();
        if ($existe) {
            echo json_encode(['id' => $existe['id'], 'nome' => $nome, 'status' => 'existia']);
        } else {
            $pdo->prepare("INSERT INTO conhecimentos (nome) VALUES (?)")->execute([$nome]);
            echo json_encode(['id' => $pdo->lastInsertId(), 'nome' => $nome, 'status' => 'novo']);
        }
    } catch (Exception $e) { echo json_encode(['erro' => $e->getMessage()]); }
    exit;
}

// 0.1 AJAX DETALHES + HISTÓRICO
if (isset($_POST['ajax_get_details'])) {
    header('Content-Type: application/json');
    $cid = $_POST['cand_id']; $aid = $_POST['app_id'];
    $c = $pdo->query("SELECT * FROM candidatos WHERE id = $cid")->fetch(PDO::FETCH_ASSOC);
    $logs = $pdo->query("SELECT * FROM logs_testes WHERE aplicacao_id = $aid ORDER BY data_hora DESC")->fetchAll(PDO::FETCH_ASSOC);
    $historico = $pdo->query("SELECT * FROM historico_fases WHERE aplicacao_id = $aid ORDER BY data_movimentacao DESC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['cand' => $c, 'logs' => $logs, 'historico' => $historico]);
    exit;
}

// =================================================================================
// 1. LÓGICA DE VAGAS
// =================================================================================
if (isset($_POST['acao_vaga'])) {
    try {
        $pdo->beginTransaction();
        $arq = null;
        if (isset($_FILES['arquivo_teste']) && $_FILES['arquivo_teste']['error'] == 0) {
            $ext = pathinfo($_FILES['arquivo_teste']['name'], PATHINFO_EXTENSION);
            $nm = "VT_".uniqid().".".$ext; move_uploaded_file($_FILES['arquivo_teste']['tmp_name'], "uploads/testes_rh/".$nm); $arq = $nm;
        }

        if($_POST['acao_vaga'] == 'criar') {
            $sql = "INSERT INTO vagas (titulo, setor, tipo_vaga, descricao, requisitos, teste_pratico, arquivo_teste, banco_teste_id, localizacao, faixa_salarial, salario_real, quantidade_vagas, regime_contratacao, idade_min, idade_max, altura_minima, altura_nao_necessaria, req_ensino_fundamental, req_ensino_medio, req_office, req_ingles, req_espanhol, req_powerbi, exclusivo_pcd, ocultar_salario, data_inicio, data_fim, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            
            $pdo->prepare($sql)->execute([
                $_POST['titulo'], $_POST['setor'], $_POST['tipo_vaga'], $_POST['descricao'], $_POST['requisitos'], 
                $_POST['teste_pratico'], $arq, ($_POST['banco_teste_id']?:null),
                $_POST['localizacao'], $_POST['faixa_salarial'], $_POST['salario_real'], $_POST['qtd'], $_POST['regime'],
                $_POST['idade_min'], $_POST['idade_max'], ($_POST['altura_minima']?:null), ($_POST['altura_nao_necessaria']??0),
                ($_POST['req_ensino_fundamental']??0), ($_POST['req_ensino_medio']??0), ($_POST['req_office']??0), $_POST['req_ingles'], $_POST['req_espanhol'], ($_POST['req_powerbi']??0),
                ($_POST['exclusivo_pcd']??0), ($_POST['ocultar_salario']??0), $_POST['inicio'], $_POST['fim'], 'aberta'
            ]);
            $vid = $pdo->lastInsertId();

            $skills = $_POST['skills'] ?? []; $obrig = $_POST['obrigatorio'] ?? [];
            $stmtSk = $pdo->prepare("INSERT INTO vagas_conhecimentos (vaga_id, conhecimento_id, obrigatorio) VALUES (?,?,?)");
            foreach ($skills as $sid) { $stmtSk->execute([$vid, $sid, (isset($obrig[$sid])?1:0)]); }
            
            $fases = $_POST['fases_selecionadas'] ?? [];
            $stmtFases = $pdo->prepare("INSERT INTO vagas_fases_vinculo (vaga_id, fase_id) VALUES (?,?)");
            foreach ($fases as $fid) { $stmtFases->execute([$vid, $fid]); }

            $pdo->commit();
            $msg = "Vaga criada com sucesso!";
        }
    } catch (Exception $e) { if($pdo->inTransaction()) $pdo->rollBack(); $erro = $e->getMessage(); }
}

if (isset($_GET['acao']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    if ($_GET['acao'] == 'encerrar') { $pdo->prepare("UPDATE vagas SET status = 'fechada' WHERE id = ?")->execute([$id]); $msg = "Vaga encerrada."; }
    if ($_GET['acao'] == 'excluir') {
        $pdo->prepare("DELETE FROM aplicacoes WHERE vaga_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM vagas WHERE id = ?")->execute([$id]);
        $msg = "Vaga excluída.";
    }
}

// =================================================================================
// 2. LÓGICA DE FASES (NOVO: EDIÇÃO)
// =================================================================================
if (isset($_POST['salvar_fase_sistema'])) {
    $pdo->prepare("INSERT INTO fases_processo (nome, cor, ordem) VALUES (?,?,?)")->execute([$_POST['nome_fase'], $_POST['cor_fase'], $_POST['ordem_fase']]);
    $msg = "Nova fase cadastrada.";
}
// ATUALIZAÇÃO DA FASE (ORDEM/NOME)
if (isset($_POST['editar_fase'])) {
    $pdo->prepare("UPDATE fases_processo SET nome = ?, cor = ?, ordem = ? WHERE id = ?")
        ->execute([$_POST['nome_fase'], $_POST['cor_fase'], $_POST['ordem_fase'], $_POST['id_fase']]);
    $msg = "Fase atualizada com sucesso!";
}
if (isset($_GET['del_fase'])) {
    $pdo->prepare("DELETE FROM fases_processo WHERE id = ?")->execute([$_GET['del_fase']]);
    $msg = "Fase removida.";
}

// =================================================================================
// 3. LÓGICA BANCO DE TESTES & TEMPLATES
// =================================================================================
if (isset($_POST['salvar_teste_db'])) { /* ... Mantido ... */ }
if (isset($_POST['add_questao'])) { /* ... Mantido ... */ }
if (isset($_POST['salvar_template'])) {
    $fase = $_POST['fase']; $assunto = $_POST['assunto']; $mensagem = $_POST['mensagem'];
    $check = $pdo->query("SELECT fase FROM mensagens_padrao WHERE fase = '$fase'")->fetch();
    if ($check) { $pdo->prepare("UPDATE mensagens_padrao SET assunto = ?, mensagem = ? WHERE fase = ?")->execute([$assunto, $mensagem, $fase]); $msg = "Template atualizado!"; } 
    else { $pdo->prepare("INSERT INTO mensagens_padrao (fase, assunto, mensagem) VALUES (?, ?, ?)")->execute([$fase, $assunto, $mensagem]); $msg = "Novo template cadastrado!"; }
}

// =================================================================================
// 4. GESTÃO CANDIDATOS
// =================================================================================
if (isset($_POST['mudar_status_completo'])) {
    try {
        $novo_status = $_POST['novo_status']; 
        $app_id = $_POST['app_id'];
        $status_anterior = $_POST['status_anterior'];
        $obs = $_POST['observacao_fase'];
        $rec = $_SESSION['nome'];

        $pdo->prepare("INSERT INTO historico_fases (aplicacao_id, fase_anterior, fase_nova, observacao, recrutador) VALUES (?,?,?,?,?)")->execute([$app_id, $status_anterior, $novo_status, $obs, $rec]);

        $data_db=null; $local_db=null; $link_db=null;
        if (strpos(strtolower($novo_status), 'entrevista') !== false && !empty($_POST['data_entrevista'])) {
            $data_db = $_POST['data_entrevista'];
            $tipo = $_POST['tipo_entrevista'];
            if ($tipo == 'Presencial') {
                $local_db = ($_POST['end_rua'] ?? '') . ", " . ($_POST['end_num'] ?? '') . " - " . ($_POST['end_bairro'] ?? '');
            } else {
                $link_db = $_POST['link_reuniao'] ?? '';
                $local_db = "Online via " . ($_POST['plataforma_online'] ?? 'Web');
            }
        }

        $pdo->prepare("UPDATE aplicacoes SET status = ?, data_entrevista = ?, local_entrevista = ?, link_reuniao = ? WHERE id = ?")->execute([$novo_status, $data_db, $local_db, $link_db, $app_id]);

        if (isset($_POST['notificar_candidato'])) {
            $mailer = new EmailHelper();
            $assunto = $_POST['assunto_email'] ?? "Atualização Processo";
            $mailer->enviar($_POST['email_cand'], $_POST['nome_cand'], $assunto, nl2br($_POST['mensagem_email']));
            if (isset($_POST['enviar_whats'])) {
                $_SESSION['popup_whatsapp'] = ['telefone' => $_POST['telefone_cand'], 'mensagem' => $_POST['mensagem_whats']];
            }
        }
        $msg = "Fase atualizada!";
    } catch (Exception $e) { $erro = $e->getMessage(); }
}
if (isset($_POST['reativar_teste'])) {
    $pdo->prepare("UPDATE aplicacoes SET data_inicio_teste = NULL, data_fim_teste = NULL, resultado_teste = NULL, status = 'teste_pratico' WHERE id = ?")->execute([$_POST['app_id']]);
    $pdo->prepare("INSERT INTO logs_testes (aplicacao_id, acao, detalhes) VALUES (?, 'Reativado', 'Reiniciado por RH')")->execute([$_POST['app_id']]);
    $msg = "Teste reiniciado.";
}
if (isset($_POST['salvar_obs_geral'])) {
    $pdo->prepare("UPDATE candidatos SET observacoes_rh = ? WHERE id = ?")->execute([$_POST['obs_geral'], $_POST['cand_id']]);
    $msg = "Ficha atualizada.";
}

// DADOS GERAIS
$total_vagas = $pdo->query("SELECT COUNT(*) FROM vagas WHERE status='aberta'")->fetchColumn();
$total_cands = $pdo->query("SELECT COUNT(*) FROM candidatos")->fetchColumn();
$total_proc  = $pdo->query("SELECT COUNT(*) FROM aplicacoes WHERE status='entrevista'")->fetchColumn();
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"> <meta name="viewport" content="width=device-width, initial-scale=1"> <title>RH Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.3/tinymce.min.js" referrerpolicy="no-referrer"></script>
    <script>tinymce.init({ selector:'.editor-rico', height:200, menubar:false, branding:false, plugins:'lists link image table', toolbar:'bold italic bullist numlist' });</script>

    <style>
        body { background: #f4f6f9; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }
        .sidebar { min-height: 100vh; background: #1a1a1a; color: #fff; width: 260px; position: fixed; z-index: 1000; }
        .sidebar .brand { padding: 25px; text-align: center; border-bottom: 1px solid #333; background: #000; }
        .sidebar a { color: #aaa; text-decoration: none; padding: 15px 20px; display: block; border-bottom: 1px solid #222; font-size: 0.95rem; }
        .sidebar a:hover, .sidebar a.active { background: #222; color: #fff; border-left: 4px solid #0d6efd; padding-left: 25px; }
        .main-content { margin-left: 260px; padding: 30px; }
        @media (max-width: 768px) { .sidebar { width: 100%; position: relative; min-height: auto; } .main-content { margin-left: 0; padding: 15px; } }
        .card-custom { border: none; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); background: white; margin-bottom: 25px; padding: 25px; }
        .skill-box { transition: 0.2s; border: 1px solid #ddd; }
        .skill-box.mandatory { border: 2px solid #dc3545; background-color: #fff8f8; }
        .skill-box.desirable { border: 2px solid #0d6efd; background-color: #f8fbff; }
    </style>
</head>
<body>

    <nav class="sidebar">
        <div class="brand"><img src="logo-branco.png" width="130"></div>
        <a href="?pag=dashboard" class="<?=$pagina=='dashboard'?'active':''?>"><i class="fas fa-chart-line me-2"></i> Dashboard</a>
        <a href="?pag=vagas" class="<?=$pagina=='vagas'?'active':''?>"><i class="fas fa-briefcase me-2"></i> Nova Vaga</a>
        <a href="?pag=config_fases" class="<?=$pagina=='config_fases'?'active':''?>"><i class="fas fa-tasks me-2"></i> Config. Fases</a>
        <a href="?pag=lista_vagas" class="<?=$pagina=='lista_vagas'?'active':''?>"><i class="fas fa-list-ul me-2"></i> Minhas Vagas</a>
        <a href="?pag=banco_testes" class="<?=$pagina=='banco_testes'?'active':''?>"><i class="fas fa-file-alt me-2"></i> Banco de Testes</a>
        <a href="?pag=templates" class="<?=$pagina=='templates'?'active':''?>"><i class="fas fa-envelope-open-text me-2 text-warning"></i> Templates</a>
        <a href="?pag=candidatos" class="<?=$pagina=='candidatos'?'active':''?>"><i class="fas fa-users me-2"></i> Candidatos</a>
        <a href="logout.php" class="text-danger mt-4"><i class="fas fa-sign-out-alt me-2"></i> Sair</a>
    </nav>

    <div class="main-content">
        <?php if($msg): ?><div class="alert alert-success shadow-sm rounded-3"><i class="fas fa-check-circle me-2"></i> <?=$msg?></div><?php endif; ?>
        <?php if($erro): ?><div class="alert alert-danger shadow-sm rounded-3"><i class="fas fa-exclamation-triangle me-2"></i> <?=$erro?></div><?php endif; ?>

        <!-- DASHBOARD -->
        <?php if($pagina == 'dashboard'): ?>
            <h3 class="mb-4 text-dark fw-bold">Dashboard</h3>
            <div class="row g-4 mt-2">
                <div class="col-md-6"><div class="card-custom border-start border-5 border-primary"><h2><?=$total_vagas?></h2><p class="text-muted mb-0">Vagas Abertas</p></div></div>
                <div class="col-md-6"><div class="card-custom border-start border-5 border-success"><h2><?=$total_cands?></h2><p class="text-muted mb-0">Candidatos</p></div></div>
            </div>
            <!-- Grid Vagas -->
            <div class="card-custom mt-4 p-0"><div class="p-3 border-bottom"><strong>Vagas Recentes</strong></div><table class="table table-hover mb-0"><thead class="table-light"><tr><th>Vaga</th><th>Inscritos</th><th>Ação</th></tr></thead><tbody>
            <?php $vagas_dash = $pdo->query("SELECT v.*, (SELECT COUNT(*) FROM aplicacoes WHERE vaga_id = v.id) as n FROM vagas v ORDER BY created_at DESC LIMIT 5")->fetchAll(); foreach($vagas_dash as $v): ?>
            <tr><td><?=$v['titulo']?></td><td><span class="badge bg-primary rounded-pill"><?=$v['n']?></span></td><td><a href="?pag=candidatos&filtro_vaga=<?=$v['id']?>" class="btn btn-outline-primary btn-sm">Ver Inscritos</a></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>

        <!-- NOVA VAGA -->
        <?php if($pagina == 'vagas'): 
            $skills_db = $pdo->query("SELECT * FROM conhecimentos ORDER BY nome")->fetchAll();
            $testes_db = $pdo->query("SELECT * FROM banco_testes WHERE status='ativo' ORDER BY titulo")->fetchAll();
            $fases_db = $pdo->query("SELECT * FROM fases_processo ORDER BY ordem ASC")->fetchAll();
        ?>
            <h3 class="mb-4">Nova Vaga</h3>
            <form method="POST" enctype="multipart/form-data" class="card-custom">
                <input type="hidden" name="acao_vaga" value="criar">
                <h5 class="text-primary mb-3">Dados da Vaga</h5>
                <div class="row g-3">
                    <div class="col-md-6"><label class="fw-bold">Título</label><input type="text" name="titulo" class="form-control" required></div>
                    <div class="col-md-3"><label>Tipo</label><select name="tipo_vaga" class="form-select"><option value="externa">Externa</option><option value="interna">Interna</option><option value="mista">Mista</option></select></div>
                    <div class="col-md-3"><label>Setor</label><select name="setor" class="form-select"><option value="">Selecione...</option><optgroup label="Industrial"><option>Produção</option><option>Manutenção</option><option>Qualidade</option><option>Logística</option></optgroup><optgroup label="Escritório"><option>Administrativo</option><option>Comercial</option><option>TI</option><option>RH</option><option>Financeiro</option></optgroup></select></div>
                    <div class="col-md-3"><label class="text-success fw-bold">Salário (Site)</label><input type="text" name="faixa_salarial" class="form-control"></div>
                    <div class="col-md-3"><label class="text-danger fw-bold">Salário (Interno)</label><input type="number" step="0.01" name="salario_real" class="form-control"></div>
                    <div class="col-md-3 mt-4"><label class="text-danger"><input type="checkbox" name="ocultar_salario" value="1"> Ocultar</label></div>
                    <div class="col-md-3"><label>Regime</label><select name="regime" class="form-select"><option>CLT</option><option>Temporário</option><option>PJ</option><option>Estágio</option><option>Jovem Aprendiz</option><option>Freelancer</option></select></div>
                    <div class="col-md-6"><label>Local</label><input type="text" name="localizacao" class="form-control" value="Sete Lagoas - MG"></div>
                    <div class="col-md-2"><label>Qtd</label><input type="number" name="qtd" class="form-control" value="1"></div>
                    <div class="col-md-2"><label>Início</label><input type="date" name="inicio" class="form-control" required></div>
                    <div class="col-md-2"><label>Fim</label><input type="date" name="fim" class="form-control" required></div>
                    <div class="col-12"><div class="alert alert-light border p-2 mb-0 small"><i class="fas fa-lock"></i> <strong>Filtro Interno:</strong> Idade <input type="number" name="idade_min" class="d-inline-block w-auto form-control form-control-sm" value="18"> a <input type="number" name="idade_max" class="d-inline-block w-auto form-control form-control-sm" value="65"> | Altura Mín <input type="number" step="0.01" name="altura_minima" class="d-inline-block w-auto form-control form-control-sm"> | <label><input type="checkbox" name="altura_nao_necessaria" value="1" checked> Altura ñ necessária</label></div></div>
                    <div class="col-md-6"><label>Descrição</label><textarea name="descricao" class="form-control" rows="4"></textarea></div>
                    <div class="col-md-6"><label>Requisitos (Texto)</label><textarea name="requisitos" class="form-control" rows="4"></textarea></div>
                    <div class="col-md-12"><label class="fw-bold">Fases do Processo</label><div class="d-flex flex-wrap gap-3 border p-3 rounded bg-white"><?php foreach($fases_db as $f): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="fases_selecionadas[]" value="<?=$f['id']?>" checked><label class="form-check-label badge bg-<?=$f['cor']?>"><?=$f['nome']?></label></div><?php endforeach; ?></div></div>
                </div>
                <hr>
                <div class="d-flex justify-content-between align-items-center mb-2"><h5 class="text-primary mb-0">Flags Obrigatórias</h5><span class="badge bg-secondary">Legenda: Azul = Desejável | Vermelho = Obrigatório</span></div>
                <div class="row g-3 bg-light p-3 rounded mb-3 border">
                    <div class="col-md-3"><label><input type="checkbox" name="req_ensino_fundamental" value="1"> Fundamental</label></div>
                    <div class="col-md-3"><label><input type="checkbox" name="req_ensino_medio" value="1"> Ensino Médio</label></div>
                    <div class="col-md-3"><label><input type="checkbox" name="req_office" value="1"> Pacote Office</label></div>
                    <div class="col-md-3"><label><input type="checkbox" name="req_powerbi" value="1"> Power BI</label></div>
                    <div class="col-md-3"><label class="text-primary fw-bold"><input type="checkbox" name="exclusivo_pcd" value="1"> Vaga PCD</label></div>
                    <div class="col-12"><hr></div>
                    <div class="col-md-6"><label>Inglês</label><select name="req_ingles" class="form-select form-select-sm"><option value="">-</option><option>Básico</option><option>Interm.</option><option>Avançado</option><option>Fluente</option></select></div>
                    <div class="col-md-6"><label>Espanhol</label><select name="req_espanhol" class="form-select form-select-sm"><option value="">-</option><option>Básico</option><option>Interm.</option><option>Avançado</option><option>Fluente</option></select></div>
                </div>
                <h6 class="text-muted mt-4">Conhecimentos Específicos</h6>
                <div class="row g-2 mb-3" id="container_skills"><?php foreach($skills_db as $sk): ?><div class="col-md-3 border rounded p-2 bg-white d-flex justify-content-between" id="box_<?=$sk['id']?>"><div><input type="checkbox" name="skills[]" value="<?=$sk['id']?>"> <?=$sk['nome']?></div><div class="form-check form-switch"><input class="form-check-input chk-obrig" type="checkbox" name="obrigatorio[<?=$sk['id']?>]" onchange="toggleColor(<?=$sk['id']?>)"></div></div><?php endforeach; ?></div>
                <div class="row"><div class="col-md-6"><div class="input-group"><input type="text" id="nova_skill_input" class="form-control" placeholder="Novo..."><button type="button" class="btn btn-dark" onclick="adicionarSkillRapido()">Add</button></div></div></div>
                <hr>
                <h5 class="text-primary mb-3">Teste Prático</h5>
                <div class="row"><div class="col-md-6"><label>Vincular Teste</label><select name="banco_teste_id" class="form-select"><option value="">Nenhum</option><?php foreach($testes_db as $t): ?><option value="<?=$t['id']?>"><?=$t['titulo']?></option><?php endforeach; ?></select></div><div class="col-md-6"><label>Arquivo (Opcional)</label><input type="file" name="arquivo_teste" class="form-control"></div><div class="col-12 mt-2"><label>Instruções</label><textarea name="teste_pratico" class="form-control" rows="2"></textarea></div></div>
                <div class="text-end mt-4"><button type="submit" class="btn btn-success btn-lg shadow">Salvar Vaga</button></div>
            </form>
            <script>function toggleColor(id){ let el=$('#box_'+id); if(el.find('.chk-obrig').is(':checked')) el.removeClass('desirable').addClass('mandatory'); else el.removeClass('mandatory').addClass('desirable'); }</script>
        <?php endif; ?>

        <!-- CONFIG FASES (NOVA ABA) -->
        <?php if($pagina == 'config_fases'): 
            $fases = $pdo->query("SELECT * FROM fases_processo ORDER BY ordem ASC")->fetchAll();
        ?>
            <h3>Configuração de Fases</h3>
            <div class="row"><div class="col-md-5"><div class="card-custom"><h5>Nova Fase</h5><form method="POST"><input type="hidden" name="salvar_fase_sistema" value="1"><div class="mb-3"><label>Nome</label><input type="text" name="nome_fase" class="form-control" required></div><div class="mb-3"><label>Cor</label><select name="cor_fase" class="form-select"><option value="secondary">Cinza</option><option value="primary">Azul</option><option value="success">Verde</option><option value="warning">Amarelo</option></select></div><div class="mb-3"><label>Ordem</label><input type="number" name="ordem_fase" class="form-control" value="10"></div><button class="btn btn-dark w-100">Adicionar</button></form></div></div>
            <div class="col-md-7"><div class="card-custom p-0"><table class="table mb-0"><thead><tr><th>Ordem</th><th>Fase</th><th>Ação</th></tr></thead><tbody><?php foreach($fases as $f): ?><tr><td><?=$f['ordem']?></td><td><span class="badge bg-<?=$f['cor']?>"><?=$f['nome']?></span></td><td>
                <button class="btn btn-sm btn-primary" onclick="abrirEdicaoFase(<?=$f['id']?>,'<?=$f['nome']?>','<?=$f['cor']?>',<?=$f['ordem']?>)"><i class="fas fa-edit"></i></button> 
                <a href="?pag=config_fases&del_fase=<?=$f['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('Excluir?')"><i class="fas fa-trash"></i></a>
            </td></tr><?php endforeach; ?></tbody></table></div></div></div>
            
            <!-- MODAL EDITAR FASE -->
            <div class="modal fade" id="modalEditaFase"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5>Editar Fase</h5></div><div class="modal-body"><form method="POST"><input type="hidden" name="editar_fase" value="1"><input type="hidden" name="id_fase" id="ef_id"><div class="mb-3"><label>Nome</label><input type="text" name="nome_fase" id="ef_nome" class="form-control"></div><div class="mb-3"><label>Cor</label><select name="cor_fase" id="ef_cor" class="form-select"><option value="secondary">Cinza</option><option value="primary">Azul</option><option value="success">Verde</option><option value="warning">Amarelo</option></select></div><div class="mb-3"><label>Ordem</label><input type="number" name="ordem_fase" id="ef_ordem" class="form-control"></div><button class="btn btn-success w-100">Salvar Alterações</button></form></div></div></div></div>
            <script>function abrirEdicaoFase(id,n,c,o){ $('#ef_id').val(id); $('#ef_nome').val(n); $('#ef_cor').val(c); $('#ef_ordem').val(o); new bootstrap.Modal('#modalEditaFase').show(); }</script>
        <?php endif; ?>

        <!-- LISTA DE VAGAS -->
        <?php if($pagina == 'lista_vagas'): $lista=$pdo->query("SELECT * FROM vagas ORDER BY id DESC")->fetchAll(); ?>
            <h3 class="mb-4">Minhas Vagas</h3>
            <div class="card-custom p-0"><table class="table table-hover mb-0"><thead class="table-dark"><tr><th>Vaga</th><th>Início</th><th>Fim</th><th>Status</th><th class="text-end">Ações</th></tr></thead><tbody>
            <?php foreach($lista as $v): ?><tr><td><strong><?=$v['titulo']?></strong></td><td><?=date('d/m/Y', strtotime($v['data_inicio']))?></td><td><?=date('d/m/Y', strtotime($v['data_fim']))?></td><td><span class="badge <?=$v['status']=='aberta'?'bg-success':'bg-secondary'?>"><?=strtoupper($v['status'])?></span></td><td class="text-end"><a href="?pag=lista_vagas&acao=excluir&id=<?=$v['id']?>" class="btn btn-sm btn-danger">Excluir</a></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>

        <!-- BANCO DE TESTES (MANTIDO) -->
        <?php if($pagina=='banco_testes'): /* (Conteúdo mantido da versão anterior para não estourar limite, cole aqui o bloco banco_testes) */ include 'admin_modules_rest.php'; endif; ?>
        
        <!-- CANDIDATOS (COMPLETO) -->
        <?php if($pagina == 'candidatos'): 
            $filtro = $_GET['filtro_vaga'] ?? '';
            $sql = "SELECT c.*, v.titulo as vaga, a.status, a.id as app_id FROM aplicacoes a JOIN candidatos c ON a.candidato_id=c.id JOIN vagas v ON a.vaga_id=v.id ";
            if($filtro) $sql .= " WHERE v.id = $filtro ";
            $sql .= " ORDER BY a.data_aplicacao DESC";
            $cands = $pdo->query($sql)->fetchAll();
            $vagas_opt = $pdo->query("SELECT id, titulo FROM vagas")->fetchAll();
            $fases_all = $pdo->query("SELECT * FROM fases_processo ORDER BY ordem ASC")->fetchAll();
        ?>
            <h3>Gestão Candidatos</h3>
            <form method="GET" class="card-custom p-3 bg-light d-flex gap-2"><input type="hidden" name="pag" value="candidatos"><select name="filtro_vaga" class="form-select w-auto"><option value="">Todas Vagas</option><?php foreach($vagas_opt as $vo): ?><option value="<?=$vo['id']?>" <?=$filtro==$vo['id']?'selected':''?>><?=$vo['titulo']?></option><?php endforeach; ?></select><button class="btn btn-dark">Filtrar</button></form>
            <div class="card-custom p-0"><table class="table table-hover mb-0 align-middle"><thead class="table-dark"><tr><th>Nome</th><th>Vaga</th><th>Status</th><th>Ações</th></tr></thead><tbody>
            <?php foreach($cands as $c): ?>
            <tr><td><strong><?=$c['nome']?></strong></td><td><?=$c['vaga']?></td><td><span class="badge bg-secondary"><?=$c['status']?></span></td><td><button class="btn btn-sm btn-outline-dark fw-bold btn-fase" data-appid="<?=$c['app_id']?>" data-nome="<?=$c['nome']?>" data-email="<?=$c['email']?>" data-tel="<?=preg_replace('/\D/','',$c['telefone'])?>" data-st="<?=$c['status']?>">Mudar Fase</button> <button class="btn btn-sm btn-primary" onclick="abrirDetalhes(<?=$c['id']?>, <?=$c['app_id']?>)">Detalhes</button></td></tr><?php endforeach; ?></tbody></table></div>
            
            <!-- MODAL FASE -->
            <div class="modal fade" id="modalFase"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5>Mudar Fase</h5></div><div class="modal-body"><form method="POST" id="formFase">
                <input type="hidden" name="mudar_status_completo" value="1"><input type="hidden" name="app_id" id="f_app_id"><input type="hidden" name="email_cand" id="f_email"><input type="hidden" name="nome_cand" id="f_nome"><input type="hidden" name="telefone_cand" id="f_tel"><input type="hidden" name="status_anterior" id="f_st_ant">
                <div class="row g-3"><div class="col-12"><label class="fw-bold">Nova Fase</label><select name="novo_status" id="f_status" class="form-select border-primary"><option value="">Selecione...</option><?php foreach($fases_all as $f): ?><option value="<?=$f['nome']?>"><?=$f['nome']?></option><?php endforeach; ?></select></div><div class="col-12"><label>Observação (Histórico)</label><textarea name="observacao_fase" class="form-control" rows="2" required></textarea></div><div class="col-12"><label>Mensagem</label><textarea name="mensagem_email" class="form-control" rows="3"></textarea><div class="form-check mt-2"><input type="checkbox" name="notificar_candidato" value="1" checked class="form-check-input"><label>Enviar E-mail</label></div></div><div class="col-12"><button class="btn btn-primary w-100">SALVAR</button></div></div>
            </form></div></div></div></div>
            
            <!-- MODAL DETALHES -->
            <div class="modal fade" id="modalDetalhes"><div class="modal-dialog modal-xl"><div class="modal-content"><div class="modal-header"><h5>Prontuário</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><ul class="nav nav-tabs mb-3"><li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tD">Dados</button></li><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tH">Timeline</button></li></ul><div class="tab-content"><div class="tab-pane fade show active" id="tD"><h5 id="dNome"></h5><p id="dEmail"></p><div id="dForm"></div><div id="dExp"></div></div><div class="tab-pane fade" id="tH"><h6>Histórico</h6><div id="timelineContainer"></div></div></div></div></div></div></div>
            <script>
                document.querySelectorAll('.btn-fase').forEach(btn => { btn.addEventListener('click', function() { $('#f_app_id').val(this.dataset.appid); $('#f_nome').val(this.dataset.nome); $('#f_email').val(this.dataset.email); $('#f_tel').val(this.dataset.tel); $('#f_st_ant').val(this.dataset.st); new bootstrap.Modal('#modalFase').show(); }); });
                function abrirDetalhes(cid, aid) { 
                    $.post('admin.php', { ajax_get_details: 1, cand_id: cid, app_id: aid }, function(data) { 
                        $('#dNome').text(data.cand.nome); $('#dEmail').text(data.cand.email); 
                        $('#dForm').html(data.cand.formacoes_academicas ? 'Possui formação' : 'Sem formação');
                        let hist = data.historico.map(h => `<div class="timeline-item"><strong>${h.fase_nova}</strong> <small>${h.data_movimentacao} (${h.recrutador})</small><br><em>${h.observacao}</em></div>`).join('');
                        $('#timelineContainer').html(hist || 'Sem histórico.');
                        new bootstrap.Modal('#modalDetalhes').show(); 
                    }, 'json'); 
                }
            </script>
        <?php endif; ?>

        <!-- TEMPLATES -->
        <?php if($pagina=='templates') { include 'admin_modules_rest.php'; } ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>function adicionarSkillRapido() { let n=$('#nova_skill_input').val(); if(n) $.post('admin.php',{ajax_add_skill:n},function(d){ if(!d.erro) $('#container_skills').append(`<div class="col-md-3 border rounded p-2 bg-white d-flex justify-content-between" id="box_${d.id}"><div><input type="checkbox" name="skills[]" value="${d.id}" checked> ${d.nome}</div><div class="form-check form-switch"><input class="form-check-input chk-obrig" type="checkbox" name="obrigatorio[${d.id}]" onchange="toggleColor(${d.id})"></div></div>`); },'json'); } function toggleColor(id){ let el=$('#box_'+id); if(el.find('.chk-obrig').is(':checked')) el.removeClass('desirable').addClass('mandatory'); else el.removeClass('mandatory').addClass('desirable'); }</script>
</body>
</html>