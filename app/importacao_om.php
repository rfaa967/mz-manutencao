<?php
declare(strict_types=1);

/**
 * Importação das planilhas de controle de OM (Excel .xlsx) usadas pelo departamento de manutenção:
 * OMM (mecânica), OME (elétrica) e OMC/SMC (civil) — uma aba por mês, colunas
 * Nº SM | Data/Sol | Empresa | Solicitante | Equipamento | Cód. Patrimônio | (Colaborador) Responsável | Data início | Data término | OBS.
 *
 * Cada linha vira uma OT sem plano (OM): área pela planilha, status pelas datas (término → concluída,
 * só início → em execução, nenhuma → aberta). O patrimônio é normalizado (C1 10 016 → C1.10.016) e, se
 * houver ativo com esse patrimônio, a OT é ligada a ele. Responsáveis viram técnicos.
 *
 * A mesma SM copiada de um mês para o outro (pendência carregada) entra uma vez só, JUNTANDO as informações
 * das cópias (a data de término de qualquer uma delas vale). Duas linhas iguais na mesma aba são OMs distintas.
 * Reenviar a planilha atualizada: linhas novas entram; OMs que estavam pendentes e ganharam data de término
 * são concluídas; o que já foi encerrado ou editado no sistema não é tocado.
 * Vínculo linha → OT na tabela importacao_mapa (origem 'planilha_om').
 */

const OM_ORIGEM = 'planilha_om';
const OM_ROTULOS = ['ordens_trabalho' => 'OMs (ordens de trabalho)', 'tecnicos' => 'Técnicos'];
const OM_MESES = ['janeiro' => 1, 'fevereiro' => 2, 'marco' => 3, 'abril' => 4, 'maio' => 5, 'junho' => 6, 'julho' => 7,
                  'agosto' => 8, 'setembro' => 9, 'outubro' => 10, 'novembro' => 11, 'dezembro' => 12, 'desembro' => 12];

/* ===================== leitor mínimo de .xlsx (sem dependências) ===================== */

// O XML é carregado em memória pela libxml (fora do memory_limit do PHP): os limites abaixo contêm um arquivo malicioso.
const XLSX_MAX_PARTE = 8 * 1048576;     // cada XML interno, descompactado
const XLSX_MAX_TOTAL = 30 * 1048576;    // soma dos XMLs lidos
const XLSX_MAX_ABAS = 40;
const XLSX_MAX_LINHAS = 20000;          // por aba

/** Lê um .xlsx e devolve [nome da aba => [nº da linha => [coluna (0..n) => valor bruto]]]. */
function xlsx_ler(string $caminho): array
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('A extensão zip do PHP não está instalada no servidor.');
    $z = new ZipArchive();
    if ($z->open($caminho) !== true) throw new RuntimeException('Arquivo .xlsx inválido ou corrompido.');
    try {
        $total = 0;
        $xml = function (string $nome, bool $obrigatorio = false) use ($z, &$total): ?SimpleXMLElement {
            if ($z->statName($nome) === false) {
                if ($obrigatorio) throw new RuntimeException('O arquivo não é uma planilha do Excel (.xlsx).');
                return null;
            }
            // lê no máximo o limite + 1 byte: o tamanho declarado no zip não é confiável
            $c = $z->getFromName($nome, XLSX_MAX_PARTE + 1);
            if ($c === false) throw new RuntimeException("Não foi possível ler $nome dentro da planilha.");
            $total += strlen($c);
            if (strlen($c) > XLSX_MAX_PARTE || $total > XLSX_MAX_TOTAL) throw new RuntimeException('Planilha grande demais para importar (limite de 8 MB por aba e 30 MB no total, descompactada).');
            if (stripos($c, '<!DOCTYPE') !== false || stripos($c, '<!ENTITY') !== false) throw new RuntimeException("Planilha recusada: $nome contém uma declaração DOCTYPE/ENTITY.");
            $antes = libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $ok = $dom->loadXML($c, LIBXML_NONET | LIBXML_COMPACT);
            libxml_clear_errors(); libxml_use_internal_errors($antes);
            if (!$ok) throw new RuntimeException("Planilha corrompida: não foi possível interpretar $nome.");
            // o teste acima olha bytes: XML em UTF-16/UTF-7 passaria. Quem decide é o documento já interpretado.
            if ($dom->doctype !== null) throw new RuntimeException("Planilha recusada: $nome contém uma declaração DOCTYPE/ENTITY.");
            return simplexml_import_dom($dom);
        };
        $wb = $xml('xl/workbook.xml', true);
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
        unset($s);
        $abas = [];
        $usados = [];
        foreach ($wb->sheets->sheet as $sh) {
            if (count($abas) >= XLSX_MAX_ABAS) throw new RuntimeException('A planilha tem abas demais (limite de ' . XLSX_MAX_ABAS . ').');
            $rid = (string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $alvo = $rels[$rid] ?? '';
            if ($alvo === '') continue;
            $alvo = str_starts_with($alvo, '/') ? ltrim($alvo, '/') : 'xl/' . $alvo;
            if (isset($usados[$alvo])) continue;          // duas abas apontando para o mesmo XML: lê uma vez só
            $usados[$alvo] = true;
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
                if (array_filter($l, fn($x) => trim((string)$x) !== '')) {
                    if (count($linhas) >= XLSX_MAX_LINHAS) throw new RuntimeException('A aba "' . (string)$sh['name'] . '" tem linhas demais (limite de ' . XLSX_MAX_LINHAS . ').');
                    $linhas[(int)$row['r']] = $l;
                }
            }
            unset($sx);
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
    foreach (str_split((string)preg_replace('/[^A-Z]/', '', strtoupper($ref))) as $ch) { if ($ch !== '') $n = $n * 26 + (ord($ch) - 64); }
    return max(0, min(16383, $n - 1));
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
    // erros de digitação comuns: "07/07//26", "14/08/2.6", "05/1026", "03/06026"
    if (str_contains($v, '/')) {
        $v = (string)preg_replace('#/{2,}#', '/', (string)preg_replace('#(?<=\d)[.,\s](?=\d)#', '', $v));
        if (preg_match('#^(\d{1,2})/(\d{2})0?(\d{2})$#', $v, $m)) $v = "$m[1]/$m[2]/$m[3]";
    }
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2}|\d{4})$#', $v, $m)) {
        $a = strlen($m[3]) === 2 ? 2000 + (int)$m[3] : (int)$m[3];
        return checkdate((int)$m[2], (int)$m[1], $a) ? sprintf('%04d-%02d-%02d', $a, $m[2], $m[1]) : null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return substr($v, 0, 10);
    return null;
}

/** om_data() como era até a v3.3.0 (sem corrigir erros de digitação): só para recompor a chave das OMs importadas por ela. */
function om_data_v330($v): ?string
{
    $v = trim((string)$v);
    if ($v === '' || str_contains($v, '//') || !preg_match('#^(\d+(\.\d+)?|\d{1,2}/\d{1,2}/(\d{2}|\d{4})|\d{4}-\d{2}-\d{2}.*)$#', $v)) return null;
    return om_data($v);
}

function om_chave_texto(string $v): string
{
    $v = mb_strtolower(trim($v));
    $v = strtr($v, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c', '°' => '', 'º' => '']);
    return trim((string)preg_replace('/[^a-z0-9]+/', ' ', $v));
}

function om_nome_proprio(string $v): string
{
    $v = trim((string)preg_replace('/\s+/', ' ', $v));
    return mb_convert_case(mb_strtolower($v), MB_CASE_TITLE);
}

/* ===================== importação ===================== */

final class ImportadorPlanilhasOM
{
    private array $rel = ['criados' => [], 'existentes' => [], 'atualizados' => [], 'novos' => [], 'alterados' => [], 'ignorados' => [], 'avisos' => [], 'resumo' => []];
    private array $tecnicos = [];     // chave normalizada → id
    private array $ativosPat = [];    // patrimônio → id
    private bool $simulacao = false;
    private int $seqSim = 0;

    /** $arquivos: [['caminho' => ..., 'nome' => nome original], ...] */
    public function __construct(private array $arquivos, private ?int $usuarioId = null) {}

    public function executar(bool $aplicar): array
    {
        $this->simulacao = !$aplicar;
        $linhas = [];
        foreach ($this->arquivos as $a) $linhas = array_merge($linhas, $this->lerArquivo($a['caminho'], $a['nome']));
        $linhas = $this->deduplicar($linhas);
        if ((int)qv("SELECT GET_LOCK('mzmanut_importacao', 0)") !== 1) throw new RuntimeException('Já existe uma importação em andamento. Aguarde e tente de novo.');
        try {
            @set_time_limit(300);
            $pdo = db();
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

    private array $datasRuins = [];

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

    /** Ano da planilha pelo nome do arquivo ("… 2026.xlsx" ou "… - 26.xlsx"); sem isso, o ano corrente (com aviso). */
    private function ano(string $nome, array $abas): int
    {
        // o ano das datas da própria planilha vale mais que o nome do arquivo ("OMM atualizada 27-11-26.xlsx" não é de 2027)
        $anos = [];
        foreach ($abas as $linhas) foreach ($linhas as $l) foreach ($l as $cel) {
            $cel = (string)$cel;
            if ($cel === '' || strlen($cel) > 12 || !preg_match('#^\d{5}(\.\d+)?$|^\d{1,2}/\d{1,2}/\d{2,4}$#', $cel)) continue;
            $d = om_data($cel);
            if ($d !== null && $d >= '2020-01-01' && $d <= '2049-12-31') $anos[(int)substr($d, 0, 4)] = ($anos[(int)substr($d, 0, 4)] ?? 0) + 1;
        }
        arsort($anos);
        $moda = $anos && reset($anos) >= 5 && reset($anos) >= 0.6 * array_sum($anos) ? (int)array_key_first($anos) : null;
        $doNome = null;
        $base = pathinfo($nome, PATHINFO_FILENAME);
        if (preg_match_all('/(?<!\d)(20[2-4]\d)(?!\d)/', $base, $m)) $doNome = (int)end($m[1]);
        elseif (preg_match_all('/(?<!\d)([2-4]\d)(?!\d)/', $base, $m)) $doNome = 2000 + (int)end($m[1]);
        if ($moda !== null) {
            if ($doNome !== null && $doNome !== $moda) $this->rel['avisos'][] = "$nome: o nome do arquivo sugere $doNome, mas as datas da planilha são de $moda; usei $moda.";
            return $moda;
        }
        if ($doNome !== null) return $doNome;
        $this->rel['avisos'][] = "$nome: não deu para saber o ano pelas datas nem pelo nome do arquivo; usei " . date('Y') . '. Renomeie o arquivo com o ano (ex.: "OMM 2026.xlsx") se for de outro.';
        return (int)date('Y');
    }

    private function lerArquivo(string $caminho, string $nome): array
    {
        $abas = xlsx_ler($caminho);
        $area = $this->area($nome, $abas);
        // a área faz parte da identificação de cada OM: sem ela, reenviar a planilha com outro nome duplicaria tudo
        if (!$area) throw new RuntimeException("$nome: não deu para saber a área pelo nome do arquivo. Renomeie com OMM (mecânica), OME (elétrica) ou OMC/SMC (civil) — ex.: \"OMM 2026.xlsx\" — e envie de novo.");
        $ano = $this->ano($nome, $abas);
        // abas que não são meses (rascunhos como "Planilha1") primeiro: o que estiver nos meses prevalece
        $ordenadas = [];
        foreach ($abas as $n => $l) if (!isset(OM_MESES[om_chave_texto((string)$n)])) $ordenadas[$n] = $l;
        foreach ($abas as $n => $l) if (isset(OM_MESES[om_chave_texto((string)$n)])) $ordenadas[$n] = $l;
        $res = [];
        foreach ($ordenadas as $nomeAba => $linhas) {
            $nomeAba = (string)$nomeAba;
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
            $cIni = $col('data inicio'); $cFim = $col('data termino'); $cObs = $col('obs', 'descricao');
            $mesAba = $mes ? sprintf('%04d-%02d-01', $ano, $mes) : null;
            // datas digitadas erradas (ex.: 2006 no lugar de 2026) ficam de fora: vale de um ano antes da planilha até 60 dias à frente
            $ondeData = '';
            $ok = function (string $txt) use ($ano, &$ondeData): ?string {
                $d = om_data($txt);
                if ($d !== null && $d >= ($ano - 1) . '-01-01' && $d <= date('Y-m-d', strtotime('+60 days'))) return $d;
                // data fora do período ou texto com números que não forma uma data: fica de fora, mas é listada
                if ($d !== null || (preg_match('/\d/', $txt) && !preg_match('/^ok\b/i', $txt)) || str_starts_with($txt, '#')) $this->datasRuins["$ondeData: \"" . mb_strimwidth($txt, 0, 20, '…') . '"'] = true;
                return null;
            };
            foreach ($linhas as $nl => $l) {
                if ($nl <= $cab) continue;
                $g = fn(?int $c) => $c === null ? '' : trim(str_replace(["\r\n", "\r"], "\n", (string)($l[$c] ?? '')));
                $eq = $g($cEq); $obs = $g($cObs);
                if (!preg_match('/\pL/u', $eq) && !preg_match('/\pL/u', $obs)) { $eq = ''; $obs = ''; }   // só pontuação/asteriscos
                if (($eq === '' && $obs === '') || preg_match('/^\*+$|^feriado$/i', $eq)) {
                    if ($eq === '' && $obs === '' && (om_data($g($cIni)) || om_data($g($cFim)) || om_data($g($cSol)))) $this->rel['ignorados'][] = ['ordens_trabalho', mb_strimwidth("$nome / $nomeAba / linha $nl", 0, 160, '…'), 'tem datas, mas não tem equipamento nem observação'];
                    if ($eq !== '' || $obs !== '') $this->rel['ignorados'][] = ['ordens_trabalho', mb_strimwidth("$nome / $nomeAba / linha $nl", 0, 160, '…'), mb_strimwidth($eq ?: 'linha sem equipamento', 0, 120, '…')];
                    continue;
                }
                $fimTxt = $g($cFim);
                $ondeData = "$nomeAba linha $nl";
                $v330 = function (string $txt) use ($ano): ?string {
                    $d = om_data_v330($txt);
                    return $d !== null && $d >= ($ano - 1) . '-01-01' && $d <= date('Y-m-d', strtotime('+60 days')) ? $d : null;
                };
                $res[] = [
                    'antiga_data' => $v330($g($cSol)) ?? $v330($g($cIni)) ?? $mesAba,
                    'arquivo' => $nome, 'aba' => $nomeAba, 'linha' => $nl, 'area' => $area, 'ano' => $ano,
                    'numero' => ltrim((string)preg_replace('/\s+/', '', $g($cNum)), '.'), 'data_sol' => $ok($g($cSol)), 'mes_aba' => $mesAba,
                    'empresa' => $g($cEmp), 'solicitante' => $g($cSolic), 'equipamento' => $eq, 'patrimonio' => $g($cPat),
                    'responsavel' => $g($cResp), 'inicio' => $ok($g($cIni)), 'fim' => $ok($fimTxt),
                    'fim_ok' => (bool)preg_match('/^ok\b/i', $fimTxt), 'obs' => $obs,
                ];
            }
        }
        if ($this->datasRuins) {
            $n = count($this->datasRuins);
            $this->rel['avisos'][] = "$nome: $n data(s) não reconhecida(s) ou fora do período foram desconsideradas — confira estas OMs (podem ter entrado como abertas): "
                . implode('; ', array_slice(array_keys($this->datasRuins), 0, 40)) . ($n > 40 ? '; …' : '') . '.';
            $this->datasRuins = [];
        }
        return $res;
    }

    /**
     * Agrupa as cópias da mesma OM em abas diferentes e junta as informações (a mais recente prevalece; o que ela
     * não tem vem das anteriores). Linhas iguais NA MESMA aba são OMs distintas (ex.: o mesmo serviço em dois dias).
     * Chave: com nº de SM → área|ano|nº|equipamento (texto parecido conta, p/ redigitações entre meses);
     *        sem nº → área|ano|equipamento|data da solicitação (ou mês da aba). Responsável e datas de execução ficam
     *        fora da chave: preenchê-los depois na planilha não cria outra OM.
     */
    private function deduplicar(array $linhas): array
    {
        $grupos = [];        // chave → linha consolidada
        $porNumero = [];     // arquivo|nº → chaves-base já vistas
        $naAba = [];         // chave-base|arquivo|aba → quantas vezes já apareceu nessa aba
        $donoAntiga = [];    // chave da v3.3.0 → grupo que a herda
        // textos que aparecem tal e qual em cada aba: um serviço que continua listado não é "redigitação" de outro parecido
        $exatasNaAba = [];
        foreach ($linhas as $l) {
            $n0 = ltrim($l['numero'], '0');
            if ($n0 !== '') $exatasNaAba["{$l['arquivo']}|{$l['aba']}|{$l['area']}|{$l['ano']}|n$n0|" . (om_chave_texto($l['equipamento']) ?: 'obs ' . om_chave_texto($l['obs']))] = true;
        }
        foreach ($linhas as $l) {
            $eqK = om_chave_texto($l['equipamento']);
            $num = ltrim($l['numero'], '0');
            // chave usada até a versão 3.3.0 (para reconhecer o que já foi importado por ela)
            $antiga = sha1($num !== '' ? "{$l['area']}|n$num|$eqK"
                                       : "{$l['area']}|d" . $l['antiga_data'] . '|' . om_chave_texto($l['responsavel']) . "|$eqK");
            if ($eqK === '') $eqK = 'obs ' . om_chave_texto($l['obs']);
            if ($num !== '') {
                $base = "{$l['area']}|{$l['ano']}|n$num|$eqK";
                $cands = $porNumero["{$l['arquivo']}|$num"] ?? [];
                if (!isset($cands[$base])) {   // texto idêntico sempre vence; senão, a cópia MAIS parecida de outra aba (redigitação)
                    $melhor = null; $pMelhor = 60.0;
                    $eqC = substr($eqK, 0, 200);
                    foreach ($cands as $outra) {
                        if ($grupos[$outra]['aba'] === $l['aba'] || isset($exatasNaAba["{$l['arquivo']}|{$l['aba']}|$outra"])) continue;
                        similar_text(substr(om_chave_texto($grupos[$outra]['equipamento']), 0, 200), $eqC, $p);
                        if ($p >= $pMelhor) { $pMelhor = $p; $melhor = $outra; }
                    }
                    if ($melhor !== null) $base = $melhor;
                }
                $porNumero["{$l['arquivo']}|$num"][$base] = $base;
            } else {
                $base = "{$l['area']}|{$l['ano']}|$eqK|d" . ($l['data_sol'] ?? $l['mes_aba'] ?? '');
            }
            $k = "$base|{$l['arquivo']}|{$l['aba']}";
            $ocorrencia = $naAba[$k] ?? 0;
            $naAba[$k] = $ocorrencia + 1;
            $chave = $ocorrencia ? "$base#$ocorrencia" : $base;
            $l['chave'] = sha1($chave);
            $l['chaves_antigas'] = [$antiga => true];
            $donoAntiga[$antiga] = $chave;   // a versão anterior ficava com a ÚLTIMA linha igual: a chave antiga pertence a ela
            if (isset($grupos[$chave])) {
                $ant = $grupos[$chave];
                $this->rel['resumo']['repetidas'] = ($this->rel['resumo']['repetidas'] ?? 0) + 1;
                foreach (['fim', 'inicio', 'data_sol'] as $c) $l[$c] ??= $ant[$c];
                $l['fim_ok'] = $l['fim_ok'] || $ant['fim_ok'];
                foreach (['responsavel', 'patrimonio', 'obs', 'empresa', 'solicitante', 'numero'] as $c) if ($l[$c] === '') $l[$c] = $ant[$c];
                $l['chaves_antigas'] += $ant['chaves_antigas'];
            }
            $grupos[$chave] = $l;
        }
        foreach ($grupos as $chave => $gr) {
            foreach (array_keys($gr['chaves_antigas']) as $a) if (($donoAntiga[$a] ?? $chave) !== $chave) unset($grupos[$chave]['chaves_antigas'][$a]);
        }
        return array_values($grupos);
    }

    private function parecidos(string $a, string $b): bool
    {
        $a = substr(om_chave_texto($a), 0, 200); $b = substr(om_chave_texto($b), 0, 200);   // similar_text é cúbico em textos longos
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

    /** Técnico pelo nome. Erro de digitação de 1 letra é unificado (julan = julian) e avisado. Cria se não existir. */
    private function tecnico(string $nome): ?int
    {
        $nome = implode(' ', array_unique(preg_split('/\s+/', trim($nome)) ?: []));   // "julian julian" → "julian"
        $k = om_chave_texto($nome);
        if ($k === '' || mb_strlen($k) < 3) return null;
        if (isset($this->tecnicos[$k])) return $this->tecnicos[$k];
        foreach ($this->tecnicos as $kk => $id) {
            if (levenshtein($k, (string)$kk) <= 1 && min(strlen($k), strlen((string)$kk)) >= 5) {
                $this->rel['avisos'][] = '"' . mb_substr(om_nome_proprio($nome), 0, 120) . '" foi tratado como o técnico "' . om_nome_proprio((string)$kk) . '" (1 letra de diferença). Se forem pessoas diferentes, cadastre os dois em Técnicos antes de importar.';
                return $this->tecnicos[$k] = $id;
            }
        }
        $id = insert('tecnicos', ['nome' => mb_substr(om_nome_proprio($nome), 0, 120), 'custo_hora' => 0, 'ativo' => 1]);
        $this->conta('criados', 'tecnicos');
        $this->rel['novos']['tecnicos'][] = mb_substr(om_nome_proprio($nome), 0, 120);
        return $this->tecnicos[$k] = $id;
    }

    private function tipo(string $texto): string
    {
        return preg_match('/manuten|troca|repar|consert|arrum|regul|vazam|quebr|defeit|diagnost|rompid|solto|queimad|desmont|ajust|revis|substitu|corrig|falha/u', om_chave_texto($texto))
            ? 'corretiva' : 'melhoria';
    }

    /** OT já importada para esta linha (pela chave atual ou pela chave da versão anterior, que então é atualizada). */
    private function otImportada(array $l): ?int
    {
        $busca = fn(string $ch) => qv('SELECT entidade_id FROM importacao_mapa WHERE origem = ? AND tipo = ? AND chave = ?', [OM_ORIGEM, 'ordens_trabalho', $ch]);
        $id = $busca($l['chave']);
        if ($id !== null) return (int)$id;
        foreach (array_keys($l['chaves_antigas']) as $antiga) {
            $antiga = (string)$antiga;
            if ($antiga === $l['chave'] || ($id = $busca($antiga)) === null) continue;
            qx('UPDATE importacao_mapa SET chave = ? WHERE origem = ? AND tipo = ? AND chave = ?', [$l['chave'], OM_ORIGEM, 'ordens_trabalho', $antiga]);
            return (int)$id;
        }
        return null;
    }

    private function importarLinha(array $l): void
    {
        $origem = "{$l['arquivo']} / {$l['aba']} / linha {$l['linha']}";
        $rotulo = ($l['numero'] !== '' ? "SM {$l['numero']} — " : '') . mb_strimwidth($l['equipamento'] ?: $l['obs'], 0, 80, '…') . " ($origem)";

        // datas e situação
        $inicio = $l['inicio']; $fim = $l['fim'];
        if (!$fim && $l['fim_ok']) $fim = $inicio ?? $l['data_sol'] ?? $l['mes_aba'];
        if ($inicio && $fim && $fim < $inicio) {   // término antes do início: uma das duas foi digitada errada — vale a que cai no mês da aba
            $doMes = $l['mes_aba'] ? substr($l['mes_aba'], 0, 7) : null;
            $usa = ($doMes && substr($fim, 0, 7) === $doMes && substr($inicio, 0, 7) !== $doMes) ? $fim : $inicio;
            if (count($this->rel['avisos']) < 300) $this->rel['avisos'][] = "$origem: término (" . dt($fim) . ') anterior ao início (' . dt($inicio) . '); usei ' . dt($usa) . ' nas duas.';
            $inicio = $fim = $usa;
        }
        $abertura = $l['data_sol'] ?? $inicio ?? $fim ?? $l['mes_aba'] ?? date('Y-m-d');
        if ($inicio && $inicio < $abertura) $abertura = $inicio;   // solicitação registrada depois do início
        if ($fim && $fim < $abertura) $abertura = $fim;
        $status = $fim ? 'concluida' : ($inicio ? 'em_execucao' : 'aberta');

        // responsáveis ("Marlon/Julian": o 1º é o responsável, os demais vão na descrição)
        $nomes = array_values(array_filter(array_map('trim', preg_split('#[/,;&]| e #u', $l['responsavel']) ?: [])));

        $existente = $this->otImportada($l);
        if ($existente !== null) {
            // reenvio da planilha: só avança OMs que continuam pendentes no sistema (nunca reabre nem sobrescreve o que foi editado)
            $ot = q1('SELECT id, numero, status, responsavel_id, data_inicio FROM ordens_trabalho WHERE id = ? FOR UPDATE', [$existente]);
            $avanca = $ot && (($ot['status'] === 'aberta' && $status !== 'aberta') || (in_array($ot['status'], ['aberta', 'planejada', 'em_execucao', 'aguardando_pecas'], true) && $status === 'concluida'));
            if (!$avanca) { $this->conta('existentes', 'ordens_trabalho'); return; }
            // já movimentada aqui (horas, materiais, edição ou mudança de status pelo sistema): quem conclui é o usuário, pelo fluxo normal
            $tocada = qv('SELECT 1 FROM ot_mao_obra WHERE ot_id = ? LIMIT 1', [$ot['id']]) || qv('SELECT 1 FROM ot_materiais WHERE ot_id = ? LIMIT 1', [$ot['id']])
                   || qv("SELECT 1 FROM auditoria WHERE entidade = 'ordens_trabalho' AND entidade_id = ? AND acao <> 'criar' LIMIT 1", [$ot['id']]);
            if ($tocada) {
                if (count($this->rel['avisos']) < 300) $this->rel['avisos'][] = "{$ot['numero']}: a planilha traz " . ($status === 'concluida' ? 'término' : 'início') . ', mas a OT já foi movimentada no sistema — atualize por lá. ' . "($origem)";
                $this->conta('existentes', 'ordens_trabalho'); return;
            }
            $dados = ['status' => $status, 'data_inicio' => $ot['data_inicio'] ?? ($inicio ? "$inicio 08:00:00" : ($fim ? "$fim 08:00:00" : null))];
            if ($fim) {
                $dados['data_fim'] = "$fim 17:00:00";
                if ($dados['data_inicio'] && $dados['data_inicio'] > $dados['data_fim']) $dados['data_inicio'] = "$fim 08:00:00";
                qx('UPDATE ordens_trabalho SET data_abertura = LEAST(data_abertura, ?) WHERE id = ?', ["$fim 08:00:00", $ot['id']]);
            }
            if (!$ot['responsavel_id'] && $nomes) $dados['responsavel_id'] = $this->tecnico($nomes[0]);
            update('ordens_trabalho', $dados, (int)$ot['id']);
            $this->conta('atualizados', 'ordens_trabalho');
            if (count($this->rel['alterados']) < 500) $this->rel['alterados'][] = "{$ot['numero']}: " . rotulo('ot_status', $ot['status']) . ' → ' . rotulo('ot_status', $status) . " — $rotulo";
            return;
        }

        $resp = $nomes ? $this->tecnico($nomes[0]) : null;
        foreach (array_slice($nomes, 1) as $n) $this->tecnico($n);

        // patrimônio → ativo
        $pat = patrimonio_normalizar($l['patrimonio']);
        $ref = null;
        if ($pat !== null && !patrimonio_valido($pat)) { $ref = $l['patrimonio']; $pat = null; }
        $ativoId = $pat ? ($this->ativosPat[$pat] ?? null) : null;
        if ($ativoId) $this->rel['resumo']['ligadas_ativo'] = ($this->rel['resumo']['ligadas_ativo'] ?? 0) + 1;

        $titulo = mb_strimwidth($l['equipamento'] !== '' ? $l['equipamento'] : $l['obs'], 0, 150, '…');
        $desc = [];
        if ($l['obs'] !== '' && $l['obs'] !== '.') $desc[] = $l['obs'];
        if (count($nomes) > 1) $desc[] = 'Executado por: ' . implode(', ', array_map('om_nome_proprio', $nomes)) . '.';
        if ($ref !== null) $desc[] = "Referência/patrimônio informado na planilha: $ref";
        $desc[] = "Importado da planilha de controle de OM ($origem).";

        $otId = insert('ordens_trabalho', [
            // na simulação não usa a sequência real (o UPDATE travaria a criação de OTs dos outros usuários até o rollback)
            'numero' => $this->simulacao ? sprintf('SIMOT-%06d', ++$this->seqSim) : proximo_numero('ordens_trabalho', 'OT'),
            'numero_sm' => $l['numero'] !== '' ? mb_substr($l['numero'], 0, 20) : null,
            'ativo_id' => $ativoId, 'equipamento' => mb_strimwidth($l['equipamento'], 0, 150, '…') ?: null, 'patrimonio' => $pat,
            'tipo' => $this->tipo($l['equipamento'] . ' ' . $l['obs']), 'area' => $l['area'],
            'empresa' => $this->empresa($l['empresa']), 'solicitante' => $l['solicitante'] !== '' && mb_strlen($l['solicitante']) > 1 ? mb_substr(om_nome_proprio($l['solicitante']), 0, 100) : null,
            'prioridade' => 'media', 'status' => $status, 'titulo' => $titulo, 'descricao' => implode("\n\n", $desc),
            'responsavel_id' => $resp, 'data_abertura' => $abertura . ' 08:00:00', 'data_prevista' => $inicio ?? $abertura,
            'data_inicio' => $inicio ? $inicio . ' 08:00:00' : ($fim ? $fim . ' 08:00:00' : null), 'data_fim' => $fim ? $fim . ' 17:00:00' : null,
            'criado_por' => $this->usuarioId,
        ]);
        qx('INSERT INTO importacao_mapa (origem, tipo, chave, entidade_id) VALUES (?, ?, ?, ?)', [OM_ORIGEM, 'ordens_trabalho', $l['chave'], $otId]);
        $this->conta('criados', 'ordens_trabalho');
        $this->rel['resumo']['status'][$status] = ($this->rel['resumo']['status'][$status] ?? 0) + 1;
        $this->rel['resumo']['area'][$l['area'] ?? ''] = ($this->rel['resumo']['area'][$l['area'] ?? ''] ?? 0) + 1;
        if (count($this->rel['novos']['ordens_trabalho'] ?? []) < 500) $this->rel['novos']['ordens_trabalho'][] = $rotulo;
    }
}

function om_resumo(array $rel): string
{
    $p = [];
    if ($n = $rel['criados']['ordens_trabalho'] ?? 0) $p[] = "$n OMs novas";
    if ($n = $rel['atualizados']['ordens_trabalho'] ?? 0) $p[] = "$n OMs atualizadas";
    if ($n = $rel['criados']['tecnicos'] ?? 0) $p[] = "$n técnicos";
    return $p ? implode(', ', $p) : 'nenhum registro novo';
}
