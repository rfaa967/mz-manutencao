<?php
declare(strict_types=1);

/**
 * Importação de dados do ManWinWin (Express / 6.x, banco Access .mdb).
 *
 * Fonte aceita:
 *  - .zip com um CSV por tabela (gerado por tools/manwinwin/exportar_manwinwin.bat no Windows
 *    ou exportar_manwinwin.sh no Linux/macOS);
 *  - o próprio .mdb, quando o servidor tem o mdbtools (mdb-export).
 *
 * Importa só o que é pertinente:
 *  - Localizações (LocaisObj) e fornecedores (Forneced) cadastrados pelo cliente;
 *  - tipos de equipamento (TipObjeto) e especialidades que estão em uso;
 *  - equipamentos (Objectos) → ativos, sem os exemplos "Modelo" que vêm com o ManWinWin;
 *  - funcionários (Funcionario) → técnicos;
 *  - fichas de manutenção (Fichas) + programação (Calibra) → planos preventivos;
 *  - ordens de trabalho emitidas/fechadas (Calibra) → OTs, e pedidos de trabalho (PTs) → pedidos;
 *  - opcional: catálogo de artigos (MestreArtigos) com o saldo em estoque (Artigos).
 * Não importa: tabelas de configuração do ManWinWin (contas, moedas, sistemas-modelo, características
 * vazias, licença, usuários/senhas).
 *
 * Idempotente: importacao_mapa guarda código de origem → id. Reimportar cria só o que falta e não
 * sobrescreve o que foi editado no sistema; o que foi importado e depois excluído não volta.
 * A simulação roda o mesmo código dentro de uma transação desfeita no final (o resultado é exato).
 */

const MWW_ORIGEM = 'manwinwin';

/** Tabelas lidas do ManWinWin (as demais são ignoradas). */
const MWW_TABELAS = ['Objectos', 'LocaisObj', 'TipObjeto', 'Forneced', 'Especialidade', 'Funcionario', 'Cencusto', 'Sistemas',
                     'Fichas', 'Calibra', 'TipTrabalho', 'PTs', 'Causas', 'MestreArtigos', 'Artigos', 'ARQ02'];

const MWW_ROTULOS = [
    'localizacoes' => 'Localizações', 'categorias_ativo' => 'Tipos de equipamento', 'fornecedores' => 'Fornecedores',
    'especialidades' => 'Especialidades', 'tecnicos' => 'Técnicos', 'ativos' => 'Ativos', 'planos_manutencao' => 'Planos de manutenção',
    'ordens_trabalho' => 'Ordens de trabalho', 'pedidos' => 'Pedidos de trabalho', 'artigos' => 'Artigos',
];

/* ===================== leitura do arquivo ===================== */

/** Lê o arquivo enviado (.zip de CSVs ou .mdb) e devolve [tabela => [linhas]]. */
function mww_ler_arquivo(string $caminho, string $nomeOriginal = ''): array
{
    $ext = strtolower(pathinfo($nomeOriginal !== '' ? $nomeOriginal : $caminho, PATHINFO_EXTENSION));
    $t = match ($ext) {
        'zip'          => mww_ler_zip($caminho),
        'mdb', 'accdb' => mww_ler_mdb($caminho),
        default        => throw new RuntimeException('Formato não suportado. Envie o .zip gerado pelo exportador ou o arquivo .mdb do ManWinWin.'),
    };
    if (!isset($t['Objectos'])) throw new RuntimeException('O arquivo não tem a tabela Objectos (equipamentos) do ManWinWin. Confira se é o banco certo (ex.: ManWinWin.mdb).');
    return $t;
}

function mww_ler_zip(string $caminho): array
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('A extensão zip do PHP não está instalada no servidor.');
    $z = new ZipArchive();
    if ($z->open($caminho) !== true) throw new RuntimeException('Não foi possível abrir o .zip.');
    $nomes = [];
    foreach (MWW_TABELAS as $n) $nomes[strtolower($n)] = $n;
    $t = [];
    try {
        for ($i = 0; $i < $z->numFiles; $i++) {
            $st = $z->statIndex($i);
            $base = strtolower(basename(str_replace('\\', '/', (string)$st['name'])));
            if (!str_ends_with($base, '.csv') || !isset($nomes[substr($base, 0, -4)])) continue;
            $tab = $nomes[substr($base, 0, -4)];
            if ((int)$st['size'] > MWW_MAX_BYTES) throw new RuntimeException("A tabela $tab passa de " . (int)(MWW_MAX_BYTES / 1048576) . ' MB descompactada.');
            $fp = $z->getStream((string)$st['name']);
            if (!$fp) throw new RuntimeException("Não foi possível ler $tab.csv dentro do .zip.");
            try { $t[$tab] = mww_ler_csv($fp, $tab); } finally { fclose($fp); }
        }
    } finally {
        $z->close();
    }
    return $t;
}

/** Caminho do mdb-export (mdbtools), se o servidor tiver. */
function mww_mdbtools(): ?string
{
    if (!function_exists('proc_open')) return null;
    foreach (['/usr/bin/mdb-export', '/usr/local/bin/mdb-export', '/opt/homebrew/bin/mdb-export'] as $b) {
        if (@is_executable($b)) return $b;
    }
    return null;
}

function mww_ler_mdb(string $caminho): array
{
    $bin = mww_mdbtools();
    if ($bin === null) {
        throw new RuntimeException('Este servidor não lê .mdb diretamente (falta o mdbtools). No computador com o ManWinWin, rode tools/manwinwin/exportar_manwinwin.bat e envie o .zip gerado.');
    }
    $t = [];
    foreach (MWW_TABELAS as $tab) {
        // sem shell: argumentos passados como array (o nome do arquivo não é interpretado)
        $cmd = [$bin, '-T', '%Y-%m-%d %H:%M:%S', '-D', '%Y-%m-%d %H:%M:%S', '-b', 'strip', $caminho, $tab];
        // um .mdb corrompido pode fazer o mdb-export consumir toda a memória: limita a 1 GB e 60 s de CPU
        if (@is_executable('/usr/bin/prlimit')) array_unshift($cmd, '/usr/bin/prlimit', '--as=1073741824', '--cpu=60');
        $errArq = tempnam(sys_get_temp_dir(), 'mdb');   // stderr em arquivo: um pipe não lido travaria o processo
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $errArq, 'w']], $pipes);
        if (!is_resource($p)) { @unlink($errArq); throw new RuntimeException('Falha ao executar o mdb-export.'); }
        try { $linhas = mww_ler_csv($pipes[1], $tab); }
        finally { fclose($pipes[1]); $cod = proc_close($p); $erro = trim((string)@file_get_contents($errArq, false, null, 0, 2000)); @unlink($errArq); }
        if ($cod === 0) $t[$tab] = $linhas;   // tabela inexistente nesta versão do ManWinWin: ignora
        elseif ($tab === 'Objectos') throw new RuntimeException('Não foi possível ler o .mdb: ' . mb_substr($erro, 0, 200));
    }
    return $t;
}

/** Colunas com senhas/dados pessoais do ManWinWin que a importação não usa: descartadas já na leitura. */
function mww_coluna_sensivel(string $tabela, string $coluna): bool
{
    if (preg_match('/^(snh|senha|pass|pwd)/i', $coluna)) return true;
    return $tabela === 'Funcionario' && !in_array($coluna, ['CodFunCnr', 'NomFunCnr', 'Email_Fun', 'CodEspCld', 'CstEspCld', 'FlgActivo'], true);
}

const MWW_MAX_LINHAS = 200000;          // por tabela
const MWW_MAX_BYTES = 150 * 1048576;    // por tabela, descompactada (o tamanho declarado no zip pode mentir)

/** Lê um CSV (cabeçalho na 1ª linha, aspas duplas, quebras de linha dentro de campos). */
function mww_ler_csv($fp, string $tabela = ''): array
{
    $cab = fgetcsv($fp, 0, ',', '"', '');
    if (!$cab || $cab === [null]) return [];
    $cab[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$cab[0]);   // BOM
    $cab = array_map(fn($c) => trim((string)$c), $cab);
    foreach ($cab as $i => $c) if ($c !== '' && mww_coluna_sensivel($tabela, $c)) $cab[$i] = '';
    $linhas = [];
    $bytes = 0;
    while (($l = fgetcsv($fp, 0, ',', '"', '')) !== false) {
        if ($l === [null]) continue;
        foreach ($l as $x) $bytes += strlen((string)$x);
        if (count($linhas) >= MWW_MAX_LINHAS || $bytes > MWW_MAX_BYTES) throw new RuntimeException("A tabela $tabela é grande demais para importar por aqui (limite de " . MWW_MAX_LINHAS . ' linhas / 150 MB).');
        $r = [];
        foreach ($cab as $i => $c) if ($c !== '') $r[$c] = mww_texto($l[$i] ?? '');
        $linhas[] = $r;
    }
    return $linhas;
}

function mww_texto(?string $v): string
{
    $v = (string)$v;
    if ($v !== '' && !mb_check_encoding($v, 'UTF-8')) $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    return trim(str_replace(["\r\n", "\r"], "\n", $v));
}

/** Data do ManWinWin → 'Y-m-d H:i:s' (ou null). Aceita ISO e o formato americano do mdb-export. */
function mww_data(?string $v): ?string
{
    $v = trim((string)$v);
    if ($v === '') return null;
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'm/d/y H:i:s', 'm/d/Y H:i:s', 'm/d/y', 'm/d/Y', 'd/m/Y H:i:s', 'd/m/Y'] as $f) {
        $d = DateTime::createFromFormat('!' . $f, $v);
        $err = DateTime::getLastErrors();
        if ($d && (!$err || ($err['warning_count'] === 0 && $err['error_count'] === 0))) {
            $ano = (int)$d->format('Y');
            return ($ano < 1950 || $ano > 2100) ? null : $d->format('Y-m-d H:i:s');   // 1899-12-30 = "sem data" no Access
        }
    }
    return null;
}

function mww_dia(?string $v): ?string { $d = mww_data($v); return $d ? substr($d, 0, 10) : null; }

function mww_num(?string $v): ?float
{
    $v = trim((string)$v);
    if ($v === '') return null;
    if (!str_contains($v, '.') && str_contains($v, ',')) $v = str_replace(',', '.', $v);
    return is_numeric($v) ? (float)$v : null;
}

/** Quebra um texto de tarefas do ManWinWin em itens de checklist (uma linha = uma tarefa). */
function mww_tarefas(string $texto, bool $soMarcadas = false): array
{
    $res = [];
    foreach (explode("\n", $texto) as $l) {
        $l = trim($l);
        $marcada = (bool)preg_match('/^[-•*–]\s*/u', $l);
        if ($soMarcadas && !$marcada) continue;
        $l = trim((string)preg_replace('/^[-•*–]\s*/u', '', $l));
        $l = rtrim($l, " ,;");
        if (mb_strlen($l) < 3) continue;
        $res[] = mb_strimwidth($l, 0, 255, '…');
    }
    return $res;
}

/* ===================== importação ===================== */

final class ImportadorManWinWin
{
    private array $t;
    private array $op;
    private ?int $usuarioId;
    private array $rel = ['criados' => [], 'existentes' => [], 'novos' => [], 'ignorados' => [], 'avisos' => []];
    private array $cache = [];

    private array $tiposObj = [];
    private array $locais = [];
    private array $fornec = [];
    private array $especs = [];
    private array $cencus = [];
    private array $sistemas = [];
    private array $tiposTrab = [];
    private array $causas = [];
    private array $objetos = [];       // código → linha (só os que serão importados)
    private array $planoChave = [];    // "obj|ficha" → plano_id
    private array $codigosOrigem = []; // todos os códigos de Objectos (inclusive os ignorados)

    /**
     * $opcoes: incluir_modelo (bool) — também importa os exemplos "Modelo" do ManWinWin;
     *          incluir_artigos (bool) — importa o catálogo de artigos e os saldos.
     */
    public function __construct(array $tabelas, array $opcoes = [], ?int $usuarioId = null)
    {
        $this->t = $tabelas;
        $this->op = $opcoes + ['incluir_modelo' => false, 'incluir_artigos' => false];
        $this->usuarioId = $usuarioId;
    }

    private bool $simulacao = false;
    private int $seqSim = 0;

    /** Número do documento. Na simulação não toca na tabela de sequências (o UPDATE travaria a criação de OTs dos outros usuários). */
    private function numero(string $tabela, string $prefixo): string
    {
        return $this->simulacao ? sprintf('SIM%s-%06d', $prefixo, ++$this->seqSim) : proximo_numero($tabela, $prefixo);
    }

    /** Executa a importação. $aplicar = false → simulação (tudo é desfeito no final). */
    public function executar(bool $aplicar): array
    {
        $this->simulacao = !$aplicar;
        if ($aplicar && $this->usuarioId === null) throw new RuntimeException('Informe o usuário responsável pela importação.');
        if ((int)qv("SELECT GET_LOCK('mzmanut_importacao', 0)") !== 1) throw new RuntimeException('Já existe uma importação em andamento. Aguarde e tente de novo.');
        @set_time_limit(0);
        $pdo = db();
        try {
            $pdo->beginTransaction();
            try {
                $this->indexar();
                $this->cadastrosBase();
                $this->ativos();
                $this->tecnicos();
                $this->planos();
                $this->ordens();
                $this->pedidos();
                if ($this->op['incluir_artigos']) $this->artigos();
                $aplicar ? $pdo->commit() : $pdo->rollBack();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $ex;
            }
        } finally {
            qv("SELECT RELEASE_LOCK('mzmanut_importacao')");
        }
        $this->rel['aplicado'] = $aplicar;
        return $this->rel;
    }

    /* ---------- utilidades ---------- */

    private function tab(string $nome): array { return $this->t[$nome] ?? []; }

    private function conta(string $grupo, string $tipo): void { $this->rel[$grupo][$tipo] = ($this->rel[$grupo][$tipo] ?? 0) + 1; }

    private function ignorar(string $tipo, string $item, string $motivo): void { $this->rel['ignorados'][] = [$tipo, $item, $motivo]; }

    private function aviso(string $msg): void { $this->rel['avisos'][] = $msg; }

    /**
     * Devolve o id do registro de destino para a chave de origem: pelo vínculo de uma importação anterior,
     * por um registro equivalente já cadastrado ($existente) ou criando ($criar).
     */
    private function obter(string $tipo, string $chave, string $rotulo, ?callable $existente, callable $criar): ?int
    {
        $k = "$tipo|$chave";
        if (array_key_exists($k, $this->cache)) return $this->cache[$k];
        $id = qv('SELECT entidade_id FROM importacao_mapa WHERE origem = ? AND tipo = ? AND chave = ?', [MWW_ORIGEM, $tipo, $chave]);
        if ($id !== null) {
            if (qv("SELECT 1 FROM `$tipo` WHERE id = ?", [$id])) { $this->conta('existentes', $tipo); return $this->cache[$k] = (int)$id; }
            $this->ignorar($tipo, $rotulo, 'importado antes e excluído no sistema — não é recriado');
            return $this->cache[$k] = null;
        }
        $id = $existente ? $existente() : null;
        if ($id) {
            $this->conta('existentes', $tipo);
        } else {
            $id = $criar();
            if (!$id) return $this->cache[$k] = null;
            $this->conta('criados', $tipo);
            $this->rel['novos'][$tipo][] = $rotulo;
        }
        qx('INSERT INTO importacao_mapa (origem, tipo, chave, entidade_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE entidade_id = VALUES(entidade_id)',
           [MWW_ORIGEM, $tipo, mb_substr($chave, 0, 150), (int)$id]);
        return $this->cache[$k] = (int)$id;
    }

    /** Id já resolvido nesta execução (sem criar). */
    private function resolvido(string $tipo, string $chave): ?int { return $this->cache["$tipo|$chave"] ?? null; }

    private static function cortar(?string $v, int $max): ?string
    {
        $v = trim((string)$v);
        return $v === '' ? null : mb_strimwidth($v, 0, $max, '…');
    }

    private static function prioridade(?string $cri): string
    {
        return match ((int)$cri) { 1 => 'urgente', 2 => 'alta', 4, 5 => 'baixa', default => 'media' };
    }

    /** Tipo de OT a partir do tipo de trabalho do ManWinWin (A=preventivo sistemático, B=condicionado, C=corretivo, M=melhoria). */
    private function tipoOt(string $codTipTrb, bool $temFicha): string
    {
        $tt = $this->tiposTrab[$codTipTrb] ?? null;
        $man = strtoupper((string)($tt['CodTipMan'] ?? substr($codTipTrb, 0, 1)));
        $dsc = mb_strtolower((string)($tt['DscTipTrb'] ?? ''));
        if (str_contains($dsc, 'lubrif')) return 'lubrificacao';
        if (str_contains($dsc, 'inspe')) return 'inspecao';
        if (str_contains($dsc, 'calibr') || $man === 'Q') return 'calibracao';
        return match ($man) {
            'A' => 'preventiva', 'B' => 'preditiva', 'C' => 'corretiva', 'M' => 'melhoria',
            default => $temFicha ? 'preventiva' : 'corretiva',
        };
    }

    private function especNome(string $cod): ?string
    {
        $e = $this->especs[$cod] ?? null;
        return $e ? ($e['DscEspCld'] ?: $cod) : null;
    }

    /* ---------- índices das tabelas de origem ---------- */

    private function indexar(): void
    {
        foreach ($this->tab('TipObjeto') as $r) $this->tiposObj[$r['CodTipObj'] ?? ''] = $r;
        foreach ($this->tab('LocaisObj') as $r) $this->locais[$r['CodLocObj'] ?? ''] = $r;
        foreach ($this->tab('Forneced') as $r) $this->fornec[$r['CodForncd'] ?? ''] = $r;
        foreach ($this->tab('Especialidade') as $r) $this->especs[$r['CodEspCld'] ?? ''] = $r;
        foreach ($this->tab('Cencusto') as $r) $this->cencus[$r['CodCenCst'] ?? ''] = $r;
        foreach ($this->tab('Sistemas') as $r) $this->sistemas[$r['CodSistem'] ?? ''] = $r;
        foreach ($this->tab('TipTrabalho') as $r) $this->tiposTrab[$r['CodTipTrb'] ?? ''] = $r;
        foreach ($this->tab('Causas') as $r) $this->causas[$r['CodCausa_'] ?? ''] = (($r['DscCausaBR'] ?? '') ?: ($r['DscCausa_'] ?? ''));
        unset($this->tiposObj[''], $this->locais[''], $this->fornec[''], $this->especs[''], $this->cencus[''], $this->sistemas[''], $this->tiposTrab['']);

        // exemplos que acompanham o ManWinWin: centro de custo "Modelo" (9999) e códigos XX-0000
        $ccModelo = [];
        foreach ($this->cencus as $c => $r) if ($c === '9999' || stripos((string)($r['DscCenCst'] ?? ''), 'modelo') !== false) $ccModelo[$c] = true;
        foreach ($this->tab('Objectos') as $o) {
            $cod = $o['CodObjeto'] ?? '';
            if ($cod === '') continue;
            $this->codigosOrigem[$cod] = true;
            $nome = ($o['DscObjeto'] ?? '') ?: $cod;
            if (!$this->op['incluir_modelo'] && (isset($ccModelo[$o['CodCenCst'] ?? '']) || preg_match('/-0+$/', $cod))) {
                $this->ignorar('ativos', "$cod — $nome", 'exemplo "Modelo" do ManWinWin');
                continue;
            }
            $this->objetos[$cod] = $o;
        }
    }

    /* ---------- localizações e fornecedores (cadastrados pelo cliente: todos) ---------- */

    private function cadastrosBase(): void
    {
        foreach (array_keys($this->locais) as $cod) $this->localizacao((string)$cod);
        foreach (array_keys($this->fornec) as $cod) $this->fornecedor((string)$cod);
    }

    private function localizacao(string $cod, int $nivel = 0): ?int
    {
        if ($cod === '' || !isset($this->locais[$cod]) || $nivel > 20) return null;
        $l = $this->locais[$cod];
        $nome = self::cortar($l['DscLocObj'] ?? '', 120) ?? $cod;
        $paiCod = (string)($l['CodPaiLoc'] ?? '');
        $pai = ($paiCod !== '' && $paiCod !== $cod) ? $this->localizacao($paiCod, $nivel + 1) : null;
        return $this->obter('localizacoes', $cod, "$cod — $nome",
            fn() => qv('SELECT id FROM localizacoes WHERE codigo = ? OR (nome = ? AND parent_id <=> ?) ORDER BY codigo = ? DESC LIMIT 1', [$cod, $nome, $pai, $cod]),
            fn() => insert('localizacoes', ['parent_id' => $pai, 'codigo' => mb_substr($cod, 0, 30), 'nome' => $nome, 'descricao' => 'Importado do ManWinWin']));
    }

    private function fornecedor(string $cod): ?int
    {
        if ($cod === '' || !isset($this->fornec[$cod])) return null;
        $f = $this->fornec[$cod];
        $nome = self::cortar(($f['NomCmlFcd'] ?? '') ?: ($f['NomFtsFcd'] ?? ''), 150) ?? $cod;
        $fiscal = trim((string)($f['NomFtsFcd'] ?? ''));
        $obs = 'Importado do ManWinWin (código ' . $cod . ')' . ($fiscal !== '' && strcasecmp($fiscal, $nome) !== 0 ? "\nRazão social: $fiscal" : '');
        $dados = ['nome' => $nome, 'observacoes' => $obs];
        foreach (['cnpj' => ['NroCtbFcd', 'NroFiscal', 'NifForncd'], 'telefone' => ['TelForncd', 'NroTelFcd'], 'email' => ['EmlForncd', 'Email_Fcd'], 'contato' => ['CttForncd', 'NomCttFcd']] as $campo => $colunas) {
            foreach ($colunas as $c) if (($f[$c] ?? '') !== '') { $dados[$campo] = mb_substr($f[$c], 0, $campo === 'email' ? 150 : ($campo === 'contato' ? 100 : 30)); break; }
        }
        return $this->obter('fornecedores', $cod, "$cod — $nome",
            fn() => qv('SELECT id FROM fornecedores WHERE nome = ? LIMIT 1', [$nome]),
            fn() => insert('fornecedores', $dados));
    }

    private function categoria(string $cod): ?int
    {
        if ($cod === '') return null;
        $nome = self::cortar($this->tiposObj[$cod]['dscTipObj'] ?? '', 100) ?? $cod;
        return $this->obter('categorias_ativo', $cod, "$cod — $nome",
            fn() => qv('SELECT id FROM categorias_ativo WHERE nome = ?', [$nome]),
            fn() => insert('categorias_ativo', ['nome' => $nome]));
    }

    private function especialidade(string $cod): ?int
    {
        $nome = $this->especNome($cod);
        if ($nome === null) return null;
        $nome = mb_substr($nome, 0, 80);
        return $this->obter('especialidades', $cod, "$cod — $nome",
            fn() => qv('SELECT id FROM especialidades WHERE nome = ?', [$nome]),
            fn() => insert('especialidades', ['nome' => $nome]));
    }

    /* ---------- ativos ---------- */

    /** Rótulos das características do tipo de equipamento (carTip01_ … carTip40_) por número. */
    private function rotulosCaract(string $tipo): array
    {
        $r = [];
        foreach ($this->tiposObj[$tipo] ?? [] as $col => $v) {
            if (preg_match('/^cartip(\d+)_?$/i', (string)$col, $m) && trim((string)$v) !== '') $r[(int)$m[1]] = trim((string)$v);
        }
        return $r;
    }

    private function ativos(): void
    {
        $criados = [];
        foreach ($this->objetos as $cod => $o) {
            $cod = (string)$cod;
            $nome = self::cortar($o['DscObjeto'] ?? '', 150) ?? $cod;
            $antes = $this->rel['criados']['ativos'] ?? 0;
            $id = $this->obter('ativos', $cod, "$cod — $nome",
                fn() => qv('SELECT id FROM ativos WHERE codigo = ?', [$cod]),
                fn() => insert('ativos', $this->dadosAtivo($cod, $nome, $o)));
            if ($id && ($this->rel['criados']['ativos'] ?? 0) > $antes) $criados[$cod] = $id;
        }
        // hierarquia (equipamento › componente): só nos ativos criados agora
        foreach ($criados as $cod => $id) {
            $pai = (string)($this->objetos[$cod]['CodPaiObj'] ?? '');
            if ($pai === '' || $pai === $cod) continue;
            $paiId = $this->resolvido('ativos', $pai) ?? (qv('SELECT id FROM ativos WHERE codigo = ?', [$pai]) ?: null);
            if ($paiId) qx('UPDATE ativos SET parent_id = ? WHERE id = ?', [$paiId, $id]);
            else $this->aviso("Ativo $cod: o equipamento pai $pai não foi importado; ficou sem pai.");
        }
    }

    private function dadosAtivo(string $cod, string $nome, array $o): array
    {
        $tipo = (string)($o['CodTipObj'] ?? '');
        $rot = $this->rotulosCaract($tipo);
        $vals = [];
        foreach ($o as $col => $v) {
            if (preg_match('/^carobj(\d+)_?$/i', (string)$col, $m) && trim((string)$v) !== '') $vals[(int)$m[1]] = trim((string)$v);
        }
        ksort($vals);
        $fab = $mod = $serie = $ano = null;
        $extras = [];
        foreach ($vals as $n => $v) {
            $r = $rot[$n] ?? "Característica $n";
            if ($fab === null && preg_match('/MARCA|FABRICANTE/iu', $r)) { $fab = $v; continue; }
            if ($mod === null && preg_match('/^MODELO/iu', $r)) { $mod = $v; continue; }
            if ($serie === null && preg_match('/S[ÉE]RIE/iu', $r)) { $serie = $v; continue; }
            if ($ano === null && preg_match('/^ANO\b/iu', $r)) { $ano = $v; continue; }
            $extras[] = mb_convert_case($r, MB_CASE_TITLE) . ': ' . $v;
        }

        $sis = (string)($o['CodSistem'] ?? '');
        $desc = [];
        if ($sis !== '') $desc[] = 'Sistema: ' . $sis . (isset($this->sistemas[$sis]) ? ' — ' . $this->sistemas[$sis]['DscSistem'] : '');
        if ($ano !== null) $desc[] = 'Ano de fabricação: ' . $ano;
        if (($o['RegObjeto'] ?? '') !== '') $desc[] = 'Registro: ' . $o['RegObjeto'];
        foreach ($extras as $x) $desc[] = $x;
        $desc[] = 'Importado do ManWinWin (código ' . $cod . ').';

        $valor = mww_num($o['InvmtoObj'] ?? '');
        if ($valor !== null && $valor <= 0) $valor = null;
        if ($valor !== null && $valor >= 1e10) { $this->aviso("Ativo $cod: valor de aquisição " . num($valor) . ' fora do limite; não importado.'); $valor = null; }
        elseif ($valor !== null && $valor >= 5e6) $this->aviso("Ativo $cod ($nome): valor de aquisição " . moeda($valor) . ' — confira se não houve erro de digitação no ManWinWin.');

        $data = mww_dia($o['DatObjeto'] ?? '');
        if ($data !== null && $data > date('Y-m-d')) $data = null;
        $cc = (string)($o['CodCenCst'] ?? '');
        $hDia = mww_num($o['ObjFunDia'] ?? '');

        return [
            'codigo'         => mb_substr($cod, 0, 40),
            'nome'           => $nome,
            'localizacao_id' => $this->localizacao((string)($o['CodLocObj'] ?? '')),
            'categoria_id'   => $this->categoria($tipo),
            'fornecedor_id'  => $this->fornecedor((string)($o['CodForNcd'] ?? '')),
            'fabricante'     => self::cortar($fab, 100),
            'modelo'         => self::cortar($mod, 100),
            'numero_serie'   => self::cortar($serie, 100),
            'data_aquisicao' => $data,
            'valor_aquisicao'=> $valor,
            'criticidade'    => ['1' => 'A', '2' => 'B', '3' => 'C'][trim((string)($o['CodCriade'] ?? ''))] ?? 'B',
            'status'         => ($o['FlgActivo'] ?? '1') === '0' ? 'desativado' : 'operacional',
            'centro_custo'   => self::cortar($cc !== '' ? (($this->cencus[$cc]['DscCenCst'] ?? '') ?: $cc) : null, 60),
            'horas_operacao_dia' => ($hDia !== null && $hDia > 0 && $hDia <= 24) ? $hDia : 24,
            'descricao'      => implode("\n", $desc),
        ];
    }

    /* ---------- técnicos ---------- */

    private function tecnicos(): void
    {
        foreach ($this->tab('Funcionario') as $f) {
            $cod = (string)($f['CodFunCnr'] ?? '');
            $nome = self::cortar($f['NomFunCnr'] ?? '', 120) ?? $cod;
            if ($cod === '') continue;
            if (strtoupper($cod) === 'DEMO' || stripos((string)($f['Email_Fun'] ?? ''), '@manwinwin.') !== false) {
                $this->ignorar('tecnicos', "$cod — $nome", 'usuário de demonstração do ManWinWin');
                continue;
            }
            $email = filter_var($f['Email_Fun'] ?? '', FILTER_VALIDATE_EMAIL) ?: null;
            $this->obter('tecnicos', $cod, "$cod — $nome",
                fn() => qv('SELECT id FROM tecnicos WHERE nome = ? LIMIT 1', [$nome]),
                fn() => insert('tecnicos', [
                    'nome' => $nome, 'especialidade_id' => $this->especialidade((string)($f['CodEspCld'] ?? '')),
                    'custo_hora' => max(0, (float)(mww_num($f['CstEspCld'] ?? '') ?? 0)), 'email' => $email,
                    'ativo' => ($f['FlgActivo'] ?? '1') === '0' ? 0 : 1,
                ]));
        }
    }

    private function tecnico(string $cod): ?int { return $cod === '' ? null : $this->resolvido('tecnicos', $cod); }

    /* ---------- planos (Fichas + programação em Calibra) ---------- */

    private function otFechada(array $c): bool
    {
        return mww_data($c['DatEncClb'] ?? '') !== null || mww_data($c['DatFimClb'] ?? '') !== null
            || in_array(strtoupper((string)($c['StaOrdClb'] ?? '')), ['FE', 'FC', 'EN', 'CO', 'CN'], true);
    }

    private function otCancelada(array $c): bool { return in_array(strtoupper((string)($c['StaOrdClb'] ?? '')), ['AN', 'CA'], true); }

    private function planos(): void
    {
        $unid = [0 => 'dia', 1 => 'semana', 2 => 'mes', 3 => 'ano'];
        // programações (Calibra) agrupadas pela ficha do equipamento
        $prog = [];
        foreach ($this->tab('Calibra') as $c) {
            $obj = (string)($c['CodObjeto'] ?? ''); $fic = (string)($c['CodFicha_'] ?? '');
            if ($obj !== '' && $fic !== '') $prog["$obj|$fic"][] = $c;
        }
        $fichas = [];
        foreach ($this->tab('Fichas') as $f) {
            $obj = (string)($f['CodObjeto'] ?? ''); $fic = (string)($f['CodFicha_'] ?? '');
            if ($obj !== '' && $fic !== '') $fichas["$obj|$fic"] = $f;
        }
        foreach (array_unique([...array_keys($fichas), ...array_keys($prog)]) as $chave) {
            [$obj, $fic] = explode('|', (string)$chave, 2);
            $f = $fichas[$chave] ?? null;
            $cs = $prog[$chave] ?? [];
            $ref = $cs[0] ?? null;
            $titulo = self::cortar(($f['DscFicha_'] ?? '') ?: ($ref['DscOrdClb'] ?? ''), 150) ?? "Ficha $fic";
            $rotulo = "$obj / $fic — $titulo";
            if (!isset($this->objetos[$obj])) {
                if ($this->objetoExisteNaOrigem($obj)) continue;   // exemplo "Modelo" (já listado nos ativos ignorados)
                $this->ignorar('planos_manutencao', $rotulo, "equipamento $obj não existe no ManWinWin");
                continue;
            }
            $ativoId = $this->resolvido('ativos', $obj);
            if (!$ativoId) { $this->ignorar('planos_manutencao', $rotulo, "ativo $obj não importado"); continue; }

            $codTipTrb = (string)(($f['CodTipTrb'] ?? '') ?: ($ref['CodTipTrb'] ?? ''));
            $tipo = $this->tipoOt($codTipTrb, true);
            if (in_array($tipo, ['corretiva', 'melhoria'], true)) { $this->ignorar('planos_manutencao', $rotulo, 'ficha de trabalho não periódica (' . rotulo('tipo_ot', $tipo) . ')'); continue; }

            // periodicidade: a da programação vigente tem prioridade sobre a da ficha
            $pendente = null; $ultimaExec = null; $ultimaProg = null;
            foreach ($cs as $c) {
                if ($this->otCancelada($c)) continue;
                $dp = mww_dia($c['DatProClb'] ?? '');
                if ($this->otFechada($c)) {
                    $fim = mww_dia(($c['DatFimClb'] ?? '') ?: ($c['DatEncClb'] ?? ''));
                    if ($fim && (!$ultimaExec || $fim > $ultimaExec)) $ultimaExec = $fim;
                } elseif (!$pendente || ($dp && $dp < (mww_dia($pendente['DatProClb'] ?? '') ?? '9999'))) {
                    $pendente = $c;
                }
                if ($dp && (!$ultimaProg || $dp > $ultimaProg)) $ultimaProg = $dp;
            }
            $base = $pendente ?? $ref;
            $n = (int)(mww_num($base['NroPrdClb'] ?? null) ?? mww_num($f['NroPrdFic'] ?? null) ?? 0);
            $u = $unid[(int)(mww_num($base['PrdOrdClb'] ?? null) ?? mww_num($f['PrdFicha_'] ?? null) ?? -1)] ?? null;

            $proxima = null; $ativo = 0;
            if ($n > 0 && $u) {
                if ($pendente) { $proxima = mww_dia($pendente['DatProClb'] ?? '') ?? mww_dia($pendente['DatEmiClb'] ?? ''); }
                elseif ($ultimaProg) { $proxima = somar_periodo($ultimaProg, $n, $u); }
                $ativo = $proxima ? 1 : 0;
            }

            $txtTarefa = (string)(($f['TxtTarefa'] ?? '') ?: ($ref['TxtTarefa'] ?? ''));
            $txtFicha = (string)(($f['TxtFicha_'] ?? '') ?: ($ref['TxtOrdClb'] ?? ''));
            $tarefas = $txtTarefa !== '' ? mww_tarefas($txtTarefa) : mww_tarefas($txtFicha, true);
            $esp = $this->especNome((string)($base['CodEspCld'] ?? ''));
            if ($esp) $this->especialidade((string)$base['CodEspCld']);
            $desc = trim($txtFicha . ($esp ? "\n\nEspecialidade: $esp" : '') . "\n\nFicha ManWinWin $obj / $fic.");
            $duracao = mww_num($f['Tempo_Fic'] ?? '') ?: mww_num($base['Tempo_Clb'] ?? '');

            $antes = $this->rel['criados']['planos_manutencao'] ?? 0;
            $planoId = $this->obter('planos_manutencao', (string)$chave, $rotulo, null, function () use ($ativoId, $titulo, $tipo, $desc, $n, $u, $proxima, $ativo, $ultimaExec, $base, $duracao, $tarefas) {
                $id = insert('planos_manutencao', [
                    'ativo_id' => $ativoId, 'titulo' => $titulo, 'tipo' => $tipo, 'descricao' => $desc, 'gatilho' => 'tempo',
                    'periodicidade' => $n > 0 ? $n : null, 'unidade_periodo' => $u ?? 'mes', 'base_calculo' => 'fixa',
                    'proxima_data' => $proxima, 'dia_ancora' => $proxima ? (int)substr($proxima, 8, 2) : null,
                    'ultima_execucao' => $ultimaExec,
                    // antecedência menor que a periodicidade (a tela de planos exige; senão cada conclusão já geraria a próxima OT)
                    'antecedencia_dias' => ($n > 0 && $u) ? max(0, min(7, intdiv(periodo_em_dias($n, $u), 2))) : 7,
                    'responsavel_id' => $this->tecnico((string)($base['CodFunCnr'] ?? '')),
                    'prioridade' => self::prioridade($base['CriOrdClb'] ?? null),
                    'duracao_estimada' => ($duracao !== null && $duracao > 0 && $duracao < 10000) ? $duracao : null, 'ativo' => $ativo,
                ]);
                foreach ($tarefas as $i => $tx) insert('plano_tarefas', ['plano_id' => $id, 'ordem' => $i + 1, 'descricao' => $tx]);
                return $id;
            });
            if ($planoId) $this->planoChave[$chave] = $planoId;
            if ($planoId && ($this->rel['criados']['planos_manutencao'] ?? 0) > $antes && !$ativo) {
                $this->rel['planos_inativos'][] = $rotulo . ($n > 0 && $u ? ' (sem programação no ManWinWin)' : ' (sem periodicidade)');
            }
        }
    }

    private function objetoExisteNaOrigem(string $cod): bool { return isset($this->codigosOrigem[$cod]); }

    /* ---------- ordens de trabalho (Calibra emitidas/fechadas) ---------- */

    private function ordens(): void
    {
        foreach ($this->tab('Calibra') as $c) {
            $num = (string)($c['CodOrdClb'] ?? '');
            if ($num === '') continue;
            $obj = (string)($c['CodObjeto'] ?? '');
            $titulo = self::cortar($c['DscOrdClb'] ?? '', 150) ?? "OT $num";
            $rotulo = "OT $num — " . ($obj !== '' ? "$obj — " : '') . $titulo;
            if ($obj !== '' && !isset($this->objetos[$obj])) {
                if (!$this->objetoExisteNaOrigem($obj)) $this->ignorar('ordens_trabalho', $rotulo, "equipamento $obj não existe no ManWinWin");
                continue;
            }
            $fechada = $this->otFechada($c);
            $cancelada = $this->otCancelada($c);
            $emitida = mww_data($c['DatEmiClb'] ?? '') !== null;
            if (!$fechada && !$cancelada && !$emitida) continue;   // só programada (ainda não emitida): fica a cargo do plano

            $ativoId = $obj !== '' ? $this->resolvido('ativos', $obj) : null;
            if ($obj !== '' && !$ativoId) { $this->ignorar('ordens_trabalho', $rotulo, "ativo $obj não importado"); continue; }
            $fic = (string)($c['CodFicha_'] ?? '');
            $tipo = $this->tipoOt((string)($c['CodTipTrb'] ?? ''), $fic !== '');
            $planoId = ($fic !== '' && !in_array($tipo, ['corretiva', 'melhoria'], true)) ? ($this->planoChave["$obj|$fic"] ?? null) : null;

            $status = $cancelada ? 'cancelada' : ($fechada ? 'concluida' : 'aberta');
            $abertura = mww_data($c['DatEmiClb'] ?? '') ?? mww_data($c['DatOrdClb'] ?? '') ?? mww_data($c['DatProClb'] ?? '') ?? date('Y-m-d H:i:s');
            $prevista = mww_dia($c['DatProClb'] ?? '') ?? mww_dia($c['DatIniClb'] ?? '');
            $fim = $fechada ? (mww_data($c['DatFimClb'] ?? '') ?? mww_data($c['DatEncClb'] ?? '')) : null;
            if ($fechada && !$cancelada && !$fim) $fim = $abertura;   // fechada só pelo código de situação: sem data de fim ela sumiria dos relatórios
            $inicio = $fechada ? (mww_data($c['DatIniClb'] ?? '') ?? $fim) : null;
            if ($inicio && $fim && $inicio > $fim) $inicio = $fim;
            if ($fim && $abertura > $fim) $abertura = $inicio ?? $fim;

            $esp = $this->especNome((string)($c['CodEspCld'] ?? ''));
            if ($esp) $this->especialidade((string)$c['CodEspCld']);
            $desc = trim((string)($c['TxtOrdClb'] ?? ''));
            $desc .= ($desc !== '' ? "\n\n" : '') . ($esp ? "Especialidade: $esp\n" : '') . "OT ManWinWin nº $num"
                   . (($c['StaOrdClb'] ?? '') !== '' ? ' (situação ' . $c['StaOrdClb'] . ')' : '')
                   . (($lim = mww_dia($c['DatLimExe'] ?? '')) ? ', limite de execução ' . date('d/m/Y', strtotime($lim)) : '') . '.';
            $sol = [];
            foreach (['ObsOrdClb' => 'Relatório', 'MaoObra__' => 'Mão de obra', 'Artigos__' => 'Materiais', 'Servicos_' => 'Serviços', 'Despesas_' => 'Despesas'] as $col => $r) {
                if (trim((string)($c[$col] ?? '')) !== '') $sol[] = "$r: " . trim((string)$c[$col]);
            }
            $custo = $fechada ? (mww_num($c['CstTotClb'] ?? '') ?? 0) : 0;
            if ($custo > 0) $sol[] = 'Custo total registrado no ManWinWin: ' . moeda($custo) . ' (lançado como custo externo).';
            $causa = (string)($c['CodCausa_'] ?? '');
            $tarefas = mww_tarefas((string)($c['TxtTarefa'] ?? ''));
            if (!$tarefas && $planoId) $tarefas = array_column(q('SELECT descricao FROM plano_tarefas WHERE plano_id = ? ORDER BY ordem, id', [$planoId]), 'descricao');

            $this->obter('ordens_trabalho', $num, $rotulo, null, function () use ($c, $ativoId, $planoId, $tipo, $status, $titulo, $desc, $abertura, $prevista, $inicio, $fim, $sol, $custo, $causa, $tarefas, $fechada) {
                $id = insert('ordens_trabalho', [
                    'numero' => $this->numero('ordens_trabalho', 'OT'), 'ativo_id' => $ativoId, 'plano_id' => $planoId,
                    'tipo' => $tipo, 'prioridade' => self::prioridade(($c['CriOrdClb'] ?? '') ?: ($c['PriOrdClb'] ?? null)),
                    'status' => $status, 'titulo' => $titulo, 'descricao' => $desc,
                    'responsavel_id' => $this->tecnico((string)($c['CodFunCnr'] ?? '')),
                    'data_abertura' => $abertura, 'data_prevista' => $prevista, 'data_inicio' => $inicio, 'data_fim' => $fim,
                    'data_plano' => $planoId && $prevista ? substr((string)$prevista, 0, 10) : null,   // adiar a OT depois não desloca o plano
                    'causa_falha' => $causa !== '' ? self::cortar(($this->causas[$causa] ?? '') ?: $causa, 150) : null,
                    'solucao' => $sol ? implode("\n\n", $sol) : null, 'custo_externo' => round($custo, 2),
                    'criado_por' => $this->usuarioId,
                ]);
                foreach ($tarefas as $i => $tx) insert('ot_tarefas', ['ot_id' => $id, 'ordem' => $i + 1, 'descricao' => $tx, 'concluida' => $fechada ? 1 : 0]);
                return $id;
            });
        }
    }

    /* ---------- pedidos de trabalho (PTs) ---------- */

    private function pedidos(): void
    {
        $pts = $this->tab('PTs');
        if (!$pts) return;
        $solic = $this->usuarioId ?? (int)qv("SELECT id FROM usuarios WHERE perfil = 'admin' AND ativo = 1 ORDER BY id LIMIT 1");
        foreach ($pts as $p) {
            $num = (string)($p['NroPedTrb'] ?? '');
            if ($num === '') continue;
            $obj = (string)($p['CodObjeto'] ?? '');
            $titulo = self::cortar($p['DscPedTrb'] ?? '', 150) ?? "Pedido $num";
            $rotulo = "PT $num — $titulo";
            if ($obj !== '' && !isset($this->objetos[$obj])) {
                if (!$this->objetoExisteNaOrigem($obj)) $this->ignorar('pedidos', $rotulo, "equipamento $obj não existe no ManWinWin");
                continue;
            }
            $ativoId = $obj !== '' ? $this->resolvido('ativos', $obj) : null;
            $otNum = (string)($p['CodOrdClb'] ?? '');
            $otId = $otNum !== '' ? $this->resolvido('ordens_trabalho', $otNum) : null;
            if ($otId && qv('SELECT 1 FROM ordens_trabalho WHERE id = ? AND pedido_id IS NOT NULL', [$otId])) $otId = null;
            $desc = trim((string)($p['ObsPedTrb'] ?? ''));
            $desc .= ($desc !== '' ? "\n\n" : '') . "Pedido ManWinWin nº $num" . (($p['CodFunCnr'] ?? '') !== '' ? ', solicitado por ' . $p['CodFunCnr'] : '')
                   . (($p['StaPedTrb'] ?? '') !== '' ? ' (situação ' . $p['StaPedTrb'] . ')' : '') . '.';
            $this->obter('pedidos', $num, $rotulo, null, function () use ($p, $ativoId, $otId, $solic, $titulo, $desc) {
                $id = insert('pedidos', [
                    'numero' => $this->numero('pedidos', 'PT'), 'ativo_id' => $ativoId, 'solicitante_id' => $solic,
                    'titulo' => $titulo, 'descricao' => $desc, 'prioridade' => self::prioridade($p['PriPedTrb'] ?? null),
                    'status' => $otId ? 'convertido' : 'aberto', 'ot_id' => $otId,
                    'criado_em' => mww_data($p['DatPedTrb'] ?? '') ?? date('Y-m-d H:i:s'),
                ]);
                if ($otId) qx('UPDATE ordens_trabalho SET pedido_id = ? WHERE id = ?', [$id, $otId]);
                return $id;
            });
        }
    }

    /* ---------- artigos (opcional) ---------- */

    private function artigos(): void
    {
        $fam = [];
        foreach ($this->tab('ARQ02') as $r) $fam[($r['CodClasse'] ?? '') . '|' . ($r['CodFamLia'] ?? '')] = $r['DSCFAMLIA'] ?? ($r['DscFamLia'] ?? '');
        $saldo = [];
        foreach ($this->tab('Artigos') as $r) {
            $c = (string)($r['CodArtigo'] ?? '');
            if ($c === '') continue;
            $s = &$saldo[$c];
            $s['qtd'] = ($s['qtd'] ?? 0) + (mww_num($r['SldExsArt'] ?? '') ?? 0);
            $s['min'] = ($s['min'] ?? 0) + (mww_num($r['SitMinArt'] ?? '') ?? 0);
            $s['custo'] = mww_num($r['CstMedArt'] ?? '') ?: ($s['custo'] ?? null);
            $s['loc'] = ($s['loc'] ?? '') ?: (string)($r['LocLzcArt'] ?? '');
            unset($s);
        }
        foreach ($this->tab('MestreArtigos') as $a) {
            $cod = (string)($a['CodArtigo'] ?? '');
            if ($cod === '') continue;
            $desc = self::cortar($a['DscArtigo'] ?? '', 200) ?? $cod;
            $s = $saldo[$cod] ?? [];
            $custo = (float)(($s['custo'] ?? null) ?: (mww_num($a['CstMedArt'] ?? '') ?? 0));
            $this->obter('artigos', $cod, "$cod — $desc", fn() => qv('SELECT id FROM artigos WHERE codigo = ?', [$cod]), function () use ($cod, $desc, $a, $s, $custo, $fam) {
                $id = insert('artigos', [
                    'codigo' => mb_substr($cod, 0, 40), 'descricao' => $desc, 'unidade' => mb_strtolower(mb_substr(($a['UndArtigo'] ?? '') ?: 'un', 0, 10)),
                    'familia' => self::cortar($fam[($a['CodClasse'] ?? '') . '|' . ($a['CodFamLia'] ?? '')] ?? '', 80),
                    'estoque_minimo' => max(0, (float)($s['min'] ?? 0)), 'custo_medio' => max(0, round($custo, 4)),
                    'localizacao' => self::cortar($s['loc'] ?? '', 80),
                ]);
                $qtd = (float)($s['qtd'] ?? 0);
                if ($qtd > 0) {
                    qx('UPDATE artigos SET estoque_atual = ? WHERE id = ?', [$qtd, $id]);
                    insert('movimentos_estoque', ['artigo_id' => $id, 'tipo' => 'ajuste', 'quantidade' => $qtd, 'custo_unitario' => max(0, $custo),
                        'saldo_apos' => $qtd, 'documento' => 'ManWinWin', 'observacao' => 'Saldo inicial importado do ManWinWin', 'usuario_id' => $this->usuarioId]);
                }
                return $id;
            });
        }
    }
}

/** Resumo de uma linha para auditoria/flash. */
function mww_resumo(array $rel): string
{
    $p = [];
    foreach (MWW_ROTULOS as $t => $r) if (!empty($rel['criados'][$t])) $p[] = $rel['criados'][$t] . ' ' . mb_strtolower($r);
    return $p ? implode(', ', $p) : 'nenhum registro novo';
}
