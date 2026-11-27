<?php
declare(strict_types=1);

/**
 * Importação das planilhas de controle de OM (Excel .xlsx) usadas pelo departamento de manutenção:
 * OMM (mecânica), OME (elétrica) e OMC/SMC (civil) — uma aba por mês, colunas
 * Nº SM | Data/Sol | Empresa | Solicitante | Equipamento | Cód. Patrimônio | (Colaborador) Responsável | Data início | Data término | OBS.
 *
 * Cada linha vira uma OT sem plano (OM): área pela planilha, status pelas datas (término → concluída,
 * só início → em execução, nenhuma → aberta). O patrimônio é normalizado (C1 10 016 → C1.10.016) e, se
 * houver ativo com esse patrimônio, a OT é ligada a ele. Responsáveis viram técnicos (nomes com 1 letra
 * de diferença são unificados: "julan" = "Julian").
 * A mesma SM repetida em vários meses (pendência carregada) é importada uma vez, com a situação mais recente.
 * Idempotente pela tabela importacao_mapa (origem 'planilha_om').
 */

const OM_ORIGEM = 'planilha_om';
const OM_ROTULOS = ['ordens_trabalho' => 'OMs (ordens de trabalho)', 'tecnicos' => 'Técnicos'];
const OM_MESES = ['janeiro' => 1, 'fevereiro' => 2, 'marco' => 3, 'abril' => 4, 'maio' => 5, 'junho' => 6, 'julho' => 7,
                  'agosto' => 8, 'setembro' => 9, 'outubro' => 10, 'novembro' => 11, 'dezembro' => 12, 'desembro' => 12];

/* ===================== leitor mínimo de .xlsx (sem dependências) ===================== */

/** Lê um .xlsx e devolve [nome da aba => [linhas => [coluna (0..n) => valor bruto]]]. */
function xlsx_ler(string $caminho): array
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('A extensão zip do PHP não está instalada no servidor.');
    $z = new ZipArchive();
    if ($z->open($caminho) !== true) throw new RuntimeException('Arquivo .xlsx inválido ou corrompido.');
    try {
        $xml = function (string $nome) use ($z): ?SimpleXMLElement {
            $st = $z->statName($nome);
            if (!$st) return null;
            if ((int)$st['size'] > 100 * 1048576) throw new RuntimeException("$nome passa de 100 MB.");
            $c = $z->getFromName($nome);
            return $c === false ? null : (simplexml_load_string($c, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT) ?: null);
        };
        $wb = $xml('xl/workbook.xml');
        if (!$wb) throw new RuntimeException('O arquivo não é uma planilha do Excel (.xlsx).');
        $rels = [];
        if ($r = $xml('xl/_rels/workbook.xml.rels')) foreach ($r->Relationship as $rel) $rels[(string)$rel['Id']] = (string)$rel['Target'];
        $ss = [];
        if ($s = $xml('xl/sharedStrings.xml')) {
            foreach ($s->si as $si) {
                $t = isset($si->t) ? (string)$si->t : '';
                foreach ($si->r as $run) $t .= (string)$run->t;
                $ss[] = $t;
            }
        }
        $abas = [];
        foreach ($wb->sheets->sheet as $sh) {
            $rid = (string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $alvo = $rels[$rid] ?? '';
            if ($alvo === '') continue;
            $alvo = str_starts_with($alvo, '/') ? ltrim($alvo, '/') : 'xl/' . $alvo;
            $sx = $xml($alvo);
            if (!$sx) continue;
            $linhas = [];
            foreach ($sx->sheetData->row as $row) {
                $l = [];
                foreach ($row->c as $c) {
                    $col = xlsx_coluna((string)$c['r']);
                    $t = (string)$c['t'];
                    $v = $t === 'inlineStr' ? (string)$c->is->t : (string)$c->v;
                    if ($t === 's') $v = $ss[(int)$v] ?? '';
                    $l[$col] = $v;
                }
                if (array_filter($l, fn($x) => trim((string)$x) !== '')) $linhas[(int)$row['r']] = $l;
            }
            $abas[(string)$sh['name']] = $linhas;
        }
        return $abas;
    } finally {
        $z->close();
    }
}

/** "C12" → 2 (base 0). */
function xlsx_coluna(string $ref): int
{
    $n = 0;
    foreach (str_split(preg_replace('/\d+/', '', strtoupper($ref))) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return max(0, $n - 1);
}

/** Valor de uma célula de data: número serial do Excel ou texto dd/mm/aaaa. Retorna 'Y-m-d' ou null. */
function om_data($v): ?string
{
    $v = trim((string)$v);
    if ($v === '') return null;
    if (is_numeric($v)) {
        $n = (float)$v;
        if ($n < 30000 || $n > 80000) return null;   // ~1982 a ~2119
        return (new DateTime('1899-12-30'))->modify('+' . (int)floor($n) . ' day')->format('Y-m-d');
    }
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2}|\d{4})$#', $v, $m)) {
        $a = strlen($m[3]) === 2 ? 2000 + (int)$m[3] : (int)$m[3];
        return checkdate((int)$m[2], (int)$m[1], $a) ? sprintf('%04d-%02d-%02d', $a, $m[2], $m[1]) : null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return substr($v, 0, 10);
    return null;
}

function om_chave_texto(string $v): string
{
    $v = mb_strtolower(trim($v));
    $v = strtr($v, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c', '°' => '', 'º' => '']);
    return trim((string)preg_replace('/[^a-z0-9]+/', ' ', $v));
}

function om_nome_proprio(string $v): string
{
    $v = trim(preg_replace('/\s+/', ' ', $v));
    return mb_convert_case(mb_strtolower($v), MB_CASE_TITLE);
}

/* ===================== importação ===================== */

final class ImportadorPlanilhasOM
{
    private array $rel = ['criados' => [], 'existentes' => [], 'novos' => [], 'ignorados' => [], 'avisos' => [], 'resumo' => []];
    private array $tecnicos = [];     // chave normalizada → id
    private array $ativosPat = [];    // patrimônio → id

    /** $arquivos: [['caminho' => ..., 'nome' => nome original], ...] */
    public function __construct(private array $arquivos, private ?int $usuarioId = null) {}

    public function executar(bool $aplicar): array
    {
        if ((int)qv("SELECT GET_LOCK('mzmanut_importacao', 0)") !== 1) throw new RuntimeException('Já existe uma importação em andamento. Aguarde e tente de novo.');
        @set_time_limit(0);
        $linhas = [];
        foreach ($this->arquivos as $a) $linhas = array_merge($linhas, $this->lerArquivo($a['caminho'], $a['nome']));
        $linhas = $this->deduplicar($linhas);
        $pdo = db();
        try {
            $pdo->beginTransaction();
            try {
                foreach (q('SELECT id, nome FROM tecnicos') as $t) $this->tecnicos[om_chave_texto($t['nome'])] = (int)$t['id'];
                foreach (q('SELECT id, patrimonio FROM ativos WHERE patrimonio IS NOT NULL') as $a) $this->ativosPat[$a['patrimonio']] = (int)$a['id'];
                foreach ($linhas as $l) $this->importarLinha($l);
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

    private function conta(string $g, string $t): void { $this->rel[$g][$t] = ($this->rel[$g][$t] ?? 0) + 1; }

    /** Área pela planilha: nome do arquivo (OMM/OME/OMC/SMC) ou o título da primeira aba. */
    private function area(string $nome, array $abas): ?string
    {
        $txt = om_chave_texto($nome . ' ' . implode(' ', array_map(fn($l) => implode(' ', $l), array_slice(reset($abas) ?: [], 0, 2))));
        if (preg_match('/\bomc\b|\bsmc\b|civil/', $txt)) return 'civil';
        if (preg_match('/\bome\b|eletric/', $txt)) return 'eletrica';
        if (preg_match('/\bomm\b|mecanic/', $txt)) return 'mecanica';
        return null;
    }

    private function lerArquivo(string $caminho, string $nome): array
    {
        $abas = xlsx_ler($caminho);
        $area = $this->area($nome, $abas);
        if (!$area) $this->rel['avisos'][] = "$nome: não deu para saber a área (mecânica/elétrica/civil) pelo nome do arquivo; as OMs ficam sem área.";
        $ano = preg_match('/20\d{2}/', $nome, $m) ? (int)$m[0] : (preg_match('/\b(\d{2})\b/', om_chave_texto($nome), $m2) ? 2000 + (int)$m2[1] : (int)date('Y'));
        $res = [];
        $ordemAba = 0;
        foreach ($abas as $nomeAba => $linhas) {
            $ordemAba++;
            $mes = OM_MESES[om_chave_texto($nomeAba)] ?? null;
            // cabeçalho: a linha que tem "equipamento"
            $cab = null; $mapa = [];
            foreach ($linhas as $nl => $l) {
                $norm = array_map(fn($x) => om_chave_texto((string)$x), $l);
                if (in_array('equipamento', $norm, true)) { $cab = $nl; foreach ($norm as $col => $h) $mapa[$col] = $h; break; }
            }
            if ($cab === null) continue;
            $col = function (string ...$nomes) use ($mapa): ?int {
                foreach ($mapa as $c => $h) foreach ($nomes as $n) if ($h === $n || str_starts_with($h, $n)) return $c;
                return null;
            };
            $cNum = $col('n sm', 'n smc', 'n'); $cSol = $col('data sol'); $cEmp = $col('empresa'); $cSolic = $col('solicitante');
            $cEq = $col('equipamento'); $cPat = $col('cod patrimonio', 'patrimonio'); $cResp = $col('colaborador responsavel', 'responsavel');
            $cIni = $col('data inicio'); $cFim = $col('data termino'); $cObs = $col('obs');
            $mesAba = $mes ? sprintf('%04d-%02d-01', $ano, $mes) : null;
            foreach ($linhas as $nl => $l) {
                if ($nl <= $cab) continue;
                $g = fn(?int $c) => $c === null ? '' : trim(str_replace(["\r\n", "\r"], "\n", (string)($l[$c] ?? '')));
                $eq = $g($cEq); $obs = $g($cObs);
                        if (!preg_match('/\pL/u', $eq) && !preg_match('/\pL/u', $obs)) { $eq = ''; $obs = ''; }   // só pontuação/asteriscos
                if (($eq === '' && $obs === '') || preg_match('/^\*+$|^feriado$/i', $eq)) {
                    if ($eq !== '' || $obs !== '') $this->rel['ignorados'][] = ['ordens_trabalho', "$nome / $nomeAba / linha $nl", $eq ?: 'linha sem equipamento'];
                    continue;
                }
                $fimTxt = $g($cFim);
                // datas digitadas erradas (ex.: 2006 no lugar de 2026) ficam de fora: vale só de um ano antes da planilha até 60 dias à frente
                $ok = fn(?string $d) => ($d && $d >= ($ano - 1) . '-01-01' && $d <= date('Y-m-d', strtotime('+60 days'))) ? $d : null;
                $res[] = [
                    'arquivo' => $nome, 'aba' => $nomeAba, 'linha' => $nl, 'ordem' => [$ordemAba, $nl], 'area' => $area,
                    'numero' => ltrim(preg_replace('/\s+/', '', $g($cNum)), '.'), 'data_sol' => $ok(om_data($g($cSol))), 'mes_aba' => $mesAba,
                    'empresa' => $g($cEmp), 'solicitante' => $g($cSolic), 'equipamento' => $eq, 'patrimonio' => $g($cPat),
                    'responsavel' => $g($cResp), 'inicio' => $ok(om_data($g($cIni))), 'fim' => $ok(om_data($fimTxt)),
                    'fim_ok' => (bool)preg_match('/^ok\b/i', $fimTxt), 'obs' => $obs,
                ];
            }
        }
        return $res;
    }

    /** A mesma SM aparece em vários meses enquanto pendente: fica a última versão (a mais atual). */
    private function deduplicar(array $linhas): array
    {
        $porChave = [];
        $porNumero = [];   // área|nº → chaves já vistas (para achar a mesma SM em outro mês, mesmo com o texto redigitado)
        foreach ($linhas as $l) {
            $eqK = om_chave_texto($l['equipamento']);
            $num = ltrim($l['numero'], '0');
            if ($num !== '') {
                $base = $l['area'] . '|n' . $num . '|' . $eqK;
                foreach ($porNumero[$l['area'] . '|' . $num] ?? [] as $outra) {
                    // mesma SM numa aba (mês) anterior e texto parecido (ex.: "Moto esmeril" × "Motor esmeril")
                    if ($porChave[$outra]['aba'] !== $l['aba'] && $this->parecidos($porChave[$outra]['equipamento'], $l['equipamento'])) { $base = $outra; break; }
                }
                $porNumero[$l['area'] . '|' . $num][] = $base;
            } else {
                $base = $l['area'] . '|d' . ($l['data_sol'] ?? $l['inicio'] ?? $l['mes_aba']) . '|' . om_chave_texto($l['responsavel']) . '|' . $eqK;
            }
            if (isset($porChave[$base])) $this->rel['resumo']['repetidas'] = ($this->rel['resumo']['repetidas'] ?? 0) + 1;
            $l['chave'] = sha1($base);
            $porChave[$base] = $l;   // as abas são lidas em ordem: a última (situação mais recente) vence
        }
        return array_values($porChave);
    }

    private function parecidos(string $a, string $b): bool
    {
        $a = om_chave_texto($a); $b = om_chave_texto($b);
        if ($a === $b) return true;
        similar_text($a, $b, $pct);
        return $pct >= 60;
    }

    private function empresa(string $v): ?string
    {
        $k = om_chave_texto($v);
        if ($k === '' || $k === 'ok' || !preg_match('/[a-z]{3}/', $k)) return null;
        if (preg_match('/th?l+h?ven|tlhven|thlv/', $k)) return 'Thlven';
        if (preg_match('/n?alton/', $k)) return 'Nalton';
        if (str_contains($k, 'granja')) return 'Granja';
        return mb_substr(om_nome_proprio($v), 0, 60);
    }

    /** Técnico pelo nome (unifica diferenças de 1 letra: julan = julian). Cria se não existir. */
    private function tecnico(string $nome): ?int
    {
        $nome = implode(' ', array_unique(preg_split('/\s+/', trim($nome))));   // "julian julian" → "julian"
        $k = om_chave_texto($nome);
        if ($k === '' || mb_strlen($k) < 3) return null;
        if (isset($this->tecnicos[$k])) return $this->tecnicos[$k];
        foreach ($this->tecnicos as $kk => $id) if (levenshtein($k, $kk) <= 1 && min(strlen($k), strlen($kk)) >= 5) return $this->tecnicos[$k] = $id;
        $id = insert('tecnicos', ['nome' => mb_substr(om_nome_proprio($nome), 0, 120), 'custo_hora' => 0, 'ativo' => 1]);
        $this->conta('criados', 'tecnicos');
        $this->rel['novos']['tecnicos'][] = om_nome_proprio($nome);
        return $this->tecnicos[$k] = $id;
    }

    private function tipo(string $texto): string
    {
        return preg_match('/manuten|troca|repar|consert|arrum|regul|vazam|quebr|defeit|diagnost|rompid|solto|queimad|desmont|ajust|revis|substitu|corrig|falha/u', om_chave_texto($texto))
            ? 'corretiva' : 'melhoria';
    }

    private function importarLinha(array $l): void
    {
        $origem = "{$l['arquivo']} / {$l['aba']} / linha {$l['linha']}";
        $rotulo = ($l['numero'] !== '' ? "SM {$l['numero']} — " : '') . mb_strimwidth($l['equipamento'] ?: $l['obs'], 0, 80, '…') . " ($origem)";
        $id = qv('SELECT entidade_id FROM importacao_mapa WHERE origem = ? AND tipo = ? AND chave = ?', [OM_ORIGEM, 'ordens_trabalho', $l['chave']]);
        if ($id !== null) { $this->conta('existentes', 'ordens_trabalho'); return; }

        // datas e situação
        $inicio = $l['inicio']; $fim = $l['fim'];
        if (!$fim && $l['fim_ok']) $fim = $inicio ?? $l['data_sol'] ?? $l['mes_aba'];
        if ($inicio && $fim && $fim < $inicio) $inicio = $fim;
        $abertura = $l['data_sol'] ?? $inicio ?? $fim ?? $l['mes_aba'] ?? date('Y-m-d');
        if ($inicio && $inicio < $abertura) $abertura = $inicio;   // solicitação registrada depois do início
        $status = $fim ? 'concluida' : ($inicio ? 'em_execucao' : 'aberta');

        // patrimônio → ativo
        $pat = patrimonio_normalizar($l['patrimonio']);
        $ref = null;
        if ($pat !== null && !patrimonio_valido($pat)) { $ref = $l['patrimonio']; $pat = null; }
        $ativoId = $pat ? ($this->ativosPat[$pat] ?? null) : null;
        if ($ativoId) $this->rel['resumo']['ligadas_ativo'] = ($this->rel['resumo']['ligadas_ativo'] ?? 0) + 1;

        // responsáveis ("Marlon/Julian": o 1º é o responsável, os demais vão na descrição)
        $nomes = array_values(array_filter(array_map('trim', preg_split('#[/,;&]| e #u', $l['responsavel']) ?: [])));
        $resp = $nomes ? $this->tecnico($nomes[0]) : null;
        foreach (array_slice($nomes, 1) as $n) $this->tecnico($n);

        $titulo = mb_strimwidth($l['equipamento'] !== '' ? $l['equipamento'] : $l['obs'], 0, 150, '…');
        $desc = [];
        if ($l['obs'] !== '' && $l['obs'] !== '.') $desc[] = $l['obs'];
        if (count($nomes) > 1) $desc[] = 'Executado por: ' . implode(', ', array_map('om_nome_proprio', $nomes)) . '.';
        if ($ref !== null) $desc[] = "Referência/patrimônio informado na planilha: $ref";
        $desc[] = "Importado da planilha de controle de OM ($origem).";

        $otId = insert('ordens_trabalho', [
            'numero' => proximo_numero('ordens_trabalho', 'OT'), 'numero_sm' => $l['numero'] !== '' ? mb_substr($l['numero'], 0, 20) : null,
            'ativo_id' => $ativoId, 'equipamento' => mb_strimwidth($l['equipamento'], 0, 150, '…') ?: null, 'patrimonio' => $pat,
            'tipo' => $this->tipo($l['equipamento'] . ' ' . $l['obs']), 'area' => $l['area'],
            'empresa' => $this->empresa($l['empresa']), 'solicitante' => $l['solicitante'] !== '' && mb_strlen($l['solicitante']) > 1 ? mb_substr(om_nome_proprio($l['solicitante']), 0, 100) : null,
            'prioridade' => 'media', 'status' => $status, 'titulo' => $titulo, 'descricao' => implode("\n\n", $desc),
            'responsavel_id' => $resp, 'data_abertura' => $abertura . ' 08:00:00', 'data_prevista' => $inicio ?? $abertura,
            'data_inicio' => $inicio ? $inicio . ' 08:00:00' : null, 'data_fim' => $fim ? $fim . ' 17:00:00' : null,
            'criado_por' => $this->usuarioId,
        ]);
        qx('INSERT INTO importacao_mapa (origem, tipo, chave, entidade_id) VALUES (?, ?, ?, ?)', [OM_ORIGEM, 'ordens_trabalho', $l['chave'], $otId]);
        $this->conta('criados', 'ordens_trabalho');
        $this->rel['resumo']['status'][$status] = ($this->rel['resumo']['status'][$status] ?? 0) + 1;
        $this->rel['resumo']['area'][$l['area'] ?? ''] = ($this->rel['resumo']['area'][$l['area'] ?? ''] ?? 0) + 1;
        if (count($this->rel['novos']['ordens_trabalho'] ?? []) < 1000) $this->rel['novos']['ordens_trabalho'][] = $rotulo;
    }
}

function om_resumo(array $rel): string
{
    $p = [];
    if ($n = $rel['criados']['ordens_trabalho'] ?? 0) $p[] = "$n OMs";
    if ($n = $rel['criados']['tecnicos'] ?? 0) $p[] = "$n técnicos";
    return $p ? implode(', ', $p) : 'nenhum registro novo';
}
