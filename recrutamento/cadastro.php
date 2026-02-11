<?php
// --- CONFIGURAÇÃO ---
ini_set('display_errors', 0); // Oculta erros na tela em produção
error_reporting(E_ALL);

require_once 'includes/db.php';
require_once 'includes/validacoes.php';
require_once 'includes/email.php';

$mensagem = "";
$erro = "";
$senha_gerada = "";
$vaga_id = $_GET['vaga_id'] ?? null;
$vaga_titulo = "Banco de Talentos";

// Busca Título da Vaga
if ($vaga_id) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT titulo FROM vagas WHERE id = ?");
        $stmt->execute([$vaga_id]);
        $res = $stmt->fetch();
        if($res) $vaga_titulo = $res['titulo'];
    } catch (Exception $e) { /* Silêncio */ }
}

// --- PROCESSAMENTO DO CADASTRO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = getDB();
        
        // 1. Validação CPF
        $cpfLimpo = Validador::limpar($_POST['cpf']);
        if (!Validador::validarCPF($cpfLimpo)) throw new Exception("CPF inválido.");
        
        // Verifica duplicidade
        $stmtCheck = $pdo->prepare("SELECT id FROM candidatos WHERE cpf = ?");
        $stmtCheck->execute([$cpfLimpo]);
        if ($stmtCheck->rowCount() > 0) throw new Exception("CPF já cadastrado. Tente fazer login.");

        $pdo->beginTransaction();

        // 2. Upload Currículo
        $caminhoBanco = null;
        if (isset($_FILES['cv']) && $_FILES['cv']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['cv']['name'], PATHINFO_EXTENSION));
            $nomePasta = Validador::slugPasta($_POST['cpf'], $_POST['nome']);
            $caminhoPasta = __DIR__ . '/uploads/' . $nomePasta;
            
            if (!is_dir($caminhoPasta)) mkdir($caminhoPasta, 0755, true);
            
            $nomeArquivo = "CV_" . date('Ymd_His') . "." . $ext;
            if(move_uploaded_file($_FILES['cv']['tmp_name'], $caminhoPasta . '/' . $nomeArquivo)) {
                $caminhoBanco = $nomePasta . '/' . $nomeArquivo;
            } else {
                throw new Exception("Erro ao salvar arquivo na pasta.");
            }
        } else {
            throw new Exception("O anexo do Currículo é obrigatório.");
        }

        // 3. Preparação dos Dados
        $senha_plain = substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyz"), 0, 6);
        $senha_hash = password_hash($senha_plain, PASSWORD_DEFAULT);
        $senha_gerada = $senha_plain;

        // Formata CNH
        $cnhCompleta = ($_POST['possui_cnh'] == 'Sim') ? ($_POST['cnh_cat'] ?? '') . " - " . ($_POST['cnh_num'] ?? '') : "Não";
        
        // Tratamento de campos opcionais
        $matricula = ($_POST['sou_funcionario'] ?? 0) ? $_POST['matricula'] : null;
        $unidade   = ($_POST['sou_funcionario'] ?? 0) ? $_POST['unidade_atual'] : null;
        $setor     = ($_POST['sou_funcionario'] ?? 0) ? $_POST['setor_atual'] : null;
        $cargo_int = ($_POST['sou_funcionario'] ?? 0) ? $_POST['cargo_atual_interno'] : null;
        $gestor    = ($_POST['sou_funcionario'] ?? 0) ? $_POST['gestor_imediato'] : null;
        $admissao  = ($_POST['sou_funcionario'] ?? 0) ? $_POST['data_admissao'] : null;
        $ciente    = isset($_POST['ciente_gestor']) ? 1 : 0;

        $resumo = $_POST['resumo_profissional'] ?? '';
        $ingles   = $_POST['ingles'] ?? 'Não informado';
        $espanhol = $_POST['espanhol'] ?? 'Não informado';
        $area   = $_POST['area_interesse'] ?? 'Geral';
        $pretensao = $_POST['pretensao'] ?? '';

        $sql = "INSERT INTO candidatos (
            nome, cpf, rg, matricula, unidade_atual, setor_atual, cargo_atual_interno, gestor_imediato, data_admissao, ciente_gestor,
            nome_pai, nome_mae, email, senha, telefone, data_nascimento, estado_civil, genero,
            cep, endereco, numero_endereco, complemento, bairro, cidade, estado,
            nivel_ingles, nivel_espanhol, area_interesse, pretensao_salarial, cnh,
            resumo_profissional, arquivo_curriculo
        ) VALUES (
            :nome, :cpf, :rg, :mat, :unid, :setor, :cargoint, :gestor, :adm, :ciente,
            :pai, :mae, :email, :senha, :tel, :nasc, :civil, :genero,
            :cep, :end, :num, :comp, :bairro, :cidade, :uf,
            :ingles, :esp, :area, :salario, :cnh,
            :resumo, :arq
        )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':nome' => $_POST['nome'],
            ':cpf' => $cpfLimpo,
            ':rg' => $_POST['rg'] ?? '',
            ':mat' => $matricula, ':unid' => $unidade, ':setor' => $setor, ':cargoint' => $cargo_int, ':gestor' => $gestor, ':adm' => $admissao, ':ciente' => $ciente,
            ':pai' => $_POST['nome_pai'] ?? '',
            ':mae' => $_POST['nome_mae'] ?? '',
            ':email' => $_POST['email'],
            ':senha' => $senha_hash,
            ':tel' => $_POST['telefone'],
            ':nasc' => $_POST['nascimento'],
            ':civil' => $_POST['estado_civil'],
            ':genero' => $_POST['genero'],
            ':cep' => Validador::limpar($_POST['cep']),
            ':end' => $_POST['endereco'],
            ':num' => $_POST['numero'],
            ':comp' => $_POST['complemento'],
            ':bairro' => $_POST['bairro'],
            ':cidade' => $_POST['cidade'],
            ':uf' => $_POST['estado'],
            ':ingles' => $ingles,
            ':esp' => $espanhol,
            ':area' => $area,
            ':salario' => $pretensao,
            ':cnh' => $cnhCompleta,
            ':resumo' => $resumo,
            ':arq' => $caminhoBanco
        ]);
        $candidatoId = $pdo->lastInsertId();

        // 4. Salva Formações
        $formacoes = json_decode($_POST['lista_formacoes_json'] ?? '[]', true);
        if (is_array($formacoes)) {
            $stmtForm = $pdo->prepare("INSERT INTO formacoes_academicas (candidato_id, nivel, instituicao, curso, status, data_inicio, data_conclusao) VALUES (?,?,?,?,?,?,?)");
            foreach ($formacoes as $f) {
                $stmtForm->execute([$candidatoId, $f['nivel'], $f['instituicao'], $f['curso'], $f['status'], $f['inicio'], $f['conclusao']]);
            }
        }

        // 5. Salva Experiências
        $experiencias = json_decode($_POST['lista_experiencias_json'] ?? '[]', true);
        if (is_array($experiencias)) {
            $stmtExp = $pdo->prepare("INSERT INTO experiencias (candidato_id, empresa, cargo, inicio, fim, atual, descricao) VALUES (?,?,?,?,?,?,?)");
            foreach ($experiencias as $e) {
                $stmtExp->execute([$candidatoId, $e['empresa'], $e['cargo'], $e['inicio'], ($e['atual']?null:$e['fim']), $e['atual'], $e['descricao']]);
            }
        }

        // 6. Aplicação na Vaga
        if ($vaga_id) {
            $pdo->prepare("INSERT INTO aplicacoes (vaga_id, candidato_id) VALUES (?, ?)")->execute([$vaga_id, $candidatoId]);
        }

        $pdo->commit();
        
        // 7. Envio SMTP
        $mailer = new EmailHelper();
        $assunto = "Cadastro Realizado - RH Cedro";
        $corpoHTML = "
        <div style='font-family: Arial, sans-serif; color: #333;'>
            <h2 style='color: #000;'>Bem-vindo(a) à Cedro Têxtil</h2>
            <p>Olá <strong>" . $_POST['nome'] . "</strong>,</p>
            <p>Recebemos seu currículo com sucesso.</p>
            <div style='background: #f4f4f4; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                <strong>Seus Dados de Acesso:</strong><br>
                Login: $cpfLimpo<br>
                Senha: $senha_plain
            </div>
            <p><small>Este é um e-mail automático.</small></p>
        </div>";
        
        $envio = $mailer->enviar($_POST['email'], $_POST['nome'], $assunto, $corpoHTML);

        if($envio) {
            $mensagem = "Cadastro realizado! Um e-mail de confirmação foi enviado.";
        } else {
            $mensagem = "Cadastro realizado com sucesso. (E-mail não pôde ser enviado, use a senha abaixo).";
        }

    } catch (Exception $e) {
        if(isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $erro = "Erro: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro - <?php echo htmlspecialchars($vaga_titulo); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>

    <style>
        body { background: #f4f7f6; font-family: 'Segoe UI', sans-serif; padding-bottom: 50px; }
        .bg-cedro { background-color: #000; }
        
        .navbar-nav .nav-link { color: rgba(255,255,255,0.8); margin-left: 15px; transition: 0.3s; }
        .navbar-nav .nav-link:hover { color: #fff; transform: translateY(-2px); }
        .nav-pills .nav-link { color: #6c757d; font-weight: 600; padding: 15px; border-bottom: 3px solid #dee2e6; border-radius: 0; background: #fff; }
        .nav-pills .nav-link:hover { background: #f8f9fa; }
        .nav-pills .nav-link.active { color: #000; background: #fff; border-bottom: 3px solid #000; }
        .nav-pills .nav-link i { margin-right: 8px; }
        
        .tab-content { background: #fff; padding: 30px; border-radius: 0 0 8px 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); min-height: 400px;}
        .btn-add { background: #000; color: #fff; border: none; font-size: 0.9rem; padding: 8px 20px; border-radius: 20px; transition: 0.2s;}
        .btn-add:hover { background: #333; transform: scale(1.05); }
        .trash-btn { cursor: pointer; color: #dc3545; font-size: 1.2rem; }
        .trash-btn:hover { color: #a00; }
        .section-title { border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 20px; color: #000; font-weight: bold; }
        
        /* Estilo Interno */
        .box-interno { border: 1px solid #007bff; background-color: #f0f8ff; padding: 20px; border-radius: 8px; margin-top: 15px; }
        .box-interno label { color: #0056b3; }

        /* Estilo para Cards de Experiência (Timeline Visual) */
        .exp-card { 
            border-left: 4px solid #000; 
            background: #f8f9fa; 
            padding: 15px; 
            margin-bottom: 10px; 
            border-radius: 4px;
            position: relative;
        }
        .exp-card h6 { margin-bottom: 2px; font-weight: bold; color: #000; }
        .exp-card .badge { font-weight: normal; margin-left: 5px; }
        .exp-card p { font-size: 0.9rem; color: #555; margin-bottom: 0; white-space: pre-line; }
        .exp-card .btn-del { position: absolute; top: 10px; right: 10px; }
        .exp-empty { text-align: center; color: #999; padding: 20px; font-style: italic; border: 2px dashed #ddd; border-radius: 8px; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-cedro sticky-top">
        <div class="container">
            <a class="navbar-brand" href="index.php"><img src="logo-branco.png" height="30" alt="Cedro"></a>
            <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item"><a class="nav-link" href="vagas.php"><i class="fas fa-briefcase"></i> Vagas</a></li>
                    <li class="nav-item"><a class="nav-link" href="index.php"><i class="fas fa-sign-in-alt"></i> Login</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4">

        <?php if ($mensagem): ?>
            <div class="alert alert-success text-center p-5 shadow">
                <i class="fas fa-check-circle fa-4x mb-3 text-success"></i>
                <h2 class="fw-bold">Cadastro Realizado!</h2>
                <p class="lead"><?php echo $mensagem; ?></p>
                <?php if($senha_gerada): ?>
                    <div class="alert alert-light border mt-3 d-inline-block text-start">
                        <strong>Seus dados de acesso:</strong><br>
                        Login: <strong><?php echo Validador::limpar($_POST['cpf']); ?></strong><br>
                        Senha: <strong><?php echo $senha_gerada; ?></strong>
                    </div>
                <?php endif; ?>
                <div class="mt-4"><a href="index.php" class="btn btn-dark">Fazer Login</a></div>
            </div>
            <?php exit; ?>
        <?php endif; ?>

        <?php if ($erro): ?>
            <div class="alert alert-danger shadow"><i class="fas fa-exclamation-triangle"></i> <?php echo $erro; ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="mainForm">
            <!-- ABAS -->
            <ul class="nav nav-pills nav-fill bg-white shadow-sm rounded-top" id="myTab" role="tablist">
                <li class="nav-item"><button class="nav-link active" id="pessoal-tab" data-bs-toggle="pill" data-bs-target="#pessoal" type="button"><i class="fas fa-user"></i> Dados Pessoais</button></li>
                <li class="nav-item"><button class="nav-link" id="formacao-tab" data-bs-toggle="pill" data-bs-target="#formacao" type="button"><i class="fas fa-graduation-cap"></i> Formação</button></li>
                <li class="nav-item"><button class="nav-link" id="exp-tab" data-bs-toggle="pill" data-bs-target="#exp" type="button"><i class="fas fa-briefcase"></i> Experiência</button></li>
                <li class="nav-item"><button class="nav-link" id="anexo-tab" data-bs-toggle="pill" data-bs-target="#anexo" type="button"><i class="fas fa-file-upload"></i> Finalizar</button></li>
            </ul>

            <div class="tab-content shadow-sm">
                
                <!-- ABA 1: DADOS PESSOAIS -->
                <div class="tab-pane fade show active" id="pessoal">
                    <h5 class="section-title">Informações Básicas</h5>
                    <div class="row g-3">
                        <div class="col-md-3"><label class="form-label">CPF *</label><input type="text" name="cpf" id="cpf" class="form-control" placeholder="000.000.000-00"></div>
                        <div class="col-md-3"><label class="form-label">RG *</label><input type="text" name="rg" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label">Nome Completo *</label><input type="text" name="nome" id="nome" class="form-control"></div>
                        
                        <!-- PAIS -->
                        <div class="col-md-6"><label class="form-label">Nome da Mãe</label><input type="text" name="nome_mae" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label">Nome do Pai</label><input type="text" name="nome_pai" class="form-control"></div>

                        <div class="col-md-3"><label class="form-label">Nascimento *</label><input type="date" name="nascimento" id="nascimento" class="form-control"></div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Estado Civil</label>
                            <select name="estado_civil" class="form-select">
                                <option>Solteiro(a)</option>
                                <option>Casado(a)</option>
                                <option>União Estável</option>
                                <option>Divorciado(a)</option>
                                <option>Separado(a)</option>
                                <option>Viúvo(a)</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3"><label class="form-label">Gênero</label><select name="genero" class="form-select"><option>Masculino</option><option>Feminino</option><option>Outro</option></select></div>
                        <div class="col-md-3"><label class="form-label">Celular *</label><input type="text" name="telefone" id="telefone" class="form-control"></div>
                        <div class="col-md-9"><label class="form-label">E-mail *</label><input type="email" name="email" id="email" class="form-control"></div>
                        
                        <!-- ÁREA RECRUTAMENTO INTERNO -->
                        <div class="col-12 mt-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="sou_funcionario" id="sou_funcionario" onchange="toggleInterno()">
                                <label class="form-check-label fw-bold text-primary">Sou Funcionário (Vaga Interna)</label>
                            </div>
                        </div>
                        
                        <div class="col-12 d-none" id="box_interno">
                            <div class="box-interno">
                                <h6 class="fw-bold mb-3"><i class="fas fa-id-badge"></i> Dados Corporativos (Interno)</h6>
                                <div class="row g-3">
                                    <div class="col-md-3"><label>Matrícula *</label><input type="text" name="matricula" class="form-control border-primary"></div>
                                    <div class="col-md-5"><label>Unidade Atual *</label><select name="unidade_atual" class="form-select"><option value="">Selecione...</option><option>Sete Lagoas - Fábrica</option><option>Sete Lagoas - Escritório</option><option>Belo Horizonte</option><option>Outra</option></select></div>
                                    <div class="col-md-4"><label>Data de Admissão *</label><input type="date" name="data_admissao" class="form-control"></div>
                                    <div class="col-md-4"><label>Setor Atual *</label><input type="text" name="setor_atual" class="form-control" placeholder="Ex: Fiação"></div>
                                    <div class="col-md-4"><label>Cargo Atual *</label><input type="text" name="cargo_atual_interno" class="form-control" placeholder="Ex: Operador II"></div>
                                    <div class="col-md-4"><label>Gestor Imediato *</label><input type="text" name="gestor_imediato" class="form-control"></div>
                                    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="ciente_gestor" id="ciente_gestor"><label class="form-check-label small">Estou ciente de que meu gestor será comunicado.</label></div></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 mt-4"><h6 class="text-muted fw-bold">Endereço</h6></div>
                        <div class="col-md-3"><label class="form-label">CEP</label><input type="text" name="cep" id="cep" class="form-control"></div>
                        <div class="col-md-7"><label class="form-label">Rua</label><input type="text" name="endereco" id="rua" class="form-control"></div>
                        <div class="col-md-2"><label class="form-label">Número</label><input type="text" name="numero" id="numero" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">Bairro</label><input type="text" name="bairro" id="bairro" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label">Cidade</label><input type="text" name="cidade" id="cidade" class="form-control bg-light"></div>
                        <div class="col-md-2"><label class="form-label">UF</label><input type="text" name="estado" id="estado" class="form-control bg-light"></div>
                        <div class="col-md-12"><label class="form-label">Complemento</label><input type="text" name="complemento" class="form-control"></div>

                        <div class="col-12 mt-4"><h6 class="text-muted fw-bold">Habilitação</h6></div>
                        <div class="col-md-3">
                            <label class="form-label">Possui CNH?</label>
                            <select name="possui_cnh" id="possui_cnh" class="form-select" onchange="toggleCNH()">
                                <option value="Não">Não</option><option value="Sim">Sim</option>
                            </select>
                        </div>
                        <div class="col-md-4 d-none cnh-box">
                            <label class="form-label">Categoria</label>
                            <select name="cnh_cat" class="form-select">
                                <option value="A">A - Moto</option><option value="B">B - Carro</option><option value="AB">AB - Moto e Carro</option><option value="C">C - Caminhão</option><option value="AC">AC</option><option value="D">D - Ônibus</option><option value="AD">AD</option><option value="E">E - Carreta</option><option value="AE">AE</option>
                            </select>
                        </div>
                        <div class="col-md-4 d-none cnh-box"><label class="form-label">Registro</label><input type="text" name="cnh_num" class="form-control"></div>
                    </div>
                    <div class="text-end mt-4"><button type="button" class="btn btn-dark" onclick="switchTab('#formacao-tab')">Próximo <i class="fas fa-arrow-right"></i></button></div>
                </div>

                <!-- ABA 2: FORMAÇÃO -->
                <div class="tab-pane fade" id="formacao">
                    <h5 class="section-title">Histórico Acadêmico</h5>
                    <div class="card bg-light border-0 shadow-sm p-4 mb-4">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Nível de Escolaridade</label>
                                <select id="edu_nivel" class="form-select">
                                    <option value="">Selecione...</option>
                                    <option>Ensino Fundamental Incompleto</option><option>Ensino Fundamental Completo</option>
                                    <option>Ensino Médio Incompleto</option><option>Ensino Médio Completo</option>
                                    <option>Ensino Técnico</option>
                                    <option>Ensino Superior (Graduação)</option>
                                    <option>Pós-Graduação / MBA</option><option>Mestrado</option><option>Doutorado</option>
                                </select>
                            </div>
                            <div class="col-md-8"><label class="form-label">Instituição / Escola</label><input type="text" id="edu_inst" class="form-control" placeholder="Ex: SENAI, PUC..."></div>
                            <div class="col-md-4"><label class="form-label">Nome do Curso</label><input type="text" id="edu_curso" class="form-control" placeholder="Ex: Engenharia"></div>
                            <div class="col-md-4"><label class="form-label">Situação</label><select id="edu_status" class="form-select"><option>Concluído</option><option>Cursando</option><option>Trancado</option><option>Incompleto</option></select></div>
                            <div class="col-md-2"><label class="form-label">Ano Início</label><input type="number" id="edu_ini" class="form-control" placeholder="2020"></div>
                            <div class="col-md-2"><label class="form-label">Ano Fim</label><input type="number" id="edu_fim" class="form-control" placeholder="2024"></div>
                            <div class="col-md-12 d-flex justify-content-between align-items-center">
                                <div class="form-check"><input class="form-check-input" type="checkbox" id="edu_atual" onchange="toggleEduFim()"><label class="form-check-label small fw-bold" for="edu_atual">Cursando Atualmente</label></div>
                                <button type="button" class="btn btn-add" onclick="addFormacao()"><i class="fas fa-plus-circle me-2"></i> Adicionar Curso</button>
                            </div>
                        </div>
                    </div>
                    <div id="lista-formacao-container"></div> <!-- VISUAL CARD -->
                    <input type="hidden" name="lista_formacoes_json" id="lista_formacoes_json">
                    <div class="text-end mt-4"><button type="button" class="btn btn-light border me-2" onclick="switchTab('#pessoal-tab')"><i class="fas fa-arrow-left"></i> Voltar</button><button type="button" class="btn btn-dark" onclick="switchTab('#exp-tab')">Próximo <i class="fas fa-arrow-right"></i></button></div>
                </div>

                <!-- ABA 3: EXPERIÊNCIA (REORGANIZADA) -->
                <div class="tab-pane fade" id="exp">
                    
                    <!-- 1. OBJETIVO E IDIOMAS (MOVIDOS PARA O TOPO) -->
                    <h5 class="section-title">Objetivo e Qualificações</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Área de Interesse</label>
                            <select name="area_interesse" class="form-select">
                                <option value="">Selecione...</option>
                                <optgroup label="Produção Industrial"><option>Operador de Produção</option><option>Auxiliar de Produção</option><option>Líder de Produção</option><option>Engenharia</option></optgroup>
                                <optgroup label="Manutenção & Técnica"><option>Mecânico</option><option>Eletricista</option><option>Ferramentaria</option></optgroup>
                                <optgroup label="Qualidade & Apoio"><option>Analista de Qualidade</option><option>Segurança do Trabalho</option><option>Logística</option></optgroup>
                                <optgroup label="Administrativo"><option>RH</option><option>Financeiro</option><option>TI</option><option>Comercial</option></optgroup>
                            </select>
                        </div>
                        <div class="col-md-4"><label class="form-label">Pretensão Salarial</label><input type="text" name="pretensao" id="dinheiro" class="form-control" placeholder="R$ 0,00"></div>
                        <div class="col-md-4">
                            <label class="form-label">Nível de Inglês</label>
                            <select name="ingles" class="form-select"><option value="Não possuo">Não possuo</option><option value="Básico">Básico</option><option value="Intermediário">Intermediário</option><option value="Avançado">Avançado</option><option value="Fluente">Fluente</option></select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Nível de Espanhol</label>
                            <select name="espanhol" class="form-select"><option value="Não possuo">Não possuo</option><option value="Básico">Básico</option><option value="Intermediário">Intermediário</option><option value="Avançado">Avançado</option><option value="Fluente">Fluente</option></select>
                        </div>
                    </div>

                    <!-- 2. HISTÓRICO PROFISSIONAL -->
                    <h5 class="section-title mt-5">Histórico Profissional</h5>
                    
                    <div class="alert alert-secondary border-0 mb-4 d-flex align-items-center"><div class="form-check"><input class="form-check-input" type="checkbox" id="sem_experiencia" onchange="toggleSemExp()"><label class="form-check-label fw-bold">Não possuo experiência profissional (Primeiro Emprego)</label></div></div>

                    <div id="box_exp_form">
                        <div class="card bg-light border-0 shadow-sm p-4 mb-4">
                            <h6 class="mb-3 text-muted">Adicionar Nova Experiência</h6>
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label">Empresa</label><input type="text" id="exp_empresa" class="form-control"></div>
                                <div class="col-md-6"><label class="form-label">Cargo</label><input type="text" id="exp_cargo" class="form-control"></div>
                                <div class="col-md-3"><label class="form-label">Início</label><input type="date" id="exp_ini" class="form-control"></div>
                                <div class="col-md-3"><label class="form-label">Fim</label><input type="date" id="exp_fim" class="form-control"></div>
                                <div class="col-md-12">
                                    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="exp_atual" onchange="toggleExpFim()"><label class="form-check-label small fw-bold">Trabalho Atual</label></div>
                                    <label class="form-label">Atividades Realizadas</label><textarea id="exp_desc" class="form-control" rows="2"></textarea>
                                </div>
                                <div class="col-md-12 text-end"><button type="button" class="btn btn-add" onclick="addExp()">+ Adicionar ao Histórico</button></div>
                            </div>
                        </div>
                    </div>

                    <!-- VISUAL GRID CARDS (MUDANÇA AQUI) -->
                    <div id="lista-exp-container" class="mb-4">
                        <div class="exp-empty">Nenhuma experiência adicionada ainda.</div>
                    </div>
                    
                    <input type="hidden" name="lista_experiencias_json" id="lista_experiencias_json">
                    
                    <div class="mt-4"><label class="form-label">Observações / Resumo Profissional / Cursos Extras</label><textarea name="resumo_profissional" id="resumo_profissional" class="form-control" rows="3"></textarea></div>
                    <div class="text-end mt-4"><button type="button" class="btn btn-light border me-2" onclick="switchTab('#formacao-tab')">Voltar</button><button type="button" class="btn btn-dark" onclick="switchTab('#anexo-tab')">Próximo <i class="fas fa-arrow-right"></i></button></div>
                </div>

                <!-- ABA 4: FINALIZAR -->
                <div class="tab-pane fade" id="anexo">
                    <div class="col-md-6 offset-md-3 text-center py-4">
                        <h5 class="mb-3">Anexar Currículo</h5>
                        <div class="card border-dashed p-4 mb-3">
                            <input type="file" name="cv" id="cv" class="form-control form-control-lg">
                            <small class="text-muted mt-2 d-block">PDF ou DOCX</small>
                        </div>
                        <button type="button" class="btn btn-success btn-lg w-100 shadow" onclick="validarTudo()">FINALIZAR E ENVIAR</button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- MODAIS -->
    <div class="modal fade" id="modalSaving" data-bs-backdrop="static"><div class="modal-dialog modal-dialog-centered"><div class="modal-content text-center p-5"><div class="spinner-border text-success mb-3"></div><h5>Salvando...</h5></div></div></div>
    <div class="modal fade" id="modalCepLoading" data-bs-backdrop="static"><div class="modal-dialog modal-dialog-centered modal-sm"><div class="modal-content text-center p-4"><div class="spinner-border text-primary mb-3"></div><p class="mb-0">Buscando...</p></div></div></div>
    <div class="modal fade" id="modalErro"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-danger text-white"><h5>Atenção</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><ul id="listaErros" class="text-danger fw-bold"></ul></div></div></div></div>

    <script>
        function switchTab(sel) { document.querySelector(sel).click(); window.scrollTo(0,0); }
        function validarTudo() {
            let erros = [];
            if(!$('#cpf').val()) erros.push("CPF"); if(!$('#nome').val()) erros.push("Nome");
            if($('#sou_funcionario').is(':checked')) { if(!$('input[name="matricula"]').val()) erros.push("Matrícula"); if(!$('input[name="data_admissao"]').val()) erros.push("Data Admissão"); }
            if(!$('[name="area_interesse"]').val()) erros.push("Área de Interesse"); if(!$('#cv').val()) erros.push("Currículo");
            if(erros.length > 0) { $('#listaErros').html(erros.map(e=>`<li>${e}</li>`).join('')); new bootstrap.Modal('#modalErro').show(); } 
            else { new bootstrap.Modal('#modalSaving').show(); document.getElementById('mainForm').submit(); }
        }

        // Toggles
        function toggleCNH() { if($('#possui_cnh').val()=='Sim') $('.cnh-box').removeClass('d-none'); else $('.cnh-box').addClass('d-none'); }
        function toggleInterno() { if($('#sou_funcionario').is(':checked')) $('#box_interno').removeClass('d-none'); else $('#box_interno').addClass('d-none'); }
        function toggleSemExp() {
            let checked = document.getElementById('sem_experiencia').checked;
            if(checked) { $('#box_exp_form').addClass('d-none'); listaExp = []; renderExp(); $('#resumo_profissional').val('Primeiro Emprego.'); } 
            else { $('#box_exp_form').removeClass('d-none'); $('#resumo_profissional').val(''); }
        }

        // GRID FORMAÇÃO (Visual Card)
        let listaFormacao = [];
        function toggleEduFim() { let chk = document.getElementById('edu_atual').checked; if(chk) $('#edu_fim').val('').prop('disabled', true); else $('#edu_fim').prop('disabled', false); }
        function addFormacao() {
            let nivel = $('#edu_nivel').val(); let inst = $('#edu_inst').val();
            if(!nivel || !inst) return alert('Preencha Nível e Instituição');
            let item = { nivel: nivel, instituicao: inst, curso: $('#edu_curso').val(), status: $('#edu_status').val(), inicio: $('#edu_ini').val(), conclusao: $('#edu_atual').is(':checked') ? 'Atual' : $('#edu_fim').val() };
            listaFormacao.push(item); renderFormacao();
            $('#edu_inst').val(''); $('#edu_curso').val(''); $('#edu_ini').val(''); $('#edu_fim').val('');
        }
        function renderFormacao() {
            if(listaFormacao.length === 0) { $('#lista-formacao-container').html('<div class="exp-empty">Nenhum curso adicionado.</div>'); return; }
            let html = listaFormacao.map((i,x)=>`
                <div class="exp-card" style="border-left-color: #6610f2;">
                    <i class="fas fa-trash trash-btn btn-del" onclick="listaFormacao.splice(${x},1);renderFormacao()"></i>
                    <h6>${i.nivel} - ${i.curso}</h6>
                    <p class="small text-muted mb-1">${i.instituicao}</p>
                    <span class="badge bg-secondary">${i.status}</span> <small>(${i.inicio} - ${i.conclusao})</small>
                </div>
            `).join('');
            $('#lista-formacao-container').html(html);
            $('#lista_formacoes_json').val(JSON.stringify(listaFormacao));
        }

        // GRID EXPERIÊNCIA (Visual Card)
        let listaExp = [];
        function toggleExpFim() { let chk = document.getElementById('exp_atual').checked; if(chk) $('#exp_fim').val('').prop('disabled', true); else $('#exp_fim').prop('disabled', false); }
        function addExp() {
            let item = {
                empresa: $('#exp_empresa').val(), cargo: $('#exp_cargo').val(),
                inicio: $('#exp_ini').val(), fim: $('#exp_fim').val(), atual: $('#exp_atual').is(':checked')?1:0, descricao: $('#exp_desc').val()
            };
            if(!item.empresa) return alert('Informe a Empresa.');
            listaExp.push(item); renderExp();
            $('#exp_empresa').val(''); $('#exp_desc').val('');
        }
        function renderExp() {
            if(listaExp.length === 0) { $('#lista-exp-container').html('<div class="exp-empty">Nenhuma experiência adicionada.</div>'); return; }
            let html = listaExp.map((i,x)=>`
                <div class="exp-card" style="border-left-color: #000;">
                    <i class="fas fa-trash trash-btn btn-del" onclick="listaExp.splice(${x},1);renderExp()"></i>
                    <h6>${i.empresa} <span class="badge bg-dark">${i.cargo}</span></h6>
                    <p class="small text-muted mb-2"><i class="far fa-calendar-alt"></i> ${i.atual?'Atualmente':i.inicio+' até '+i.fim}</p>
                    <p>${i.descricao}</p>
                </div>
            `).join('');
            $('#lista-exp-container').html(html);
            $('#lista_experiencias_json').val(JSON.stringify(listaExp));
        }

        $(document).ready(function(){
            $('#cpf').mask('000.000.000-00'); $('#cep').mask('00000-000'); $('#telefone').mask('(00) 00000-0000'); $('#dinheiro').mask('R$ 000.000.000,00', {reverse: true});
            $("#cep").blur(function() {
                var c = $(this).val().replace(/\D/g, '');
                if(c){
                    var m = new bootstrap.Modal(document.getElementById('modalCepLoading')); m.show();
                    $.getJSON("https://viacep.com.br/ws/"+c+"/json/?callback=?", function(d){
                        m.hide(); if(!("erro" in d)) { $("#rua").val(d.logradouro); $("#bairro").val(d.bairro); $("#cidade").val(d.localidade); $("#estado").val(d.uf); }
                    });
                }
            });
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>