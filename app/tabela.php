<?php
declare(strict_types=1);

/**
 * Tabela server-side no estilo DataTables: busca (vários termos, em várias colunas), ordenação por
 * coluna, paginação, itens por página, exportação CSV do resultado filtrado e atualização parcial
 * via AJAX (o app.js pede ?_parcial=<id> e troca só o bloco da tabela). Sem JavaScript, os mesmos
 * links e formulários funcionam com recarga da página.
 *
 * Uso:
 *   $t = new Tabela('ots', 'ordens_trabalho o LEFT JOIN ativos a ON a.id = o.ativo_id', 'o.*, a.codigo', [
 *       'numero' => ['rotulo' => 'Nº', 'ordem' => 'o.numero', 'busca' => 'o.numero', 'render' => fn($l) => e($l['numero'])],
 *       ...
 *   ]);
 *   $t->where[] = "o.status = ?"; $t->params[] = 'aberta';
 *   $t->ordemPadrao = 'numero'; $t->dirPadrao = 'desc'; $t->desempate = 'o.id';
 *   $t->responder('ordens_trabalho');   // atende _parcial e csv e encerra, se for o caso
 *   ... view_header(); echo $t->html(); view_footer();
 *
 * Colunas: rotulo, ordem (expressão SQL para ORDER BY), busca (expressão SQL pesquisável),
 * render (fn(array $linha): string HTML já escapado), csv (fn(array $linha): scalar; false = fora do CSV),
 * classe (classes do <td>/<th>, ex.: 'text-end d-none d-md-table-cell').
 */
final class Tabela
{
    public array $where = [];
    public array $params = [];
    public string $ordemPadrao = '';
    public string $dirPadrao = 'asc';
    public string $desempate = 'id';          // coluna única para ordem estável entre páginas
    /** @var callable|null fn(array $linha): ?string */
    public $href = null;
    /** @var callable|null fn(array $linha): string */
    public $classeLinha = null;
    public string $vazio = 'Nenhum registro encontrado.';
    public string $placeholder = 'Buscar…';
    public array $opcoesPorPagina = [10, 25, 50, 100];
    public int $porPaginaPadrao = 25;
    public bool $exportar = true;
    public int $limiteCsv = 50000;

    // estado após executar()
    public array $linhas = [];
    public int $total = 0;          // registros com os filtros da página (sem a busca)
    public int $filtrado = 0;       // registros após a busca
    public int $pag = 1;
    public int $pp = 25;
    public string $q = '';
    public string $ordem = '';
    public string $dir = 'asc';
    private bool $executada = false;

    public function __construct(public string $id, public string $from, public string $select, public array $colunas) {}

    /** Lê parâmetros da URL com validação (ordem e direção só de uma lista branca). */
    private function lerParametros(): void
    {
        $this->q = mb_substr(trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : ''), 0, 100);
        $o = is_string($_GET['ordem'] ?? null) ? $_GET['ordem'] : '';
        $this->ordem = isset($this->colunas[$o]['ordem']) ? $o : $this->ordemPadrao;
        $d = is_string($_GET['dir'] ?? null) ? strtolower($_GET['dir']) : '';
        $this->dir = in_array($d, ['asc', 'desc'], true) ? $d : ($this->ordem === $this->ordemPadrao ? $this->dirPadrao : 'asc');
        $pp = (int)($_GET['pp'] ?? 0);
        if (in_array($pp, $this->opcoesPorPagina, true)) $_SESSION['dt_pp'][$this->id] = $pp;
        $this->pp = (int)($_SESSION['dt_pp'][$this->id] ?? $this->porPaginaPadrao);
        if (!in_array($this->pp, $this->opcoesPorPagina, true)) $this->pp = $this->porPaginaPadrao;
        $this->pag = max(1, min(100000, (int)($_GET['pag'] ?? 1)));
    }

    /** Condição da busca: cada termo precisa aparecer em alguma coluna pesquisável. */
    private function condicaoBusca(): array
    {
        $exprs = array_values(array_filter(array_map(fn($c) => $c['busca'] ?? null, $this->colunas)));
        if ($this->q === '' || !$exprs) return ['', []];
        $termos = array_slice(array_filter(preg_split('/\s+/u', $this->q)), 0, 5);
        $sql = []; $p = [];
        foreach ($termos as $t) {
            $like = '%' . addcslashes($t, '%_\\') . '%';
            $sql[] = '(' . implode(' OR ', array_map(fn($e) => "$e LIKE ?", $exprs)) . ')';
            array_push($p, ...array_fill(0, count($exprs), $like));
        }
        return [implode(' AND ', $sql), $p];
    }

    private function sqlBase(bool $comBusca): array
    {
        $w = $this->where; $p = $this->params;
        if ($comBusca) {
            [$b, $pb] = $this->condicaoBusca();
            if ($b !== '') { $w[] = $b; $p = array_merge($p, $pb); }
        }
        return ['FROM ' . $this->from . ($w ? ' WHERE ' . implode(' AND ', array_map(fn($x) => "($x)", $w)) : ''), $p];
    }

    private function orderBy(): string
    {
        $expr = $this->colunas[$this->ordem]['ordem'] ?? null;
        $dir = strtoupper($this->dir);
        return ' ORDER BY ' . ($expr ? "$expr $dir, " : '') . $this->desempate . ' ' . $dir;
    }

    public function executar(): static
    {
        if ($this->executada) return $this;
        $this->executada = true;
        $this->lerParametros();
        [$fromF, $pF] = $this->sqlBase(true);
        $this->filtrado = (int)qv("SELECT COUNT(*) $fromF", $pF);
        $this->total = $this->q === '' ? $this->filtrado : (int)qv('SELECT COUNT(*) ' . $this->sqlBase(false)[0], $this->params);
        $paginas = max(1, (int)ceil($this->filtrado / $this->pp));
        $this->pag = min($this->pag, $paginas);
        $this->linhas = q("SELECT {$this->select} $fromF" . $this->orderBy() . ' LIMIT ' . $this->pp . ' OFFSET ' . (($this->pag - 1) * $this->pp), $pF);
        return $this;
    }

    /** Atende a atualização parcial (AJAX) e a exportação CSV; encerra a requisição nesses casos. */
    public function responder(string $nomeCsv): void
    {
        if ($this->exportar && !empty($_GET['csv'])) $this->csv($nomeCsv);
        if (($_GET['_parcial'] ?? '') === $this->id) {
            $this->executar();
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            echo $this->html();
            exit;
        }
    }

    /** URL da página atual com parâmetros trocados (preserva os filtros da página). */
    public function url(array $mudar = []): string
    {
        $qs = array_filter(array_merge(array_diff_key($_GET, ['_parcial' => 1, 'csv' => 1]), $mudar), fn($v) => $v !== null && $v !== '' && !is_array($v));
        return '?' . http_build_query($qs);
    }

    public function csv(string $nome): never
    {
        $this->lerParametros();
        [$fromF, $pF] = $this->sqlBase(true);
        $cols = array_filter($this->colunas, fn($c) => ($c['csv'] ?? null) !== false);
        $linhas = q("SELECT {$this->select} $fromF" . $this->orderBy() . ' LIMIT ' . $this->limiteCsv, $pF);
        auditar('exportar_csv', $this->id, null, count($linhas) . ' linha(s)');
        csv_download($nome, array_map(fn($c) => $c['rotulo'], $cols), array_map(function ($l) use ($cols) {
            return array_map(fn($c) => isset($c['csv']) && is_callable($c['csv']) ? $c['csv']($l)
                : trim(html_entity_decode(strip_tags(($c['render'])($l)), ENT_QUOTES, 'UTF-8')), $cols);
        }, $linhas));
    }

    public function html(): string
    {
        $this->executar();
        $id = e($this->id);
        $h = '<div class="dt" data-dt="' . $id . '" data-url="' . e($this->url()) . '">';

        // barra: busca + itens por página + exportar
        $ocultos = '';
        foreach (array_diff_key($_GET, ['q' => 1, 'pp' => 1, 'pag' => 1, '_parcial' => 1, 'csv' => 1]) as $k => $v) {
            if (is_scalar($v)) $ocultos .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
        }
        $h .= '<form method="get" class="dt-form d-flex flex-wrap gap-2 align-items-center mb-2" role="search">' . $ocultos;
        $temBusca = (bool)array_filter($this->colunas, fn($c) => !empty($c['busca']));
        if ($temBusca) {
            $h .= '<div class="input-group input-group-sm dt-busca-grupo"><span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>'
                . '<input type="search" class="form-control dt-busca" name="q" value="' . e($this->q) . '" placeholder="' . e($this->placeholder) . '" aria-label="Buscar na tabela" autocomplete="off">'
                . '<button class="btn btn-outline-secondary dt-buscar-btn">Buscar</button></div>';
        }
        $h .= '<label class="d-flex align-items-center gap-1 small text-muted ms-auto mb-0">Mostrar <select class="form-select form-select-sm dt-pp w-auto" name="pp" aria-label="Itens por página">';
        foreach ($this->opcoesPorPagina as $o) $h .= '<option value="' . $o . '"' . ($o === $this->pp ? ' selected' : '') . ">$o</option>";
        $h .= '</select></label>';
        if ($this->exportar) $h .= '<a class="btn btn-outline-secondary btn-sm" href="' . e($this->url(['csv' => 1, 'pag' => null])) . '"><i class="bi bi-filetype-csv" aria-hidden="true"></i> CSV</a>';
        $h .= '<noscript><button class="btn btn-sm btn-secondary">Aplicar</button></noscript></form>';
        $h .= '<div class="alert alert-danger py-1 small dt-erro" role="alert" hidden></div>';

        // tabela
        $h .= '<div class="card"><div class="table-responsive"><table class="table table-hover table-sm mb-0 align-middle"><thead><tr>';
        foreach ($this->colunas as $k => $c) {
            $cls = e($c['classe'] ?? '');
            if (empty($c['ordem'])) { $h .= '<th scope="col" class="' . $cls . '">' . e($c['rotulo']) . '</th>'; continue; }
            $ativa = $this->ordem === $k;
            $prox = $ativa && $this->dir === 'asc' ? 'desc' : 'asc';
            $icone = $ativa ? ($this->dir === 'asc' ? 'bi-sort-up' : 'bi-sort-down') : 'bi-arrow-down-up opacity-25';
            $aria = $ativa ? ($this->dir === 'asc' ? 'ascending' : 'descending') : 'none';
            $h .= '<th scope="col" class="' . $cls . '" aria-sort="' . $aria . '"><a class="dt-nav dt-ordenar text-reset text-decoration-none text-nowrap" href="'
                . e($this->url(['ordem' => $k, 'dir' => $prox, 'pag' => null])) . '">' . e($c['rotulo']) . ' <i class="bi ' . $icone . '" aria-hidden="true"></i></a></th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach ($this->linhas as $l) {
            $href = $this->href ? ($this->href)($l) : null;
            $cl = $this->classeLinha ? ($this->classeLinha)($l) : '';
            $h .= '<tr' . ($href ? ' data-href="' . e($href) . '"' : '') . ($cl ? ' class="' . e($cl) . '"' : '') . '>';
            foreach ($this->colunas as $c) $h .= '<td class="' . e($c['classe'] ?? '') . '">' . ($c['render'])($l) . '</td>';
            $h .= '</tr>';
        }
        if (!$this->linhas) {
            $h .= '<tr><td colspan="' . count($this->colunas) . '" class="text-center text-muted py-4">'
                . e($this->q !== '' ? 'Nada encontrado para “' . $this->q . '”.' : $this->vazio) . '</td></tr>';
        }
        $h .= '</tbody></table></div>';

        // rodapé: informação + paginação
        $ini = $this->filtrado ? ($this->pag - 1) * $this->pp + 1 : 0;
        $fim = min($this->filtrado, $this->pag * $this->pp);
        $info = $this->filtrado
            ? 'Mostrando ' . num($ini, 0) . ' a ' . num($fim, 0) . ' de ' . num($this->filtrado, 0) . ($this->filtrado === 1 ? ' registro' : ' registros')
            : 'Nenhum registro';
        if ($this->q !== '' && $this->total !== $this->filtrado) $info .= ' (filtrados de ' . num($this->total, 0) . ')';
        $h .= '<div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2"><div class="small text-muted" aria-live="polite">' . $info . '</div>';
        $h .= $this->paginacao() . '</div></div></div>';
        return $h;
    }

    private function paginacao(): string
    {
        $paginas = max(1, (int)ceil($this->filtrado / $this->pp));
        if ($paginas <= 1) return '';
        $item = function (?int $p, string $rot, bool $ativo = false, string $aria = '') {
            if ($p === null) return '<li class="page-item disabled"><span class="page-link">' . $rot . '</span></li>';
            return '<li class="page-item' . ($ativo ? ' active' : '') . '"><a class="page-link dt-nav" href="' . e($this->url(['pag' => $p > 1 ? $p : null])) . '"'
                . ($aria ? ' aria-label="' . e($aria) . '"' : '') . ($ativo ? ' aria-current="page"' : '') . '>' . $rot . '</a></li>';
        };
        $h = '<nav aria-label="Paginação"><ul class="pagination pagination-sm mb-0 flex-wrap">';
        $h .= $item($this->pag > 1 ? 1 : null, '«', false, 'Primeira página');
        $h .= $item($this->pag > 1 ? $this->pag - 1 : null, '‹', false, 'Página anterior');
        $iniJ = max(1, $this->pag - 2); $fimJ = min($paginas, $this->pag + 2);
        if ($iniJ > 1) $h .= $item(null, '…');
        for ($p = $iniJ; $p <= $fimJ; $p++) $h .= $item($p, (string)$p, $p === $this->pag);
        if ($fimJ < $paginas) $h .= $item(null, '…');
        $h .= $item($this->pag < $paginas ? $this->pag + 1 : null, '›', false, 'Próxima página');
        $h .= $item($this->pag < $paginas ? $paginas : null, '»', false, 'Última página');
        return $h . '</ul></nav>';
    }
}
