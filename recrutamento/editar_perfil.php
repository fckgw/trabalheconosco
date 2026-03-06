<?php
session_start();
// --- CONFIGURAÇÃO ---
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'includes/db.php';
require_once 'includes/validacoes.php';

// 1. SEGURANÇA
if (!isset($_SESSION['nivel']) || $_SESSION['nivel'] !== 'candidato') {
    header("Location: index.php");
    exit;
}

$pdo = getDB();
$cand_id = $_SESSION['usuario_id'];
$msg = "";
$erro = "";

// 2. BUSCAR DADOS ATUAIS (PARA PREENCHER O FORMULÁRIO)
$c = $pdo->query("SELECT * FROM candidatos WHERE id = $cand_id")->fetch(PDO::FETCH_ASSOC);
$formacoes_db = $pdo->query("SELECT * FROM formacoes_academicas WHERE candidato_id = $cand_id")->fetchAll(PDO::FETCH_ASSOC);
$experiencias_db = $pdo->query("SELECT * FROM experiencias WHERE candidato_id = $cand_id")->fetchAll(PDO::FETCH_ASSOC);

// Prepara JSON para o JavaScript carregar as grids
$json_formacoes = json_encode($formacoes_db);
$json_experiencias = json_encode($experiencias_db);

// 3. PROCESSAR ATUALIZAÇÃO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        // A. Upload de Novo Currículo (Opcional na edição)
        $caminhoBanco = $c['arquivo_curriculo']; // Mantém o antigo por padrão
        if (isset($_FILES['cv']) && $_FILES['cv']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['cv']['name'], PATHINFO_EXTENSION));
            $nomePasta = Validador::slugPasta($c['cpf'], $_POST['nome']); // Usa o CPF original para a pasta
            $caminhoPasta = __DIR__ . '/uploads/' . $nomePasta;
            
            if (!is_dir($caminhoPasta)) mkdir($caminhoPasta, 0755, true);
            
            $nomeArquivo = "CV_UPD_" . date('Ymd_His') . "." . $ext;
            if(move_uploaded_file($_FILES['cv']['tmp_name'], $caminhoPasta . '/' . $nomeArquivo)) {
                $caminhoBanco = $nomePasta . '/' . $nomeArquivo;
            }
        }

        // B. Tratamento de Campos
        $cnhCompleta = ($_POST['possui_cnh'] == 'Sim') ? ($_POST['cnh_cat'] ?? '') . " - " . ($_POST['cnh_num'] ?? '') : "Não";
        $matricula = ($_POST['sou_funcionario'] ?? 0) ? $_POST['matricula'] : null;
        $unidade   = ($_POST['sou_funcionario'] ?? 0) ? $_POST['unidade_atual'] : null;
        $setor     = ($_POST['sou_funcionario'] ?? 0) ? $_POST['setor_atual'] : null;
        $cargo_int = ($_POST['sou_funcionario'] ?? 0) ? $_POST['cargo_atual_interno'] : null;
        $gestor    = ($_POST['sou_funcionario'] ?? 0) ? $_POST['gestor_imediato'] : null;
        $admissao  = ($_POST['sou_funcionario'] ?? 0) ? $_POST['data_admissao'] : null;
        
        // C. Update Candidato
        $sql = "UPDATE candidatos SET 
            nome=?, rg=?, matricula=?, unidade_atual=?, setor_atual=?, cargo_atual_interno=?, gestor_imediato=?, data_admissao=?,
            nome_pai=?, nome_mae=?, email=?, telefone=?, data_nascimento=?, estado_civil=?, genero=?,
            cep=?, endereco=?, numero_endereco=?, complemento=?, bairro=?, cidade=?, estado=?,
            nivel_ingles=?, nivel_espanhol=?, area_interesse=?, pretensao_salarial=?, cnh=?,
            resumo_profissional=?, arquivo_curriculo=?, data_atualizacao=NOW()
            WHERE id=?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['nome'], $_POST['rg'], $matricula, $unidade, $setor, $cargo_int, $gestor, $admissao,
            $_POST['nome_pai'], $_POST['nome_mae'], $_POST['email'], $_POST['telefone'], $_POST['nascimento'], $_POST['estado_civil'], $_POST['genero'],
            Validador::limpar($_POST['cep']), $_POST['endereco'], $_POST['numero'], $_POST['complemento'], $_POST['bairro'], $_POST['cidade'], $_POST['estado'],
            $_POST['ingles'], $_POST['espanhol'], $_POST['area_interesse'], $_POST['pretensao'], $cnhCompleta,
            $_POST['resumo_profissional'], $caminhoBanco, $cand_id
        ]);

        // D. Atualizar Formações (Apaga antigas e insere novas do JSON)
        $pdo->prepare("DELETE FROM formacoes_academicas WHERE candidato_id = ?")->execute([$cand_id]);
        $formacoes = json_decode($_POST['lista_formacoes_json'] ?? '[]', true);
        if (is_array($formacoes)) {
            $stmtForm = $pdo->prepare("INSERT INTO formacoes_academicas (candidato_id, nivel, instituicao, curso, status, data_inicio, data_conclusao) VALUES (?,?,?,?,?,?,?)");
            foreach ($formacoes as $f) {
                // Adaptação caso o JSON venha do banco ou da tela (nomes de chaves podem variar ligeiramente se não padronizados, aqui padronizamos)
                $stmtForm->execute([$cand_id, $f['nivel'], $f['instituicao'], $f['curso'], $f['status'], $f['data_inicio'] ?? $f['inicio'], $f['data_conclusao'] ?? $f['conclusao']]);
            }
        }

        // E. Atualizar Experiências
        $pdo->prepare("DELETE FROM experiencias WHERE candidato_id = ?")->execute([$cand_id]);
        $experiencias = json_decode($_POST['lista_experiencias_json'] ?? '[]', true);
        if (is_array($experiencias)) {
            $stmtExp = $pdo->prepare("INSERT INTO experiencias (candidato_id, empresa, cargo, inicio, fim, atual, descricao) VALUES (?,?,?,?,?,?,?)");
            foreach ($experiencias as $e) {
                $stmtExp->execute([$cand_id, $e['empresa'], $e['cargo'], $e['inicio'], ($e['atual']?null:$e['fim']), $e['atual'], $e['descricao']]);
            }
        }

        $pdo->commit();
        
        // Recarrega os dados para exibir atualizado
        $c = $pdo->query("SELECT * FROM candidatos WHERE id = $cand_id")->fetch(PDO::FETCH_ASSOC);
        $formacoes_db = $pdo->query("SELECT * FROM formacoes_academicas WHERE candidato_id = $cand_id")->fetchAll(PDO::FETCH_ASSOC);
        $experiencias_db = $pdo->query("SELECT * FROM experiencias WHERE candidato_id = $cand_id")->fetchAll(PDO::FETCH_ASSOC);
        $json_formacoes = json_encode($formacoes_db);
        $json_experiencias = json_encode($experiencias_db);
        
        $msg = "Perfil atualizado com sucesso!";

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $erro = "Erro ao atualizar: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Perfil - Cedro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>

    <style>
        body { background: #f4f6f9; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }
        .sidebar { min-height: 100vh; background: #1a1a1a; color: #fff; width: 260px; position: fixed; z-index: 1000; }
        .sidebar .brand { padding: 25px; text-align: center; border-bottom: 1px solid #333; background: #000; }
        .sidebar a { color: #aaa; text-decoration: none; padding: 15px 20px; display: block; border-bottom: 1px solid #222; font-size: 0.95rem; }
        .sidebar a:hover, .sidebar a.active { background: #222; color: #fff; border-left: 4px solid #0d6efd; padding-left: 25px; }
        .sidebar i { width: 25px; margin-right: 10px; }
        
        .main-content { margin-left: 260px; padding: 30px; }
        @media (max-width: 768px) { .sidebar { width: 100%; position: relative; min-height: auto; } .main-content { margin-left: 0; padding: 15px; } }
        
        /* Estilos do Form */
        .card-custom { border: none; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); background: white; margin-bottom: 25px; padding: 25px; }
        .nav-pills .nav-link { color: #6c757d; font-weight: 600; padding: 10px 15px; border-bottom: 3px solid #dee2e6; border-radius: 0; background: #fff; }
        .nav-pills .nav-link.active { color: #000; background: #fff; border-bottom: 3px solid #000; }
        .btn-add { background: #000; color: #fff; border: none; font-size: 0.9rem; padding: 5px 15px; border-radius: 20px; }
        .exp-card { border-left: 4px solid #000; background: #f8f9fa; padding: 15px; margin-bottom: 10px; border-radius: 4px; position: relative; }
        .trash-btn { cursor: pointer; color: #dc3545; }
    </style>
</head>
<body>

    <nav class="sidebar">
        <div class="brand"><img src="logo-branco.png" width="130"></div>
        <div class="p-3 text-center border-bottom border-secondary mb-2">
            <div class="rounded-circle bg-secondary d-inline-flex justify-content-center align-items-center mb-2" style="width: 50px; height: 50px;"><i class="fas fa-user fa-lg text-white"></i></div>
            <div class="small fw-bold text-white"><?php echo explode(' ', $c['nome'])[0]; ?></div>
        </div>
        <a href="painel_candidato.php?pag=dashboard"><i class="fas fa-home"></i> Dashboard</a>
        <a href="painel_candidato.php?pag=historico"><i class="fas fa-briefcase"></i> Minhas Vagas</a>
        <a href="painel_candidato.php?pag=sala_teste"><i class="fas fa-laptop-code"></i> Sala de Testes</a>
        <a href="painel_candidato.php?pag=perfil"><i class="fas fa-id-card"></i> Meu Perfil</a>
        <a href="editar_perfil.php" class="active"><i class="fas fa-user-edit"></i> Editar Dados</a>
        <a href="logout.php" class="text-danger mt-4"><i class="fas fa-sign-out-alt"></i> Sair</a>
    </nav>

    <div class="main-content">
        <h3 class="mb-4">Editar Meu Perfil</h3>

        <?php if($msg): ?><div class="alert alert-success"><?=$msg?></div><?php endif; ?>
        <?php if($erro): ?><div class="alert alert-danger"><?=$erro?></div><?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="mainForm">
            <ul class="nav nav-pills nav-fill bg-white shadow-sm rounded-top mb-3" id="myTab" role="tablist">
                <li class="nav-item"><button class="nav-link active" id="pessoal-tab" data-bs-toggle="pill" data-bs-target="#pessoal" type="button"><i class="fas fa-user"></i> Dados Pessoais</button></li>
                <li class="nav-item"><button class="nav-link" id="formacao-tab" data-bs-toggle="pill" data-bs-target="#formacao" type="button"><i class="fas fa-graduation-cap"></i> Formação</button></li>
                <li class="nav-item"><button class="nav-link" id="exp-tab" data-bs-toggle="pill" data-bs-target="#exp" type="button"><i class="fas fa-briefcase"></i> Experiência</button></li>
                <li class="nav-item"><button class="nav-link" id="anexo-tab" data-bs-toggle="pill" data-bs-target="#anexo" type="button"><i class="fas fa-file-upload"></i> Currículo</button></li>
            </ul>

            <div class="tab-content card-custom">
                
                <!-- ABA 1: DADOS PESSOAIS -->
                <div class="tab-pane fade show active" id="pessoal">
                    <div class="row g-3">
                        <div class="col-md-3"><label class="form-label">CPF (Fixo)</label><input type="text" name="cpf" class="form-control bg-light" value="<?=$c['cpf']?>" readonly></div>
                        <div class="col-md-3"><label class="form-label">RG</label><input type="text" name="rg" class="form-control" value="<?=$c['rg']?>"></div>
                        <div class="col-md-6"><label class="form-label">Nome Completo</label><input type="text" name="nome" class="form-control" value="<?=$c['nome']?>" required></div>
                        
                        <div class="col-md-6"><label class="form-label">Nome da Mãe</label><input type="text" name="nome_mae" class="form-control" value="<?=$c['nome_mae']?>"></div>
                        <div class="col-md-6"><label class="form-label">Nome do Pai</label><input type="text" name="nome_pai" class="form-control" value="<?=$c['nome_pai']?>"></div>

                        <div class="col-md-3"><label class="form-label">Nascimento</label><input type="date" name="nascimento" class="form-control" value="<?=$c['data_nascimento']?>"></div>
                        <div class="col-md-3"><label class="form-label">Estado Civil</label>
                            <select name="estado_civil" class="form-select">
                                <option <?=$c['estado_civil']=='Solteiro(a)'?'selected':''?>>Solteiro(a)</option>
                                <option <?=$c['estado_civil']=='Casado(a)'?'selected':''?>>Casado(a)</option>
                                <option <?=$c['estado_civil']=='União Estável'?'selected':''?>>União Estável</option>
                                <option <?=$c['estado_civil']=='Divorciado(a)'?'selected':''?>>Divorciado(a)</option>
                                <option <?=$c['estado_civil']=='Viúvo(a)'?'selected':''?>>Viúvo(a)</option>
                            </select>
                        </div>
                        <div class="col-md-3"><label class="form-label">Gênero</label><select name="genero" class="form-select"><option <?=$c['genero']=='Masculino'?'selected':''?>>Masculino</option><option <?=$c['genero']=='Feminino'?'selected':''?>>Feminino</option></select></div>
                        <div class="col-md-3"><label class="form-label">Celular</label><input type="text" name="telefone" id="telefone" class="form-control" value="<?=$c['telefone']?>"></div>
                        <div class="col-md-12"><label class="form-label">E-mail</label><input type="email" name="email" class="form-control" value="<?=$c['email']?>" required></div>
                        
                        <!-- VAGA INTERNA -->
                        <div class="col-12 mt-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="sou_funcionario" id="sou_funcionario" onchange="toggleInterno()" <?=!empty($c['matricula'])?'checked':''?>><label class="form-check-label fw-bold text-primary">Sou Funcionário (Dados Internos)</label></div></div>
                        <div class="col-12 <?=!empty($c['matricula'])?'':'d-none'?>" id="box_interno"><div class="box-interno"><h6 class="fw-bold mb-3">Dados Corporativos</h6><div class="row g-3">
                            <div class="col-md-3"><label>Matrícula</label><input type="text" name="matricula" class="form-control border-primary" value="<?=$c['matricula']?>"></div>
                            <div class="col-md-5"><label>Unidade</label><select name="unidade_atual" class="form-select"><option value="">Selecione...</option><option <?=$c['unidade_atual']=='Sete Lagoas - Fábrica'?'selected':''?>>Sete Lagoas - Fábrica</option><option <?=$c['unidade_atual']=='Sete Lagoas - Escritório'?'selected':''?>>Sete Lagoas - Escritório</option><option <?=$c['unidade_atual']=='Belo Horizonte'?'selected':''?>>Belo Horizonte</option></select></div>
                            <div class="col-md-4"><label>Admissão</label><input type="date" name="data_admissao" class="form-control" value="<?=$c['data_admissao']?>"></div>
                            <div class="col-md-4"><label>Setor Atual</label><input type="text" name="setor_atual" class="form-control" value="<?=$c['setor_atual']?>"></div>
                            <div class="col-md-4"><label>Cargo Atual</label><input type="text" name="cargo_atual_interno" class="form-control" value="<?=$c['cargo_atual_interno']?>"></div>
                            <div class="col-md-4"><label>Gestor Imediato</label><input type="text" name="gestor_imediato" class="form-control" value="<?=$c['gestor_imediato']?>"></div>
                        </div></div></div>

                        <div class="col-12 mt-4"><h6 class="text-muted fw-bold">Endereço</h6></div>
                        <div class="col-md-3"><label>CEP</label><input type="text" name="cep" id="cep" class="form-control" value="<?=$c['cep']?>"></div>
                        <div class="col-md-7"><label>Rua</label><input type="text" name="endereco" id="rua" class="form-control" value="<?=$c['endereco']?>"></div>
                        <div class="col-md-2"><label>Número</label><input type="text" name="numero" class="form-control" value="<?=$c['numero_endereco']?>"></div>
                        <div class="col-md-4"><label>Bairro</label><input type="text" name="bairro" id="bairro" class="form-control" value="<?=$c['bairro']?>"></div>
                        <div class="col-md-6"><label>Cidade</label><input type="text" name="cidade" id="cidade" class="form-control bg-light" value="<?=$c['cidade']?>"></div>
                        <div class="col-md-2"><label>UF</label><input type="text" name="estado" id="estado" class="form-control bg-light" value="<?=$c['estado']?>"></div>
                        <div class="col-md-12"><label>Complemento</label><input type="text" name="complemento" class="form-control" value="<?=$c['complemento']?>"></div>
                    </div>
                </div>

                <!-- ABA 2: FORMAÇÃO -->
                <div class="tab-pane fade" id="formacao">
                    <div class="card bg-light border-0 shadow-sm p-4 mb-4">
                        <div class="row g-3">
                            <div class="col-md-4"><label>Nível</label><select id="edu_nivel" class="form-select"><option>Ensino Médio</option><option>Superior</option><option>Pós</option></select></div>
                            <div class="col-md-8"><label>Instituição</label><input type="text" id="edu_inst" class="form-control"></div>
                            <div class="col-md-4"><label>Curso</label><input type="text" id="edu_curso" class="form-control"></div>
                            <div class="col-md-4"><label>Situação</label><select id="edu_status" class="form-select"><option>Concluído</option><option>Cursando</option></select></div>
                            <div class="col-md-2"><label>Início</label><input type="number" id="edu_ini" class="form-control"></div>
                            <div class="col-md-2"><label>Fim</label><input type="number" id="edu_fim" class="form-control"></div>
                            <div class="col-12 text-end"><div class="form-check d-inline-block me-3"><input class="form-check-input" type="checkbox" id="edu_atual"><label>Cursando</label></div><button type="button" class="btn btn-add" onclick="addFormacao()">Adicionar</button></div>
                        </div>
                    </div>
                    <div id="lista-formacao-container"></div>
                    <input type="hidden" name="lista_formacoes_json" id="lista_formacoes_json">
                </div>

                <!-- ABA 3: EXPERIÊNCIA -->
                <div class="tab-pane fade" id="exp">
                    <div class="row g-3 mb-4">
                        <div class="col-md-12"><label class="fw-bold">Área de Interesse</label><select name="area_interesse" class="form-select"><option selected><?=$c['area_interesse']?></option><option>Produção</option><option>Adm</option><option>TI</option></select></div>
                        <div class="col-md-4"><label>Pretensão</label><input type="text" name="pretensao" id="dinheiro" class="form-control" value="<?=$c['pretensao_salarial']?>"></div>
                        <div class="col-md-4"><label>Inglês</label><select name="ingles" class="form-select"><option selected><?=$c['nivel_ingles']?></option><option>Básico</option><option>Avançado</option></select></div>
                        <div class="col-md-4"><label>Espanhol</label><select name="espanhol" class="form-select"><option selected><?=$c['nivel_espanhol']?></option><option>Básico</option><option>Avançado</option></select></div>
                    </div>
                    
                    <div class="card bg-light border-0 shadow-sm p-4 mb-4">
                        <div class="row g-3">
                            <div class="col-md-6"><label>Empresa</label><input type="text" id="exp_empresa" class="form-control"></div>
                            <div class="col-md-6"><label>Cargo</label><input type="text" id="exp_cargo" class="form-control"></div>
                            <div class="col-md-3"><label>Início</label><input type="date" id="exp_ini" class="form-control"></div>
                            <div class="col-md-3"><label>Fim</label><input type="date" id="exp_fim" class="form-control"></div>
                            <div class="col-md-12"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="exp_atual"><label>Atual</label></div><label>Atividades</label><textarea id="exp_desc" class="form-control" rows="2"></textarea></div>
                            <div class="col-md-12 text-end"><button type="button" class="btn btn-add" onclick="addExp()">Adicionar</button></div>
                        </div>
                    </div>
                    <div id="lista-exp-container"></div>
                    <input type="hidden" name="lista_experiencias_json" id="lista_experiencias_json">
                    
                    <div class="mt-4"><label>Resumo / Cursos Extras</label><textarea name="resumo_profissional" class="form-control" rows="3"><?=$c['resumo_profissional']?></textarea></div>
                </div>

                <!-- ABA 4: CURRÍCULO -->
                <div class="tab-pane fade" id="anexo">
                    <div class="col-md-6 offset-md-3 text-center py-4">
                        <h5>Atualizar Currículo</h5>
                        <?php if($c['arquivo_curriculo']): ?><p class="text-success small"><i class="fas fa-check"></i> Você já possui um CV cadastrado. Envie outro apenas se quiser substituir.</p><?php endif; ?>
                        <div class="card border-dashed p-4 mb-3"><input type="file" name="cv" class="form-control form-control-lg"></div>
                        <button type="submit" class="btn btn-success btn-lg w-100 shadow mt-2">SALVAR ALTERAÇÕES</button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Inicializa arrays com dados do banco (PHP -> JS)
        let listaFormacao = <?php echo $json_formacoes ?: '[]'; ?>;
        let listaExp = <?php echo $json_experiencias ?: '[]'; ?>;

        // Renderiza ao carregar
        $(document).ready(function(){
            renderFormacao();
            renderExp();
            $('#cpf').mask('000.000.000-00'); $('#cep').mask('00000-000'); $('#telefone').mask('(00) 00000-0000'); $('#dinheiro').mask('R$ 000.000.000,00', {reverse: true});
            $("#cep").blur(function() {
                var c = $(this).val().replace(/\D/g, '');
                if(c){ $.getJSON("https://viacep.com.br/ws/"+c+"/json/?callback=?", function(d){ if(!("erro" in d)) { $("#rua").val(d.logradouro); $("#bairro").val(d.bairro); $("#cidade").val(d.localidade); $("#estado").val(d.uf); } }); }
            });
        });

        function toggleInterno() { if($('#sou_funcionario').is(':checked')) $('#box_interno').removeClass('d-none'); else $('#box_interno').addClass('d-none'); }

        // Lógica de Grids (Idêntica ao cadastro.php)
        function toggleEduFim() { let chk = document.getElementById('edu_atual').checked; if(chk) { $('#edu_fim').val('').prop('disabled', true); } else { $('#edu_fim').prop('disabled', false); } }
        function addFormacao() {
            let nivel = $('#edu_nivel').val(); let inst = $('#edu_inst').val();
            if(!nivel || !inst) return alert('Preencha Nível e Instituição');
            let item = { nivel: nivel, instituicao: inst, curso: $('#edu_curso').val(), status: $('#edu_status').val(), inicio: $('#edu_ini').val(), conclusao: $('#edu_atual').is(':checked') ? 'Atual' : $('#edu_fim').val() };
            listaFormacao.push(item); renderFormacao();
            $('#edu_inst').val(''); $('#edu_curso').val(''); $('#edu_ini').val(''); $('#edu_fim').val('');
        }
        function renderFormacao() {
            if(listaFormacao.length === 0) { $('#lista-formacao-container').html('<div class="exp-empty text-center text-muted">Nenhuma formação cadastrada.</div>'); return; }
            let html = listaFormacao.map((i,x)=>`
                <div class="exp-card" style="border-left: 4px solid #6610f2; background:#f8f9fa; padding:10px; margin-bottom:5px; border-radius:4px; position:relative;">
                    <i class="fas fa-trash trash-btn" style="position:absolute; right:10px; cursor:pointer;" onclick="listaFormacao.splice(${x},1);renderFormacao()"></i>
                    <h6 style="margin:0;">${i.nivel} - ${i.curso}</h6><small>${i.instituicao}</small>
                </div>`).join('');
            $('#lista-formacao-container').html(html);
            $('#lista_formacoes_json').val(JSON.stringify(listaFormacao));
        }

        function addExp() {
            let item = { empresa: $('#exp_empresa').val(), cargo: $('#exp_cargo').val(), inicio: $('#exp_ini').val(), fim: $('#exp_fim').val(), atual: $('#exp_atual').is(':checked')?1:0, descricao: $('#exp_desc').val() };
            if(!item.empresa) return alert('Informe a Empresa.');
            listaExp.push(item); renderExp();
            $('#exp_empresa').val(''); $('#exp_desc').val('');
        }
        function renderExp() {
            if(listaExp.length === 0) { $('#lista-exp-container').html('<div class="exp-empty text-center text-muted">Nenhuma experiência cadastrada.</div>'); return; }
            let html = listaExp.map((i,x)=>`
                <div class="exp-card" style="border-left: 4px solid #000; background:#f8f9fa; padding:10px; margin-bottom:5px; border-radius:4px; position:relative;">
                    <i class="fas fa-trash trash-btn" style="position:absolute; right:10px; cursor:pointer;" onclick="listaExp.splice(${x},1);renderExp()"></i>
                    <h6 style="margin:0;">${i.empresa} (${i.cargo})</h6><small>${i.atual?'Atual':i.inicio}</small>
                </div>`).join('');
            $('#lista-exp-container').html(html);
            $('#lista_experiencias_json').val(JSON.stringify(listaExp));
        }
    </script>
</body>
</html>