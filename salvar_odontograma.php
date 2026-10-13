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

    /*
    |--------------------------------------------------------------------------
    | DADOS
    |--------------------------------------------------------------------------
    */

    $prontuarioId = (int)($_POST['prontuario_id'] ?? 0);
    $odontogramaDenteId = (int)($_POST['odontograma_dente_id'] ?? 0);

    $estadoGeral = trim($_POST['estado_geral'] ?? '');
    $observacao = trim($_POST['observacao'] ?? '');
    $implanteStatus = trim($_POST['implante_status'] ?? 'nao_se_aplica');

    $superficies = $_POST['superficies'] ?? [];

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
        'extraido'
    ];


    if (!in_array($estadoGeral, $estadosPermitidos, true)) {
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
        $estadoGeral !== 'ausente' &&
        $estadoGeral !== 'extraido'
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
        $superficies = [];
    }

    $superficies = array_values(
        array_intersect(
            $superficies,
            $superficiesPermitidas
        )
    );

    /*
    |--------------------------------------------------------------------------
    | LOCALIZAR DENTE
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            prontuario_id,
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

    if ($implanteAnterior !== $implanteStatus) {

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
    }

    if (empty($superficies)) {

        /*
    |--------------------------------------------------------------------------
    | REGISTRAR HISTÓRICO DO ESTADO GERAL
    |--------------------------------------------------------------------------
    */

        $estadoAnterior = $dente['estado_geral'];

        if ($estadoAnterior !== $estadoGeral) {

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
                $observacao !== '' ? $observacao : null
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
            $observacao !== '' ? $observacao : null,
            $odontogramaDenteId,
            $prontuarioId
        ]);


        /*
    |--------------------------------------------------------------------------
    | ESTADO GERAL PASSA A SER O PADRÃO DO DENTE
    |--------------------------------------------------------------------------
    |
    | Ao alterar o estado geral, qualquer estado específico de superfície
    | deixa de ser o estado atual.
    |
    | Os registros antigos NÃO são apagados.
    | Apenas são arquivados para preservar o histórico.
    |--------------------------------------------------------------------------
    */

        $stmtArquivarTodos = $pdo->prepare("
        UPDATE odontograma_registros
        SET arquivado = 1
        WHERE odontograma_dente_id = ?
          AND arquivado = 0
    ");

        $stmtArquivarTodos->execute([
            $odontogramaDenteId
        ]);


        /*
    |--------------------------------------------------------------------------
    | REGISTRAR O ESTADO GERAL ATUAL
    |--------------------------------------------------------------------------
    |
    | Mantemos um registro "geral" ativo para que o frontend possa
    | utilizá-lo como estado padrão das superfícies.
    |
    */

        $stmtRegistroGeral = $pdo->prepare("
        INSERT INTO odontograma_registros (
            odontograma_dente_id,
            superficie,
            condicao,
            observacao,
            data_registro,
            criado_em,
            arquivado
        )
        VALUES (
            ?,
            'geral',
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
            $observacao !== '' ? $observacao : null
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
            $estadoGeral === 'ausente' ||
            $estadoGeral === 'extraido'
        ) {
            throw new Exception(
                'Ausente e Extraído devem ser definidos como estado geral do dente.'
            );
        }


        /*
    |--------------------------------------------------------------------------
    | ARQUIVAR ESTADO ANTERIOR DE CADA SUPERFÍCIE
    |--------------------------------------------------------------------------
    */

        $stmtArquivarSuperficie = $pdo->prepare("
        UPDATE odontograma_registros
        SET arquivado = 1
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
            data_registro,
            criado_em,
            arquivado
        )
        VALUES (
            ?,
            ?,
            ?,
            ?,
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
                $estadoGeral,
                $observacao !== '' ? $observacao : null
            ]);
        }
    }
    $pdo->commit();

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Odontograma salvo com sucesso.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);

    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);

    exit;
}
