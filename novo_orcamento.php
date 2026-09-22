<?php

require_once 'config/auth.php';
require_once 'config/csrf.php';

exigirLogin();

require_once 'conexao/conexao.php';

/*
|--------------------------------------------------------------------------
| BUSCAR PACIENTES
|--------------------------------------------------------------------------
*/


$pacientes = $pdo->query("
    SELECT id, paciente
    FROM prontuarios
    ORDER BY paciente
")->fetchAll();

/*
|--------------------------------------------------------------------------
| SERVIÇOS DO CATÁLOGO
|--------------------------------------------------------------------------
| Apenas serviços ativos ficam disponíveis para novos orçamentos.
| O nome e o valor são copiados para o item e continuam editáveis.
|--------------------------------------------------------------------------
*/
$servicos = $pdo->query("
    SELECT id, nome, descricao, valor_sugerido
    FROM servicos
    WHERE ativo = 1
    ORDER BY nome ASC
")->fetchAll(PDO::FETCH_ASSOC);

$erro = null;

/*
|--------------------------------------------------------------------------
| ORIGEM: ETAPA DO PLANO DE TRATAMENTO
|--------------------------------------------------------------------------
| Quando o orçamento é aberto a partir de uma etapa do plano,
| o formulário é pré-preenchido e o vínculo é salvo em uma tabela
| intermediária.
|
| O orçamento continua sendo independente: depois de criado, seus
| valores podem ser alterados sem modificar o plano.
|--------------------------------------------------------------------------
*/
$plano_item_id = (int)($_GET['plano_item_id'] ?? $_POST['plano_item_id'] ?? 0);
$plano_item_origem = null;

if ($plano_item_id > 0) {

    $stmtOrigem = $pdo->prepare("
        SELECT
            pti.id,
            pti.plano_id,
            pti.servico_id,
            pti.descricao,
            pti.valor_estimado,
            pti.status,
            pt.paciente_id,
            pt.titulo AS plano_titulo
        FROM planos_tratamento_itens pti
        INNER JOIN planos_tratamento pt
            ON pt.id = pti.plano_id
        WHERE pti.id = ?
        LIMIT 1
    ");

    $stmtOrigem->execute([$plano_item_id]);
    $plano_item_origem = $stmtOrigem->fetch(PDO::FETCH_ASSOC);

    if (!$plano_item_origem) {
        $plano_item_id = 0;
    }
}

/*
|--------------------------------------------------------------------------
| SALVAR ORÇAMENTO
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    validar_csrf();

    try {

        $pdo->beginTransaction();

        /*
        |--------------------------------------------------------------------------
        | DADOS PRINCIPAIS
        |--------------------------------------------------------------------------
        */

        $paciente_id = (int)($_POST['paciente_id'] ?? 0);

        $validade = $_POST['validade'] ?? '';

        $observacoes = trim(
            $_POST['observacoes'] ?? ''
        );

        $plano_item_id_post = (int)(
            $_POST['plano_item_id'] ?? 0
        );

        if ($plano_item_id_post > 0) {
            $stmtOrigemPost = $pdo->prepare("
                SELECT
                    pti.id,
                    pt.paciente_id
                FROM planos_tratamento_itens pti
                INNER JOIN planos_tratamento pt
                    ON pt.id = pti.plano_id
                WHERE pti.id = ?
                LIMIT 1
            ");

            $stmtOrigemPost->execute([
                $plano_item_id_post
            ]);

            $origemValida = $stmtOrigemPost->fetch(
                PDO::FETCH_ASSOC
            );

            if (!$origemValida) {
                throw new Exception(
                    'A etapa do plano de tratamento não foi encontrada.'
                );
            }

            if ((int)$origemValida['paciente_id'] !== $paciente_id) {
                throw new Exception(
                    'A etapa do plano não pertence ao paciente selecionado.'
                );
            }

            $plano_item_id = $plano_item_id_post;
        }

        /*
        |--------------------------------------------------------------------------
        | VALIDAÇÕES
        |--------------------------------------------------------------------------
        */

        if ($paciente_id <= 0) {
            throw new Exception(
                "Selecione um paciente válido."
            );
        }

        if (empty($validade)) {
            throw new Exception(
                "A data de validade é obrigatória."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | INSERIR ORÇAMENTO
        |--------------------------------------------------------------------------
        |
        | IMPORTANTE:
        | Sua tabela é "orcamento" no singular.
        |
        */

        $stmt = $pdo->prepare("
            INSERT INTO orcamentos (
                paciente_id,
                data_criacao,
                validade,
                observacoes
            )
            VALUES (?, ?, ?, ?)
        ");

        $stmt->execute([
            $paciente_id,
            date('Y-m-d'),
            $validade,
            $observacoes
        ]);

        $orcamento_id = $pdo->lastInsertId();


        /*
        |--------------------------------------------------------------------------
        | ITENS
        |--------------------------------------------------------------------------
        */

        $descricoes = $_POST['descricao'] ?? [];

        $quantidades = $_POST['quantidade'] ?? [];

        $valores = $_POST['valor'] ?? [];


        $total_itens = 0;

        $itens_salvos = 0;


        /*
        |--------------------------------------------------------------------------
        | INSERIR ITENS
        |--------------------------------------------------------------------------
        |
        | Sua tabela é "orcamento_itens" no singular.
        |
        */

        foreach ($descricoes as $i => $desc) {

            $desc = trim(
                $desc ?? ''
            );


            /*
            | Normalizar valor
            |
            | Aceita:
            | 1500.50
            | 1500,50
            */

            $valor_bruto = $valores[$i] ?? '';

            $valor_bruto = str_replace(
                'R$',
                '',
                $valor_bruto
            );

            $valor_bruto = trim(
                $valor_bruto
            );


            /*
            | Se vier no formato brasileiro:
            | 1.500,50
            |
            | transforma para:
            | 1500.50
            */

            if (
                strpos($valor_bruto, ',') !== false
            ) {

                $valor_bruto = str_replace(
                    '.',
                    '',
                    $valor_bruto
                );

                $valor_bruto = str_replace(
                    ',',
                    '.',
                    $valor_bruto
                );
            }


            $valor = (float)$valor_bruto;


            /*
            | Quantidade
            */

            $qtd = (int)(
                $quantidades[$i] ?? 1
            );


            if ($qtd <= 0) {
                $qtd = 1;
            }


            /*
            |--------------------------------------------------------------------------
            | VALIDAR ITEM
            |--------------------------------------------------------------------------
            */

            if (
                !empty($desc) &&
                $valor > 0
            ) {

                $subtotal = $qtd * $valor;

                $total_itens += $subtotal;


                /*
                |--------------------------------------------------------------------------
                | INSERT DO ITEM
                |--------------------------------------------------------------------------
                */

                $stmtItem = $pdo->prepare("
                    INSERT INTO orcamentos_itens (
                        orcamento_id,
                        descricao,
                        quantidade,
                        valor_unitario
                    )
                    VALUES (?, ?, ?, ?)
                ");

                $stmtItem->execute([
                    $orcamento_id,
                    $desc,
                    $qtd,
                    $valor
                ]);

                $itens_salvos++;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | GARANTIR PELO MENOS UM ITEM
        |--------------------------------------------------------------------------
        */

        if ($itens_salvos === 0) {

            throw new Exception(
                "Adicione pelo menos 1 item válido ao orçamento."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | PARCELAMENTO
        |--------------------------------------------------------------------------
        */

        $num_parcelas = (int)($_POST['num_parcelas'] ?? 1);
        $data_primeiro_vencimento = $_POST['data_primeiro_vencimento'] ?? '';

        if ($num_parcelas < 1 || $num_parcelas > 24) {
            throw new Exception('Número de condições inválido.');
        }

        $dataBase = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $data_primeiro_vencimento
        );

        if (
            !$dataBase ||
            $dataBase->format('Y-m-d') !== $data_primeiro_vencimento
        ) {
            throw new Exception('Data do primeiro vencimento inválida.');
        }




        /*
|--------------------------------------------------------------------------
| GERAR CONDIÇÕES COMERCIAIS
|--------------------------------------------------------------------------
*/

        function adicionarMesesMantendoDia(
            DateTimeImmutable $data,
            int $meses
        ): DateTimeImmutable {

            $diaOriginal = (int)$data->format('d');

            $primeiroDiaMesDestino = $data->modify(
                'first day of +' . $meses . ' month'
            );

            $ultimoDiaMesDestino = (int)$primeiroDiaMesDestino->format('t');

            $diaFinal = min(
                $diaOriginal,
                $ultimoDiaMesDestino
            );

            return $primeiroDiaMesDestino->modify(
                '+' . ($diaFinal - 1) . ' days'
            );
        }

        if ($num_parcelas > 0 && $total_itens > 0) {

            /*
    |--------------------------------------------------------------------------
    | Valor base
    |--------------------------------------------------------------------------
    | Divide o total igualmente entre as condições.
    | A última condição recebe eventual diferença de centavos.
    |--------------------------------------------------------------------------
    */

            $valor_base = round(
                $total_itens / $num_parcelas,
                2
            );

            $valor_acumulado = 0;

            for ($i = 1; $i <= $num_parcelas; $i++) {

                if ($i < $num_parcelas) {

                    $valor_condicao = $valor_base;
                } else {

                    $valor_condicao = round(
                        $total_itens - $valor_acumulado,
                        2
                    );
                }

                $valor_acumulado = round(
                    $valor_acumulado + $valor_condicao,
                    2
                );

                $dataCondicao = adicionarMesesMantendoDia(
                    $dataBase,
                    $i - 1
                );

                $stmtCondicao = $pdo->prepare("
            INSERT INTO orcamentos_condicoes (
                orcamento_id,
                numero,
                valor,
                vencimento_previsto
            )
            VALUES (?, ?, ?, ?)
        ");

                $stmtCondicao->execute([
                    $orcamento_id,
                    $i,
                    $valor_condicao,
                    $dataCondicao->format('Y-m-d')
                ]);
            }
        }


        /*
        |--------------------------------------------------------------------------
        | VINCULAR AO PLANO DE TRATAMENTO
        |--------------------------------------------------------------------------
        */

        if ($plano_item_id > 0) {

            $stmtVinculo = $pdo->prepare("
                INSERT INTO planos_tratamento_itens_orcamentos (
                    plano_item_id,
                    orcamento_id
                )
                VALUES (?, ?)
            ");

            $stmtVinculo->execute([
                $plano_item_id,
                $orcamento_id
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | FINALIZAR TRANSAÇÃO
        |--------------------------------------------------------------------------
        */

        $pdo->commit();


        /*
        |--------------------------------------------------------------------------
        | IR PARA VISUALIZAÇÃO
        |--------------------------------------------------------------------------
        */

        header(
            "Location: visualizar_orcamento.php?id=" .
                $orcamento_id
        );

        exit;
    } catch (Exception $e) {

        /*
        |--------------------------------------------------------------------------
        | DESFAZER TRANSAÇÃO
        |--------------------------------------------------------------------------
        */

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }


        $msg = $e->getMessage();


        /*
        |--------------------------------------------------------------------------
        | MENSAGEM DE ERRO
        |--------------------------------------------------------------------------
        */

        if (
            strpos($msg, '1146') !== false
        ) {

            preg_match(
                "/Table '[^.]+\\.([^']+)' doesn't exist/",
                $msg,
                $matches
            );

            $tabela =
                $matches[1]
                ?? 'desconhecida';


            $erro =
                "Tabela não encontrada: " .
                "<strong>" .
                htmlspecialchars($tabela) .
                "</strong>. " .
                "Verifique se ela foi criada no banco.";
        } else {

            $erro =
                "Erro ao salvar: " .
                htmlspecialchars($msg);
        }


        error_log(
            "ERRO ORÇAMENTO: " .
                $msg
        );
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
        Novo Orçamento - Evoluqi
    </title>

    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/variables.css">
    <link rel="stylesheet" href="css/layout.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/new_orcamento.css?v=7">

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="icon" type="image/png" href="img/icon.PNG">
</head>


<body>

    <?php include 'navbar.php'; ?>


    <div class="container">

        <h1>
            Novo Orçamento
        </h1>


        <?php if (!empty($erro)): ?>

            <div
                class="erro"
                style="
                background:#ffebee;
                color:#c62828;
                padding:12px;
                border-radius:6px;
                margin-bottom:20px;
            ">

                <?= $erro ?>

            </div>

        <?php endif; ?>


        <form
            method="POST"
            id="formOrcamento">


            <?= csrf_field() ?>

            <input
                type="hidden"
                name="plano_item_id"
                value="<?= (int)$plano_item_id ?>">

            <?php if ($plano_item_origem): ?>

                <div class="orcamento-origem-plano">
                    <i class="fa-solid fa-link"></i>
                    <div>
                        <strong>Orçamento a partir do plano de tratamento</strong>
                        <span>
                            Etapa: <?= htmlspecialchars($plano_item_origem['descricao']) ?>
                            • Plano: <?= htmlspecialchars($plano_item_origem['plano_titulo']) ?>
                        </span>
                    </div>
                </div>

            <?php endif; ?>


            <!-- =====================================================
             PACIENTE
        ====================================================== -->

            <div class="form-group">

                <label>
                    Paciente
                </label>

                <select
                    name="paciente_id"
                    required>

                    <option value="">
                        Selecione
                    </option>

                    <?php foreach ($pacientes as $p): ?>

                        <option
                            value="<?= $p['id'] ?>"
                            <?= (
                                ($plano_item_origem && (int)$plano_item_origem['paciente_id'] === (int)$p['id'])
                                || (!$plano_item_origem && (string)($_POST['paciente_id'] ?? '') === (string)$p['id'])
                            ) ? 'selected' : '' ?>>

                            <?= htmlspecialchars(
                                $p['paciente']
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- =====================================================
             VALIDADE
        ====================================================== -->

            <div class="form-group">

                <label>
                    Data de Validade
                </label>

                <input
                    type="date"
                    name="validade"
                    value="<?= date(
                                'Y-m-d',
                                strtotime('+30 days')
                            ) ?>"
                    required>

            </div>


            <!-- =====================================================
             ITENS DO ORÇAMENTO
        ====================================================== -->

            <div class="form-group">

                <label>
                    Itens do orçamento
                </label>


                <div id="itens-container">

                    <div class="item-row">

                        <div class="catalogo-servico">

                            <label>
                                Serviço do catálogo
                            </label>

                            <select
                                class="servico-catalogo"
                                onchange="selecionarServicoCatalogo(this)">

                                <option value="">
                                    Selecionar serviço...
                                </option>

                                <option value="__manual__">
                                    Serviço personalizado
                                </option>

                                <?php foreach ($servicos as $servico): ?>
                                    <option
                                        value="<?= (int)$servico['id'] ?>"
                                        data-nome="<?= htmlspecialchars($servico['nome'], ENT_QUOTES) ?>"
                                        data-descricao="<?= htmlspecialchars($servico['descricao'] ?? '', ENT_QUOTES) ?>"
                                        data-valor="<?= htmlspecialchars((string)$servico['valor_sugerido'], ENT_QUOTES) ?>"
                                        <?= (
                                            $plano_item_origem &&
                                            (int)($plano_item_origem['servico_id'] ?? 0) === (int)$servico['id']
                                        ) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($servico['nome']) ?>
                                        — R$
                                        <?= number_format((float)$servico['valor_sugerido'], 2, ',', '.') ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>

                            <?php if (empty($servicos)): ?>
                                <span class="catalogo-servico-vazio">
                                    Nenhum serviço ativo cadastrado. Você ainda pode criar um item personalizado.
                                </span>
                            <?php endif; ?>

                        </div>

                        <div>

                            <input
                                type="text"
                                name="descricao[]"
                                placeholder="Ex: Clareamento dental"
                                value="<?= htmlspecialchars(
                                            $plano_item_origem['descricao'] ?? '',
                                            ENT_QUOTES
                                        ) ?>"
                                required>

                        </div>


                        <div>

                            <input
                                type="number"
                                name="quantidade[]"
                                value="1"
                                min="1"
                                style="width:80px;">

                        </div>


                        <div>

                            <input
                                type="number"
                                name="valor[]"
                                step="0.01"
                                min="0"
                                placeholder="0.00"
                                value="<?= htmlspecialchars(
                                            $plano_item_origem['valor_estimado'] ?? '',
                                            ENT_QUOTES
                                        ) ?>"
                                required
                                class="item-valor">

                        </div>

                        <button
                            type="button"
                            class="btn-remove-item"
                            onclick="removerItem(this)"
                            title="Excluir item">
                            <i class="fa-solid fa-trash"></i>
                        </button>

                    </div>

                </div>


                <button
                    type="button"
                    class="btn-add-item"
                    onclick="adicionarItem()">

                    + Adicionar Item

                </button>

            </div>


            <!-- =====================================================
             PARCELAMENTO
        ====================================================== -->

            <div
                class="form-group"
                style="
                border-top:1px solid #eee;
                padding-top:20px;
                margin-top:20px;
            ">

                <label>
                    Condições comerciais
                </label>


                <select
                    name="num_parcelas"
                    id="num_parcelas">

                    <option value="1">
                        À vista (1x)
                    </option>

                    <?php for ($i = 2; $i <= 24; $i++): ?>

                        <option value="<?= $i ?>">

                            <?= $i ?>x sem juros

                        </option>

                    <?php endfor; ?>

                </select>

                <div class="form-group">
                    <label for="data_primeiro_vencimento">
                        Primeiro vencimento
                    </label>

                    <input
                        type="date"
                        id="data_primeiro_vencimento"
                        name="data_primeiro_vencimento"
                        value="<?= htmlspecialchars($_POST['data_primeiro_vencimento'] ?? date('Y-m-d')) ?>"
                        required>
                </div>


                <div
                    id="preview_parcelas"
                    style="
                    margin-top:8px;
                    font-size:13px;
                    font-weight:600;
                    color:#7b3ff2;
                ">

                </div>

            </div>


            <!-- =====================================================
             OBSERVAÇÕES
        ====================================================== -->

            <div class="form-group">

                <label>
                    Observações (opcional)
                </label>

                <textarea
                    name="observacoes"
                    rows="3"
                    placeholder="Condições, descontos, etc..."></textarea>

            </div>


            <!-- =====================================================
             AÇÕES
        ====================================================== -->

            <button
                type="submit"
                class="btn">

                Criar Orçamento

            </button>


            <a
                href="orcamento"
                style="
                display:inline-block;
                margin-left:12px;
                color:var(--roxo-medio);
            ">

                Cancelar

            </a>

        </form>

    </div>


    <script>
        /*
|--------------------------------------------------------------------------
| ADICIONAR ITEM
|--------------------------------------------------------------------------
*/


        /*
         |--------------------------------------------------------------------------
         | CATÁLOGO DE SERVIÇOS
         |--------------------------------------------------------------------------
        */

        const servicosCatalogo = <?= json_encode(
                                        array_map(
                                            static function ($servico) {
                                                return [
                                                    'id' => (int)$servico['id'],
                                                    'nome' => $servico['nome'],
                                                    'descricao' => $servico['descricao'] ?? '',
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
            return servicosCatalogo.map(servico => `
                <option
                    value="${Number(servico.id)}"
                    data-nome="${escaparHtml(servico.nome)}"
                    data-descricao="${escaparHtml(servico.descricao)}"
                    data-valor="${Number(servico.valor)}">
                    ${escaparHtml(servico.nome)}
                    — R$ ${Number(servico.valor).toFixed(2).replace('.', ',')}
                </option>
            `).join('');
        }

        function selecionarServicoCatalogo(select) {

            const linha = select.closest('.item-row');

            if (!linha) {
                return;
            }

            const descricaoInput =
                linha.querySelector('[name="descricao[]"]');

            const valorInput =
                linha.querySelector('[name="valor[]"]');

            if (!descricaoInput || !valorInput) {
                return;
            }

            if (select.value === '__manual__') {
                descricaoInput.value = '';
                valorInput.value = '';
                descricaoInput.focus();
                calcularPreviewParcelas();
                return;
            }

            if (select.value === '') {
                calcularPreviewParcelas();
                return;
            }

            const opcao =
                select.options[select.selectedIndex];

            descricaoInput.value =
                opcao.dataset.nome || '';

            valorInput.value =
                Number(opcao.dataset.valor || 0).toFixed(2);

            calcularPreviewParcelas();
        }

        function adicionarItem() {

            const container =
                document.getElementById(
                    'itens-container'
                );


            const div =
                document.createElement('div');


            div.className =
                'item-row';


            div.innerHTML = `

        <div class="catalogo-servico">

            <label>
                Serviço do catálogo
            </label>

            <select
                class="servico-catalogo"
                onchange="selecionarServicoCatalogo(this)">

                <option value="">
                    Selecionar serviço...
                </option>

                <option value="__manual__">
                    Serviço personalizado
                </option>

                ${obterOpcoesServicos()}

            </select>

        </div>

        <div>

            <input
                type="text"
                name="descricao[]"
                placeholder="Ex: Restauração"
                required>

        </div>

        <div>

            <input
                type="number"
                name="quantidade[]"
                value="1"
                min="1"
                style="width:80px;">

        </div>

        <div>

            <input
                type="number"
                name="valor[]"
                step="0.01"
                min="0"
                placeholder="0.00"
                required
                class="item-valor">

        </div>

        <button
            type="button"
            class="btn-remove-item"
            onclick="removerItem(this)"
            title="Excluir item">
            <i class="fa-solid fa-trash"></i>
        </button>

    `;


            container.appendChild(div);


            /*
            | Atualizar preview quando o valor mudar
            */

            div
                .querySelector('.item-valor')
                .addEventListener(
                    'input',
                    calcularPreviewParcelas
                );


            div
                .querySelector('[name="quantidade[]"]')
                .addEventListener(
                    'input',
                    calcularPreviewParcelas
                );
        }


        /*
        |--------------------------------------------------------------------------
        | REMOVER ITEM
        |--------------------------------------------------------------------------
        */

        function removerItem(botao) {
            const container = document.getElementById('itens-container');
            const itens = container.querySelectorAll('.item-row');

            if (itens.length <= 1) {
                const primeiro = itens[0];

                if (primeiro) {
                    primeiro.querySelector('[name="descricao[]"]').value = '';
                    primeiro.querySelector('[name="quantidade[]"]').value = '1';
                    primeiro.querySelector('[name="valor[]"]').value = '';

                    const catalogo =
                        primeiro.querySelector('.servico-catalogo');

                    if (catalogo) {
                        catalogo.value = '';
                    }
                }

                calcularPreviewParcelas();
                return;
            }

            botao.closest('.item-row').remove();
            calcularPreviewParcelas();
        }

        /*
        |--------------------------------------------------------------------------
        | CALCULAR PREVIEW DAS PARCELAS
        |--------------------------------------------------------------------------
        */

        function calcularPreviewParcelas() {

            const qtdCondicoes =
                parseInt(
                    document.getElementById('num_parcelas').value
                ) || 1;

            const dataPrimeiroVencimento =
                document.getElementById('data_primeiro_vencimento')?.value || '';

            let total = 0;

            document
                .querySelectorAll('.item-row')
                .forEach(row => {

                    const qtdInput =
                        row.querySelector('[name="quantidade[]"]');

                    const valInput =
                        row.querySelector('[name="valor[]"]');

                    const qtd =
                        parseInt(qtdInput?.value) || 1;

                    const val =
                        parseFloat(valInput?.value) || 0;

                    total += qtd * val;
                });

            const preview =
                document.getElementById('preview_parcelas');

            if (total === 0) {

                preview.textContent =
                    'Adicione itens para calcular as condições comerciais.';

                preview.style.color = '#999';

                return;
            }

            if (!dataPrimeiroVencimento) {

                preview.textContent =
                    'Informe o primeiro vencimento para visualizar as condições.';

                preview.style.color = '#999';

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Converter data YYYY-MM-DD para uma data local
            |--------------------------------------------------------------------------
            */

            const partesData =
                dataPrimeiroVencimento.split('-');

            const ano =
                parseInt(partesData[0]);

            const mes =
                parseInt(partesData[1]) - 1;

            const dia =
                parseInt(partesData[2]);

            const dataBase =
                new Date(ano, mes, dia);

            /*
            |--------------------------------------------------------------------------
            | Valor das condições
            |--------------------------------------------------------------------------
            */

            const valorBase =
                Math.round(
                    (total / qtdCondicoes) * 100
                ) / 100;

            let valorAcumulado = 0;

            let html = '';

            for (
                let i = 1; i <= qtdCondicoes; i++
            ) {

                let valorCondicao;

                if (i < qtdCondicoes) {

                    valorCondicao = valorBase;

                } else {

                    valorCondicao =
                        Math.round(
                            (total - valorAcumulado) * 100
                        ) / 100;
                }

                valorAcumulado =
                    Math.round(
                        (valorAcumulado + valorCondicao) * 100
                    ) / 100;

                /*
                |--------------------------------------------------------------------------
                | Calcular vencimento
                |--------------------------------------------------------------------------
                */

                const dataVencimento =
                    new Date(
                        dataBase.getFullYear(),
                        dataBase.getMonth() + (i - 1),
                        1
                    );

                const ultimoDiaMes =
                    new Date(
                        dataVencimento.getFullYear(),
                        dataVencimento.getMonth() + 1,
                        0
                    ).getDate();

                const diaFinal =
                    Math.min(
                        dia,
                        ultimoDiaMes
                    );

                dataVencimento.setDate(diaFinal);

                /*
                |--------------------------------------------------------------------------
                | Formatar data
                |--------------------------------------------------------------------------
                */

                const diaFormatado =
                    String(
                        dataVencimento.getDate()
                    ).padStart(2, '0');

                const mesFormatado =
                    String(
                        dataVencimento.getMonth() + 1
                    ).padStart(2, '0');

                const anoFormatado =
                    dataVencimento.getFullYear();

                const dataFormatada =
                    `${diaFormatado}/${mesFormatado}/${anoFormatado}`;

                /*
                |--------------------------------------------------------------------------
                | Montar preview
                |--------------------------------------------------------------------------
                */

                html += `
            <div style="margin-bottom:4px;">
                ${i}ª condição —
                R$ ${valorCondicao
                    .toFixed(2)
                    .replace('.', ',')}
                — Vencimento previsto:
                ${dataFormatada}
            </div>
        `;
            }

            html += `
        <div style="margin-top:8px;">
            Total: R$ ${total
                .toFixed(2)
                .replace('.', ',')}
        </div>
    `;

            preview.innerHTML = html;

            preview.style.color = '#7b3ff2';
        }


        /*
        |--------------------------------------------------------------------------
        | EVENTOS
        |--------------------------------------------------------------------------
        */

        document.addEventListener(
            'DOMContentLoaded',
            () => {

                /*
                | Parcelas
                */

                document
                    .getElementById('num_parcelas')
                    .addEventListener(
                        'change',
                        calcularPreviewParcelas
                    );

                document
                    .getElementById('data_primeiro_vencimento')
                    .addEventListener(
                        'change',
                        calcularPreviewParcelas
                    );


                /*
                | Valores
                */

                document
                    .querySelectorAll('.item-valor')
                    .forEach(input => {

                        input.addEventListener(
                            'input',
                            calcularPreviewParcelas
                        );

                    });


                /*
                | Quantidades
                */

                document
                    .querySelectorAll(
                        '[name="quantidade[]"]'
                    )
                    .forEach(input => {

                        input.addEventListener(
                            'input',
                            calcularPreviewParcelas
                        );

                    });


                /*
                | Preview inicial
                */

                calcularPreviewParcelas();

            }
        );
    </script>


</body>

</html>