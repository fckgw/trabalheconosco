<?php
session_start();
// --- CONFIGURAÇÃO DE ERROS ---
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

// =================================================================================
// 1. LÓGICA DE VAGAS
// =================================================================================
if (isset($_POST['acao_vaga'])) {
    try {
        $pdo->beginTransaction();
        $arq = null;
        if (isset($_FILES['arquivo_teste']) && $_FILES['arquivo_teste']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['arquivo_teste']['name'], PATHINFO_EXTENSION));
            $nm = "VT_".uniqid().".".$ext; move_uploaded_file($_FILES['arquivo_teste']['tmp_name'], "uploads/testes_rh/".$nm); $arq = $nm;
        }

        if($_POST['acao_vaga'] == 'criar') {
            $sql = "INSERT INTO vagas (
                titulo, setor, tipo_vaga, descricao, requisitos, teste_pratico, arquivo_teste, banco_teste_id,
                localizacao, faixa_salarial, salario_real, quantidade_vagas, regime_contratacao, 
                idade_min, idade_max, 
                req_ensino_medio, req_office, req_ingles, req_espanhol, req_powerbi, 
                exclusivo_pcd, ocultar_salario, data_inicio, data_fim, status
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

            $pdo->prepare($sql)->execute([
                $_POST['titulo'], $_POST['setor'], $_POST['tipo_vaga'], $_POST['descricao'], $_POST['requisitos'], 
                $_POST['teste_pratico'], $arq, ($_POST['banco_teste_id']?:null),
                $_POST['localizacao'], $_POST['faixa_salarial'], $_POST['salario_real'], $_POST['qtd'], $_POST['regime'],
                $_POST['idade_min'], $_POST['idade_max'], 
                ($_POST['req_ensino_medio']??0), ($_POST['req_office']??0), $_POST['req_ingles'], $_POST['req_espanhol'], ($_POST['req_powerbi']??0),
                ($_POST['exclusivo_pcd']??0), ($_POST['ocultar_salario']??0), $_POST['inicio'], $_POST['fim'], 'aberta'
            ]);
            $vid = $pdo->lastInsertId();
            $skills = $_POST['skills'] ?? []; $obrig = $_POST['obrigatorio'] ?? [];
            $stmtSk = $pdo->prepare("INSERT INTO vagas_conhecimentos (vaga_id, conhecimento_id, obrigatorio) VALUES (?,?,?)");
            foreach ($skills as $sid) { $stmtSk->execute([$vid, $sid, (isset($obrig[$sid])?1:0)]); }
            $pdo->commit();
            $msg = "Vaga criada com sucesso!";
        }
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $erro = $e->getMessage(); }
}

// Ações Vaga (GET)
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
// 2. LÓGICA BANCO DE TESTES E TEMPLATES
// =================================================================================
if (isset($_POST['salvar_teste_db'])) {
    $arq = $_POST['arquivo_atual'] ?? null;
    if (isset($_FILES['arq_teste']) && $_FILES['arq_teste']['error'] == 0) {
        $ext = pathinfo($_FILES['arq_teste']['name'], PATHINFO_EXTENSION);
        $nome = "Repo_" . uniqid() . "." . $ext;
        if(move_uploaded_file($_FILES['arq_teste']['tmp_name'], "uploads/testes_rh/" . $nome)) { $arq = $nome; }
    }

    if (!empty($_POST['teste_id'])) {
        $pdo->prepare("UPDATE banco_testes SET titulo=?, descricao=?, gabarito_geral=?, tempo_limite=?, segmento=?, arquivo_anexo=? WHERE id=?")
            ->execute([$_POST['titulo_teste'], $_POST['desc_teste'], $_POST['gabarito_geral'], $_POST['tempo'], $_POST['segmento'], $arq, $_POST['teste_id']]);
        $msg = "Teste atualizado!";
    } else {
        $pdo->prepare("INSERT INTO banco_testes (titulo, descricao, gabarito_geral, tempo_limite, segmento, arquivo_anexo, criado_por_id, status) VALUES (?,?,?,?,?,?,?,'ativo')")
            ->execute([$_POST['titulo_teste'], $_POST['desc_teste'], $_POST['gabarito_geral'], $_POST['tempo'], $_POST['segmento'], $arq, $_SESSION['usuario_id']]);
        $msg = "Novo teste criado!";
    }
}
if(isset($_POST['add_questao'])) {
    $pdo->prepare("INSERT INTO questoes_teste (banco_teste_id, enunciado, resposta_esperada) VALUES (?,?,?)")
        ->execute([$_POST['teste_id'], $_POST['enunciado'], $_POST['gabarito_questao']]);
    $msg = "Questão adicionada.";
}
if (isset($_GET['acao_teste']) && isset($_GET['id'])) {
    if ($_GET['acao_teste'] == 'excluir') { $pdo->prepare("DELETE FROM banco_testes WHERE id=?")->execute([$_GET['id']]); $msg = "Teste excluído."; }
}
if (isset($_POST['salvar_template'])) {
    $fase = $_POST['fase'];
    $assunto = $_POST['assunto'];
    $mensagem = $_POST['mensagem'];
    $check = $pdo->query("SELECT fase FROM mensagens_padrao WHERE fase = '$fase'")->fetch();
    if ($check) {
        $pdo->prepare("UPDATE mensagens_padrao SET assunto = ?, mensagem = ? WHERE fase = ?")->execute([$assunto, $mensagem, $fase]);
        $msg = "Template atualizado!";
    } else {
        $pdo->prepare("INSERT INTO mensagens_padrao (fase, assunto, mensagem) VALUES (?, ?, ?)")->execute([$fase, $assunto, $mensagem]);
        $msg = "Novo template cadastrado!";
    }
}

// =================================================================================
// 3. GESTÃO CANDIDATOS
// =================================================================================
if (isset($_POST['mudar_status_completo'])) {
    try {
        $novo_status = $_POST['novo_status'];
        $app_id = $_POST['app_id'];
        $email = $_POST['email_cand'];
        $nome = $_POST['nome_cand'];
        $msg_base = $_POST['mensagem_email'];
        
        $data_db = null; $local_db = null; $link_db = null;
        $rec_db = $_POST['nome_recrutador'] ?? 'RH';
        
        $links_agenda = "";
        if ($novo_status == 'entrevista' && !empty($_POST['data_entrevista'])) {
            $data_db = $_POST['data_entrevista'];
            $tipo = $_POST['tipo_entrevista'];
            
            if ($tipo == 'Presencial') {
                $local_db = ($_POST['end_rua'] ?? '') . ", " . ($_POST['end_num'] ?? '');
            } else {
                $link_db = $_POST['link_reuniao'] ?? '';
                $local_db = "Online via " . ($_POST['plataforma_online'] ?? 'Web');
            }
            $links_cal = CalendarHelper::getLinks("Entrevista Cedro - $nome", "Com $rec_db", $data_db, 60, $local_db);
            $links_agenda = "<br><hr><p><strong>Adicione à agenda:</strong> <a href='{$links_cal['google']}' target='_blank'>Google</a> | <a href='{$links_cal['outlook']}' target='_blank'>Outlook</a></p>";
        }

        $sql = "UPDATE aplicacoes SET status = ?, data_entrevista = ?, local_entrevista = ?, link_reuniao = ?, recrutador_entrevista = ? WHERE id = ?";
        $pdo->prepare($sql)->execute([$novo_status, $data_db, $local_db, $link_db, $rec_db, $app_id]);

        if (isset($_POST['notificar_candidato'])) {
            $mailer = new EmailHelper();
            $assunto = $_POST['assunto_email'] ?? "Atualização Processo Seletivo";
            $mailer->enviar($email, $nome, $assunto, nl2br($msg_base) . $links_agenda);
            
            if (isset($_POST['enviar_whats'])) {
                $_SESSION['popup_whatsapp'] = ['telefone' => $_POST['telefone_cand'], 'mensagem' => $_POST['mensagem_whats']];
            }
            $msg .= " (Notificação enviada)";
        }

    } catch (Exception $e) { $erro = $e->getMessage(); }
}

// Reativar Teste
if (isset($_POST['reativar_teste'])) {
    $pdo->prepare("UPDATE aplicacoes SET data_inicio_teste = NULL, data_fim_teste = NULL, resultado_teste = NULL, status = 'teste_pratico' WHERE id = ?")->execute([$_POST['app_id']]);
    $pdo->prepare("INSERT INTO logs_testes (aplicacao_id, acao, detalhes) VALUES (?, 'Reativado', 'Reiniciado por RH')")->execute([$_POST['app_id']]);
    $msg = "Teste reiniciado.";
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
        .sidebar a { color: #aaa; text-decoration: none; padding: 15px 20px; display: block; border-bottom: 1px solid #222; }
        .sidebar a:hover, .sidebar a.active { background: #222; color: #fff; border-left: 4px solid #0d6efd; padding-left: 25px; }
        .main-content { margin-left: 260px; padding: 30px; }
        @media (max-width: 768px) { .sidebar { width: 100%; position: relative; min-height: auto; } .main-content { margin-left: 0; } }
        .card-custom { border: none; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); background: white; margin-bottom: 20px; padding: 20px; }
    </style>
</head>
<body>

    <nav class="sidebar">
        <div class="brand"><img src="logo-branco.png" width="130"></div>
        <a href="?pag=dashboard" class="<?=$pagina=='dashboard'?'active':''?>"><i class="fas fa-chart-line me-2"></i> Dashboard</a>
        <a href="?pag=vagas" class="<?=$pagina=='vagas'?'active':''?>"><i class="fas fa-briefcase me-2"></i> Nova Vaga</a>
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
                <div class="col-md-4"><div class="card-custom border-start border-5 border-primary"><h2><?=$total_vagas?></h2><p class="text-muted mb-0">Vagas Abertas</p></div></div>
                <div class="col-md-4"><div class="card-custom border-start border-5 border-success"><h2><?=$total_cands?></h2><p class="text-muted mb-0">Candidatos</p></div></div>
                <div class="col-md-4"><div class="card-custom border-start border-5 border-warning"><h2><?=$total_proc?></h2><p class="text-muted mb-0">Em Entrevista</p></div></div>
            </div>
            <!-- Grid Vagas Recentes -->
            <div class="card-custom mt-4 p-0">
                <div class="p-3 border-bottom"><strong>Vagas Recentes</strong></div>
                <table class="table table-hover mb-0"><thead class="table-light"><tr><th>Status</th><th>Vaga</th><th>Inscritos</th><th>Ação</th></tr></thead><tbody>
                <?php 
                    // CORREÇÃO: Alias "as inscritos" para evitar erro de undefined index
                    $vagas_dash = $pdo->query("SELECT v.*, (SELECT COUNT(*) FROM aplicacoes WHERE vaga_id = v.id) as inscritos FROM vagas v ORDER BY v.created_at DESC LIMIT 5")->fetchAll();
                    foreach($vagas_dash as $v): 
                ?>
                <tr>
                    <td><span class="badge <?=$v['status']=='aberta'?'bg-success':'bg-secondary'?>"><?=strtoupper($v['status'])?></span></td>
                    <td><strong><?=$v['titulo']?></strong><br><small class="text-muted"><?=$v['setor']?></small></td>
                    <td><span class="badge bg-primary rounded-pill"><?=$v['inscritos']?></span></td>
                    <td><a href="?pag=candidatos&filtro_vaga=<?=$v['id']?>" class="btn btn-outline-primary btn-sm">Ver Inscritos</a></td>
                </tr>
                <?php endforeach; ?></tbody></table>
            </div>
        <?php endif; ?>

        <!-- NOVA VAGA (COMPLETO) -->
        <?php if($pagina == 'vagas'): 
            $skills_db = $pdo->query("SELECT * FROM conhecimentos ORDER BY nome")->fetchAll();
            $testes_db = $pdo->query("SELECT * FROM banco_testes WHERE status='ativo' ORDER BY titulo")->fetchAll();
        ?>
            <h3 class="mb-4">Nova Vaga</h3>
            <form method="POST" enctype="multipart/form-data" class="card-custom">
                <input type="hidden" name="acao_vaga" value="criar">
                <h5 class="text-primary mb-3">Dados da Vaga</h5>
                <div class="row g-3">
                    <div class="col-md-6"><label class="fw-bold">Título</label><input type="text" name="titulo" class="form-control" required></div>
                    <div class="col-md-3">
                        <label>Tipo de Vaga</label>
                        <select name="tipo_vaga" class="form-select">
                            <option value="externa">Externa</option>
                            <option value="interna">Interna</option>
                            <option value="mista">Mista</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label>Setor</label>
                        <select name="setor" class="form-select">
                            <option value="">Selecione...</option>
                            <optgroup label="Industrial"><option>Produção</option><option>Manutenção</option><option>Qualidade</option><option>Logística</option></optgroup>
                            <optgroup label="Escritório"><option>Administrativo</option><option>Comercial</option><option>TI</option><option>RH</option><option>Financeiro</option></optgroup>
                        </select>
                    </div>

                    <div class="col-md-3"><label class="text-success fw-bold">Salário (Site)</label><input type="text" name="faixa_salarial" class="form-control" placeholder="R$ 2.000"></div>
                    <div class="col-md-3"><label class="text-danger fw-bold">Salário (Interno)</label><input type="number" step="0.01" name="salario_real" class="form-control"></div>
                    <div class="col-md-3 mt-4"><label class="text-danger"><input type="checkbox" name="ocultar_salario" value="1"> Ocultar Salário</label></div>
                    
                    <div class="col-md-3">
                        <label>Regime</label>
                        <select name="regime" class="form-select">
                            <option>CLT</option><option>Temporário</option><option>PJ</option><option>Estágio</option>
                            <option>Jovem Aprendiz</option><option>Trainee</option><option>Freelancer</option>
                        </select>
                    </div>

                    <div class="col-md-6"><label>Localização</label><input type="text" name="localizacao" class="form-control" value="Sete Lagoas - MG"></div>
                    <div class="col-md-2"><label>Qtd</label><input type="number" name="qtd" class="form-control" value="1"></div>
                    <div class="col-md-2"><label>Início</label><input type="date" name="inicio" class="form-control" required></div>
                    <div class="col-md-2"><label>Fim</label><input type="date" name="fim" class="form-control" required></div>

                    <div class="col-12"><div class="alert alert-light border p-2 mb-0 small"><i class="fas fa-lock"></i> <strong>Filtro de Idade (Interno):</strong> <input type="number" name="idade_min" class="d-inline-block w-auto form-control" style="height:25px" value="18"> a <input type="number" name="idade_max" class="d-inline-block w-auto form-control" style="height:25px" value="65"> anos.</div></div>

                    <div class="col-md-6"><label>Descrição</label><textarea name="descricao" class="form-control" rows="4"></textarea></div>
                    <div class="col-md-6"><label>Requisitos (Texto)</label><textarea name="requisitos" class="form-control" rows="4"></textarea></div>
                </div>
                <hr>
                <div class="d-flex justify-content-between align-items-center mb-2"><h5 class="text-primary mb-0">Requisitos Automáticos</h5></div>
                <div class="row g-3 bg-light p-3 rounded mb-3 border">
                    <div class="col-md-3"><label><input type="checkbox" name="req_ensino_medio" value="1"> Ensino Médio</label></div>
                    <div class="col-md-3"><label><input type="checkbox" name="req_office" value="1"> Pacote Office</label></div>
                    <div class="col-md-3"><label><input type="checkbox" name="req_powerbi" value="1"> Power BI</label></div>
                    <div class="col-md-3"><label class="text-primary fw-bold"><input type="checkbox" name="exclusivo_pcd" value="1"> Vaga PCD</label></div>
                    <div class="col-md-6"><label>Inglês</label><select name="req_ingles" class="form-select form-select-sm"><option value="">-</option><option>Básico</option><option>Intermediário</option><option>Avançado</option><option>Fluente</option></select></div>
                    <div class="col-md-6"><label>Espanhol</label><select name="req_espanhol" class="form-select form-select-sm"><option value="">-</option><option>Básico</option><option>Intermediário</option><option>Avançado</option><option>Fluente</option></select></div>
                </div>
                <h6 class="text-muted mt-4">Conhecimentos Específicos</h6>
                <div class="row g-2 mb-3" id="container_skills"><?php foreach($skills_db as $sk): ?><div class="col-md-3 border rounded p-2 bg-white d-flex justify-content-between"><div><input type="checkbox" name="skills[]" value="<?=$sk['id']?>"> <?=$sk['nome']?></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="obrigatorio[<?=$sk['id']?>]"></div></div><?php endforeach; ?></div>
                <div class="row"><div class="col-md-6"><div class="input-group"><input type="text" id="nova_skill_input" class="form-control" placeholder="Novo..."><button type="button" class="btn btn-dark" onclick="adicionarSkillRapido()">Add</button></div></div></div>
                <hr>
                <h5 class="text-primary mb-3">Teste Prático</h5>
                <div class="row"><div class="col-md-6"><label>Vincular Teste</label><select name="banco_teste_id" class="form-select"><option value="">Nenhum</option><?php foreach($testes_db as $t): ?><option value="<?=$t['id']?>"><?=$t['titulo']?></option><?php endforeach; ?></select></div><div class="col-md-6"><label>Arquivo (Opcional)</label><input type="file" name="arquivo_teste" class="form-control"></div><div class="col-12 mt-2"><label>Instruções</label><textarea name="teste_pratico" class="form-control" rows="2"></textarea></div></div>
                <div class="text-end mt-4"><button type="submit" class="btn btn-success btn-lg shadow">Salvar Vaga</button></div>
            </form>
        <?php endif; ?>

        <!-- LISTA DE VAGAS -->
        <?php if($pagina == 'lista_vagas'): $lista=$pdo->query("SELECT * FROM vagas ORDER BY id DESC")->fetchAll(); ?>
            <h3 class="mb-4">Minhas Vagas</h3>
            <div class="card-custom p-0"><table class="table table-hover mb-0"><thead class="table-dark"><tr><th>Vaga</th><th>Início</th><th>Fim</th><th>Status</th><th class="text-end">Ações</th></tr></thead><tbody>
            <?php foreach($lista as $v): $ini=date('d/m/Y',strtotime($v['data_inicio'])); $fim=date('d/m/Y',strtotime($v['data_fim'])); ?>
            <tr><td><strong><?=$v['titulo']?></strong></td><td><?=$ini?></td><td><?=$fim?></td><td><span class="badge <?=$v['status']=='aberta'?'bg-success':'bg-secondary'?>"><?=strtoupper($v['status'])?></span></td><td class="text-end"><a href="?pag=lista_vagas&acao=excluir&id=<?=$v['id']?>" class="btn btn-sm btn-danger">Excluir</a></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>

        <!-- BANCO DE TESTES -->
        <?php if($pagina == 'banco_testes'): 
            $edit_id = $_GET['edit'] ?? null; $view_id = $_GET['view'] ?? null;
            if($edit_id) { $dados_t = $pdo->query("SELECT * FROM banco_testes WHERE id = $edit_id")->fetch(); }
            if($view_id): 
                $teste = $pdo->query("SELECT * FROM banco_testes WHERE id=$view_id")->fetch(); $questoes = $pdo->query("SELECT * FROM questoes_teste WHERE banco_teste_id=$view_id")->fetchAll();
        ?>
            <div class="d-flex justify-content-between mb-4"><h3>Questões: <?=$teste['titulo']?></h3><a href="?pag=banco_testes" class="btn btn-outline-dark">Voltar</a></div>
            <div class="row"><div class="col-md-7"><div class="card-custom"><h5 class="mb-3">Adicionar Questão</h5><form method="POST"><input type="hidden" name="add_questao" value="1"><input type="hidden" name="teste_id" value="<?=$view_id?>"><div class="mb-3"><label>Enunciado</label><textarea name="enunciado" class="editor-rico"></textarea></div><div class="mb-3"><label>Gabarito</label><textarea name="gabarito_questao" class="editor-rico"></textarea></div><button class="btn btn-primary w-100">Salvar Questão</button></form></div></div><div class="col-md-5"><?php foreach($questoes as $i=>$q): ?><div class="card mb-2 shadow-sm p-3 border-0"><span class="badge bg-secondary mb-2 w-25">Q <?=$i+1?></span><div><?=$q['enunciado']?></div></div><?php endforeach; ?></div></div>
        <?php else: $lista = $pdo->query("SELECT * FROM banco_testes ORDER BY id DESC")->fetchAll(); ?>
            <h3>Banco de Testes</h3>
            <div class="card-custom mt-4"><h5 class="text-primary">Novo Teste</h5><form method="POST" enctype="multipart/form-data"><input type="hidden" name="salvar_teste_db" value="1"><div class="row g-2"><div class="col-6"><label>Título</label><input type="text" name="titulo_teste" class="form-control" required></div><div class="col-md-3"><label>Segmento</label><select name="segmento" class="form-select"><option>Geral</option><option>TI</option><option>Adm</option></select></div><div class="col-3"><label>Tempo</label><input type="number" name="tempo" class="form-control" value="60"></div><div class="col-12"><label>Descrição</label><textarea name="desc_teste" class="editor-rico"></textarea></div><div class="col-12"><label>Gabarito</label><textarea name="gabarito_geral" class="editor-rico"></textarea></div><div class="col-6"><input type="file" name="arq_teste" class="form-control"></div><div class="col-6 text-end"><button class="btn btn-dark">Salvar</button></div></div></form></div>
            <h5 class="mt-5">Histórico</h5>
            <div class="card-custom p-0"><table class="table table-hover mb-0"><thead><tr><th>Título</th><th>Ação</th></tr></thead><tbody><?php foreach($lista as $t): ?><tr><td><?=$t['titulo']?></td><td class="text-end"><a href="?pag=banco_testes&view=<?=$t['id']?>" class="btn btn-sm btn-info">Questões</a></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; endif; ?>

        <!-- TEMPLATES -->
        <?php if($pagina == 'templates'): $templates = $pdo->query("SELECT * FROM mensagens_padrao")->fetchAll(); ?>
            <h3>Templates</h3>
            <div class="row"><div class="col-md-5"><div class="card-custom"><h5>Novo / Editar</h5><form method="POST"><input type="hidden" name="salvar_template" value="1"><div class="mb-3"><label>Fase</label><select name="fase" class="form-select"><option value="aprovado">Aprovado</option><option value="reprovado">Reprovado</option><option value="standby">Stand-By</option></select></div><div class="mb-3"><label>Assunto</label><input type="text" name="assunto" class="form-control"></div><div class="mb-3"><label>Texto</label><textarea name="mensagem" class="form-control" rows="5"></textarea></div><button class="btn btn-primary w-100">Salvar</button></form></div></div><div class="col-md-7"><div class="card-custom p-0"><ul class="list-group list-group-flush"><?php foreach($templates as $t): ?><li class="list-group-item"><strong><?=strtoupper($t['fase'])?></strong><br><small><?=$t['mensagem']?></small></li><?php endforeach; ?></ul></div></div></div>
        <?php endif; ?>

        <!-- CANDIDATOS -->
        <?php if($pagina == 'candidatos'): 
            $cands = $pdo->query("SELECT c.*, v.titulo as vaga, a.status, a.id as app_id FROM aplicacoes a JOIN candidatos c ON a.candidato_id=c.id JOIN vagas v ON a.vaga_id=v.id ORDER BY a.data_aplicacao DESC")->fetchAll();
            $templates_db = $pdo->query("SELECT * FROM mensagens_padrao")->fetchAll();
        ?>
            <h3>Gestão Candidatos</h3>
            <div class="card-custom p-0"><table class="table table-hover mb-0 align-middle"><thead class="table-dark"><tr><th>Nome</th><th>Vaga</th><th>Status</th><th>Ações</th></tr></thead><tbody>
            <?php foreach($cands as $c): ?>
            <tr><td><strong><?=$c['nome']?></strong></td><td><?=$c['vaga']?></td><td><span class="badge bg-secondary"><?=strtoupper($c['status'])?></span></td><td><button class="btn btn-sm btn-outline-dark fw-bold btn-fase" data-appid="<?=$c['app_id']?>" data-nome="<?=$c['nome']?>" data-email="<?=$c['email']?>" data-tel="<?=preg_replace('/\D/','',$c['telefone'])?>" data-vaga="<?=$c['vaga']?>">Mudar Fase</button></td></tr>
            <?php endforeach; ?></tbody></table></div>

            <!-- MODAL FASE -->
            <div class="modal fade" id="modalFase"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5>Mudar Fase</h5></div><div class="modal-body"><form method="POST" id="formFase"><input type="hidden" name="mudar_status_completo" value="1"><input type="hidden" name="app_id" id="f_app_id"><input type="hidden" name="email_cand" id="f_email"><input type="hidden" name="nome_cand" id="f_nome"><input type="hidden" name="telefone_cand" id="f_tel"><input type="hidden" id="f_vaga"><div class="row g-3"><div class="col-md-12"><label>Nova Fase</label><select name="novo_status" id="f_status" class="form-select" onchange="toggleForm()"><option value="">Selecione...</option><option value="aprovado">Aprovado</option><option value="reprovado">Reprovado</option><option value="standby">Stand-By</option><option value="entrevista">Entrevista</option></select></div><div class="col-6 d-none" id="box_feedback"><label>Motivo</label><select id="motivo_repo" class="form-select" onchange="updMsg()"><option value="">Selecione...</option><?php foreach($templates_db as $t): ?><option value="<?=$t['fase']?>"><?=$t['assunto']?></option><?php endforeach; ?></select></div>
            <!-- AGENDAMENTO -->
            <div class="col-12 d-none p-3 bg-light border" id="box_agenda"><div class="row g-2"><div class="col-4"><label>Data</label><input type="datetime-local" name="data_entrevista" id="f_data" class="form-control" onchange="updMsg()"></div><div class="col-3"><label>Tipo</label><select name="tipo_entrevista" id="f_tipo" class="form-select" onchange="updMsg()"><option>Online</option><option>Presencial</option></select></div><div class="col-5 div-online"><select name="plataforma_online" id="f_plat" class="form-select"><option>Google Meet</option><option>Teams</option></select></div><div class="col-12 div-online"><input type="text" name="link_reuniao" id="f_link" class="form-control" placeholder="Link..." onkeyup="updMsg()"></div><div class="col-3 div-pres d-none"><input type="text" id="f_cep" class="form-control" placeholder="CEP"></div><div class="col-9 div-pres d-none"><input type="text" name="end_rua" id="f_rua" class="form-control" readonly></div><div class="col-3 div-pres d-none"><input type="text" name="end_num" id="f_num" class="form-control" placeholder="Nº" onkeyup="updMsg()"></div><div class="col-4 div-pres d-none"><input type="text" name="end_comp" id="f_comp" class="form-control" placeholder="Comp" onkeyup="updMsg()"></div><div class="col-5 div-pres d-none"><input type="text" name="end_bairro" id="f_bairro" class="form-control"><input type="hidden" name="end_cidade" id="f_cidade"><input type="hidden" name="end_uf" id="f_uf"></div></div></div>
            <div class="col-12"><label>Mensagem</label><textarea name="mensagem_email" id="f_msg" class="form-control" rows="4"></textarea></div><textarea name="mensagem_whats" id="f_msg_whats" class="d-none"></textarea><div class="col-12 border-top pt-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="notificar_candidato" value="1" checked> Notificar Email</div><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="enviar_whats" value="1"> Ativar WhatsApp</div></div><div class="col-12"><button class="btn btn-primary w-100">SALVAR</button></div></div></form></div></div></div></div>

            <!-- POPUP WHATSAPP -->
            <?php if(isset($_SESSION['popup_whatsapp'])): $pop=$_SESSION['popup_whatsapp']; unset($_SESSION['popup_whatsapp']); ?>
            <div class="modal fade" id="modalZap"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header bg-success text-white"><h5>Sucesso!</h5></div><div class="modal-body text-center"><p>Envie também por WhatsApp:</p><a href="https://wa.me/55<?=$pop['telefone']?>?text=<?=urlencode($pop['mensagem'])?>" target="_blank" class="btn btn-success btn-lg w-100">ENVIAR AGORA</a></div></div></div></div>
            <script> new bootstrap.Modal('#modalZap').show(); </script>
            <?php endif; ?>

            <script>
                const templates = <?php echo json_encode($templates_db, JSON_HEX_QUOT|JSON_HEX_TAG); ?>;
                document.querySelectorAll('.btn-fase').forEach(btn => {
                    btn.addEventListener('click', function() {
                        $('#f_app_id').val(this.dataset.appid); $('#f_nome').val(this.dataset.nome); 
                        $('#f_email').val(this.dataset.email); $('#f_tel').val(this.dataset.tel); $('#f_vaga').val(this.dataset.vaga);
                        $('#f_status').val(''); $('#f_msg').val(''); $('#box_feedback, #box_agenda').addClass('d-none');
                        new bootstrap.Modal('#modalFase').show();
                    });
                });
                function toggleForm() {
                    let st = $('#f_status').val();
                    if(st=='reprovado'||st=='standby') { $('#box_feedback').removeClass('d-none'); $('#box_agenda').addClass('d-none'); }
                    else if(st=='entrevista') { $('#box_agenda').removeClass('d-none'); $('#box_feedback').addClass('d-none'); }
                    else { $('#box_feedback, #box_agenda').addClass('d-none'); }
                    updMsg();
                }
                function updMsg(){
                    let st=$('#f_status').val(); let n=$('#f_nome').val(); let v=$('#f_vaga').val(); let txt="";
                    if(st=='entrevista'){
                        let dt=$('#f_data').val(); let loc=($('#f_tipo').val()=='Online')?"Online":$('#f_rua').val();
                        txt=`Olá ${n},\nEntrevista agendada.\nData: ${dt}\nLocal: ${loc}`;
                    } else {
                        let t=templates.find(x=>x.fase==st); txt=t?t.mensagem:"Olá {NOME}";
                    }
                    txt=txt.replace(/{NOME}/g,n).replace(/{VAGA}/g,v);
                    $('#f_msg').val(txt); $('#f_msg_whats').val(txt.replace(/<br>/g,"\n"));
                }
                // CEP
                $("#f_cep").blur(function(){ let c=this.value.replace(/\D/g,''); if(c) $.getJSON("https://viacep.com.br/ws/"+c+"/json/?callback=?", function(d){ if(!d.erro){ $("#f_rua").val(d.logradouro); $("#f_bairro").val(d.bairro); $("#f_cidade").val(d.localidade); $("#f_uf").val(d.uf); updMsg(); } }); });
                // Toggle Tipo
                $('#f_tipo').change(function(){ if(this.value=='Online'){ $('.div-online').removeClass('d-none'); $('.div-pres').addClass('d-none'); } else { $('.div-online').addClass('d-none'); $('.div-pres').removeClass('d-none'); } updMsg(); });
            </script>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function adicionarSkillRapido() {
            let nome = $('#nova_skill_input').val(); if(!nome) return;
            $.post('admin.php', { ajax_add_skill: nome }, function(data) {
                if(data.erro) alert(data.erro);
                else {
                    $('#container_skills').append(`<div class="col-md-3 border rounded p-2 bg-white d-flex justify-content-between"><div><input type="checkbox" name="skills[]" value="${data.id}" checked> ${data.nome}</div></div>`);
                    $('#nova_skill_input').val('');
                }
            }, 'json');
        }
    </script>
</body>
</html>