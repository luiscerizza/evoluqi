<?php
require_once 'config/auth.php';
require_once 'config/csrf.php';
exigirLogin();

require_once 'conexao/conexao.php';
require_once 'funcoes_export.php';

/* =========================================================
   FILTROS
   ========================================================= */

$busca       = trim($_GET['busca'] ?? '');
$id_orcamento = trim($_GET['id_orcamento'] ?? '');
$status      = $_GET['status'] ?? '';
$data_ini    = $_GET['data_ini'] ?? '';
$data_fim    = $_GET['data_fim'] ?? '';
$validade_ini = $_GET['validade_ini'] ?? '';
$validade_fim = $_GET['validade_fim'] ?? '';

$status_validos = [
    'pendente',
    'parcialmente_aceito',
    'aceito',
    'parcialmente_recusado',
    'recusado'
];

$status_labels = [
    'pendente' => 'Pendente',
    'parcialmente_aceito' => 'Parcialmente aceito',
    'aceito' => 'Aceito',
    'parcialmente_recusado' => 'Parcialmente recusado',
    'recusado' => 'Recusado'
];

if (!in_array($status, $status_validos, true)) {
    $status = '';
}

/* =========================================================
   WHERE DINÂMICO
   ========================================================= */

$where = ["o.arquivado = 0"];
$params = [];

if ($busca !== '') {
    $where[] = "p.paciente LIKE ?";
    $params[] = "%{$busca}%";
}

if ($id_orcamento !== '' && ctype_digit($id_orcamento)) {
    $where[] = "o.id = ?";
    $params[] = (int)$id_orcamento;
}

if ($status !== '') {
    $where[] = "o.status = ?";
    $params[] = $status;
}

if ($data_ini !== '') {
    $where[] = "o.data_criacao >= ?";
    $params[] = $data_ini;
}

if ($data_fim !== '') {
    $where[] = "o.data_criacao <= ?";
    $params[] = $data_fim;
}

if ($validade_ini !== '') {
    $where[] = "o.validade >= ?";
    $params[] = $validade_ini;
}

if ($validade_fim !== '') {
    $where[] = "o.validade <= ?";
    $params[] = $validade_fim;
}

$where_sql = implode(' AND ', $where);

/* =========================================================
   EXPORTAÇÃO CSV
   ========================================================= */

if (isset($_GET['exportar']) && $_GET['exportar'] === '1') {
    $sql_export = "
        SELECT
            o.id AS `ID`,
            p.paciente AS `Paciente`,
            o.data_criacao AS `Data de criação`,
            o.validade AS `Validade`,
            o.status AS `Status`,
            COALESCE(
                SUM(
                    COALESCE(oi.quantidade, 0) * COALESCE(oi.valor_unitario, 0)
                ),
                0
            ) AS `Valor total`
        FROM orcamentos o
        INNER JOIN prontuarios p
            ON o.paciente_id = p.id
        LEFT JOIN orcamentos_itens oi
            ON oi.orcamento_id = o.id
        WHERE {$where_sql}
        GROUP BY
            o.id,
            p.paciente,
            o.data_criacao,
            o.validade,
            o.status
        ORDER BY o.data_criacao DESC, o.id DESC
    ";

    exportarParaCSV(
        $pdo,
        'Relatório de Orçamentos - Evoluqi',
        $sql_export,
        $params,
        'relatorio_orcamentos'
    );
}

/* =========================================================
   CONSULTA PRINCIPAL
   ========================================================= */

$sql = "
    SELECT
        o.id,
        o.status,
        o.data_criacao,
        o.validade,
        o.arquivado,
        o.arquivado_em,
        p.paciente
    FROM orcamentos o
    INNER JOIN prontuarios p
        ON o.paciente_id = p.id
    WHERE {$where_sql}
    ORDER BY o.data_criacao DESC, o.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orcamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

function dataBRLista(?string $data): string
{
    if (empty($data)) {
        return '—';
    }

    $timestamp = strtotime($data);

    return $timestamp
        ? date('d/m/Y', $timestamp)
        : '—';
}

function filtrosAtivosOrcamento(
    string $busca,
    string $id_orcamento,
    string $status,
    string $data_ini,
    string $data_fim,
    string $validade_ini,
    string $validade_fim,
    array $status_labels
): array {
    $ativos = [];

    if ($busca !== '') {
        $ativos[] = "Paciente: '{$busca}'";
    }

    if ($id_orcamento !== '' && ctype_digit($id_orcamento)) {
        $ativos[] = 'Orçamento: #' . (int)$id_orcamento;
    }

    if ($status !== '' && isset($status_labels[$status])) {
        $ativos[] = 'Status: ' . $status_labels[$status];
    }

    if ($data_ini !== '') {
        $ativos[] = 'Criado de: ' . dataBRLista($data_ini);
    }

    if ($data_fim !== '') {
        $ativos[] = 'Criado até: ' . dataBRLista($data_fim);
    }

    if ($validade_ini !== '') {
        $ativos[] = 'Validade de: ' . dataBRLista($validade_ini);
    }

    if ($validade_fim !== '') {
        $ativos[] = 'Validade até: ' . dataBRLista($validade_fim);
    }

    return $ativos;
}

$filtros_ativos = filtrosAtivosOrcamento(
    $busca,
    $id_orcamento,
    $status,
    $data_ini,
    $data_fim,
    $validade_ini,
    $validade_fim,
    $status_labels
);

$tem_filtros = !empty($filtros_ativos);

?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orçamentos - Evoluqi</title>

    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/variables.css">
    <link rel="stylesheet" href="css/layout.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/orcamento.css">

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="icon" type="image/png" href="img/icon.PNG">
</head>

<body>
    <?php include 'navbar.php'; ?>

    <div class="content">
        <div class="container">
            <h1>Orçamentos</h1>

            <!-- =====================================================
                 FILTROS
            ====================================================== -->
            <div class="filter-bar">
                <form method="GET" class="filter-form">

                    <div class="filter-field filter-field-paciente">
                        <label for="busca">Paciente</label>
                        <input
                            id="busca"
                            type="text"
                            name="busca"
                            placeholder="Nome do paciente..."
                            value="<?= htmlspecialchars($busca) ?>">
                    </div>

                    <div class="filter-field">
                        <label for="id_orcamento">Nº orçamento</label>
                        <input
                            id="id_orcamento"
                            type="number"
                            name="id_orcamento"
                            min="1"
                            placeholder="Ex.: 23"
                            value="<?= htmlspecialchars($id_orcamento) ?>">
                    </div>

                    <div class="filter-field">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="">Todos os status</option>
                            <?php foreach ($status_labels as $valor => $rotulo): ?>
                                <option
                                    value="<?= htmlspecialchars($valor) ?>"
                                    <?= $status === $valor ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($rotulo) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-field">
                        <label for="data_ini">Criação — de</label>
                        <input
                            id="data_ini"
                            type="date"
                            name="data_ini"
                            value="<?= htmlspecialchars($data_ini) ?>">
                    </div>

                    <div class="filter-field">
                        <label for="data_fim">Criação — até</label>
                        <input
                            id="data_fim"
                            type="date"
                            name="data_fim"
                            value="<?= htmlspecialchars($data_fim) ?>">
                    </div>

                    <div class="filter-field">
                        <label for="validade_ini">Validade — de</label>
                        <input
                            id="validade_ini"
                            type="date"
                            name="validade_ini"
                            value="<?= htmlspecialchars($validade_ini) ?>">
                    </div>

                    <div class="filter-field">
                        <label for="validade_fim">Validade — até</label>
                        <input
                            id="validade_fim"
                            type="date"
                            name="validade_fim"
                            value="<?= htmlspecialchars($validade_fim) ?>">
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn-filter">🔍 Filtrar</button>
                        <a href="orcamento.php" class="btn-reset">✖ Limpar</a>
                    </div>
                </form>

                <?php if ($tem_filtros): ?>
                    <div class="filter-info">
                        <strong>Filtros ativos:</strong>
                        <?= htmlspecialchars(implode(' • ', $filtros_ativos)) ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- =====================================================
                 AÇÕES
            ====================================================== -->
            <div class="top-actions">
                <a href="novo_orcamento" class="btn-add">+ Novo Orçamento</a>

                <?php
                $export_params = $_GET;
                $export_params['exportar'] = '1';
                ?>
                <a
                    href="?<?= htmlspecialchars(http_build_query($export_params)) ?>"
                    class="btn-exportar">
                    📥 Exportar CSV
                </a>
            </div>

            <?php if (empty($orcamentos)): ?>
                <p class="estado-vazio">
                    <?= $tem_filtros
                        ? 'Nenhum orçamento encontrado com os filtros aplicados.'
                        : 'Nenhum orçamento cadastrado.' ?>
                </p>
            <?php else: ?>
                <div class="table-wrapper-lista">
                    <table>
                        <thead>
                            <tr>
                                <th>Paciente</th>
                                <th>Data</th>
                                <th>Validade</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orcamentos as $o): ?>
                                <?php
                                $status_item = $o['status'] ?? 'pendente';
                                $status_label = $status_labels[$status_item] ?? 'Pendente';
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($o['paciente']) ?></td>

                                    <td>
                                        <?= dataBRLista($o['data_criacao']) ?>
                                    </td>

                                    <td>
                                        <?= dataBRLista($o['validade']) ?>
                                    </td>

                                    <td>
                                        <span class="status-badge status-<?= htmlspecialchars($status_item) ?>">
                                            <?= htmlspecialchars($status_label) ?>
                                        </span>
                                    </td>

                                    <td class="acoes">
                                        <a
                                            href="visualizar_orcamento.php?id=<?= (int)$o['id'] ?>"
                                            class="acao-icone acao-visualizar"
                                            aria-label="Visualizar orçamento #<?= (int)$o['id'] ?>"
                                            title="Visualizar">
                                            <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                        </a>

                                        <?php if ($status_item === 'pendente'): ?>
                                            <a
                                                href="editar_orcamento.php?id=<?= (int)$o['id'] ?>"
                                                class="acao-icone acao-editar"
                                                aria-label="Editar orçamento #<?= (int)$o['id'] ?>"
                                                title="Editar">
                                                <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                            </a>

                                            <form
                                                method="POST"
                                                action="aceitar_orcamento.php"
                                                onsubmit="return confirm('Aceitar este orçamento?')">
                                                <?= csrf_field() ?>
                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= (int)$o['id'] ?>">
                                                <button
                                                    type="submit"
                                                    class="acao-icone acao-aceitar"
                                                    aria-label="Aceitar orçamento #<?= (int)$o['id'] ?>"
                                                    title="Aceitar">
                                                    <i class="fa-solid fa-check" aria-hidden="true"></i>
                                                </button>
                                            </form>

                                            <form
                                                method="POST"
                                                action="recusar_orcamento.php"
                                                onsubmit="return confirm('Recusar este orçamento?')">
                                                <?= csrf_field() ?>
                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= (int)$o['id'] ?>">
                                                <button
                                                    type="submit"
                                                    class="acao-icone acao-recusar"
                                                    aria-label="Recusar orçamento #<?= (int)$o['id'] ?>"
                                                    title="Recusar">
                                                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                                </button>
                                            </form>

                                            <?php if ((int)$o['arquivado'] === 0): ?>
                                                <form
                                                    method="POST"
                                                    action="arquivar_orcamento.php"
                                                    class="form-acao"
                                                    onsubmit="return confirm('Arquivar este orçamento? Ele será retirado da listagem principal, mas seu histórico será preservado.');">
                                                    <?= csrf_field() ?>
                                                    <input
                                                        type="hidden"
                                                        name="id"
                                                        value="<?= (int)$o['id'] ?>">
                                                    <button
                                                        type="submit"
                                                        class="acao-icone acao-arquivar"
                                                        aria-label="Arquivar orçamento #<?= (int)$o['id'] ?>"
                                                        title="Arquivar">
                                                        <i class="fa-solid fa-box-archive" aria-hidden="true"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>