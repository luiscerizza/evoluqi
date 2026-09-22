<?php

require_once 'config/auth.php';
require_once 'conexao/conexao.php';

exigirLogin();

/*
|--------------------------------------------------------------------------
| 1. Validar ID do orçamento
|--------------------------------------------------------------------------
*/

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    exit('Orçamento inválido.');
}

/*
|--------------------------------------------------------------------------
| 2. Buscar orçamento
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        o.*,
        p.paciente,
        p.cpf,
        p.telefone,
        p.email

    FROM orcamentos o

    INNER JOIN prontuarios p
        ON o.paciente_id = p.id

    WHERE o.id = ?

    LIMIT 1
");

$stmt->execute([$id]);

$orcamento = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$orcamento) {
    http_response_code(404);
    exit('Orçamento não encontrado.');
}

if ((int)$orcamento['arquivado'] === 1) {
    http_response_code(403);
?>
    <!DOCTYPE html>
    <html lang="pt-BR">

    <head>
        <meta charset="UTF-8">
        <meta
            name="viewport"
            content="width=device-width, initial-scale=1.0">

        <title>Orçamento indisponível</title>

        <style>
            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
                background: #f5f6fa;
                font-family: Arial, Helvetica, sans-serif;
                color: #333;
            }

            .bloqueio {
                width: 100%;
                max-width: 520px;
                padding: 40px 35px;
                background: #fff;
                border-radius: 12px;
                text-align: center;
                box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            }

            .icone {
                width: 64px;
                height: 64px;
                margin: 0 auto 20px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 50%;
                background: #f1f1f1;
                font-size: 28px;
            }

            h1 {
                margin: 0 0 12px;
                font-size: 22px;
            }

            p {
                margin: 0 0 25px;
                color: #666;
                line-height: 1.6;
                font-size: 14px;
            }

            .btn-voltar {
                display: inline-block;
                padding: 10px 18px;
                border-radius: 6px;
                background: #333;
                color: #fff;
                text-decoration: none;
                font-size: 14px;
            }

            .btn-voltar:hover {
                opacity: 0.9;
            }
        </style>
    </head>

    <body>

        <div class="bloqueio">

            <div class="icone">
                🔒
            </div>

            <h1>
                Orçamento indisponível para impressão
            </h1>

            <p>
                Este orçamento está arquivado e, por isso,
                não pode mais ser impresso ou gerar um PDF.
                O registro permanece armazenado apenas para
                preservação do histórico da proposta.
            </p>

            <a
                href="orcamento.php"
                class="btn-voltar">
                ← Voltar para o orçamento
            </a>

        </div>

    </body>

    </html>
<?php
    exit;
}

/*
|--------------------------------------------------------------------------
| 3. Buscar itens
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        descricao,
        quantidade,
        valor_unitario,
        status

    FROM orcamentos_itens

    WHERE orcamento_id = ?

    ORDER BY id ASC
");

$stmt->execute([$id]);

$itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| 4. Buscar condições comerciais
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

$stmt->execute([$id]);

$condicoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| 5. Calcular total dos itens
|--------------------------------------------------------------------------
*/

$total_orcamento = 0;

foreach ($itens as $item) {
    $total_orcamento +=
        (float)$item['quantidade'] *
        (float)$item['valor_unitario'];
}

$total_orcamento = round($total_orcamento, 2);

/*
|--------------------------------------------------------------------------
| 6. Preparar status
|--------------------------------------------------------------------------
*/

$status_texto = [
    'pendente' => 'Pendente',
    'parcialmente_aceito' => 'Parcialmente aceito',
    'aceito' => 'Aceito',
    'parcialmente_recusado' => 'Parcialmente recusado',
    'recusado' => 'Recusado'
];

$status_exibicao =
    $status_texto[$orcamento['status']]
    ?? $orcamento['status'];

$status = $orcamento['status'] ?? 'pendente';
$decisao_registrada = $status !== 'pendente';
$mostrar_validade = !$decisao_registrada;

$status_icones = [
    'pendente' => '⏳',
    'parcialmente_aceito' => '◐',
    'aceito' => '✓',
    'parcialmente_recusado' => '◐',
    'recusado' => '×'
];

$status_icone = $status_icones[$status] ?? '•';

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>
        Orçamento #<?= (int)$orcamento['id'] ?>
    </title>

    <link rel="stylesheet" href="css/imprimir_orcamento.css">
</head>

<body>

    <div class="acoes">

        <a
            href="visualizar_orcamento.php?id=<?= (int)$orcamento['id'] ?>"
            class="btn btn-voltar">
            ← Voltar
        </a>

        <button
            type="button"
            class="btn btn-imprimir"
            onclick="window.print()">
            🖨️ Imprimir / Salvar PDF
        </button>

    </div>

    <main class="documento">

        <header class="cabecalho">

            <div class="empresa">

                <div class="marca">
                    <img
                        src="img/logo.png"
                        alt="Evoluqi"
                        class="logo">

                    <div class="marca-divisor"></div>

                    <div class="assinatura">
                        <span>Mais organização.</span>
                        <strong>Mais tempo para o que importa:</strong>
                        <span>seus pacientes.</span>
                    </div>
                </div>

            </div>

            <div class="titulo-documento">
                <span class="tipo-documento">DOCUMENTO COMERCIAL</span>
                <h2>ORÇAMENTO</h2>

                <div class="meta-documento">
                    <span>Nº <?= (int)$orcamento['id'] ?></span>
                    <span>Emitido em <?= date('d/m/Y', strtotime($orcamento['data_criacao'])) ?></span>
                    <?php if ($mostrar_validade): ?>
                        <span>Válido até <?= date('d/m/Y', strtotime($orcamento['validade'])) ?></span>
                    <?php endif; ?>
                </div>

                <div class="status status-<?= htmlspecialchars($status) ?>">
                    <span class="status-icone" aria-hidden="true"><?= $status_icone ?></span>
                    <?= htmlspecialchars($status_exibicao) ?>
                </div>
            </div>

        </header>


        <section class="secao">

            <h3 class="secao-titulo">
                <span class="secao-icone" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <circle cx="12" cy="8" r="3.2"></circle>
                        <path d="M5 20c.8-3.7 3.1-5.5 7-5.5s6.2 1.8 7 5.5"></path>
                    </svg>
                </span>
                Dados do paciente
            </h3>

            <div class="dados-grid">

                <div class="dado">

                    <strong>Paciente</strong>

                    <?= htmlspecialchars($orcamento['paciente']) ?>

                </div>

                <div class="dado">

                    <strong>CPF</strong>

                    <?= htmlspecialchars($orcamento['cpf'] ?? '') ?>

                </div>

                <div class="dado">

                    <strong>Telefone</strong>

                    <?= htmlspecialchars($orcamento['telefone'] ?? '') ?>

                </div>

                <div class="dado">

                    <strong>E-mail</strong>

                    <?= htmlspecialchars($orcamento['email'] ?? '') ?>

                </div>

            </div>

        </section>


        <section class="secao">

            <h3 class="secao-titulo">
                <span class="secao-icone" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <rect x="5" y="3.5" width="14" height="17" rx="2"></rect>
                        <path d="M8 8h8M8 12h8M8 16h5"></path>
                    </svg>
                </span>
                Itens da proposta
            </h3>

            <table>

                <thead>

                    <tr>

                        <th>
                            Descrição
                        </th>

                        <th class="col-quantidade">
                            Qtd.
                        </th>

                        <th class="col-valor">
                            Valor unitário
                        </th>

                        <th class="col-valor">
                            Subtotal
                        </th>

                    </tr>

                </thead>

                <tbody>

                    <?php foreach ($itens as $item): ?>

                        <?php
                        $subtotal =
                            (float)$item['quantidade'] *
                            (float)$item['valor_unitario'];
                        ?>

                        <tr>

                            <td>
                                <?= htmlspecialchars(
                                    $item['descricao']
                                ) ?>
                            </td>

                            <td class="col-quantidade">
                                <?= (int)$item['quantidade'] ?>
                            </td>

                            <td class="col-valor">
                                R$
                                <?= number_format(
                                    (float)$item['valor_unitario'],
                                    2,
                                    ',',
                                    '.'
                                ) ?>
                            </td>

                            <td class="col-valor">
                                R$
                                <?= number_format(
                                    $subtotal,
                                    2,
                                    ',',
                                    '.'
                                ) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>


            <div class="total-container">

                <div class="total">

                    Total da proposta:
                    R$
                    <?= number_format(
                        $total_orcamento,
                        2,
                        ',',
                        '.'
                    ) ?>

                </div>

            </div>

        </section>


        <section class="secao">

            <h3 class="secao-titulo">
                <span class="secao-icone" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <rect x="3.5" y="6" width="17" height="12" rx="2"></rect>
                        <path d="M3.5 10h17M7 14h3"></path>
                    </svg>
                </span>
                Condições comerciais
            </h3>

            <?php if (!empty($condicoes)): ?>

                <table>

                    <thead>

                        <tr>

                            <th>
                                Condição
                            </th>

                            <th>
                                Vencimento previsto
                            </th>

                            <th class="col-valor">
                                Valor
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($condicoes as $condicao): ?>

                            <tr>

                                <td>
                                    <?= (int)$condicao['numero'] ?>ª condição
                                </td>

                                <td>
                                    <?= date(
                                        'd/m/Y',
                                        strtotime(
                                            $condicao['vencimento_previsto']
                                        )
                                    ) ?>
                                </td>

                                <td class="col-valor">
                                    R$
                                    <?= number_format(
                                        (float)$condicao['valor'],
                                        2,
                                        ',',
                                        '.'
                                    ) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            <?php else: ?>

                <p>
                    Nenhuma condição comercial cadastrada.
                </p>

            <?php endif; ?>

        </section>


        <?php if (!empty($orcamento['observacoes'])): ?>

            <section class="secao">

                <h3 class="secao-titulo">
                    <span class="secao-icone" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <path d="M6 4.5h12a1.5 1.5 0 0 1 1.5 1.5v12A1.5 1.5 0 0 1 18 19.5H6A1.5 1.5 0 0 1 4.5 18V6A1.5 1.5 0 0 1 6 4.5Z"></path>
                            <path d="M8 9h8M8 12h8M8 15h5"></path>
                        </svg>
                    </span>
                    Observações
                </h3>

                <div class="observacoes">

                    <?= nl2br(
                        htmlspecialchars(
                            $orcamento['observacoes']
                        )
                    ) ?>

                </div>

            </section>

        <?php endif; ?>




    </main>


    <script>
        function imprimirOrcamento() {
            window.print();
        }
    </script>

</body>

</html>