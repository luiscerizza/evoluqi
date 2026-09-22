<?php

require_once 'config/auth.php';
require_once 'config/csrf.php';
require_once 'config/orcamento.php';
require_once 'conexao/conexao.php';

exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

validar_csrf();

$item_id = (int)($_POST['item_id'] ?? 0);
$decisao = $_POST['decisao'] ?? '';

if ($item_id <= 0) {
    exit('Item do orçamento inválido.');
}

if (!in_array($decisao, ['aceito', 'recusado'], true)) {
    exit('Decisão inválida.');
}

try {

    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | 1. Buscar e bloquear o item
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            orcamento_id,
            status
        FROM orcamentos_itens
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([$item_id]);

    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        throw new Exception(
            'Item do orçamento não encontrado.'
        );
    }

    $orcamento_id = (int)$item['orcamento_id'];

    /*
    |--------------------------------------------------------------------------
    | 2. Buscar e bloquear o orçamento
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            status
        FROM orcamentos
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([$orcamento_id]);

    $orcamento = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$orcamento) {
        throw new Exception(
            'Orçamento não encontrado.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 3. Verificar se o item ainda está pendente
    |--------------------------------------------------------------------------
    */

    if ($item['status'] !== 'pendente') {
        throw new Exception(
            'Este item já possui uma decisão registrada.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Atualizar decisão do item
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        UPDATE orcamentos_itens
        SET status = ?
        WHERE id = ?
          AND status = 'pendente'
    ");

    $stmt->execute([
        $decisao,
        $item_id
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new Exception(
            'Não foi possível registrar a decisão do item.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 5. Buscar todos os itens novamente
    |--------------------------------------------------------------------------
    */

    $stmt_itens = $pdo->prepare("
        SELECT
            id,
            status
        FROM orcamentos_itens
        WHERE orcamento_id = ?
        ORDER BY id ASC
        FOR UPDATE
    ");

    $stmt_itens->execute([$orcamento_id]);

    $itens = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);

    if (count($itens) === 0) {
        throw new Exception(
            'O orçamento não possui itens.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 6. Calcular status geral
    |--------------------------------------------------------------------------
    */

    $novo_status = calcularStatusOrcamento($itens);

    /*
    |--------------------------------------------------------------------------
    | 7. Atualizar status geral do orçamento
    |--------------------------------------------------------------------------
    */

    $stmt_status = $pdo->prepare("
        UPDATE orcamentos
        SET status = ?
        WHERE id = ?
    ");

    $stmt_status->execute([
        $novo_status,
        $orcamento_id
    ]);

    /*
    |--------------------------------------------------------------------------
    | 8. Registrar log
    |--------------------------------------------------------------------------
    */

    $descricao_log = $decisao === 'aceito'
        ? 'Item do orçamento aceito.'
        : 'Item do orçamento recusado.';

    registrarLog(
        $pdo,
        $decisao === 'aceito'
            ? 'Aceitou item do orçamento'
            : 'Recusou item do orçamento',
        'orcamentos_itens',
        $item_id,
        $descricao_log
    );

    /*
    |--------------------------------------------------------------------------
    | 9. Confirmar transação
    |--------------------------------------------------------------------------
    */

    $pdo->commit();

    header(
        'Location: visualizar_orcamento.php?id=' .
        $orcamento_id
    );

    exit;

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'ERRO AO DECIDIR ITEM DO ORÇAMENTO #' .
        $item_id .
        ': ' .
        $e->getMessage()
    );

    die(
        'Não foi possível registrar a decisão do item. ' .
        htmlspecialchars(
            $e->getMessage(),
            ENT_QUOTES,
            'UTF-8'
        )
    );
}