<?php
/**
 * CRUD genérico para tabelas auxiliares, dirigido por configuração.
 * Tipos de campo: text, textarea, email, number, money, select, check, tree (pai na mesma tabela)
 */
exigir('gerir');

$CAD = [
    'localizacoes' => [
        'tabela' => 'localizacoes', 'titulo' => 'Localizações', 'singular' => 'localização', 'f' => true, 'ordem' => 'nome',
        'campos' => [
            'codigo' => ['Código', 'text'], 'nome' => ['Nome', 'text', 'req' => true],
            'parent_id' => ['Pertence a', 'tree'], 'descricao' => ['Descrição', 'textarea'],
        ],
        'lista' => ['codigo', 'nome', 'parent_id'], 'arvore' => true,
    ],
    'categorias' => [
        'tabela' => 'categorias_ativo', 'titulo' => 'Categorias de ativo', 'singular' => 'categoria', 'f' => true, 'ordem' => 'nome',
        'campos' => ['nome' => ['Nome', 'text', 'req' => true]], 'lista' => ['nome'],
    ],
    'especialidades' => [
        'tabela' => 'especialidades', 'titulo' => 'Especialidades', 'singular' => 'especialidade', 'f' => true, 'ordem' => 'nome',
        'campos' => ['nome' => ['Nome', 'text', 'req' => true]], 'lista' => ['nome'],
    ],
    'fornecedores' => [
        'tabela' => 'fornecedores', 'titulo' => 'Fornecedores', 'singular' => 'fornecedor', 'ordem' => 'nome',
        'campos' => [
            'nome' => ['Razão social / nome', 'text', 'req' => true], 'cnpj' => ['CNPJ/CPF', 'text'], 'contato' => ['Contato', 'text'],
            'telefone' => ['Telefone', 'text'], 'email' => ['E-mail', 'email'], 'endereco' => ['Endereço', 'text'], 'observacoes' => ['Observações', 'textarea'],
        ],
        'lista' => ['nome', 'cnpj', 'contato', 'telefone', 'email'], 'busca' => ['nome', 'cnpj', 'contato'],
    ],
    'tecnicos' => [
        'tabela' => 'tecnicos', 'titulo' => 'Técnicos', 'singular' => 'técnico', 'ordem' => 'nome',
        'campos' => [
            'nome' => ['Nome', 'text', 'req' => true],
            'especialidade_id' => ['Especialidade', 'select', 'sql' => 'SELECT id, nome FROM especialidades ORDER BY nome'],
            'fornecedor_id' => ['Empresa (se terceirizado)', 'select', 'sql' => 'SELECT id, nome FROM fornecedores ORDER BY nome'],
            'custo_hora' => ['Custo/hora (R$)', 'money'], 'telefone' => ['Telefone', 'text'], 'email' => ['E-mail', 'email'],
            'ativo' => ['Ativo', 'check', 'padrao' => 1],
        ],
        'lista' => ['nome', 'especialidade_id', 'fornecedor_id', 'custo_hora', 'ativo'], 'busca' => ['nome'],
    ],
];

$t = (string)get('t', '');
if (!isset($CAD[$t])) redirect('dashboard');
$c = $CAD[$t];
$tab = $c['tabela'];
$menu = "cad_$t";

// resolve opções de selects uma vez
$opc = [];
foreach ($c['campos'] as $k => $f) {
    if ($f[1] === 'select') $opc[$k] = opcoes($f['sql']);
}

function valor_lista(array $c, array $opc, string $k, array $l): string
{
    $f = $c['campos'][$k]; $v = $l[$k];
    return match ($f[1]) {
        'select' => e($opc[$k][$v] ?? ''),
        'tree'   => e($v ? (string)qv("SELECT nome FROM `{$c['tabela']}` WHERE id = ?", [$v]) : ''),
        'money'  => moeda($v),
        'check'  => $v ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle text-muted"></i>',
        default  => e($v),
    };
}

/* ---------- Salvar ---------- */
if ($acao === 'salvar' && is_post()) {
    $id = post_int('id');
    $dados = [];
    foreach ($c['campos'] as $k => $f) {
        $dados[$k] = match ($f[1]) {
            'select', 'tree' => post_int($k),
            'money', 'number' => post_dec($k) ?? 0,
            'check' => post($k) ? 1 : 0,
            default => (post($k) === '' ? null : post($k)),
        };
        if (!empty($f['req']) && ($dados[$k] === null || $dados[$k] === '')) {
            flash("O campo \"{$f[0]}\" é obrigatório.", 'danger');
            redirect('cadastros/form', ['t' => $t] + ($id ? ['id' => $id] : []));
        }
    }
    if (isset($dados['parent_id']) && $id && in_array($dados['parent_id'], descendentes($tab, $id), true)) {
        flash('Não é possível subordinar um item a ele mesmo ou a um descendente.', 'danger');
        redirect('cadastros/form', ['t' => $t, 'id' => $id]);
    }
    try {
        if ($id) { update($tab, $dados, $id); auditar('editar', $tab, $id); }
        else { $id = insert($tab, $dados); auditar('criar', $tab, $id); }
        flash(mb_strtoupper(mb_substr($c['singular'], 0, 1)) . mb_substr($c['singular'], 1) . (!empty($c['f']) ? ' salva.' : ' salvo.'));
    } catch (PDOException $ex) {
        flash($ex->getCode() === '23000' ? 'Já existe um registro com esses dados.' : 'Erro ao salvar.', 'danger');
    }
    redirect('cadastros', ['t' => $t]);
}

/* ---------- Excluir ---------- */
if ($acao === 'excluir' && is_post()) {
    $id = post_int('id');
    try {
        qx("DELETE FROM `$tab` WHERE id = ?", [$id]);
        auditar('excluir', $tab, $id);
        flash('Registro excluído.');
    } catch (PDOException) {
        flash('Não é possível excluir: o registro está em uso.', 'danger');
    }
    redirect('cadastros', ['t' => $t]);
}

/* ---------- Formulário ---------- */
if ($acao === 'form') {
    $id = (int)get('id', 0);
    $r = $id ? q1("SELECT * FROM `$tab` WHERE id = ?", [$id]) : [];
    if (!empty($_SESSION['old']) || !empty($GLOBALS['__old'])) $r = com_old($r);
    if ($id && !$r) redirect('cadastros', ['t' => $t]);
    $novo = !empty($c['f']) ? 'Nova' : 'Novo';
    view_header(($id ? 'Editar ' : "$novo ") . $c['singular'], $menu);
    ?>
    <div class="page-title"><h1><?= $id ? 'Editar' : $novo ?> <?= e($c['singular']) ?></h1>
      <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('cadastros', ['t' => $t])) ?>">Voltar</a></div>
    <div class="card"><div class="card-body">
    <form method="post" action="<?= e(url('cadastros/salvar', ['t' => $t])) ?>"><?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $id ?: '' ?>">
      <div class="row g-3">
      <?php foreach ($c['campos'] as $k => $f):
          $v = $r[$k] ?? ($f['padrao'] ?? ''); $req = !empty($f['req']) ? 'required' : ''; ?>
        <div class="<?= $f[1] === 'textarea' ? 'col-12' : 'col-md-6' ?>">
          <?php if ($f[1] === 'check'): ?>
            <div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="<?= $k ?>" id="f_<?= $k ?>" value="1" <?= $v ? 'checked' : '' ?>>
            <label class="form-check-label" for="f_<?= $k ?>"><?= e($f[0]) ?></label></div>
          <?php else: ?>
            <label class="form-label" for="f_<?= $k ?>"><?= e($f[0]) ?><?= $req ? ' *' : '' ?></label>
            <?php if ($f[1] === 'textarea'): ?><textarea class="form-control" rows="3" name="<?= $k ?>" id="f_<?= $k ?>"><?= e($v) ?></textarea>
            <?php elseif ($f[1] === 'select'): ?><?= sel($k, $opc[$k], $v) ?>
            <?php elseif ($f[1] === 'tree'): ?><?= sel($k, arvore_opcoes($tab, 'nome', $id ?: null), $v, true) ?>
            <?php else: ?><input class="form-control" type="<?= $f[1] === 'email' ? 'email' : 'text' ?>" <?= in_array($f[1], ['money', 'number']) ? 'inputmode="decimal"' : '' ?>
                 name="<?= $k ?>" id="f_<?= $k ?>" value="<?= e($f[1] === 'money' ? num($v ?: 0) : $v) ?>" <?= $req ?>>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>
      <div class="mt-4"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> Salvar</button></div>
    </form>
    <?php if ($id): ?>
      <form method="post" action="<?= e(url('cadastros/excluir', ['t' => $t])) ?>" class="mt-3 text-end" data-confirm="Excluir este registro?">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-link text-danger btn-sm">Excluir</button></form>
    <?php endif; ?>
    </div></div>
    <?php
    view_footer();
    exit;
}

/* ---------- Lista ---------- */
$where = ''; $params = [];
$busca = (string)get('q', '');
if ($busca !== '' && !empty($c['busca'])) {
    $where = ' WHERE ' . implode(' OR ', array_map(fn($k) => "`$k` LIKE ?", $c['busca']));
    $params = array_fill(0, count($c['busca']), "%$busca%");
}
$linhas = q("SELECT * FROM `$tab`$where ORDER BY {$c['ordem']}", $params);

view_header($c['titulo'], $menu);
?>
<div class="page-title"><h1><?= e($c['titulo']) ?></h1>
  <div class="d-flex gap-2">
    <?php if (!empty($c['busca'])): ?>
      <form class="d-flex" method="get"><input type="hidden" name="r" value="cadastros"><input type="hidden" name="t" value="<?= e($t) ?>">
        <input class="form-control form-control-sm" name="q" placeholder="Buscar…" value="<?= e($busca) ?>"></form>
    <?php endif; ?>
    <a class="btn btn-warning btn-sm" href="<?= e(url('cadastros/form', ['t' => $t])) ?>"><i class="bi bi-plus-lg"></i> Novo</a>
  </div>
</div>
<?php if (!empty($c['arvore'])): ?>
  <div class="card"><div class="card-body arvore">
    <?php
    $filhos = [];
    foreach ($linhas as $l) $filhos[(int)($l['parent_id'] ?? 0)][] = $l;
    $qtdAtivos = opcoes('SELECT localizacao_id, COUNT(*) FROM ativos WHERE localizacao_id IS NOT NULL GROUP BY localizacao_id');
    $render = function (int $pai) use (&$render, $filhos, $t, $qtdAtivos) {
        if (empty($filhos[$pai])) return;
        echo '<ul>';
        foreach ($filhos[$pai] as $n) {
            $qtd = (int)($qtdAtivos[$n['id']] ?? 0);
            echo '<li><i class="bi bi-geo-alt text-warning"></i> <a href="' . e(url('cadastros/form', ['t' => $t, 'id' => $n['id']])) . '">'
               . ($n['codigo'] ? '<span class="text-muted">' . e($n['codigo']) . '</span> ' : '') . e($n['nome']) . '</a>'
               . ($qtd ? ' <a class="badge text-bg-light" href="' . e(url('ativos', ['loc' => $n['id']])) . '">' . plural($qtd, 'ativo', 'ativos') . '</a>' : '');
            $render((int)$n['id']);
            echo '</li>';
        }
        echo '</ul>';
    };
    $render(0);
    if (!$linhas) echo '<p class="text-muted mb-0">Nenhuma localização cadastrada.</p>';
    ?>
  </div></div>
<?php else: ?>
  <div class="card"><div class="table-responsive">
  <table class="table table-hover mb-0">
    <thead><tr><?php foreach ($c['lista'] as $k): ?><th><?= e($c['campos'][$k][0]) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($linhas as $l): ?>
      <tr data-href="<?= e(url('cadastros/form', ['t' => $t, 'id' => $l['id']])) ?>">
        <?php foreach ($c['lista'] as $k): ?><td><?= valor_lista($c, $opc, $k, $l) ?></td><?php endforeach; ?>
      </tr>
    <?php endforeach; if (!$linhas): ?><tr><td colspan="9" class="text-muted text-center py-4">Nenhum registro.</td></tr><?php endif; ?>
    </tbody>
  </table></div></div>
<?php endif;
view_footer();
