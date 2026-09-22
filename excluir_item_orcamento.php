<?php

require_once 'config/auth.php';
require_once 'config/csrf.php';
require_once 'conexao/conexao.php';

exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

validar_csrf();

$orcamento_id = (int)($_POST['orcamento_id'] ?? 0);
$item_id = (int)($_POST['item_id'] ?? 0);

if ($orcamento_id <= 0 || $item_id <= 0) {
    exit('Dados inválidos.');
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
    | 2. O orçamento precisa estar pendente
    |--------------------------------------------------------------------------
    */

    if ($orcamento['status'] !== 'pendente') {
        throw new Exception(
            'Este orçamento não pode mais ter itens excluídos.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 3. Verificar se existe parcela paga
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM parcelas
        WHERE orcamento_id = ?
          AND status = 'paga'
    ");

    $stmt->execute([$orcamento_id]);

    if ((int)$stmt->fetchColumn() > 0) {
        throw new Exception(
            'Não é possível excluir itens de um orçamento que possui parcelas pagas.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Buscar item
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            descricao,
            status
        FROM orcamentos_itens
        WHERE id = ?
          AND orcamento_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        $item_id,
        $orcamento_id
    ]);

    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        throw new Exception(
            'Item não encontrado neste orçamento.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 5. Só pode excluir item pendente
    |--------------------------------------------------------------------------
    */

    if ($item['status'] !== 'pendente') {
        throw new Exception(
            'Este item não pode ser excluído porque já possui uma decisão registrada.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 6. Não permitir orçamento sem itens
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM orcamentos_itens
        WHERE orcamento_id = ?
    ");

    $stmt->execute([$orcamento_id]);

    $quantidade_itens = (int)$stmt->fetchColumn();

    if ($quantidade_itens <= 1) {
        throw new Exception(
            'O orçamento precisa possuir pelo menos um item.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 7. Excluir item
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        DELETE FROM orcamentos_itens
        WHERE id = ?
          AND orcamento_id = ?
          AND status = 'pendente'
    ");

    $stmt->execute([
        $item_id,
        $orcamento_id
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new Exception(
            'Não foi possível excluir o item.'
        );
    }

    /*
|--------------------------------------------------------------------------
| 7.1. Recalcular total dos itens restantes
|--------------------------------------------------------------------------
*/

    $stmt = $pdo->prepare("
    SELECT
        COALESCE(
            SUM(quantidade * valor_unitario),
            0
        )
    FROM orcamentos_itens
    WHERE orcamento_id = ?
");

    $stmt->execute([$orcamento_id]);

    $novo_total = round(
        (float)$stmt->fetchColumn(),
        2
    );

    /*
|--------------------------------------------------------------------------
| 7.2. Buscar condições comerciais
|--------------------------------------------------------------------------
*/

    $stmt = $pdo->prepare("
    SELECT
        id,
        numero,
        valor,
        vencimento_previsto
    FROM orcamentos_condicoes
    WHERE orcamento_id = ?
    ORDER BY numero ASC
");

    $stmt->execute([$orcamento_id]);

    $condicoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
|--------------------------------------------------------------------------
| 7.3. Atualizar valores das condições
|--------------------------------------------------------------------------
*/

    if (!empty($condicoes)) {

        $quantidade_condicoes = count($condicoes);

        $valor_base = round(
            $novo_total / $quantidade_condicoes,
            2
        );

        $valor_acumulado = 0;

        $stmt_update_condicao = $pdo->prepare("
        UPDATE orcamentos_condicoes
        SET valor = ?
        WHERE id = ?
          AND orcamento_id = ?
    ");

        foreach ($condicoes as $indice => $condicao) {

            if ($indice < $quantidade_condicoes - 1) {

                $valor_condicao = $valor_base;
            } else {

                $valor_condicao = round(
                    $novo_total - $valor_acumulado,
                    2
                );
            }

            $valor_acumulado = round(
                $valor_acumulado + $valor_condicao,
                2
            );

            $stmt_update_condicao->execute([
                $valor_condicao,
                (int)$condicao['id'],
                $orcamento_id
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 8. Registrar log
    |--------------------------------------------------------------------------
    */

    if (function_exists('registrarLog')) {

        registrarLog(
            $pdo,
            'Excluiu item de orçamento',
            'orcamentos_itens',
            $item_id,
            'Item "' .
                $item['descricao'] .
                '" excluído do orçamento #' .
                $orcamento_id
        );
    }

    $pdo->commit();

    header(
        'Location: editar_orcamento.php?id=' .
            $orcamento_id
    );

    exit;
} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'ERRO AO EXCLUIR ITEM DO ORÇAMENTO #' .
            $orcamento_id .
            ': ' .
            $e->getMessage()
    );

    die('Não foi possível excluir o item. ' .
        htmlspecialchars(
            $e->getMessage(),
            ENT_QUOTES,
            'UTF-8'
        ));
}
