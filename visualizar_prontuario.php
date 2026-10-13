<?php

require_once 'config/auth.php';
exigirLogin();

require_once 'conexao/conexao.php';

/*
|--------------------------------------------------------------------------
| ARQUIVAR PROCEDIMENTO
|--------------------------------------------------------------------------
| O procedimento é mantido no histórico.
| Os materiais utilizados NÃO são devolvidos ao estoque,
| pois o procedimento já foi realizado.
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_procedimento') {
    try {
        if (
            empty($_POST['csrf_token']) ||
            empty($_SESSION['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            throw new Exception('Token de segurança inválido.');
        }

        $procedimentoExcluir = (int)($_POST['procedimento_id'] ?? 0);
        $prontuarioExcluir = (int)($_POST['prontuario_id'] ?? 0);
        if ($procedimentoExcluir <= 0 || $prontuarioExcluir <= 0) {
            throw new Exception('Procedimento inválido.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
    SELECT id, arquivado
    FROM procedimentos
    WHERE id = ?
      AND paciente_id = ?
    LIMIT 1
    FOR UPDATE
");

        $stmt->execute([
            $procedimentoExcluir,
            $prontuarioExcluir
        ]);

        $procedimento = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$procedimento) {
            throw new Exception('Procedimento não encontrado.');
        }

        if ((int)$procedimento['arquivado'] === 1) {
            throw new Exception('Este procedimento já está arquivado.');
        }

        $stmt = $pdo->prepare("
    UPDATE procedimentos
    SET
        arquivado = 1,
        arquivado_em = NOW()
    WHERE id = ?
      AND arquivado = 0
");

        $stmt->execute([$procedimentoExcluir]);

        if ($stmt->rowCount() === 0) {
            throw new Exception('Procedimento não encontrado ou já arquivado.');
        }
        $pdo->commit();

        header('Location: visualizar_prontuario.php?id=' . $prontuarioExcluir);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        /*
        |--------------------------------------------------------------------------
        | Não expor erros internos do banco ao usuário.
        |
        | Quando o procedimento possui cobranças/parcelas vinculadas,
        | a FK do banco impede a exclusão. Nesse caso, redirecionamos
        | para o prontuário e mostramos uma mensagem amigável.
        |--------------------------------------------------------------------------
        */
        $codigoErro = 'generico';

        if (
            $e instanceof PDOException
            && $e->getCode() === '23000'
            && (int)$e->errorInfo[1] === 1451
        ) {
            $codigoErro = 'procedimento_vinculado';
        }

        if ($prontuarioExcluir > 0) {
            header(
                'Location: visualizar_prontuario.php?id='
                    . $prontuarioExcluir
                    . '&erro='
                    . $codigoErro
            );
            exit;
        }

        http_response_code(400);
        die('Não foi possível concluir a operação.');
    }
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Prontuário não encontrado.");
}

$id = (int) $_GET['id'];

$mensagemErro = null;
$tipoMensagemErro = null;

if (isset($_GET['erro'])) {
    $erro = (string)$_GET['erro'];

    if ($erro === 'generico') {
        $mensagemErro =
            'Não foi possível arquivar o procedimento. Tente novamente.';
        $tipoMensagemErro = 'error';
    }
}

$isPrint = isset($_GET['print']) && $_GET['print'] == '1';

/*
|--------------------------------------------------------------------------
| BUSCAR PRONTUÁRIO
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM prontuarios
    WHERE id = ?
");

$stmt->execute([$id]);

$prontuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$prontuario) {
    die("Prontuário não encontrado.");
}

// Buscar consentimento do prontuário
$stmtConsentimento = $pdo->prepare("
    SELECT aceito, data_aceite
    FROM consentimentos
    WHERE prontuario_id = ?
    ORDER BY id DESC
    LIMIT 1
");

$stmtConsentimento->execute([$id]);
$consentimento = $stmtConsentimento->fetch(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| CALCULAR IDADE
|--------------------------------------------------------------------------
*/

$dataNasc = new DateTime($prontuario['nascimento']);
$hoje = new DateTime();

$idade = $hoje->diff($dataNasc)->y;

/*
|--------------------------------------------------------------------------
| INICIAIS DO PACIENTE
|--------------------------------------------------------------------------
*/

$nomeCompleto = trim($prontuario['paciente']);

$partesNome = preg_split('/\s+/', $nomeCompleto);

if (count($partesNome) >= 2) {
    $iniciais =
        mb_substr($partesNome[0], 0, 1) .
        mb_substr($partesNome[count($partesNome) - 1], 0, 1);
} else {
    $iniciais = mb_substr($nomeCompleto, 0, 2);
}

$iniciais = mb_strtoupper($iniciais);

/*
|--------------------------------------------------------------------------
| BUSCAR PROCEDIMENTOS
|--------------------------------------------------------------------------
*/

$stmtProc = $pdo->prepare("
    SELECT
        p.*
    FROM procedimentos p
    WHERE p.paciente_id = ?
      AND p.arquivado = 0
    ORDER BY p.data_procedimento DESC
");

$stmtProc->execute([$id]);

$procedimentos = $stmtProc->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| BUSCAR ODONTOGRAMA
|--------------------------------------------------------------------------
*/

$stmtOdontograma = $pdo->prepare("
    SELECT
        id,
        numero_dente,
        denticao,
        estado_geral,
        implante_status,
        observacoes
    FROM odontograma_dentes
    WHERE prontuario_id = ?
    ORDER BY CAST(numero_dente AS UNSIGNED)
");

$stmtOdontograma->execute([$id]);

$odontogramaDentes = $stmtOdontograma->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| HISTÓRICO DO ODONTOGRAMA
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| HISTÓRICO DAS SUPERFÍCIES
|--------------------------------------------------------------------------
*/

$stmtOdontogramaRegistros = $pdo->prepare("
    SELECT
        r.id,
        r.odontograma_dente_id,
        r.superficie,
        r.condicao,
        r.observacao,
        r.procedimento_id,
        r.plano_tratamento_item_id,
        r.data_registro,
        r.arquivado
    FROM odontograma_registros r
    INNER JOIN odontograma_dentes d
        ON d.id = r.odontograma_dente_id
    WHERE d.prontuario_id = ?
    ORDER BY r.data_registro DESC, r.id DESC
");

$stmtOdontogramaRegistros->execute([$id]);

$odontogramaRegistros = $stmtOdontogramaRegistros->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| ÚLTIMA ALTERAÇÃO ATIVA DE CADA DENTE
|--------------------------------------------------------------------------
*/
$ultimaAlteracaoPorDente = [];

foreach ($odontogramaRegistros as $registro) {
    // Ignora registros arquivados.
    if ((int)$registro['arquivado'] !== 0) {
        continue;
    }

    $denteId = (int)$registro['odontograma_dente_id'];

    // A consulta já está ordenada do registro mais recente para o mais antigo.
    // Portanto, o primeiro registro ativo de cada dente é o mais recente.
    if (!isset($ultimaAlteracaoPorDente[$denteId])) {
        $ultimaAlteracaoPorDente[$denteId] = $registro;
    }
}



/*
|--------------------------------------------------------------------------
| HISTÓRICO DOS ESTADOS GERAIS
|--------------------------------------------------------------------------
*/

$stmtOdontogramaEstadosHistorico = $pdo->prepare("
    SELECT
        h.id,
        h.odontograma_dente_id,
        h.estado,
        h.observacao,
        h.data_registro,
        h.criado_em
    FROM odontograma_estados_historico h
    INNER JOIN odontograma_dentes d
        ON d.id = h.odontograma_dente_id
    WHERE d.prontuario_id = ?
    ORDER BY h.data_registro DESC, h.id DESC
");

$stmtOdontogramaEstadosHistorico->execute([$id]);

$odontogramaEstadosHistorico =
    $stmtOdontogramaEstadosHistorico->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| HISTÓRICO DOS IMPLANTES
|--------------------------------------------------------------------------
*/

$stmtOdontogramaImplantesHistorico = $pdo->prepare("
    SELECT
        h.id,
        h.odontograma_dente_id,
        h.status,
        h.observacao,
        h.data_registro,
        h.criado_em
    FROM odontograma_implantes_historico h
    INNER JOIN odontograma_dentes d
        ON d.id = h.odontograma_dente_id
    WHERE d.prontuario_id = ?
    ORDER BY h.data_registro DESC, h.id DESC
");

$stmtOdontogramaImplantesHistorico->execute([$id]);

$odontogramaImplantesHistorico =
    $stmtOdontogramaImplantesHistorico->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| UNIFICAR HISTÓRICOS
|--------------------------------------------------------------------------
*/

$odontogramaHistorico = [];


/*
|--------------------------------------------------------------------------
| HISTÓRICO DE SUPERFÍCIES
|--------------------------------------------------------------------------
*/

foreach ($odontogramaRegistros as $registro) {

    $denteId = (int)$registro['odontograma_dente_id'];

    if (!isset($odontogramaHistorico[$denteId])) {
        $odontogramaHistorico[$denteId] = [];
    }

    $registro['tipo'] = 'superficie';

    $odontogramaHistorico[$denteId][] = $registro;
}


/*
|--------------------------------------------------------------------------
| HISTÓRICO DE ESTADOS GERAIS
|--------------------------------------------------------------------------
*/

foreach ($odontogramaEstadosHistorico as $registro) {

    $denteId = (int)$registro['odontograma_dente_id'];

    if (!isset($odontogramaHistorico[$denteId])) {
        $odontogramaHistorico[$denteId] = [];
    }

    $registro['tipo'] = 'estado';

    $registro['condicao'] = $registro['estado'];

    $registro['superficie'] = null;

    $registro['procedimento_id'] = null;

    $registro['plano_tratamento_item_id'] = null;

    $registro['arquivado'] = 1;

    $odontogramaHistorico[$denteId][] = $registro;
}

/*
|--------------------------------------------------------------------------
| HISTÓRICO DE IMPLANTES
|--------------------------------------------------------------------------
*/

foreach ($odontogramaImplantesHistorico as $registro) {

    $denteId = (int)$registro['odontograma_dente_id'];

    if (!isset($odontogramaHistorico[$denteId])) {
        $odontogramaHistorico[$denteId] = [];
    }

    $registro['tipo'] = 'implante';

    $registro['condicao'] = $registro['status'];

    $registro['superficie'] = null;

    $registro['procedimento_id'] = null;

    $registro['plano_tratamento_item_id'] = null;

    $registro['arquivado'] = 1;

    $odontogramaHistorico[$denteId][] = $registro;
}


/*
|--------------------------------------------------------------------------
| ORDENAR HISTÓRICO POR DATA
|--------------------------------------------------------------------------
*/

foreach ($odontogramaHistorico as $denteId => &$registros) {

    usort($registros, function ($a, $b) {

        $dataA = strtotime($a['data_registro'] ?? '');
        $dataB = strtotime($b['data_registro'] ?? '');

        if ($dataA === $dataB) {
            return ((int)($b['id'] ?? 0))
                <=> ((int)($a['id'] ?? 0));
        }

        return $dataB <=> $dataA;
    });
}

unset($registros);

$dentesPermanentes = [];
$dentesDeciduos = [];

foreach ($odontogramaDentes as $dente) {
    if ($dente['denticao'] === 'permanente') {
        $dentesPermanentes[] = $dente;
    } else {
        $dentesDeciduos[] = $dente;
    }
}

/*
|--------------------------------------------------------------------------
| RESUMO DO ODONTOGRAMA
|--------------------------------------------------------------------------
*/

$odontogramaResumo = [
    'total' => count($odontogramaDentes),
    'nao_avaliado' => 0,
    'higido' => 0,
    'carie' => 0,
    'restaurado' => 0,
    'fraturado' => 0,
    'em_tratamento' => 0,
    'ausente' => 0,
    'extraido' => 0
];

foreach ($odontogramaDentes as $dente) {
    $estado = $dente['estado_geral'];

    if (isset($odontogramaResumo[$estado])) {
        $odontogramaResumo[$estado]++;
    }
}

/*
|--------------------------------------------------------------------------
| STATUS DO TERMO
|--------------------------------------------------------------------------
*/

// O aceite pode estar registrado em prontuarios ou em consentimentos.
$consentimentoAceito = !empty($consentimento)
    && (int)($consentimento['aceito'] ?? 0) === 1;

$termoAceito = (isset($prontuario['termo_consentimento_aceito'])
    && (int)$prontuario['termo_consentimento_aceito'] === 1)
    || $consentimentoAceito;

$dataAceite = $prontuario['termo_consentimento_aceito_em']
    ?? ($consentimento['data_aceite'] ?? null);

?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>
        Prontuário de <?= htmlspecialchars($prontuario['paciente']) ?>
        | Evoluqi
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
        href="css/visualizar_prontuario.css">

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link
        rel="icon"
        type="image/png"
        href="img/icon.PNG">

</head>

<body class="<?= $isPrint ? 'print-mode' : '' ?>">

    <?php if (!$isPrint): ?>

        <?php include 'navbar.php'; ?>

    <?php endif; ?>


    <main class="prontuario-page">

        <?php if (!$isPrint && $mensagemErro): ?>
            <div class="page-alert page-alert-error" role="alert">
                <i class="fa-solid fa-circle-exclamation"></i>
                <div>
                    <strong>Não foi possível concluir a exclusão</strong>
                    <span>
                        <?= htmlspecialchars(
                            $mensagemErro,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </span>
                </div>
                <button
                    type="button"
                    class="page-alert-close"
                    aria-label="Fechar mensagem"
                    onclick="this.closest('.page-alert').remove();">
                    &times;
                </button>
            </div>
        <?php endif; ?>

        <!-- =====================================================
         CABEÇALHO
    ====================================================== -->

        <div class="page-header">

            <div class="page-header-info">

                <div class="breadcrumb">

                    <span>Prontuários</span>

                    <span class="breadcrumb-separator">/</span>

                    <span>Visualização</span>

                </div>

                <h1>
                    Prontuário do paciente
                </h1>

                <p>
                    Consulte os dados, histórico e informações do paciente.
                </p>

            </div>

        </div>


        <!-- =====================================================
         CABEÇALHO DO PACIENTE
    ====================================================== -->

        <section class="patient-header">

            <div class="patient-main">

                <div class="patient-avatar">
                    <?= htmlspecialchars($iniciais) ?>
                </div>

                <div class="patient-info">

                    <h2>
                        <?= htmlspecialchars($prontuario['paciente']) ?>
                    </h2>

                    <div class="patient-details">

                        <span>
                            <i class="fa-regular fa-calendar"></i>

                            <?= date(
                                'd/m/Y',
                                strtotime($prontuario['nascimento'])
                            ) ?>

                            (<?= $idade ?> anos)
                        </span>


                        <span>
                            <i class="fa-regular fa-id-card"></i>

                            CPF:
                            <?= htmlspecialchars(
                                $prontuario['cpf'] ?: 'Não informado'
                            ) ?>
                        </span>


                        <span>
                            <i class="fa-solid fa-phone"></i>

                            <?= htmlspecialchars(
                                $prontuario['telefone'] ?: 'Não informado'
                            ) ?>
                        </span>
                        <span>
                            <i class="fa-regular fa-envelope"></i>
                            E-mail: <?= htmlspecialchars($prontuario['email'] ?: 'Não informado') ?>
                        </span>

                    </div>

                </div>

            </div>


            <?php if (!$isPrint): ?>

                <div class="patient-actions">

                    <a
                        href="editar_prontuario.php?id=<?= $id ?>"
                        class="action-btn">

                        <i class="fa-solid fa-pen"></i>

                        Editar

                    </a>


                    <button
                        type="button"
                        class="action-btn"
                        onclick="window.open('visualizar_prontuario.php?id=<?= $id ?>&print=1', '_blank')">

                        <i class="fa-solid fa-print"></i>

                        Imprimir

                    </button>


                    <div class="more-wrapper">

                        <button
                            type="button"
                            class="action-btn"
                            id="moreButton">

                            Mais

                            <i class="fa-solid fa-chevron-down"></i>

                        </button>

                        <div
                            class="more-menu"
                            id="moreMenu">

                            <a
                                href="termo_conscentimento.php?id=<?= $id ?>"
                                target="_blank">

                                <i class="fa-regular fa-file-lines"></i>

                                Termo de Consentimento

                            </a>

                            <a
                                href="adicionar_procedimento.php?prontuario_id=<?= $id ?>">

                                <i class="fa-solid fa-plus"></i>

                                Adicionar Procedimento

                            </a>

                        </div>

                    </div>

                </div>

            <?php endif; ?>

        </section>


        <!-- =====================================================
         ABAS
    ====================================================== -->

        <?php if (!$isPrint): ?>

            <nav class="patient-tabs">

                <button
                    type="button"
                    class="patient-tab active"
                    data-tab="resumo">

                    Resumo

                </button>

                <button
                    type="button"
                    class="patient-tab"
                    data-tab="odontograma">

                    <i class="fa-solid fa-tooth"></i>

                    Odontograma
                </button>

                <button
                    type="button"
                    class="patient-tab"
                    data-tab="historico">

                    Histórico

                </button>

                <button
                    type="button"
                    class="patient-tab"
                    data-tab="documentos">

                    Documentos

                </button>

                <button
                    type="button"
                    class="patient-tab"
                    data-tab="anexos">

                    Anexos

                </button>

                <button
                    type="button"
                    class="patient-tab"
                    data-tab="financeiro">

                    Financeiro

                </button>

            </nav>

        <?php endif; ?>


        <!-- =====================================================
         RESUMO
    ====================================================== -->

        <div
            class="tab-content active"
            id="tab-resumo">


            <!-- INFORMAÇÕES PESSOAIS -->

            <div class="content-grid">

                <section class="info-card">

                    <div class="card-header">

                        <h2>
                            Informações pessoais
                        </h2>

                    </div>


                    <div class="info-list">

                        <div class="info-item">

                            <strong>Sexo</strong>

                            <span>
                                <?= htmlspecialchars(
                                    $prontuario['sexo'] ?: 'Não informado'
                                ) ?>
                            </span>

                        </div>


                        <div class="info-item">

                            <strong>Estado civil</strong>

                            <span>
                                <?= htmlspecialchars(
                                    $prontuario['estado_civil'] ?: 'Não informado'
                                ) ?>
                            </span>

                        </div>


                        <div class="info-item">

                            <strong>Profissão</strong>

                            <span>
                                <?= htmlspecialchars(
                                    $prontuario['profissao'] ?: 'Não informado'
                                ) ?>
                            </span>

                        </div>


                        <div class="info-item">

                            <strong>RG</strong>

                            <span>
                                <?= htmlspecialchars(
                                    $prontuario['rg'] ?: 'Não informado'
                                ) ?>
                            </span>

                        </div>


                        <div class="info-item">

                            <strong>CEP</strong>

                            <span>
                                <?= htmlspecialchars(
                                    $prontuario['cep'] ?: 'Não informado'
                                ) ?>
                            </span>

                        </div>


                        <div class="info-item info-item-full">

                            <strong>Endereço</strong>

                            <span>
                                <?= nl2br(
                                    htmlspecialchars(
                                        $prontuario['endereco'] ?: 'Não informado'
                                    )
                                ) ?>
                            </span>

                        </div>

                    </div>

                </section>


                <!-- OBSERVAÇÕES -->

                <section class="info-card">

                    <div class="card-header">

                        <h2>
                            Observações
                        </h2>

                    </div>


                    <div class="observacoes">

                        <?php if (!empty($prontuario['observacoes'])): ?>

                            <?= nl2br(
                                htmlspecialchars(
                                    $prontuario['observacoes']
                                )
                            ) ?>

                        <?php else: ?>

                            <span class="empty-text">
                                Nenhuma observação registrada.
                            </span>

                        <?php endif; ?>

                    </div>

                </section>

            </div>


            <!-- =================================================
             TERMO DE CONSENTIMENTO
        ================================================== -->

            <section class="info-card consent-card">

                <div class="card-header">

                    <div>

                        <span class="card-kicker">
                            DOCUMENTAÇÃO
                        </span>

                        <h2>
                            Termo de Consentimento
                        </h2>

                    </div>

                    <?php if ($termoAceito): ?>

                        <span class="status-badge status-accepted">
                            <i class="fa-solid fa-check"></i>
                            Aceito
                        </span>

                    <?php else: ?>

                        <span class="status-badge status-pending">
                            <i class="fa-solid fa-clock"></i>
                            Pendente
                        </span>

                    <?php endif; ?>

                </div>


                <div class="consent-content">

                    <?php if ($termoAceito): ?>

                        <div class="consent-status accepted">

                            <div class="consent-icon">
                                <i class="fa-solid fa-check"></i>
                            </div>

                            <div>

                                <strong>
                                    Termo aceito pelo paciente
                                </strong>

                                <?php if ($dataAceite): ?>

                                    <span>
                                        Aceito em
                                        <?= date(
                                            'd/m/Y \à\s H:i',
                                            strtotime($dataAceite)
                                        ) ?>
                                    </span>

                                <?php endif; ?>

                            </div>

                        </div>

                    <?php else: ?>

                        <div class="consent-status pending">

                            <div class="consent-icon">
                                <i class="fa-solid fa-clock"></i>
                            </div>

                            <div>

                                <strong>
                                    Consentimento ainda não registrado
                                </strong>

                                <span>
                                    O paciente ainda não confirmou o Termo de
                                    Consentimento.
                                </span>

                            </div>

                        </div>

                    <?php endif; ?>
                    <?php if (!$isPrint && !$termoAceito): ?>
                        <a href="termo_conscentimento.php?id=<?= $id ?>"
                            target="_blank"
                            class="consent-button">
                            <i class="fa-regular fa-file-lines"></i>
                            Aceitar Termo de Consentimento
                        </a>
                    <?php elseif (!$isPrint && $termoAceito): ?>
                        <span class="consent-accepted-label">
                            <i class="fa-solid fa-circle-check"></i>
                            Termo já aceito
                        </span>
                    <?php endif; ?>

                </div>

            </section>


            <!-- =================================================
             PROCEDIMENTOS
        ================================================== -->

            <section class="info-card procedures-card">

                <div class="card-header">

                    <div>

                        <span class="card-kicker">
                            HISTÓRICO CLÍNICO
                        </span>

                        <h2>
                            Últimos procedimentos
                        </h2>

                    </div>

                    <?php if (!$isPrint): ?>

                        <a
                            href="adicionar_procedimento.php?prontuario_id=<?= $id ?>"
                            class="card-link">

                            + Adicionar procedimento

                        </a>

                    <?php endif; ?>

                </div>


                <?php if (empty($procedimentos)): ?>

                    <div class="empty-state">

                        <div class="empty-icon">
                            <i class="fa-regular fa-folder-open"></i>
                        </div>

                        <strong>
                            Nenhum procedimento registrado
                        </strong>

                        <span>
                            Os procedimentos realizados neste paciente
                            aparecerão aqui.
                        </span>

                    </div>

                <?php else: ?>

                    <div class="table-wrapper">

                        <table class="procedures-table">

                            <thead>

                                <tr>

                                    <th>Data</th>

                                    <th>Procedimento</th>

                                    <th>Descrição</th>
                                    <th>Valores</th>

                                    <?php if (!$isPrint): ?>

                                        <th>Ação</th>

                                    <?php endif; ?>

                                </tr>

                            </thead>


                            <tbody>

                                <?php foreach ($procedimentos as $proc): ?>

                                    <tr>

                                        <td>
                                            <?= date(
                                                'd/m/Y',
                                                strtotime(
                                                    $proc['data_procedimento']
                                                )
                                            ) ?>
                                        </td>


                                        <td>

                                            <strong>
                                                <?= htmlspecialchars(
                                                    $proc['titulo']
                                                ) ?>
                                            </strong>

                                        </td>


                                        <td>

                                            <?php

                                            $descricao =
                                                trim(
                                                    $proc['descricao'] ?? ''
                                                );

                                            if ($descricao === '') {
                                                echo '<span class="muted">—</span>';
                                            } else {

                                                echo htmlspecialchars(
                                                    mb_strimwidth(
                                                        $descricao,
                                                        0,
                                                        100,
                                                        '...'
                                                    )
                                                );
                                            }

                                            ?>

                                        </td>


                                        <td class="procedure-values">

                                            <div class="procedure-value-line">
                                                <span>Valor final</span>

                                                <strong>
                                                    R$
                                                    <?= number_format(
                                                        (float)($proc['valor_final'] ?? 0),
                                                        2,
                                                        ',',
                                                        '.'
                                                    ) ?>
                                                </strong>
                                            </div>

                                        </td>

                                        <?php if (!$isPrint): ?>

                                            <td class="procedure-actions">

                                                <a
                                                    href="gerar_cobranca_procedimento.php?procedimento_id=<?= (int)$proc['id'] ?>"
                                                    class="acao-icone acao-cobranca"
                                                    aria-label="Gerar cobrança do procedimento #<?= (int)$proc['id'] ?>"
                                                    title="Gerar cobrança">
                                                    <i class="fa-solid fa-money-bill-wave" aria-hidden="true"></i>
                                                </a>

                                                <a
                                                    class="acao-icone acao-editar"
                                                    href="adicionar_procedimento.php?prontuario_id=<?= $id ?>&procedimento_id=<?= (int)$proc['id'] ?>"
                                                    aria-label="Editar procedimento #<?= (int)$proc['id'] ?>"
                                                    title="Editar">
                                                    <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                                </a>

                                                <button
                                                    type="button"
                                                    class="acao-icone acao-visualizar btn-visualizar"
                                                    aria-label="Visualizar procedimento #<?= (int)$proc['id'] ?>"
                                                    title="Visualizar"
                                                    data-titulo="<?= htmlspecialchars(
                                                                        $proc['titulo'],
                                                                        ENT_QUOTES
                                                                    ) ?>"
                                                    data-descricao="<?= htmlspecialchars(
                                                                        $proc['descricao'] ?? '',
                                                                        ENT_QUOTES
                                                                    ) ?>"
                                                    data-medicamentos="<?= htmlspecialchars(
                                                                            $proc['medicamentos'] ?? '',
                                                                            ENT_QUOTES
                                                                        ) ?>"
                                                    data-data="<?= date(
                                                                    'd/m/Y',
                                                                    strtotime(
                                                                        $proc['data_procedimento']
                                                                    )
                                                                ) ?>"
                                                    data-valor-materiais="<?= htmlspecialchars(
                                                                                $proc['valor_materiais'] ?? '0',
                                                                                ENT_QUOTES
                                                                            ) ?>"
                                                    data-valor-mao-obra="<?= htmlspecialchars(
                                                                                $proc['valor_mao_obra'] ?? '0',
                                                                                ENT_QUOTES
                                                                            ) ?>"
                                                    data-valor-final="<?= htmlspecialchars(
                                                                            $proc['valor_final'] ?? '0',
                                                                            ENT_QUOTES
                                                                        ) ?>">
                                                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                                </button>

                                                <form
                                                    method="POST"
                                                    class="form-excluir-procedimento"
                                                    onsubmit="return confirm('Arquivar este procedimento? Ele permanecerá no histórico e os materiais utilizados não serão devolvidos ao estoque.');">

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= htmlspecialchars(
                                                                    $_SESSION['csrf_token'] ?? ''
                                                                ) ?>">

                                                    <input
                                                        type="hidden"
                                                        name="acao"
                                                        value="excluir_procedimento">

                                                    <input
                                                        type="hidden"
                                                        name="prontuario_id"
                                                        value="<?= (int)$id ?>">

                                                    <input
                                                        type="hidden"
                                                        name="procedimento_id"
                                                        value="<?= (int)$proc['id'] ?>">

                                                    <button
                                                        type="submit"
                                                        class="acao-icone acao-arquivar"
                                                        aria-label="Arquivar procedimento #<?= (int)$proc['id'] ?>"
                                                        title="Arquivar">
                                                        <i class="fa-solid fa-box-archive" aria-hidden="true"></i>
                                                    </button>

                                                </form>

                                            </td>

                                        <?php endif; ?>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </section>

        </div>

        <!-- =====================================================
     ODONTOGRAMA
====================================================== -->

        <div
            class="tab-content"
            id="tab-odontograma">

            <section class="info-card odontograma-card">

                <div class="card-header">

                    <div>
                        <span class="card-kicker">
                            ODONTOLOGIA
                        </span>

                        <h2>
                            Odontograma
                        </h2>

                        <p class="odontograma-subtitle">
                            Clique em um dente para visualizar suas informações.
                        </p>
                    </div>

                    <div class="odontograma-legenda">

                        <span>
                            <i class="odontograma-dot dot-nao-avaliado"></i>
                            Não avaliado
                        </span>

                        <span>
                            <i class="odontograma-dot dot-higido"></i>
                            Hígido
                        </span>

                        <span>
                            <i class="odontograma-dot dot-carie"></i>
                            Cárie
                        </span>

                        <span>
                            <i class="odontograma-dot dot-restaurado"></i>
                            Restaurado
                        </span>

                        <span>
                            <i class="odontograma-dot dot-fraturado"></i>
                            Fraturado
                        </span>

                        <span>
                            <i class="odontograma-dot dot-em-tratamento"></i>
                            Em tratamento
                        </span>

                        <span>
                            <i class="odontograma-dot dot-implante"></i>
                            Implante planejado
                        </span>

                        <span>
                            <i class="odontograma-dot dot-implante-tratamento"></i>
                            Implante em tratamento
                        </span>

                        <span>
                            <i class="odontograma-dot dot-implante-realizado"></i>
                            Implante realizado
                        </span>

                    </div>

                </div>


                <!-- =================================================
             DENTES PERMANENTES
        ================================================== -->

                <div class="odontograma-section">

                    <div class="odontograma-section-title">
                        <i class="fa-solid fa-tooth"></i>

                        <span>
                            Dentes Permanentes
                        </span>

                        <strong>
                            <?= count($dentesPermanentes) ?>
                        </strong>
                    </div>


                    <div class="odontograma-arc">

                        <div class="odontograma-row odontograma-row-superior">

                            <?php
                            $superiores = array_filter(
                                $dentesPermanentes,
                                fn($dente) =>
                                (int)$dente['numero_dente'] >= 11 &&
                                    (int)$dente['numero_dente'] <= 28
                            );

                            foreach ($superiores as $dente):
                            ?>

                                <button
                                    type="button"
                                    class="odontograma-dente estado-<?= htmlspecialchars($dente['estado_geral']) ?>"
                                    data-dente-id="<?= (int)$dente['id'] ?>"
                                    data-numero="<?= htmlspecialchars($dente['numero_dente']) ?>"
                                    data-estado="<?= htmlspecialchars($dente['estado_geral']) ?>"
                                    data-implante-status="<?= htmlspecialchars($dente['implante_status']) ?>">

                                    <span class="dente-numero">
                                        <?= htmlspecialchars($dente['numero_dente']) ?>
                                    </span>

                                    <i class="fa-solid fa-tooth dente-icone"></i>


                                    <?php
                                    $ultimaAlteracao = $ultimaAlteracaoPorDente[(int)$dente['id']] ?? null;

                                    $condicaoUltimaAlteracao = $ultimaAlteracao['condicao'] ?? '';

                                    $nomesCondicoes = [
                                        'carie' => 'Cárie',
                                        'restaurado' => 'Restaurado',
                                        'fraturado' => 'Fraturado',
                                        'em_tratamento' => 'Em tratamento',
                                    ];

                                    $nomeUltimaAlteracao = $nomesCondicoes[$condicaoUltimaAlteracao]
                                        ?? $condicaoUltimaAlteracao;
                                    ?>

                                    <?php if ($ultimaAlteracao !== null): ?>
                                        <span
                                            class="indicador-ultima-alteracao estado-<?= htmlspecialchars($condicaoUltimaAlteracao) ?>"
                                            title="Última alteração: <?= htmlspecialchars($nomeUltimaAlteracao) ?>"
                                            aria-label="Última alteração: <?= htmlspecialchars($nomeUltimaAlteracao) ?>">
                                        </span>
                                    <?php endif; ?>


                                    <span
                                        class="indicador-implante indicador-implante-<?= htmlspecialchars($dente['implante_status']) ?>"
                                        title="Implante: <?= htmlspecialchars($dente['implante_status']) ?>">
                                        <i class="<?=
                                                    $dente['implante_status'] === 'planejado'
                                                        ? 'fa-solid fa-plus'
                                                        : (
                                                            $dente['implante_status'] === 'em_tratamento'
                                                            ? 'fa-solid fa-gear'
                                                            : (
                                                                $dente['implante_status'] === 'realizado'
                                                                ? 'fa-solid fa-check'
                                                                : ''
                                                            )
                                                        )
                                                    ?>"></i>
                                    </span>

                                </button>

                            <?php endforeach; ?>

                        </div>


                        <div class="odontograma-divisor"></div>


                        <div class="odontograma-row odontograma-row-inferior">

                            <?php
                            $inferiores = array_filter(
                                $dentesPermanentes,
                                fn($dente) =>
                                (int)$dente['numero_dente'] >= 31 &&
                                    (int)$dente['numero_dente'] <= 48
                            );

                            foreach (array_reverse($inferiores) as $dente):
                            ?>

                                <button
                                    type="button"
                                    class="odontograma-dente estado-<?= htmlspecialchars($dente['estado_geral']) ?>"
                                    data-dente-id="<?= (int)$dente['id'] ?>"
                                    data-numero="<?= htmlspecialchars($dente['numero_dente']) ?>"
                                    data-estado="<?= htmlspecialchars($dente['estado_geral']) ?>"
                                    data-implante-status="<?= htmlspecialchars($dente['implante_status']) ?>">

                                    <span class="dente-numero">
                                        <?= htmlspecialchars($dente['numero_dente']) ?>
                                    </span>

                                    <i class="fa-solid fa-tooth dente-icone"></i>

                                    <?php
                                    $ultimaAlteracao = $ultimaAlteracaoPorDente[(int)$dente['id']] ?? null;

                                    $condicaoUltimaAlteracao = $ultimaAlteracao['condicao'] ?? '';

                                    $nomesCondicoes = [
                                        'carie' => 'Cárie',
                                        'restaurado' => 'Restaurado',
                                        'fraturado' => 'Fraturado',
                                        'em_tratamento' => 'Em tratamento',
                                    ];

                                    $nomeUltimaAlteracao = $nomesCondicoes[$condicaoUltimaAlteracao]
                                        ?? $condicaoUltimaAlteracao;
                                    ?>

                                    <?php if ($ultimaAlteracao !== null): ?>
                                        <span
                                            class="indicador-ultima-alteracao estado-<?= htmlspecialchars($condicaoUltimaAlteracao) ?>"
                                            title="Última alteração: <?= htmlspecialchars($nomeUltimaAlteracao) ?>"
                                            aria-label="Última alteração: <?= htmlspecialchars($nomeUltimaAlteracao) ?>">
                                        </span>
                                    <?php endif; ?>

                                    <span
                                        class="indicador-implante indicador-implante-<?= htmlspecialchars($dente['implante_status']) ?>"
                                        title="Implante: <?= htmlspecialchars($dente['implante_status']) ?>">
                                        <i class="fa-solid fa-plus"></i>
                                    </span>

                                </button>

                            <?php endforeach; ?>

                        </div>

                        <div class="odontograma-arc-label">
                            Arco inferior
                        </div>

                    </div>

                </div>


                <!-- =================================================
             DENTES DECÍDUOS
        ================================================== -->

                <div class="odontograma-section odontograma-section-deciduos">

                    <div class="odontograma-section-title">
                        <i class="fa-solid fa-tooth"></i>

                        <span>
                            Dentes Decíduos
                        </span>

                        <strong>
                            <?= count($dentesDeciduos) ?>
                        </strong>
                    </div>


                    <div class="odontograma-arc">

                        <div class="odontograma-row">

                            <?php
                            foreach ($dentesDeciduos as $dente):
                            ?>

                                <button
                                    type="button"
                                    class="odontograma-dente estado-<?= htmlspecialchars($dente['estado_geral']) ?>"
                                    data-dente-id="<?= (int)$dente['id'] ?>"
                                    data-numero="<?= htmlspecialchars($dente['numero_dente']) ?>"
                                    data-estado="<?= htmlspecialchars($dente['estado_geral']) ?>"
                                    data-implante-status="<?= htmlspecialchars($dente['implante_status']) ?>">

                                    <span class="dente-numero">
                                        <?= htmlspecialchars($dente['numero_dente']) ?>
                                    </span>

                                    <i class="fa-solid fa-tooth dente-icone"></i>

                                    <?php
                                    $ultimaAlteracao = $ultimaAlteracaoPorDente[(int)$dente['id']] ?? null;

                                    $condicaoUltimaAlteracao = $ultimaAlteracao['condicao'] ?? '';

                                    $nomesCondicoes = [
                                        'carie' => 'Cárie',
                                        'restaurado' => 'Restaurado',
                                        'fraturado' => 'Fraturado',
                                        'em_tratamento' => 'Em tratamento',
                                    ];

                                    $nomeUltimaAlteracao = $nomesCondicoes[$condicaoUltimaAlteracao]
                                        ?? $condicaoUltimaAlteracao;
                                    ?>

                                    <?php if ($ultimaAlteracao !== null): ?>
                                        <span
                                            class="indicador-ultima-alteracao estado-<?= htmlspecialchars($condicaoUltimaAlteracao) ?>"
                                            title="Última alteração: <?= htmlspecialchars($nomeUltimaAlteracao) ?>"
                                            aria-label="Última alteração: <?= htmlspecialchars($nomeUltimaAlteracao) ?>">
                                        </span>
                                    <?php endif; ?>

                                    <span
                                        class="indicador-implante indicador-implante-<?= htmlspecialchars($dente['implante_status']) ?>"
                                        title="Implante: <?= htmlspecialchars($dente['implante_status']) ?>">
                                        <i class="fa-solid fa-plus"></i>
                                    </span>

                                </button>
                            <?php endforeach; ?>

                        </div>

                    </div>

                </div>


                <!-- =================================================
             RESUMO
        ================================================== -->

                <div class="odontograma-resumo">

                    <div class="odontograma-resumo-item">
                        <span>Total</span>
                        <strong>
                            <?= $odontogramaResumo['total'] ?>
                        </strong>
                    </div>

                    <div class="odontograma-resumo-item">
                        <span>Não avaliados</span>
                        <strong>
                            <?= $odontogramaResumo['nao_avaliado'] ?>
                        </strong>
                    </div>

                    <div class="odontograma-resumo-item">
                        <span>Hígidos</span>
                        <strong>
                            <?= $odontogramaResumo['higido'] ?>
                        </strong>
                    </div>

                    <div class="odontograma-resumo-item">
                        <span>Cáries</span>
                        <strong>
                            <?= $odontogramaResumo['carie'] ?>
                        </strong>
                    </div>

                    <div class="odontograma-resumo-item">
                        <span>Restaurados</span>
                        <strong>
                            <?= $odontogramaResumo['restaurado'] ?>
                        </strong>
                    </div>

                    <div class="odontograma-resumo-item">
                        <span>Fraturados</span>
                        <strong>
                            <?= $odontogramaResumo['fraturado'] ?>
                        </strong>
                    </div>

                    <div class="odontograma-resumo-item">
                        <span>Em tratamento</span>
                        <strong>
                            <?= $odontogramaResumo['em_tratamento'] ?>
                        </strong>
                    </div>

                    <div class="odontograma-resumo-item">
                        <span>Ausentes</span>
                        <strong>
                            <?= $odontogramaResumo['ausente'] ?>
                        </strong>
                    </div>

                    <div class="odontograma-resumo-item">
                        <span>Extraídos</span>
                        <strong>
                            <?= $odontogramaResumo['extraido'] ?>
                        </strong>
                    </div>

                </div>

            </section>

            <!-- =========================================================
     MODAL DO ODONTOGRAMA
========================================================= -->

            <div
                id="modalOdontograma"
                class="odontograma-modal"
                aria-hidden="true">
                <div class="odontograma-modal-overlay"></div>

                <div
                    class="odontograma-modal-content"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="modalOdontogramaTitulo">

                    <!-- Cabeçalho -->
                    <div class="odontograma-modal-header">

                        <div>
                            <span class="odontograma-modal-kicker">
                                ODONTOGRAMA
                            </span>

                            <h3 id="modalOdontogramaTitulo">
                                Dente
                            </h3>
                        </div>

                        <button
                            type="button"
                            class="odontograma-modal-fechar"
                            id="fecharModalOdontograma"
                            aria-label="Fechar"
                            title="Fechar">
                            <i class="fa-solid fa-xmark"></i>
                        </button>

                    </div>

                    <!-- Corpo -->
                    <div class="odontograma-modal-body">

                        <!-- Identificação -->
                        <div class="odontograma-modal-dente-info">

                            <div class="odontograma-modal-dente-icon">
                                <i class="fa-solid fa-tooth"></i>
                            </div>

                            <div>
                                <span>Dente selecionado</span>
                                <strong id="modalNumeroDente">--</strong>
                            </div>

                        </div>

                        <!-- Visualização do dente -->
                        <div class="odontograma-dente-visual">

                            <svg
                                id="odontogramaDenteSvg"
                                class="odontograma-dente-svg"
                                viewBox="0 0 300 360"
                                role="img"
                                aria-label="Representação interativa do dente">

                                <!-- Contorno do dente -->
                                <path
                                    class="dente-contorno"
                                    d="M150 25
               C105 25 65 48 58 92
               C53 125 70 150 72 180
               C74 220 72 268 92 318
               C100 338 117 342 128 321
               L145 282
               C148 274 152 274 155 282
               L172 321
               C183 342 200 338 208 318
               C228 268 226 220 228 180
               C230 150 247 125 242 92
               C235 48 195 25 150 25Z">
                                </path>




                                <!-- VESTIBULAR -->
                                <path
                                    class="dente-superficie"
                                    data-superficie-dente="vestibular"
                                    d="M83 78
               C105 55 127 52 150 52
               C173 52 195 55 217 78
               L208 122
               C190 112 170 107 150 107
               C130 107 110 112 92 122
               Z">
                                </path>


                                <!-- MESIAL -->
                                <path
                                    class="dente-superficie"
                                    data-superficie-dente="mesial"
                                    d="M83 78
               C72 91 69 108 72 125
               C75 143 84 157 91 169
               L91 230
               C91 248 92 265 96 279
               L118 229
               L118 175
               C106 153 98 132 92 122
               Z">
                                </path>


                                <!-- OCLUSAL / INCISAL -->
                                <path
                                    class="dente-superficie"
                                    data-superficie-dente="oclusal"
                                    d="M92 122
               C110 112 130 107 150 107
               C170 107 190 112 208 122
               C198 140 187 154 177 169
               L177 218
               C167 212 158 209 150 209
               C142 209 133 212 123 218
               L123 169
               C113 154 102 140 92 122
               Z">
                                </path>


                                <!-- DISTAL -->
                                <path
                                    class="dente-superficie"
                                    data-superficie-dente="distal"
                                    d="M217 78
               C228 91 231 108 228 125
               C225 143 216 157 209 169
               L209 230
               C209 248 208 265 204 279
               L182 229
               L182 175
               C194 153 202 132 208 122
               Z">
                                </path>


                                <!-- LINGUAL / PALATINA -->
                                <path
                                    class="dente-superficie"
                                    data-superficie-dente="lingual_palatina"
                                    d="M96 279
               C110 266 128 254 150 254
               C172 254 190 266 204 279
               L208 318
               C200 338 183 342 172 321
               L155 282
               C152 274 148 274 145 282
               L128 321
               C117 342 100 338 92 318
               Z">
                                </path>

                                <!-- Indicador visual de dente extraído -->
                                <g
                                    id="indicadorExtracao"
                                    class="indicador-extracao"
                                    aria-hidden="true">
                                    <line x1="95" y1="100" x2="205" y2="260"></line>
                                    <line x1="205" y1="100" x2="95" y2="260"></line>

                                </g>

                            </svg>

                        </div>


                        <!-- Estado geral -->
                        <div class="odontograma-modal-campo">

                            <label for="modalEstadoDente">
                                Estado geral
                            </label>

                            <select id="modalEstadoDente">

                                <option value="nao_avaliado">
                                    Não avaliado
                                </option>

                                <option value="higido">
                                    Hígido
                                </option>

                                <option value="carie">
                                    Cárie
                                </option>

                                <option value="restaurado">
                                    Restaurado
                                </option>

                                <option value="fraturado">
                                    Fraturado
                                </option>

                                <option value="em_tratamento">
                                    Em tratamento
                                </option>

                                <option value="ausente">
                                    Ausente
                                </option>

                                <option value="extraido">
                                    Extraído
                                </option>

                            </select>

                            <div class="form-group" id="grupoImplante" style="display: none;">

                                <label for="implanteStatus">
                                    Planejamento de implante
                                </label>

                                <select
                                    id="implanteStatus"
                                    name="implante_status"
                                    class="form-control">

                                    <option value="nao_se_aplica">
                                        Não se aplica
                                    </option>

                                    <option value="planejado">
                                        Planejado
                                    </option>

                                    <option value="em_tratamento">
                                        Em tratamento
                                    </option>

                                    <option value="realizado">
                                        Realizado
                                    </option>

                                </select>

                            </div>

                            <div class="odontograma-modal-legendas">

                                <div class="odontograma-modal-legenda">
                                    <div class="odontograma-modal-legenda-titulo">
                                        Legenda do estado
                                    </div>

                                    <div class="odontograma-modal-legenda-itens">

                                        <span>
                                            <i class="odontograma-dot dot-nao-avaliado"></i>
                                            Não avaliado
                                        </span>

                                        <span>
                                            <i class="odontograma-dot dot-higido"></i>
                                            Hígido
                                        </span>

                                        <span>
                                            <i class="odontograma-dot dot-carie"></i>
                                            Cárie
                                        </span>

                                        <span>
                                            <i class="odontograma-dot dot-restaurado"></i>
                                            Restaurado
                                        </span>

                                        <span>
                                            <i class="odontograma-dot dot-fraturado"></i>
                                            Fraturado
                                        </span>

                                        <span>
                                            <i class="odontograma-dot dot-em-tratamento"></i>
                                            Em tratamento
                                        </span>

                                        <span>
                                            <i class="odontograma-dot dot-ausente"></i>
                                            Ausente
                                        </span>

                                        <span>
                                            <i class="odontograma-dot dot-extraido"></i>
                                            Extraído
                                        </span>

                                    </div>
                                </div>

                                <div class="odontograma-modal-legenda">
                                    <div class="odontograma-modal-legenda-titulo">
                                        Legenda do implante
                                    </div>

                                    <div class="odontograma-modal-legenda-itens">

                                        <span>
                                            <i class="odontograma-dot dot-implante"></i>
                                            Planejado
                                        </span>

                                        <span>
                                            <i class="odontograma-dot dot-implante-tratamento"></i>
                                            Em tratamento
                                        </span>

                                        <span>
                                            <i class="odontograma-dot dot-implante-realizado"></i>
                                            Realizado
                                        </span>

                                    </div>
                                </div>

                            </div>

                        </div>


                        <!-- Superfícies -->
                        <div class="odontograma-modal-campo">

                            <div class="odontograma-modal-label-row">

                                <label>
                                    Superfícies
                                </label>

                                <span>
                                    Clique para selecionar
                                </span>

                            </div>


                            <div class="odontograma-superficies">

                                <button
                                    type="button"
                                    class="odontograma-superficie superficie-superior"
                                    data-superficie="vestibular">
                                    Vestibular
                                </button>

                                <button
                                    type="button"
                                    class="odontograma-superficie superficie-mesial"
                                    data-superficie="mesial">
                                    Mesial
                                </button>

                                <button
                                    type="button"
                                    class="odontograma-superficie superficie-centro"
                                    data-superficie="oclusal">
                                    Oclusal /
                                    Incisal
                                </button>

                                <button
                                    type="button"
                                    class="odontograma-superficie superficie-distal"
                                    data-superficie="distal">
                                    Distal
                                </button>

                                <button
                                    type="button"
                                    class="odontograma-superficie superficie-inferior"
                                    data-superficie="lingual_palatina">
                                    Lingual /
                                    Palatina
                                </button>

                            </div>

                        </div>


                        <!-- Observações -->
                        <div class="odontograma-modal-campo">

                            <label for="modalObservacaoDente">
                                Observações
                            </label>

                            <textarea
                                id="modalObservacaoDente"
                                rows="4"
                                placeholder="Digite uma observação sobre este dente..."></textarea>

                        </div>


                        <!-- Histórico -->
                        <div class="odontograma-modal-historico">

                            <div class="odontograma-modal-historico-header">

                                <div>
                                    <i class="fa-solid fa-clock-rotate-left"></i>

                                    <span>
                                        Histórico do dente
                                    </span>
                                </div>

                            </div>

                            <div
                                id="modalHistoricoDente"
                                class="odontograma-modal-historico-vazio">
                                Nenhum registro realizado.
                            </div>

                        </div>

                    </div>


                    <!-- Rodapé -->
                    <div class="odontograma-modal-footer">

                        <button
                            type="button"
                            class="odontograma-btn-secundario"
                            id="cancelarModalOdontograma">
                            Cancelar
                        </button>

                        <button
                            type="button"
                            class="odontograma-btn-salvar"
                            id="salvarOdontograma">
                            <i class="fa-solid fa-floppy-disk"></i>
                            Salvar
                        </button>

                    </div>

                </div>
            </div>

        </div>


        <!-- =====================================================
         ABAS FUTURAS
    ====================================================== -->

        <?php if (!$isPrint): ?>

            <div
                class="tab-content"
                id="tab-historico">

                <section class="info-card future-card">

                    <div class="future-icon">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>

                    <h2>Histórico</h2>

                    <p>
                        O histórico completo do paciente será disponibilizado
                        nesta seção.
                    </p>

                </section>

            </div>


            <div
                class="tab-content"
                id="tab-documentos">

                <section class="info-card future-card">

                    <div class="future-icon">
                        <i class="fa-regular fa-file-lines"></i>
                    </div>

                    <h2>Documentos</h2>

                    <p>
                        Os documentos do paciente serão disponibilizados
                        nesta seção.
                    </p>

                </section>

            </div>


            <div
                class="tab-content"
                id="tab-anexos">

                <section class="info-card future-card">

                    <div class="future-icon">
                        <i class="fa-solid fa-paperclip"></i>
                    </div>

                    <h2>Anexos</h2>

                    <p>
                        Os anexos do paciente serão disponibilizados
                        nesta seção.
                    </p>

                </section>

            </div>


            <div
                class="tab-content"
                id="tab-financeiro">

                <section class="info-card future-card">

                    <div class="future-icon">
                        <i class="fa-solid fa-dollar-sign"></i>
                    </div>

                    <h2>Financeiro</h2>

                    <p>
                        As informações financeiras do paciente serão
                        disponibilizadas nesta seção.
                    </p>

                </section>

            </div>

        <?php endif; ?>

    </main>




    <!-- =========================================================
     MODAL PROCEDIMENTO
========================================================== -->

    <?php if (!$isPrint): ?>

        <div
            class="modal"
            id="procedureModal">

            <div class="modal-content">

                <div class="modal-header">

                    <h2 id="modalTitle">
                        Procedimento
                    </h2>

                    <button
                        type="button"
                        class="modal-close"
                        id="modalClose">

                        &times;

                    </button>

                </div>


                <div
                    class="modal-body"
                    id="modalBody">
                </div>


                <div class="modal-footer">

                    <button
                        type="button"
                        class="modal-button"
                        id="modalCloseFooter">

                        Fechar

                    </button>

                </div>

            </div>

        </div>

    <?php endif; ?>


    <?php if (!$isPrint): ?>

        <?php if (!$isPrint): ?>

            <script>
                document.addEventListener('DOMContentLoaded', function() {

                    /*
                    |--------------------------------------------------------------------------
                    | HISTÓRICO DO ODONTOGRAMA
                    |--------------------------------------------------------------------------
                    */

                    const odontogramaHistorico =
                        <?= json_encode(
                            $odontogramaHistorico,
                            JSON_UNESCAPED_UNICODE |
                                JSON_UNESCAPED_SLASHES |
                                JSON_HEX_TAG |
                                JSON_HEX_AMP |
                                JSON_HEX_APOS |
                                JSON_HEX_QUOT
                        ) ?>;

                    window.odontogramaHistoricoTeste = odontogramaHistorico;


                    /*
                    |--------------------------------------------------------------------------
                    | MENU "MAIS"
                    |--------------------------------------------------------------------------
                    */

                    const moreButton = document.getElementById('moreButton');
                    const moreMenu = document.getElementById('moreMenu');

                    if (moreButton && moreMenu) {

                        moreButton.addEventListener('click', function(event) {

                            event.stopPropagation();

                            moreMenu.classList.toggle('show');

                        });

                        document.addEventListener('click', function() {

                            moreMenu.classList.remove('show');

                        });

                    }


                    /*
                    |--------------------------------------------------------------------------
                    | ABAS DO PRONTUÁRIO
                    |--------------------------------------------------------------------------
                    */

                    const tabs = document.querySelectorAll('.patient-tab');
                    const contents = document.querySelectorAll('.tab-content');

                    tabs.forEach(function(tab) {

                        tab.addEventListener('click', function(event) {

                            event.preventDefault();

                            const target = this.dataset.tab;

                            tabs.forEach(function(item) {

                                item.classList.remove('active');

                            });

                            contents.forEach(function(content) {

                                content.classList.remove('active');

                            });

                            this.classList.add('active');

                            const targetContent =
                                document.getElementById('tab-' + target);

                            if (targetContent) {

                                targetContent.classList.add('active');

                            }

                        });

                    });


                    /*
                    |--------------------------------------------------------------------------
                    | FUNÇÕES AUXILIARES
                    |--------------------------------------------------------------------------
                    */

                    function escapeHtml(valor) {

                        const div = document.createElement('div');

                        div.textContent = valor ?? '';

                        return div.innerHTML;

                    }


                    function formatarDataOdontograma(data) {

                        if (!data) {

                            return '-';

                        }

                        const partes = String(data).split(' ');

                        if (partes.length === 0) {

                            return data;

                        }

                        const dataPartes = partes[0].split('-');

                        if (dataPartes.length !== 3) {

                            return data;

                        }

                        return (
                            dataPartes[2] +
                            '/' +
                            dataPartes[1] +
                            '/' +
                            dataPartes[0]
                        );

                    }


                    function formatarMoedaModal(valor) {

                        return Number(valor || 0).toLocaleString(
                            'pt-BR', {
                                style: 'currency',
                                currency: 'BRL'
                            }
                        );

                    }


                    /*
                    |--------------------------------------------------------------------------
                    | MODAL DO ODONTOGRAMA
                    |--------------------------------------------------------------------------
                    */

                    const odontogramaModal =
                        document.getElementById('modalOdontograma');

                    if (odontogramaModal) {

                        const overlay =
                            odontogramaModal.querySelector(
                                '.odontograma-modal-overlay'
                            );

                        const fecharBtn =
                            document.getElementById(
                                'fecharModalOdontograma'
                            );

                        const cancelarBtn =
                            document.getElementById(
                                'cancelarModalOdontograma'
                            );

                        const titulo =
                            document.getElementById(
                                'modalOdontogramaTitulo'
                            );

                        const numeroDente =
                            document.getElementById(
                                'modalNumeroDente'
                            );

                        const estadoDente =
                            document.getElementById(
                                'modalEstadoDente'
                            );

                        const grupoImplante =
                            document.getElementById(
                                'grupoImplante'
                            );

                        const implanteStatus =
                            document.getElementById(
                                'implanteStatus'
                            );

                        const observacao =
                            document.getElementById(
                                'modalObservacaoDente'
                            );

                        const historicoDente =
                            document.getElementById(
                                'modalHistoricoDente'
                            );

                        const superficies =
                            odontogramaModal.querySelectorAll(
                                '.odontograma-superficie'
                            );

                        const regioesDente =
                            odontogramaModal.querySelectorAll(
                                '.dente-superficie'
                            );

                        const salvarBtn =
                            document.getElementById(
                                'salvarOdontograma'
                            );



                        function atualizarCampoImplante() {
                            if (!grupoImplante || !estadoDente) {
                                return;
                            }
                            const podePlanejarImplante =
                                estadoDente.value === 'ausente' ||
                                estadoDente.value === 'extraido';

                            grupoImplante.style.display =
                                podePlanejarImplante ? '' : 'none';

                            if (!podePlanejarImplante && implanteStatus) {
                                implanteStatus.value = 'nao_se_aplica';
                            }

                            // Atualiza a aparência do dente no modal
                            odontogramaDenteSvg.classList.remove(
                                'estado-nao_avaliado',
                                'estado-higido',
                                'estado-carie',
                                'estado-restaurado',
                                'estado-fraturado',
                                'estado-em_tratamento',
                                'estado-ausente',
                                'estado-extraido'
                            );

                            odontogramaDenteSvg.classList.add(
                                'estado-' + estadoDente.value
                            );
                        }

                        function atualizarSuperficiesPorEstado() {

                            if (!estadoDente) {
                                return;
                            }

                            const bloquearSuperficies =
                                estadoDente.value === 'ausente' ||
                                estadoDente.value === 'extraido';

                            superficies.forEach(function(superficie) {

                                superficie.disabled = bloquearSuperficies;

                                superficie.classList.toggle(
                                    'superficie-desabilitada',
                                    bloquearSuperficies
                                );

                                if (bloquearSuperficies) {
                                    superficie.classList.remove('is-selected');
                                }

                            });

                            regioesDente.forEach(function(regiao) {

                                regiao.classList.toggle(
                                    'superficie-desabilitada',
                                    bloquearSuperficies
                                );

                                if (bloquearSuperficies) {
                                    regiao.classList.remove('is-selected');
                                }

                            });
                        }

                        if (estadoDente) {
                            estadoDente.addEventListener(
                                'change',
                                atualizarCampoImplante
                            );
                        }

                        if (estadoDente) {
                            estadoDente.addEventListener(
                                'change',
                                atualizarSuperficiesPorEstado
                            );
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | DENTE SELECIONADO
                        |--------------------------------------------------------------------------
                        */

                        let denteSelecionado = null;


                        /*
                        |--------------------------------------------------------------------------
                        | NOMES DOS ESTADOS
                        |--------------------------------------------------------------------------
                        */

                        const estadoNomes = {

                            nao_avaliado: 'Não avaliado',

                            higido: 'Hígido',

                            carie: 'Cárie',

                            restaurado: 'Restaurado',

                            fraturado: 'Fraturado',

                            em_tratamento: 'Em tratamento',

                            ausente: 'Ausente',

                            extraido: 'Extraído'

                        };

                        const implanteNomes = {

                            nao_se_aplica: 'Não se aplica',

                            planejado: 'Planejado',

                            em_tratamento: 'Em tratamento',

                            realizado: 'Realizado'
                        };


                        /*
                        |--------------------------------------------------------------------------
                        | RENDERIZA HISTÓRICO
                        |--------------------------------------------------------------------------
                        */

                        function renderizarHistorico(denteId) {

                            if (!historicoDente) {

                                return;

                            }

                            const estadosGerais = [
                                'nao_avaliado',
                                'higido',
                                'carie',
                                'restaurado',
                                'fraturado',
                                'em_tratamento',
                                'ausente',
                                'extraido'
                            ];

                            const registros =
                                (odontogramaHistorico[denteId] || [])
                                .filter(function(registro) {

                                    if (
                                        registro.tipo === 'superficie' &&
                                        registro.superficie === 'geral' &&
                                        estadosGerais.includes(registro.condicao)
                                    ) {
                                        return false;
                                    }

                                    return true;
                                });


                            if (registros.length === 0) {

                                historicoDente.className =
                                    'odontograma-modal-historico-vazio';

                                historicoDente.innerHTML =
                                    'Nenhum registro realizado.';

                                return;

                            }


                            let html = '';


                            registros.forEach(function(registro) {

                                const ehEstadoGeral =
                                    registro.tipo === 'estado';

                                const ehImplante =
                                    registro.tipo === 'implante';


                                html += `
            <div class="odontograma-historico-item">

                <div class="odontograma-historico-header">

                    <strong>
    ${escapeHtml(
        ehImplante
            ? (
                implanteNomes[registro.condicao] ||
                registro.condicao ||
                'Status do implante'
            )
            : (
                estadoNomes[registro.condicao] ||
                registro.condicao ||
                'Registro'
            )
    )}
</strong>

                    <span>
                        ${escapeHtml(
                            formatarDataOdontograma(
                                registro.data_registro
                            )
                        )}
                    </span>

                </div>
        `;


                                /*
                                |--------------------------------------------------------------------------
                                | TIPO DO REGISTRO
                                |--------------------------------------------------------------------------
                                */

                                if (ehEstadoGeral) {

                                    html += `
        <div class="odontograma-historico-superficie">

            Estado geral do dente

        </div>
    `;

                                } else if (ehImplante) {

                                    html += `
        <div class="odontograma-historico-superficie">

            Status do implante

        </div>
    `;

                                } else {

                                    html += `
        <div class="odontograma-historico-superficie">

            Superfície:
            ${escapeHtml(
                registro.superficie || 'geral'
            )}

        </div>
    `;

                                }


                                /*
                                |--------------------------------------------------------------------------
                                | OBSERVAÇÃO
                                |--------------------------------------------------------------------------
                                */

                                if (
                                    registro.observacao &&
                                    registro.observacao.trim() !== ''
                                ) {

                                    html += `
                <div class="odontograma-historico-observacao">

                    ${escapeHtml(
                        registro.observacao
                    )}

                </div>
            `;

                                }


                                /*
                                |--------------------------------------------------------------------------
                                | PROCEDIMENTO
                                |--------------------------------------------------------------------------
                                */

                                if (
                                    !ehEstadoGeral &&
                                    registro.procedimento_id
                                ) {

                                    html += `
                <div class="odontograma-historico-vinculo">

                    Procedimento #
                    ${escapeHtml(
                        String(
                            registro.procedimento_id
                        )
                    )}

                </div>
            `;

                                }


                                /*
                                |--------------------------------------------------------------------------
                                | PLANO DE TRATAMENTO
                                |--------------------------------------------------------------------------
                                */

                                if (
                                    !ehEstadoGeral &&
                                    registro.plano_tratamento_item_id
                                ) {

                                    html += `
                <div class="odontograma-historico-vinculo">

                    Plano de tratamento #
                    ${escapeHtml(
                        String(
                            registro.plano_tratamento_item_id
                        )
                    )}

                </div>
            `;

                                }


                                html += `
            </div>
        `;

                            });


                            historicoDente.className =
                                'odontograma-modal-historico-lista';

                            historicoDente.innerHTML = html;

                        }


                        /*
                        |--------------------------------------------------------------------------
                        | ABRIR MODAL
                        |--------------------------------------------------------------------------
                        */

                        function abrirModal(dente) {

                            denteSelecionado = dente;


                            const numero =
                                dente.dataset.numero || '';

                            const estado = dente.dataset.estado || 'nao_avaliado';

                            odontogramaDenteSvg.classList.remove(
                                'estado-ausente',
                                'estado-extraido'
                            );

                            if (estado === 'ausente') {
                                odontogramaDenteSvg.classList.add('estado-ausente');
                            }

                            if (estado === 'extraido') {
                                odontogramaDenteSvg.classList.add('estado-extraido');
                            }

                            const implanteStatusSalvo =
                                dente.dataset.implanteStatus || 'nao_se_aplica';

                            titulo.textContent = 'Dente ' + numero;
                            numeroDente.textContent = numero;
                            estadoDente.value = estado;

                            atualizarSuperficiesPorEstado();

                            const denteId =
                                Number(
                                    dente.dataset.denteId || 0
                                );


                            /*
                            |--------------------------------------------------------------------------
                            | TÍTULO
                            |--------------------------------------------------------------------------
                            */

                            if (titulo) {

                                titulo.textContent =
                                    'Dente ' + numero;

                            }


                            /*
                            |--------------------------------------------------------------------------
                            | NÚMERO DO DENTE
                            |--------------------------------------------------------------------------
                            */

                            if (numeroDente) {

                                numeroDente.textContent =
                                    numero;

                            }


                            /*
                            |--------------------------------------------------------------------------
                            | ESTADO
                            |--------------------------------------------------------------------------
                            */

                            if (estadoDente) {

                                estadoDente.value =
                                    estado;

                            }

                            if (implanteStatus) {

                                implanteStatus.value =
                                    implanteStatusSalvo;

                            }

                            atualizarCampoImplante();


                            /*
                            |--------------------------------------------------------------------------
                            | OBSERVAÇÃO
                            |--------------------------------------------------------------------------
                            */

                            if (observacao) {

                                observacao.value = '';

                            }


                            /*
                            |--------------------------------------------------------------------------
                            | LIMPA SUPERFÍCIES
                            |--------------------------------------------------------------------------
                            */

                            superficies.forEach(function(superficie) {

                                superficie.classList.remove(
                                    'is-selected'
                                );

                            });

                            regioesDente.forEach(function(regiao) {

                                regiao.classList.remove(
                                    'is-selected',
                                    'estado-higido',
                                    'estado-carie',
                                    'estado-restaurado',
                                    'estado-fraturado',
                                    'estado-em_tratamento'
                                );

                            });

                            /*
|--------------------------------------------------------------------------
| RESTAURA SUPERFÍCIES ATUAIS
|--------------------------------------------------------------------------
*/

                            const registrosAtuais =
                                (odontogramaHistorico[denteId] || [])
                                .filter(function(registro) {

                                    return Number(registro.arquivado) === 0;

                                });

                            if (
                                estado === 'ausente' ||
                                estado === 'extraido'
                            ) {
                                registrosAtuais.length = 0;
                            }


                            /*
                            |--------------------------------------------------------------------------
                            | 1. ESTADO GERAL — BASE DO DENTE
                            |--------------------------------------------------------------------------
                            */

                            registrosAtuais.forEach(function(registro) {

                                if (registro.superficie !== 'geral') {
                                    return;
                                }

                                const superficiesDente = [
                                    'vestibular',
                                    'mesial',
                                    'oclusal',
                                    'distal',
                                    'lingual_palatina'
                                ];

                                superficiesDente.forEach(function(superficie) {

                                    const regiao =
                                        odontogramaModal.querySelector(
                                            '[data-superficie-dente="' +
                                            superficie +
                                            '"]'
                                        );

                                    if (!regiao) {
                                        return;
                                    }

                                    regiao.classList.remove(
                                        'estado-nao_avaliado',
                                        'estado-higido',
                                        'estado-carie',
                                        'estado-restaurado',
                                        'estado-fraturado',
                                        'estado-em_tratamento'
                                    );

                                    regiao.classList.add(
                                        'estado-' + registro.condicao
                                    );

                                });

                            });


                            /*
                            |--------------------------------------------------------------------------
                            | 2. ESTADOS ESPECÍFICOS — SOBRESCREVEM O ESTADO GERAL
                            |--------------------------------------------------------------------------
                            */

                            registrosAtuais.forEach(function(registro) {

                                const nomeSuperficie =
                                    registro.superficie;

                                if (
                                    !nomeSuperficie ||
                                    nomeSuperficie === 'geral'
                                ) {
                                    return;
                                }

                                const regiao =
                                    odontogramaModal.querySelector(
                                        '[data-superficie-dente="' +
                                        nomeSuperficie +
                                        '"]'
                                    );

                                if (!regiao) {
                                    return;
                                }


                                /*
                                |--------------------------------------------------------------------------
                                | Remove qualquer estado visual anterior
                                |--------------------------------------------------------------------------
                                */

                                regiao.classList.remove(
                                    'estado-nao_avaliado',
                                    'estado-higido',
                                    'estado-carie',
                                    'estado-restaurado',
                                    'estado-fraturado',
                                    'estado-em_tratamento'
                                );


                                /*
                                |--------------------------------------------------------------------------
                                | Aplica somente o estado específico desta superfície
                                |--------------------------------------------------------------------------
                                */

                                regiao.classList.add(
                                    'estado-' + registro.condicao
                                );

                            });


                            /*
                            |--------------------------------------------------------------------------
                            | HISTÓRICO
                            |--------------------------------------------------------------------------
                            */

                            renderizarHistorico(denteId);


                            /*
                            |--------------------------------------------------------------------------
                            | ABRE MODAL
                            |--------------------------------------------------------------------------
                            */

                            odontogramaModal.classList.add(
                                'is-open'
                            );

                            odontogramaModal.setAttribute(
                                'aria-hidden',
                                'false'
                            );


                            /*
                            |--------------------------------------------------------------------------
                            | BLOQUEIA SCROLL
                            |--------------------------------------------------------------------------
                            */

                            document.body.style.overflow =
                                'hidden';

                        }


                        /*
                        |--------------------------------------------------------------------------
                        | FECHAR MODAL
                        |--------------------------------------------------------------------------
                        */

                        function fecharModalOdontograma() {

                            odontogramaModal.classList.remove(
                                'is-open'
                            );

                            odontogramaModal.setAttribute(
                                'aria-hidden',
                                'true'
                            );

                            document.body.style.overflow = '';

                            denteSelecionado = null;

                        }


                        /*
                        |--------------------------------------------------------------------------
                        | CLIQUE NOS DENTES
                        |--------------------------------------------------------------------------
                        */

                        document.addEventListener('click', function(event) {

                            const dente = event.target.closest('.odontograma-dente');

                            if (!dente) {
                                return;
                            }

                            abrirModal(dente);

                        });


                        /*
|--------------------------------------------------------------------------
| SELEÇÃO DAS SUPERFÍCIES
|--------------------------------------------------------------------------
*/

                        superficies.forEach(function(superficie) {

                            superficie.addEventListener(
                                'click',
                                function(event) {

                                    event.stopPropagation();

                                    const nomeSuperficie =
                                        superficie.dataset.superficie;

                                    const selecionada =
                                        superficie.classList.toggle(
                                            'is-selected'
                                        );

                                    const regiao =
                                        odontogramaModal.querySelector(
                                            '[data-superficie-dente="' +
                                            nomeSuperficie +
                                            '"]'
                                        );

                                    if (regiao) {

                                        regiao.classList.toggle(
                                            'is-selected',
                                            selecionada
                                        );

                                    }

                                }
                            );

                        });

                        /*
|--------------------------------------------------------------------------
| CLIQUE DIRETAMENTE NA REGIÃO DO DENTE
|--------------------------------------------------------------------------
*/

                        regioesDente.forEach(function(regiao) {

                            regiao.addEventListener(
                                'click',
                                function(event) {

                                    event.stopPropagation();

                                    const nomeSuperficie =
                                        regiao.dataset.superficieDente;

                                    const botao =
                                        odontogramaModal.querySelector(
                                            '[data-superficie="' +
                                            nomeSuperficie +
                                            '"]'
                                        );

                                    if (!botao) {
                                        return;
                                    }

                                    const selecionada =
                                        regiao.classList.toggle(
                                            'is-selected'
                                        );

                                    botao.classList.toggle(
                                        'is-selected',
                                        selecionada
                                    );

                                }
                            );

                        });

                        /*
                        |--------------------------------------------------------------------------
                        | FECHAR PELO X
                        |--------------------------------------------------------------------------
                        */

                        if (fecharBtn) {

                            fecharBtn.addEventListener(
                                'click',
                                fecharModalOdontograma
                            );

                        }


                        /*
                        |--------------------------------------------------------------------------
                        | FECHAR PELO BOTÃO
                        |--------------------------------------------------------------------------
                        */

                        if (cancelarBtn) {

                            cancelarBtn.addEventListener(
                                'click',
                                fecharModalOdontograma
                            );

                        }


                        /*
                        |--------------------------------------------------------------------------
                        | FECHAR CLICANDO NO OVERLAY
                        |--------------------------------------------------------------------------
                        */

                        if (overlay) {

                            overlay.addEventListener(
                                'click',
                                fecharModalOdontograma
                            );

                        }


                        /*
                        |--------------------------------------------------------------------------
                        | ESC
                        |--------------------------------------------------------------------------
                        */

                        document.addEventListener(
                            'keydown',
                            function(event) {

                                if (
                                    event.key === 'Escape' &&
                                    odontogramaModal.classList.contains(
                                        'is-open'
                                    )
                                ) {

                                    fecharModalOdontograma();

                                }

                            }
                        );


                        /*
                        |--------------------------------------------------------------------------
                        | SALVAR ODONTOGRAMA
                        |--------------------------------------------------------------------------
                        */

                        if (salvarBtn) {

                            salvarBtn.addEventListener(
                                'click',
                                async function() {

                                    if (!denteSelecionado) {

                                        alert(
                                            'Nenhum dente selecionado.'
                                        );

                                        return;

                                    }


                                    const denteId =
                                        Number(
                                            denteSelecionado.dataset.denteId
                                        );


                                    const estado =
                                        estadoDente ?
                                        estadoDente.value :
                                        'nao_avaliado';

                                    const implanteStatusValor =
                                        implanteStatus ?
                                        implanteStatus.value :
                                        'nao_se_aplica';


                                    const observacaoValor =
                                        observacao ?
                                        observacao.value.trim() :
                                        '';


                                    const superficiesSelecionadas = [];


                                    superficies.forEach(
                                        function(superficie) {

                                            if (
                                                superficie.classList.contains(
                                                    'is-selected'
                                                )
                                            ) {

                                                superficiesSelecionadas.push(
                                                    superficie.dataset.superficie
                                                );

                                            }

                                        }
                                    );


                                    /*
                                    |--------------------------------------------------------------------------
                                    | FORM DATA
                                    |--------------------------------------------------------------------------
                                    */

                                    const dados =
                                        new FormData();


                                    dados.append(
                                        'csrf_token',
                                        '<?= htmlspecialchars(
                                                $_SESSION['csrf_token'] ?? '',
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>'
                                    );


                                    dados.append(
                                        'prontuario_id',
                                        '<?= (int)$id ?>'
                                    );


                                    dados.append(
                                        'odontograma_dente_id',
                                        denteId
                                    );


                                    dados.append(
                                        'estado_geral',
                                        estado
                                    );

                                    dados.append(
                                        'implante_status',
                                        implanteStatusValor
                                    );



                                    dados.append(
                                        'observacao',
                                        observacaoValor
                                    );


                                    superficiesSelecionadas.forEach(
                                        function(superficie) {

                                            dados.append(
                                                'superficies[]',
                                                superficie
                                            );

                                        }
                                    );


                                    /*
                                    |--------------------------------------------------------------------------
                                    | ESTADO DO BOTÃO
                                    |--------------------------------------------------------------------------
                                    */

                                    salvarBtn.disabled = true;

                                    salvarBtn.innerHTML = `
                        <i class="fa-solid fa-spinner fa-spin"></i>
                        Salvando...
                    `;


                                    try {

                                        /*
                                        |--------------------------------------------------------------------------
                                        | ENVIA PARA O PHP
                                        |--------------------------------------------------------------------------
                                        */

                                        const resposta =
                                            await fetch(
                                                'salvar_odontograma.php', {
                                                    method: 'POST',
                                                    body: dados
                                                }
                                            );


                                        /*
                                        |--------------------------------------------------------------------------
                                        | LÊ RESPOSTA
                                        |--------------------------------------------------------------------------
                                        */

                                        const resultado =
                                            await resposta.json();


                                        /*
                                        |--------------------------------------------------------------------------
                                        | VALIDA RESPOSTA
                                        |--------------------------------------------------------------------------
                                        */

                                        if (
                                            !resposta.ok ||
                                            !resultado.sucesso
                                        ) {

                                            throw new Error(
                                                resultado.mensagem ||
                                                'Não foi possível salvar o odontograma.'
                                            );

                                        }


                                        /*
                                        |--------------------------------------------------------------------------
                                        | SUCESSO
                                        |--------------------------------------------------------------------------
                                        */

                                        alert(
                                            resultado.mensagem ||
                                            'Odontograma salvo com sucesso.'
                                        );


                                        /*
                                        |--------------------------------------------------------------------------
                                        | FECHA
                                        |--------------------------------------------------------------------------
                                        */

                                        fecharModalOdontograma();


                                        /*
                                        |--------------------------------------------------------------------------
                                        | RECARREGA PRONTUÁRIO
                                        |--------------------------------------------------------------------------
                                        */

                                        window.location.reload();


                                    } catch (erro) {

                                        console.error(
                                            'Erro ao salvar odontograma:',
                                            erro
                                        );


                                        alert(
                                            erro.message ||
                                            'Ocorreu um erro ao salvar o odontograma.'
                                        );


                                    } finally {

                                        salvarBtn.disabled =
                                            false;

                                        salvarBtn.innerHTML = `
                            <i class="fa-solid fa-floppy-disk"></i>
                            Salvar
                        `;

                                    }

                                }
                            );

                        }

                    }


                    /*
                    |--------------------------------------------------------------------------
                    | MODAL DE PROCEDIMENTO
                    |--------------------------------------------------------------------------
                    */

                    const procedureModal =
                        document.getElementById(
                            'procedureModal'
                        );

                    const procedureModalTitle =
                        document.getElementById(
                            'modalTitle'
                        );

                    const procedureModalBody =
                        document.getElementById(
                            'modalBody'
                        );

                    const procedureModalClose =
                        document.getElementById(
                            'modalClose'
                        );

                    const procedureModalCloseFooter =
                        document.getElementById(
                            'modalCloseFooter'
                        );


                    function fecharModalProcedimento() {

                        if (procedureModal) {

                            procedureModal.classList.remove(
                                'show'
                            );

                        }

                    }


                    /*
                    |--------------------------------------------------------------------------
                    | VISUALIZAR PROCEDIMENTO
                    |--------------------------------------------------------------------------
                    */

                    document
                        .querySelectorAll('.btn-visualizar')
                        .forEach(function(button) {

                            button.addEventListener(
                                'click',
                                function() {

                                    if (
                                        !procedureModal ||
                                        !procedureModalTitle ||
                                        !procedureModalBody
                                    ) {

                                        return;

                                    }


                                    const titulo =
                                        this.dataset.titulo ||
                                        'Procedimento';


                                    const descricao =
                                        this.dataset.descricao ||
                                        '';


                                    const medicamentos =
                                        this.dataset.medicamentos ||
                                        '';


                                    const data =
                                        this.dataset.data ||
                                        '';


                                    const valorMateriais =
                                        Number(
                                            this.dataset.valorMateriais ||
                                            0
                                        );


                                    const valorMaoObra =
                                        Number(
                                            this.dataset.valorMaoObra ||
                                            0
                                        );


                                    const valorFinal =
                                        Number(
                                            this.dataset.valorFinal ||
                                            0
                                        );


                                    const valorOrcadoRaw =
                                        this.dataset.valorOrcado ||
                                        '';


                                    const valorOrcado =
                                        valorOrcadoRaw === '' ?
                                        null :
                                        Number(
                                            valorOrcadoRaw
                                        );


                                    const diferenca =
                                        valorOrcado !== null ?
                                        valorFinal -
                                        valorOrcado :
                                        null;


                                    procedureModalTitle.textContent =
                                        titulo;


                                    let html = '';


                                    /*
                                    |--------------------------------------------------------------------------
                                    | DATA
                                    |--------------------------------------------------------------------------
                                    */

                                    html += `
                        <div class="modal-info">

                            <strong>
                                Data
                            </strong>

                            <span>
                                ${escapeHtml(data)}
                            </span>

                        </div>
                    `;


                                    /*
                                    |--------------------------------------------------------------------------
                                    | DESCRIÇÃO
                                    |--------------------------------------------------------------------------
                                    */

                                    if (
                                        descricao.trim() !== ''
                                    ) {

                                        html += `
                            <div class="modal-section">

                                <strong>
                                    Descrição
                                </strong>

                                <p>
                                    ${escapeHtml(
                                        descricao
                                    ).replace(
                                        /\n/g,
                                        '<br>'
                                    )}
                                </p>

                            </div>
                        `;

                                    }


                                    /*
                                    |--------------------------------------------------------------------------
                                    | MEDICAMENTOS
                                    |--------------------------------------------------------------------------
                                    */

                                    if (
                                        medicamentos.trim() !== ''
                                    ) {

                                        html += `
                            <div class="modal-section">

                                <strong>
                                    Medicamentos receitados
                                </strong>

                                <p>
                                    ${escapeHtml(
                                        medicamentos
                                    ).replace(
                                        /\n/g,
                                        '<br>'
                                    )}
                                </p>

                            </div>
                        `;

                                    }


                                    /*
                                    |--------------------------------------------------------------------------
                                    | VALORES
                                    |--------------------------------------------------------------------------
                                    */

                                    html += `
                        <div class="modal-info">

                            <strong>
                                Valores
                            </strong>

                            <span>

                                Materiais:
                                ${formatarMoedaModal(
                                    valorMateriais
                                )}

                                |

                                Mão de obra:
                                ${formatarMoedaModal(
                                    valorMaoObra
                                )}

                                |

                                Final realizado:
                                <strong>
                                    ${formatarMoedaModal(
                                        valorFinal
                                    )}
                                </strong>

                            </span>

                        </div>
                    `;


                                    /*
                                    |--------------------------------------------------------------------------
                                    | ORÇAMENTO
                                    |--------------------------------------------------------------------------
                                    */

                                    if (
                                        valorOrcado !== null
                                    ) {

                                        let textoDiferenca;


                                        if (diferenca > 0) {

                                            textoDiferenca =
                                                `+${formatarMoedaModal(
                                    diferenca
                                )} acima do orçamento`;

                                        } else if (
                                            diferenca < 0
                                        ) {

                                            textoDiferenca =
                                                `${formatarMoedaModal(
                                    Math.abs(
                                        diferenca
                                    )
                                )} abaixo do orçamento`;

                                        } else {

                                            textoDiferenca =
                                                'Sem diferença em relação ao orçamento';

                                        }


                                        html += `
                            <div class="modal-info">

                                <strong>
                                    Orçamento
                                </strong>

                                <span>

                                    Orçado:
                                    ${formatarMoedaModal(
                                        valorOrcado
                                    )}

                                    |

                                    ${escapeHtml(
                                        textoDiferenca
                                    )}

                                </span>

                            </div>
                        `;

                                    }


                                    /*
                                    |--------------------------------------------------------------------------
                                    | NENHUMA INFORMAÇÃO
                                    |--------------------------------------------------------------------------
                                    */

                                    if (
                                        descricao.trim() === '' &&
                                        medicamentos.trim() === ''
                                    ) {

                                        html += `
                            <p class="modal-empty">

                                Nenhuma informação adicional registrada.

                            </p>
                        `;

                                    }


                                    procedureModalBody.innerHTML =
                                        html;


                                    procedureModal.classList.add(
                                        'show'
                                    );

                                }
                            );

                        });


                    /*
                    |--------------------------------------------------------------------------
                    | FECHAR MODAL DE PROCEDIMENTO
                    |--------------------------------------------------------------------------
                    */

                    if (procedureModalClose) {

                        procedureModalClose.addEventListener(
                            'click',
                            fecharModalProcedimento
                        );

                    }


                    if (procedureModalCloseFooter) {

                        procedureModalCloseFooter.addEventListener(
                            'click',
                            fecharModalProcedimento
                        );

                    }


                    if (procedureModal) {

                        procedureModal.addEventListener(
                            'click',
                            function(event) {

                                if (
                                    event.target ===
                                    procedureModal
                                ) {

                                    fecharModalProcedimento();

                                }

                            }
                        );

                    }


                    /*
                    |--------------------------------------------------------------------------
                    | ESC GLOBAL
                    |--------------------------------------------------------------------------
                    */

                    document.addEventListener(
                        'keydown',
                        function(event) {

                            if (
                                event.key !== 'Escape'
                            ) {

                                return;

                            }


                            /*
                            | Fecha modal odontograma
                            */

                            const odontogramaModal =
                                document.getElementById(
                                    'modalOdontograma'
                                );


                            if (
                                odontogramaModal &&
                                odontogramaModal.classList.contains(
                                    'is-open'
                                )
                            ) {

                                odontogramaModal.classList.remove(
                                    'is-open'
                                );

                                odontogramaModal.setAttribute(
                                    'aria-hidden',
                                    'true'
                                );

                                document.body.style.overflow =
                                    '';

                            }


                            /*
                            | Fecha modal procedimento
                            */

                            if (
                                procedureModal &&
                                procedureModal.classList.contains(
                                    'show'
                                )
                            ) {

                                fecharModalProcedimento();

                            }

                        }
                    );

                });
            </script>

        <?php endif; ?>
    <?php endif; ?>


</body>

</html>