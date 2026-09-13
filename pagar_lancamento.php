<?php

declare(strict_types=1);

require_once 'config/auth.php';
require_once 'config/csrf.php';
require_once 'conexao/conexao.php';

exigirLogin();

function escapar(?string $valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function moedaBR($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function dataBR(?string $data): string
{
    if (!$data) {
        return '-';
    }

    $objeto = DateTime::createFromFormat('Y-m-d', $data);

    return $objeto ? $objeto->format('d/m/Y') : $data;
}

function textoTipo(string $tipo): string
{
    return $tipo === 'despesa' ? 'Despesa' : 'Receita';
}

function classeTipo(string $tipo): string
{
    return $tipo === 'despesa' ? 'type-expense' : 'type-income';
}

function textoStatus(string $status): string
{
    return match (strtolower(trim($status))) {
        'pago', 'paga' => 'Pago',
        'atrasada' => 'Vencido',
        default => 'Pendente',
    };
}

function classeStatus(string $status): string
{
    return match (strtolower(trim($status))) {
        'pago', 'paga' => 'status-pago',
        'atrasada' => 'status-atrasada',
        default => 'status-pendente',
    };
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$parcela_id = filter_input(INPUT_GET, 'parcela_id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    header('Location: financeiro.php?erro=lancamento_invalido');
    exit;
}

$parcela_id = $parcela_id && $parcela_id > 0
    ? $parcela_id
    : null;

try {
    /*
     * Carregar o lançamento-pai.
     */
    $stmt = $pdo->prepare("
        SELECT
            id,
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
        FROM lancamentos_financeiros
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$id]);

    $lancamento = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lancamento) {
        header('Location: financeiro.php?erro=lancamento_nao_encontrado');
        exit;
    }

    $eh_parcela_manual = false;
    $parcela = null;

    /*
     * Se foi informada uma parcela, validar se ela
     * pertence ao lançamento manual.
     */
    if ($parcela_id !== null) {
        if (
            !empty($lancamento['orcamento_id']) ||
            !empty($lancamento['parcela_id']) ||
            !empty($lancamento['procedimento_id'])
        ) {
            header('Location: financeiro.php?erro=lancamento_nao_editavel');
            exit;
        }

        $stmtParcela = $pdo->prepare("
            SELECT
                id,
                lancamento_id,
                numero_parcela,
                valor,
                vencimento,
                status,
                data_pagamento
            FROM lancamentos_parcelas
            WHERE id = ?
              AND lancamento_id = ?
            LIMIT 1
        ");

        $stmtParcela->execute([
            $parcela_id,
            $id
        ]);

        $parcela = $stmtParcela->fetch(PDO::FETCH_ASSOC);

        if (!$parcela) {
            header('Location: financeiro.php?erro=parcela_nao_encontrada');
            exit;
        }

        $statusParcela = strtolower(
            trim((string) $parcela['status'])
        );

        if (!in_array(
            $statusParcela,
            ['pendente', 'atrasada'],
            true
        )) {
            header('Location: financeiro.php?erro=parcela_nao_pendente');
            exit;
        }

        $eh_parcela_manual = true;
    } else {
        /*
         * Sem parcela_id, só permitimos pagamento de
         * lançamento manual à vista.
         */
        if (
            !empty($lancamento['orcamento_id']) ||
            !empty($lancamento['parcela_id']) ||
            !empty($lancamento['procedimento_id'])
        ) {
            header('Location: financeiro.php?erro=lancamento_nao_editavel');
            exit;
        }
    }
} catch (Throwable $e) {
    error_log('pagar_lancamento.php [GET]: ' . $e->getMessage());

    header('Location: financeiro.php?erro=erro_ao_carregar_lancamento');
    exit;
}

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        validar_csrf();

        $idPost = filter_input(
            INPUT_POST,
            'lancamento_id',
            FILTER_VALIDATE_INT
        );

        $parcelaIdPost = filter_input(
            INPUT_POST,
            'parcela_id',
            FILTER_VALIDATE_INT
        );

        if (!$idPost || $idPost !== $id) {
            throw new RuntimeException('Lançamento inválido.');
        }

        /*
         * O parcela_id enviado pelo formulário precisa
         * corresponder ao parcela_id da URL.
         */
        $parcelaIdPost = $parcelaIdPost && $parcelaIdPost > 0
            ? $parcelaIdPost
            : null;

        if ($parcelaIdPost !== $parcela_id) {
            throw new RuntimeException('Parcela inválida.');
        }

        $pdo->beginTransaction();

        /*
         * Bloquear o lançamento-pai para evitar que
         * duas operações alterem o mesmo registro
         * simultaneamente.
         */
        $stmtLock = $pdo->prepare("
            SELECT
                id,
                tipo,
                categoria,
                descricao,
                data,
                data_pagamento,
                forma_pagamento,
                valor,
                parcelas,
                status,
                observacoes,
                orcamento_id,
                parcela_id,
                procedimento_id
            FROM lancamentos_financeiros
            WHERE id = ?
            FOR UPDATE
        ");

        $stmtLock->execute([$id]);

        $lancamentoLocked = $stmtLock->fetch(PDO::FETCH_ASSOC);

        if (!$lancamentoLocked) {
            throw new RuntimeException('Lançamento não encontrado.');
        }

        /*
         * Este fluxo continua sendo exclusivo para
         * lançamentos financeiros manuais.
         */
        if (
            !empty($lancamentoLocked['orcamento_id']) ||
            !empty($lancamentoLocked['parcela_id']) ||
            !empty($lancamentoLocked['procedimento_id'])
        ) {
            throw new RuntimeException(
                'Este lançamento está vinculado a outro registro e não pode ser pago por aqui.'
            );
        }

        /*
         * =====================================================
         * PAGAMENTO DE UMA PARCELA MANUAL
         * =====================================================
         */
        if ($parcela_id !== null) {
            /*
             * Bloquear a parcela específica.
             */
            $stmtParcelaLock = $pdo->prepare("
                SELECT
                    id,
                    lancamento_id,
                    numero_parcela,
                    valor,
                    vencimento,
                    status,
                    data_pagamento
                FROM lancamentos_parcelas
                WHERE id = ?
                  AND lancamento_id = ?
                FOR UPDATE
            ");

            $stmtParcelaLock->execute([
                $parcela_id,
                $id
            ]);

            $parcelaLocked = $stmtParcelaLock->fetch(PDO::FETCH_ASSOC);

            if (!$parcelaLocked) {
                throw new RuntimeException(
                    'Parcela não encontrada para este lançamento.'
                );
            }

            /*
             * Parcela pendente ou atrasada pode ser paga.
             */
            $statusParcela = strtolower(
                trim((string) $parcelaLocked['status'])
            );

            if (!in_array(
                $statusParcela,
                ['pendente', 'atrasada'],
                true
            )) {
                throw new RuntimeException(
                    'Esta parcela já está marcada como paga.'
                );
            }

            /*
             * Marcar SOMENTE a parcela como paga.
             */
            $stmtUpdateParcela = $pdo->prepare("
                UPDATE lancamentos_parcelas
                SET
                    status = 'paga',
                    data_pagamento = CURDATE()
                WHERE id = ?
                  AND lancamento_id = ?
                  AND status IN ('pendente', 'atrasada')
            ");

            $stmtUpdateParcela->execute([
                $parcela_id,
                $id
            ]);

            if ($stmtUpdateParcela->rowCount() !== 1) {
                throw new RuntimeException(
                    'Não foi possível registrar o pagamento da parcela.'
                );
            }

            /*
             * Verificar se ainda existem parcelas em aberto.
             */
            $stmtPendentes = $pdo->prepare("
                SELECT COUNT(*)
                FROM lancamentos_parcelas
                WHERE lancamento_id = ?
                  AND status IN ('pendente', 'atrasada')
            ");

            $stmtPendentes->execute([$id]);

            $parcelasPendentes = (int) $stmtPendentes->fetchColumn();

            /*
             * O lançamento-pai só fica pago quando
             * TODAS as parcelas estiverem pagas.
             */
            if ($parcelasPendentes === 0) {
                $stmtUpdatePai = $pdo->prepare("
                    UPDATE lancamentos_financeiros
                    SET
                        status = 'pago',
                        data_pagamento = CURDATE()
                    WHERE id = ?
                      AND status = 'pendente'
                      AND orcamento_id IS NULL
                      AND parcela_id IS NULL
                      AND procedimento_id IS NULL
                ");

                $stmtUpdatePai->execute([$id]);

                if ($stmtUpdatePai->rowCount() !== 1) {
                    throw new RuntimeException(
                        'A parcela foi paga, mas não foi possível finalizar o lançamento.'
                    );
                }
            }

            $pdo->commit();

            $mensagem = $lancamentoLocked['tipo'] === 'despesa'
                ? 'Parcela da despesa marcada como paga.'
                : 'Parcela da receita marcada como recebida.';

            header(
                'Location: visualizar_lancamento.php?id=' .
                    $id .
                    '&parcela_id=' .
                    $parcela_id .
                    '&sucesso=' .
                    urlencode($mensagem)
            );

            exit;
        }

        /*
         * =====================================================
         * PAGAMENTO DE LANÇAMENTO À VISTA
         * =====================================================
         */

        if ($lancamentoLocked['status'] === 'pago') {
            throw new RuntimeException(
                'Este lançamento já está marcado como pago.'
            );
        }

        /*
         * Lançamento à vista: aqui sim alteramos
         * diretamente o lançamento-pai.
         */
        $stmtUpdate = $pdo->prepare("
            UPDATE lancamentos_financeiros
            SET
                status = 'pago',
                data_pagamento = CURDATE()
            WHERE id = ?
              AND status = 'pendente'
              AND orcamento_id IS NULL
              AND parcela_id IS NULL
              AND procedimento_id IS NULL
        ");

        $stmtUpdate->execute([$id]);

        if ($stmtUpdate->rowCount() !== 1) {
            throw new RuntimeException(
                'Não foi possível concluir o pagamento do lançamento.'
            );
        }

        $pdo->commit();

        $mensagem = $lancamentoLocked['tipo'] === 'despesa'
            ? 'Despesa marcada como paga.'
            : 'Receita marcada como recebida.';

        header(
            'Location: visualizar_lancamento.php?id=' .
                $id .
                '&sucesso=' .
                urlencode($mensagem)
        );

        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'pagar_lancamento.php [POST]: ' .
                $e->getMessage()
        );

        $erros[] = $e->getMessage();
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$isDespesa = $lancamento['tipo'] === 'despesa';

$valorExibicao = $eh_parcela_manual
    ? (float) $parcela['valor']
    : (float) $lancamento['valor'];

$statusExibicao = $eh_parcela_manual
    ? strtolower(trim((string) $parcela['status']))
    : strtolower(trim((string) $lancamento['status']));

$dataVencimentoExibicao = $eh_parcela_manual
    ? $parcela['vencimento']
    : $lancamento['data'];

$numeroParcelaExibicao = $eh_parcela_manual
    ? (int) $parcela['numero_parcela']
    : null;

if ($eh_parcela_manual) {
    $acaoTexto = $isDespesa
        ? 'Pagar parcela da despesa'
        : 'Marcar parcela como recebida';

    $confirmacaoTexto = $isDespesa
        ? 'Confirme que esta parcela da despesa foi efetivamente paga.'
        : 'Confirme que esta parcela da receita foi efetivamente recebida.';
} else {
    $acaoTexto = $isDespesa
        ? 'Pagar despesa'
        : 'Marcar receita como recebida';

    $confirmacaoTexto = $isDespesa
        ? 'Confirme que esta despesa foi efetivamente paga.'
        : 'Confirme que esta receita foi efetivamente recebida.';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escapar($acaoTexto) ?> - Dentech</title>

    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/variables.css">
    <link rel="stylesheet" href="css/layout.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/pagar_lancamento.css">
    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>

<body>

    <?php include 'navbar.php'; ?>

    <main class="page-container">
        <div class="page-header">
            <div>
                <span class="eyebrow">FINANCEIRO</span>
                <h1>
                    <i class="fa-solid <?= $isDespesa ? 'fa-money-bill-transfer' : 'fa-circle-check' ?>"></i>
                    <?= escapar($acaoTexto) ?>
                </h1>
                <p><?= escapar($confirmacaoTexto) ?></p>
            </div>

            <a
                href="visualizar_lancamento.php?id=<?= (int) $lancamento['id'] ?><?= $eh_parcela_manual ? '&amp;parcela_id=' . (int) $parcela['id'] : '' ?>"
                class="btn btn-secondary">
                <i class="fa-solid fa-arrow-left"></i>
                Voltar
            </a>
        </div>

        <?php if ($erros): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <div>
                    <?php foreach ($erros as $erro): ?>
                        <div><?= escapar($erro) ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <section class="payment-card">

            <?php
            if ($eh_parcela_manual) {
                $statusAtual = strtolower(
                    trim((string) $parcela['status'])
                );

                if (
                    $statusAtual === 'pendente'
                    && !empty($parcela['vencimento'])
                    && $parcela['vencimento'] < date('Y-m-d')
                ) {
                    $statusAtual = 'atrasada';
                }
            } else {
                $statusAtual = strtolower(
                    trim((string) $lancamento['status'])
                );
            }
            ?>

            <div class="card-title">
                <div class="title-icon <?= $isDespesa ? 'icon-expense' : 'icon-income' ?>">
                    <i class="fa-solid <?= $isDespesa ? 'fa-receipt' : 'fa-hand-holding-dollar' ?>"></i>
                </div>

                <div>
                    <h2>Confirmar operação</h2>
                    <p>Confira os dados antes de alterar o status financeiro.</p>
                </div>

                <span class="status-topo <?= escapar(classeStatus($statusAtual === 'paga' ? 'pago' : $statusAtual)) ?>">
                    <i class="fa-solid <?= $statusAtual === 'paga' || $statusAtual === 'pago'
                                            ? 'fa-circle-check'
                                            : 'fa-clock' ?>"></i>

                    <?= escapar(textoStatus($statusAtual)) ?>
                </span>
            </div>

            <div class="summary-grid">

                <div class="summary-item">
                    <span>Tipo</span>
                    <strong class="<?= escapar(classeTipo($lancamento['tipo'])) ?>">
                        <?= escapar(textoTipo($lancamento['tipo'])) ?>
                    </strong>
                </div>

                <div class="summary-item">
                    <span>Categoria</span>
                    <strong><?= escapar($lancamento['categoria']) ?></strong>
                </div>

                <div class="summary-item summary-wide">
                    <span>Descrição</span>
                    <strong><?= escapar($lancamento['descricao']) ?></strong>
                </div>

                <?php if ($eh_parcela_manual): ?>

                    <div class="summary-item">
                        <span>Parcela</span>
                        <strong>
                            <?= (int) $parcela['numero_parcela'] ?>
                            de
                            <?= (int) $lancamento['parcelas'] ?>
                        </strong>
                    </div>

                    <div class="summary-item">
                        <span>Valor da parcela</span>
                        <strong class="value">
                            <?= moedaBR($parcela['valor']) ?>
                        </strong>
                    </div>

                    <div class="summary-item">
                        <span>Vencimento</span>
                        <strong>
                            <?= escapar(dataBR($parcela['vencimento'])) ?>
                        </strong>
                    </div>

                <?php else: ?>

                    <div class="summary-item">
                        <span>Valor</span>
                        <strong class="value">
                            <?= moedaBR($lancamento['valor']) ?>
                        </strong>
                    </div>

                    <div class="summary-item">
                        <span>Data</span>
                        <strong>
                            <?= escapar(dataBR($lancamento['data'])) ?>
                        </strong>
                    </div>

                <?php endif; ?>

                <div class="summary-item">
                    <span>Forma de pagamento</span>
                    <strong>
                        <?= escapar($lancamento['forma_pagamento']) ?>
                    </strong>
                </div>
            </div>

            <div class="warning-box">
                <i class="fa-solid fa-circle-info"></i>

                <div>
                    <strong>O que acontecerá?</strong>

                    <?php if ($eh_parcela_manual): ?>

                        <p>
                            Esta parcela será alterada de
                            <strong>
                                <?= escapar(textoStatus($statusAtual)) ?>
                            </strong>
                            para
                            <strong>Pago</strong>.
                            O lançamento principal só será marcado como pago
                            quando todas as parcelas estiverem quitadas.
                        </p>

                    <?php else: ?>

                        <p>
                            O lançamento será alterado de
                            <strong>Pendente</strong>
                            para
                            <strong>Pago</strong>.
                            Esta operação não cria parcelas,
                            não altera procedimentos e não modifica orçamentos.
                        </p>

                    <?php endif; ?>
                </div>
            </div>

            <form
                method="POST"
                action="pagar_lancamento.php?id=<?= (int) $lancamento['id'] ?><?= $eh_parcela_manual ? '&amp;parcela_id=' . (int) $parcela['id'] : '' ?>">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escapar($csrfToken) ?>">

                <input
                    type="hidden"
                    name="lancamento_id"
                    value="<?= (int) $lancamento['id'] ?>">

                <?php if ($eh_parcela_manual): ?>

                    <input
                        type="hidden"
                        name="parcela_id"
                        value="<?= (int) $parcela['id'] ?>">

                <?php endif; ?>

                <div class="form-footer">

                    <a
                        href="visualizar_lancamento.php?id=<?= (int) $lancamento['id'] ?><?= $eh_parcela_manual ? '&amp;parcela_id=' . (int) $parcela['id'] : '' ?>"
                        class="btn btn-secondary">
                        Cancelar
                    </a>

                    <button type="submit" class="btn btn-success">
                        <i class="fa-solid fa-circle-check"></i>
                        <?= escapar($acaoTexto) ?>
                    </button>

                </div>

            </form>
        </section>
    </main>

</body>

</html>