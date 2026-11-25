<?php
require_once 'config/auth.php';
exigirLogin();
require_once 'conexao/conexao.php';

// 🔍 Captura filtros da URL
$busca    = trim($_GET['busca'] ?? '');
$data_ini = $_GET['data_ini'] ?? '';
$data_fim = $_GET['data_fim'] ?? '';

// 🛡️ Monta WHERE dinâmico seguro
$where = [
    "p.arquivado = 0"
];
$params = [];

// Filtro de busca (nome, CPF ou telefone)
if ($busca !== '') {
    $where[] = "(p.paciente LIKE ? OR p.cpf LIKE ? OR p.telefone LIKE ?)";
    $busca_like = "%{$busca}%";
    $params[] = $busca_like;
    $params[] = $busca_like;
    $params[] = $busca_like;
}

// Filtro de período (nascimento)
if ($data_ini !== '') {
    $where[] = "p.nascimento >= ?";
    $params[] = $data_ini;
}
if ($data_fim !== '') {
    $where[] = "p.nascimento <= ?";
    $params[] = $data_fim;
}

// 🔽 Query principal COM filtros aplicados
$sql = "
    SELECT 
        p.*,
        COALESCE(proc_count.total, 0) as total_procedimentos
    FROM prontuarios p
    LEFT JOIN (
        SELECT paciente_id, COUNT(*) as total
        FROM procedimentos
        WHERE arquivado = 0
        GROUP BY paciente_id
    ) proc_count ON p.id = proc_count.paciente_id
";

// 🔽 Lógica de Exportação CSV
if (isset($_GET['exportar']) && $_GET['exportar'] === '1') {
    require_once 'funcoes_export.php';

    // Reutiliza exatamente a mesma query dos filtros
    $sql_exp = "
    SELECT
        p.id,
        p.paciente,
        p.cpf,
        p.telefone,
        p.nascimento,
        p.observacoes,
        COALESCE(proc_count.total, 0) as total_procedimentos
    FROM prontuarios p
    LEFT JOIN (
        SELECT
            paciente_id,
            COUNT(*) as total
        FROM procedimentos
        WHERE arquivado = 0
        GROUP BY paciente_id
    ) proc_count ON p.id = proc_count.paciente_id
";

    if (!empty($where)) {
        $sql_exp .= " WHERE " . implode(" AND ", $where);
    }
    $sql_exp .= " ORDER BY p.paciente ASC";

    exportarParaCSV($pdo, 'Relatório de Prontuários - Dentech', $sql_exp, $params, 'prontuarios');
}

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY p.paciente ASC";

// Executa com prepared statement
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$prontuarios = $stmt->fetchAll();
// 🔼 Fim da query filtrada
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prontuários - Dentech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/variables.css">
    <link rel="stylesheet" href="css/layout.css">
    <link rel="stylesheet" href="css/prontuarios.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="icon" type="image/png" href="img/icon.PNG">
</head>

<body>
    <?php include 'navbar.php'; ?>

    <div class="content">
        <main>

            <!-- CABEÇALHO DA PÁGINA -->
            <div class="page-header">

                <div>
                    <span class="breadcrumb">Prontuários</span>
                    <h1>Prontuários</h1>
                </div>

                <div class="page-header-actions">

                    <form method="GET" class="search-form">

                        <div class="search-input">
                            <i class="fa-solid fa-magnifying-glass"></i>

                            <input
                                type="text"
                                name="busca"
                                placeholder="Buscar paciente..."
                                value="<?= htmlspecialchars($busca) ?>">
                        </div>

                        <?php if ($data_ini !== ''): ?>
                            <input
                                type="hidden"
                                name="data_ini"
                                value="<?= htmlspecialchars($data_ini) ?>">
                        <?php endif; ?>

                        <?php if ($data_fim !== ''): ?>
                            <input
                                type="hidden"
                                name="data_fim"
                                value="<?= htmlspecialchars($data_fim) ?>">
                        <?php endif; ?>

                    </form>

                    <a href="editar_prontuario.php" class="btn-primary">
                        <i class="fa-solid fa-plus"></i>
                        Novo prontuário
                    </a>

                </div>

            </div>


            <!-- CARDS DE RESUMO -->
            <section class="stats-grid">

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fa-solid fa-users"></i>
                    </div>

                    <div>
                        <span>Pacientes</span>
                        <strong><?= count($prontuarios) ?></strong>
                    </div>
                </div>


                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fa-solid fa-file-medical"></i>
                    </div>

                    <div>
                        <span>Prontuários</span>
                        <strong><?= count($prontuarios) ?></strong>
                    </div>
                </div>


                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fa-solid fa-notes-medical"></i>
                    </div>

                    <div>
                        <span>Procedimentos</span>

                        <strong>
                            <?php
                            $totalProcedimentos = 0;

                            foreach ($prontuarios as $p) {
                                $totalProcedimentos += (int) ($p['total_procedimentos'] ?? 0);
                            }

                            echo $totalProcedimentos;
                            ?>
                        </strong>

                    </div>
                </div>


                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fa-solid fa-clock"></i>
                    </div>

                    <div>
                        <span>Atualização</span>
                        <strong>Hoje</strong>
                    </div>
                </div>

            </section>


            <!-- ÁREA PRINCIPAL -->
            <section class="content-card">

                <!-- ABAS -->
                <div class="tabs">

                    <button class="tab active" type="button">
                        Pacientes
                    </button>

                    <button class="tab" type="button">
                        Histórico de atendimentos
                    </button>

                </div>


                <!-- FILTROS -->
                <div class="filter-area">

                    <form method="GET" class="filter-form">

                        <div class="filter-field">

                            <label for="data_ini">
                                Nascimento a partir de
                            </label>

                            <input
                                id="data_ini"
                                type="date"
                                name="data_ini"
                                value="<?= htmlspecialchars($data_ini) ?>">

                        </div>


                        <div class="filter-field">

                            <label for="data_fim">
                                Nascimento até
                            </label>

                            <input
                                id="data_fim"
                                type="date"
                                name="data_fim"
                                value="<?= htmlspecialchars($data_fim) ?>">

                        </div>


                        <div class="filter-buttons">

                            <button type="submit" class="btn-filter">
                                <i class="fa-solid fa-filter"></i>
                                Filtrar
                            </button>

                            <a href="prontuarios" class="btn-reset">
                                Limpar
                            </a>

                        </div>

                    </form>


                    <?php if ($busca !== '' || $data_ini !== '' || $data_fim !== ''): ?>

                        <div class="filter-info">

                            <i class="fa-solid fa-circle-info"></i>

                            <span>Filtros ativos:</span>

                            <?php
                            $ativos = [];

                            if ($busca !== '') {
                                $ativos[] = "Busca: '{$busca}'";
                            }

                            if ($data_ini !== '') {
                                $ativos[] = "De: " . date('d/m/Y', strtotime($data_ini));
                            }

                            if ($data_fim !== '') {
                                $ativos[] = "Até: " . date('d/m/Y', strtotime($data_fim));
                            }

                            echo htmlspecialchars(implode(' • ', $ativos));
                            ?>

                        </div>

                    <?php endif; ?>

                </div>


                <!-- TABELA -->
                <div class="table-wrapper">

                    <table class="patients-table">

                        <thead>

                            <tr>

                                <th>Paciente</th>

                                <th>Data de nascimento</th>

                                <th>Último atendimento</th>

                                <th>Telefone</th>

                                <th>Ações</th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php if (empty($prontuarios)): ?>

                                <tr>

                                    <td colspan="5" class="empty-table">

                                        <div class="empty-state">

                                            <i class="fa-solid fa-user-group"></i>

                                            <strong>
                                                <?= ($busca !== '' || $data_ini !== '' || $data_fim !== '')
                                                    ? 'Nenhum prontuário encontrado'
                                                    : 'Nenhum prontuário cadastrado'
                                                ?>
                                            </strong>

                                            <span>
                                                <?= ($busca !== '' || $data_ini !== '' || $data_fim !== '')
                                                    ? 'Tente alterar os filtros utilizados.'
                                                    : 'Cadastre o primeiro paciente para começar.'
                                                ?>
                                            </span>

                                        </div>

                                    </td>

                                </tr>

                            <?php else: ?>

                                <?php foreach ($prontuarios as $p): ?>

                                    <tr>

                                        <td>

                                            <div class="patient-name">

                                                <div class="patient-avatar">
                                                    <?= strtoupper(substr($p['paciente'], 0, 1)) ?>
                                                </div>

                                                <div>
                                                    <strong>
                                                        <?= htmlspecialchars($p['paciente']) ?>
                                                    </strong>

                                                    <span>
                                                        CPF:
                                                        <?= htmlspecialchars($p['cpf'] ?? '—') ?>
                                                    </span>
                                                </div>

                                            </div>

                                        </td>


                                        <td>

                                            <?= date(
                                                'd/m/Y',
                                                strtotime($p['nascimento'])
                                            ) ?>

                                        </td>


                                        <td>

                                            <span class="not-available">
                                                —
                                            </span>

                                        </td>


                                        <td>

                                            <?= htmlspecialchars(
                                                $p['telefone'] ?? '—'
                                            ) ?>

                                        </td>


                                        <td>

                                            <div class="table-actions">

                                                <a
                                                    href="visualizar_prontuario.php?id=<?= $p['id'] ?>"
                                                    class="action-button"
                                                    title="Visualizar">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>


                                                <a
                                                    href="editar_prontuario.php?id=<?= $p['id'] ?>"
                                                    class="action-button"
                                                    title="Editar">
                                                    <i class="fa-solid fa-pen"></i>
                                                </a>


                                                <button
                                                    type="button"
                                                    class="action-button danger"
                                                    title="Excluir"
                                                    onclick="excluirProntuario(<?= $p['id'] ?>)">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>

                                            </div>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>


                <!-- RODAPÉ DA TABELA -->
                <?php if (!empty($prontuarios)): ?>

                    <div class="table-footer">

                        <span><?= count($prontuarios) ?> prontuário(s) encontrado(s)</span>
                        <?php
                        $export_url = '?' . http_build_query(array_merge($_GET, ['exportar' => '1']));
                        ?>
                        <a href="<?= $export_url ?>" class="btn-exportar"><i class="fa-solid fa-file-csv"></i>Exportar CSV</a>
                    </div>
                <?php endif; ?>
            </section>
        </main>

        <script>
            function excluirProntuario(id) {
                if (!confirm('⚠️ ATENÇÃO:\nExcluir este prontuário também removerá todos os agendamentos, procedimentos e orçamentos vinculados a ele.\n\nDeseja continuar?')) {
                    return;
                }

                fetch('excluir_prontuario.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'id=' + encodeURIComponent(id) +
                            '&csrf_token=' + encodeURIComponent('<?= $_SESSION['csrf_token'] ?>')
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Prontuário excluído com sucesso!');
                            location.reload();
                        } else {
                            alert('Erro: ' + data.error);
                        }
                    })
                    .catch(error => {
                        console.error('Erro:', error);
                        alert('Erro de conexão. Tente novamente.');
                    });
            }
        </script>
</body>

</html>