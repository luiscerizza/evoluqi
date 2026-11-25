<?php

require_once 'config/auth.php';
require_once 'config/csrf.php';
require_once 'conexao/conexao.php';

exigirLogin();

$procedimento_id = (int)($_GET['procedimento_id'] ?? 0);

if ($procedimento_id <= 0) {
    die('Procedimento inválido.');
}

/*
|--------------------------------------------------------------------------
| Buscar procedimento
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.paciente_id,
        p.titulo,
        p.descricao,
        p.valor_materiais,
        p.valor_mao_obra,
        p.valor_final,
        p.data_procedimento,
        p.orcamento_id,
        p.plano_item_id,
        p.arquivado,
        pr.paciente
    FROM procedimentos p
    INNER JOIN prontuarios pr
        ON pr.id = p.paciente_id
    WHERE p.id = ?
    LIMIT 1
");

$stmt->execute([$procedimento_id]);

$procedimento = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$procedimento) {
    die('Procedimento não encontrado.');
}

if ((int)$procedimento['arquivado'] === 1) {
    die('Este procedimento está arquivado e não pode gerar cobrança.');
}

/*
|--------------------------------------------------------------------------
| Validar valor
|--------------------------------------------------------------------------
*/

$valor_final = round((float)$procedimento['valor_final'], 2);

if ($valor_final <= 0) {
    die('Este procedimento não possui um valor final válido para cobrança.');
}

/*
|--------------------------------------------------------------------------
| Verificar se já existe cobrança
|--------------------------------------------------------------------------
|
| A cobrança agora é vinculada diretamente ao procedimento
| através da tabela parcelas.
|
*/

$stmt = $pdo->prepare("
    SELECT
        COUNT(*)
    FROM parcelas
    WHERE procedimento_id = ?
");

$stmt->execute([$procedimento_id]);

$quantidade_cobrancas = (int)$stmt->fetchColumn();

if ($quantidade_cobrancas > 0) {
    die('Este procedimento já possui uma cobrança cadastrada.');
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| Data padrão
|--------------------------------------------------------------------------
*/

$data_minima = date('Y-m-d');

$data_padrao = date(
    'Y-m-d',
    strtotime('+1 month')
);

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>Gerar cobrança | Dentech</title>

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
        href="css/gerar_cobranca.css">

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

    <main class="cobranca-container">

        <header class="page-header">

            <span class="page-kicker">
                FINANCEIRO
            </span>

            <h1>
                Gerar cobrança
            </h1>

            <p>
                Defina como o paciente irá pagar este procedimento.
            </p>

        </header>

        <form
            method="POST"
            action="salvar_cobranca_procedimento.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrf_token) ?>">

            <input
                type="hidden"
                name="procedimento_id"
                value="<?= (int)$procedimento['id'] ?>">

            <div class="cobranca-grid">

                <!-- =====================================================
                 PROCEDIMENTO
            ====================================================== -->

                <section class="card">

                    <h2>
                        Dados do procedimento
                    </h2>

                    <div class="info-item">

                        <span class="info-label">
                            Paciente
                        </span>

                        <div class="info-value">
                            <?= htmlspecialchars($procedimento['paciente']) ?>
                        </div>

                    </div>

                    <div class="info-item">

                        <span class="info-label">
                            Procedimento
                        </span>

                        <div class="info-value">
                            <?= htmlspecialchars($procedimento['titulo']) ?>
                        </div>

                    </div>

                    <div class="info-item">

                        <span class="info-label">
                            Data do procedimento
                        </span>

                        <div class="info-value">

                            <?= date(
                                'd/m/Y',
                                strtotime($procedimento['data_procedimento'])
                            ) ?>

                        </div>

                    </div>

                    <div class="valor-total">

                        <span>
                            Valor final cobrado
                        </span>

                        <strong>
                            R$
                            <?= number_format(
                                $valor_final,
                                2,
                                ',',
                                '.'
                            ) ?>
                        </strong>

                    </div>

                </section>


                <!-- =====================================================
                 CONFIGURAÇÃO
            ====================================================== -->

                <section class="card">

                    <h2>
                        Condições de pagamento
                    </h2>

                    <div class="form-group">

                        <label for="forma_pagamento">
                            Forma de pagamento
                        </label>

                        <select
                            id="forma_pagamento"
                            name="forma_pagamento"
                            required>

                            <option value="">
                                Selecione
                            </option>

                            <option value="Dinheiro">
                                Dinheiro
                            </option>

                            <option value="PIX">
                                PIX
                            </option>

                            <option value="Cartão de débito">
                                Cartão de débito
                            </option>

                            <option value="Cartão de crédito">
                                Cartão de crédito
                            </option>

                            <option value="Boleto">
                                Boleto
                            </option>

                            <option value="Transferência bancária">
                                Transferência bancária
                            </option>

                            <option value="Cheque">
                                Cheque
                            </option>

                            <option value="Outro">
                                Outro
                            </option>

                        </select>

                    </div>


                    <div class="form-group">

                        <label for="quantidade_parcelas">
                            Quantidade de parcelas
                        </label>

                        <select
                            id="quantidade_parcelas"
                            name="quantidade_parcelas"
                            required>

                            <?php for ($i = 1; $i <= 60; $i++): ?>

                                <option value="<?= $i ?>">
                                    <?= $i === 1
                                        ? 'À vista'
                                        : $i . 'x' ?>
                                </option>

                            <?php endfor; ?>

                        </select>

                        <div class="form-help">
                            Máximo de 60 parcelas.
                        </div>

                    </div>


                    <div class="form-group">

                        <label for="primeiro_vencimento">
                            Primeiro vencimento
                        </label>

                        <input
                            type="date"
                            id="primeiro_vencimento"
                            name="primeiro_vencimento"
                            value="<?= htmlspecialchars($data_padrao) ?>"
                            min="<?= htmlspecialchars($data_minima) ?>"
                            required>

                        <div class="form-help">
                            As próximas parcelas serão geradas mensalmente.
                        </div>

                    </div>


                    <div class="resumo">

                        <div class="resumo-linha">

                            <span>
                                Valor total
                            </span>

                            <strong>
                                R$
                                <?= number_format(
                                    $valor_final,
                                    2,
                                    ',',
                                    '.'
                                ) ?>
                            </strong>

                        </div>

                        <div
                            class="resumo-linha"
                            id="resumo-parcelas">

                            <span>
                                Parcelamento
                            </span>

                            <strong>
                                À vista
                            </strong>

                        </div>

                    </div>

                </section>

            </div>


            <div class="acoes">

                <a
                    href="visualizar_prontuario.php?id=<?= (int)$procedimento['paciente_id'] ?>"
                    class="btn btn-cancelar">

                    Cancelar

                </a>

                <button
                    type="submit"
                    class="btn btn-primary">

                    <i class="fa-solid fa-file-invoice-dollar"></i>

                    Gerar cobrança

                </button>

            </div>

        </form>

    </main>


    <script>
        const valorTotal =
            <?= json_encode($valor_final) ?>;

        const selectParcelas =
            document.getElementById('quantidade_parcelas');

        const resumoParcelas =
            document.getElementById('resumo-parcelas');

        function atualizarResumo() {

            const quantidade =
                parseInt(selectParcelas.value, 10);

            if (quantidade === 1) {

                resumoParcelas.innerHTML = `
                <span>Pagamento</span>
                <strong>À vista</strong>
            `;

                return;
            }

            const valorParcela =
                valorTotal / quantidade;

            resumoParcelas.innerHTML = `
            <span>Parcelamento</span>
            <strong>
                ${quantidade}x de R$ ${valorParcela
                    .toFixed(2)
                    .replace('.', ',')}
            </strong>
        `;

        }

        selectParcelas.addEventListener(
            'change',
            atualizarResumo
        );

        atualizarResumo();
    </script>

</body>

</html>