<?php

require_once 'config/auth.php';
require_once 'config/csrf.php';
require_once 'conexao/conexao.php';

exigirLogin();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0) {
    header('Location: plano_tratamento.php?erro=invalido');
    exit;
}

$erro = null;

/*
|--------------------------------------------------------------------------
| PLANO
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        paciente_id,
        titulo,
        descricao,
        status
    FROM planos_tratamento
    WHERE id = ?
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
| DADOS DE APOIO
|--------------------------------------------------------------------------
*/

$pacientes = $pdo->query("
    SELECT id, paciente
    FROM prontuarios
    ORDER BY paciente ASC
")->fetchAll(PDO::FETCH_ASSOC);

$servicos = $pdo->query("
    SELECT
        id,
        nome,
        descricao,
        valor_sugerido
    FROM servicos
    WHERE ativo = 1
    ORDER BY nome ASC
")->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| ITENS ATUAIS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        servico_id,
        descricao,
        dente_regiao,
        prioridade,
        valor_estimado,
        status,
        ordem,
        observacoes
    FROM planos_tratamento_itens
    WHERE plano_id = ?
    ORDER BY ordem ASC, id ASC
");

$stmt->execute([$id]);

$itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| SALVAR
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    validar_csrf();

    try {

        $pacienteId = (int)($_POST['paciente_id'] ?? 0);
        $titulo = trim($_POST['titulo'] ?? '');
        $descricaoPlano = trim($_POST['descricao'] ?? '');
        $status = $_POST['status'] ?? 'planejamento';

        $itemIds = $_POST['item_id'] ?? [];
        $servicoIds = $_POST['servico_id'] ?? [];
        $descricoes = $_POST['item_descricao'] ?? [];
        $dentes = $_POST['dente_regiao'] ?? [];
        $prioridades = $_POST['prioridade'] ?? [];
        $valores = $_POST['valor_estimado'] ?? [];
        $statusItens = $_POST['status_item'] ?? [];
        $observacoesItens = $_POST['observacoes_item'] ?? [];

        $statusPermitidos = [
            'planejamento',
            'em_andamento',
            'concluido',
            'cancelado'
        ];

        if ($pacienteId <= 0) {
            throw new Exception('Selecione um paciente.');
        }

        if ($titulo === '') {
            throw new Exception(
                'Informe o título do plano de tratamento.'
            );
        }

        if (mb_strlen($titulo) > 255) {
            throw new Exception(
                'O título do plano pode ter no máximo 255 caracteres.'
            );
        }

        if (!in_array($status, $statusPermitidos, true)) {
            throw new Exception(
                'Status do plano inválido.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validar paciente
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT id
            FROM prontuarios
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$pacienteId]);

        if (!$stmt->fetchColumn()) {
            throw new Exception(
                'O paciente selecionado não foi encontrado.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Montar itens
        |--------------------------------------------------------------------------
        */

        $itensNovos = [];

        $totalItens = max(
            count($itemIds),
            count($servicoIds),
            count($descricoes),
            count($dentes),
            count($prioridades),
            count($valores),
            count($statusItens),
            count($observacoesItens)
        );

        for ($i = 0; $i < $totalItens; $i++) {

            $itemId = (int)($itemIds[$i] ?? 0);
            $servicoId = (int)($servicoIds[$i] ?? 0);
            $descricaoItem = trim($descricoes[$i] ?? '');
            $denteRegiao = trim($dentes[$i] ?? '');
            $prioridade = $prioridades[$i] ?? 'media';
            $valorBruto = trim($valores[$i] ?? '');
            $statusItem = $statusItens[$i] ?? 'planejado';
            $observacaoItem = trim($observacoesItens[$i] ?? '');

            /*
            |--------------------------------------------------------------------------
            | Serviço
            |--------------------------------------------------------------------------
            */

            if ($servicoId > 0) {

                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        nome
                    FROM servicos
                    WHERE id = ?
                    LIMIT 1
                ");

                $stmt->execute([$servicoId]);

                $servico = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$servico) {
                    throw new Exception(
                        'Um dos serviços selecionados não foi encontrado.'
                    );
                }

                if ($descricaoItem === '') {
                    $descricaoItem = $servico['nome'];
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Valor
            |--------------------------------------------------------------------------
            */

            if (strpos($valorBruto, ',') !== false) {
                $valorBruto = str_replace('.', '', $valorBruto);
                $valorBruto = str_replace(',', '.', $valorBruto);
            }

            $valorEstimado = (float)$valorBruto;

            /*
            |--------------------------------------------------------------------------
            | Status/prioridade
            |--------------------------------------------------------------------------
            */

            $prioridadesPermitidas = [
                'baixa',
                'media',
                'alta'
            ];

            if (!in_array($prioridade, $prioridadesPermitidas, true)) {
                $prioridade = 'media';
            }

            $statusItensPermitidos = [
                'planejado',
                'em_andamento',
                'concluido',
                'cancelado'
            ];

            if (!in_array($statusItem, $statusItensPermitidos, true)) {
                $statusItem = 'planejado';
            }

            /*
            |--------------------------------------------------------------------------
            | Ignorar linha completamente vazia
            |--------------------------------------------------------------------------
            */

            if (
                $servicoId <= 0 &&
                $descricaoItem === '' &&
                $denteRegiao === '' &&
                $valorBruto === '' &&
                $observacaoItem === ''
            ) {
                continue;
            }

            if ($descricaoItem === '') {
                throw new Exception(
                    'Informe a descrição de cada etapa preenchida.'
                );
            }

            if ($valorEstimado < 0) {
                throw new Exception(
                    'O valor estimado não pode ser negativo.'
                );
            }

            $itensNovos[] = [
                'id' => $itemId > 0 ? $itemId : null,
                'servico_id' => $servicoId > 0 ? $servicoId : null,
                'descricao' => $descricaoItem,
                'dente_regiao' => $denteRegiao !== ''
                    ? $denteRegiao
                    : null,
                'prioridade' => $prioridade,
                'valor_estimado' => $valorEstimado,
                'status' => $statusItem,
                'ordem' => count($itensNovos) + 1,
                'observacoes' => $observacaoItem !== ''
                    ? $observacaoItem
                    : null,
            ];
        }

        if (empty($itensNovos)) {
            throw new Exception(
                'O plano precisa ter pelo menos uma etapa.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | TRANSAÇÃO E PRESERVAÇÃO DOS IDs
        |--------------------------------------------------------------------------
        */

        $pdo->beginTransaction();

        // Bloqueia o plano durante a edição para evitar alterações concorrentes.
        $stmt = $pdo->prepare("SELECT paciente_id FROM planos_tratamento WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $pacienteAtualId = (int)$stmt->fetchColumn();

        // Não permite trocar o paciente quando já existem vínculos clínicos.
        if ($pacienteId !== $pacienteAtualId) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM odontograma_tratamentos_dentes otd
                INNER JOIN odontograma_dentes od ON od.id = otd.odontograma_dente_id
                INNER JOIN planos_tratamento_itens pti ON pti.id = otd.plano_tratamento_item_id
                WHERE pti.plano_id = ? AND od.prontuario_id = ?
            ");
            $stmt->execute([$id, $pacienteAtualId]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new Exception('Não é possível trocar o paciente deste plano porque existem tratamentos vinculados ao odontograma.');
            }
        }

        $stmt = $pdo->prepare("
            UPDATE planos_tratamento
            SET paciente_id = ?, titulo = ?, descricao = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $pacienteId,
            $titulo,
            $descricaoPlano !== '' ? $descricaoPlano : null,
            $status,
            $id
        ]);

        // Carrega os IDs que realmente pertencem a este plano.
        $stmt = $pdo->prepare("SELECT id FROM planos_tratamento_itens WHERE plano_id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $idsAtuais = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $idsEnviados = [];

        foreach ($itensNovos as $item) {
            if ($item['id'] === null) {
                continue;
            }
            if (!in_array($item['id'], $idsAtuais, true)) {
                throw new Exception('Uma das etapas informadas não pertence a este plano. Atualize a página e tente novamente.');
            }
            if (in_array($item['id'], $idsEnviados, true)) {
                throw new Exception('Uma etapa foi enviada mais de uma vez. Atualize a página e tente novamente.');
            }
            $idsEnviados[] = $item['id'];
        }

        $stmtUpdate = $pdo->prepare("
            UPDATE planos_tratamento_itens
            SET servico_id = ?, descricao = ?, dente_regiao = ?, prioridade = ?,
                valor_estimado = ?, status = ?, ordem = ?, observacoes = ?
            WHERE id = ? AND plano_id = ?
        ");

        $stmtInsert = $pdo->prepare("
            INSERT INTO planos_tratamento_itens (
                plano_id, servico_id, descricao, dente_regiao, prioridade,
                valor_estimado, status, ordem, observacoes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($itensNovos as $item) {
            $dados = [
                $item['servico_id'],
                $item['descricao'],
                $item['dente_regiao'],
                $item['prioridade'],
                $item['valor_estimado'],
                $item['status'],
                $item['ordem'],
                $item['observacoes']
            ];

            if ($item['id'] !== null) {
                $stmtUpdate->execute(array_merge($dados, [$item['id'], $id]));
            } else {
                $stmtInsert->execute(array_merge([$id], $dados));
            }
        }

        // Etapas removidas do formulário devem ser excluídas definitivamente.
        // Se houver qualquer registro associado no odontograma, bloqueia a operação.
        $idsRemovidos = array_values(array_diff($idsAtuais, $idsEnviados));

        if ($idsRemovidos) {
            $stmtVinculos = $pdo->prepare("
                SELECT
                    (SELECT COUNT(*) FROM odontograma_tratamentos_dentes
                     WHERE plano_tratamento_item_id = ?) +
                    (SELECT COUNT(*) FROM odontograma_registros
                     WHERE plano_tratamento_item_id = ?) +
                    (SELECT COUNT(*) FROM odontograma_tratamentos_gerais
                     WHERE plano_tratamento_item_id = ?) AS total
            ");

            $stmtExcluir = $pdo->prepare("
                DELETE FROM planos_tratamento_itens
                WHERE id = ? AND plano_id = ?
            ");

            foreach ($idsRemovidos as $idRemovido) {
                $stmtVinculos->execute([
                    $idRemovido,
                    $idRemovido,
                    $idRemovido
                ]);

                $totalVinculos = (int)$stmtVinculos->fetchColumn();

                if ($totalVinculos > 0) {
                    throw new Exception(
                        'Não foi possível excluir esta etapa porque ela possui '
                        . 'registro(s) vinculado(s) ao odontograma. Abra o prontuário, '
                        . 'localize o vínculo destacado e exclua-o definitivamente '
                        . 'antes de remover a etapa do plano.'
                    );
                }

                $stmtExcluir->execute([$idRemovido, $id]);
            }
        }

        $pdo->commit();

        header(
            'Location: visualizar_plano_tratamento.php?id=' .
                $id .
                '&sucesso=editado'
        );

        exit;
    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $erro = $e->getMessage();

        error_log(
            'ERRO EDITAR PLANO TRATAMENTO: ' .
                $e->getMessage()
        );

        /*
        |--------------------------------------------------------------------------
        | Recarregar os valores digitados em caso de erro
        |--------------------------------------------------------------------------
        */

        $plano['paciente_id'] =
            $_POST['paciente_id']
            ?? $plano['paciente_id'];

        $plano['titulo'] =
            $_POST['titulo']
            ?? $plano['titulo'];

        $plano['descricao'] =
            $_POST['descricao']
            ?? $plano['descricao'];

        $plano['status'] =
            $_POST['status']
            ?? $plano['status'];

        $itens = [];

        $totalItens = max(
            count($itemIds),
            count($servicoIds),
            count($descricoes),
            count($dentes),
            count($prioridades),
            count($valores),
            count($statusItens),
            count($observacoesItens)
        );

        for ($i = 0; $i < $totalItens; $i++) {

            $itens[] = [
                'id' => (int)($itemIds[$i] ?? 0),
                'servico_id' => (int)($servicoIds[$i] ?? 0),
                'descricao' => trim($descricoes[$i] ?? ''),
                'dente_regiao' => trim($dentes[$i] ?? ''),
                'prioridade' => $prioridades[$i] ?? 'media',
                'valor_estimado' => $valores[$i] ?? '',
                'status' => $statusItens[$i] ?? 'planejado',
                'observacoes' => trim(
                    $observacoesItens[$i] ?? ''
                )
            ];
        }
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>
        Editar Plano de Tratamento - Dentech
    </title>

    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/variables.css">
    <link rel="stylesheet" href="css/layout.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/editar_plano_tratamento.css">

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

    <main class="editar-plano-page">

        <div class="editar-plano-container">

            <header class="page-header">

                <div>

                    <span class="page-kicker">
                        PLANEJAMENTO CLÍNICO
                    </span>

                    <h1>
                        Editar plano de tratamento
                    </h1>

                    <p>
                        Atualize os dados do planejamento e suas etapas.
                    </p>

                </div>

                <div class="header-actions">

                    <a
                        href="visualizar_plano_tratamento.php?id=<?= $id ?>"
                        class="btn btn-outline">
                        <i class="fa-solid fa-arrow-left"></i>
                        Voltar
                    </a>

                </div>

            </header>

            <?php if (!empty($erro)): ?>

                <div class="message message-error">

                    <i class="fa-solid fa-circle-exclamation"></i>

                    <span>
                        <?= htmlspecialchars($erro) ?>
                    </span>

                </div>

            <?php endif; ?>

            <form
                method="POST"
                id="formPlano"
                class="form-card">

                <?= csrf_field() ?>

                <input
                    type="hidden"
                    name="id"
                    value="<?= $id ?>">

                <section class="section-card">

                    <div class="section-header">

                        <div>

                            <span class="section-kicker">
                                PLANO
                            </span>

                            <h2>
                                Dados do planejamento
                            </h2>

                        </div>

                    </div>

                    <div class="form-grid">

                        <div class="form-group form-group-full">

                            <label for="paciente_id">
                                Paciente <span>*</span>
                            </label>

                            <select
                                id="paciente_id"
                                name="paciente_id"
                                required>

                                <?php foreach (
                                    $pacientes as $paciente
                                ): ?>

                                    <option
                                        value="<?= (int)$paciente['id'] ?>"
                                        <?= (
                                            (string)$plano['paciente_id']
                                            === (string)$paciente['id']
                                        )
                                            ? 'selected'
                                            : '' ?>>

                                        <?= htmlspecialchars(
                                            $paciente['paciente']
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="form-group">

                            <label for="titulo">
                                Título do plano <span>*</span>
                            </label>

                            <input
                                type="text"
                                id="titulo"
                                name="titulo"
                                maxlength="255"
                                value="<?= htmlspecialchars(
                                            $plano['titulo']
                                        ) ?>"
                                required>

                        </div>

                        <div class="form-group">

                            <label for="status">
                                Status
                            </label>

                            <select
                                id="status"
                                name="status">

                                <option
                                    value="planejamento"
                                    <?= $plano['status']
                                        === 'planejamento'
                                        ? 'selected'
                                        : '' ?>>
                                    Planejamento
                                </option>

                                <option
                                    value="em_andamento"
                                    <?= $plano['status']
                                        === 'em_andamento'
                                        ? 'selected'
                                        : '' ?>>
                                    Em andamento
                                </option>

                                <option
                                    value="concluido"
                                    <?= $plano['status']
                                        === 'concluido'
                                        ? 'selected'
                                        : '' ?>>
                                    Concluído
                                </option>

                                <option
                                    value="cancelado"
                                    <?= $plano['status']
                                        === 'cancelado'
                                        ? 'selected'
                                        : '' ?>>
                                    Cancelado
                                </option>

                            </select>

                        </div>

                        <div class="form-group form-group-full">

                            <label for="descricao">
                                Descrição geral
                            </label>

                            <textarea
                                id="descricao"
                                name="descricao"
                                rows="4"><?= htmlspecialchars(
                                                $plano['descricao'] ?? ''
                                            ) ?></textarea>

                        </div>

                    </div>

                </section>

                <section class="section-card">

                    <div class="section-header">

                        <div>

                            <span class="section-kicker">
                                ETAPAS
                            </span>

                            <h2>
                                Etapas do tratamento
                            </h2>

                            <p>
                                O valor estimado é independente do
                                preço atual do catálogo.
                            </p>

                        </div>

                    </div>

                    <div
                        id="itens-container"
                        class="itens-container">

                        <?php foreach (
                            $itens as $item
                        ): ?>

                            <div class="tratamento-item">

                                <input type="hidden" name="item_id[]" value="<?= (int)$item['id'] ?>">

                                <div class="item-field item-service">

                                    <label>
                                        Serviço
                                    </label>

                                    <select
                                        name="servico_id[]"
                                        class="servico-select"
                                        onchange="preencherEtapa(this)">

                                        <option value="">
                                            Serviço personalizado
                                        </option>

                                        <?php foreach (
                                            $servicos as $servico
                                        ): ?>

                                            <option
                                                value="<?= (int)$servico['id'] ?>"
                                                data-nome="<?= htmlspecialchars(
                                                                $servico['nome'],
                                                                ENT_QUOTES
                                                            ) ?>"
                                                data-valor="<?= htmlspecialchars(
                                                                (string)$servico['valor_sugerido'],
                                                                ENT_QUOTES
                                                            ) ?>"
                                                <?= (
                                                    (int)($item['servico_id'] ?? 0)
                                                    === (int)$servico['id']
                                                )
                                                    ? 'selected'
                                                    : '' ?>>

                                                <?= htmlspecialchars(
                                                    $servico['nome']
                                                ) ?>
                                                — R$
                                                <?= number_format(
                                                    (float)$servico['valor_sugerido'],
                                                    2,
                                                    ',',
                                                    '.'
                                                ) ?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="item-field item-description">

                                    <label>
                                        Descrição <span>*</span>
                                    </label>

                                    <input
                                        type="text"
                                        name="item_descricao[]"
                                        value="<?= htmlspecialchars(
                                                    $item['descricao'] ?? ''
                                                ) ?>"
                                        required>

                                </div>

                                <div class="item-field item-region">

                                    <label>
                                        Dente / região
                                    </label>

                                    <input
                                        type="text"
                                        name="dente_regiao[]"
                                        value="<?= htmlspecialchars(
                                                    $item['dente_regiao'] ?? ''
                                                ) ?>">

                                </div>

                                <div class="item-field item-priority">

                                    <label>
                                        Prioridade
                                    </label>

                                    <select name="prioridade[]">

                                        <option
                                            value="baixa"
                                            <?= $item['prioridade']
                                                === 'baixa'
                                                ? 'selected'
                                                : '' ?>>
                                            Baixa
                                        </option>

                                        <option
                                            value="media"
                                            <?= $item['prioridade']
                                                === 'media'
                                                ? 'selected'
                                                : '' ?>>
                                            Média
                                        </option>

                                        <option
                                            value="alta"
                                            <?= $item['prioridade']
                                                === 'alta'
                                                ? 'selected'
                                                : '' ?>>
                                            Alta
                                        </option>

                                    </select>

                                </div>

                                <div class="item-field item-value">

                                    <label>
                                        Valor estimado
                                    </label>

                                    <div class="money-input">

                                        <span>
                                            R$
                                        </span>

                                        <input
                                            type="number"
                                            name="valor_estimado[]"
                                            step="0.01"
                                            min="0"
                                            value="<?= htmlspecialchars(
                                                        (string)$item['valor_estimado']
                                                    ) ?>"
                                            required>

                                    </div>

                                </div>

                                <div class="item-field item-status">

                                    <label>
                                        Status da etapa
                                    </label>

                                    <select name="status_item[]">

                                        <option
                                            value="planejado"
                                            <?= $item['status']
                                                === 'planejado'
                                                ? 'selected'
                                                : '' ?>>
                                            Planejado
                                        </option>

                                        <option
                                            value="em_andamento"
                                            <?= $item['status']
                                                === 'em_andamento'
                                                ? 'selected'
                                                : '' ?>>
                                            Em andamento
                                        </option>

                                        <option
                                            value="concluido"
                                            <?= $item['status']
                                                === 'concluido'
                                                ? 'selected'
                                                : '' ?>>
                                            Concluído
                                        </option>

                                        <option
                                            value="cancelado"
                                            <?= $item['status']
                                                === 'cancelado'
                                                ? 'selected'
                                                : '' ?>>
                                            Cancelado
                                        </option>

                                    </select>

                                </div>

                                <div class="item-field item-observation">

                                    <label>
                                        Observações
                                    </label>

                                    <input
                                        type="text"
                                        name="observacoes_item[]"
                                        value="<?= htmlspecialchars(
                                                    $item['observacoes'] ?? ''
                                                ) ?>"
                                        placeholder="Observação da etapa">

                                </div>

                                <button
                                    type="button"
                                    class="btn-remove-item"
                                    onclick="removerEtapa(this)"
                                    title="Excluir etapa">
                                    <i class="fa-solid fa-trash"></i>
                                </button>

                            </div>

                        <?php endforeach; ?>

                        <?php if (empty($itens)): ?>

                            <div class="tratamento-item">

                                <input type="hidden" name="item_id[]" value="">

                                <div class="item-field item-service">

                                    <label>
                                        Serviço
                                    </label>

                                    <select
                                        name="servico_id[]"
                                        class="servico-select"
                                        onchange="preencherEtapa(this)">

                                        <option value="">
                                            Serviço personalizado
                                        </option>

                                        <?php foreach (
                                            $servicos as $servico
                                        ): ?>

                                            <option
                                                value="<?= (int)$servico['id'] ?>"
                                                data-nome="<?= htmlspecialchars(
                                                                $servico['nome'],
                                                                ENT_QUOTES
                                                            ) ?>"
                                                data-valor="<?= htmlspecialchars(
                                                                (string)$servico['valor_sugerido'],
                                                                ENT_QUOTES
                                                            ) ?>">
                                                <?= htmlspecialchars(
                                                    $servico['nome']
                                                ) ?>
                                                — R$
                                                <?= number_format(
                                                    (float)$servico['valor_sugerido'],
                                                    2,
                                                    ',',
                                                    '.'
                                                ) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="item-field item-description">

                                    <label>
                                        Descrição <span>*</span>
                                    </label>

                                    <input
                                        type="text"
                                        name="item_descricao[]"
                                        required>

                                </div>

                                <div class="item-field item-region">

                                    <label>
                                        Dente / região
                                    </label>

                                    <input
                                        type="text"
                                        name="dente_regiao[]">

                                </div>

                                <div class="item-field item-priority">

                                    <label>
                                        Prioridade
                                    </label>

                                    <select name="prioridade[]">

                                        <option value="baixa">
                                            Baixa
                                        </option>

                                        <option
                                            value="media"
                                            selected>
                                            Média
                                        </option>

                                        <option value="alta">
                                            Alta
                                        </option>

                                    </select>

                                </div>

                                <div class="item-field item-value">

                                    <label>
                                        Valor estimado
                                    </label>

                                    <div class="money-input">

                                        <span>R$</span>

                                        <input
                                            type="number"
                                            name="valor_estimado[]"
                                            step="0.01"
                                            min="0"
                                            placeholder="0,00"
                                            required>

                                    </div>

                                </div>

                                <div class="item-field item-status">

                                    <label>
                                        Status da etapa
                                    </label>

                                    <select name="status_item[]">

                                        <option
                                            value="planejado"
                                            selected>
                                            Planejado
                                        </option>

                                        <option value="em_andamento">
                                            Em andamento
                                        </option>

                                        <option value="concluido">
                                            Concluído
                                        </option>

                                        <option value="cancelado">
                                            Cancelado
                                        </option>

                                    </select>

                                </div>

                                <div class="item-field item-observation">

                                    <label>
                                        Observações
                                    </label>

                                    <input
                                        type="text"
                                        name="observacoes_item[]"
                                        placeholder="Observação da etapa">

                                </div>

                                <button
                                    type="button"
                                    class="btn-remove-item"
                                    onclick="removerEtapa(this)"
                                    title="Excluir etapa">
                                    <i class="fa-solid fa-trash"></i>
                                </button>

                            </div>

                        <?php endif; ?>

                    </div>

                    <button
                        type="button"
                        class="btn-add-item"
                        onclick="adicionarEtapa()">
                        <i class="fa-solid fa-plus"></i>
                        Adicionar etapa
                    </button>

                </section>

                <div class="form-actions">

                    <a
                        href="visualizar_plano_tratamento.php?id=<?= $id ?>"
                        class="btn btn-cancel">
                        Cancelar
                    </a>

                    <button
                        type="submit"
                        class="btn btn-primary">
                        <i class="fa-solid fa-check"></i>
                        Salvar alterações
                    </button>

                </div>

            </form>

        </div>

    </main>

    <script>
        const servicosCatalogo = <?= json_encode(
                                        array_map(
                                            static function ($servico) {
                                                return [
                                                    'id' => (int)$servico['id'],
                                                    'nome' => $servico['nome'],
                                                    'valor' => (float)$servico['valor_sugerido'],
                                                ];
                                            },
                                            $servicos
                                        ),
                                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                    ) ?>;

        function escaparHtml(valor) {
            return String(valor ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function obterOpcoesServicos() {

            return servicosCatalogo
                .map(servico => `
            <option
                value="${Number(servico.id)}"
                data-nome="${escaparHtml(servico.nome)}"
                data-valor="${Number(servico.valor)}"
            >
                ${escaparHtml(servico.nome)}
                — R$
                ${Number(servico.valor)
                    .toFixed(2)
                    .replace('.', ',')}
            </option>
        `)
                .join('');
        }

        function preencherEtapa(select) {

            const item =
                select.closest('.tratamento-item');

            if (!item) {
                return;
            }

            const descricao =
                item.querySelector('[name="item_descricao[]"]');

            const valor =
                item.querySelector('[name="valor_estimado[]"]');

            if (!descricao || !valor) {
                return;
            }

            if (!select.value) {
                return;
            }

            const option =
                select.options[select.selectedIndex];

            if (descricao.value.trim() === '') {
                descricao.value =
                    option.dataset.nome || '';
            }

            if (
                valor.value === '' ||
                Number(valor.value) === 0
            ) {
                valor.value =
                    Number(option.dataset.valor || 0)
                    .toFixed(2);
            }
        }

        function adicionarEtapa() {

            const container =
                document.getElementById('itens-container');

            const div =
                document.createElement('div');

            div.className =
                'tratamento-item';

            div.innerHTML = `

        <input type="hidden" name="item_id[]" value="">

        <div class="item-field item-service">

            <label>
                Serviço
            </label>

            <select
                name="servico_id[]"
                class="servico-select"
                onchange="preencherEtapa(this)"
            >

                <option value="">
                    Serviço personalizado
                </option>

                ${obterOpcoesServicos()}

            </select>

        </div>

        <div class="item-field item-description">

            <label>
                Descrição <span>*</span>
            </label>

            <input
                type="text"
                name="item_descricao[]"
                placeholder="Descrição da etapa"
                required
            >

        </div>

        <div class="item-field item-region">

            <label>
                Dente / região
            </label>

            <input
                type="text"
                name="dente_regiao[]"
                placeholder="Ex.: 16"
            >

        </div>

        <div class="item-field item-priority">

            <label>
                Prioridade
            </label>

            <select name="prioridade[]">

                <option value="baixa">
                    Baixa
                </option>

                <option value="media" selected>
                    Média
                </option>

                <option value="alta">
                    Alta
                </option>

            </select>

        </div>

        <div class="item-field item-value">

            <label>
                Valor estimado
            </label>

            <div class="money-input">

                <span>R$</span>

                <input
                    type="number"
                    name="valor_estimado[]"
                    step="0.01"
                    min="0"
                    placeholder="0,00"
                    required
                >

            </div>

        </div>

        <div class="item-field item-status">

            <label>
                Status da etapa
            </label>

            <select name="status_item[]">

                <option value="planejado" selected>
                    Planejado
                </option>

                <option value="em_andamento">
                    Em andamento
                </option>

                <option value="concluido">
                    Concluído
                </option>

                <option value="cancelado">
                    Cancelado
                </option>

            </select>

        </div>

        <div class="item-field item-observation">

            <label>
                Observações
            </label>

            <input
                type="text"
                name="observacoes_item[]"
                placeholder="Observação da etapa"
            >

        </div>

        <button
            type="button"
            class="btn-remove-item"
            onclick="removerEtapa(this)"
            title="Excluir etapa"
        >
            <i class="fa-solid fa-trash"></i>
        </button>
    `;

            container.appendChild(div);

            div.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
        }

        function removerEtapa(botao) {

            const container =
                document.getElementById('itens-container');

            const itens =
                container.querySelectorAll('.tratamento-item');

            if (itens.length <= 1) {

                const primeiro = itens[0];

                if (!primeiro) {
                    return;
                }

                primeiro.querySelector(
                    '[name="servico_id[]"]'
                ).value = '';

                primeiro.querySelector(
                    '[name="item_descricao[]"]'
                ).value = '';

                primeiro.querySelector(
                    '[name="dente_regiao[]"]'
                ).value = '';

                primeiro.querySelector(
                    '[name="prioridade[]"]'
                ).value = 'media';

                primeiro.querySelector(
                    '[name="valor_estimado[]"]'
                ).value = '';

                primeiro.querySelector(
                    '[name="status_item[]"]'
                ).value = 'planejado';

                primeiro.querySelector(
                    '[name="observacoes_item[]"]'
                ).value = '';

                return;
            }

            botao
                .closest('.tratamento-item')
                .remove();
        }
    </script>

</body>

</html>