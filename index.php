<?php
require_once 'conexao/conexao.php';
require_once 'config/auth.php';

exigirLogin();

/*
|--------------------------------------------------------------------------
| DADOS DO DASHBOARD
|--------------------------------------------------------------------------
| Esta página usa somente as tabelas que já existem no Dentech.
| Nenhuma alteração de banco é necessária para esta etapa.
*/

// Pacientes cadastrados
$total_pacientes = (int) $pdo
    ->query("SELECT COUNT(*) FROM prontuarios")
    ->fetchColumn();

// Agendamentos de hoje
$stmt_hoje = $pdo->prepare("
    SELECT
        a.id,
        COALESCE(p.paciente, a.paciente_nome) AS paciente,
        a.procedimento,
        a.data,
        a.horario
    FROM agendamentos a
    LEFT JOIN prontuarios p ON a.paciente_id = p.id
    WHERE a.data = CURDATE()
    ORDER BY a.horario ASC
");
$stmt_hoje->execute();
$agendamentos_hoje = $stmt_hoje->fetchAll();

// Próximos agendamentos
$stmt_proximos = $pdo->prepare("
    SELECT
        a.id,
        COALESCE(p.paciente, a.paciente_nome) AS paciente,
        a.procedimento,
        a.data,
        a.horario
    FROM agendamentos a
    LEFT JOIN prontuarios p ON a.paciente_id = p.id
    WHERE a.data >= CURDATE()
    ORDER BY a.data ASC, a.horario ASC
    LIMIT 6
");
$stmt_proximos->execute();
$proximos_agendamentos = $stmt_proximos->fetchAll();

// Estoque baixo
$stmt_estoque = $pdo->query("
    SELECT
        id,
        nome,
        quantidade,
        estoque_minimo,
        unidade
    FROM estoque
    WHERE quantidade <= estoque_minimo
    ORDER BY quantidade ASC, nome ASC
");
$materiais_baixo = $stmt_estoque->fetchAll();
$total_estoque_baixo = count($materiais_baixo);

// Orçamentos do mês
$mes_atual = date('Y-m');

$stmt_rel_orc = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) AS pendentes,
        SUM(CASE WHEN status = 'aceito' THEN 1 ELSE 0 END) AS aceitos,
        SUM(CASE WHEN status = 'recusado' THEN 1 ELSE 0 END) AS recusados
    FROM orcamentos
    WHERE DATE_FORMAT(data_criacao, '%Y-%m') = ?
");
$stmt_rel_orc->execute([$mes_atual]);
$rel_orc = $stmt_rel_orc->fetch();

$total_orc = (int) ($rel_orc['total'] ?? 0);
$pendentes = (int) ($rel_orc['pendentes'] ?? 0);
$aceitos = (int) ($rel_orc['aceitos'] ?? 0);
$recusados = (int) ($rel_orc['recusados'] ?? 0);

// Valor dos orçamentos aceitos no mês
$stmt_valor = $pdo->prepare("
    SELECT COALESCE(SUM(itens.total_item), 0) AS valor_total
    FROM orcamentos o
    LEFT JOIN (
        SELECT
            orcamento_id,
            SUM(quantidade * valor_unitario) AS total_item
        FROM orcamentos_itens
        GROUP BY orcamento_id
    ) itens ON o.id = itens.orcamento_id
    WHERE o.status = 'aceito'
      AND DATE_FORMAT(o.data_criacao, '%Y-%m') = ?
");
$stmt_valor->execute([$mes_atual]);
$valor_total_aceitos = (float) ($stmt_valor->fetchColumn() ?? 0);

// Últimos orçamentos
$stmt_orc = $pdo->prepare("
    SELECT
        o.id,
        o.data_criacao,
        o.status,
        p.paciente
    FROM orcamentos o
    JOIN prontuarios p ON o.paciente_id = p.id
    ORDER BY o.data_criacao DESC, o.id DESC
    LIMIT 5
");
$stmt_orc->execute();
$ultimos_orcamentos = $stmt_orc->fetchAll();

function formatarData($data): string
{
    return $data ? date('d/m/Y', strtotime($data)) : '-';
}

function formatarHora($hora): string
{
    return $hora ? substr($hora, 0, 5) : '--:--';
}

function statusOrcamento(string $status): string
{
    return match ($status) {
        'aceito' => 'Aceito',
        'recusado' => 'Recusado',
        default => 'Pendente',
    };
}
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dentech | Dashboard</title>

    <link rel="icon" href="img/icon.png">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/variables.css">
    <link rel="stylesheet" href="css/index.css">

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>

<body>
    <?php include 'navbar.php'; ?>

    <main class="content">

        <header class="topbar">

            <div class="page-heading">
                <h1>Dashboard</h1>
                <p>Visão geral da clínica</p>
            </div>

            <div class="topbar-actions">
                <span class="today">
                    <i class="fa-regular fa-calendar"></i>
                    <?= date('d/m/Y') ?>
                </span>

                <button class="icon-button" type="button" title="Notificações">
                    <i class="fa-regular fa-bell"></i>
                </button>

                <div class="user-mini">
                    <div class="user-avatar">
                        <i class="fa-solid fa-user"></i>
                    </div>
                    <div>
                        <strong>Usuário</strong>
                        <span>Clínica</span>
                    </div>
                </div>
            </div>

        </header>

        <section class="stats-grid">

            <a href="prontuarios" class="stat-card">
                <div class="stat-icon stat-blue">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div class="stat-content">
                    <span>Total de pacientes</span>
                    <strong><?= $total_pacientes ?></strong>
                    <small>Pacientes cadastrados</small>
                </div>
            </a>

            <a href="agendamentos" class="stat-card">
                <div class="stat-icon stat-teal">
                    <i class="fa-solid fa-calendar-day"></i>
                </div>
                <div class="stat-content">
                    <span>Agendamentos hoje</span>
                    <strong><?= count($agendamentos_hoje) ?></strong>
                    <small><?= count($agendamentos_hoje) === 1 ? '1 atendimento marcado' : count($agendamentos_hoje) . ' atendimentos marcados' ?></small>
                </div>
            </a>

            <a href="orcamento" class="stat-card">
                <div class="stat-icon stat-green">
                    <i class="fa-solid fa-file-circle-check"></i>
                </div>
                <div class="stat-content">
                    <span>Orçamentos aceitos</span>
                    <strong><?= $aceitos ?></strong>
                    <small><?= $total_orc ?> orçamento(s) no mês</small>
                </div>
            </a>

            <a href="inventario" class="stat-card <?= $total_estoque_baixo > 0 ? 'stat-alert' : '' ?>">
                <div class="stat-icon stat-orange">
                    <i class="fa-solid fa-box-open"></i>
                </div>
                <div class="stat-content">
                    <span>Estoque baixo</span>
                    <strong><?= $total_estoque_baixo ?></strong>
                    <small><?= $total_estoque_baixo > 0 ? 'Itens precisam de atenção' : 'Estoque normal' ?></small>
                </div>
            </a>

        </section>

        <section class="dashboard-grid">

            <div class="panel appointments-panel">

                <div class="panel-header">
                    <div>
                        <span class="panel-kicker">Agenda</span>
                        <h2>Agendamentos de hoje</h2>
                    </div>

                    <a href="agendamentos" class="panel-link">
                        Ver todos
                        <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>

                <?php if (empty($agendamentos_hoje)): ?>

                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fa-regular fa-calendar-xmark"></i>
                        </div>
                        <strong>Nenhum agendamento para hoje</strong>
                        <span>A agenda está livre neste momento.</span>
                        <a href="agendamentos" class="btn-primary">
                            <i class="fa-solid fa-plus"></i>
                            Novo agendamento
                        </a>
                    </div>

                <?php else: ?>

                    <div class="appointment-list">

                        <?php foreach ($agendamentos_hoje as $ag): ?>

                            <div class="appointment-item">

                                <div class="appointment-time">
                                    <?= formatarHora($ag['horario']) ?>
                                </div>

                                <div class="appointment-icon">
                                    <i class="fa-solid fa-user"></i>
                                </div>

                                <div class="appointment-info">
                                    <strong><?= htmlspecialchars($ag['paciente'] ?: 'Paciente não informado') ?></strong>
                                    <span><?= htmlspecialchars($ag['procedimento']) ?></span>
                                </div>

                                <a href="agendamentos" class="row-action" title="Abrir agenda">
                                    <i class="fa-solid fa-chevron-right"></i>
                                </a>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </div>

            <div class="panel budget-panel">

                <div class="panel-header">
                    <div>
                        <span class="panel-kicker">Orçamentos</span>
                        <h2>Resumo do mês</h2>
                    </div>

                    <a href="orcamento" class="panel-link">
                        Ver todos
                        <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>

                <div class="budget-total">
                    <span>Valor dos aceitos</span>
                    <strong>R$ <?= number_format($valor_total_aceitos, 2, ',', '.') ?></strong>
                    <small>Orçamentos aceitos em <?= date('m/Y') ?></small>
                </div>

                <div class="budget-status">

                    <div class="budget-status-item">
                        <span class="status-dot pending"></span>
                        <div>
                            <strong><?= $pendentes ?></strong>
                            <span>Pendentes</span>
                        </div>
                    </div>

                    <div class="budget-status-item">
                        <span class="status-dot accepted"></span>
                        <div>
                            <strong><?= $aceitos ?></strong>
                            <span>Aceitos</span>
                        </div>
                    </div>

                    <div class="budget-status-item">
                        <span class="status-dot refused"></span>
                        <div>
                            <strong><?= $recusados ?></strong>
                            <span>Recusados</span>
                        </div>
                    </div>

                </div>

            </div>

        </section>

        <section class="lower-grid">

            <div class="panel">

                <div class="panel-header">
                    <div>
                        <span class="panel-kicker">Agenda</span>
                        <h2>Próximos agendamentos</h2>
                    </div>

                    <a href="agendamentos" class="panel-link">Ver agenda</a>
                </div>

                <div class="simple-list">

                    <?php if (empty($proximos_agendamentos)): ?>

                        <div class="empty-inline">
                            Nenhum próximo agendamento encontrado.
                        </div>

                    <?php else: ?>

                        <?php foreach ($proximos_agendamentos as $ag): ?>

                            <div class="simple-list-item">

                                <div class="date-box">
                                    <strong><?= date('d', strtotime($ag['data'])) ?></strong>
                                    <span><?= strtoupper(date('M', strtotime($ag['data']))) ?></span>
                                </div>

                                <div class="simple-list-info">
                                    <strong><?= htmlspecialchars($ag['paciente'] ?: 'Paciente não informado') ?></strong>
                                    <span><?= htmlspecialchars($ag['procedimento']) ?></span>
                                </div>

                                <time><?= formatarHora($ag['horario']) ?></time>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            </div>

            <div class="panel">

                <div class="panel-header">
                    <div>
                        <span class="panel-kicker">Estoque</span>
                        <h2>Itens que precisam de atenção</h2>
                    </div>

                    <a href="inventario" class="panel-link">Ver estoque</a>
                </div>

                <div class="simple-list">

                    <?php if (empty($materiais_baixo)): ?>

                        <div class="empty-state compact">
                            <div class="empty-icon success">
                                <i class="fa-solid fa-check"></i>
                            </div>
                            <strong>Estoque em dia</strong>
                            <span>Nenhum item abaixo do mínimo.</span>
                        </div>

                    <?php else: ?>

                        <?php foreach (array_slice($materiais_baixo, 0, 5) as $mat): ?>

                            <div class="simple-list-item stock-item">

                                <div class="stock-icon">
                                    <i class="fa-solid fa-box"></i>
                                </div>

                                <div class="simple-list-info">
                                    <strong><?= htmlspecialchars($mat['nome']) ?></strong>
                                    <span>Mínimo: <?= number_format($mat['estoque_minimo'], 0, ',', '.') ?> <?= htmlspecialchars($mat['unidade']) ?></span>
                                </div>

                                <span class="stock-badge">
                                    <?= number_format($mat['quantidade'], 0, ',', '.') ?> <?= htmlspecialchars($mat['unidade']) ?>
                                </span>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            </div>

        </section>

        <section class="panel recent-panel">

            <div class="panel-header">
                <div>
                    <span class="panel-kicker">Orçamentos</span>
                    <h2>Últimos orçamentos</h2>
                </div>

                <a href="orcamento" class="panel-link">
                    Ver todos
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>Paciente</th>
                            <th>Data</th>
                            <th>Status</th>
                            <th class="align-right">Ação</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (empty($ultimos_orcamentos)): ?>

                            <tr>
                                <td colspan="4" class="table-empty">
                                    Nenhum orçamento encontrado.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($ultimos_orcamentos as $orc): ?>

                                <tr>
                                    <td>
                                        <div class="table-person">
                                            <div class="table-avatar">
                                                <?= strtoupper(substr($orc['paciente'], 0, 1)) ?>
                                            </div>
                                            <strong><?= htmlspecialchars($orc['paciente']) ?></strong>
                                        </div>
                                    </td>

                                    <td><?= formatarData($orc['data_criacao']) ?></td>

                                    <td>
                                        <span class="status-badge <?= htmlspecialchars($orc['status']) ?>">
                                            <?= statusOrcamento($orc['status']) ?>
                                        </span>
                                    </td>

                                    <td class="align-right">
                                        <a href="visualizar_orcamento?id=<?= (int)$orc['id'] ?>" class="table-action">
                                            <i class="fa-regular fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    </main>

</body>

</html>