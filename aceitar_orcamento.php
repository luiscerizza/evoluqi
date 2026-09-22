<?php

require_once 'config/auth.php';
require_once 'config/csrf.php';
require_once 'conexao/conexao.php';
require_once 'config/orcamento.php';

exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: orcamento.php');
    exit;
}

validar_csrf();

$orcamento_id = (int)($_POST['id'] ?? 0);

if ($orcamento_id <= 0) {
    die('Orçamento inválido.');
}

try {

    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | 1. Buscar orçamento
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
        throw new Exception('Orçamento não encontrado.');
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Validar status
    |--------------------------------------------------------------------------
    */

    if ($orcamento['status'] !== 'pendente') {
        throw new Exception(
            'Este orçamento já possui uma decisão registrada e não pode ser confirmado novamente.'
        );
    }

    /*
|--------------------------------------------------------------------------
| 3. Aceitar itens do orçamento
|--------------------------------------------------------------------------
|
| O botão "Confirmar" aceita todos os itens que ainda
| estão pendentes.
|
| Isso não cria procedimento, cobrança, parcela
| ou lançamento financeiro.
|
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
            'Este orçamento não possui itens para confirmar.'
        );
    }

    /*
 * Aceita todos os itens que ainda estão pendentes.
 */
    $stmt_aceitar_itens = $pdo->prepare("
    UPDATE orcamentos_itens
    SET status = 'aceito'
    WHERE orcamento_id = ?
      AND status = 'pendente'
");

    $stmt_aceitar_itens->execute([$orcamento_id]);

    /*
 * Reconsulta os itens depois da decisão.
 */
    $stmt_itens->execute([$orcamento_id]);

    $itens_atualizados = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);

    /*
 * Calcula o status geral do orçamento
 * com base nos itens.
 */
    $novo_status = calcularStatusOrcamento($itens_atualizados);

    $stmt_status = $pdo->prepare("
    UPDATE orcamentos
    SET status = ?
    WHERE id = ?
      AND status = 'pendente'
");

    $stmt_status->execute([
        $novo_status,
        $orcamento_id
    ]);

    if ($stmt_status->rowCount() !== 1) {
        throw new Exception(
            'Não foi possível atualizar o status do orçamento.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Registrar log
    |--------------------------------------------------------------------------
    */

    if (function_exists('registrarLog')) {

        registrarLog(
            $pdo,
            'Confirmou orçamento',
            'orcamentos',
            $orcamento_id,
            'Orçamento confirmado. Nenhum lançamento financeiro foi criado.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 5. Confirmar transação
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
        'ERRO AO CONFIRMAR ORÇAMENTO #' .
            $orcamento_id .
            ': ' .
            $e->getMessage()
    );

    die('Não foi possível confirmar o orçamento. ' .
        htmlspecialchars(
            $e->getMessage(),
            ENT_QUOTES,
            'UTF-8'
        ));
}
