<?php
session_start();
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
$whatsapp_queue = []; // Fila para o modal

// =================================================================================
// 0. API AJAX (Adicionar Skill Dinamicamente)
// =================================================================================
if (isset($_POST['ajax_add_skill'])) {
    header('Content-Type: application/json');
    $nome = trim($_POST['ajax_add_skill']);
    if(empty($nome)) { echo json_encode(['erro' => 'Vazio']); exit; }
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

// 0.1 API AJAX DETALHES CANDIDATO
if (isset($_POST['ajax_get_details'])) {
    header('Content-Type: application/json');
    $cid = $_POST['cand_id']; $aid = $_POST['app_id'];
    $c = $pdo->query("SELECT * FROM candidatos WHERE id = $cid")->fetch(PDO::FETCH_ASSOC);
    $logs = $pdo->query("SELECT * FROM logs_testes WHERE aplicacao_id = $aid ORDER BY data_hora DESC")->fetchAll(PDO::FETCH_ASSOC);
    $historico = $pdo->query("SELECT * FROM historico_fases WHERE aplicacao_id = $aid ORDER BY data_movimentacao DESC")->fetchAll(PDO::FETCH_ASSOC);
    $formacoes = $pdo->query("SELECT * FROM formacoes_academicas WHERE candidato_id = $cid")->fetchAll(PDO::FETCH_ASSOC);
    $experiencias = $pdo->query("SELECT * FROM experiencias WHERE candidato_id = $cid ORDER BY inicio DESC")->fetchAll(PDO::FETCH_ASSOC);
    $c['formacoes_academicas'] = $formacoes; $c['experiencias'] = $experiencias;
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

// =================================================================================
// 2. GESTÃO DE FASES (Lógica Restaurada)
// =================================================================================
if (isset($_POST['salvar_fase_sistema'])) {
    $pdo->prepare("INSERT INTO fases_processo (nome, cor, ordem) VALUES (?,?,?)")->execute([$_POST['nome_fase'], $_POST['cor_fase'], $_POST['ordem_fase']]);
    $msg = "Fase criada.";
}
if (isset($_GET['del_fase'])) {
    $pdo->prepare("DELETE FROM fases_processo WHERE id = ?")->execute([$_GET['del_fase']]);
    $msg = "Fase removida.";
}

// =================================================================================
// 3. GESTÃO CANDIDATOS (EM MASSA)
// =================================================================================
if (isset($_POST['mudar_status_completo'])) {
    try {
        $ids_raw = $_POST['app_id']; // IDs separados por virgula
        $ids_array = explode(',', $ids_raw);
        $novo_status = $_POST['novo_status'];
        $msg_base = $_POST['mensagem_email']; 
        $rec_db = $_POST['nome_recrutador'] ?? 'RH';
        
        $data_db = null; $local_db = null; $link_db = null; $links_agenda = "";

        // Dados de agendamento (comuns a todos do lote)
        if (strpos(strtolower($novo_status), 'entrevista') !== false && !empty($_POST['data_entrevista'])) {
            $data_db = $_POST['data_entrevista'];
            $tipo = $_POST['tipo_entrevista'];
            if ($tipo == 'Presencial') {
                $local_db = ($_POST['end_rua'] ?? '') . ", " . ($_POST['end_num'] ?? '');
            } else {
                $link_db = $_POST['link_reuniao'] ?? '';
                $local_db = "Online via " . ($_POST['plataforma_online'] ?? 'Web');
            }
        }

        $mailer = new EmailHelper();
        $assunto = $_POST['assunto_email'] ?? ("Atualização - " . strtoupper($novo_status));

        // LOOP PARA PROCESSAR CADA CANDIDATO
        foreach ($ids_array as $app_id) {
            if(empty($app_id)) continue;

            $dado_cand = $pdo->query("SELECT c.nome, c.email, c.telefone FROM aplicacoes a JOIN candidatos c ON a.candidato_id=c.id WHERE a.id = $app_id")->fetch();
            $nome_atual = $dado_cand['nome'];

            // Salva Banco
            $sql = "UPDATE aplicacoes SET status = ?, data_entrevista = ?, local_entrevista = ?, link_reuniao = ?, recrutador_entrevista = ? WHERE id = ?";
            $pdo->prepare($sql)->execute([$novo_status, $data_db, $local_db, $link_db, $rec_db, $app_id]);
            
            // Grava Histórico
            $obs = "Alteração em massa para $novo_status";
            $pdo->prepare("INSERT INTO historico_fases (aplicacao_id, fase_anterior, fase_nova, observacao, recrutador) VALUES (?,?,?,?,?)")->execute([$app_id, 'massa', $novo_status, $obs, $_SESSION['nome']]);

            // Envia Email
            if (isset($_POST['notificar_candidato'])) {
                if ($novo_status == 'entrevista') {
                    $links_cal = CalendarHelper::getLinks("Entrevista - $nome_atual", "Com $rec_db. $local_db", $data_db, 60, $local_db);
                    $links_agenda = "<br><hr><p><strong>Agenda:</strong> <a href='{$links_cal['google']}'>Google</a> | <a href='{$links_cal['outlook']}'>Outlook</a> | <a href='{$links_cal['ics']}'>ICS</a></p>";
                }
                $corpo_final = str_replace('{NOME}', $nome_atual, $msg_base);
                $mailer->enviar($dado_cand['email'], $nome_atual, $assunto, nl2br($corpo_final) . $links_agenda);
            }

            // Fila do WhatsApp
            if (isset($_POST['enviar_whats'])) {
                $msg_zap_final = str_replace('{NOME}', $nome_atual, $_POST['mensagem_whats']);
                $whatsapp_queue[] = ['nome' => $nome_atual, 'telefone' => $dado_cand['telefone'], 'mensagem' => $msg_zap_final];
            }
        }

        if (!empty($whatsapp_queue)) { $_SESSION['whatsapp_queue'] = $whatsapp_queue; }
        $msg = "Ação realizada em " . count($ids_array) . " candidato(s).";

    } catch (Exception $e) { $erro = $e->getMessage(); }
}
if (isset($_POST['reativar_teste'])) {
    $pdo->prepare("UPDATE aplicacoes SET data_inicio_teste = NULL, data_fim_teste = NULL, resultado_teste = NULL, status = 'teste_pratico' WHERE id = ?")->execute([$_POST['app_id']]);
    $pdo->prepare("INSERT INTO logs_testes (aplicacao_id, acao, detalhes) VALUES (?, 'Reativado', 'Reiniciado por RH')")->execute([$_POST['app_id']]);
    $msg = "Teste reiniciado.";
}
if (isset($_POST['salvar_template'])) {
    // Lógica simples de template
    $f = $_POST['fase']; $a = $_POST['assunto']; $m = $_POST['mensagem'];
    $chk = $pdo->query("SELECT fase FROM mensagens_padrao WHERE fase = '$f'")->fetch();
    if($chk) $pdo->prepare("UPDATE mensagens_padrao SET assunto=?, mensagem=? WHERE fase=?")->execute([$a,$m,$f]);
    else $pdo->prepare("INSERT INTO mensagens_padrao (fase,assunto,mensagem) VALUES (?,?,?)")->execute([$f,$a,$m]);
    $msg="Template salvo.";
}

// DADOS GERAIS
$total_vagas = $pdo->query("SELECT COUNT(*) FROM vagas WHERE status='aberta'")->fetchColumn();
$total_cands = $pdo->query("SELECT COUNT(*) FROM candidatos")->fetchColumn();
$total_proc  = $pdo->query("SELECT COUNT(*) FROM aplicacoes WHERE status='entrevista'")->fetchColumn();

// AGENDA
$eventos_json = "[]";
if($pagina == 'agenda') {
    $entrevistas = $pdo->query("SELECT a.data_entrevista, c.nome, v.titulo, a.id FROM aplicacoes a JOIN candidatos c ON a.candidato_id=c.id JOIN vagas v ON a.vaga_id=v.id WHERE a.status LIKE '%entrevista%' AND a.data_entrevista IS NOT NULL")->fetchAll();
    $evs = [];
    foreach($entrevistas as $e) { $evs[] = ['title' => explode(' ',$e['nome'])[0], 'start' => $e['data_entrevista'], 'color' => '#0d6efd', 'url' => "?pag=candidatos&filtro_vaga=" . $e['id']]; }
    $vagas_fim = $pdo->query("SELECT titulo, data_fim FROM vagas WHERE status='aberta'")->fetchAll();
    foreach($vagas_fim as $v) { $evs[] = ['title' => "Fim: " . $v['titulo'], 'start' => $v['data_fim'], 'color' => '#dc3545', 'allDay' => true]; }
    $eventos_json = json_encode($evs);
}
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
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>
    <style>
        body { background: #f4f6f9; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }
        .sidebar { min-height: 100vh; background: #1a1a1a; color: #fff; width: 260px; position: fixed; z-index: 1000; }
        .sidebar .brand { padding: 25px; text-align: center; border-bottom: 1px solid #333; background: #000; }
        .sidebar a { color: #aaa; text-decoration: none; padding: 15px 20px; display: block; border-bottom: 1px solid #222; font-size: 0.95rem; }
        .sidebar a:hover, .sidebar a.active { background: #222; color: #fff; border-left: 4px solid #0d6efd; padding-left: 25px; }
        .main-content { margin-left: 260px; padding: 30px; }
        @media (max-width: 768px) { .sidebar { width: 100%; position: relative; min-height: auto; } .main-content { margin-left: 0; padding: 15px; } }
        .card-custom { border: none; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); background: white; margin-bottom: 25px; padding: 25px; }
        #calendar { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; }
        .skill-box { transition: 0.2s; border: 1px solid #ddd; }
        .skill-box.mandatory { border: 2px solid #dc3545; background-color: #fff8f8; }
        .skill-box.desirable { border: 2px solid #0d6efd; background-color: #f8fbff; }
    </style>
</head>
<body>

    <nav class="sidebar">
        <div class="brand"><img src="logo-branco.png" width="130"></div>
        <a href="?pag=dashboard" class="<?=$pagina=='dashboard'?'active':''?>"><i class="fas fa-chart-line me-2"></i> Dashboard</a>
        <a href="?pag=agenda" class="<?=$pagina=='agenda'?'active':''?>"><i class="fas fa-calendar-alt me-2 text-warning"></i> Agenda</a>
        <a href="?pag=vagas" class="<?=$pagina=='vagas'?'active':''?>"><i class="fas fa-briefcase me-2"></i> Nova Vaga</a>
        <a href="?pag=config_fases" class="<?=$pagina=='config_fases'?'active':''?>"><i class="fas fa-tasks me-2"></i> Config. Fases</a>
        <a href="?pag=lista_vagas" class="<?=$pagina=='lista_vagas'?'active':''?>"><i class="fas fa-list-ul me-2"></i> Minhas Vagas</a>
        <a href="banco_testes.php" target="_blank"><i class="fas fa-file-alt me-2"></i> Banco de Testes</a>
        <a href="?pag=templates" class="<?=$pagina=='templates'?'active':''?>"><i class="fas fa-envelope-open-text me-2"></i> Templates</a>
        <a href="?pag=candidatos" class="<?=$pagina=='candidatos'?'active':''?>"><i class="fas fa-users me-2"></i> Candidatos</a>
        <a href="logout.php" class="text-danger mt-4"><i class="fas fa-sign-out-alt me-2"></i> Sair</a>
    </nav>

    <div class="main-content">
        <?php if($msg): ?><div class="alert alert-success shadow-sm rounded-3"><?=$msg?></div><?php endif; ?>
        <?php if($erro): ?><div class="alert alert-danger shadow-sm rounded-3"><?=$erro?></div><?php endif; ?>

        <!-- DASHBOARD -->
        <?php if($pagina == 'dashboard'): ?>
            <h3 class="mb-4 text-dark fw-bold">Dashboard</h3>
            <div class="row g-4 mt-2">
                <div class="col-md-4"><div class="card-custom border-start border-5 border-primary"><h2><?=$total_vagas?></h2> Vagas</div></div>
                <div class="col-md-4"><div class="card-custom border-start border-5 border-success"><h2><?=$total_cands?></h2> Candidatos</div></div>
                <div class="col-md-4"><div class="card-custom border-start border-5 border-warning"><h2><?=$total_proc?></h2> Entrevistas</div></div>
            </div>
            <div class="card-custom mt-4 p-0"><div class="p-3 border-bottom"><strong>Vagas Recentes</strong></div><table class="table table-hover mb-0"><thead class="table-light"><tr><th>Vaga</th><th>Inscritos</th><th>Ação</th></tr></thead><tbody>
            <?php $vagas_dash = $pdo->query("SELECT v.*, (SELECT COUNT(*) FROM aplicacoes WHERE vaga_id = v.id) as inscritos FROM vagas v ORDER BY v.created_at DESC LIMIT 5")->fetchAll(); foreach($vagas_dash as $v): ?>
            <tr><td><?=$v['titulo']?></td><td><span class="badge bg-primary rounded-pill"><?=$v['inscritos']?></span></td><td><a href="?pag=candidatos&filtro_vaga=<?=$v['id']?>" class="btn btn-outline-primary btn-sm">Ver</a></td></tr>
            <?php endforeach; ?></tbody></table></div>
        <?php endif; ?>

        <!-- AGENDA -->
        <?php if($pagina=='agenda'): ?>
            <h3>Agenda</h3><div id='calendar'></div>
            <script>document.addEventListener('DOMContentLoaded', function() { var calendar = new FullCalendar.Calendar(document.getElementById('calendar'), { initialView: 'dayGridMonth', locale: 'pt-br', events: <?=$eventos_json?> }); calendar.render(); });</script>
        <?php endif; ?>

        <!-- CONFIG FASES -->
        <?php if($pagina == 'config_fases'): $fases = $pdo->query("SELECT * FROM fases_processo ORDER BY ordem ASC")->fetchAll(); ?>
            <h3>Configuração de Fases</h3>
            <div class="row"><div class="col-md-5"><div class="card-custom"><h5>Nova Fase</h5><form method="POST"><input type="hidden" name="salvar_fase_sistema" value="1"><div class="mb-3"><label>Nome</label><input type="text" name="nome_fase" class="form-control" required></div><div class="mb-3"><label>Cor</label><select name="cor_fase" class="form-select"><option value="secondary">Cinza</option><option value="primary">Azul</option><option value="success">Verde</option><option value="warning">Amarelo</option></select></div><div class="mb-3"><label>Ordem</label><input type="number" name="ordem_fase" class="form-control" value="10"></div><button class="btn btn-dark w-100">Adicionar</button></form></div></div>
            <div class="col-md-7"><div class="card-custom p-0"><table class="table mb-0"><thead><tr><th>Ordem</th><th>Fase</th><th>Ação</th></tr></thead><tbody><?php foreach($fases as $f): ?><tr><td><?=$f['ordem']?></td><td><span class="badge bg-<?=$f['cor']?>"><?=$f['nome']?></span></td><td><a href="?pag=config_fases&del_fase=<?=$f['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('Excluir?')">X</a></td></tr><?php endforeach; ?></tbody></table></div></div></div>
        <?php endif; ?>

        <!-- NOVA VAGA (COMPLETA) -->
        <?php if($pagina == 'vagas'): 
            $skills_db = $pdo->query("SELECT * FROM conhecimentos ORDER BY nome")->fetchAll();
            $testes_db = $pdo->query("SELECT * FROM banco_testes WHERE status='ativo' ORDER BY titulo")->fetchAll();
            $fases_db = $pdo->query("SELECT * FROM fases_processo ORDER BY ordem ASC")->fetchAll();
        ?>
            <h3>Nova Vaga</h3>
            <form method="POST" enctype="multipart/form-data" class="card-custom">
                <input type="hidden" name="acao_vaga" value="criar">
                <div class="row g-3">
                    <div class="col-md-6"><label class="fw-bold">Título</label><input type="text" name="titulo" class="form-control" required></div>
                    <div class="col-md-3"><label>Tipo</label><select name="tipo_vaga" class="form-select"><option value="externa">Externa</option><option value="interna">Interna</option><option value="mista">Mista</option></select></div>
                    <div class="col-md-3"><label>Setor</label><select name="setor" class="form-select"><option value="">Selecione...</option><optgroup label="Industrial"><option>Produção</option><option>Manutenção</option><option>Qualidade</option><option>Logística</option></optgroup><optgroup label="Escritório"><option>Adm</option><option>Comercial</option><option>TI</option><option>RH</option></optgroup></select></div>
                    <div class="col-md-3"><label>Salário Site</label><input type="text" name="faixa_salarial" class="form-control"></div>
                    <div class="col-md-3"><label>Salário Real</label><input type="number" step="0.01" name="salario_real" class="form-control"></div>
                    <div class="col-md-3 pt-4"><label><input type="checkbox" name="ocultar_salario" value="1"> Ocultar</label></div>
                    <div class="col-md-3"><label>Regime</label><select name="regime" class="form-select"><option>CLT</option><option>Temporário</option><option>PJ</option><option>Estágio</option><option>Jovem Aprendiz</option></select></div>
                    <div class="col-md-6"><label>Local</label><input type="text" name="localizacao" class="form-control" value="Sete Lagoas - MG"></div>
                    <div class="col-md-2"><label>Qtd</label><input type="number" name="qtd" class="form-control" value="1"></div>
                    <div class="col-md-2"><label>Início</label><input type="date" name="inicio" class="form-control" required></div>
                    <div class="col-md-2"><label>Fim</label><input type="date" name="fim" class="form-control" required></div>
                    <div class="col-12"><div class="alert alert-light border p-2 small"><strong>Filtro Idade:</strong> <input type="number" name="idade_min" class="d-inline-block w-auto form-control" style="height:25px" value="18"> a <input type="number" name="idade_max" class="d-inline-block w-auto form-control" style="height:25px" value="65"> | Altura Mín <input type="number" step="0.01" name="altura_minima" class="d-inline-block w-auto form-control"></div></div>
                    <div class="col-md-6"><label>Descrição</label><textarea name="descricao" class="form-control" rows="4"></textarea></div>
                    <div class="col-md-6"><label>Requisitos</label><textarea name="requisitos" class="form-control" rows="4"></textarea></div>
                    <div class="col-md-12"><label class="fw-bold">Fases</label><div class="d-flex flex-wrap gap-3 border p-3 rounded bg-white"><?php foreach($fases_db as $f): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="fases_selecionadas[]" value="<?=$f['id']?>" checked><label class="form-check-label badge bg-<?=$f['cor']?>"><?=$f['nome']?></label></div><?php endforeach; ?></div></div>
                </div>
                <hr>
                <div class="d-flex justify-content-between mb-2"><h5 class="text-primary mb-0">Requisitos Automáticos</h5><span class="badge bg-secondary">Azul=Desejável / Vermelho=Obrig.</span></div>
                <div class="row g-3 bg-light p-3 rounded mb-3 border">
                    <div class="col-md-3"><label><input type="checkbox" name="req_ensino_fundamental" value="1"> Fundamental</label></div>
                    <div class="col-md-3"><label><input type="checkbox" name="req_ensino_medio" value="1"> Ensino Médio</label></div>
                    <div class="col-md-3"><label><input type="checkbox" name="req_office" value="1"> Pacote Office</label></div>
                    <div class="col-md-3"><label><input type="checkbox" name="req_powerbi" value="1"> Power BI</label></div>
                    <div class="col-md-3"><label class="text-primary fw-bold"><input type="checkbox" name="exclusivo_pcd" value="1"> PCD</label></div>
                    <div class="col-6"><label>Inglês</label><select name="req_ingles" class="form-select form-select-sm"><option value="">-</option><option>Básico</option><option>Avançado</option></select></div>
                    <div class="col-6"><label>Espanhol</label><select name="req_espanhol" class="form-select form-select-sm"><option value="">-</option><option>Básico</option><option>Avançado</option></select></div>
                </div>
                <h6 class="text-muted mt-4">Conhecimentos Específicos</h6>
                <div class="row g-2 mb-3" id="container_skills"><?php foreach($skills_db as $sk): ?><div class="col-md-3 border rounded p-2 bg-white d-flex justify-content-between" id="box_<?=$sk['id']?>"><div><input type="checkbox" name="skills[]" value="<?=$sk['id']?>"> <?=$sk['nome']?></div><div class="form-check form-switch"><input class="form-check-input chk-obrig" type="checkbox" name="obrigatorio[<?=$sk['id']?>]" onchange="toggleColor(<?=$sk['id']?>)"></div></div><?php endforeach; ?></div>
                <div class="row"><div class="col-md-6"><div class="input-group"><input type="text" id="nova_skill_input" class="form-control" placeholder="Novo..."><button type="button" class="btn btn-dark" onclick="adicionarSkillRapido()">Add</button></div></div></div>
                <hr>
                <div class="row"><div class="col-md-6"><label>Vincular Teste</label><select name="banco_teste_id" class="form-select"><option value="">Nenhum</option><?php foreach($testes_db as $t): ?><option value="<?=$t['id']?>"><?=$t['titulo']?></option><?php endforeach; ?></select></div><div class="col-md-6"><label>Arquivo</label><input type="file" name="arquivo_teste" class="form-control"></div><div class="col-12 mt-2"><label>Instruções</label><textarea name="teste_pratico" class="form-control" rows="2"></textarea></div></div>
                <div class="text-end mt-4"><button type="submit" class="btn btn-success btn-lg shadow">Salvar Vaga</button></div>
            </form>
            <script>
                function adicionarSkillRapido() { let n=$('#nova_skill_input').val(); if(n) $.post('admin.php',{ajax_add_skill:n},function(d){ if(!d.erro) $('#container_skills').append(`<div class="col-md-3 border rounded p-2 bg-white d-flex justify-content-between"><div><input type="checkbox" name="skills[]" value="${d.id}" checked> ${d.nome}</div></div>`); },'json'); }
                function toggleColor(id){ let el=$('#box_'+id); if(el.find('.chk-obrig').is(':checked')) el.removeClass('desirable').addClass('mandatory'); else el.removeClass('mandatory').addClass('desirable'); }
            </script>
        <?php endif; ?>

        <!-- LISTA DE VAGAS -->
        <?php if($pagina == 'lista_vagas'): $lista=$pdo->query("SELECT * FROM vagas ORDER BY id DESC")->fetchAll(); ?>
            <h3 class="mb-4">Minhas Vagas</h3>
            <div class="card-custom p-0"><table class="table table-hover mb-0"><thead class="table-dark"><tr><th>Vaga</th><th>Início</th><th>Fim</th><th>Status</th><th class="text-end">Ações</th></tr></thead><tbody>
            <?php foreach($lista as $v): ?><tr><td><strong><?=$v['titulo']?></strong></td><td><?=date('d/m/Y', strtotime($v['data_inicio']))?></td><td><?=date('d/m/Y', strtotime($v['data_fim']))?></td><td><span class="badge <?=$v['status']=='aberta'?'bg-success':'bg-secondary'?>"><?=strtoupper($v['status'])?></span></td><td class="text-end"><a href="?pag=lista_vagas&acao=excluir&id=<?=$v['id']?>" class="btn btn-sm btn-danger">Excluir</a></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>

        <!-- TEMPLATES -->
        <?php if($pagina == 'templates'): $templates = $pdo->query("SELECT * FROM mensagens_padrao")->fetchAll(); ?>
            <h3>Templates</h3><div class="row"><div class="col-md-5"><div class="card-custom"><h5>Novo / Editar</h5><form method="POST"><input type="hidden" name="salvar_template" value="1"><div class="mb-3"><label>Fase</label><select name="fase" id="tmpl_fase" class="form-select" onchange="carregarTemplate()"><option value="aprovado">Aprovado</option><option value="reprovado">Reprovado</option><option value="standby">Stand-By</option></select></div><div class="mb-3"><label>Assunto</label><input type="text" name="assunto" id="tmpl_assunto" class="form-control"></div><div class="mb-3"><label>Texto</label><textarea name="mensagem" id="tmpl_msg" class="form-control" rows="5"></textarea></div><button class="btn btn-primary w-100">Salvar</button></form></div></div><div class="col-md-7"><div class="card-custom p-0"><ul class="list-group list-group-flush"><?php foreach($templates as $t): ?><li class="list-group-item"><strong><?=strtoupper($t['fase'])?></strong><br><small><?=$t['mensagem']?></small></li><?php endforeach; ?></ul></div></div></div>
            <script>const templates=<?php echo json_encode($templates); ?>; function carregarTemplate(){ let f=$('#tmpl_fase').val(); let t=templates.find(x=>x.fase==f); if(t){ $('#tmpl_assunto').val(t.assunto); $('#tmpl_msg').val(t.mensagem); } else { $('#tmpl_assunto').val(''); $('#tmpl_msg').val(''); } }</script>
        <?php endif; ?>
        
        <!-- CANDIDATOS (COM AÇÃO EM MASSA) -->
        <?php if($pagina == 'candidatos'): 
            $filtro = $_GET['filtro_vaga'] ?? '';
            $sql = "SELECT c.*, v.titulo as vaga, a.status, a.id as app_id FROM aplicacoes a JOIN candidatos c ON a.candidato_id=c.id JOIN vagas v ON a.vaga_id=v.id ";
            if($filtro) $sql .= " WHERE v.id = $filtro ";
            $sql .= " ORDER BY a.data_aplicacao DESC";
            $cands = $pdo->query($sql)->fetchAll();
            $vagas_opt = $pdo->query("SELECT id, titulo FROM vagas")->fetchAll();
            $fases_all = $pdo->query("SELECT * FROM fases_processo ORDER BY ordem ASC")->fetchAll();
            $templates_db = $pdo->query("SELECT * FROM mensagens_padrao")->fetchAll();
        ?>
            <div class="d-flex justify-content-between mb-3"><h3>Gestão Candidatos</h3><select class="form-select w-auto" onchange="window.location.href='?pag=candidatos&filtro_vaga='+this.value"><option value="">Todas Vagas</option><?php foreach($vagas_opt as $vo): ?><option value="<?=$vo['id']?>" <?=$filtro==$vo['id']?'selected':''?>><?=$vo['titulo']?></option><?php endforeach; ?></select></div>
            <div class="mb-2"><button class="btn btn-sm btn-outline-dark" onclick="acaoMassa()">⚡ Ação em Massa</button></div>
            <div class="card-custom p-0"><table class="table table-hover mb-0 align-middle"><thead class="table-dark"><tr><th width="30"><input type="checkbox" id="checkAll"></th><th>Nome</th><th>Vaga</th><th>Status</th><th>Ações</th></tr></thead><tbody>
            <?php foreach($cands as $c): ?>
            <tr><td><input type="checkbox" class="chk-cand" value="<?=$c['app_id']?>" data-nome="<?=$c['nome']?>" data-email="<?=$c['email']?>" data-tel="<?=preg_replace('/\D/','',$c['telefone'])?>" data-vaga="<?=$c['vaga']?>"></td><td><strong><?=$c['nome']?></strong><br><small><?=$c['email']?></small></td><td><?=$c['vaga']?></td><td><span class="badge bg-secondary"><?=strtoupper($c['status'])?></span></td><td><button class="btn btn-sm btn-outline-dark fw-bold btn-fase" onclick="mudarFaseIndividual('<?=$c['app_id']?>','<?=$c['nome']?>','<?=$c['email']?>','<?=$c['vaga']?>','<?=preg_replace('/\D/','',$c['telefone'])?>')">Mudar Fase</button> <button class="btn btn-sm btn-primary" onclick="abrirDetalhes(<?=$c['id']?>, <?=$c['app_id']?>)">Detalhes</button> <a href="uploads/<?=$c['arquivo_curriculo']?>" target="_blank" class="btn btn-sm btn-danger">CV</a></td></tr><?php endforeach; ?></tbody></table></div>

            <!-- MODAL FASE -->
            <div class="modal fade" id="modalFase"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5>Mudar Fase</h5></div><div class="modal-body"><form method="POST" id="formFase"><input type="hidden" name="mudar_status_completo" value="1"><input type="hidden" name="app_id" id="f_app_id"><input type="hidden" name="email_cand" id="f_email"><input type="hidden" name="nome_cand" id="f_nome"><input type="hidden" name="telefone_cand" id="f_tel"><input type="hidden" id="f_vaga"><div class="alert alert-info small d-none" id="msg_massa">Massa</div><div class="row g-3"><div class="col-md-12"><label class="fw-bold">Nova Fase</label><select name="novo_status" id="f_status" class="form-select border-primary" onchange="toggleForm()"><option value="">Selecione...</option><?php foreach($fases_all as $f): ?><option value="<?=$f['nome']?>"><?=$f['nome']?></option><?php endforeach; ?></select></div><div class="col-6 d-none" id="box_recrutador"><label>Recrutador</label><input type="text" name="nome_recrutador" id="f_rec" class="form-control" onkeyup="updMsg()"></div><div class="col-6 d-none" id="box_feedback"><label>Motivo</label><select id="motivo_repo" class="form-select" onchange="updMsg()"><option value="">Selecione...</option><?php foreach($templates_db as $t): ?><option value="<?=$t['fase']?>"><?=$t['assunto']?></option><?php endforeach; ?></select></div><div class="col-12 d-none p-3 bg-light border" id="box_agenda"><div class="row g-2"><div class="col-4"><input type="datetime-local" name="data_entrevista" id="f_data" class="form-control" onchange="updMsg()"></div><div class="col-4"><select name="tipo_entrevista" id="f_tipo" class="form-select" onchange="updMsg()"><option>Online</option><option>Presencial</option></select></div><div class="col-4"><input type="text" name="link_reuniao" id="f_link" class="form-control" placeholder="Link/Endereço" onkeyup="updMsg()"></div></div></div><div class="col-12"><label>Mensagem</label><textarea name="mensagem_email" id="f_msg" class="form-control" rows="4"></textarea></div><textarea name="mensagem_whats" id="f_msg_whats" class="d-none"></textarea><div class="col-12 border-top pt-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="notificar_candidato" value="1" checked><label>Notificar</label></div><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="enviar_whats" value="1"><label>Popup WhatsApp (Individual)</label></div></div><div class="col-12"><button class="btn btn-primary w-100">SALVAR</button></div></div></form></div></div></div></div>

            <!-- MODAL DETALHES -->
            <div class="modal fade" id="modalDetalhes"><div class="modal-dialog modal-xl"><div class="modal-content"><div class="modal-header"><h5>Prontuário</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><ul class="nav nav-tabs mb-3"><li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tD">Dados</button></li><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tH">Timeline</button></li></ul><div class="tab-content"><div class="tab-pane fade show active" id="tD"><h5 id="dNome"></h5><p id="dEmail"></p><div id="dForm"></div></div><div class="tab-pane fade" id="tH"><h6>Histórico</h6><div id="timelineContainer"></div></div></div></div></div></div></div>

            <!-- POPUP FILA WHATSAPP -->
            <?php if(isset($_SESSION['whatsapp_queue'])): $queue=$_SESSION['whatsapp_queue']; unset($_SESSION['whatsapp_queue']); ?>
            <div class="modal fade" id="modalZapQueue"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-success text-white"><h5>Disparar WhatsApp</h5></div><div class="modal-body"><div class="list-group"><?php foreach($queue as $q): ?><a href="https://wa.me/55<?=$q['telefone']?>?text=<?=urlencode($q['mensagem'])?>" target="_blank" class="list-group-item list-group-item-action d-flex justify-content-between"><span><?=$q['nome']?></span> <i class="fab fa-whatsapp text-success"></i></a><?php endforeach; ?></div></div></div></div></div><script>new bootstrap.Modal('#modalZapQueue').show();</script>
            <?php endif; ?>

            <script>
                const templates = <?php echo json_encode($templates_db, JSON_HEX_QUOT|JSON_HEX_TAG); ?>;
                function mudarFaseIndividual(id, nome, email, vaga, tel){ $('#f_app_id').val(id); $('#f_nome').val(nome); $('#f_email').val(email); $('#f_tel').val(tel); $('#f_vaga').val(vaga); $('#f_status').val(''); $('#f_msg').val(''); $('#box_feedback, #box_agenda, #box_recrutador, #msg_massa').addClass('d-none'); new bootstrap.Modal('#modalFase').show(); }
                function acaoMassa(){ let ids=[]; let nomes=[]; $('.chk-cand:checked').each(function(){ ids.push($(this).val()); nomes.push($(this).data('nome')); }); if(ids.length==0) return alert("Selecione alguém"); $('#f_app_id').val(ids.join(',')); $('#msg_massa').removeClass('d-none'); $('#f_nome').val('Candidato'); new bootstrap.Modal('#modalFase').show(); }
                function toggleForm() { let st=$('#f_status').val(); if(st.includes('reprovado')||st.includes('standby')) { $('#box_feedback').removeClass('d-none'); $('#box_agenda').addClass('d-none'); } else if(st.includes('entrevista')) { $('#box_agenda, #box_recrutador').removeClass('d-none'); $('#box_feedback').addClass('d-none'); } else { $('#box_feedback, #box_agenda').addClass('d-none'); } updMsg(); }
                function updMsg(){ let st=$('#f_status').val(); let n=$('#f_nome').val(); let v=$('#f_vaga').val(); let txt=""; if(st.includes('reprovado')) { let mid=$('#motivo_repo').val(); let t=templates.find(x=>x.fase==mid); txt=t?t.mensagem:""; } else if(st.includes('entrevista')) { let dt=$('#f_data').val(); let loc=$('#f_link').val()||'Online'; txt=`Olá ${n},\nEntrevista agendada.\nData: ${dt}\nLocal: ${loc}`; } else { let t=templates.find(x=>x.fase==st); txt=t?t.mensagem:"Olá {NOME}, atualização."; } txt=txt.replace(/{NOME}/g,n).replace(/{VAGA}/g,v); $('#f_msg').val(txt); $('#f_msg_whats').val(txt.replace(/<br>/g,"\n")); }
                function abrirDetalhes(cid, aid) { $.post('admin.php', { ajax_get_details: 1, cand_id: cid, app_id: aid }, function(data) { $('#dNome').text(data.cand.nome); $('#dEmail').text(data.cand.email); let fHtml=data.cand.formacoes_academicas.map(f=>`<div><b>${f.nivel}</b> - ${f.curso}</div>`).join(''); $('#dForm').html(fHtml); let hHtml = data.historico.map(h => `<div class="timeline-item"><strong>${h.fase_nova}</strong> <small>${h.data_movimentacao} (${h.recrutador})</small><br><em>${h.observacao}</em></div>`).join(''); $('#timelineContainer').html(hHtml); new bootstrap.Modal('#modalDetalhes').show(); }, 'json'); }
                $('#checkAll').click(function(){ $('.chk-cand').prop('checked', this.checked); });
            </script>
        <?php endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>function adicionarSkillRapido() { let n=$('#nova_skill_input').val(); if(n) $.post('admin.php',{ajax_add_skill:n},function(d){ if(!d.erro) $('#container_skills').append(`<div class="col-md-3 border rounded p-2 bg-white d-flex justify-content-between"><div><input type="checkbox" name="skills[]" value="${d.id}" checked> ${d.nome}</div></div>`); },'json'); }</script>
</body>
</html>