<?php

require_once 'config/auth.php';
require_once 'config/csrf.php';

exigirLogin();

require_once 'conexao/conexao.php';

header('Content-Type: application/json; charset=utf-8');

/*
|--------------------------------------------------------------------------
| Somente POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'error' => 'Método não permitido.'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

try {

    validar_csrf();
} catch (Throwable $e) {

    http_response_code(403);

    echo json_encode([
        'success' => false,
        'error' => 'Token de segurança inválido.'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Receber dados
|--------------------------------------------------------------------------
*/

try {

    $dadosJson = $_POST['dados'] ?? '';

    if ($dadosJson === '') {
        throw new Exception(
            'Dados do procedimento não foram enviados.'
        );
    }

    $dados = json_decode(
        $dadosJson,
        true
    );

    if (!is_array($dados)) {
        throw new Exception(
            'Dados do procedimento inválidos.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Dados principais
    |--------------------------------------------------------------------------
    */

    $prontuario_id = (int)(
        $dados['prontuario_id'] ?? 0
    );

    /*
    |--------------------------------------------------------------------------
    | ID do procedimento
    |--------------------------------------------------------------------------
    |
    | Aceitamos:
    | - dentro do JSON
    | - ou como POST separado
    |
    | Isso mantém compatibilidade com versões anteriores do frontend.
    |--------------------------------------------------------------------------
    */

    $procedimento_id = null;

    $procedimento_id_recebido =
        $dados['procedimento_id']
        ?? ($_POST['procedimento_id'] ?? null);

    if (
        $procedimento_id_recebido !== null &&
        $procedimento_id_recebido !== ''
    ) {

        $procedimento_id =
            (int)$procedimento_id_recebido;
    }

    /*
    |--------------------------------------------------------------------------
    | Agendamento
    |--------------------------------------------------------------------------
    */

    $agendamento_id = null;

    if (
        isset($dados['agendamento_id']) &&
        $dados['agendamento_id'] !== null &&
        $dados['agendamento_id'] !== ''
    ) {

        $agendamento_id =
            (int)$dados['agendamento_id'];
    }

    /*
    |--------------------------------------------------------------------------
    | Item do plano de tratamento
    |--------------------------------------------------------------------------
    |
    | Pode vir diretamente do frontend.
    |
    | Caso não venha, tentaremos descobrir através do agendamento.
    |--------------------------------------------------------------------------
    */

    $plano_item_id = null;

    if (
        isset($dados['plano_item_id']) &&
        $dados['plano_item_id'] !== null &&
        $dados['plano_item_id'] !== ''
    ) {

        $plano_item_id =
            (int)$dados['plano_item_id'];

        if ($plano_item_id <= 0) {
            $plano_item_id = null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Dados do procedimento
    |--------------------------------------------------------------------------
    */

    $titulo = trim(
        $dados['titulo'] ?? ''
    );

    $data_procedimento = trim(
        $dados['data_procedimento'] ?? ''
    );

    $descricao = trim(
        $dados['descricao'] ?? ''
    );

    $medicamentos = trim(
        $dados['medicamentos'] ?? ''
    );

    $valor_mao_obra = (float)(
        $dados['valor_mao_obra'] ?? 0
    );

    $valor_final_informado = (float)(
        $dados['valor_final'] ?? 0
    );

    $materiaisRecebidos =
        $dados['materiais'] ?? [];

    /*
    |--------------------------------------------------------------------------
    | Validações básicas
    |--------------------------------------------------------------------------
    */

    if ($prontuario_id <= 0) {

        throw new Exception(
            'Prontuário inválido.'
        );
    }

    if ($titulo === '') {

        throw new Exception(
            'Informe o nome do procedimento.'
        );
    }

    if ($data_procedimento === '') {

        throw new Exception(
            'Informe a data do procedimento.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Validar data
    |--------------------------------------------------------------------------
    */

    $dataValida =
        DateTime::createFromFormat(
            'Y-m-d',
            $data_procedimento
        );

    if (
        !$dataValida ||
        $dataValida->format('Y-m-d')
        !== $data_procedimento
    ) {

        throw new Exception(
            'Data do procedimento inválida.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Validar valores
    |--------------------------------------------------------------------------
    */

    if ($valor_mao_obra < 0) {

        throw new Exception(
            'O valor da mão de obra não pode ser negativo.'
        );
    }

    if ($valor_final_informado < 0) {

        throw new Exception(
            'O valor final do procedimento não pode ser negativo.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Validar materiais
    |--------------------------------------------------------------------------
    */

    if (!is_array($materiaisRecebidos)) {

        throw new Exception(
            'Lista de materiais inválida.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Normalizar materiais
    |--------------------------------------------------------------------------
    |
    | Se o mesmo material vier duas vezes,
    | somamos as quantidades no backend.
    |--------------------------------------------------------------------------
    */

    $materiais = [];

    foreach ($materiaisRecebidos as $material) {

        if (!is_array($material)) {
            continue;
        }

        $estoque_id = (int)(
            $material['estoque_id'] ?? 0
        );

        $quantidade = (float)(
            $material['quantidade'] ?? 0
        );

        $subtotal_informado =
            array_key_exists(
                'subtotal',
                $material
            )
            ? (float)$material['subtotal']
            : null;

        if ($estoque_id <= 0) {

            throw new Exception(
                'Material inválido.'
            );
        }

        if ($quantidade <= 0) {

            throw new Exception(
                'A quantidade utilizada deve ser maior que zero.'
            );
        }

        if (
            $subtotal_informado !== null &&
            $subtotal_informado < 0
        ) {

            throw new Exception(
                'O subtotal do material não pode ser negativo.'
            );
        }

        if (isset($materiais[$estoque_id])) {

            $materiais[$estoque_id]['quantidade']
                += $quantidade;

            if ($subtotal_informado !== null) {

                $materiais[$estoque_id]['subtotal_informado']
                    = (
                        $materiais[$estoque_id]['subtotal_informado']
                        ?? 0
                    )
                    + $subtotal_informado;
            }
        } else {

            $materiais[$estoque_id] = [

                'estoque_id' =>
                $estoque_id,

                'quantidade' =>
                $quantidade,

                'subtotal_informado' =>
                $subtotal_informado

            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Iniciar transação
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | 1. Verificar prontuário
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            paciente
        FROM prontuarios
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $prontuario_id
    ]);

    $prontuario =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$prontuario) {

        throw new Exception(
            'Prontuário não encontrado.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Determinar modo
    |--------------------------------------------------------------------------
    */

    $modo =
        $procedimento_id === null
        ? 'criacao'
        : 'edicao';

    $procedimento_antigo = null;

    /*
    |--------------------------------------------------------------------------
    | 3. Buscar procedimento existente
    |--------------------------------------------------------------------------
    |
    | Na edição, preservamos os vínculos existentes:
    | - orçamento
    | - plano
    | - agendamento
    |--------------------------------------------------------------------------
    */

    $orcamento_id = null;

    if ($procedimento_id !== null) {

        if ($procedimento_id <= 0) {

            throw new Exception(
                'Procedimento inválido.'
            );
        }

        $stmt = $pdo->prepare("
    SELECT
        id,
        paciente_id,
        orcamento_id,
        plano_item_id,
        agendamento_id,
        titulo,
        descricao,
        medicamentos,
        valor_mao_obra,
        valor_final,
        data_procedimento,
        arquivado
    FROM procedimentos
    WHERE id = ?
      AND paciente_id = ?
    LIMIT 1
    FOR UPDATE
");

        $stmt->execute([
            $procedimento_id,
            $prontuario_id
        ]);

        $procedimento_antigo =
            $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$procedimento_antigo) {

            throw new Exception(
                'Procedimento não encontrado.'
            );
        }
        if ((int)$procedimento_antigo['arquivado'] === 1) {

            throw new Exception(
                'Este procedimento está arquivado e não pode ser editado.'
            );
        }

        /*
        | Preservar orçamento antigo
        */

        if (
            !empty($procedimento_antigo['orcamento_id'])
        ) {

            $orcamento_id =
                (int)$procedimento_antigo['orcamento_id'];
        }

        /*
        | Se não veio plano_item_id no frontend,
        | preservar o vínculo antigo.
        */

        if (
            $plano_item_id === null &&
            !empty($procedimento_antigo['plano_item_id'])
        ) {

            $plano_item_id =
                (int)$procedimento_antigo['plano_item_id'];
        }

        /*
        | Se não veio agendamento_id,
        | preservar o vínculo antigo.
        */

        if (
            $agendamento_id === null &&
            !empty($procedimento_antigo['agendamento_id'])
        ) {

            $agendamento_id =
                (int)$procedimento_antigo['agendamento_id'];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Verificar agendamento
    |--------------------------------------------------------------------------
    |
    | Agendamento é opcional.
    |
    | Quando informado:
    | - precisa existir;
    | - precisa pertencer ao paciente;
    | - precisa estar confirmado;
    | - seu plano_item_id passa a ser a origem do procedimento.
    |--------------------------------------------------------------------------
    */

    if ($agendamento_id !== null) {

        $stmt = $pdo->prepare("
            SELECT
                id,
                paciente_id,
                status,
                plano_item_id
            FROM agendamentos
            WHERE id = ?
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            $agendamento_id
        ]);

        $agendamento =
            $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$agendamento) {

            throw new Exception(
                'Agendamento não encontrado.'
            );
        }

        if (
            (int)$agendamento['paciente_id']
            !== $prontuario_id
        ) {

            throw new Exception(
                'O agendamento não pertence a este paciente.'
            );
        }

        if (
            $agendamento['status']
            !== 'confirmado'
        ) {

            throw new Exception(
                'O agendamento precisa estar confirmado antes de registrar o procedimento.'
            );
        }

        /*
        | Se o agendamento estiver ligado
        | a uma etapa do plano, usamos essa etapa.
        */

        if (
            !empty($agendamento['plano_item_id'])
        ) {

            $plano_item_id =
                (int)$agendamento['plano_item_id'];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 5. Verificar item do plano
    |--------------------------------------------------------------------------
    |
    | O item precisa pertencer a um plano
    | cujo paciente seja o mesmo do procedimento.
    |--------------------------------------------------------------------------
    */

    $plano_id = null;

    if ($plano_item_id !== null) {

        $stmt = $pdo->prepare("
            SELECT
                pti.id,
                pti.plano_id,
                pti.status,
                pt.paciente_id
            FROM planos_tratamento_itens pti
            INNER JOIN planos_tratamento pt
                ON pt.id = pti.plano_id
            WHERE pti.id = ?
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            $plano_item_id
        ]);

        $planoItem =
            $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$planoItem) {

            throw new Exception(
                'Item do plano de tratamento não encontrado.'
            );
        }

        if (
            (int)$planoItem['paciente_id']
            !== $prontuario_id
        ) {

            throw new Exception(
                'O item do plano não pertence a este paciente.'
            );
        }

        $plano_id =
            (int)$planoItem['plano_id'];
    }

    /*
    |--------------------------------------------------------------------------
    | 6. Verificar orçamento
    |--------------------------------------------------------------------------
    |
    | ORÇAMENTO É OPCIONAL.
    |
    | Se existir um orçamento vinculado,
    | ele precisa:
    |
    | - existir;
    | - pertencer ao paciente;
    | - estar aceito.
    |
    | O procedimento não depende mais de orçamento.
    |--------------------------------------------------------------------------
    */

    if ($orcamento_id !== null) {

        $stmt = $pdo->prepare("
            SELECT
                id,
                status
            FROM orcamentos
            WHERE id = ?
              AND paciente_id = ?
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            $orcamento_id,
            $prontuario_id
        ]);

        $orcamento =
            $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$orcamento) {

            throw new Exception(
                'Orçamento vinculado não encontrado.'
            );
        }

        if (
            $orcamento['status']
            !== 'aceito'
        ) {

            throw new Exception(
                'O orçamento vinculado ao procedimento precisa estar aceito.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 7. Verificar materiais antigos
    |--------------------------------------------------------------------------
    |
    | Na edição:
    |
    | 1. devolvemos os materiais antigos ao estoque;
    | 2. apagamos os vínculos antigos;
    | 3. depois registramos os novos materiais.
    |--------------------------------------------------------------------------
    */

    $materiaisProcessados = [];

    $valor_materiais = 0;

    $valor_sugerido = 0;

    if ($procedimento_id !== null) {

        $stmt = $pdo->prepare("
            SELECT
                estoque_id,
                quantidade
            FROM procedimento_materiais
            WHERE procedimento_id = ?
            FOR UPDATE
        ");

        $stmt->execute([
            $procedimento_id
        ]);

        $materiaisAntigos =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );

        foreach ($materiaisAntigos as $materialAntigo) {

            $pdo->prepare("
                UPDATE estoque
                SET quantidade = quantidade + ?
                WHERE id = ?
            ")->execute([

                (float)$materialAntigo['quantidade'],

                (int)$materialAntigo['estoque_id']

            ]);
        }

        $pdo->prepare("
            DELETE FROM procedimento_materiais
            WHERE procedimento_id = ?
        ")->execute([
            $procedimento_id
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 8. Validar e calcular materiais
    |--------------------------------------------------------------------------
    */

    foreach ($materiais as $material) {

        $estoque_id =
            (int)$material['estoque_id'];

        $quantidadeUsada =
            (float)$material['quantidade'];

        /*
        | Buscar estoque com bloqueio.
        */

        $stmt = $pdo->prepare("
            SELECT
                id,
                nome,
                quantidade,
                unidade,
                valor_item,
                valor_sugerido
            FROM estoque
            WHERE id = ?
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            $estoque_id
        ]);

        $estoque =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$estoque) {

            throw new Exception(
                'Material não encontrado no estoque.'
            );
        }

        $quantidadeDisponivel =
            (float)$estoque['quantidade'];

        /*
        | Impedir estoque negativo.
        */

        if (
            $quantidadeUsada >
            $quantidadeDisponivel
        ) {

            throw new Exception(
                'Estoque insuficiente para "' .
                    $estoque['nome'] .
                    '". Disponível: ' .
                    number_format(
                        $quantidadeDisponivel,
                        2,
                        ',',
                        '.'
                    ) .
                    ' ' .
                    $estoque['unidade'] .
                    '. Solicitado: ' .
                    number_format(
                        $quantidadeUsada,
                        2,
                        ',',
                        '.'
                    ) .
                    '.'
            );
        }

        $valorUnitario =
            (float)$estoque['valor_item'];

        $valorSugeridoUnitario =
            (float)$estoque['valor_sugerido'];

        $subtotalInformado =
            $material['subtotal_informado']
            ?? null;

        $valorTotal =
            $subtotalInformado === null

            ? (
                $valorUnitario *
                $quantidadeUsada
            )

            : max(
                0,
                (float)$subtotalInformado
            );

        $valorSugeridoTotal =
            $valorSugeridoUnitario *
            $quantidadeUsada;

        $valor_materiais +=
            $valorTotal;

        $valor_sugerido +=
            $valorSugeridoTotal;

        $materiaisProcessados[] = [

            'estoque_id' =>
            $estoque_id,

            'nome' =>
            $estoque['nome'],

            'quantidade' =>
            $quantidadeUsada,

            'valor_unitario' =>
            $valorUnitario,

            'valor_total' =>
            $valorTotal
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | 9. Calcular valores finais
    |--------------------------------------------------------------------------
    */

    $valor_materiais =
        round(
            $valor_materiais,
            2
        );

    $valor_sugerido =
        round(
            $valor_sugerido,
            2
        );

    $valor_mao_obra =
        round(
            $valor_mao_obra,
            2
        );

    /*
    | O valor final é definido pelo usuário.
    |
    | O backend apenas valida e armazena.
    */

    $valor_final =
        round(
            $valor_final_informado,
            2
        );

    /*
    |--------------------------------------------------------------------------
    | 10. Criar ou atualizar procedimento
    |--------------------------------------------------------------------------
    */

    if ($procedimento_id !== null) {

        $stmt = $pdo->prepare("
            UPDATE procedimentos

            SET
                titulo = ?,
                descricao = ?,
                medicamentos = ?,
                valor_materiais = ?,
                valor_mao_obra = ?,
                valor_final = ?,
                data_procedimento = ?,
                orcamento_id = ?,
                plano_item_id = ?,
                agendamento_id = ?

            WHERE id = ?
              AND paciente_id = ?
        ");

        $stmt->execute([

            $titulo,

            $descricao !== ''
                ? $descricao
                : null,

            $medicamentos !== ''
                ? $medicamentos
                : null,

            $valor_materiais,

            $valor_mao_obra,

            $valor_final,

            $data_procedimento,

            $orcamento_id,

            $plano_item_id,

            $agendamento_id,

            $procedimento_id,

            $prontuario_id
        ]);
    } else {

        $stmt = $pdo->prepare("
            INSERT INTO procedimentos (
                paciente_id,
                titulo,
                descricao,
                medicamentos,
                valor_materiais,
                valor_mao_obra,
                valor_final,
                data_procedimento,
                orcamento_id,
                plano_item_id,
                agendamento_id
            )

            VALUES (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
            )
        ");

        $stmt->execute([

            $prontuario_id,

            $titulo,

            $descricao !== ''
                ? $descricao
                : null,

            $medicamentos !== ''
                ? $medicamentos
                : null,

            $valor_materiais,

            $valor_mao_obra,

            $valor_final,

            $data_procedimento,

            $orcamento_id,

            $plano_item_id,

            $agendamento_id
        ]);

        $procedimento_id =
            (int)$pdo->lastInsertId();
    }

    /*
    |--------------------------------------------------------------------------
    | 11. Registrar materiais utilizados
    |--------------------------------------------------------------------------
    */

    $stmtMaterial = $pdo->prepare("
        INSERT INTO procedimento_materiais (
            procedimento_id,
            estoque_id,
            quantidade,
            valor_unitario,
            valor_total
        )

        VALUES (?, ?, ?, ?, ?)
    ");

    foreach (
        $materiaisProcessados
        as $material
    ) {

        $stmtMaterial->execute([

            $procedimento_id,

            $material['estoque_id'],

            $material['quantidade'],

            $material['valor_unitario'],

            $material['valor_total']
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 12. Baixar estoque
    |--------------------------------------------------------------------------
    */

    $stmtEstoque = $pdo->prepare("
        UPDATE estoque

        SET quantidade =
            quantidade - ?

        WHERE id = ?
          AND quantidade >= ?
    ");

    foreach (
        $materiaisProcessados
        as $material
    ) {

        $quantidade =
            $material['quantidade'];

        $estoque_id =
            $material['estoque_id'];

        $stmtEstoque->execute([

            $quantidade,

            $estoque_id,

            $quantidade
        ]);

        if (
            $stmtEstoque->rowCount()
            !== 1
        ) {

            throw new Exception(
                'Não foi possível atualizar o estoque do material "' .
                    $material['nome'] .
                    '".'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 13. Atualizar etapa do plano
    |--------------------------------------------------------------------------
    |
    | Se o procedimento estiver ligado a uma etapa,
    | consideramos a etapa concluída.
    |--------------------------------------------------------------------------
    */

    if ($plano_item_id !== null) {

        $stmt = $pdo->prepare("
            UPDATE planos_tratamento_itens

            SET status = 'concluido'

            WHERE id = ?
        ");

        $stmt->execute([
            $plano_item_id
        ]);

        /*
        |--------------------------------------------------------------------------
        | 14. Atualizar status geral do plano
        |--------------------------------------------------------------------------
        |
        | O plano será:
        |
        | - concluido
        |   se todas as etapas estiverem concluídas/canceladas;
        |
        | - em_andamento
        |   caso ainda existam etapas abertas.
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT

                plano_id,

                COUNT(*) AS total_etapas,

                SUM(
                    CASE
                        WHEN status IN (
                            'concluido',
                            'cancelado'
                        )
                        THEN 1
                        ELSE 0
                    END
                ) AS etapas_finalizadas

            FROM planos_tratamento_itens

            WHERE plano_id = ?

            GROUP BY plano_id
        ");

        $stmt->execute([
            $plano_id
        ]);

        $resumoPlano =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        if ($resumoPlano) {

            if (
                (int)$resumoPlano['total_etapas'] > 0
                &&
                (int)$resumoPlano['etapas_finalizadas']
                ===
                (int)$resumoPlano['total_etapas']
            ) {

                $novoStatusPlano =
                    'concluido';
            } else {

                $novoStatusPlano =
                    'em_andamento';
            }

            $stmt = $pdo->prepare("
                UPDATE planos_tratamento

                SET status = ?

                WHERE id = ?
            ");

            $stmt->execute([

                $novoStatusPlano,

                $plano_id
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 15. Confirmar transação
    |--------------------------------------------------------------------------
    */

    $pdo->commit();

    /*
    |--------------------------------------------------------------------------
    | 16. Resposta
    |--------------------------------------------------------------------------
    */

    echo json_encode([

        'success' =>
        true,

        'procedimento_id' =>
        $procedimento_id,

        'modo' =>
        $modo,

        'orcamento_id' =>
        $orcamento_id,

        'plano_item_id' =>
        $plano_item_id,

        'agendamento_id' =>
        $agendamento_id,

        'valor_materiais' =>
        $valor_materiais,

        'valor_sugerido' =>
        $valor_sugerido,

        'valor_mao_obra' =>
        $valor_mao_obra,

        'valor_final' =>
        $valor_final

    ]);

    exit;
} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | Rollback
    |--------------------------------------------------------------------------
    */

    if ($pdo->inTransaction()) {

        $pdo->rollBack();
    }

    http_response_code(400);

    echo json_encode([

        'success' =>
        false,

        'error' =>
        $e->getMessage()

    ]);

    exit;
}
