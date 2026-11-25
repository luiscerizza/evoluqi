<?php

require_once 'config/auth.php';
exigirLogin();

require_once 'conexao/conexao.php';

/*
|--------------------------------------------------------------------------
| SALVAR PROCEDIMENTO (novo / edição)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        if (
            empty($_POST['csrf_token']) ||
            empty($_SESSION['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            throw new Exception('Token de segurança inválido.');
        }

        $dados = json_decode($_POST['dados'] ?? '', true);
        if (!is_array($dados)) {
            throw new Exception('Dados do procedimento inválidos.');
        }

        $prontuario_id = (int)($dados['prontuario_id'] ?? 0);
        $procedimento_id = !empty($dados['procedimento_id']) ? (int)$dados['procedimento_id'] : null;
        $titulo = trim($dados['titulo'] ?? '');
        $data_procedimento = $dados['data_procedimento'] ?? '';
        $descricao = trim($dados['descricao'] ?? '');
        $medicamentos = trim($dados['medicamentos'] ?? '');
        $valor_mao_obra = max(0, (float)($dados['valor_mao_obra'] ?? 0));
        $valor_final = max(0, (float)($dados['valor_final'] ?? 0));
        $agendamento_id_post = !empty($dados['agendamento_id']) ? (int)$dados['agendamento_id'] : null;
        $materiais_post = $dados['materiais'] ?? [];

        if ($prontuario_id <= 0 || $titulo === '' || $data_procedimento === '') {
            throw new Exception('Preencha os dados obrigatórios do procedimento.');
        }

        $stmt = $pdo->prepare('SELECT id FROM prontuarios WHERE id = ? LIMIT 1');
        $stmt->execute([$prontuario_id]);
        if (!$stmt->fetchColumn()) {
            throw new Exception('Prontuário não encontrado.');
        }

        /*
        |--------------------------------------------------------------------------
        | Orçamento é opcional
        |--------------------------------------------------------------------------
        |
        | O procedimento pode nascer diretamente do prontuário, sem orçamento.
        | Se vier de um agendamento, apenas validamos o agendamento; não exigimos
        | que exista um orçamento aceito.
        |
        | Quando houver um orçamento aceito para o agendamento, ele pode ser
        | associado ao procedimento como referência, mas isso não é obrigatório.
        |
        |--------------------------------------------------------------------------
        */
        $orcamento_id = null;

        if ($agendamento_id_post) {
            $stmt = $pdo->prepare('SELECT paciente_id, status FROM agendamentos WHERE id = ? LIMIT 1');
            $stmt->execute([$agendamento_id_post]);
            $ag = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ag || (int)$ag['paciente_id'] !== $prontuario_id || $ag['status'] !== 'confirmado') {
                throw new Exception('O agendamento precisa estar confirmado e pertencer ao paciente.');
            }

            // Se existir orçamento aceito, usamos apenas como referência.
            $stmt = $pdo->prepare("
                SELECT id
                FROM orcamentos
                WHERE agendamento_id = ?
                  AND paciente_id = ?
                  AND status = 'aceito'
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([$agendamento_id_post, $prontuario_id]);
            $orcamento_id = $stmt->fetchColumn();

            $orcamento_id = $orcamento_id
                ? (int)$orcamento_id
                : null;
        }

        $pdo->beginTransaction();

        $materiais_normalizados = [];
        foreach ($materiais_post as $material) {
            $estoque_id = (int)($material['estoque_id'] ?? 0);
            $quantidade = (float)($material['quantidade'] ?? 0);
            $subtotal_informado = array_key_exists('subtotal', $material)
                ? (float)$material['subtotal']
                : null;

            if ($estoque_id <= 0 || $quantidade <= 0) {
                throw new Exception('Material ou quantidade inválidos.');
            }

            if ($subtotal_informado !== null && $subtotal_informado < 0) {
                throw new Exception('O subtotal do material não pode ser negativo.');
            }

            if (isset($materiais_normalizados[$estoque_id])) {
                throw new Exception('O mesmo material foi adicionado mais de uma vez.');
            }

            $materiais_normalizados[$estoque_id] = [
                'quantidade' => $quantidade,
                'subtotal_informado' => $subtotal_informado
            ];
        }

        // Na edição, devolvemos ao estoque os materiais do procedimento antigo.
        if ($procedimento_id) {
            $stmt = $pdo->prepare('
    SELECT
        id,
        paciente_id,
        orcamento_id,
        arquivado
    FROM procedimentos
    WHERE id = ?
    LIMIT 1
    FOR UPDATE
');

            $stmt->execute([$procedimento_id]);

            $procedimento_antigo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                !$procedimento_antigo ||
                (int)$procedimento_antigo['paciente_id'] !== $prontuario_id
            ) {
                throw new Exception('Procedimento não encontrado para edição.');
            }

            if ((int)$procedimento_antigo['arquivado'] === 1) {
                throw new Exception(
                    'Este procedimento está arquivado e não pode ser editado.'
                );
            }

            if (!$orcamento_id && !empty($procedimento_antigo['orcamento_id'])) {
                $orcamento_id = (int)$procedimento_antigo['orcamento_id'];
            }

            /*
            | O orçamento é opcional. Se o procedimento antigo tiver um
            | orçamento associado, preservamos a referência sem exigir que
            | ele continue com status "aceito".
            */

            $stmt = $pdo->prepare('SELECT estoque_id, quantidade FROM procedimento_materiais WHERE procedimento_id = ?');
            $stmt->execute([$procedimento_id]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $antigo) {
                $pdo->prepare('UPDATE estoque SET quantidade = quantidade + ? WHERE id = ?')
                    ->execute([(float)$antigo['quantidade'], (int)$antigo['estoque_id']]);
            }

            $pdo->prepare('DELETE FROM procedimento_materiais WHERE procedimento_id = ?')->execute([$procedimento_id]);
        }

        $valor_materiais = 0.0;
        foreach ($materiais_normalizados as $estoque_id => $material_data) {
            $quantidade = (float)$material_data['quantidade'];

            $stmt = $pdo->prepare('SELECT nome, quantidade, unidade, valor_item FROM estoque WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$estoque_id]);
            $estoque = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$estoque) {
                throw new Exception('Material não encontrado no estoque.');
            }

            if ((float)$estoque['quantidade'] < $quantidade) {
                throw new Exception(
                    'Estoque insuficiente para "' . $estoque['nome'] . '". Disponível: ' .
                        $estoque['quantidade'] . ' ' . $estoque['unidade'] . '.'
                );
            }

            $subtotal_informado = $material_data['subtotal_informado'];

            if ($subtotal_informado === null) {
                $valor_unitario = (float)$estoque['valor_item'];
                $valor_total = $valor_unitario * $quantidade;
            } else {
                $valor_total = max(0, $subtotal_informado);
                $valor_unitario = $quantidade > 0 ? $valor_total / $quantidade : 0;
            }

            $valor_materiais += $valor_total;

            $pdo->prepare('UPDATE estoque SET quantidade = quantidade - ? WHERE id = ?')
                ->execute([$quantidade, $estoque_id]);

            $materiais_normalizados[$estoque_id] = [
                'quantidade' => $quantidade,
                'valor_unitario' => $valor_unitario,
                'valor_total' => $valor_total
            ];
        }

        if ($procedimento_id) {
            $stmt = $pdo->prepare('UPDATE procedimentos SET titulo = ?, descricao = ?, medicamentos = ?, valor_materiais = ?, valor_mao_obra = ?, valor_final = ?, data_procedimento = ?, orcamento_id = ?, agendamento_id = ? WHERE id = ?');
            $stmt->execute([
                $titulo,
                $descricao ?: null,
                $medicamentos ?: null,
                $valor_materiais,
                $valor_mao_obra,
                $valor_final,
                $data_procedimento,
                $orcamento_id,
                $agendamento_id_post,
                $procedimento_id
            ]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO procedimentos (paciente_id, orcamento_id, titulo, descricao, medicamentos, valor_materiais, valor_mao_obra, valor_final, data_procedimento, agendamento_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $prontuario_id,
                $orcamento_id,
                $titulo,
                $descricao ?: null,
                $medicamentos ?: null,
                $valor_materiais,
                $valor_mao_obra,
                $valor_final,
                $data_procedimento,
                $agendamento_id_post
            ]);
            $procedimento_id = (int)$pdo->lastInsertId();
        }

        $stmtMaterial = $pdo->prepare('INSERT INTO procedimento_materiais (procedimento_id, estoque_id, quantidade, valor_unitario, valor_total) VALUES (?, ?, ?, ?, ?)');
        foreach ($materiais_normalizados as $estoque_id => $material) {
            $stmtMaterial->execute([
                $procedimento_id,
                $estoque_id,
                $material['quantidade'],
                $material['valor_unitario'],
                $material['valor_total']
            ]);
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'procedimento_id' => $procedimento_id,
            'valor_materiais' => $valor_materiais,
            'valor_final' => $valor_final
        ]);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Verificação do prontuário

|--------------------------------------------------------------------------
*/

if (!isset($_GET['prontuario_id']) || !is_numeric($_GET['prontuario_id'])) {
    die("Prontuário não especificado.");
}

$prontuario_id = (int) $_GET['prontuario_id'];
$procedimento_id = isset($_GET['procedimento_id']) && is_numeric($_GET['procedimento_id']) ? (int)$_GET['procedimento_id'] : null;
$agendamento_id = isset($_GET['agendamento_id']) && is_numeric($_GET['agendamento_id']) ? (int)$_GET['agendamento_id'] : null;

/*
|--------------------------------------------------------------------------
| Agendamento
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT id, paciente
    FROM prontuarios
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$prontuario_id]);
$prontuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$prontuario) {
    die("Prontuário não encontrado.");
}

$paciente = $prontuario['paciente'];

$agendamento = $agendamento ?? null;

if ($procedimento_id === null && $agendamento_id !== null) {
    $stmt = $pdo->prepare("
        SELECT id, paciente_id, paciente_nome, procedimento, data, horario, status
        FROM agendamentos
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$agendamento_id]);
    $agendamento = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$agendamento) {
        die("Agendamento não encontrado.");
    }

    if ((int)$agendamento['paciente_id'] !== $prontuario_id) {
        die("O agendamento não pertence a este paciente.");
    }

    if ($agendamento['status'] !== 'confirmado') {
        die("Este agendamento ainda não está confirmado.");
    }

    // Orçamento é opcional. Se existir um orçamento aceito para este
    // agendamento, ele é apenas uma referência visual/administrativa.
    $stmt = $pdo->prepare("
        SELECT id
        FROM orcamentos
        WHERE agendamento_id = ?
          AND paciente_id = ?
          AND status = 'aceito'
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$agendamento_id, $prontuario_id]);
    $orcamento_id = $stmt->fetchColumn();

    $orcamento_id = $orcamento_id
        ? (int)$orcamento_id
        : null;
}

/*
|--------------------------------------------------------------------------
| Buscar materiais disponíveis no estoque
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT id, nome, quantidade, unidade, estoque_minimo, valor_item, valor_sugerido
    FROM estoque
    ORDER BY nome ASC
");
$materiais = $stmt->fetchAll(PDO::FETCH_ASSOC);

$procedimento = null;
$materiaisExistentes = [];

if ($procedimento_id) {
    $stmt = $pdo->prepare("SELECT * FROM procedimentos WHERE id = ? AND paciente_id = ? LIMIT 1");
    $stmt->execute([$procedimento_id, $prontuario_id]);
    $procedimento = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$procedimento) {
        die("Procedimento não encontrado.");
    }

    $stmt = $pdo->prepare("
        SELECT estoque_id, quantidade, valor_total
        FROM procedimento_materiais
        WHERE procedimento_id = ?
    ");
    $stmt->execute([$procedimento_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $materiaisExistentes[(int)$m['estoque_id']] = [
            'quantidade' => (float)$m['quantidade'],
            'subtotal' => (float)$m['valor_total']
        ];
    }
}

foreach ($materiais as &$material) {
    $existente = $materiaisExistentes[(int)$material['id']] ?? null;
    $quantidadeExistente = is_array($existente)
        ? (float)$existente['quantidade']
        : (float)$existente;

    $material['quantidade_disponivel'] =
        (float)$material['quantidade'] + $quantidadeExistente;
}
unset($material);

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= $procedimento_id ? 'Editar Procedimento -' : 'Novo Procedimento -' ?>
        <?= htmlspecialchars($paciente) ?>
        | Dentech
    </title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/variables.css">
    <link rel="stylesheet" href="css/layout.css">
    <link rel="stylesheet" href="css/add_procedimento.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="icon" type="image/png" href="img/icon.PNG">

</head>

<body>

    <?php include 'navbar.php'; ?>

    <div class="container">

        <main>

            <h1><?= $procedimento_id ? 'Editar Procedimento' : 'Novo Procedimento' ?></h1>

            <p class="subtitle">
                Paciente:
                <strong><?= htmlspecialchars($paciente) ?></strong>
            </p>

            <?php if ($agendamento): ?>

                <div class="agendamento-info">

                    <strong>Agendamento confirmado</strong>

                    <span>
                        <?= htmlspecialchars($agendamento['procedimento']) ?>
                    </span>

                    <span>
                        <?= date('d/m/Y', strtotime($agendamento['data'])) ?>
                        às
                        <?= date('H:i', strtotime($agendamento['horario'])) ?>
                    </span>

                </div>

            <?php endif; ?>


            <form id="procedimentoForm">

                <input
                    type="hidden"
                    id="prontuarioId"
                    value="<?= $prontuario_id ?>">

                <input
                    type="hidden"
                    id="agendamentoId"
                    value="<?= $agendamento_id ?? '' ?>">

                <input
                    type="hidden"
                    id="procedimentoId"
                    value="<?= $procedimento_id ?? '' ?>">

                <!-- =====================================================
                 DADOS DO PROCEDIMENTO
            ====================================================== -->

                <section class="form-section">

                    <h2>Dados do procedimento</h2>

                    <div class="form-group">

                        <label for="titulo">
                            Nome do procedimento
                        </label>

                        <input
                            type="text"
                            id="titulo"
                            value="<?= htmlspecialchars($procedimento['titulo'] ?? '') ?>"
                            placeholder="Ex: Clareamento dental, Restauração..."
                            required>

                    </div>


                    <div class="form-group">

                        <label for="data_procedimento">
                            Data do procedimento
                        </label>

                        <input
                            type="date"
                            id="data_procedimento"
                            value="<?= htmlspecialchars($procedimento['data_procedimento'] ?? date('Y-m-d')) ?>"
                            required>

                    </div>


                    <div class="form-group">

                        <label for="descricao">
                            Descrição do procedimento
                        </label>

                        <textarea
                            id="descricao"
                            placeholder="Detalhes clínicos, observações, etc..."><?= htmlspecialchars($procedimento['descricao'] ?? '') ?></textarea>

                    </div>


                    <div class="form-group">

                        <label for="medicamentos">
                            Medicamentos receitados
                        </label>

                        <textarea
                            id="medicamentos"
                            placeholder="Ex: Amoxicilina 500mg – 1 comprimido de 8/8h por 7 dias"><?= htmlspecialchars($procedimento['medicamentos'] ?? '') ?></textarea>

                    </div>

                </section>


                <!-- =====================================================
                 MATERIAIS
            ====================================================== -->

                <section class="form-section">

                    <div class="section-header">

                        <div>

                            <h2>Materiais utilizados</h2>

                            <p>
                                Selecione os materiais utilizados no procedimento.
                            </p>

                        </div>

                        <button
                            type="button"
                            class="btn btn-add-material"
                            id="btnAdicionarMaterial">
                            + Adicionar material
                        </button>

                    </div>


                    <div id="materiaisContainer">

                        <div class="material-row">

                            <div class="material-field material-select">

                                <label>Material</label>

                                <select
                                    class="material"
                                    required>

                                    <option value="">
                                        Selecione um material
                                    </option>

                                    <?php foreach ($materiais as $material): ?>

                                        <option
                                            value="<?= (int)$material['id'] ?>"
                                            <?= isset($materiaisExistentes[(int)$material['id']]) ? 'selected' : '' ?>
                                            data-estoque="<?= htmlspecialchars($material['quantidade_disponivel']) ?>"
                                            data-unidade="<?= htmlspecialchars($material['unidade']) ?>"
                                            data-valor="<?= htmlspecialchars($material['valor_item']) ?>"
                                            data-sugerido="<?= htmlspecialchars($material['valor_sugerido']) ?>">

                                            <?= htmlspecialchars($material['nome']) ?>

                                            —
                                            Estoque:
                                            <?= htmlspecialchars($material['quantidade']) ?>
                                            <?= htmlspecialchars($material['unidade']) ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>


                            <div class="material-field">

                                <label>Quantidade</label>

                                <input
                                    type="number"
                                    class="quantidade-material"
                                    min="0.01"
                                    step="0.01"
                                    value="1"
                                    required>

                                <small class="estoque-disponivel">
                                    Selecione um material
                                </small>

                            </div>


                            <div class="material-field">

                                <label>Valor do item</label>

                                <input
                                    type="text"
                                    class="valor-material"
                                    value="R$ 0,00"
                                    readonly>

                            </div>


                            <div class="material-field">

                                <label>Valor sugerido</label>

                                <input
                                    type="text"
                                    class="valor-sugerido-material"
                                    value="R$ 0,00"
                                    readonly>

                            </div>


                            <div class="material-field">

                                <label>Subtotal</label>

                                <input
                                    type="number"
                                    class="subtotal-material"
                                    min="0"
                                    step="0.01"
                                    value="0.00">

                            </div>


                            <button
                                type="button"
                                class="btn-remove-material"
                                title="Remover material">
                                ×
                            </button>

                        </div>

                    </div>


                    <!-- =================================================
                     RESUMO DOS MATERIAIS
                ================================================== -->

                    <div class="materiais-resumo">

                        <div>

                            <span>
                                Custo dos materiais
                            </span>

                            <strong id="totalMateriais">
                                R$ 0,00
                            </strong>

                        </div>


                        <div>

                            <span>
                                Valor sugerido dos materiais
                            </span>

                            <strong id="totalSugerido">
                                R$ 0,00
                            </strong>

                        </div>

                    </div>

                </section>


                <!-- =====================================================
                 VALORES
            ====================================================== -->

                <section class="form-section">

                    <h2>Valores do procedimento</h2>


                    <div class="valor-grid">

                        <div class="form-group">

                            <label>
                                Custo dos materiais
                            </label>

                            <input
                                type="text"
                                id="valorMateriais"
                                value="R$ 0,00"
                                readonly>

                        </div>


                        <div class="form-group">

                            <label>
                                Valor sugerido dos materiais
                            </label>

                            <input
                                type="text"
                                id="valorSugerido"
                                value="R$ 0,00"
                                readonly>

                        </div>


                        <div class="form-group">

                            <label for="maoObra">
                                Mão de obra / procedimento
                            </label>

                            <input
                                type="number"
                                id="maoObra"
                                min="0"
                                step="0.01"
                                value="<?= htmlspecialchars($procedimento['valor_mao_obra'] ?? '0.00') ?>">

                        </div>

                    </div>


                    <div class="valor-final-box">
                        <div>
                            <span>Valor sugerido do procedimento</span>
                            <strong id="valorFinalSugerido">R$ 0,00</strong>
                        </div>
                        <div class="valor-final-editavel">
                            <label for="valorFinalInput">Valor final cobrado</label>
                            <input type="number" id="valorFinalInput" min="0" step="0.01" value="<?= htmlspecialchars($procedimento['valor_final'] ?? '0.00') ?>">
                        </div>
                    </div>

                </section>


                <!-- =====================================================
                 AÇÕES
            ====================================================== -->

                <div class="actions">

                    <button
                        type="button"
                        class="btn btn-cancel"
                        onclick="voltar()">
                        Cancelar
                    </button>

                    <button
                        type="submit"
                        class="btn btn-save"
                        id="btnSalvar">
                        <?= $procedimento_id ? 'Salvar Alterações' : 'Adicionar Procedimento' ?>
                    </button>

                </div>

            </form>

        </main>

    </div>


    <script>
        const procedimentoExistente = <?= json_encode(
                                            $procedimento ?: null,
                                            JSON_UNESCAPED_UNICODE |
                                                JSON_UNESCAPED_SLASHES |
                                                JSON_HEX_TAG |
                                                JSON_HEX_APOS |
                                                JSON_HEX_AMP |
                                                JSON_HEX_QUOT
                                        ) ?>;

        const materiaisDisponiveis = <?= json_encode(
                                            $materiais,
                                            JSON_UNESCAPED_UNICODE |
                                                JSON_UNESCAPED_SLASHES |
                                                JSON_HEX_TAG |
                                                JSON_HEX_APOS |
                                                JSON_HEX_AMP |
                                                JSON_HEX_QUOT
                                        ) ?>;

        const materiaisExistentes = <?= json_encode($materiaisExistentes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;


        /*
        |--------------------------------------------------------------------------
        | Formatação monetária
        |--------------------------------------------------------------------------
        */

        function formatarMoeda(valor) {

            valor = Number(valor) || 0;

            return valor.toLocaleString('pt-BR', {
                style: 'currency',
                currency: 'BRL'
            });

        }


        /*
        |--------------------------------------------------------------------------
        | Criar opções do estoque
        |--------------------------------------------------------------------------
        */

        function gerarOpcoesMateriais() {

            let html = `
        <option value="">
            Selecione um material
        </option>
    `;

            materiaisDisponiveis.forEach(material => {

                html += `
            <option
                value="${material.id}"
                data-estoque="${material.quantidade_disponivel}"
                data-unidade="${material.unidade}"
                data-valor="${material.valor_item}"
                data-sugerido="${material.valor_sugerido}"
            >
                ${escapeHtml(material.nome)}
                —
                Estoque: ${material.quantidade} ${escapeHtml(material.unidade)}
            </option>
        `;

            });

            return html;

        }


        /*
        |--------------------------------------------------------------------------
        | Escape HTML
        |--------------------------------------------------------------------------
        */

        function escapeHtml(text) {

            const div = document.createElement('div');

            div.textContent = text ?? '';

            return div.innerHTML;

        }


        /*
        |--------------------------------------------------------------------------
        | Atualizar linha do material
        |--------------------------------------------------------------------------
        */

        function atualizarLinha(linha) {

            const select = linha.querySelector('.material');

            const quantidadeInput =
                linha.querySelector('.quantidade-material');

            const valorInput =
                linha.querySelector('.valor-material');

            const sugeridoInput =
                linha.querySelector('.valor-sugerido-material');

            const subtotalInput =
                linha.querySelector('.subtotal-material');

            const estoqueTexto =
                linha.querySelector('.estoque-disponivel');

            const option =
                select.options[select.selectedIndex];


            if (!option || !option.value) {

                valorInput.value = 'R$ 0,00';

                sugeridoInput.value = 'R$ 0,00';

                subtotalInput.value = '0.00';

                estoqueTexto.textContent =
                    'Selecione um material';

                return;

            }


            const estoque =
                Number(option.dataset.estoque) || 0;

            const valor =
                Number(option.dataset.valor) || 0;

            const sugerido =
                Number(option.dataset.sugerido) || 0;

            const quantidade =
                Number(quantidadeInput.value) || 0;


            valorInput.value =
                formatarMoeda(valor);

            sugeridoInput.value =
                formatarMoeda(sugerido);


            if (!subtotalInput.dataset.manual || Number(subtotalInput.value) <= 0) {
                subtotalInput.value = (valor * quantidade).toFixed(2);
                subtotalInput.dataset.manual = 'false';
            }


            estoqueTexto.textContent =
                `Disponível: ${estoque} ${option.dataset.unidade}`;


            if (quantidade > estoque) {

                estoqueTexto.classList.add('estoque-insuficiente');

                estoqueTexto.textContent =
                    `Estoque insuficiente. Disponível: ${estoque} ${option.dataset.unidade}`;

            } else {

                estoqueTexto.classList.remove('estoque-insuficiente');

            }


            atualizarTotais();

        }


        /*
        |--------------------------------------------------------------------------
        | Atualizar totais
        |--------------------------------------------------------------------------
        */

        function atualizarTotais() {

            let totalMateriais = 0;

            let totalSugerido = 0;


            document
                .querySelectorAll('.material-row')
                .forEach(linha => {

                    const select =
                        linha.querySelector('.material');

                    const quantidadeInput =
                        linha.querySelector('.quantidade-material');

                    if (!select.value) {
                        return;
                    }


                    const option =
                        select.options[select.selectedIndex];

                    const quantidade =
                        Number(quantidadeInput.value) || 0;

                    const subtotalInput =
                        linha.querySelector('.subtotal-material');

                    const subtotal =
                        Number(subtotalInput.value) || 0;

                    const sugerido =
                        Number(option.dataset.sugerido) || 0;

                    totalMateriais += subtotal;

                    totalSugerido +=
                        sugerido * quantidade;

                });


            document.getElementById('totalMateriais').textContent =
                formatarMoeda(totalMateriais);

            document.getElementById('totalSugerido').textContent =
                formatarMoeda(totalSugerido);


            document.getElementById('valorMateriais').value =
                formatarMoeda(totalMateriais);

            document.getElementById('valorSugerido').value =
                formatarMoeda(totalSugerido);


            atualizarValorFinal();

        }


        /*
        |--------------------------------------------------------------------------
        | Atualizar valor final
        |--------------------------------------------------------------------------
        */

        function atualizarValorFinal() {

            let totalMateriais = 0;

            let maoObra =
                Number(document.getElementById('maoObra').value) || 0;


            document
                .querySelectorAll('.material-row')
                .forEach(linha => {

                    const select =
                        linha.querySelector('.material');

                    const quantidadeInput =
                        linha.querySelector('.quantidade-material');

                    if (!select.value) {
                        return;
                    }


                    const option =
                        select.options[select.selectedIndex];

                    const quantidade =
                        Number(quantidadeInput.value) || 0;

                    const valor =
                        Number(option.dataset.valor) || 0;


                    totalMateriais +=
                        valor * quantidade;

                });


            const totalSugerido = document.querySelector('#totalSugerido') ? null : null;
            let materiaisSugeridos = 0;
            document.querySelectorAll('.material-row').forEach(linha => {
                const select = linha.querySelector('.material');
                const quantidadeInput = linha.querySelector('.quantidade-material');
                if (!select.value) return;
                const option = select.options[select.selectedIndex];
                materiaisSugeridos += (Number(option.dataset.sugerido) || 0) * (Number(quantidadeInput.value) || 0);
            });

            const valorSugeridoFinal = materiaisSugeridos + maoObra;
            document.getElementById('valorFinalSugerido').textContent = formatarMoeda(valorSugeridoFinal);

        }


        /*
        |--------------------------------------------------------------------------
        | Adicionar nova linha
        |--------------------------------------------------------------------------
        */

        function adicionarLinhaMaterial() {

            const container =
                document.getElementById('materiaisContainer');


            const linha =
                document.createElement('div');

            linha.className =
                'material-row';


            linha.innerHTML = `

        <div class="material-field material-select">

            <label>Material</label>

            <select class="material" required>

                ${gerarOpcoesMateriais()}

            </select>

        </div>


        <div class="material-field">

            <label>Quantidade</label>

            <input
                type="number"
                class="quantidade-material"
                min="0.01"
                step="0.01"
                value="1"
                required
            >

            <small class="estoque-disponivel">
                Selecione um material
            </small>

        </div>


        <div class="material-field">

            <label>Valor do item</label>

            <input
                type="text"
                class="valor-material"
                value="R$ 0,00"
                readonly
            >

        </div>


        <div class="material-field">

            <label>Valor sugerido</label>

            <input
                type="text"
                class="valor-sugerido-material"
                value="R$ 0,00"
                readonly
            >

        </div>


        <div class="material-field">

            <label>Subtotal</label>

            <input
                type="number"
                class="subtotal-material"
                min="0"
                step="0.01"
                value="0.00"
            >

        </div>


        <button
            type="button"
            class="btn-remove-material"
            title="Remover material"
        >
            ×
        </button>

    `;


            container.appendChild(linha);

            configurarLinha(linha);

        }


        /*
        |--------------------------------------------------------------------------
        | Configurar eventos de uma linha
        |--------------------------------------------------------------------------
        */

        function configurarLinha(linha) {

            const select =
                linha.querySelector('.material');

            const quantidade =
                linha.querySelector('.quantidade-material');

            const remover =
                linha.querySelector('.btn-remove-material');


            select.addEventListener(
                'change',
                () => atualizarLinha(linha)
            );


            quantidade.addEventListener(
                'input',
                () => {
                    const subtotalInput = linha.querySelector('.subtotal-material');
                    subtotalInput.dataset.manual = 'false';
                    atualizarLinha(linha);
                }
            );

            const subtotalInput = linha.querySelector('.subtotal-material');
            subtotalInput.dataset.manual = 'true';

            subtotalInput.addEventListener(
                'input',
                () => {
                    subtotalInput.dataset.manual = 'true';
                    atualizarTotais();
                    atualizarValorFinal();
                }
            );

            remover.addEventListener(
                'click',
                () => {

                    const linhas =
                        document.querySelectorAll('.material-row');


                    if (linhas.length <= 1) {

                        select.value = '';

                        quantidade.value = 1;

                        atualizarLinha(linha);

                        return;

                    }


                    linha.remove();

                    atualizarTotais();

                }
            );

        }


        /*
        |--------------------------------------------------------------------------
        | Inicializar primeira linha
        |--------------------------------------------------------------------------
        */

        const linhasIniciais = document.querySelectorAll('.material-row');
        linhasIniciais.forEach(configurarLinha);

        const existentes = Object.entries(materiaisExistentes);

        if (existentes.length > 0) {
            const primeiraLinha = document.querySelector('.material-row');

            const preencherLinhaExistente = (linha, item) => {
                const estoqueId = item[0];
                const dadosMaterial = item[1];

                const quantidade = typeof dadosMaterial === 'object' ?
                    Number(dadosMaterial.quantidade) || 1 :
                    Number(dadosMaterial) || 1;

                const subtotal = typeof dadosMaterial === 'object' ?
                    Number(dadosMaterial.subtotal) || 0 :
                    0;

                linha.querySelector('.material').value = estoqueId;
                linha.querySelector('.quantidade-material').value = quantidade;
                linha.querySelector('.subtotal-material').value = subtotal.toFixed(2);
                linha.querySelector('.subtotal-material').dataset.manual = 'true';
            };

            preencherLinhaExistente(primeiraLinha, existentes[0]);

            for (let i = 1; i < existentes.length; i++) {
                adicionarLinhaMaterial();

                const linhas = document.querySelectorAll('.material-row');
                preencherLinhaExistente(
                    linhas[linhas.length - 1],
                    existentes[i]
                );
            }
        }

        document.querySelectorAll('.material-row').forEach(atualizarLinha);
        atualizarTotais();
        atualizarValorFinal();


        /*
        |--------------------------------------------------------------------------
        | Botão adicionar material
        |--------------------------------------------------------------------------
        */

        document
            .getElementById('btnAdicionarMaterial')
            .addEventListener(
                'click',
                adicionarLinhaMaterial
            );


        /*
        |--------------------------------------------------------------------------
        | Mão de obra
        |--------------------------------------------------------------------------
        */

        document
            .getElementById('maoObra')
            .addEventListener(
                'input',
                atualizarValorFinal
            );


        /*
        |--------------------------------------------------------------------------
        | Voltar
        |--------------------------------------------------------------------------
        */

        function voltar() {

            const prontuarioId =
                document.getElementById('prontuarioId').value;

            window.location =
                'visualizar_prontuario.php?id=' + prontuarioId;

        }


        if (procedimentoExistente) {
            document.getElementById('titulo').value =
                procedimentoExistente.titulo || '';

            document.getElementById('data_procedimento').value =
                procedimentoExistente.data_procedimento || '';

            document.getElementById('descricao').value =
                procedimentoExistente.descricao || '';

            document.getElementById('medicamentos').value =
                procedimentoExistente.medicamentos || '';

            document.getElementById('maoObra').value =
                Number(procedimentoExistente.valor_mao_obra || 0).toFixed(2);

            const valorFinalInput =
                document.getElementById('valorFinalInput');

            if (valorFinalInput) {
                valorFinalInput.value =
                    Number(procedimentoExistente.valor_final || 0).toFixed(2);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Enviar formulário
        |--------------------------------------------------------------------------
        */

        document
            .getElementById('procedimentoForm')
            .addEventListener('submit', async function(e) {

                e.preventDefault();


                const btnSalvar =
                    document.getElementById('btnSalvar');


                /*
                |--------------------------------------------------------------
                | Montar materiais
                |--------------------------------------------------------------
                */

                const materiais = [];


                let erroEstoque = false;


                document
                    .querySelectorAll('.material-row')
                    .forEach(linha => {

                        const select =
                            linha.querySelector('.material');

                        const quantidadeInput =
                            linha.querySelector('.quantidade-material');


                        if (!select.value) {
                            return;
                        }


                        const option =
                            select.options[select.selectedIndex];


                        const estoque =
                            Number(option.dataset.estoque) || 0;

                        const quantidade =
                            Number(quantidadeInput.value) || 0;


                        if (quantidade <= 0) {

                            erroEstoque = true;

                            alert(
                                'A quantidade utilizada deve ser maior que zero.'
                            );

                            return;

                        }


                        if (quantidade > estoque) {

                            erroEstoque = true;

                            alert(
                                `Estoque insuficiente para "${option.text.split('—')[0].trim()}".\n\n` +
                                `Disponível: ${estoque} ${option.dataset.unidade}\n` +
                                `Solicitado: ${quantidade}`
                            );

                            return;

                        }


                        materiais.push({

                            estoque_id: Number(option.value),

                            quantidade: quantidade

                        });

                    });


                if (erroEstoque) {
                    return;
                }


                /*
                |--------------------------------------------------------------
                | Verificar materiais duplicados
                |--------------------------------------------------------------
                */

                const ids =
                    materiais.map(material => material.estoque_id);


                const idsDuplicados =
                    ids.filter(
                        (id, index) =>
                        ids.indexOf(id) !== index
                    );


                if (idsDuplicados.length > 0) {

                    alert(
                        'O mesmo material foi adicionado mais de uma vez. ' +
                        'Ajuste a quantidade em uma única linha.'
                    );

                    return;

                }


                /*
                |--------------------------------------------------------------
                | Dados
                |--------------------------------------------------------------
                */

                const dados = {

                    prontuario_id: Number(
                        document.getElementById('prontuarioId').value
                    ),

                    procedimento_id: Number(
                        document.getElementById('procedimentoId').value
                    ) || null,

                    agendamento_id: document.getElementById('agendamentoId').value ?
                        Number(
                            document.getElementById('agendamentoId').value
                        ) : null,

                    titulo: document.getElementById('titulo').value.trim(),

                    data_procedimento: document.getElementById('data_procedimento').value,

                    descricao: document.getElementById('descricao').value.trim(),

                    medicamentos: document.getElementById('medicamentos').value.trim(),

                    valor_mao_obra: Number(
                        document.getElementById('maoObra').value
                    ) || 0,

                    /*
                    |----------------------------------------------------------
                    | O valor final é definido pelo usuário.
                    | O sistema apenas calcula e exibe uma sugestão.
                    |----------------------------------------------------------
                    */
                    valor_final: Number(
                        document.getElementById('valorFinalInput').value
                    ) || 0,

                    materiais: materiais

                };


                if (!dados.titulo) {

                    alert(
                        'Informe o nome do procedimento.'
                    );

                    return;

                }

                if (dados.valor_final < 0) {

                    alert(
                        'O valor final não pode ser negativo.'
                    );

                    return;

                }


                /*
                |--------------------------------------------------------------
                | Evitar duplo envio
                |--------------------------------------------------------------
                */

                btnSalvar.disabled = true;

                btnSalvar.textContent =
                    'Salvando...';


                try {

                    const formData =
                        new FormData();


                    formData.append(
                        'csrf_token',
                        '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>'
                    );


                    formData.append(
                        'dados',
                        JSON.stringify(dados)
                    );
                    formData.append('acao', 'salvar');


                    const response =
                        await fetch(
                            'adicionar_procedimento.php', {
                                method: 'POST',
                                body: formData
                            }
                        );


                    const result =
                        await response.json();


                    if (result.success) {

                        alert(
                            dados.procedimento_id ? 'Procedimento atualizado com sucesso!' : 'Procedimento adicionado com sucesso!'
                        );


                        window.location =
                            'visualizar_prontuario.php?id=' +
                            dados.prontuario_id;


                        return;

                    }


                    alert(
                        'Erro: ' +
                        (
                            result.error ||
                            'Não foi possível salvar o procedimento.'
                        )
                    );


                } catch (error) {

                    console.error(error);

                    alert(
                        'Erro de conexão. Tente novamente.'
                    );

                } finally {

                    btnSalvar.disabled = false;

                    btnSalvar.textContent =
                        dados.procedimento_id ? 'Salvar Alterações' : 'Adicionar Procedimento';

                }

            });
    </script>

</body>

</html>