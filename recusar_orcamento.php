<?php
require_once 'config/auth.php';
require_once 'config/csrf.php';
require_once 'config/orcamento.php';
exigirLogin();
require_once 'conexao/conexao.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

validar_csrf();

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    exit('Orçamento inválido.');
}
try {

    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | 1. Buscar e bloquear o orçamento
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

    $stmt->execute([$id]);

    $orcamento = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$orcamento) {
        throw new Exception('Orçamento não encontrado.');
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Validar status
    |--------------------------------------------------------------------------
    */

    if ($orcamento['status'] !== 'pendente') {
        throw new Exception(
            'Este orçamento já possui uma decisão registrada e não pode ser recusado novamente.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 3. Buscar itens
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

    $stmt_itens->execute([$id]);

    $itens = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);

    if (count($itens) === 0) {
        throw new Exception(
            'Este orçamento não possui itens para recusar.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Recusar itens pendentes
    |--------------------------------------------------------------------------
    */

    $stmt_recusar_itens = $pdo->prepare("
        UPDATE orcamentos_itens
        SET status = 'recusado'
        WHERE orcamento_id = ?
          AND status = 'pendente'
    ");

    $stmt_recusar_itens->execute([$id]);

    /*
    |--------------------------------------------------------------------------
    | 5. Reconsultar itens
    |--------------------------------------------------------------------------
    */

    $stmt_itens->execute([$id]);

    $itens_atualizados = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | 6. Calcular status geral
    |--------------------------------------------------------------------------
    */

    $novo_status = calcularStatusOrcamento($itens_atualizados);

    /*
    |--------------------------------------------------------------------------
    | 7. Atualizar status do orçamento
    |--------------------------------------------------------------------------
    */

    $stmt_status = $pdo->prepare("
        UPDATE orcamentos
        SET status = ?
        WHERE id = ?
          AND status = 'pendente'
    ");

    $stmt_status->execute([
        $novo_status,
        $id
    ]);

    if ($stmt_status->rowCount() !== 1) {
        throw new Exception(
            'Não foi possível atualizar o status do orçamento.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 8. Registrar log
    |--------------------------------------------------------------------------
    */

    registrarLog(
        $pdo,
        'Recusou orçamento',
        'orcamentos',
        $id,
        'Itens pendentes do orçamento foram recusados. Nenhum lançamento financeiro foi criado.'
    );

    /*
    |--------------------------------------------------------------------------
    | 9. Confirmar transação
    |--------------------------------------------------------------------------
    */

    $pdo->commit();
} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'ERRO AO RECUSAR ORÇAMENTO #' .
            $id .
            ': ' .
            $e->getMessage()
    );

    die('Não foi possível recusar o orçamento. ' .
        htmlspecialchars(
            $e->getMessage(),
            ENT_QUOTES,
            'UTF-8'
        ));
}

header("Location: visualizar_orcamento.php?id=" . $id);
exit;
