<?php

require_once 'config/auth.php';
require_once 'conexao/conexao.php';

exigirLogin();

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: plano_tratamento.php?erro=invalido');
    exit;
}

/*
|--------------------------------------------------------------------------
| PLANO
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        pt.id,
        pt.paciente_id,
        pt.titulo,
        pt.descricao,
        pt.status,
        pt.data_criacao,
        pt.data_atualizacao,
        p.paciente
    FROM planos_tratamento pt
    INNER JOIN prontuarios p
        ON p.id = pt.paciente_id
    WHERE pt.id = ?
    LIMIT 1
");

$stmt->execute([$id]);

$plano = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plano) {
    header('Location: plano_tratamento.php?erro=nao_encontrado');
    exit;
}

/*
|--------------------------------------------------------------------------
| ITENS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        pti.id,
        pti.servico_id,
        pti.descricao,
        pti.dente_regiao,
        pti.prioridade,
        pti.valor_estimado,
        pti.status,
        pti.ordem,
        pti.observacoes,
        s.nome AS servico_nome
    FROM planos_tratamento_itens pti
    LEFT JOIN servicos s
        ON s.id = pti.servico_id
    WHERE pti.plano_id = ?
    ORDER BY pti.ordem ASC, pti.id ASC
");

$stmt->execute([$id]);

$itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalEstimado = 0.00;

foreach ($itens as $item) {
    // Itens cancelados permanecem no histórico, mas não compõem o total do plano.
    if (($item['status'] ?? '') !== 'cancelado') {
        $totalEstimado += (float)$item['valor_estimado'];
    }
}

/*
|--------------------------------------------------------------------------
| ORÇAMENTOS VINCULADOS ÀS ETAPAS
|--------------------------------------------------------------------------
*/

$orcamentosPorItem = [];

if (!empty($itens)) {

    $idsItens = array_map(
        static fn($item) => (int)$item['id'],
        $itens
    );

    $placeholders = implode(',', array_fill(
        0,
        count($idsItens),
        '?'
    ));

    $stmtOrcamentos = $pdo->prepare("
        SELECT
            pio.plano_item_id,
            o.id,
            o.status,
            o.data_criacao,
            o.validade,
            COALESCE(SUM(oi.quantidade * oi.valor_unitario), 0) AS total
        FROM planos_tratamento_itens_orcamentos pio
        INNER JOIN orcamentos o
            ON o.id = pio.orcamento_id
        LEFT JOIN orcamentos_itens oi
            ON oi.orcamento_id = o.id
        WHERE pio.plano_item_id IN ($placeholders)
        GROUP BY
            pio.plano_item_id,
            o.id,
            o.status,
            o.data_criacao,
            o.validade
        ORDER BY o.id DESC
    ");

    $stmtOrcamentos->execute($idsItens);

    foreach ($stmtOrcamentos->fetchAll(PDO::FETCH_ASSOC) as $orcamento) {
        $orcamentosPorItem[(int)$orcamento['plano_item_id']][] = $orcamento;
    }
}

/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

$statusLabels = [
    'planejamento' => 'Planejamento',
    'em_andamento' => 'Em andamento',
    'concluido' => 'Concluído',
    'cancelado' => 'Cancelado',
];

$statusIcones = [
    'planejamento' => 'fa-regular fa-pen-to-square',
    'em_andamento' => 'fa-solid fa-spinner',
    'concluido' => 'fa-solid fa-circle-check',
    'cancelado' => 'fa-solid fa-ban',
];

$prioridadeLabels = [
    'baixa' => 'Baixa',
    'media' => 'Média',
    'alta' => 'Alta',
];

$itemStatusLabels = [
    'planejado' => 'Planejado',
    'em_andamento' => 'Em andamento',
    'concluido' => 'Concluído',
    'cancelado' => 'Cancelado',
];

$itemStatusClasses = [
    'planejado' => 'item-planejado',
    'em_andamento' => 'item-em-andamento',
    'concluido' => 'item-concluido',
    'cancelado' => 'item-cancelado',
];

$prioridadeClasses = [
    'baixa' => 'prioridade-baixa',
    'media' => 'prioridade-media',
    'alta' => 'prioridade-alta',
];

$sucesso = $_GET['sucesso'] ?? '';

?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>
        Plano #<?= $id ?> - Dentech
    </title>

    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/variables.css">
    <link rel="stylesheet" href="css/layout.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/visualizar_plano_tratamento.css">

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

    <main class="visualizar-plano-page">

        <div class="visualizar-plano-container">

            <?php if ($sucesso === 'criado'): ?>

                <div class="message message-success">

                    <i class="fa-solid fa-circle-check"></i>

                    <span>
                        Plano de tratamento criado com sucesso.
                    </span>

                </div>

            <?php endif; ?>

            <header class="page-header">

                <div>

                    <span class="page-kicker">
                        PLANEJAMENTO CLÍNICO
                    </span>

                    <div class="title-row">

                        <h1>
                            <?= htmlspecialchars($plano['titulo']) ?>
                        </h1>

                        <span
                            class="status-badge status-<?= htmlspecialchars(
                                                            $plano['status']
                                                        ) ?>">

                            <i class="<?= $statusIcones[$plano['status']] ?? 'fa-solid fa-circle-question' ?>"></i>

                            <?= htmlspecialchars(
                                $statusLabels[$plano['status']] ?? $plano['status']
                            ) ?>

                        </span>

                    </div>

                    <p>
                        Planejamento do paciente
                        <strong>
                            <?= htmlspecialchars($plano['paciente']) ?>
                        </strong>
                    </p>

                </div>

                <div class="header-actions">

                    <a
                        href="plano_tratamento.php"
                        class="btn btn-outline">
                        <i class="fa-solid fa-arrow-left"></i>
                        Voltar
                    </a>

                    <a
                        href="editar_plano_tratamento.php?id=<?= $id ?>"
                        class="btn btn-primary">
                        <i class="fa-solid fa-pen"></i>
                        Editar plano
                    </a>

                </div>

            </header>

            <section class="info-grid">

                <article class="info-card">

                    <span class="info-label">
                        Paciente
                    </span>

                    <strong>
                        <?= htmlspecialchars($plano['paciente']) ?>
                    </strong>

                </article>

                <article class="info-card">

                    <span class="info-label">
                        Etapas
                    </span>

                    <strong>
                        <?= count($itens) ?>
                    </strong>

                </article>

                <article class="info-card">

                    <span class="info-label">
                        Valor estimado
                    </span>

                    <strong>
                        R$
                        <?= number_format(
                            $totalEstimado,
                            2,
                            ',',
                            '.'
                        ) ?>
                    </strong>

                </article>

                <article class="info-card">

                    <span class="info-label">
                        Atualizado em
                    </span>

                    <strong>
                        <?= date(
                            'd/m/Y H:i',
                            strtotime($plano['data_atualizacao'])
                        ) ?>
                    </strong>

                </article>

            </section>

            <?php if (!empty($plano['descricao'])): ?>

                <section class="content-card">

                    <div class="card-heading">

                        <div>

                            <span class="card-kicker">
                                VISÃO GERAL
                            </span>

                            <h2>
                                Objetivo do tratamento
                            </h2>

                        </div>

                    </div>

                    <div class="description-box">
                        <?= nl2br(
                            htmlspecialchars(
                                $plano['descricao']
                            )
                        ) ?>
                    </div>

                </section>

            <?php endif; ?>

            <section class="content-card">

                <div class="card-heading">

                    <div>

                        <span class="card-kicker">
                            ETAPAS
                        </span>

                        <h2>
                            Etapas do tratamento
                        </h2>

                    </div>

                    <span class="result-count">
                        <?= count($itens) ?>
                        etapa(s)
                    </span>

                </div>

                <?php if (empty($itens)): ?>

                    <div class="empty-state">

                        <div class="empty-icon">
                            <i class="fa-solid fa-list-check"></i>
                        </div>

                        <strong>
                            Nenhuma etapa cadastrada
                        </strong>

                        <span>
                            Edite o plano para adicionar etapas.
                        </span>

                    </div>

                <?php else: ?>

                    <div class="steps-list">

                        <?php foreach ($itens as $index => $item): ?>

                            <article class="step-card">

                                <div class="step-number">
                                    <?= $index + 1 ?>
                                </div>

                                <div class="step-main">

                                    <div class="step-top">

                                        <div class="step-title">

                                            <span class="step-kicker">
                                                <?= htmlspecialchars(
                                                    $item['servico_nome']
                                                        ?: 'Serviço personalizado'
                                                ) ?>
                                            </span>

                                            <h3>
                                                <?= htmlspecialchars(
                                                    $item['descricao']
                                                ) ?>
                                            </h3>

                                        </div>

                                        <strong class="step-value">
                                            R$
                                            <?= number_format(
                                                (float)$item['valor_estimado'],
                                                2,
                                                ',',
                                                '.'
                                            ) ?>
                                        </strong>

                                    </div>

                                    <div class="step-meta">

                                        <span
                                            class="item-status
                                            <?= htmlspecialchars(
                                                $itemStatusClasses[$item['status']] ?? 'item-planejado'
                                            ) ?>">

                                            <i class="fa-solid fa-circle"></i>

                                            <?= htmlspecialchars(
                                                $itemStatusLabels[$item['status']] ?? $item['status']
                                            ) ?>

                                        </span>

                                        <span
                                            class="priority-badge
                                            <?= htmlspecialchars(
                                                $prioridadeClasses[$item['prioridade']] ?? 'prioridade-media'
                                            ) ?>">

                                            <i class="fa-solid fa-flag"></i>

                                            <?= htmlspecialchars(
                                                $prioridadeLabels[$item['prioridade']] ?? $item['prioridade']
                                            ) ?>

                                        </span>

                                        <?php if (
                                            !empty($item['dente_regiao'])
                                        ): ?>

                                            <span class="meta-tag">

                                                <i
                                                    class="fa-solid fa-tooth"></i>

                                                <?= htmlspecialchars(
                                                    $item['dente_regiao']
                                                ) ?>

                                            </span>

                                        <?php endif; ?>

                                    </div>

                                    <?php
                                    $orcamentosItem = $orcamentosPorItem[(int)$item['id']] ?? [];
                                    ?>

                                    <div class="step-budget">

                                        <div class="step-budget-heading">

                                            <span>
                                                <i class="fa-solid fa-file-invoice-dollar"></i>
                                                Orçamento
                                            </span>

                                        </div>

                                        <?php if (!empty($orcamentosItem)): ?>

                                            <div class="step-budget-list">

                                                <?php foreach ($orcamentosItem as $orcamento): ?>

                                                    <div class="step-budget-row">

                                                        <div>
                                                            <strong>#<?= (int)$orcamento['id'] ?></strong>
                                                            <span>
                                                                <?= htmlspecialchars(ucfirst($orcamento['status'])) ?>
                                                            </span>
                                                        </div>

                                                        <strong>
                                                            R$
                                                            <?= number_format(
                                                                (float)$orcamento['total'],
                                                                2,
                                                                ',',
                                                                '.'
                                                            ) ?>
                                                        </strong>

                                                        <a
                                                            href="visualizar_orcamento.php?id=<?= (int)$orcamento['id'] ?>"
                                                            class="step-budget-action">
                                                            Visualizar
                                                        </a>

                                                    </div>

                                                <?php endforeach; ?>

                                            </div>

                                        <?php else: ?>

                                            <div class="step-budget-empty">
                                                <span>
                                                    Nenhum orçamento vinculado a esta etapa.
                                                </span>

                                                <a
                                                    href="novo_orcamento.php?plano_item_id=<?= (int)$item['id'] ?>"
                                                    class="step-budget-action primary">
                                                    <i class="fa-solid fa-plus"></i>
                                                    Criar orçamento
                                                </a>
                                            </div>

                                        <?php endif; ?>

                                    </div>

                                    <?php if (
                                        !in_array(
                                            $item['status'],
                                            ['concluido', 'cancelado'],
                                            true
                                        )
                                    ): ?>

                                        <div class="step-actions">

                                            <a
                                                href="agendamentos.php?plano_item_id=<?= (int)$item['id'] ?>"
                                                class="step-action-button schedule"
                                                title="Agendar esta etapa">

                                                <i class="fa-regular fa-calendar-plus"></i>

                                                Agendar etapa

                                            </a>

                                        </div>

                                    <?php endif; ?>

                                    <?php if (!empty($item['observacoes'])): ?>

                                        <div class="step-observation">

                                            <i class="fa-regular fa-note-sticky"></i>

                                            <span>
                                                <?= nl2br(
                                                    htmlspecialchars(
                                                        $item['observacoes']
                                                    )
                                                ) ?>
                                            </span>

                                        </div>

                                    <?php endif; ?>

                                </div>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </section>

            <section class="total-card">

                <div>

                    <span>
                        Valor total estimado
                    </span>

                    <small>
                        Soma das etapas não canceladas.
                        Este valor é uma estimativa clínica.
                    </small>

                </div>

                <strong>
                    R$
                    <?= number_format(
                        $totalEstimado,
                        2,
                        ',',
                        '.'
                    ) ?>
                </strong>

            </section>

            <div class="planning-note">

                <i class="fa-solid fa-circle-info"></i>

                <p>
                    Este plano representa o planejamento clínico.
                    Ele não cria automaticamente um orçamento e não
                    registra que um procedimento foi realizado.
                </p>

            </div>

        </div>

    </main>

</body>

</html>