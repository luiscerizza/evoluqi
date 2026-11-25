<?php

require_once 'config/auth.php';
require_once 'config/csrf.php';
require_once 'conexao/conexao.php';

exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: financeiro.php');
    exit;
}

validar_csrf();

$procedimento_id = (int)($_POST['procedimento_id'] ?? 0);
$forma_pagamento = trim($_POST['forma_pagamento'] ?? '');
$quantidade_parcelas = (int)($_POST['quantidade_parcelas'] ?? 0);
$primeiro_vencimento = trim($_POST['primeiro_vencimento'] ?? '');

if ($procedimento_id <= 0) {
    die('Procedimento inválido.');
}

if ($forma_pagamento === '') {
    die('Informe a forma de pagamento.');
}

if ($quantidade_parcelas < 1 || $quantidade_parcelas > 60) {
    die('Quantidade de parcelas inválida.');
}

$dataVencimento = DateTime::createFromFormat(
    'Y-m-d',
    $primeiro_vencimento
);

if (
    !$dataVencimento ||
    $dataVencimento->format('Y-m-d') !== $primeiro_vencimento
) {
    die('Data de vencimento inválida.');
}

if ($primeiro_vencimento < date('Y-m-d')) {
    die('O primeiro vencimento não pode ser anterior à data atual.');
}

try {

    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | 1. Buscar procedimento
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            paciente_id,
            titulo,
            valor_final,
            orcamento_id,
            arquivado
        FROM procedimentos
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        $procedimento_id
    ]);

    $procedimento = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$procedimento) {
        throw new Exception(
            'Procedimento não encontrado.'
        );
    }

    if ((int)$procedimento['arquivado'] === 1) {
        throw new Exception(
            'Este procedimento está arquivado e não pode gerar cobrança.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Validar valor
    |--------------------------------------------------------------------------
    */

    $valor_total = round(
        (float)$procedimento['valor_final'],
        2
    );

    if ($valor_total <= 0) {
        throw new Exception(
            'O procedimento não possui um valor final válido para cobrança.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 3. Verificar cobrança existente
    |--------------------------------------------------------------------------
    |
    | Um procedimento não pode receber uma segunda cobrança.
    |
    */

    $stmt = $pdo->prepare("
        SELECT id
        FROM parcelas
        WHERE procedimento_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        $procedimento_id
    ]);

    if ($stmt->fetchColumn()) {
        throw new Exception(
            'Este procedimento já possui uma cobrança cadastrada.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Calcular valores em centavos
    |--------------------------------------------------------------------------
    |
    | Trabalhamos em centavos para evitar problemas de arredondamento.
    |
    */

    $valor_total_centavos = (int)round(
        $valor_total * 100
    );

    $valor_base_centavos = intdiv(
        $valor_total_centavos,
        $quantidade_parcelas
    );

    $resto_centavos =
        $valor_total_centavos %
        $quantidade_parcelas;

    /*
    |--------------------------------------------------------------------------
    | 5. Preparar inserção das parcelas
    |--------------------------------------------------------------------------
    |
    | IMPORTANTE:
    |
    | orcamento_id permanece NULL.
    |
    | A cobrança pertence diretamente ao procedimento.
    |
    */

    $stmtParcela = $pdo->prepare("
        INSERT INTO parcelas (
            orcamento_id,
            procedimento_id,
            numero_parcela,
            valor,
            vencimento,
            status,
            data_pagamento
        )
        VALUES (
            NULL,
            ?,
            ?,
            ?,
            ?,
            'pendente',
            NULL
        )
    ");

    /*
    |--------------------------------------------------------------------------
    | 6. Criar parcelas
    |--------------------------------------------------------------------------
    */

    for ($i = 1; $i <= $quantidade_parcelas; $i++) {

        /*
        |--------------------------------------------------------------
        | Distribuição dos centavos
        |--------------------------------------------------------------
        |
        | Exemplo:
        |
        | R$ 400,00 / 3
        |
        | 1 = 133,34
        | 2 = 133,33
        | 3 = 133,33
        |
        | Total = 400,00
        |
        */

        $valor_parcela_centavos =
            $valor_base_centavos;

        if ($i <= $resto_centavos) {
            $valor_parcela_centavos++;
        }

        $valor_parcela =
            $valor_parcela_centavos / 100;

        /*
        |--------------------------------------------------------------
        | Data da parcela
        |--------------------------------------------------------------
        */

        if ($i > 1) {
            $vencimento = clone $dataVencimento;

            $vencimento->modify(
                '+' . ($i - 1) . ' month'
            );
        } else {
            $vencimento = clone $dataVencimento;
        }

        /*
        |--------------------------------------------------------------
        | Inserção
        |--------------------------------------------------------------
        */

        $stmtParcela->execute([
            $procedimento_id,
            $i,
            number_format(
                $valor_parcela,
                2,
                '.',
                ''
            ),
            $vencimento->format('Y-m-d')
        ]);

        $parcela_id = (int)$pdo->lastInsertId();

        /*
|--------------------------------------------------------------------------
| Criar lançamento financeiro vinculado à parcela
|--------------------------------------------------------------------------
*/

        $descricao = sprintf(
            'Procedimento #%d - %s - Parcela %d/%d',
            $procedimento_id,
            $procedimento['titulo'],
            $i,
            $quantidade_parcelas
        );

        $observacoes = sprintf(
            'Cobrança do procedimento #%d. Parcela %d/%d.',
            $procedimento_id,
            $i,
            $quantidade_parcelas
        );

        $stmtLancamento = $pdo->prepare("
    INSERT INTO lancamentos_financeiros (
        tipo,
        categoria,
        descricao,
        data,
        forma_pagamento,
        valor,
        parcelas,
        status,
        observacoes,
        orcamento_id,
        parcela_id,
        procedimento_id
    )
    VALUES (
        'receita',
        'Procedimento',
        ?,
        ?,
        ?,
        ?,
        ?,
        'pendente',
        ?,
        NULL,
        ?,
        ?
    )
");

        $stmtLancamento->execute([
            $descricao,
            $vencimento->format('Y-m-d'),
            $forma_pagamento,
            number_format(
                $valor_parcela,
                2,
                '.',
                ''
            ),
            $quantidade_parcelas,
            $observacoes,
            $parcela_id,
            $procedimento_id
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 7. Confirmar
    |--------------------------------------------------------------------------
    */

    $pdo->commit();

    /*
    |--------------------------------------------------------------------------
    | 8. Voltar para o prontuário
    |--------------------------------------------------------------------------
    */

    header(
        'Location: visualizar_prontuario.php?id=' .
            (int)$procedimento['paciente_id'] .
            '&cobranca=1'
    );

    exit;
} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'ERRO AO GERAR COBRANÇA DO PROCEDIMENTO #' .
            $procedimento_id .
            ': ' .
            $e->getMessage()
    );

    http_response_code(400);

    die('Não foi possível gerar a cobrança: ' .
        htmlspecialchars(
            $e->getMessage(),
            ENT_QUOTES,
            'UTF-8'
        ));
}
