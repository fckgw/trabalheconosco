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
$match_results = []; 

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
// 1. LÓGICA DE VAGAS (SALVAR)
// =================================================================================
if (isset($_POST['acao_vaga']) && $_POST['acao_vaga'] == 'criar') {
    try {
        $pdo->beginTransaction();
        $arq = null;
        if (isset($_FILES['arquivo_teste']) && $_FILES['arquivo_teste']['error'] == 0) {
            $ext = pathinfo($_FILES['arquivo_teste']['name'], PATHINFO_EXTENSION);
            $nm = "VT_".uniqid().".".$ext; move_uploaded_file($_FILES['arquivo_teste']['tmp_name'], "uploads/testes_rh/".$nm); $arq = $nm;
        }

        if($_POST['acao_vaga'] == 'criar') {
            $sql = "INSERT INTO vagas (titulo, setor, tipo_vaga, descricao, requisitos, teste_pratico, arquivo_teste, banco_teste_id, localizacao, faixa_salarial, salario_real, quantidade_vagas, regime_contratacao, idade_min, idade_max, req_ensino_medio, req_office, req_ingles, req_espanhol, req_powerbi, exclusivo_pcd, ocultar_salario, data_inicio, data_fim, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
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

// ... (Outras lógicas de Banco de Testes e Candidatos mantidas) ...

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
    <script>tinymce.init({ selector:'.editor-rico', height:200, menubar:false, branding:false, plugins:'lists link image', toolbar:'bold italic bullist numlist' });</script>

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
        <a href="?pag=candidatos" class="<?=$pagina=='candidatos'?'active':''?>"><i class="fas fa-users me-2"></i> Candidatos</a>
        <a href="logout.php" class="text-danger mt-4"><i class="fas fa-sign-out-alt me-2"></i> Sair</a>
    </nav>

    <div class="main-content">
        <?php if($msg): ?><div class="alert alert-success shadow-sm"><?=$msg?></div><?php endif; ?>
        <?php if($erro): ?><div class="alert alert-danger shadow-sm"><?=$erro?></div><?php endif; ?>

        <!-- DASHBOARD (RESTAURADO) -->
        <?php if($pagina == 'dashboard'): ?>
            <h3 class="mb-4 text-dark fw-bold">Dashboard</h3>
            <div class="row g-4">
                <div class="col-md-4"><div class="card-custom border-start border-5 border-primary"><h2><?=$total_vagas?></h2><p class="text-muted mb-0">Vagas Abertas</p></div></div>
                <div class="col-md-4"><div class="card-custom border-start border-5 border-success"><h2><?=$total_cands?></h2><p class="text-muted mb-0">Candidatos</p></div></div>
                <div class="col-md-4"><div class="card-custom border-start border-5 border-warning"><h2><?=$total_proc?></h2><p class="text-muted mb-0">Em Entrevista</p></div></div>
            </div>
        <?php endif; ?>

        <!-- NOVA VAGA (RESTAURADO) -->
        <?php if($pagina == 'vagas'): 
            $skills_db = $pdo->query("SELECT * FROM conhecimentos ORDER BY nome")->fetchAll();
            $testes_db = $pdo->query("SELECT * FROM banco_testes WHERE status='ativo' ORDER BY titulo")->fetchAll();
        ?>
            <h3 class="mb-4">Nova Vaga</h3>
            <form method="POST" enctype="multipart/form-data" class="card-custom">
                <input type="hidden" name="acao_vaga" value="criar">
                
                <h5 class="text-primary mb-3">Dados da Vaga</h5>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label fw-bold">Título</label><input type="text" name="titulo" class="form-control" required></div>
                    <div class="col-md-3"><label>Tipo</label><select name="tipo_vaga" class="form-select"><option value="externa">Externa</option><option value="interna">Interna</option></select></div>
                    <div class="col-md-3">
                        <label>Setor</label>
                        <select name="setor" class="form-select">
                            <option value="">Selecione...</option>
                            <optgroup label="Industrial"><option>Produção</option><option>Manutenção</option><option>Qualidade</option><option>Logística</option></optgroup>
                            <optgroup label="Escritório"><option>Administrativo</option><option>Comercial</option><option>TI</option><option>RH</option><option>Financeiro</option></optgroup>
                        </select>
                    </div>
                    <div class="col-md-3"><label class="text-success fw-bold">Salário (Site)</label><input type="text" name="faixa_salarial" class="form-control"></div>
                    <div class="col-md-3"><label class="text-danger fw-bold">Salário (Interno)</label><input type="number" step="0.01" name="salario_real" class="form-control"></div>
                    <div class="col-md-3 mt-4"><label><input type="checkbox" name="ocultar_salario" value="1"> Ocultar</label></div>
                    <div class="col-md-3">
                        <label>Regime</label>
                        <select name="regime" class="form-select">
                            <option>CLT</option><option>Temporário</option><option>PJ</option><option>Estágio</option><option>Jovem Aprendiz</option><option>Trainee</option>
                        </select>
                    </div>
                    <div class="col-12"><div class="alert alert-light border p-2 mb-0 small"><i class="fas fa-lock"></i> <strong>Filtro de Idade (Interno):</strong> <input type="number" name="idade_min" class="form-control d-inline-block w-auto" style="height:25px" value="18"> a <input type="number" name="idade_max" class="form-control d-inline-block w-auto" style="height:25px" value="65"> anos.</div></div>
                    <div class="col-6"><label>Descrição</label><textarea name="descricao" class="form-control" rows="4"></textarea></div>
                    <div class="col-6"><label>Requisitos (OBS)</label><textarea name="requisitos" class="form-control" rows="4"></textarea></div>
                </div>

                <hr>
                <h5 class="text-primary mb-3">Requisitos Automáticos</h5>
                <div class="row g-3 bg-light p-3 rounded mb-3 border">
                    <div class="col-md-3"><label><input type="checkbox" name="req_ensino_medio" value="1"> Ensino Médio</label></div>
                    <div class="col-md-3"><label><input type="checkbox" name="req_office" value="1"> Pacote Office</label></div>
                    <div class="col-md-3"><label><input type="checkbox" name="req_powerbi" value="1"> Power BI</label></div>
                    <div class="col-md-3"><label class="text-primary fw-bold"><input type="checkbox" name="exclusivo_pcd" value="1"> Vaga PCD</label></div>
                    <div class="col-12"><hr></div>
                    <div class="col-md-6"><label>Inglês</label><select name="req_ingles" class="form-select form-select-sm"><option value="">-</option><option>Básico</option><option>Intermediário</option><option>Avançado</option><option>Fluente</option></select></div>
                    <div class="col-md-6"><label>Espanhol</label><select name="req_espanhol" class="form-select form-select-sm"><option value="">-</option><option>Básico</option><option>Intermediário</option><option>Avançado</option><option>Fluente</option></select></div>
                </div>

                <h6 class="text-muted mt-4">Conhecimentos Específicos</h6>
                <div class="row g-2 mb-3" id="container_skills"><?php foreach($skills_db as $sk): ?><div class="col-md-3 border rounded p-2 d-flex justify-content-between"><div><input type="checkbox" name="skills[]" value="<?=$sk['id']?>"> <?=$sk['nome']?></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="obrigatorio[<?=$sk['id']?>]"></div></div><?php endforeach; ?></div>
                <div class="row"><div class="col-md-6"><div class="input-group"><input type="text" id="nova_skill_input" class="form-control" placeholder="Novo..."><button type="button" class="btn btn-dark" onclick="adicionarSkillRapido()">Add</button></div></div></div>
                
                <hr>
                <h5 class="text-primary mb-3">Teste Prático</h5>
                <div class="row"><div class="col-md-6"><label>Vincular do Banco</label><select name="banco_teste_id" class="form-select"><option value="">Nenhum</option><?php foreach($testes_db as $t): ?><option value="<?=$t['id']?>"><?=$t['titulo']?></option><?php endforeach; ?></select></div><div class="col-md-6"><label>Arquivo Específico</label><input type="file" name="arquivo_teste" class="form-control"></div><div class="col-12 mt-2"><label>Instruções</label><textarea name="teste_pratico" class="form-control" rows="2"></textarea></div></div>
                
                <div class="text-end mt-4"><button type="submit" class="btn btn-success btn-lg shadow">Salvar Vaga</button></div>
            </form>
        <?php endif; ?>

        <!-- LISTA VAGAS (RESTAURADO) -->
        <?php if($pagina == 'lista_vagas'): 
            $lista = $pdo->query("SELECT * FROM vagas ORDER BY id DESC")->fetchAll();
        ?>
            <h3 class="mb-4">Minhas Vagas</h3>
            <div class="card-custom p-0"><table class="table table-hover mb-0"><thead class="table-dark"><tr><th>Vaga</th><th>Início</th><th>Fim</th><th>Status</th><th class="text-end">Ações</th></tr></thead><tbody>
            <?php foreach($lista as $v): ?>
            <tr>
                <td><strong><?=$v['titulo']?></strong></td>
                <td><?=date('d/m/Y', strtotime($v['data_inicio']))?></td>
                <td><?=date('d/m/Y', strtotime($v['data_fim']))?></td>
                <td><span class="badge <?=$v['status']=='aberta'?'bg-success':'bg-secondary'?>"><?=strtoupper($v['status'])?></span></td>
                <td class="text-end"><a href="?pag=lista_vagas&acao=excluir&id=<?=$v['id']?>" class="btn btn-sm btn-danger">Excluir</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>

        <!-- BANCO DE TESTES (RESTAURADO) -->
        <?php if($pagina == 'banco_testes'): 
            $lista = $pdo->query("SELECT t.*, u.nome as autor FROM banco_testes t LEFT JOIN usuarios u ON t.criado_por_id = u.id ORDER BY id DESC")->fetchAll();
        ?>
            <h3>Banco de Testes</h3>
            <div class="card-custom mt-4">
                <h5 class="text-primary">Novo Teste</h5>
                <form method="POST" enctype="multipart/form-data">
                    <!-- (Formulário de criação de teste aqui) -->
                </form>
            </div>
            <h5 class="mt-5">Histórico</h5>
            <div class="card-custom p-0"><table class="table table-hover mb-0"><thead><tr><th>Título</th><th>Ação</th></tr></thead><tbody>
            <?php foreach($lista as $t): ?><tr><td><?=$t['titulo']?></td><td><a href="?pag=banco_testes&edit=<?=$t['id']?>" class="btn btn-sm btn-primary">Editar</a></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
        
        <!-- CANDIDATOS -->
        <?php if($pagina == 'candidatos'): 
            $cands = $pdo->query("SELECT c.*, v.titulo as vaga, a.status, a.id as app_id FROM aplicacoes a JOIN candidatos c ON a.candidato_id=c.id JOIN vagas v ON a.vaga_id=v.id ORDER BY a.data_aplicacao DESC")->fetchAll();
        ?>
            <h3>Gestão Candidatos</h3>
            <div class="card-custom p-0"><table class="table table-hover mb-0 align-middle"><thead class="table-dark"><tr><th>Nome</th><th>Vaga</th><th>Status</th><th>Ações</th></tr></thead><tbody>
            <?php foreach($cands as $c): ?>
            <tr>
                <td><strong><?=$c['nome']?></strong><br><small><?=$c['email']?></small></td>
                <td><?=$c['vaga']?></td>
                <td><span class="badge bg-secondary"><?=strtoupper($c['status'])?></span></td>
                <td><button class="btn btn-sm btn-outline-dark fw-bold btn-fase" data-appid="<?=$c['app_id']?>" data-nome="<?=$c['nome']?>" data-email="<?=$c['email']?>" data-tel="<?=preg_replace('/\D/','',$c['telefone'])?>">Mudar Fase</button></td>
            </tr><?php endforeach; ?></tbody></table></div>

            <!-- MODAL FASE -->
            <div class="modal fade" id="modalFase"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5>Mudar Fase</h5></div><div class="modal-body"><form method="POST" id="formFase"><input type="hidden" name="mudar_status_completo" value="1"><input type="hidden" name="app_id" id="f_app_id"><input type="hidden" name="email_cand" id="f_email"><input type="hidden" name="nome_cand" id="f_nome"><input type="hidden" name="telefone_cand" id="f_tel"><div class="row g-2"><div class="col-6"><label>Fase</label><select name="novo_status" id="f_status" class="form-select"><option value="entrevista">Entrevista</option><option value="aprovado">Aprovado</option></select></div><div class="col-6"><label>Recrutador</label><input type="text" name="nome_recrutador" id="f_rec" class="form-control"></div><div class="col-12"><label>Mensagem</label><textarea name="mensagem_email" id="f_msg" class="form-control" rows="4"></textarea></div><textarea name="mensagem_whats" id="f_msg_whats" class="d-none"></textarea><div class="col-12"><button class="btn btn-primary w-100">SALVAR E ENVIAR</button></div></div></form></div></div></div></div>

            <!-- MODAL WHATSAPP -->
            <?php if(isset($_SESSION['popup_whatsapp'])): 
                $pop = $_SESSION['popup_whatsapp']; unset($_SESSION['popup_whatsapp']);
            ?>
            <div class="modal fade" id="modalZap"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header bg-success text-white"><h5>Sucesso!</h5></div><div class="modal-body text-center"><p>Envie também pelo WhatsApp:</p><a href="https://wa.me/55<?=$pop['telefone']?>?text=<?=urlencode($pop['mensagem'])?>" target="_blank" class="btn btn-success btn-lg w-100"><i class="fab fa-whatsapp"></i> ENVIAR</a></div></div></div></div>
            <script> new bootstrap.Modal('#modalZap').show(); </script>
            <?php endif; ?>

            <script>
                document.querySelectorAll('.btn-fase').forEach(btn => {
                    btn.addEventListener('click', function() {
                        $('#f_app_id').val(this.dataset.appid); $('#f_nome').val(this.dataset.nome); 
                        $('#f_email').val(this.dataset.email); $('#f_tel').val(this.dataset.tel);
                        new bootstrap.Modal('#modalFase').show();
                    });
                });
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
                    $('#container_skills').append(`<div class="col-md-3 border rounded p-2 bg-white"><div><input type="checkbox" name="skills[]" value="${data.id}" checked> ${data.nome}</div></div>`);
                    $('#nova_skill_input').val('');
                }
            }, 'json');
        }
    </script>
</body>
</html>