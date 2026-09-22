<?php
require_once 'config/auth.php';
require_once 'config/csrf.php';
exigirLogin();
require_once 'conexao/conexao.php';

// ============================================================
// VERIFICA ID DO ORÇAMENTO
// ============================================================
$id_orc = (int)($_GET['id'] ?? 0);

if ($id_orc <= 0) {
    die("ID do orçamento não informado.");
}

// ============================================================
// BUSCA ORÇAMENTO + PACIENTE
// ============================================================
$stmt = $pdo->prepare("
    SELECT 
        o.*,
        p.paciente,
        p.cpf,
        p.telefone,
        p.email
    FROM orcamentos o
    JOIN prontuarios p ON o.paciente_id = p.id
    WHERE o.id = ?
");

$stmt->execute([$id_orc]);
$orc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$orc) {
    die("Orçamento não encontrado.");
}

// ============================================================
// BUSCA ITENS
// ============================================================
$stmt_itens = $pdo->prepare("
    SELECT *
    FROM orcamentos_itens
    WHERE orcamento_id = ?
    ORDER BY id ASC
");
$stmt_itens->execute([$id_orc]);
$itens_existentes = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// BUSCA CONDIÇÕES COMERCIAIS
// ============================================================
$stmt_condicoes = $pdo->prepare("
    SELECT
        id,
        orcamento_id,
        numero,
        valor,
        vencimento_previsto
    FROM orcamentos_condicoes
    WHERE orcamento_id = ?
    ORDER BY numero ASC
");
$stmt_condicoes->execute([$id_orc]);
$condicoes_existentes = $stmt_condicoes->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// CALCULA TOTAL ATUAL
// ============================================================
$total_atual = 0;
foreach ($itens_existentes as $item) {
    $quantidade = (int)($item['quantidade'] ?? 0);
    $valor = (float)($item['valor_unitario'] ?? 0);
    $total_atual += $quantidade * $valor;
}

$total_atual = round($total_atual, 2);
// ============================================================
// VERIFICA SE O ORÇAMENTO PODE SER EDITADO
// ============================================================
$status_orcamento = $orc['status'] ?? 'pendente';
$orcamento_arquivado = (int)($orc['arquivado'] ?? 0) === 1;
$orcamento_bloqueado = $status_orcamento !== 'pendente';

$orcamento_bloqueado =
    $status_orcamento !== 'pendente'
    || $orcamento_arquivado;
if ($orcamento_arquivado) {

    throw new Exception(
        "Este orçamento está arquivado e não pode mais ser editado."
    );
}

if ($status_orcamento !== 'pendente') {

    throw new Exception(
        "Este orçamento não pode mais ser editado porque já possui uma decisão registrada."
    );
}

// ============================================================
// FUNÇÃO PARA NORMALIZAR VALORES MONETÁRIOS
// ============================================================
function normalizarValorMonetario($valor)
{
    $valor = trim((string)$valor);
    if ($valor === '') {
        return 0;
    }
    // Remove R$
    $valor = str_replace('R$', '', $valor);
    $valor = trim($valor);

    /*
     * Aceita:
     * 1500.50
     * 1500,50
     * 1.500,50
     */

    if (strpos($valor, ',') !== false) {
        // Formato brasileiro: 1.500,50
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    }

    return (float)$valor;
}
// ============================================================
// PROCESSAMENTO
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validar_csrf();
    try {
        if ($status_orcamento !== 'pendente') {
            throw new Exception(
                "Este orçamento não pode mais ser editado porque já possui uma decisão registrada."
            );
        }
        $pdo->beginTransaction();

        // ====================================================
        // DADOS GERAIS
        // ====================================================
        $validade = trim($_POST['validade'] ?? '');
        $observacoes = trim($_POST['observacoes'] ?? '');

        if ($validade === '') {
            throw new Exception("A data de validade é obrigatória.");
        }

        // Validação da data
        $dataValidade = DateTime::createFromFormat('Y-m-d', $validade);
        if (
            !$dataValidade ||
            $dataValidade->format('Y-m-d') !== $validade
        ) {
            throw new Exception("A data de validade é inválida.");
        }

        // ====================================================
        // ATUALIZA DADOS DO ORÇAMENTO
        // ====================================================
        $stmt = $pdo->prepare("
            UPDATE orcamentos
            SET validade = ?,
                observacoes = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $validade,
            $observacoes,
            $id_orc
        ]);

        // ====================================================
        // SE EXISTE PARCELA PAGA:
        // NÃO ALTERA ITENS NEM PARCELAMENTO
        // SEM PARCELA PAGA:
        // PODE ALTERAR ITENS
        // ====================================================
        $descricoes = $_POST['descricao'] ?? [];
        $quantidades = $_POST['quantidade'] ?? [];
        $valores = $_POST['valor'] ?? [];
        $item_ids = $_POST['item_id'] ?? [];

        if (!is_array($descricoes)) {
            $descricoes = [];
        }
        if (!is_array($quantidades)) {
            $quantidades = [];
        }
        if (!is_array($valores)) {
            $valores = [];
        }

        $total_novo = 0;
        $itens_validos = [];
        foreach ($descricoes as $i => $desc) {
            $desc = trim((string)$desc);
            $qtd = (int)($quantidades[$i] ?? 1);
            $valor = normalizarValorMonetario(
                $valores[$i] ?? ''
            );

            // Ignora linha completamente vazia
            if ($desc === '' && $valor <= 0) {
                continue;
            }
            // Descrição obrigatória para item preenchido
            if ($desc === '') {
                throw new Exception(
                    "O item #" . ($i + 1) . " precisa ter uma descrição."
                );
            }

            // Quantidade obrigatoriamente positiva
            if ($qtd <= 0) {
                throw new Exception(
                    "A quantidade do item #" . ($i + 1) . " deve ser maior que zero."
                );
            }

            // Valor obrigatoriamente positivo
            if ($valor <= 0) {
                throw new Exception(
                    "O valor do item #" . ($i + 1) . " deve ser maior que zero."
                );
            }

            $subtotal = round($qtd * $valor, 2);
            $total_novo += $subtotal;
            $item_id = (int)($item_ids[$i] ?? 0);
            $itens_validos[] = [
                'id' => $item_id,
                'descricao' => $desc,
                'quantidade' => $qtd,
                'valor' => $valor
            ];
        }

        $total_novo = round($total_novo, 2);

        if (empty($itens_validos)) {
            throw new Exception(
                "Adicione pelo menos 1 item válido ao orçamento."
            );
        }

        // ============================================================
        // ATUALIZA / INSERE ITENS
        // ============================================================

        $stmtUpdateItem = $pdo->prepare("
            UPDATE orcamentos_itens
            SET
                descricao = ?,
                quantidade = ?,
                valor_unitario = ?
            WHERE id = ?
              AND orcamento_id = ?
              AND status = 'pendente'
        ");

        $stmtInsertItem = $pdo->prepare("
            INSERT INTO orcamentos_itens (
                orcamento_id,
                descricao,
                quantidade,
                valor_unitario,
                status
            )
            VALUES (?, ?, ?, ?, 'pendente')
        ");

        foreach ($itens_validos as $item) {
            $item_id = (int)$item['id'];
            if ($item_id > 0) {
                $stmtUpdateItem->execute([
                    $item['descricao'],
                    $item['quantidade'],
                    $item['valor'],
                    $item_id,
                    $id_orc
                ]);

                if ($stmtUpdateItem->rowCount() === 0) {
                    $stmtVerificarItem = $pdo->prepare("
                        SELECT id, status
                        FROM orcamentos_itens
                        WHERE id = ?
                          AND orcamento_id = ?
                        LIMIT 1
                    ");
                    $stmtVerificarItem->execute([
                        $item_id,
                        $id_orc
                    ]);
                    $itemBanco = $stmtVerificarItem->fetch(PDO::FETCH_ASSOC);
                    if (!$itemBanco) {
                        throw new Exception('Item do orçamento não encontrado.');
                    }
                    if ($itemBanco['status'] !== 'pendente') {
                        throw new Exception('Um item já aceito ou recusado não pode ser alterado.');
                    }
                }
            } else {
                $stmtInsertItem->execute([
                    $id_orc,
                    $item['descricao'],
                    $item['quantidade'],
                    $item['valor']
                ]);
            }
        }
        // ATUALIZA CONDIÇÕES COMERCIAIS
        // ====================================================

        $num_condicoes = (int)($_POST['num_condicoes'] ?? 1);
        if ($num_condicoes < 1) {
            throw new Exception(
                "A quantidade de condições deve ser pelo menos 1."
            );
        }

        if ($num_condicoes > 24) {
            throw new Exception(
                "A quantidade máxima de condições é 24."
            );
        }

        if ($total_novo <= 0) {
            throw new Exception(
                "O valor total do orçamento deve ser maior que zero."
            );
        }

        // Primeiro vencimento
        $data_primeiro_vencimento = trim(
            $_POST['data_primeiro_vencimento'] ?? ''
        );

        if ($data_primeiro_vencimento === '') {
            throw new Exception(
                "A data do primeiro vencimento é obrigatória."
            );
        }

        $dataPrimeiroVencimento = DateTimeImmutable::createFromFormat(
            'Y-m-d',
            $data_primeiro_vencimento
        );

        if (
            !$dataPrimeiroVencimento ||
            $dataPrimeiroVencimento->format('Y-m-d') !==
            $data_primeiro_vencimento
        ) {
            throw new Exception(
                "A data do primeiro vencimento é inválida."
            );
        }

        // ====================================================
        // FUNÇÃO PARA ADICIONAR MESES PRESERVANDO O DIA
        // ====================================================
        function adicionarMesesMantendoDia(
            DateTimeImmutable $data,
            int $meses
        ): DateTimeImmutable {
            $diaOriginal = (int)$data->format('d');
            $primeiroDiaMes = $data
                ->modify('first day of this month')
                ->modify("+{$meses} month");
            $ultimoDiaMes = (int)$primeiroDiaMes
                ->format('t');
            $diaFinal = min(
                $diaOriginal,
                $ultimoDiaMes
            );
            return $primeiroDiaMes->setDate(
                (int)$primeiroDiaMes->format('Y'),
                (int)$primeiroDiaMes->format('m'),
                $diaFinal
            );
        }
        // ====================================================
        // REMOVE AS CONDIÇÕES COMERCIAIS ANTIGAS
        // ====================================================
        $stmtDeleteCondicoes = $pdo->prepare("
    DELETE FROM orcamentos_condicoes
    WHERE orcamento_id = ?
");
        $stmtDeleteCondicoes->execute([$id_orc]);

        // ====================================================
        // CALCULA VALOR DAS CONDIÇÕES
        // ====================================================
        $valor_condicao_base = round(
            $total_novo / $num_condicoes,
            2
        );

        // ====================================================
        // INSERE NOVAS CONDIÇÕES
        // ====================================================
        $stmtCondicao = $pdo->prepare("
    INSERT INTO orcamentos_condicoes (
        orcamento_id,
        numero,
        valor,
        vencimento_previsto
    )
    VALUES (?, ?, ?, ?)
");
        for ($i = 1; $i <= $num_condicoes; $i++) {
            /*
     * A última condição recebe o ajuste dos centavos
     * para garantir que a soma seja exatamente igual
     * ao total do orçamento.
     */
            if ($i === $num_condicoes) {
                $valor_final = round(
                    $total_novo -
                        (
                            $valor_condicao_base *
                            ($num_condicoes - 1)
                        ),
                    2
                );
            } else {
                $valor_final = $valor_condicao_base;
            }
            $vencimento = adicionarMesesMantendoDia(
                $dataPrimeiroVencimento,
                $i - 1
            );
            $stmtCondicao->execute([
                $id_orc,
                $i,
                $valor_final,
                $vencimento->format('Y-m-d')
            ]);
        }
        // ====================================================
        // FINALIZA TRANSAÇÃO
        // ====================================================
        $pdo->commit();
        $sucesso = "Orçamento atualizado com sucesso!";
        header(
            "Refresh: 2; URL=visualizar_orcamento.php?id={$id_orc}"
        );
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $erro = "Erro ao atualizar: " .
            htmlspecialchars(
                $e->getMessage(),
                ENT_QUOTES,
                'UTF-8'
            );
        error_log(
            "ERRO EDIÇÃO ORÇAMENTO #{$id_orc}: " .
                $e->getMessage()
        );
    }
}

// ============================================================
// BUSCA PACIENTES
// ============================================================
$pacientes = $pdo->query("
    SELECT id, paciente
    FROM prontuarios
    ORDER BY paciente
")->fetchAll(PDO::FETCH_ASSOC);

$status_orcamento_texto = match ($status_orcamento) {
    'parcialmente_aceito' => 'Parcialmente aceito',
    'aceito' => 'Aceito',
    'parcialmente_recusado' => 'Parcialmente recusado',
    'recusado' => 'Recusado',
    default => 'Pendente'
};
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">
    <title>
        Editar Orçamento #<?= $id_orc ?> - Evoluqi
    </title>

    <link
        rel="stylesheet"
        href="css/global.css">

    <link
        rel="stylesheet"
        href="css/variables.css">

    <link
        rel="stylesheet"
        href="css/layout.css">

    <link
        rel="stylesheet"
        href="css/navbar.css">

    <link
        rel="stylesheet"
        href="css/editar_orcamento.css">

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link
        rel="icon"
        type="image/png"
        href="img/icon.PNG">
</head>

<body>
    <?php include 'navbar.php'; ?>
    <div class="container">
        <h1>
            ✏️ Editar Orçamento #<?= $id_orc ?>
        </h1>

        <?php if (!empty($erro)): ?>
            <div class="erro">
                <?= $erro ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($sucesso)): ?>
            <div class="sucesso">
                <?= $sucesso ?>
                <small>(Redirecionando...)</small>
            </div>
        <?php endif; ?>

        <form
            method="POST"
            id="formEditarOrcamento">
            <?= csrf_field() ?>
            <!-- PACIENTE -->
            <div class="form-group">
                <label>Paciente</label>
                <input
                    type="text"
                    value="<?= htmlspecialchars($orc['paciente']) ?>"
                    readonly>
                <input
                    type="hidden"
                    name="paciente_id"
                    value="<?= (int)$orc['paciente_id'] ?>">
            </div>
            <!-- VALIDADE -->
            <div class="form-group">
                <label>Data de Validade</label>
                <input
                    type="date"
                    name="validade"
                    value="<?= htmlspecialchars($orc['validade']) ?>"
                    required
                    <?= $orcamento_bloqueado ? 'disabled' : '' ?>>
            </div>
            <!-- ITENS -->
            <div class="form-group">
                <label>Itens do orçamento</label>
                <?php if ($orcamento_bloqueado): ?>
                    <div
                        class="aviso-itens-bloqueados"
                        style="
            margin-bottom:20px;
        ">
                        🔒 Este orçamento está
                        <strong><?= htmlspecialchars($status_orcamento_texto) ?></strong>
                        e não pode mais ser editado.
                        Para preservar o histórico da proposta,
                        alterações devem ser feitas em um novo orçamento.
                    </div>

                <?php endif; ?>
                <div
                    id="itens-container">
                    <?php foreach ($itens_existentes as $idx => $item): ?>
                        <div class="item-row">
                            <input
                                type="hidden"
                                name="item_id[]"
                                value="<?= (int)$item['id'] ?>">
                            <div>
                                <input
                                    type="text"
                                    name="descricao[]"
                                    value="<?= htmlspecialchars($item['descricao']) ?>"
                                    required
                                    <?= $orcamento_bloqueado ? 'disabled' : '' ?>>
                            </div>

                            <div>
                                <input
                                    type="number"
                                    name="quantidade[]"
                                    value="<?= (int)$item['quantidade'] ?>"
                                    min="1"
                                    <?= $orcamento_bloqueado ? 'disabled' : '' ?>>
                            </div>

                            <div>
                                <input
                                    type="number"
                                    name="valor[]"
                                    step="0.01"
                                    min="0"
                                    value="<?= number_format((float)$item['valor_unitario'], 2, '.', '') ?>"
                                    placeholder="0.00"
                                    required
                                    class="item-valor"
                                    <?= $orcamento_bloqueado ? 'disabled' : '' ?>>
                            </div>
                            <?php if (
                                !$orcamento_bloqueado &&
                                ($item['status'] ?? 'pendente') === 'pendente'
                            ): ?>

                                <div class="item-acao">

                                    <button
                                        type="button"
                                        class="btn btn-excluir-item"
                                        onclick="excluirItemOrcamento(<?= (int)$item['id'] ?>)">
                                        🗑 Excluir
                                    </button>

                                </div>

                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!$orcamento_bloqueado): ?>
                    <button
                        type="button"
                        class="btn-add-item"
                        onclick="adicionarItem()">
                        + Adicionar Item
                    </button>
                <?php endif; ?>
            </div>

            <!-- CONDIÇÕES COMERCIAIS -->
            <div class="form-group secao-parcelamento">
                <label>Condições comerciais</label>
                <div
                    style="
            margin-bottom:15px;
            color:#666;
            font-size:14px;
        ">
                    Defina a quantidade de condições e a data prevista
                    para o primeiro vencimento.
                </div>

                <div
                    style="
            display:flex;
            gap:15px;
            flex-wrap:wrap;
            align-items:flex-end;
        ">

                    <div>
                        <label
                            for="num_condicoes"
                            style="display:block;">
                            Quantidade de condições
                        </label>

                        <select
                            name="num_condicoes"
                            id="num_condicoes"
                            required
                            <?= $orcamento_bloqueado ? 'disabled' : '' ?>>
                            <?php for ($i = 1; $i <= 24; $i++): ?>
                                <option
                                    value="<?= $i ?>"
                                    <?= $i === count($condicoes_existentes)
                                        ? 'selected'
                                        : '' ?>>
                                    <?= $i ?>x sem juros
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div>
                        <label
                            for="data_primeiro_vencimento"
                            style="display:block;">
                            Primeiro vencimento
                        </label>

                        <input
                            type="date"
                            name="data_primeiro_vencimento"
                            id="data_primeiro_vencimento"
                            <?= $orcamento_bloqueado ? 'disabled' : '' ?>
                            value="<?=
                                    !empty($condicoes_existentes)
                                        ? htmlspecialchars(
                                            $condicoes_existentes[0]['vencimento_previsto']
                                        )
                                        : date('Y-m-d')
                                    ?>"
                            required>
                    </div>
                </div>
                <div
                    id="preview_condicoes"
                    class="preview-parcelas"></div>
            </div>

            <!-- OBSERVAÇÕES -->
            <div class="form-group">
                <label>
                    Observações (opcional)
                </label>

                <textarea
                    name="observacoes"
                    rows="3"
                    placeholder="Condições, descontos, etc..."
                    <?= $orcamento_bloqueado ? 'disabled' : '' ?>><?= htmlspecialchars($orc['observacoes'] ?? '') ?></textarea>
            </div>

            <!-- AÇÕES -->
            <?php if (!$orcamento_bloqueado): ?>
                <button
                    type="submit"
                    class="btn">
                    💾 Salvar Alterações
                </button>
            <?php endif; ?>
            <a
                href="visualizar_orcamento.php?id=<?= $id_orc ?>"
                class="btn-cancelar">
                Cancelar
            </a>
        </form>
    </div>
    <script>
        function adicionarItem() {
            const container =
                document.getElementById('itens-container');
            const div =
                document.createElement('div');
            div.className = 'item-row';
            div.innerHTML = `
    <input
        type="hidden"
        name="item_id[]"
        value="0"
    >

    <div class="item-campo item-descricao">
        <input
            type="text"
            name="descricao[]"
            placeholder="Ex: Restauração"
            required
        >
    </div>

    <div class="item-campo item-quantidade">
        <input
            type="number"
            name="quantidade[]"
            value="1"
            min="1"
            required
        >
    </div>

    <div class="item-campo item-valor">
        <input
            type="number"
            name="valor[]"
            step="0.01"
            min="0"
            placeholder="0.00"
            required
            class="item-valor"
        >
    </div>

    <button
        type="button"
        class="btn-excluir-item"
        onclick="removerItemNovo(this)"
    >
        <i class="fa-solid fa-trash"></i>
        Excluir
    </button>
`;
            container.appendChild(div);
            div
                .querySelector('.item-valor')
                .addEventListener(
                    'input',
                    calcularPreviewCondicoes
                );

            div
                .querySelector('[name="quantidade[]"]')
                .addEventListener(
                    'input',
                    calcularPreviewCondicoes
                );
            calcularPreviewCondicoes();
        }

        function calcularPreviewCondicoes() {
            const selectCondicoes =
                document.getElementById('num_condicoes');
            const dataInput =
                document.getElementById(
                    'data_primeiro_vencimento'
                );

            const preview =
                document.getElementById(
                    'preview_condicoes'
                );

            if (!selectCondicoes || !dataInput || !preview) {
                return;
            }
            preview.classList.remove('preview-vazio');
            const qtdCondicoes =
                parseInt(selectCondicoes.value) || 1;
            const dataPrimeiroVencimento =
                dataInput.value;

            let total = 0;
            document
                .querySelectorAll('.item-row')
                .forEach(row => {

                    const qtdInput =
                        row.querySelector(
                            '[name="quantidade[]"]'
                        );

                    const valInput =
                        row.querySelector(
                            '[name="valor[]"]'
                        );

                    const qtd =
                        parseInt(qtdInput?.value) || 0;
                    const val =
                        parseFloat(valInput?.value) || 0;
                    total += qtd * val;
                });

            total = Math.round(
                (total + Number.EPSILON) * 100
            ) / 100;
            if (total <= 0) {
                preview.innerHTML =
                    'Adicione itens para calcular as condições.';
                preview.classList.add('preview-vazio');
                return;
            }

            if (!dataPrimeiroVencimento) {
                preview.innerHTML =
                    'Informe o primeiro vencimento.';
                preview.classList.add('preview-vazio');
                return;
            }

            const valorBase =
                Math.round(
                    (
                        total / qtdCondicoes +
                        Number.EPSILON
                    ) * 100
                ) / 100;

            const valorUltima =
                Math.round(
                    (
                        total -
                        (
                            valorBase *
                            (qtdCondicoes - 1)
                        )
                    ) * 100
                ) / 100;

            let html = '';

            for (
                let i = 1; i <= qtdCondicoes; i++
            ) {

                const valor =
                    i === qtdCondicoes ?
                    valorUltima :
                    valorBase;

                const data =
                    adicionarMesesMantendoDiaJS(
                        dataPrimeiroVencimento,
                        i - 1
                    );

                html += `
    <div class="preview-condicao">
        <span>
            ${i}ª condição
        </span>

        <span>
            ${formatarData(data)}
        </span>

        <strong>
            R$ ${formatarMoeda(valor)}
        </strong>
    </div>
`;
            }

            html += `
    <div class="preview-total">
        <strong>
            Total: R$ ${formatarMoeda(total)}
        </strong>
    </div>
`;

            preview.innerHTML = html;
        }

        function removerItemNovo(botao) {
            const item = botao.closest('.item-row');

            if (!item) {
                return;
            }

            item.remove();

            calcularPreviewCondicoes();
        }

        function excluirItemOrcamento(itemId) {
            if (!confirm('Excluir este item do orçamento?')) {
                return;
            }
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'excluir_item_orcamento.php';
            form.innerHTML = `
        <?= csrf_field() ?>

        <input
            type="hidden"
            name="item_id"
            value="${itemId}">

        <input
            type="hidden"
            name="orcamento_id"
            value="<?= (int)$id_orc ?>">
    `;

            document.body.appendChild(form);

            form.submit();
        }

        function adicionarMesesMantendoDiaJS(
            dataString,
            meses
        ) {

            const partes =
                dataString.split('-');
            const ano =
                parseInt(partes[0]);
            const mes =
                parseInt(partes[1]) - 1;
            const diaOriginal =
                parseInt(partes[2]);

            const data =
                new Date(
                    Date.UTC(
                        ano,
                        mes,
                        1
                    )
                );

            data.setUTCMonth(
                data.getUTCMonth() + meses
            );

            const ultimoDiaMes =
                new Date(
                    Date.UTC(
                        data.getUTCFullYear(),
                        data.getUTCMonth() + 1,
                        0
                    )
                ).getUTCDate();

            const diaFinal =
                Math.min(
                    diaOriginal,
                    ultimoDiaMes
                );
            data.setUTCDate(diaFinal);
            return data;
        }

        function formatarData(data) {
            const dia =
                String(
                    data.getUTCDate()
                ).padStart(2, '0');

            const mes =
                String(
                    data.getUTCMonth() + 1
                ).padStart(2, '0');

            const ano =
                data.getUTCFullYear();
            return `${dia}/${mes}/${ano}`;
        }

        function formatarMoeda(valor) {
            return valor
                .toFixed(2)
                .replace('.', ',');
        }

        document.addEventListener(
            'DOMContentLoaded',
            () => {

                document
                    .querySelectorAll('.item-valor')
                    .forEach(input => {
                        input.addEventListener(
                            'input',
                            calcularPreviewCondicoes
                        );
                    });

                document
                    .querySelectorAll(
                        '[name="quantidade[]"]'
                    )
                    .forEach(input => {
                        input.addEventListener(
                            'input',
                            calcularPreviewCondicoes
                        );
                    });

                document
                    .getElementById('num_condicoes')
                    ?.addEventListener(
                        'change',
                        calcularPreviewCondicoes
                    );
                document
                    .getElementById(
                        'data_primeiro_vencimento'
                    )
                    ?.addEventListener(
                        'change',
                        calcularPreviewCondicoes
                    );
                calcularPreviewCondicoes();
            }
        );
    </script>
</body>

</html>