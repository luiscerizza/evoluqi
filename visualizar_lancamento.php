<?php

require_once 'config/auth.php';
require_once 'config/csrf.php';
require_once 'conexao/conexao.php';

exigirLogin();

/*
|--------------------------------------------------------------------------
| Funções auxiliares
|--------------------------------------------------------------------------
*/

function escapar($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function moedaBR($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function dataBR(?string $data): string
{
    if (empty($data)) {
        return '-';
    }

    $timestamp = strtotime($data);

    return $timestamp ? date('d/m/Y', $timestamp) : '-';
}

function textoTipo(string $tipo): string
{
    return $tipo === 'receita' ? 'Receita' : 'Despesa';
}

function classeTipo(string $tipo): string
{
    return $tipo === 'receita' ? 'tipo-receita' : 'tipo-despesa';
}

function textoStatus(string $status): string
{
    return $status === 'pago' ? 'Pago' : 'Pendente';
}

function classeStatus(string $status): string
{
    return $status === 'pago' ? 'status-pago' : 'status-pendente';
}

/*
|--------------------------------------------------------------------------
| ID DO LANÇAMENTO E DA PARCELA
|--------------------------------------------------------------------------
*/

$lancamento_id = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

$parcela_id = isset($_GET['parcela_id'])
    ? (int) $_GET['parcela_id']
    : 0;


if ($lancamento_id <= 0) {

    header(
        'Location: financeiro.php?erro=' .
            urlencode('Lançamento inválido.')
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| BUSCAR LANÇAMENTO
|--------------------------------------------------------------------------
|
| O lançamento financeiro é o registro-pai.
|
*/

$stmt = $pdo->prepare("
    SELECT
        l.id,
        l.tipo,
        l.categoria,
        l.descricao,
        l.data,
        l.forma_pagamento,
        l.valor,
        l.parcelas,
        l.status,
        l.data_pagamento,
        l.observacoes,
        l.orcamento_id,
        l.parcela_id,
        l.procedimento_id,
        l.created_at
    FROM lancamentos_financeiros l
    WHERE l.id = ?
    LIMIT 1
");

$stmt->execute([$lancamento_id]);

$lancamento = $stmt->fetch(PDO::FETCH_ASSOC);


if (!$lancamento) {

    header(
        'Location: financeiro.php?erro=' .
            urlencode('Lançamento não encontrado.')
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| IDENTIFICAR ORIGEM
|--------------------------------------------------------------------------
*/

if (!empty($lancamento['procedimento_id'])) {

    $origem = 'procedimento';
} elseif (!empty($lancamento['parcela_id'])) {

    $origem = 'parcela';
} elseif (!empty($lancamento['orcamento_id'])) {

    $origem = 'orcamento';
} else {

    $origem = 'manual';
}


/*
|--------------------------------------------------------------------------
| LANÇAMENTO MANUAL PARCELADO
|--------------------------------------------------------------------------
|
| Quando `parcela_id` foi informado pela tela Financeiro,
| precisamos carregar a parcela específica.
|
*/

$parcela = null;


if ($parcela_id && $origem === 'manual') {

    $stmtParcela = $pdo->prepare("
        SELECT
            lp.id,
            lp.lancamento_id,
            lp.numero_parcela,
            lp.valor,
            lp.vencimento,
            lp.status,
            lp.data_pagamento,
            lp.created_at

        FROM lancamentos_parcelas lp

        WHERE lp.id = ?
          AND lp.lancamento_id = ?

        LIMIT 1
    ");

    $stmtParcela->execute([
        $parcela_id,
        $lancamento_id
    ]);

    $parcela = $stmtParcela->fetch(PDO::FETCH_ASSOC);


    /*
    |--------------------------------------------------------------------------
    | GARANTIR QUE A PARCELA PERTENCE AO LANÇAMENTO
    |--------------------------------------------------------------------------
    */

    if (!$parcela) {

        header(
            'Location: financeiro.php?erro=' .
                urlencode('Parcela não encontrada.')
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | SUBSTITUIR OS DADOS FINANCEIROS PELOS DADOS DA PARCELA
    |--------------------------------------------------------------------------
    |
    | Mantemos os dados gerais do lançamento-pai, mas os campos
    | financeiros passam a representar a parcela selecionada.
    |
    */

    $lancamento['valor'] =
        $parcela['valor'];

    $lancamento['status'] =
        strtolower(trim((string)$parcela['status']));


    $lancamento['data_pagamento'] =
        $parcela['data_pagamento'];


    /*
    |--------------------------------------------------------------------------
    | A DATA EXIBIDA PARA PARCELA
    |--------------------------------------------------------------------------
    |
    | Paga:
    |     data do pagamento.
    |
    | Pendente/atrasada:
    |     vencimento.
    |
    */

    if (
        $lancamento['status'] === 'paga'
        && !empty($parcela['data_pagamento'])
    ) {

        $lancamento['data'] =
            $parcela['data_pagamento'];
    } else {

        $lancamento['data'] =
            $parcela['vencimento'];
    }


    /*
    |--------------------------------------------------------------------------
    | NORMALIZAR STATUS
    |--------------------------------------------------------------------------
    |
    | A tabela `lancamentos_parcelas` usa:
    |     paga / pendente / atrasada
    |
    | A interface financeira usa:
    |     pago / pendente / atrasada
    |
    */

    if ($lancamento['status'] === 'paga') {

        $lancamento['status'] = 'pago';
    } elseif (
        $lancamento['status'] === 'pendente'
        && !empty($parcela['vencimento'])
        && $parcela['vencimento'] < date('Y-m-d')
    ) {

        $lancamento['status'] = 'atrasada';
    }
}


/*
|--------------------------------------------------------------------------
| BLOQUEAR EDIÇÃO/PAGAMENTO DE REGISTROS VINCULADOS
|--------------------------------------------------------------------------
|
| Parcelas de lançamentos manuais terão seu próprio fluxo.
|
*/

if ($origem !== 'manual') {

    header(
        'Location: financeiro.php?erro=' .
            urlencode(
                'Este registro possui origem vinculada e deve ser acessado pelo fluxo correspondente.'
            )
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| DADOS DE EXIBIÇÃO DA PARCELA
|--------------------------------------------------------------------------
*/

$eh_parcela_manual =
    $parcela !== null;


$numero_parcela =
    $eh_parcela_manual
    ? (int)$parcela['numero_parcela']
    : null;


$total_parcelas =
    $eh_parcela_manual
    ? (int)$lancamento['parcelas']
    : null;


$vencimento_parcela =
    $eh_parcela_manual
    ? $parcela['vencimento']
    : null;

$csrf_token = $_SESSION['csrf_token'] ?? '';

?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Visualizar lançamento #<?= (int)$lancamento['id'] ?> | Dentech
    </title>

    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/variables.css">
    <link rel="stylesheet" href="css/layout.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/visualizar_lancamento.css">

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="icon" type="image/png" href="img/icon.PNG">

</head>

<body>

    <?php include 'navbar.php'; ?>

    <main class="container">

        <div class="page-header">

            <div>
                <div class="breadcrumb">
                    <a href="financeiro.php">Financeiro</a>
                    <span class="breadcrumb-separator">/</span>
                    <span>Visualização</span>
                </div>

                <h1>
                    <?= $eh_parcela_manual
                        ? 'Visualizar parcela'
                        : 'Visualizar lançamento' ?>
                </h1>

                <p>
                    <?= $eh_parcela_manual
                        ? 'Consulte os detalhes desta parcela do lançamento financeiro.'
                        : 'Consulte os detalhes desta movimentação financeira.' ?>
                </p>
            </div>

            <div class="header-actions">

                <a
                    href="<?= $eh_parcela_manual
                                ? 'visualizar_lancamento.php?id=' . (int)$lancamento['id']
                                : 'financeiro.php' ?>"
                    class="btn btn-voltar">
                    <i class="fa-solid fa-arrow-left"></i>
                    Voltar
                </a>

                <?php if (!$eh_parcela_manual): ?>

                    <a
                        href="editar_lancamento.php?id=<?= (int)$lancamento['id'] ?>"
                        class="btn btn-editar">
                        <i class="fa-solid fa-pen"></i>
                        Editar
                    </a>

                <?php endif; ?>

                <?php if (!$eh_parcela_manual && $lancamento['status'] === 'pendente'): ?>

                    <form
                        method="POST"
                        action="pagar_lancamento.php"
                        style="margin:0;"
                        onsubmit="return confirm('Confirmar pagamento deste lançamento?');">

                        <input
                            type="hidden"
                            name="lancamento_id"
                            value="<?= (int)$lancamento['id'] ?>">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= escapar($csrf_token) ?>">

                        <button type="submit" class="btn btn-pagar">
                            <i class="fa-solid fa-check"></i>
                            Pagar
                        </button>

                    </form>

                <?php endif; ?>

            </div>

        </div>

        <section class="card">

            <div class="card-header">

                <div>
                    <h2>
                        <?= $eh_parcela_manual
                            ? 'Resumo da parcela'
                            : 'Resumo do lançamento' ?>
                    </h2>

                    <p>
                        Lançamento #<?= (int)$lancamento['id'] ?>

                        <?php if ($eh_parcela_manual): ?>
                            · Parcela <?= $numero_parcela ?>/<?= $total_parcelas ?>
                        <?php endif; ?>
                    </p>
                </div>

                <span class="status-topo <?= classeStatus($lancamento['status']) ?>">

                    <i class="fa-solid <?= $lancamento['status'] === 'pago' ? 'fa-circle-check' : 'fa-clock' ?>"></i>

                    <?= escapar(textoStatus($lancamento['status'])) ?>

                </span>

            </div>

            <div class="card-body">

                <div class="dados-grid">

                    <div class="dado">
                        <span class="dado-label">Tipo</span>

                        <div class="dado-valor tipo <?= classeTipo($lancamento['tipo']) ?>">

                            <i class="fa-solid <?= $lancamento['tipo'] === 'receita'
                                                    ? 'fa-arrow-trend-up'
                                                    : 'fa-arrow-trend-down' ?>"></i>

                            <?= escapar(textoTipo($lancamento['tipo'])) ?>

                        </div>
                    </div>

                    <div class="dado">
                        <span class="dado-label">Valor</span>

                        <div class="dado-valor valor-destaque <?= $lancamento['tipo'] === 'receita'
                                                                    ? 'valor-receita'
                                                                    : 'valor-despesa' ?>">

                            <?= $lancamento['tipo'] === 'receita' ? '+' : '-' ?>
                            <?= moedaBR($lancamento['valor']) ?>

                        </div>
                    </div>

                    <div class="dado">
                        <span class="dado-label">Descrição</span>

                        <div class="dado-valor">
                            <strong><?= escapar($lancamento['descricao']) ?></strong>
                        </div>
                    </div>

                    <div class="dado">
                        <span class="dado-label">Categoria</span>

                        <div class="dado-valor">
                            <?= escapar($lancamento['categoria']) ?>
                        </div>
                    </div>

                    <div class="dado">
                        <span class="dado-label">Data do lançamento</span>

                        <div class="dado-valor">
                            <?= dataBR($lancamento['data']) ?>
                        </div>
                    </div>

                    <?php if ($eh_parcela_manual): ?>

                        <div class="dado">

                            <span class="dado-label">
                                Vencimento da parcela
                            </span>

                            <div class="dado-valor">
                                <?= dataBR($vencimento_parcela) ?>
                            </div>

                        </div>

                    <?php endif; ?>

                    <div class="dado">
                        <span class="dado-label">Data do pagamento</span>

                        <div class="dado-valor">
                            <?= !empty($lancamento['data_pagamento'])
                                ? dataBR($lancamento['data_pagamento'])
                                : 'Ainda não pago' ?>
                        </div>
                    </div>

                    <div class="dado">
                        <span class="dado-label">Forma de pagamento</span>

                        <div class="dado-valor">
                            <?= escapar($lancamento['forma_pagamento']) ?>
                        </div>
                    </div>

                    <div class="dado">
                        <span class="dado-label">Parcelas</span>

                        <div class="dado-valor">

                            <?php if ($eh_parcela_manual): ?>

                                Parcela <?= $numero_parcela ?>
                                de <?= $total_parcelas ?>

                            <?php else: ?>

                                <?= (int)$lancamento['parcelas'] === 1
                                    ? 'À vista'
                                    : (int)$lancamento['parcelas'] . 'x' ?>

                            <?php endif; ?>

                        </div>
                    </div>

                    <div class="dado">
                        <span class="dado-label">Cadastrado em</span>

                        <div class="dado-valor">
                            <?= dataBR($lancamento['created_at']) ?>

                            <?php if (!empty($lancamento['created_at'])): ?>
                                às <?= date('H:i', strtotime($lancamento['created_at'])) ?>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

            </div>

        </section>

        <?php if (
            $origem === 'manual'
            && (int)$lancamento['parcelas'] > 1
            && !$eh_parcela_manual
        ): ?>

            <?php
            /*
            |--------------------------------------------------------------------------
            | TODAS AS PARCELAS DO LANÇAMENTO
            |--------------------------------------------------------------------------
            |
            | Nesta tela o lançamento-pai continua sendo o resumo geral.
            | As parcelas são exibidas separadamente para permitir consultar
            | o valor, vencimento e status de cada uma.
            |
            */

            $stmtTodasParcelas = $pdo->prepare("
                SELECT
                    id,
                    numero_parcela,
                    valor,
                    vencimento,
                    status,
                    data_pagamento
                FROM lancamentos_parcelas
                WHERE lancamento_id = ?
                ORDER BY numero_parcela ASC
            ");

            $stmtTodasParcelas->execute([
                $lancamento_id
            ]);

            $todasParcelas = $stmtTodasParcelas->fetchAll(
                PDO::FETCH_ASSOC
            );
            ?>

            <?php if (!empty($todasParcelas)): ?>

                <section class="card">

                    <div class="card-header">

                        <div>
                            <h2>Parcelas</h2>
                            <p>
                                Consulte o andamento de cada parcela deste lançamento.
                            </p>
                        </div>

                        <span class="contador">
                            <?= count($todasParcelas) ?>
                            <?= count($todasParcelas) === 1
                                ? 'parcela'
                                : 'parcelas' ?>
                        </span>

                    </div>

                    <div class="parcelas-lista">

                        <?php foreach ($todasParcelas as $parcelaLista): ?>

                            <?php
                            $statusParcelaLista = strtolower(
                                trim((string)$parcelaLista['status'])
                            );

                            if (
                                $statusParcelaLista === 'pendente'
                                && !empty($parcelaLista['vencimento'])
                                && $parcelaLista['vencimento'] < date('Y-m-d')
                            ) {
                                $statusParcelaLista = 'atrasada';
                            }

                            $ehParcelaSelecionada =
                                $eh_parcela_manual
                                && (int)$parcelaLista['id'] === (int)$parcela_id;
                            ?>

                            <article
                                class="parcela-item <?= $ehParcelaSelecionada ? 'parcela-selecionada' : '' ?>">

                                <div class="parcela-numero">
                                    <strong>
                                        Parcela <?= (int)$parcelaLista['numero_parcela'] ?>
                                    </strong>

                                    <span>
                                        de <?= (int)$lancamento['parcelas'] ?>
                                    </span>
                                </div>

                                <div class="parcela-dados">

                                    <div>
                                        <span>Valor</span>
                                        <strong>
                                            <?= moedaBR($parcelaLista['valor']) ?>
                                        </strong>
                                    </div>

                                    <div>
                                        <span>Vencimento</span>
                                        <strong>
                                            <?= dataBR($parcelaLista['vencimento']) ?>
                                        </strong>
                                    </div>

                                    <?php if (!empty($parcelaLista['data_pagamento'])): ?>

                                        <div>
                                            <span>Pagamento</span>
                                            <strong>
                                                <?= dataBR($parcelaLista['data_pagamento']) ?>
                                            </strong>
                                        </div>

                                    <?php endif; ?>

                                </div>

                                <div class="parcela-status">

                                    <span
                                        class="status-topo <?= escapar(classeStatus(
                                                                $statusParcelaLista === 'paga'
                                                                    ? 'pago'
                                                                    : $statusParcelaLista
                                                            )) ?>">

                                        <i class="fa-solid <?= $statusParcelaLista === 'paga'
                                                                ? 'fa-circle-check'
                                                                : 'fa-clock' ?>"></i>

                                        <?= escapar(textoStatus(
                                            $statusParcelaLista === 'paga'
                                                ? 'pago'
                                                : $statusParcelaLista
                                        )) ?>

                                    </span>

                                </div>

                                <div class="parcela-acoes">

                                    <a
                                        href="visualizar_lancamento.php?id=<?= (int)$lancamento_id ?>&amp;parcela_id=<?= (int)$parcelaLista['id'] ?>"
                                        class="btn btn-secondary"
                                        title="Visualizar parcela">

                                        <i class="fa-regular fa-eye"></i>
                                        Visualizar

                                    </a>

                                    <?php if (
                                        in_array(
                                            $statusParcelaLista,
                                            ['pendente', 'atrasada'],
                                            true
                                        )
                                    ): ?>

                                        <a
                                            href="pagar_lancamento.php?id=<?= (int)$lancamento_id ?>&amp;parcela_id=<?= (int)$parcelaLista['id'] ?>"
                                            class="btn btn-pagar"
                                            title="Pagar parcela">

                                            <i class="fa-solid fa-check"></i>
                                            Pagar

                                        </a>

                                    <?php endif; ?>

                                </div>

                            </article>

                        <?php endforeach; ?>

                    </div>

                </section>

            <?php endif; ?>

        <?php endif; ?>


        <section class="card">

            <div class="card-header">

                <div>
                    <h2>Observações</h2>
                    <p>Informações adicionais registradas no lançamento.</p>
                </div>

            </div>

            <div class="card-body">

                <?php if (trim((string)$lancamento['observacoes']) !== ''): ?>

                    <p class="observacoes">
                        <?= escapar($lancamento['observacoes']) ?>
                    </p>

                <?php else: ?>

                    <div class="sem-observacoes">
                        Nenhuma observação foi registrada para este lançamento.
                    </div>

                <?php endif; ?>

            </div>

        </section>

        <?php if ($lancamento['status'] === 'pendente'): ?>

            <section class="card">

                <div class="card-body">

                    <div class="aviso-pagamento">
                        <i class="fa-solid fa-circle-info"></i>

                        <div>
                            Este lançamento está <strong>pendente</strong>.
                            Ao confirmar o pagamento, ele será marcado como
                            <strong>pago</strong> no Financeiro.
                        </div>
                    </div>

                </div>

            </section>

        <?php endif; ?>

    </main>

</body>

</html>