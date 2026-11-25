<?php

require_once 'config/auth.php';
exigirLogin();

require_once 'conexao/conexao.php';

header('Content-Type: application/json; charset=utf-8');

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método não permitido.');
    }

    /*
    |--------------------------------------------------------------------------
    | CSRF
    |--------------------------------------------------------------------------
    */

    $csrfToken = $_POST['csrf_token'] ?? '';

    if (
        empty($csrfToken) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $csrfToken)
    ) {
        throw new Exception('Token de segurança inválido.');
    }

    $tipoOperacao = $_POST['tipo_operacao'] ?? 'dente';

    if ($tipoOperacao === 'tratamento_geral') {
        $prontuarioId = (int)($_POST['prontuario_id'] ?? 0);
        $planoTratamentoItemId = (int)($_POST['plano_tratamento_item_id'] ?? 0);
        $observacao = trim($_POST['observacao'] ?? '');

        if ($prontuarioId <= 0) {
            throw new Exception('Prontuário inválido.');
        }

        if ($planoTratamentoItemId <= 0) {
            throw new Exception('Selecione um item do plano de tratamento.');
        }

        $stmtItem = $pdo->prepare("
        SELECT
            pti.id,
            pti.descricao,
            pti.dente_regiao
        FROM planos_tratamento_itens pti
        INNER JOIN planos_tratamento pt
            ON pt.id = pti.plano_id
        WHERE pti.id = ?
          AND pt.paciente_id = ?
          AND pti.status IN ('em_andamento', 'concluido')
        LIMIT 1
    ");

        $stmtItem->execute([
            $planoTratamentoItemId,
            $prontuarioId
        ]);

        $item = $stmtItem->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new Exception(
                'Item concluído não encontrado para este prontuário.'
            );
        }

        $regiao = mb_strtolower(
            trim((string)($item['dente_regiao'] ?? '')),
            'UTF-8'
        );

        $ehArcadaCompleta =
            str_contains($regiao, 'arcada completa') ||
            str_contains($regiao, 'boca toda') ||
            str_contains($regiao, 'todos os dentes') ||
            $regiao === 'geral';

        if (!$ehArcadaCompleta) {
            throw new Exception(
                'O item selecionado não corresponde a um tratamento de arcada completa.'
            );
        }

        $pdo->beginTransaction();

        $stmtDuplicado = $pdo->prepare("
    SELECT id
    FROM odontograma_tratamentos_gerais
    WHERE prontuario_id = ?
      AND plano_tratamento_item_id = ?
      AND arquivado = 0
    LIMIT 1
    FOR UPDATE
");

        $stmtDuplicado->execute([
            $prontuarioId,
            $planoTratamentoItemId
        ]);

        if ($stmtDuplicado->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception(
                'Este tratamento de arcada completa já foi registrado.'
            );
        }

        $stmtSalvar = $pdo->prepare("
    INSERT INTO odontograma_tratamentos_gerais (
        prontuario_id,
        plano_tratamento_item_id,
        descricao,
        regiao,
        observacao
    ) VALUES (?, ?, ?, ?, ?)
");

        $stmtSalvar->execute([
            $prontuarioId,
            $planoTratamentoItemId,
            $item['descricao'],
            'Arcada completa',
            $observacao !== '' ? $observacao : null
        ]);

        $pdo->commit();

        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Tratamento de arcada completa registrado com sucesso.'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | DADOS
    |--------------------------------------------------------------------------
    */

    $prontuarioId = (int)($_POST['prontuario_id'] ?? 0);
    $odontogramaDenteId = (int)($_POST['odontograma_dente_id'] ?? 0);
    $planoTratamentoItemId = (int)($_POST['plano_tratamento_item_id'] ?? 0);
    $modoRegistro = $_POST['modo_registro'] ?? 'estado';
    $estadoGeral = trim($_POST['estado_geral'] ?? '');
    $condicaoSuperficie = trim($_POST['condicao_superficie'] ?? $estadoGeral);
    $observacao = trim($_POST['observacao'] ?? '');
    $implanteStatus = trim($_POST['implante_status'] ?? 'nao_se_aplica');
    $superficies = $_POST['superficies'] ?? [];

    if (!in_array($modoRegistro, ['estado', 'tratamento', 'ambos'], true)) {
        throw new Exception('Modo de registro inválido.');
    }
    if ($modoRegistro === 'estado') {
        $planoTratamentoItemId = 0;
    } elseif ($planoTratamentoItemId <= 0) {
        throw new Exception('Selecione um item concluído do plano de tratamento.');
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDAÇÕES
    |--------------------------------------------------------------------------
    */

    if ($prontuarioId <= 0) {
        throw new Exception('Prontuário inválido.');
    }

    if ($odontogramaDenteId <= 0) {
        throw new Exception('Dente inválido.');
    }

    $estadosPermitidos = [
        'nao_avaliado',
        'higido',
        'carie',
        'restaurado',
        'fraturado',
        'em_tratamento',
        'ausente',
        'extraido',
        'implante'
    ];


    if ($modoRegistro !== 'tratamento' && !in_array($estadoGeral, $estadosPermitidos, true)) {
        throw new Exception('Estado do dente inválido.');
    }

    $implantesPermitidos = [
        'nao_se_aplica',
        'planejado',
        'em_tratamento',
        'realizado'
    ];

    if (!in_array($implanteStatus, $implantesPermitidos, true)) {
        throw new Exception('Status do implante inválido.');
    }

    if (
        $modoRegistro !== 'tratamento' &&
        empty($superficies) &&
        $estadoGeral !== 'ausente' &&
        $estadoGeral !== 'extraido' &&
        $estadoGeral !== 'implante'
    ) {
        $implanteStatus = 'nao_se_aplica';
    }

    /*
    |--------------------------------------------------------------------------
    | SUPERFÍCIES PERMITIDAS
    |--------------------------------------------------------------------------
    */

    $superficiesPermitidas = [
        'vestibular',
        'mesial',
        'oclusal',
        'distal',
        'lingual_palatina'
    ];


    if (!is_array($superficies)) {
        throw new Exception('Formato de superfícies inválido.');
    }

    foreach ($superficies as $superficie) {
        if (
            !is_string($superficie) ||
            !in_array($superficie, $superficiesPermitidas, true)
        ) {
            throw new Exception('Uma ou mais superfícies são inválidas.');
        }
    }

    $superficies = array_values(array_unique($superficies));

    $condicoesSuperficiePermitidas = [
        'nao_avaliado',
        'higido',
        'carie',
        'restaurado',
        'fraturado',
        'em_tratamento'
    ];

    if (!empty($superficies) && !in_array($condicaoSuperficie, $condicoesSuperficiePermitidas, true)) {
        throw new Exception('Condição clínica da região inválida.');
    }

    /*
    |--------------------------------------------------------------------------
    | LOCALIZAR DENTE
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            prontuario_id,
            numero_dente,
            estado_geral,
            implante_status,
            observacoes
        FROM odontograma_dentes
        WHERE id = ?
          AND prontuario_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $pdo->beginTransaction();

    $stmt->execute([
        $odontogramaDenteId,
        $prontuarioId
    ]);

    $dente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dente) {
        throw new Exception(
            'Dente não encontrado para este prontuário.'
        );
    }

    /*
     * O estado persistido no banco é a fonte de verdade.
     * Não usamos apenas o valor enviado pelo navegador para decidir
     * se superfícies ou tratamentos podem ser alterados.
     */
    $estadoAtualBanco = (string)$dente['estado_geral'];
    $denteBloqueadoParaRegistros = in_array(
        $estadoAtualBanco,
        ['ausente', 'extraido'],
        true
    );

    if (
        $modoRegistro === 'tratamento' &&
        (
            $denteBloqueadoParaRegistros ||
            in_array($estadoGeral, ['ausente', 'extraido'], true)
        )
    ) {
        throw new Exception(
            'Não é permitido registrar tratamento em um dente ausente ou extraído.'
        );
    }

    if (
        $modoRegistro === 'ambos' &&
        (
            $denteBloqueadoParaRegistros ||
            in_array($estadoGeral, ['ausente', 'extraido'], true)
        )
    ) {
        throw new Exception(
            'Não é permitido associar tratamento a um dente ausente ou extraído.'
        );
    }

    if ($modoRegistro !== 'tratamento' && !empty($superficies)) {
        if ($denteBloqueadoParaRegistros) {
            throw new Exception(
                'Não é permitido alterar superfícies de um dente ausente ou extraído. Altere somente o status do implante.'
            );
        }

        if ($estadoGeral !== $estadoAtualBanco) {
            throw new Exception(
                'O estado geral do dente mudou. Atualize o prontuário antes de registrar as superfícies.'
            );
        }
    }

    /*
     * Um dente cujo implante já foi realizado permanece identificado
     * como implante. O status não pode ser desfeito por uma requisição
     * de superfície ou por um estado geral enviado pelo navegador.
     */
    if (
        $estadoAtualBanco === 'implante' &&
        $modoRegistro !== 'tratamento' &&
        empty($superficies)
    ) {
        if ($estadoGeral !== 'implante') {
            throw new Exception(
                'Um dente com implante realizado deve permanecer identificado como implante.'
            );
        }

        if ($implanteStatus !== 'realizado') {
            throw new Exception(
                'O status de um implante realizado não pode ser alterado nesta operação.'
            );
        }
    }

    if ($planoTratamentoItemId > 0) {
        $stmtItemPlano = $pdo->prepare("
        SELECT pti.id,
               pti.descricao,
               pti.dente_regiao
        FROM planos_tratamento_itens pti
        INNER JOIN planos_tratamento pt
            ON pt.id = pti.plano_id
        WHERE pti.id = ?
          AND pt.paciente_id = ?
          AND pti.status IN ('em_andamento', 'concluido')
        LIMIT 1
        FOR UPDATE
    ");

        $stmtItemPlano->execute([
            $planoTratamentoItemId,
            $prontuarioId
        ]);


        $itemPlano = $stmtItemPlano->fetch(PDO::FETCH_ASSOC);

        if (!$itemPlano) {
            throw new Exception(
                'Item do plano de tratamento inválido ou não concluído para este prontuário.'
            );
        }

        $regiaoItem = trim((string)($itemPlano['dente_regiao'] ?? ''));
        $numeroDente = trim((string)($dente['numero_dente'] ?? ''));

        // Normaliza a região cadastrada no plano.
        $regiaoNormalizada = mb_strtolower($regiaoItem, 'UTF-8');

        $regiaoNormalizada = iconv(
            'UTF-8',
            'ASCII//TRANSLIT//IGNORE',
            $regiaoNormalizada
        );

        $regiaoNormalizada = trim($regiaoNormalizada);

        // Arcada completa não pode ser vinculada a um único dente.
        if (
            str_contains($regiaoNormalizada, 'arcada completa') ||
            str_contains($regiaoNormalizada, 'boca toda') ||
            str_contains($regiaoNormalizada, 'todos os dentes') ||
            $regiaoNormalizada === 'geral'
        ) {
            throw new Exception(
                'Itens de arcada completa não podem ser vinculados a um único dente.'
            );
        }

        // Identifica a arcada do dente pelo primeiro dígito.
        $primeiroDigito = substr($numeroDente, 0, 1);

        $ehArcadaSuperior = in_array(
            $primeiroDigito,
            ['1', '2', '5', '6'],
            true
        );

        $ehArcadaInferior = in_array(
            $primeiroDigito,
            ['3', '4', '7', '8'],
            true
        );

        $compativelComRegiao = false;

        // Verifica se o item é destinado à arcada superior.
        if (
            str_contains($regiaoNormalizada, 'arcada superior') ||
            $regiaoNormalizada === 'superior'
        ) {
            $compativelComRegiao = $ehArcadaSuperior;

            // Verifica se o item é destinado à arcada inferior.
        } elseif (
            str_contains($regiaoNormalizada, 'arcada inferior') ||
            $regiaoNormalizada === 'inferior'
        ) {
            $compativelComRegiao = $ehArcadaInferior;

            // Verifica se o item contém o número específico do dente.
        } else {
            $numeroRegex = '/(^|[^0-9])' .
                preg_quote($numeroDente, '/') .
                '([^0-9]|$)/';

            $compativelComRegiao = preg_match(
                $numeroRegex,
                $regiaoNormalizada
            ) === 1;
        }

        if (!$compativelComRegiao) {
            throw new Exception(
                'Este item do plano de tratamento não corresponde ao dente selecionado.'
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | REGRAS DE TRANSIÇÃO DO IMPLANTE
    |--------------------------------------------------------------------------
    |
    | O implante só pode ser planejado/iniciado/concluído quando o dente
    | estiver ausente ou extraído. Ao concluir, o estado geral passa a
    | ser "implante", liberando os registros de superfície novamente.
    |
    */
    if ($modoRegistro !== 'tratamento' && empty($superficies)) {
        if (
            $estadoAtualBanco !== 'implante' &&
            $implanteStatus !== 'nao_se_aplica' &&
            !in_array($estadoAtualBanco, ['ausente', 'extraido'], true) &&
            !in_array($estadoGeral, ['ausente', 'extraido'], true)
        ) {
            throw new Exception(
                'O implante só pode ser selecionado quando o dente estiver ausente ou extraído.'
            );
        }

        if (
            in_array($estadoAtualBanco, ['ausente', 'extraido'], true) &&
            $estadoGeral !== $estadoAtualBanco &&
            $implanteStatus !== 'realizado'
        ) {
            throw new Exception(
                'Em dentes ausentes ou extraídos, somente o status do implante pode ser alterado.'
            );
        }

        if (
            $implanteStatus === 'realizado' &&
            !in_array($estadoAtualBanco, ['ausente', 'extraido'], true) &&
            $estadoAtualBanco !== 'implante'
        ) {
            throw new Exception(
                'Para concluir o implante, o dente precisa estar ausente ou extraído.'
            );
        }

        if (
            $estadoGeral === 'implante' &&
            $implanteStatus !== 'realizado' &&
            $estadoAtualBanco !== 'implante'
        ) {
            throw new Exception(
                'O estado Implante só pode ser definido ao concluir um implante.'
            );
        }

        if (
            $estadoGeral === 'implante' &&
            !in_array($estadoAtualBanco, ['ausente', 'extraido', 'implante'], true)
        ) {
            throw new Exception(
                'O dente precisa estar ausente ou extraído antes de se tornar um implante.'
            );
        }

        if ($implanteStatus === 'realizado') {
            $estadoGeral = 'implante';
        } elseif ($estadoAtualBanco === 'implante') {
            $estadoGeral = 'implante';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | TRATAMENTO INDEPENDENTE
    |--------------------------------------------------------------------------
    */
    if ($modoRegistro === 'tratamento') {
        $superficiesTratamento = empty($superficies) ? ['geral'] : $superficies;
        $stmtDuplicadoTratamento = $pdo->prepare("SELECT id FROM odontograma_tratamentos_dentes WHERE odontograma_dente_id = ? AND plano_tratamento_item_id = ? AND superficie = ? AND arquivado = 0 LIMIT 1 FOR UPDATE");
        $stmtInserirTratamento = $pdo->prepare("INSERT INTO odontograma_tratamentos_dentes (odontograma_dente_id, plano_tratamento_item_id, descricao, superficie, observacao) VALUES (?, ?, ?, ?, ?)");
        foreach ($superficiesTratamento as $superficieTratamento) {
            $stmtDuplicadoTratamento->execute([$odontogramaDenteId, $planoTratamentoItemId, $superficieTratamento]);
            if ($stmtDuplicadoTratamento->fetchColumn()) {
                throw new Exception('Este tratamento já está registrado nesta região do dente.');
            }
            $stmtInserirTratamento->execute([
                $odontogramaDenteId,
                $planoTratamentoItemId,
                $itemPlano['descricao'] ?? 'Tratamento concluído',
                $superficieTratamento,
                $observacao !== '' ? $observacao : null
            ]);
        }
        $pdo->commit();
        echo json_encode(['sucesso' => true, 'mensagem' => 'Tratamento registrado sem alterar o estado clínico do dente.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /*
|--------------------------------------------------------------------------
| DEFINIR TIPO DE ALTERAÇÃO
|--------------------------------------------------------------------------
|
| Sem superfícies selecionadas:
|   → altera o estado geral do dente.
|
| Com superfícies selecionadas:
|   → mantém o estado geral atual.
|   → altera somente as superfícies selecionadas.
|
*/

    /*
|--------------------------------------------------------------------------
| ESTADO GERAL
|--------------------------------------------------------------------------
*/

    /*
|--------------------------------------------------------------------------
| REGISTRAR ALTERAÇÃO DO IMPLANTE
|--------------------------------------------------------------------------
|
| O histórico registra somente quando o status do implante realmente mudou.
|
*/

    $implanteAnterior = $dente['implante_status'];
    $implanteHistoricoId = null;

    if (
        empty($superficies) &&
        $implanteAnterior !== $implanteStatus
    ) {

        // O vínculo com a operação clínica será definido após identificar
        // o estado histórico que originou ou mantém o ciclo do implante.
        $stmtHistoricoImplante = $pdo->prepare("
        INSERT INTO odontograma_implantes_historico (
            odontograma_dente_id,
            status,
            observacao,
            data_registro,
            criado_em
        )
        VALUES (
            ?,
            ?,
            ?,
            NOW(),
            NOW()
        )
    ");

        $stmtHistoricoImplante->execute([
            $odontogramaDenteId,
            $implanteStatus,
            $observacao !== '' ? $observacao : null
        ]);
        $implanteHistoricoId = (int) $pdo->lastInsertId();
    }

    if (empty($superficies)) {

        /*
    |--------------------------------------------------------------------------
    | REGISTRAR HISTÓRICO DO ESTADO GERAL
    |--------------------------------------------------------------------------
    */

        
$estadoAnterior = $dente['estado_geral'];

$mudouEstadoGeral = ($estadoAnterior !== $estadoGeral);
$estadoHistoricoId = null;
$estadoHistoricoVinculadoId = null;

/*
|--------------------------------------------------------------------------
| VERIFICAR DUPLICIDADE DA AVALIAÇÃO
|--------------------------------------------------------------------------
|
| Considera o evento geral imediatamente anterior do mesmo dia,
| inclusive se ele estiver arquivado.
|
*/

$stmtUltimaAvaliacao = $pdo->prepare("
    SELECT
        id,
        estado,
        observacao
    FROM odontograma_estados_historico
    WHERE odontograma_dente_id = ?
      AND DATE(data_registro) = CURRENT_DATE()
    ORDER BY data_registro DESC, id DESC
    LIMIT 1
    FOR UPDATE
");

$stmtUltimaAvaliacao->execute([
    $odontogramaDenteId
]);

$ultimaAvaliacao = $stmtUltimaAvaliacao->fetch(PDO::FETCH_ASSOC);

$observacaoAtual = $observacao !== '' ? $observacao : null;

if (
    $ultimaAvaliacao &&
    $ultimaAvaliacao['estado'] === $estadoGeral &&
    (string)($ultimaAvaliacao['observacao'] ?? '') ===
    (string)($observacaoAtual ?? '')
) {
    throw new Exception(
        'Esta avaliação já foi registrada hoje com o mesmo estado e observação.'
    );
}

/*
|--------------------------------------------------------------------------
| LOCALIZAR REGISTRO GERAL ATUAL
|--------------------------------------------------------------------------
|
| O registro anterior será associado à nova operação.
|
*/

$stmtGeralAnterior = $pdo->prepare("
    SELECT id
    FROM odontograma_registros
    WHERE odontograma_dente_id = ?
      AND superficie = 'geral'
      AND arquivado = 0
    ORDER BY data_registro DESC, id DESC
    LIMIT 1
    FOR UPDATE
");

$stmtGeralAnterior->execute([
    $odontogramaDenteId
]);

$registroGeralAnteriorId = $stmtGeralAnterior->fetchColumn();

$registroGeralAnteriorId = $registroGeralAnteriorId !== false
    ? (int)$registroGeralAnteriorId
    : null;

/*
|--------------------------------------------------------------------------
| CRIAR NOVO EVENTO DE ESTADO GERAL
|--------------------------------------------------------------------------
|
| Toda avaliação válida recebe um ID próprio.
| Não reutilizamos eventos anteriores, mesmo quando o estado permanece igual.
|
*/

$stmtHistoricoEstado = $pdo->prepare("
    INSERT INTO odontograma_estados_historico (
        odontograma_dente_id,
        estado,
        observacao,
        data_registro,
        criado_em
    )
    VALUES (
        ?,
        ?,
        ?,
        NOW(),
        NOW()
    )
");

$stmtHistoricoEstado->execute([
    $odontogramaDenteId,
    $estadoGeral,
    $observacaoAtual
]);

$estadoHistoricoId = (int)$pdo->lastInsertId();
$estadoHistoricoVinculadoId = $estadoHistoricoId;

/*
|--------------------------------------------------------------------------
| CRIAR OPERAÇÃO PRÓPRIA PARA ESTA AVALIAÇÃO
|--------------------------------------------------------------------------
*/

$stmtSalvarOperacao = $pdo->prepare("
    INSERT INTO odontograma_estados_operacoes (
        estado_historico_id,
        estado_geral_anterior,
        registro_geral_anterior_id
    )
    VALUES (?, ?, ?)
");

$stmtSalvarOperacao->execute([
    $estadoHistoricoId,
    $estadoAnterior,
    $registroGeralAnteriorId
]);

/*
|--------------------------------------------------------------------------
| SNAPSHOT DAS SUPERFÍCIES
|--------------------------------------------------------------------------
|
| Somente uma mudança real do estado geral encerra as superfícies.
| Uma nova avaliação com o mesmo estado não interfere nelas.
|
*/

if ($mudouEstadoGeral) {

    $stmtSuperficiesAtivas = $pdo->prepare("
        SELECT id
        FROM odontograma_registros
        WHERE odontograma_dente_id = ?
          AND superficie <> 'geral'
          AND arquivado = 0
        FOR UPDATE
    ");

    $stmtSuperficiesAtivas->execute([
        $odontogramaDenteId
    ]);

    $superficiesAtivasAntes = $stmtSuperficiesAtivas->fetchAll(
        PDO::FETCH_COLUMN
    );

    $stmtVincularSuperficie = $pdo->prepare("
        INSERT INTO odontograma_estados_superficies (
            estado_historico_id,
            registro_superficie_id,
            registro_geral_anterior_id,
            estado_geral_anterior
        )
        VALUES (?, ?, ?, ?)
    ");

    foreach ($superficiesAtivasAntes as $registroSuperficieId) {

        $stmtVincularSuperficie->execute([
            $estadoHistoricoId,
            (int)$registroSuperficieId,
            $registroGeralAnteriorId,
            $estadoAnterior
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ARQUIVAR SUPERFÍCIES VINCULADAS AO SNAPSHOT
    |--------------------------------------------------------------------------
    */

    $stmtArquivarSuperficies = $pdo->prepare("
        UPDATE odontograma_registros r
        INNER JOIN odontograma_estados_superficies es
            ON es.registro_superficie_id = r.id
        SET
            r.arquivado = 1,
            r.motivo_arquivamento = 'encerrado_por_estado'
        WHERE es.estado_historico_id = ?
          AND r.odontograma_dente_id = ?
          AND r.superficie <> 'geral'
          AND r.arquivado = 0
    ");

    $stmtArquivarSuperficies->execute([
        $estadoHistoricoId,
        $odontogramaDenteId
    ]);
}

/*
|--------------------------------------------------------------------------
| ARQUIVAR REGISTRO GERAL ANTERIOR
|--------------------------------------------------------------------------
|
| Estado diferente: encerrado_por_estado.
| Mesmo estado: substituido.
|
*/

if ($registroGeralAnteriorId !== null) {

    $motivoArquivamento = $mudouEstadoGeral
        ? 'encerrado_por_estado'
        : 'substituido';

    $stmtArquivarGeralAnterior = $pdo->prepare("
        UPDATE odontograma_registros
        SET
            arquivado = 1,
            motivo_arquivamento = ?
        WHERE id = ?
          AND odontograma_dente_id = ?
          AND superficie = 'geral'
          AND arquivado = 0
    ");

    $stmtArquivarGeralAnterior->execute([
        $motivoArquivamento,
        $registroGeralAnteriorId,
        $odontogramaDenteId
    ]);
}

/*
|--------------------------------------------------------------------------
| ATUALIZAR ESTADO ATUAL DO DENTE
|--------------------------------------------------------------------------
*/

$stmtAtualizarDente = $pdo->prepare("
    UPDATE odontograma_dentes
    SET
        estado_geral = ?,
        implante_status = ?,
        observacoes = ?
    WHERE id = ?
      AND prontuario_id = ?
");

$stmtAtualizarDente->execute([
    $estadoGeral,
    $implanteStatus,
    $observacaoAtual,
    $odontogramaDenteId,
    $prontuarioId
]);

/*
|--------------------------------------------------------------------------
| CRIAR NOVO REGISTRO GERAL
|--------------------------------------------------------------------------
|
| O registro fica vinculado exclusivamente ao novo evento.
|
*/

$stmtRegistroGeral = $pdo->prepare("
    INSERT INTO odontograma_registros (
        odontograma_dente_id,
        superficie,
        condicao,
        observacao,
        plano_tratamento_item_id,
        estado_historico_id,
        data_registro,
        criado_em,
        arquivado
    )
    VALUES (
        ?,
        'geral',
        ?,
        ?,
        ?,
        ?,
        NOW(),
        NOW(),
        0
    )
");

$stmtRegistroGeral->execute([
    $odontogramaDenteId,
    $estadoGeral,
    $observacaoAtual,
    $modoRegistro === 'ambos' ? $planoTratamentoItemId : null,
    $estadoHistoricoId
]);

/*
|--------------------------------------------------------------------------
| VINCULAR REGISTRO ATUAL À OPERAÇÃO
|--------------------------------------------------------------------------
*/

$registroGeralAtualId = (int)$pdo->lastInsertId();

$stmtAtualizarOperacao = $pdo->prepare("
    UPDATE odontograma_estados_operacoes
    SET registro_geral_atual_id = ?
    WHERE estado_historico_id = ?
");

$stmtAtualizarOperacao->execute([
    $registroGeralAtualId,
    $estadoHistoricoId
]);
    } else {

        /*
    |--------------------------------------------------------------------------
    | ALTERAÇÃO DE SUPERFÍCIES
    |--------------------------------------------------------------------------
    |
    | IMPORTANTE:
    |
    | O estado geral do dente NÃO é alterado.
    |
    | O estado selecionado é aplicado somente às superfícies
    | escolhidas pelo usuário.
    |
    |--------------------------------------------------------------------------
    */

        if (
            in_array($estadoAtualBanco, ['ausente', 'extraido'], true)
        ) {
            throw new Exception(
                'Não é permitido alterar superfícies de um dente ausente ou extraído.'
            );
        }

        if ($estadoGeral !== $estadoAtualBanco) {
            throw new Exception(
                'A alteração de superfícies não pode modificar o estado geral do dente.'
            );
        }


        /*
    |--------------------------------------------------------------------------
    | ARQUIVAR ESTADO ANTERIOR DE CADA SUPERFÍCIE
    |--------------------------------------------------------------------------
    */

        $stmtArquivarSuperficie = $pdo->prepare("
        UPDATE odontograma_registros
        SET
            arquivado = 1,
            motivo_arquivamento = 'substituido'
        WHERE odontograma_dente_id = ?
        AND superficie = ?
        AND arquivado = 0
    ");


        /*
    |--------------------------------------------------------------------------
    | INSERIR NOVO ESTADO DA SUPERFÍCIE
    |--------------------------------------------------------------------------
    */

        $stmtRegistroSuperficie = $pdo->prepare("
        INSERT INTO odontograma_registros (
            odontograma_dente_id,
            superficie,
            condicao,
            observacao,
            plano_tratamento_item_id,
            data_registro,
            criado_em,
            arquivado
        )
        
VALUES (
    ?, ?, ?, ?, ?,
    NOW(),
    NOW(),
    0
)

    ");


        foreach ($superficies as $superficie) {

            /*
        |--------------------------------------------------------------------------
        | Arquiva somente o estado atual daquela superfície
        |--------------------------------------------------------------------------
        */

            $stmtArquivarSuperficie->execute([
                $odontogramaDenteId,
                $superficie
            ]);


            /*
        |--------------------------------------------------------------------------
        | Cria o novo estado somente naquela superfície
        |--------------------------------------------------------------------------
        */

            $stmtRegistroSuperficie->execute([
                $odontogramaDenteId,
                $superficie,
                $condicaoSuperficie,
                $observacao !== '' ? $observacao : null,
                $modoRegistro === 'ambos' ? $planoTratamentoItemId : null
            ]);
        }
    }

    // Vincula cada mudança de status do implante à operação de Extraído/Ausente
    // que iniciou o ciclo. Para a primeira mudança, usa o estado criado nesta
    // mesma gravação; nas etapas seguintes, recupera a operação do dente.
    if ($implanteHistoricoId !== null) {
        $estadoOperacaoImplanteId = null;

        if (
            $estadoHistoricoId !== null &&
            in_array($estadoGeral, ['extraido', 'ausente'], true)
        ) {
            $estadoOperacaoImplanteId = $estadoHistoricoId;
        } elseif (
            in_array($estadoAtualBanco, ['extraido', 'ausente', 'implante'], true) ||
            in_array($estadoGeral, ['extraido', 'ausente', 'implante'], true)
        ) {
            $stmtOperacaoImplante = $pdo->prepare("
                SELECT h.id
                FROM odontograma_estados_historico h
                INNER JOIN odontograma_estados_operacoes op
                    ON op.estado_historico_id = h.id
                WHERE h.odontograma_dente_id = ?
                  AND h.estado IN ('extraido', 'ausente')
                ORDER BY h.data_registro DESC, h.id DESC
                LIMIT 1
                FOR UPDATE
            ");
            $stmtOperacaoImplante->execute([$odontogramaDenteId]);
            $idEncontrado = $stmtOperacaoImplante->fetchColumn();
            if ($idEncontrado !== false) {
                $estadoOperacaoImplanteId = (int) $idEncontrado;
            }
        }

        if ($estadoOperacaoImplanteId !== null) {
            $stmtVincularHistoricoImplante = $pdo->prepare("
                UPDATE odontograma_implantes_historico
                SET estado_historico_id = ?
                WHERE id = ?
                  AND odontograma_dente_id = ?
            ");
            $stmtVincularHistoricoImplante->execute([
                $estadoOperacaoImplanteId,
                $implanteHistoricoId,
                $odontogramaDenteId
            ]);
        }
    }

    $pdo->commit();

    echo json_encode([
        'sucesso' => true,
        'mensagem' => $modoRegistro === 'ambos'
            ? 'Estado clínico e tratamento salvos em um único registro de histórico.'
            : 'Estado clínico salvo com sucesso.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);

    $mensagem = 'Não foi possível registrar o tratamento. Tente novamente.';

    if ($e instanceof PDOException) {
        $codigoErro = $e->errorInfo[1] ?? null;

        if (
            $codigoErro === 1062 &&
            str_contains(
                $e->getMessage(),
                'uk_tratamento_geral_ativo'
            )
        ) {
            $mensagem = 'Este tratamento de arcada completa já foi registrado.';
        } else {
            error_log('Erro ao salvar odontograma: ' . $e->getMessage());
        }
    } else {
        error_log('Erro inesperado ao salvar odontograma: ' . $e->getMessage());

        $mensagem = 'Não foi possível salvar o odontograma. Tente novamente.';
    }

    echo json_encode([
        'sucesso' => false,
        'mensagem' => $mensagem
    ], JSON_UNESCAPED_UNICODE);

    exit;
}
