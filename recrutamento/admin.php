<?php
session_start();
// --- CONFIGURAÇÃO ---
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'includes/db.php';
require_once 'includes/email.php';
require_once 'includes/calendar.php';

// Segurança: Apenas RH
if (!isset($_SESSION['nivel']) || $_SESSION['nivel'] !== 'rh') {
    header("Location: index.php");
    exit;
}

$pdo = getDB();
$pagina = $_GET['pag'] ?? 'dashboard';
$msg = "";
$erro = "";

// =================================================================================
// LÓGICA DE PROCESSAMENTO (POST/GET)
// =================================================================================

// AJAX: Adicionar Conhecimento (Skill)
if (isset($_POST['ajax_add_skill'])) {
    header('Content-Type: application/json');
    $nome = trim($_POST['ajax_add_skill']);
    if(empty($nome)) { echo json_encode(['erro' => 'Vazio']); exit; }
    $ex = $pdo->prepare("SELECT id FROM conhecimentos WHERE nome = ?");
    $ex->execute([$nome]);
    $res = $ex->fetch();
    if($res) echo json_encode(['id'=>$res['id'], 'nome'=>$nome]);
    else { $pdo->prepare("INSERT INTO conhecimentos (nome) VALUES (?)")->execute([$nome]); echo json_encode(['id'=>$pdo->lastInsertId(), 'nome'=>$nome]); }
    exit;
}

// AJAX: Detalhes do Candidato (Timeline, Formação, Exp)
if (isset($_POST['ajax_get_details'])) {
    header('Content-Type: application/json');
    $cid = $_POST['cand_id'];
    $aid = $_POST['app_id'];
    $c = $pdo->query("SELECT * FROM candidatos WHERE id = $cid")->fetch(PDO::FETCH_ASSOC);
    $form = $pdo->query("SELECT * FROM formacoes_academicas WHERE candidato_id = $cid")->fetchAll(PDO::FETCH_ASSOC);
    $exp = $pdo->query("SELECT * FROM experiencias WHERE candidato_id = $cid ORDER BY inicio DESC")->fetchAll(PDO::FETCH_ASSOC);
    $hist = $pdo->query("SELECT * FROM historico_fases WHERE aplicacao_id = $aid ORDER BY data_movimentacao DESC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['cand' => $c, 'formacoes' => $form, 'experiencias' => $exp, 'historico' => $hist]);
    exit;
}

// AJAX: Verificar Senha do Recrutador
if (isset($_POST['ajax_check_pass'])) {
    header('Content-Type: application/json');
    $user = $pdo->query("SELECT senha FROM usuarios WHERE id = ".$_SESSION['usuario_id'])->fetch();
    if (password_verify($_POST['password'], $user['senha']) || $_POST['password'] === $user['senha']) echo json_encode(['status' => 'ok']);
    else echo json_encode(['status' => 'error']);
    exit;
}

// SALVAR NOVA VAGA
if (isset($_POST['acao_vaga']) && $_POST['acao_vaga'] == 'criar') {
    try {
        $pdo->beginTransaction();
        $arq = null;
        if (isset($_FILES['arquivo_teste']) && $_FILES['arquivo_teste']['error'] == 0) {
            $ext = pathinfo($_FILES['arquivo_teste']['name'], PATHINFO_EXTENSION);
            $nm = "VT_".uniqid().".".$ext; move_uploaded_file($_FILES['arquivo_teste']['tmp_name'], "uploads/testes_rh/".$nm); $arq = $nm;
        }
        $sql = "INSERT INTO vagas (titulo, setor, tipo_vaga, descricao, requisitos, teste_pratico, arquivo_teste, banco_teste_id, localizacao, faixa_salarial, salario_real, quantidade_vagas, regime_contratacao, idade_min, idade_max, altura_minima, altura_nao_necessaria, req_ensino_fundamental, req_ensino_medio, req_office, req_ingles, req_espanhol, req_powerbi, exclusivo_pcd, ocultar_salario, data_inicio, data_fim, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $pdo->prepare($sql)->execute([
            $_POST['titulo'], $_POST['setor'], $_POST['tipo_vaga'], $_POST['descricao'], $_POST['requisitos'], $_POST['teste_pratico'], $arq, ($_POST['banco_teste_id']?:null),
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
        $stmtF = $pdo->prepare("INSERT INTO vagas_fases_vinculo (vaga_id, fase_id) VALUES (?,?)");
        foreach ($fases as $fid) { $stmtF->execute([$vid, $fid]); }
        $pdo->commit(); $msg = "Vaga criada!";
    } catch (Exception $e) { if($pdo->inTransaction()) $pdo->rollBack(); $erro = $e->getMessage(); }
}

// MUDAR FASE (EM MASSA OU INDIVIDUAL)
if (isset($_POST['mudar_status_completo'])) {
    try {
        $ids = explode(',', $_POST['app_id']);
        $novo_status = $_POST['novo_status'];
        $msg_base = $_POST['mensagem_email'];
        foreach ($ids as $aid) {
            if(empty($aid)) continue;
            $d = $pdo->query("SELECT c.nome, c.email, c.telefone, v.titulo as vaga FROM aplicacoes a JOIN candidatos c ON a.candidato_id=c.id JOIN vagas v ON a.vaga_id=v.id WHERE a.id=$aid")->fetch();
            $data_db=null; $local_db=null;
            if ($novo_status == 'entrevista' && !empty($_POST['data_entrevista'])) {
                $data_db = $_POST['data_entrevista'];
                $local_db = ($_POST['tipo_entrevista']=='Presencial') ? ($_POST['end_rua']??'') : "Online";
            }
            $pdo->prepare("UPDATE aplicacoes SET status = ?, data_entrevista = ?, local_entrevista = ? WHERE id = ?")->execute([$novo_status, $data_db, $local_db, $aid]);
            $pdo->prepare("INSERT INTO historico_fases (aplicacao_id, fase_anterior, fase_nova, observacao, recrutador) VALUES (?,?,?,?,?)")->execute([$aid, $_POST['status_anterior'], $novo_status, $_POST['observacao_fase'], $_SESSION['nome']]);
            if (isset($_POST['notificar_candidato'])) {
                $mailer = new EmailHelper();
                $corpo = str_replace('{NOME}', $d['nome'], $msg_base);
                $mailer->enviar($d['email'], $d['nome'], "Atualização Cedro", nl2br($corpo));
            }
            if (count($ids) == 1 && isset($_POST['enviar_whats'])) {
                $_SESSION['popup_whatsapp'] = ['telefone' => $d['telefone'], 'mensagem' => str_replace('{NOME}', $d['nome'], $_POST['mensagem_whats'])];
            }
        }
        $msg = "Fase atualizada!";
    } catch (Exception $e) { $erro = $e->getMessage(); }
}

// LÓGICA DE FASES, TEMPLATES E LOGOUT (RESUMIDA NO TOPO)
if (isset($_POST['salvar_template'])) {
    $pdo->prepare("REPLACE INTO mensagens_padrao (fase, assunto, mensagem) VALUES (?,?,?)")->execute([$_POST['fase'], $_POST['assunto'], $_POST['mensagem']]); $msg="Template salvo.";
}
if (isset($_POST['salvar_fase_sistema'])) { $pdo->prepare("INSERT INTO fases_processo (nome, cor, ordem) VALUES (?,?,?)")->execute([$_POST['nome_fase'], $_POST['cor_fase'], $_POST['ordem_fase']]); $msg="Fase criada."; }
if (isset($_POST['editar_fase'])) { $pdo->prepare("UPDATE fases_processo SET nome=?, cor=?, ordem=? WHERE id=?")->execute([$_POST['nome_fase'], $_POST['cor_fase'], $_POST['ordem_fase'], $_POST['id_fase']]); $msg="Fase editada."; }
if (isset($_GET['del_fase'])) { $pdo->prepare("DELETE FROM fases_processo WHERE id=?")->execute([$_GET['del_fase']]); }
if (isset($_GET['acao']) && $_GET['acao']=='encerrar') { $pdo->prepare("UPDATE vagas SET status='fechada' WHERE id=?")->execute([$_GET['id']]); }

// DADOS GERAIS
$total_vagas = $pdo->query("SELECT COUNT(*) FROM vagas WHERE status='aberta'")->fetchColumn();
$total_cands = $pdo->query("SELECT COUNT(*) FROM candidatos")->fetchColumn();
$total_proc  = $pdo->query("SELECT COUNT(*) FROM aplicacoes WHERE status='entrevista'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"> <meta name="viewport" content="width=device-width, initial-scale=1"> <title>RH Admin - Cedro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.3/tinymce.min.js" referrerpolicy="no-referrer"></script>
    <script>tinymce.init({ selector:'.editor-rico', height:200, menubar:false, branding:false, plugins:'lists link image table', toolbar:'bold italic bullist numlist' });</script>
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>
    <style>
        body { background: #f4f6f9; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }
        .sidebar { min-height: 100vh; background: #1a1a1a; color: #fff; width: 260px; position: fixed; z-index: 1000; }
        .sidebar .brand { padding: 25px; text-align: center; border-bottom: 1px solid #333; background: #000; }
        .sidebar a { color: #aaa; text-decoration: none; padding: 15px 20px; display: block; border-bottom: 1px solid #222; font-size: 0.95rem; }
        .sidebar a:hover, .sidebar a.active { background: #222; color: #fff; border-left: 4px solid #0d6efd; padding-left: 25px; }
        .sidebar i { width: 25px; margin-right: 10px; }
        .main-content { margin-left: 260px; padding: 30px; }
        @media (max-width: 768px) { .sidebar { width: 100%; position: relative; min-height: auto; } .main-content { margin-left: 0; } }
        .card-custom { border: none; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); background: white; margin-bottom: 25px; padding: 25px; }
        #calendar { background: white; padding: 20px; border-radius: 10px; }
    </style>
</head>
<body>
    <!-- SIDEBAR COMPLETA -->
    <nav class="sidebar">
        <div class="brand"><img src="logo-branco.png" width="130"></div>
        <a href="?pag=dashboard" class="<?=$pagina=='dashboard'?'active':''?>"><i class="fas fa-chart-pie"></i> Dashboard</a>
        <a href="?pag=agenda" class="<?=$pagina=='agenda'?'active':''?>"><i class="fas fa-calendar-alt text-warning"></i> Agenda</a>
        <a href="?pag=vagas" class="<?=$pagina=='vagas'?'active':''?>"><i class="fas fa-briefcase"></i> Nova Vaga</a>
        <a href="?pag=lista_vagas" class="<?=$pagina=='lista_vagas'?'active':''?>"><i class="fas fa-list-ul"></i> Minhas Vagas</a>
        <a href="banco_testes.php"><i class="fas fa-file-alt"></i> Banco de Testes</a>
        <a href="?pag=templates" class="<?=$pagina=='templates'?'active':''?>"><i class="fas fa-envelope-open-text text-info"></i> Templates</a>
        <a href="?pag=config_fases" class="<?=$pagina=='config_fases'?'active':''?>"><i class="fas fa-tasks text-success"></i> Config. Fases</a>
        <a href="?pag=candidatos" class="<?=$pagina=='candidatos'?'active':''?>"><i class="fas fa-users"></i> Candidatos</a>
        <a href="logout.php" class="text-danger mt-4"><i class="fas fa-sign-out-alt"></i> Sair</a>
    </nav>

    <div class="main-content">
        <?php if($msg): ?><div class="alert alert-success"><?=$msg?></div><?php endif; ?>
        <?php if($erro): ?><div class="alert alert-danger"><?=$erro?></div><?php endif; ?>

        <!-- DASHBOARD -->
        <?php if($pagina == 'dashboard'): ?>
            <h3 class="mb-4">Dashboard</h3>
            <div class="row g-4">
                <div class="col-md-4"><div class="card-custom border-start border-5 border-primary"><h2><?=$total_vagas?></h2> Vagas Abertas</div></div>
                <div class="col-md-4"><div class="card-custom border-start border-5 border-success"><h2><?=$total_cands?></h2> Candidatos</div></div>
                <div class="col-md-4"><div class="card-custom border-start border-5 border-warning"><h2><?=$total_proc?></h2> Entrevistas</div></div>
            </div>
            <div class="card-custom mt-4 p-0">
                <div class="p-3 border-bottom"><strong>Vagas Recentes</strong></div>
                <table class="table table-hover mb-0"><thead><tr><th>Status</th><th>Vaga</th><th>Inscritos</th><th>Ação</th></tr></thead><tbody>
                <?php $vs = $pdo->query("SELECT v.*, (SELECT COUNT(*) FROM aplicacoes WHERE vaga_id=v.id) as inscritos FROM vagas v ORDER BY created_at DESC LIMIT 5")->fetchAll(); 
                foreach($vs as $v): ?><tr><td><span class="badge bg-success">ABERTA</span></td><td><?=$v['titulo']?></td><td><?=$v['inscritos']?></td><td><a href="?pag=candidatos&filtro_vaga=<?=$v['id']?>" class="btn btn-sm btn-outline-primary">Ver</a></td></tr><?php endforeach; ?>
                </tbody></table>
            </div>
        <?php endif; ?>

        <!-- AGENDA -->
        <?php if($pagina == 'agenda'): 
            $evs = []; 
            $ent = $pdo->query("SELECT a.data_entrevista, c.nome FROM aplicacoes a JOIN candidatos c ON a.candidato_id=c.id WHERE a.status='entrevista' AND a.data_entrevista IS NOT NULL")->fetchAll();
            foreach($ent as $e) $evs[] = ['title'=>'Entrevista: '.$e['nome'], 'start'=>$e['data_entrevista'], 'color'=>'#0d6efd'];
            $evs_json = json_encode($evs);
        ?>
            <h3>Agenda Corporativa</h3><div id='calendar'></div>
            <script>document.addEventListener('DOMContentLoaded', function() { var calendar = new FullCalendar.Calendar(document.getElementById('calendar'), { initialView: 'dayGridMonth', locale: 'pt-br', events: <?=$evs_json?> }); calendar.render(); });</script>
        <?php endif; ?>

        <!-- NOVA VAGA -->
        <?php if($pagina == 'vagas'): 
            $skills_db = $pdo->query("SELECT * FROM conhecimentos ORDER BY nome")->fetchAll();
            $testes_db = $pdo->query("SELECT * FROM banco_testes WHERE status='ativo'")->fetchAll();
            $fases_db = $pdo->query("SELECT * FROM fases_processo ORDER BY ordem ASC")->fetchAll();
        ?>
            <h3>Cadastrar Nova Vaga</h3>
            <form method="POST" enctype="multipart/form-data" class="card-custom">
                <input type="hidden" name="acao_vaga" value="criar">
                <div class="row g-3">
                    <div class="col-md-6"><label>Título</label><input type="text" name="titulo" class="form-control" required></div>
                    <div class="col-md-3"><label>Tipo</label><select name="tipo_vaga" class="form-select"><option value="externa">Externa</option><option value="interna">Interna</option></select></div>
                    <div class="col-md-3"><label>Setor</label><select name="setor" class="form-select"><option value="">Selecione...</option>
                        <optgroup label="Industrial"><option>Produção</option><option>Manutenção</option><option>Qualidade</option><option>Logística</option><option>Operação de Máquinas</option><option>Têxtil</option></optgroup>
                        <optgroup label="Escritório"><option>Adm</option><option>Financeiro</option><option>RH</option><option>TI</option><option>Comercial</option></optgroup>
                    </select></div>
                    <div class="col-md-3"><label>Salário Site</label><input type="text" name="faixa_salarial" class="form-control"></div>
                    <div class="col-md-3"><label>Salário Real</label><input type="number" step="0.01" name="salario_real" class="form-control"></div>
                    <div class="col-md-3 pt-4"><label><input type="checkbox" name="ocultar_salario" value="1"> Ocultar Salário</label></div>
                    <div class="col-md-3"><label>Regime</label><select name="regime" class="form-select"><option>CLT</option><option>PJ</option><option>Estágio</option><option>Temporário</option></select></div>
                    <div class="col-md-6"><label>Início</label><input type="date" name="inicio" class="form-control" required></div>
                    <div class="col-md-6"><label>Fim</label><input type="date" name="fim" class="form-control" required></div>
                    <div class="col-md-12"><label>Fases</label><div class="d-flex flex-wrap gap-2 border p-2 rounded"><?php foreach($fases_db as $f): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="fases_selecionadas[]" value="<?=$f['id']?>" checked><label class="badge bg-<?=$f['cor']?>"><?=$f['nome']?></label></div><?php endforeach; ?></div></div>
                    <div class="col-12 mt-3"><h6>Requisitos Automáticos</h6>
                        <label class="me-3"><input type="checkbox" name="req_ensino_fundamental" value="1"> Fundamental</label>
                        <label class="me-3"><input type="checkbox" name="req_ensino_medio" value="1"> Médio</label>
                        <label class="me-3"><input type="checkbox" name="req_office" value="1"> Office</label>
                        <label class="text-primary"><input type="checkbox" name="exclusivo_pcd" value="1"> PCD</label>
                    </div>
                    <div class="col-12 mt-2"><h6>Conhecimentos Específicos</h6><div id="container_skills" class="row g-2"><?php foreach($skills_db as $s): ?><div class="col-md-3 border p-2 rounded bg-light d-flex justify-content-between"><span><?=$s['nome']?></span><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="obrigatorio[<?=$s['id']?>]"></div></div><?php endforeach; ?></div><button type="button" class="btn btn-sm btn-dark mt-2" onclick="addSkill()">+ Adicionar Outro</button></div>
                    <div class="col-12 mt-3"><label>Vincular Teste</label><select name="banco_teste_id" class="form-select"><option value="">Nenhum</option><?php foreach($testes_db as $t): ?><option value="<?=$t['id']?>"><?=$t['titulo']?></option><?php endforeach; ?></select></div>
                    <div class="col-12 text-end"><button type="submit" class="btn btn-success btn-lg">Salvar Vaga</button></div>
                </div>
            </form>
        <?php endif; ?>

        <!-- LISTA VAGAS -->
        <?php if($pagina == 'lista_vagas'): $lista=$pdo->query("SELECT * FROM vagas ORDER BY id DESC")->fetchAll(); ?>
            <h3>Minhas Vagas</h3><div class="card-custom p-0"><table class="table table-hover mb-0"><thead class="table-dark"><tr><th>Vaga</th><th>Início</th><th>Fim</th><th>Status</th><th>Ações</th></tr></thead><tbody>
            <?php foreach($lista as $v): ?><tr><td><strong><?=$v['titulo']?></strong></td><td><?=date('d/m/y',strtotime($v['data_inicio']))?></td><td><?=date('d/m/y',strtotime($v['data_fim']))?></td><td><?=$v['status']?></td><td><a href="?pag=lista_vagas&acao=excluir&id=<?=$v['id']?>" class="btn btn-sm btn-danger">X</a></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>

        <!-- CANDIDATOS -->
        <?php if($pagina == 'candidatos'): 
            $cands = $pdo->query("SELECT c.*, v.titulo as vaga, a.status, a.id as app_id FROM aplicacoes a JOIN candidatos c ON a.candidato_id=c.id JOIN vagas v ON a.vaga_id=v.id ORDER BY a.data_aplicacao DESC")->fetchAll();
            $fases_all = $pdo->query("SELECT * FROM fases_processo ORDER BY ordem ASC")->fetchAll();
        ?>
            <h3>Candidatos</h3>
            <div class="mb-2"><button class="btn btn-sm btn-outline-dark" onclick="acaoMassa()">⚡ Ação em Massa</button></div>
            <div class="card-custom p-0"><table class="table table-hover mb-0"><thead><tr><th><input type="checkbox" id="checkAll"></th><th>Nome</th><th>Vaga</th><th>Status</th><th>Ação</th></tr></thead><tbody>
            <?php foreach($cands as $c): ?><tr><td><input type="checkbox" class="chk-cand" value="<?=$c['app_id']?>" data-nome="<?=$c['nome']?>" data-email="<?=$c['email']?>"></td><td><?=$c['nome']?></td><td><?=$c['vaga']?></td><td><span class="badge bg-secondary"><?=$c['status']?></span></td><td><button class="btn btn-sm btn-outline-dark" onclick="mudarFaseIndividual('<?=$c['app_id']?>','<?=$c['nome']?>','<?=$c['email']?>','<?=$c['status']?>')">Fase</button> <button class="btn btn-sm btn-primary" onclick="verDetalhes(<?=$c['id']?>, <?=$c['app_id']?>)">Detalhes</button></td></tr><?php endforeach; ?>
            </tbody></table></div>

            <!-- MODAL MUDAR FASE -->
            <div class="modal fade" id="modalFase"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5>Mudar Fase</h5></div><div class="modal-body"><form method="POST" id="formFase">
                <input type="hidden" name="mudar_status_completo" value="1"><input type="hidden" name="app_id" id="f_id"><input type="hidden" name="status_anterior" id="f_ant"><input type="hidden" name="email_cand" id="f_mail"><input type="hidden" name="nome_cand" id="f_nome">
                <div class="row g-3"><div class="col-12"><label>Nova Fase</label><select name="novo_status" id="f_status" class="form-select" onchange="toggleAgenda()"><?php foreach($fases_all as $f): ?><option value="<?=$f['nome']?>"><?=$f['nome']?></option><?php endforeach; ?></select></div>
                <div class="col-12 d-none p-3 bg-light border" id="box_agenda"><div class="row g-2"><div class="col-4"><input type="datetime-local" name="data_entrevista" id="f_data" class="form-control" onchange="updMsg()"></div><div class="col-4"><select name="tipo_entrevista" id="f_tipo" class="form-select" onchange="updMsg()"><option>Online</option><option>Presencial</option></select></div><div class="col-4"><input type="text" name="link_reuniao" id="f_link" class="form-control" placeholder="Local/Link" onkeyup="updMsg()"></div></div></div>
                <div class="col-12"><label>Obs (Obrigatória)</label><textarea name="observacao_fase" class="form-control" required></textarea></div>
                <div class="col-12"><label>Mensagem Candidato</label><textarea name="mensagem_email" id="f_msg" class="form-control" rows="4"></textarea></div>
                <div class="col-12 border-top pt-2"><label><input type="checkbox" name="notificar_candidato" value="1" checked> Notificar Email</label><label class="ms-3"><input type="checkbox" name="enviar_whats" value="1"> Popup WhatsApp</label></div>
                <div class="col-12 bg-danger bg-opacity-10 p-2"><label>Senha do Recrutador</label><input type="password" id="pass_rh" class="form-control"></div>
                <button type="button" class="btn btn-primary w-100 mt-2" onclick="salvarFase()">SALVAR</button></div></form></div></div></div></div>

            <!-- MODAL DETALHES -->
            <div class="modal fade" id="modalDetalhes"><div class="modal-dialog modal-xl"><div class="modal-content"><div class="modal-header"><h5>Prontuário</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light">
                <ul class="nav nav-tabs"><li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#td">Dados</button></li><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#th">Timeline</button></li></ul>
                <div class="tab-content p-3 bg-white border-top-0"><div class="tab-pane fade show active" id="td"><div id="dadosCand"></div></div><div class="tab-pane fade" id="th"><div id="timelineCand"></div></div></div>
            </div></div></div></div>

            <!-- POPUP ZAP -->
            <?php if(isset($_SESSION['whatsapp_queue'])): $q=$_SESSION['whatsapp_queue']; unset($_SESSION['whatsapp_queue']); ?>
            <div class="modal fade" id="modalZap"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-success text-white"><h5>Disparar WhatsApp</h5></div><div class="modal-body"><div class="list-group"><?php foreach($q as $i): ?><a href="https://wa.me/55<?=$i['telefone']?>?text=<?=urlencode($i['mensagem'])?>" target="_blank" class="list-group-item list-group-item-action d-flex justify-content-between"><span><?=$i['nome']?></span> <i class="fab fa-whatsapp"></i></a><?php endforeach; ?></div></div></div></div></div><script>new bootstrap.Modal('#modalZap').show();</script>
            <?php endif; ?>

            <script>
                function mudarFaseIndividual(id, nome, email, st){ $('#f_id').val(id); $('#f_nome').val(nome); $('#f_mail').val(email); $('#f_ant').val(st); $('#f_status').val(''); new bootstrap.Modal('#modalFase').show(); }
                function acaoMassa(){ let ids=[]; $('.chk-cand:checked').each(function(){ ids.push($(this).val()); }); if(ids.length==0) return alert("Selecione"); $('#f_id').val(ids.join(',')); new bootstrap.Modal('#modalFase').show(); }
                function toggleAgenda(){ if($('#f_status').val().toLowerCase().includes('entrevista')) $('#box_agenda').removeClass('d-none'); else $('#box_agenda').addClass('d-none'); }
                function salvarFase(){ $.post('admin.php', {ajax_check_pass:1, password:$('#pass_rh').val()}, function(r){ if(r.status=='ok') $('#formFase').submit(); else alert('Senha incorreta!'); }, 'json'); }
                function verDetalhes(cid, aid){ $.post('admin.php', {ajax_get_details:1, cand_id:cid, app_id:aid}, function(d){ $('#dadosCand').html(`<h4>${d.cand.nome}</h4><p>${d.cand.email}</p>`); $('#timelineCand').html(d.historico.map(h=>`<div class='timeline-item'><b>${h.fase_nova}</b> (${h.data_movimentacao})<br>${h.observacao}</div>`).join('')); new bootstrap.Modal('#modalDetalhes').show(); }, 'json'); }
                $('#checkAll').click(function(){ $('.chk-cand').prop('checked', this.checked); });
            </script>
        <?php endif; ?>

        <!-- CONFIG FASES, TEMPLATES (Cole aqui se desejar as abas completas de antes) -->
        <?php if($pagina == 'templates' || $pagina == 'config_fases'): include 'rest.php'; endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>