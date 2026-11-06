<?php
/** Dados de demonstração — indústria fictícia com 6 meses de histórico. */
function demo_popular(int $adminId, string $senha): void
{
    mt_srand(42);
    $hash = password_hash($senha, PASSWORD_DEFAULT);

    // Localizações
    $fab = insert('localizacoes', ['codigo' => 'FAB', 'nome' => 'Fábrica Tatuí']);
    $l1 = insert('localizacoes', ['codigo' => 'L1', 'nome' => 'Linha de usinagem', 'parent_id' => $fab]);
    $l2 = insert('localizacoes', ['codigo' => 'L2', 'nome' => 'Linha de montagem', 'parent_id' => $fab]);
    $ut = insert('localizacoes', ['codigo' => 'UT', 'nome' => 'Utilidades', 'parent_id' => $fab]);
    $adm = insert('localizacoes', ['codigo' => 'ADM', 'nome' => 'Prédio administrativo']);
    $log = insert('localizacoes', ['codigo' => 'LOG', 'nome' => 'Logística / expedição', 'parent_id' => $fab]);

    $cat = [];
    foreach (['Máquina operatriz', 'Compressor', 'Climatização', 'Bombas', 'Movimentação de carga', 'Elétrica', 'Transportadores'] as $c) $cat[$c] = insert('categorias_ativo', ['nome' => $c]);
    $esp = [];
    foreach (['Mecânica', 'Elétrica', 'Refrigeração', 'Lubrificação'] as $s) $esp[$s] = insert('especialidades', ['nome' => $s]);

    $fAtlas = insert('fornecedores', ['nome' => 'Compressores Paulista Ltda', 'cnpj' => '12.345.678/0001-90', 'contato' => 'Marcos', 'telefone' => '(15) 3251-0000', 'email' => 'vendas@compaulista.com.br']);
    $fRol = insert('fornecedores', ['nome' => 'Rolamentos Sorocaba', 'cnpj' => '98.765.432/0001-10', 'contato' => 'Juliana', 'telefone' => '(15) 3333-1111']);
    $fFrio = insert('fornecedores', ['nome' => 'FrioTec Climatização', 'contato' => 'Pedro', 'telefone' => '(15) 99999-2222']);

    $t1 = insert('tecnicos', ['nome' => 'Carlos Mendes', 'especialidade_id' => $esp['Mecânica'], 'custo_hora' => 65]);
    $t2 = insert('tecnicos', ['nome' => 'Ana Ribeiro', 'especialidade_id' => $esp['Elétrica'], 'custo_hora' => 72]);
    $t3 = insert('tecnicos', ['nome' => 'José Lima', 'especialidade_id' => $esp['Lubrificação'], 'custo_hora' => 48]);
    $t4 = insert('tecnicos', ['nome' => 'FrioTec (terceiro)', 'especialidade_id' => $esp['Refrigeração'], 'fornecedor_id' => $fFrio, 'custo_hora' => 120]);

    insert('usuarios', ['nome' => 'Gestor Demo', 'email' => 'gestor@demo.local', 'senha_hash' => $hash, 'perfil' => 'gestor']);
    insert('usuarios', ['nome' => 'Carlos Mendes', 'email' => 'tecnico@demo.local', 'senha_hash' => $hash, 'perfil' => 'tecnico', 'tecnico_id' => $t1]);
    $sol = insert('usuarios', ['nome' => 'Operador Linha 1', 'email' => 'solicitante@demo.local', 'senha_hash' => $hash, 'perfil' => 'solicitante']);

    $A = fn(array $d) => insert('ativos', $d + ['data_aquisicao' => date('Y-m-d', strtotime('-' . mt_rand(400, 2500) . ' days'))]);
    $cnc = $A(['codigo' => 'CNC-01', 'nome' => 'Centro de usinagem CNC', 'localizacao_id' => $l1, 'categoria_id' => $cat['Máquina operatriz'], 'fabricante' => 'Romi', 'modelo' => 'D 800', 'numero_serie' => 'RM8001234', 'criticidade' => 'A', 'horas_operacao_dia' => 16, 'valor_aquisicao' => 680000, 'centro_custo' => 'USIN']);
    $cncF = $A(['codigo' => 'CNC-01-FU', 'nome' => 'Fuso principal', 'parent_id' => $cnc, 'localizacao_id' => $l1, 'categoria_id' => $cat['Máquina operatriz'], 'criticidade' => 'A', 'horas_operacao_dia' => 16]);
    $A(['codigo' => 'CNC-01-RF', 'nome' => 'Sistema de refrigeração de corte', 'parent_id' => $cnc, 'localizacao_id' => $l1, 'categoria_id' => $cat['Bombas'], 'criticidade' => 'B', 'horas_operacao_dia' => 16]);
    $torno = $A(['codigo' => 'TOR-02', 'nome' => 'Torno CNC', 'localizacao_id' => $l1, 'categoria_id' => $cat['Máquina operatriz'], 'fabricante' => 'Romi', 'modelo' => 'GL 240', 'criticidade' => 'A', 'horas_operacao_dia' => 16, 'centro_custo' => 'USIN']);
    $comp = $A(['codigo' => 'CMP-01', 'nome' => 'Compressor parafuso 40 HP', 'localizacao_id' => $ut, 'categoria_id' => $cat['Compressor'], 'fabricante' => 'Atlas Copco', 'modelo' => 'GA 30', 'fornecedor_id' => $fAtlas, 'criticidade' => 'A', 'horas_operacao_dia' => 20, 'garantia_ate' => date('Y-m-d', strtotime('+8 months'))]);
    $bomba = $A(['codigo' => 'BMB-01', 'nome' => 'Bomba d\'água de recalque', 'localizacao_id' => $ut, 'categoria_id' => $cat['Bombas'], 'fabricante' => 'KSB', 'criticidade' => 'B', 'horas_operacao_dia' => 12]);
    $est = $A(['codigo' => 'EST-01', 'nome' => 'Esteira transportadora montagem', 'localizacao_id' => $l2, 'categoria_id' => $cat['Transportadores'], 'criticidade' => 'B', 'horas_operacao_dia' => 16]);
    $emp = $A(['codigo' => 'EMP-01', 'nome' => 'Empilhadeira elétrica 2,5 t', 'localizacao_id' => $log, 'categoria_id' => $cat['Movimentação de carga'], 'fabricante' => 'Hyster', 'criticidade' => 'B', 'horas_operacao_dia' => 10]);
    $ac = $A(['codigo' => 'HVAC-01', 'nome' => 'Split 60.000 BTU escritório', 'localizacao_id' => $adm, 'categoria_id' => $cat['Climatização'], 'fabricante' => 'Daikin', 'fornecedor_id' => $fFrio, 'criticidade' => 'C', 'horas_operacao_dia' => 10]);
    $qgbt = $A(['codigo' => 'QGBT-01', 'nome' => 'Quadro geral de baixa tensão', 'localizacao_id' => $ut, 'categoria_id' => $cat['Elétrica'], 'criticidade' => 'A', 'horas_operacao_dia' => 24]);

    // Contadores
    $cHor = insert('contadores', ['ativo_id' => $comp, 'nome' => 'Horímetro', 'unidade' => 'h', 'valor_atual' => 0]);
    $cEmp = insert('contadores', ['ativo_id' => $emp, 'nome' => 'Horímetro', 'unidade' => 'h', 'valor_atual' => 0]);
    $v = 11800; $ve = 3150;
    for ($d = 180; $d >= 0; $d -= 7) {
        $v += mt_rand(120, 145); $ve += mt_rand(45, 60);
        $dt = date('Y-m-d 07:30:00', strtotime("-$d days"));
        insert('leituras_contador', ['contador_id' => $cHor, 'valor' => $v, 'data_leitura' => $dt]);
        insert('leituras_contador', ['contador_id' => $cEmp, 'valor' => $ve, 'data_leitura' => $dt]);
    }
    qx('UPDATE contadores SET valor_atual = ?, atualizado_em = NOW() WHERE id = ?', [$v, $cHor]);
    qx('UPDATE contadores SET valor_atual = ?, atualizado_em = NOW() WHERE id = ?', [$ve, $cEmp]);

    // Estoque
    $art = [];
    foreach ([
        ['ROL-6205', 'Rolamento 6205 2RS', 'un', 'Rolamentos', 12, 4, 38.5, $fRol],
        ['ROL-6308', 'Rolamento 6308 ZZ', 'un', 'Rolamentos', 6, 2, 95.0, $fRol],
        ['FIL-AR-GA30', 'Elemento filtro de ar GA30', 'un', 'Filtros', 3, 2, 310.0, $fAtlas],
        ['FIL-OL-GA30', 'Filtro de óleo GA30', 'un', 'Filtros', 4, 2, 185.0, $fAtlas],
        ['OL-COMP', 'Óleo para compressor Roto-Inject', 'l', 'Lubrificantes', 40, 20, 62.0, $fAtlas],
        ['GRX-EP2', 'Graxa EP2', 'kg', 'Lubrificantes', 15, 5, 28.0, null],
        ['OL-HID68', 'Óleo hidráulico ISO 68', 'l', 'Lubrificantes', 60, 40, 19.5, null],
        ['COR-B42', 'Correia em V B-42', 'un', 'Transmissão', 2, 4, 54.0, null],
        ['CONT-25A', 'Contator 25 A 220 V', 'un', 'Elétrica', 3, 2, 145.0, null],
        ['FIL-HVAC', 'Filtro de ar split (par)', 'par', 'Filtros', 6, 2, 45.0, $fFrio],
    ] as [$cod, $desc, $un, $fam, $sal, $min, $custo, $forn]) {
        $art[$cod] = insert('artigos', ['codigo' => $cod, 'descricao' => $desc, 'unidade' => $un, 'familia' => $fam, 'estoque_minimo' => $min, 'fornecedor_id' => $forn, 'custo_medio' => $custo, 'localizacao' => 'Almox. ' . chr(65 + mt_rand(0, 3)) . mt_rand(1, 6)]);
        movimentar_estoque($art[$cod], 'entrada', $sal + 20, $custo, null, 'INICIAL', 'Saldo inicial demo');
    }

    // Planos
    $P = function (array $d, array $tarefas, array $mats = []) {
        $id = insert('planos_manutencao', $d);
        foreach ($tarefas as $i => $t) insert('plano_tarefas', ['plano_id' => $id, 'ordem' => $i + 1, 'descricao' => $t]);
        foreach ($mats as $a => $q) insert('plano_materiais', ['plano_id' => $id, 'artigo_id' => $a, 'quantidade' => $q]);
        return $id;
    };
    $pComp = $P(['ativo_id' => $comp, 'titulo' => 'Revisão 2.000 h — compressor', 'tipo' => 'preventiva', 'gatilho' => 'contador', 'contador_id' => $cHor, 'intervalo_contador' => 2000, 'proximo_valor' => 14000, 'antecedencia_dias' => 100, 'responsavel_id' => $t1, 'prioridade' => 'alta', 'duracao_estimada' => 4],
        ['Trocar elemento filtro de ar', 'Trocar filtro de óleo', 'Trocar óleo', 'Verificar separador ar/óleo', 'Medir corrente do motor', 'Registrar horímetro'],
        [$art['FIL-AR-GA30'] => 1, $art['FIL-OL-GA30'] => 1, $art['OL-COMP'] => 18]);
    $pCnc = $P(['ativo_id' => $cnc, 'titulo' => 'Preventiva mensal CNC', 'tipo' => 'preventiva', 'gatilho' => 'tempo', 'periodicidade' => 1, 'unidade_periodo' => 'mes', 'proxima_data' => date('Y-m-d', strtotime('-150 days')), 'antecedencia_dias' => 5, 'responsavel_id' => $t1, 'duracao_estimada' => 3],
        ['Limpar cavacos e filtros do refrigerante', 'Verificar nível de óleo dos barramentos', 'Checar folgas dos eixos X/Y/Z', 'Inspecionar mangueiras e conexões', 'Testar botões de emergência']);
    $pLub = $P(['ativo_id' => $est, 'titulo' => 'Lubrificação semanal esteira', 'tipo' => 'lubrificacao', 'gatilho' => 'tempo', 'periodicidade' => 2, 'unidade_periodo' => 'semana', 'proxima_data' => date('Y-m-d', strtotime('-160 days')), 'antecedencia_dias' => 2, 'responsavel_id' => $t3, 'duracao_estimada' => 0.5],
        ['Lubrificar mancais', 'Verificar tensão da correia', 'Verificar alinhamento da lona'], [$art['GRX-EP2'] => 0.3]);
    $pAc = $P(['ativo_id' => $ac, 'titulo' => 'PMOC — limpeza de filtros split', 'tipo' => 'preventiva', 'gatilho' => 'tempo', 'periodicidade' => 1, 'unidade_periodo' => 'mes', 'proxima_data' => date('Y-m-d', strtotime('-155 days')), 'antecedencia_dias' => 5, 'responsavel_id' => $t4, 'duracao_estimada' => 1.5, 'base_calculo' => 'fixa'],
        ['Limpar filtros', 'Limpar bandeja e dreno', 'Medir temperatura de insuflamento', 'Registrar no PMOC'], [$art['FIL-HVAC'] => 1]);
    $pQ = $P(['ativo_id' => $qgbt, 'titulo' => 'Termografia do QGBT', 'tipo' => 'preditiva', 'gatilho' => 'tempo', 'periodicidade' => 3, 'unidade_periodo' => 'mes', 'proxima_data' => date('Y-m-d', strtotime('-170 days')), 'antecedencia_dias' => 10, 'responsavel_id' => $t2, 'duracao_estimada' => 2],
        ['Termografar barramentos e disjuntores', 'Reapertar conexões com pontos quentes', 'Emitir laudo']);
    $P(['ativo_id' => $emp, 'titulo' => 'Revisão 500 h empilhadeira', 'tipo' => 'preventiva', 'gatilho' => 'contador', 'contador_id' => $cEmp, 'intervalo_contador' => 500, 'proximo_valor' => 4500, 'antecedencia_dias' => 30, 'responsavel_id' => $t1, 'duracao_estimada' => 3],
        ['Verificar baterias e eletrólito', 'Lubrificar pontos de graxa', 'Checar sistema hidráulico', 'Testar freios e buzina'], [$art['GRX-EP2'] => 0.5, $art['OL-HID68'] => 5]);

    // Histórico: executa as preventivas por tempo dos últimos 6 meses
    $hoje = date('Y-m-d');
    foreach ([$pCnc, $pLub, $pAc, $pQ] as $pid) {
        $guard = 0;
        while ($guard++ < 40) {
            $p = q1('SELECT * FROM planos_manutencao WHERE id = ?', [$pid]);
            if ($p['proxima_data'] >= date('Y-m-d', strtotime('-3 days'))) break;
            $ot = gerar_ot_de_plano($p, $p['proxima_data'], $adminId);
            $atraso = mt_rand(0, 10) > 8 ? mt_rand(2, 6) : mt_rand(0, 1);
            $ini = date('Y-m-d 08:00:00', strtotime($p['proxima_data'] . " +$atraso days"));
            if ($ini > $hoje) $ini = date('Y-m-d 08:00:00');
            $dur = (float)$p['duracao_estimada'] * (mt_rand(80, 130) / 100);
            $fim = date('Y-m-d H:i:s', (int)(strtotime($ini) + $dur * 3600));
            insert('ot_mao_obra', ['ot_id' => $ot, 'tecnico_id' => $p['responsavel_id'], 'data' => substr($ini, 0, 10), 'horas' => round($dur, 1), 'custo_hora' => qv('SELECT custo_hora FROM tecnicos WHERE id=?', [$p['responsavel_id']])]);
            foreach (q('SELECT * FROM ot_materiais WHERE ot_id = ?', [$ot]) as $m) {
                $c = movimentar_estoque((int)$m['artigo_id'], 'saida', (float)$m['quantidade'], null, $ot, null, 'Consumo OT');
                qx('UPDATE ot_materiais SET baixado=1, custo_unitario=? WHERE id=?', [$c, $m['id']]);
            }
            qx('UPDATE ot_tarefas SET concluida = 1 WHERE ot_id = ?', [$ot]);
            qx("UPDATE ordens_trabalho SET status='concluida', data_abertura=?, data_inicio=?, data_fim=?, solucao='Executado conforme checklist.' WHERE id=?",
               [date('Y-m-d 06:00:00', strtotime($p['proxima_data'] . ' -' . $p['antecedencia_dias'] . ' days')), $ini, $fim, $ot]);
            reprogramar_plano_apos_ot($ot);
        }
    }

    // Corretivas históricas
    $falhas = [
        [$cnc, 'Alarme de sobrecarga no eixo Z', 'Sobrecarga', $t2, 3.5, ['CONT-25A' => 1]],
        [$cncF, 'Ruído no fuso principal', 'Desgaste natural', $t1, 9, ['ROL-6308' => 2, 'GRX-EP2' => 0.2]],
        [$comp, 'Compressor desarmando por temperatura', 'Falta de lubrificação', $t1, 5, ['OL-COMP' => 6]],
        [$bomba, 'Vazamento no selo mecânico', 'Desgaste natural', $t1, 4, ['ROL-6205' => 2]],
        [$est, 'Correia rompida', 'Desgaste natural', $t1, 1.5, ['COR-B42' => 2]],
        [$est, 'Motor da esteira não parte', 'Falha elétrica', $t2, 2.5, ['CONT-25A' => 1]],
        [$torno, 'Placa hidráulica sem pressão', 'Falha hidráulica', $t1, 6, ['OL-HID68' => 8]],
        [$emp, 'Empilhadeira com freio fraco', 'Desgaste natural', $t1, 3, []],
        [$ac, 'Split não gela', 'Falta de gás', $t4, 2, []],
        [$cnc, 'Porta da máquina não trava', 'Operação inadequada', $t2, 1, []],
        [$comp, 'Vazamento de ar na linha principal', 'Corrosão', $t1, 2, []],
        [$torno, 'Erro de comunicação no CNC', 'Falha elétrica', $t2, 4, []],
    ];
    foreach ($falhas as $i => [$at, $tit, $causa, $tec, $h, $mats]) {
        $dAb = strtotime('-' . mt_rand(5, 175) . ' days ' . mt_rand(6, 20) . ':' . mt_rand(10, 59));
        $ped = insert('pedidos', ['numero' => proximo_numero('pedidos', 'PT'), 'ativo_id' => $at, 'solicitante_id' => $sol, 'titulo' => $tit, 'prioridade' => 'alta', 'ativo_parado' => 1, 'status' => 'convertido', 'avaliado_por' => $adminId, 'avaliado_em' => date('Y-m-d H:i:s', $dAb + 1800), 'criado_em' => date('Y-m-d H:i:s', $dAb)]);
        $ini = $dAb + mt_rand(1, 4) * 3600; $fim = $ini + (int)($h * 3600);
        $ot = insert('ordens_trabalho', ['numero' => proximo_numero('ordens_trabalho', 'OT'), 'ativo_id' => $at, 'pedido_id' => $ped, 'tipo' => 'corretiva', 'prioridade' => 'alta', 'status' => 'concluida',
            'titulo' => $tit, 'responsavel_id' => $tec, 'data_abertura' => date('Y-m-d H:i:s', $dAb + 1800), 'data_prevista' => date('Y-m-d', $dAb), 'data_inicio' => date('Y-m-d H:i:s', $ini), 'data_fim' => date('Y-m-d H:i:s', $fim),
            'ativo_parado' => 1, 'horas_parada' => round(($fim - $dAb) / 3600, 2), 'causa_falha' => $causa, 'solucao' => 'Diagnosticado e corrigido.', 'custo_externo' => $tec === $t4 ? 450 : 0, 'criado_por' => $adminId]);
        qx('UPDATE pedidos SET ot_id = ? WHERE id = ?', [$ot, $ped]);
        insert('ot_mao_obra', ['ot_id' => $ot, 'tecnico_id' => $tec, 'data' => date('Y-m-d', $ini), 'horas' => $h, 'custo_hora' => qv('SELECT custo_hora FROM tecnicos WHERE id=?', [$tec])]);
        foreach ($mats as $cod => $q) {
            $c = movimentar_estoque($art[$cod], 'saida', $q, null, $ot, null, 'Consumo OT');
            insert('ot_materiais', ['ot_id' => $ot, 'artigo_id' => $art[$cod], 'quantidade' => $q, 'custo_unitario' => $c, 'baixado' => 1]);
        }
    }

    // Situação atual: um pedido aberto, uma OT em execução com ativo parado
    insert('pedidos', ['numero' => proximo_numero('pedidos', 'PT'), 'ativo_id' => $bomba, 'solicitante_id' => $sol, 'titulo' => 'Bomba com vibração excessiva', 'descricao' => 'Desde ontem à tarde, ruído aumentando.', 'prioridade' => 'media']);
    insert('pedidos', ['numero' => proximo_numero('pedidos', 'PT'), 'ativo_id' => $torno, 'solicitante_id' => $sol, 'titulo' => 'Torno parou com alarme de lubrificação', 'prioridade' => 'urgente', 'ativo_parado' => 1]);
    qx("UPDATE ativos SET status='parado' WHERE id=?", [$torno]);
    $otx = insert('ordens_trabalho', ['numero' => proximo_numero('ordens_trabalho', 'OT'), 'ativo_id' => $est, 'tipo' => 'corretiva', 'prioridade' => 'alta', 'status' => 'em_execucao',
        'titulo' => 'Rolamento do tambor motriz travando', 'responsavel_id' => $t1, 'data_prevista' => date('Y-m-d'), 'data_inicio' => date('Y-m-d 07:40:00'), 'ativo_parado' => 1, 'criado_por' => $adminId]);
    insert('ot_tarefas', ['ot_id' => $otx, 'ordem' => 1, 'descricao' => 'Bloquear e etiquetar (LOTO)']);
    insert('ot_tarefas', ['ot_id' => $otx, 'ordem' => 2, 'descricao' => 'Desmontar tambor e substituir rolamentos']);
    qx("UPDATE ativos SET status='em_manutencao' WHERE id=?", [$est]);
    insert('ordens_trabalho', ['numero' => proximo_numero('ordens_trabalho', 'OT'), 'ativo_id' => $cnc, 'tipo' => 'melhoria', 'prioridade' => 'baixa', 'status' => 'aberta',
        'titulo' => 'Instalar iluminação LED interna na CNC', 'data_prevista' => date('Y-m-d', strtotime('+12 days')), 'criado_por' => $adminId]);

    // custos desnormalizados das OTs criadas acima por inserção direta
    qx('UPDATE ordens_trabalho o
          LEFT JOIN (SELECT ot_id, SUM(horas * custo_hora) v FROM ot_mao_obra GROUP BY ot_id) mo ON mo.ot_id = o.id
          LEFT JOIN (SELECT ot_id, SUM(quantidade * custo_unitario) v FROM ot_materiais WHERE baixado = 1 GROUP BY ot_id) mt ON mt.ot_id = o.id
        SET o.custo_mo = COALESCE(mo.v, 0), o.custo_mat = COALESCE(mt.v, 0)');
    // pedidos de demonstração com a localização do ativo
    qx('UPDATE pedidos p JOIN ativos a ON a.id = p.ativo_id SET p.localizacao_id = a.localizacao_id WHERE p.localizacao_id IS NULL');
    // gera as preventivas devidas hoje (inclui as por contador)
    gerar_preventivas($adminId);
}
