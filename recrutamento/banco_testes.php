<?php
session_start();
require 'includes/db.php';
if (!isset($_SESSION['nivel']) || $_SESSION['nivel'] !== 'rh') { header("Location: index.php"); exit; }
$pdo = getDB(); $acao = $_GET['acao'] ?? 'listar'; $id_teste = $_GET['id'] ?? null; $msg="";

if (isset($_POST['salvar_teste'])) {
    $arq = $_POST['arquivo_atual'] ?? null;
    if (isset($_FILES['arquivo']) && $_FILES['arquivo']['error'] == 0) {
        $ext = pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION); $nm = "Repo_".uniqid().".".$ext; 
        move_uploaded_file($_FILES['arquivo']['tmp_name'], "uploads/testes_rh/".$nm); $arq = $nm;
    }
    if ($id_teste) $pdo->prepare("UPDATE banco_testes SET titulo=?, segmento=?, tempo_limite=?, descricao=?, arquivo_anexo=? WHERE id=?")->execute([$_POST['titulo'], $_POST['segmento'], $_POST['tempo'], $_POST['descricao'], $arq, $id_teste]);
    else { $pdo->prepare("INSERT INTO banco_testes (titulo, segmento, tempo_limite, descricao, arquivo_anexo, criado_por_id, status) VALUES (?,?,?,?,?,?,'ativo')")->execute([$_POST['titulo'], $_POST['segmento'], $_POST['tempo'], $_POST['descricao'], $arq, $_SESSION['usuario_id']]); $id_teste=$pdo->lastInsertId(); header("Location: banco_testes.php?acao=gerenciar&id=$id_teste"); exit; }
}

if (isset($_POST['salvar_questao'])) {
    $qid=$pdo->prepare("INSERT INTO questoes_teste (banco_teste_id, enunciado, tipo, resposta_esperada) VALUES (?,?,?,?)");
    $qid->execute([$id_teste, $_POST['enunciado'], $_POST['tipo'], $_POST['gabarito_texto']]);
    $qid=$pdo->lastInsertId();
    if($_POST['tipo']=='multipla_escolha'){
        $op=$_POST['opcao']; $cor=$_POST['correta'];
        foreach($op as $k=>$v) $pdo->prepare("INSERT INTO questoes_opcoes (questao_id, texto_opcao, eh_correta) VALUES (?,?,?)")->execute([$qid, $v, ($k==$cor?1:0)]);
    }
    $msg="Questão salva.";
}
if(isset($_GET['del_q'])) $pdo->prepare("DELETE FROM questoes_teste WHERE id=?")->execute([$_GET['del_q']]);
?>
<!DOCTYPE html><html lang="pt-br"><head><meta charset="UTF-8"><title>Banco Testes</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.3/tinymce.min.js" referrerpolicy="no-referrer"></script><script>tinymce.init({ selector:'.editor-rico', height:200, menubar:false, branding:false, plugins:'lists link image', toolbar:'bold italic bullist numlist' });</script><style>body{background:#f0f2f5;padding:30px;}.card{border:none;box-shadow:0 2px 10px rgba(0,0,0,0.05);}</style></head><body>
<div class="container"><div class="d-flex justify-content-between mb-4"><h3>Gestão de Testes</h3><a href="admin.php?pag=vagas" class="btn btn-dark">Voltar</a></div>
<?php if($msg): ?><div class="alert alert-success"><?=$msg?></div><?php endif; ?>

<?php if($acao=='listar'): $ts=$pdo->query("SELECT * FROM banco_testes ORDER BY id DESC")->fetchAll(); ?>
<div class="card p-4"><a href="?acao=novo" class="btn btn-success mb-3 w-25">Novo Teste</a><table class="table table-hover"><thead><tr><th>Título</th><th>Segmento</th><th>Ações</th></tr></thead><tbody><?php foreach($ts as $t): ?><tr><td><?=$t['titulo']?></td><td><?=$t['segmento']?></td><td><a href="?acao=gerenciar&id=<?=$t['id']?>" class="btn btn-sm btn-primary">Questões</a> <a href="?acao=editar&id=<?=$t['id']?>" class="btn btn-sm btn-outline-dark">Editar</a></td></tr><?php endforeach; ?></tbody></table></div>

<?php elseif($acao=='novo' || $acao=='editar'): $d=$id_teste?$pdo->query("SELECT * FROM banco_testes WHERE id=$id_teste")->fetch():[]; ?>
<div class="card p-4"><form method="POST" enctype="multipart/form-data"><input type="hidden" name="salvar_teste" value="1"><?php if(isset($d['arquivo_anexo'])): ?><input type="hidden" name="arquivo_atual" value="<?=$d['arquivo_anexo']?>"><?php endif; ?>
<div class="row g-3"><div class="col-6"><label>Título</label><input type="text" name="titulo" class="form-control" value="<?=$d['titulo']??''?>" required></div><div class="col-3"><label>Segmento</label><select name="segmento" class="form-select"><option>TI</option><option>Adm</option><option>Geral</option></select></div><div class="col-3"><label>Tempo</label><input type="number" name="tempo" class="form-control" value="<?=$d['tempo_limite']??60?>"></div><div class="col-12"><label>Instruções</label><textarea name="descricao" class="editor-rico"><?=$d['descricao']??''?></textarea></div><div class="col-12"><label>Anexo</label><input type="file" name="arquivo" class="form-control"></div><button class="btn btn-success mt-3">Salvar</button></div></form></div>

<?php elseif($acao=='gerenciar'): $qs=$pdo->query("SELECT * FROM questoes_teste WHERE banco_teste_id=$id_teste")->fetchAll(); ?>
<div class="row"><div class="col-md-5"><div class="card p-4"><h5>Nova Questão</h5><form method="POST"><input type="hidden" name="salvar_questao" value="1"><div class="mb-3"><label>Tipo</label><select name="tipo" id="tp" class="form-select" onchange="toggleT()"><option value="dissertativa">Dissertativa</option><option value="multipla_escolha">Múltipla Escolha</option></select></div><div class="mb-3"><label>Enunciado</label><textarea name="enunciado" class="editor-rico"></textarea></div><div id="box_txt"><label>Gabarito</label><textarea name="gabarito_texto" class="form-control"></textarea></div><div id="box_mult" class="d-none bg-light p-2"><label>Opções (Marque a certa)</label><?php for($i=0;$i<4;$i++):?><div class="input-group mb-2"><div class="input-group-text"><input type="radio" name="correta" value="<?=$i?>" <?=$i==0?'checked':''?>></div><input type="text" name="opcao[]" class="form-control" placeholder="Opção <?=$i+1?>"></div><?php endfor; ?></div><button class="btn btn-primary w-100 mt-3">Adicionar</button></form></div></div>
<div class="col-md-7"><?php foreach($qs as $i=>$q): ?><div class="card mb-2 p-3"><div class="d-flex justify-content-between"><span class="badge bg-secondary">Q<?=$i+1?> (<?=$q['tipo']?>)</span> <a href="?acao=gerenciar&id=<?=$id_teste?>&del_q=<?=$q['id']?>" class="text-danger">X</a></div><div class="mt-2"><?=$q['enunciado']?></div></div><?php endforeach; ?></div></div>
<script>function toggleT(){ if(document.getElementById('tp').value=='multipla_escolha'){ document.getElementById('box_mult').classList.remove('d-none'); document.getElementById('box_txt').classList.add('d-none'); } else { document.getElementById('box_mult').classList.add('d-none'); document.getElementById('box_txt').classList.remove('d-none'); } }</script>
<?php endif; ?>
</div></body></html>