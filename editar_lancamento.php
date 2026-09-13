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

function normalizarValor(string $valor): ?string
{
    $valor = trim($valor);

    if ($valor === '') {
        return null;
    }

    // Formato brasileiro completo: 1.500,50
    if (str_contains($valor, ',')) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    } else {
        // Mantém formato decimal simples: 1500.50
        $valor = str_replace(' ', '', $valor);
    }

    if (!is_numeric($valor)) {
        return null;
    }

    $numero = (float)$valor;

    if ($numero <= 0) {
        return null;
    }

    return number_format($numero, 2, '.', '');
}

function valorParaFormulario(string $valor): string
{
    return number_format((float)$valor, 2, ',', '.');
}

function dataValida(string $data): bool
{
    $objeto = DateTime::createFromFormat('Y-m-d', $data);
    return $objeto !== false && $objeto->format('Y-m-d') === $data;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    header('Location: financeiro.php?erro=lancamento_invalido');
    exit;
}

$erros = [];
$sucesso = false;

try {
    $stmt = $pdo->prepare("
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
            procedimento_id,
            created_at
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

    // Esta tela é exclusiva para lançamentos manuais.
    if (
        !empty($lancamento['orcamento_id']) ||
        !empty($lancamento['parcela_id']) ||
        !empty($lancamento['procedimento_id'])
    ) {
        header('Location: financeiro.php?erro=lancamento_nao_editavel');
        exit;
    }

    // Se qualquer parcela já foi paga, o valor do lançamento fica bloqueado.
    $stmtParcelaPaga = $pdo->prepare("
        SELECT 1
        FROM lancamentos_parcelas
        WHERE lancamento_id = ?
          AND status = 'paga'
        LIMIT 1
    ");
    $stmtParcelaPaga->execute([$id]);
    $temParcelaPaga = (bool) $stmtParcelaPaga->fetchColumn();
} catch (Throwable $e) {
    error_log('editar_lancamento.php: ' . $e->getMessage());
    header('Location: financeiro.php?erro=erro_ao_carregar_lancamento');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        validar_csrf();

        $tipo = trim((string)($_POST['tipo'] ?? ''));
        $categoria = trim((string)($_POST['categoria'] ?? ''));
        $descricao = trim((string)($_POST['descricao'] ?? ''));
        $data = trim((string)($_POST['data'] ?? ''));
        $formaPagamento = trim((string)($_POST['forma_pagamento'] ?? ''));
        $valor = normalizarValor((string)($_POST['valor'] ?? ''));
        $observacoes = trim((string)($_POST['observacoes'] ?? ''));

        if (!in_array($tipo, ['receita', 'despesa'], true)) {
            $erros[] = 'Tipo de lançamento inválido.';
        }

        if ($categoria === '') {
            $erros[] = 'Informe a categoria.';
        } elseif (mb_strlen($categoria) > 100) {
            $erros[] = 'A categoria deve ter no máximo 100 caracteres.';
        }

        if ($descricao === '') {
            $erros[] = 'Informe a descrição.';
        } elseif (mb_strlen($descricao) > 255) {
            $erros[] = 'A descrição deve ter no máximo 255 caracteres.';
        }

        if (!dataValida($data)) {
            $erros[] = 'Informe uma data válida.';
        }

        if ($formaPagamento === '') {
            $erros[] = 'Informe a forma de pagamento.';
        } elseif (mb_strlen($formaPagamento) > 50) {
            $erros[] = 'A forma de pagamento deve ter no máximo 50 caracteres.';
        }

        if ($valor === null) {
            $erros[] = 'Informe um valor maior que zero.';
        }

        if (!$erros) {
            $pdo->beginTransaction();

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

            if (
                !empty($lancamentoLocked['orcamento_id']) ||
                !empty($lancamentoLocked['parcela_id']) ||
                !empty($lancamentoLocked['procedimento_id'])
            ) {
                throw new RuntimeException(
                    'Este lançamento está vinculado a outro registro e não pode ser editado aqui.'
                );
            }

            // Se qualquer parcela já foi paga, o valor não pode ser alterado.
            $stmtParcelaPagaLock = $pdo->prepare("
                SELECT 1
                FROM lancamentos_parcelas
                WHERE lancamento_id = ?
                  AND status = 'paga'
                LIMIT 1
            ");
            $stmtParcelaPagaLock->execute([$id]);
            $temParcelaPagaLock = (bool) $stmtParcelaPagaLock->fetchColumn();

            if (
                $temParcelaPagaLock &&
                $valor !== null &&
                (float) $valor !== (float) $lancamentoLocked['valor']
            ) {
                throw new RuntimeException(
                    'O valor não pode ser alterado porque este lançamento já possui uma parcela paga.'
                );
            }

            // Se qualquer parcela já foi paga, o tipo também não pode ser alterado.
            if (
                $temParcelaPagaLock &&
                $tipo !== $lancamentoLocked['tipo']
            ) {
                throw new RuntimeException(
                    'O tipo não pode ser alterado porque este lançamento já possui uma parcela paga.'
                );
            }

            // O status é controlado pelo fluxo de pagamento.
            // A edição mantém o status atual.
            $stmtUpdate = $pdo->prepare("
                UPDATE lancamentos_financeiros
                SET
                    tipo = :tipo,
                    categoria = :categoria,
                    descricao = :descricao,
                    data = :data,
                    forma_pagamento = :forma_pagamento,
                    valor = :valor,
                    observacoes = :observacoes
                WHERE id = :id
                  AND orcamento_id IS NULL
                  AND parcela_id IS NULL
                  AND procedimento_id IS NULL
            ");

            $stmtUpdate->execute([
                ':tipo' => $tipo,
                ':categoria' => $categoria,
                ':descricao' => $descricao,
                ':data' => $data,
                ':forma_pagamento' => $formaPagamento,
                ':valor' => $valor,
                ':observacoes' => $observacoes !== '' ? $observacoes : null,
                ':id' => $id,
            ]);

            $pdo->commit();

            header('Location: visualizar_lancamento.php?id=' . $id . '&sucesso=atualizado');
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('editar_lancamento.php: ' . $e->getMessage());
        $erros[] = $e->getMessage();
    }

    // Preserva os valores digitados quando a validação falhar.
    $lancamento['tipo'] = $tipo ?? $lancamento['tipo'];
    $lancamento['categoria'] = $categoria ?? $lancamento['categoria'];
    $lancamento['descricao'] = $descricao ?? $lancamento['descricao'];
    $lancamento['data'] = $data ?? $lancamento['data'];
    $lancamento['forma_pagamento'] = $formaPagamento ?? $lancamento['forma_pagamento'];
    $lancamento['valor'] = $valor ?? $lancamento['valor'];
    $lancamento['observacoes'] = $observacoes ?? $lancamento['observacoes'];
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Lançamento - Dentech</title>

    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/variables.css">
    <link rel="stylesheet" href="css/layout.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/editar_lancamento.css">
    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>

<body>

    <?php include 'navbar.php'; ?>

    <main class="page-container">
        <div class="page-header">
            <div>
                <span class="eyebrow">FINANCEIRO</span>
                <h1><i class="fa-solid fa-pen-to-square"></i> Editar lançamento</h1>
                <p>Atualize os dados deste lançamento manual.</p>
            </div>

            <a href="visualizar_lancamento.php?id=<?= (int)$lancamento['id'] ?>" class="btn btn-secondary">
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

        <section class="form-card">
            <div class="card-title">
                <div class="title-icon">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                </div>
                <div>
                    <h2>Dados do lançamento</h2>
                    <p>Altere as informações necessárias e salve as modificações.</p>
                </div>
            </div>

            <form method="POST" action="editar_lancamento.php?id=<?= (int)$lancamento['id'] ?>" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= escapar($csrfToken) ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="tipo">Tipo <span>*</span></label>
                        <select
                            name="tipo"
                            id="tipo"
                            required
                            <?= $temParcelaPaga ? 'disabled' : '' ?>>
                            <option value="receita" <?= $lancamento['tipo'] === 'receita' ? 'selected' : '' ?>>
                                Receita
                            </option>
                            <option value="despesa" <?= $lancamento['tipo'] === 'despesa' ? 'selected' : '' ?>>
                                Despesa
                            </option>
                        </select>

                        <?php if ($temParcelaPaga): ?>
                            <input
                                type="hidden"
                                name="tipo"
                                value="<?= escapar((string)$lancamento['tipo']) ?>">
                            <small>
                                O tipo está bloqueado porque este lançamento já possui uma parcela paga.
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="categoria">Categoria <span>*</span></label>
                        <input
                            type="text"
                            name="categoria"
                            id="categoria"
                            maxlength="100"
                            value="<?= escapar($lancamento['categoria']) ?>"
                            required>
                    </div>

                    <div class="form-group form-group-wide">
                        <label for="descricao">Descrição <span>*</span></label>
                        <input
                            type="text"
                            name="descricao"
                            id="descricao"
                            maxlength="255"
                            value="<?= escapar($lancamento['descricao']) ?>"
                            required>
                    </div>

                    <div class="form-group">
                        <label for="data">Data <span>*</span></label>
                        <input
                            type="date"
                            name="data"
                            id="data"
                            value="<?= escapar($lancamento['data']) ?>"
                            required>
                    </div>

                    <div class="form-group">
                        <label for="forma_pagamento">Forma de pagamento <span>*</span></label>
                        <input
                            type="text"
                            name="forma_pagamento"
                            id="forma_pagamento"
                            maxlength="50"
                            value="<?= escapar($lancamento['forma_pagamento']) ?>"
                            placeholder="Ex.: Pix, Dinheiro, Cartão..."
                            required>
                    </div>

                    <div class="form-group">
                        <label for="valor">Valor <span>*</span></label>
                        <div class="money-input">
                            <span>R$</span>
                            <input
                                type="text"
                                name="valor"
                                id="valor"
                                inputmode="decimal"
                                value="<?= escapar(valorParaFormulario((string)$lancamento['valor'])) ?>"
                                <?= $temParcelaPaga ? 'readonly' : '' ?>
                                required>
                        </div>

                        <?php if ($temParcelaPaga): ?>
                            <small>
                                O valor está bloqueado porque este lançamento já possui uma parcela paga.
                            </small>
                        <?php else: ?>
                            <small>Use o formato brasileiro, por exemplo: 1.500,50</small>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label>Status</label>
                        <div class="status-readonly <?= $lancamento['status'] === 'pago' ? 'status-paid' : 'status-pending' ?>">
                            <i class="fa-solid <?= $lancamento['status'] === 'pago' ? 'fa-circle-check' : 'fa-clock' ?>"></i>
                            <?= $lancamento['status'] === 'pago' ? 'Pago' : 'Pendente' ?>
                        </div>
                        <small>O status é alterado pelo fluxo de pagamento.</small>
                    </div>

                    <div class="form-group form-group-wide">
                        <label for="observacoes">Observações</label>
                        <textarea
                            name="observacoes"
                            id="observacoes"
                            rows="5"
                            placeholder="Observações adicionais..."><?= escapar((string)$lancamento['observacoes']) ?></textarea>
                    </div>
                </div>

                <div class="form-footer">
                    <a href="visualizar_lancamento.php?id=<?= (int)$lancamento['id'] ?>" class="btn btn-secondary">
                        Cancelar
                    </a>

                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-floppy-disk"></i>
                        Salvar alterações
                    </button>
                </div>
            </form>
        </section>
    </main>

    <script>
        (function() {
            const campoValor = document.getElementById('valor');

            if (!campoValor || campoValor.readOnly) {
                return;
            }

            campoValor.addEventListener('input', function() {
                let valor = this.value.replace(/[^\d,.-]/g, '');

                // Mantém apenas uma vírgula decimal.
                const partesVirgula = valor.split(',');
                if (partesVirgula.length > 2) {
                    valor = partesVirgula.shift() + ',' + partesVirgula.join('');
                }

                this.value = valor;
            });
        })();
    </script>

</body>

</html>