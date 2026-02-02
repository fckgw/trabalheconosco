<?php
session_start(); ini_set('display_errors',1); error_reporting(E_ALL);
require 'includes/db.php'; require_once 'includes/email.php';
if (!isset($_SESSION['nivel']) || $_SESSION['nivel'] !== 'rh') { header("Location: index.php"); exit; }
$pdo = getDB(); $pag = $_GET['pag'] ?? 'dashboard'; $msg = "";

// --- SALVAR VAGA ---
if (isset($_POST['acao_vaga'])) {
    $arq = null;
    if (isset($_FILES['arquivo_teste']) && $_FILES['arquivo_teste']['error']==0) {
        $ext = pathinfo($_FILES['arquivo_teste']['name'], PATHINFO_EXTENSION);
        $nm = "T_".uniqid().".".$ext; move_uploaded_file($_FILES['arquivo_teste']['tmp_name'], "uploads/testes_rh/".$nm);
        $arq = $nm;
    }
    
    // Flags
    $pcd = $_POST['exclusivo_pcd']??0; $ocultar = $_POST['ocultar_salario']??0;
    $req_em = $_POST['req_ensino_medio']??0; $req_off = $_POST['req_office']??0; $req_bi = $_POST['req_powerbi']??0;

    if ($_POST['acao_vaga'] == 'criar') {
        $sql = "INSERT INTO vagas (titulo, setor, tipo_vaga, descricao, requisitos, teste_pratico, arquivo_teste, localizacao, faixa_salarial, salario_real, quantidade_vagas, regime_contratacao, idade_min, idade_max, req_ensino_medio, req_office, req_ingles, req_powerbi, exclusivo_pcd, ocultar_salario, data_inicio, data_fim) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $pdo->prepare($sql)->execute([$_POST['titulo'], $_POST['setor'], $_POST['tipo_vaga'], $_POST['descricao'], $_POST['requisitos'], $_POST['teste_pratico'], $arq, $_POST['localizacao'], $_POST['faixa_salarial'], $_POST['salario_real'], $_POST['qtd'], $_POST['regime'], $_POST['idade_min'], $_POST['idade_max'], $req_em, $req_off, $_POST['req_ingles'], $req_bi, $pcd, $ocultar, $_POST['inicio'], $_POST['fim']]);
        $msg = "Vaga criada!";
    }
}

// --- MUDAR STATUS / OBS ---
if (isset($_POST['salvar_obs'])) {
    $pdo->prepare("UPDATE aplicacoes SET obs_entrevista = ? WHERE id = ?")->execute([$_POST['obs_entrevista'], $_POST['app_id']]);
    $msg = "Observação salva!";
}
if (isset($_POST['mudar_status_simples'])) {
    $pdo->prepare("UPDATE aplicacoes SET status = ? WHERE id = ?")->execute([$_POST['novo_status'], $_POST['app_id']]);
    
    // Libera sala de teste se for o caso
    if($_POST['novo_status'] == 'teste_pratico') {
        // Opcional: Enviar email avisando
    }
    $msg = "Status atualizado.";
}

// DASHBOARD
$tot_vagas = $pdo->query("SELECT COUNT(*) FROM vagas WHERE status='aberta'")->fetchColumn();
$tot_cands = $pdo->query("SELECT COUNT(*) FROM candidatos")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"> <title>RH Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style> 
        body{background:#f0f2f5;display:flex;} 
        .sidebar{width:250px;background:#000;color:#fff;min-height:100vh;padding:20px;} 
        .sidebar a{color:#ccc;text-decoration:none;display:block;padding:10px;border-bottom:1px solid #333;} 
        .content{flex:1;padding:30px;} 
    </style>
</head>
<body>
    <div class="sidebar">
        <h4>RH Cedro</h4>
        <a href="?pag=dashboard">Dashboard</a>
        <a href="?pag=vagas">Vagas</a>
        <a href="?pag=candidatos">Candidatos</a>
        <a href="logout.php">Sair</a>
    </div>
    <div class="content">
        <?php if($msg): ?><div class="alert alert-success"><?php echo $msg; ?></div><?php endif; ?>

        <?php if($pag=='dashboard'): ?>
            <h3>Dashboard</h3>
            <div class="row">
                <div class="col-md-4"><div class="card p-3 bg-primary text-white"><h3><?php echo $tot_vagas; ?></h3> Vagas Abertas</div></div>
                <div class="col-md-4"><div class="card p-3 bg-success text-white"><h3><?php echo $tot_cands; ?></h3> Candidatos</div></div>
            </div>
        <?php endif; ?>

        <?php if($pag=='vagas'): ?>
            <h3>Nova Vaga</h3>
            <form method="POST" enctype="multipart/form-data" class="bg-white p-4 shadow mb-4">
                <input type="hidden" name="acao_vaga" value="criar">
                <div class="row g-2">
                    <div class="col-6"><label>Título</label><input type="text" name="titulo" class="form-control" required></div>
                    <div class="col-3"><label>Tipo</label><select name="tipo_vaga" class="form-select"><option value="externa">Externa</option><option value="interna">Interna</option></select></div>
                    <div class="col-3"><label>Setor</label><select name="setor" class="form-select"><option>Produção</option><option>Adm</option></select></div>
                    
                    <div class="col-12 bg-light p-2">
                        <strong>Flags:</strong>
                        <label class="me-3"><input type="checkbox" name="req_ensino_medio" value="1"> Médio Completo</label>
                        <label class="me-3"><input type="checkbox" name="req_office" value="1"> Office</label>
                        <label class="me-3"><input type="checkbox" name="req_powerbi" value="1"> PowerBI</label>
                        <label class="me-3 text-primary"><input type="checkbox" name="exclusivo_pcd" value="1"> PCD</label>
                        <label class="text-danger"><input type="checkbox" name="ocultar_salario" value="1"> Ocultar Salário</label>
                    </div>

                    <div class="col-6"><label>Descrição</label><textarea name="descricao" class="form-control"></textarea></div>
                    <div class="col-6"><label>Instrução Teste Prático</label><textarea name="teste_pratico" class="form-control"></textarea></div>
                    <div class="col-6"><label>Arquivo Teste (ZIP/PDF)</label><input type="file" name="arquivo_teste" class="form-control"></div>
                    
                    <div class="col-3"><label>Inicio</label><input type="date" name="inicio" class="form-control"></div>
                    <div class="col-3"><label>Fim</label><input type="date" name="fim" class="form-control"></div>
                    
                    <div class="col-12 mt-2"><button class="btn btn-dark w-100">Salvar Vaga</button></div>
                </div>
            </form>
        <?php endif; ?>

        <?php if($pag=='candidatos'): 
            $cands = $pdo->query("SELECT c.*, a.status, a.id as app_id, a.obs_entrevista FROM aplicacoes a JOIN candidatos c ON a.candidato_id=c.id ORDER BY a.data_aplicacao DESC")->fetchAll();
        ?>
            <h3>Candidatos</h3>
            <table class="table table-hover bg-white">
                <thead><tr><th>Nome</th><th>Matrícula/CPF</th><th>Status</th><th>Ações</th></tr></thead>
                <tbody>
                    <?php foreach($cands as $c): ?>
                    <tr>
                        <td><?php echo $c['nome']; ?><br><small><?php echo $c['email']; ?></small></td>
                        <td><?php echo $c['matricula'] ? "Mat: ".$c['matricula'] : $c['cpf']; ?></td>
                        <td>
                            <form method="POST">
                                <input type="hidden" name="mudar_status_simples" value="1">
                                <input type="hidden" name="app_id" value="<?php echo $c['app_id']; ?>">
                                <select name="novo_status" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="inscrito" <?php echo $c['status']=='inscrito'?'selected':''; ?>>Inscrito</option>
                                    <option value="teste_pratico" <?php echo $c['status']=='teste_pratico'?'selected':''; ?>>Teste Prático (Sala Virtual)</option>
                                    <option value="entrevista" <?php echo $c['status']=='entrevista'?'selected':''; ?>>Entrevista</option>
                                    <option value="aprovado" <?php echo $c['status']=='aprovado'?'selected':''; ?>>Aprovado</option>
                                    <option value="reprovado" <?php echo $c['status']=='reprovado'?'selected':''; ?>>Reprovado</option>
                                </select>
                            </form>
                        </td>
                        <td>
                            <a href="uploads/<?php echo $c['arquivo_curriculo']; ?>" target="_blank" class="btn btn-sm btn-danger"><i class="fas fa-file-pdf"></i></a>
                            <button class="btn btn-sm btn-primary" onclick="verObs('<?php echo $c['app_id']; ?>', '<?php echo addslashes($c['obs_entrevista']); ?>')"><i class="fas fa-comment"></i> Obs</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- MODAL OBS -->
            <div class="modal fade" id="modalObs"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5>Observações</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="salvar_obs" value="1">
                    <input type="hidden" name="app_id" id="obs_app_id">
                    <textarea name="obs_entrevista" id="obs_text" class="form-control" rows="5"></textarea>
                    <button class="btn btn-dark mt-2 w-100">Salvar</button>
                </form>
            </div></div></div></div>
            <script>function verObs(id, txt){ $('#obs_app_id').val(id); $('#obs_text').val(txt); new bootstrap.Modal('#modalObs').show(); }</script>
        <?php endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>