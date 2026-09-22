<?php

require_once 'config/auth.php';
require_once 'config/csrf.php';

exigirLogin();

require_once 'conexao/conexao.php';


/*
|--------------------------------------------------------------------------
| ID DO ORÇAMENTO
|--------------------------------------------------------------------------
*/

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    die('ID do orçamento não informado.');
}


/*
|--------------------------------------------------------------------------
| BUSCAR ORÇAMENTO + PACIENTE
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

$orc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$orc) {
    die('Orçamento não encontrado.');
}

/*
|--------------------------------------------------------------------------
| BUSCAR ITENS
|--------------------------------------------------------------------------
*/

$stmt_itens = $pdo->prepare("
    SELECT
        id,
        orcamento_id,
        descricao,
        quantidade,
        valor_unitario,
        status
    FROM orcamentos_itens
    WHERE orcamento_id = ?
    ORDER BY id ASC
");

$stmt_itens->execute([$id]);

$itens = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| BUSCAR CONDIÇÕES COMERCIAIS
|--------------------------------------------------------------------------
*/

$stmt_condicoes = $pdo->prepare("
    SELECT
        id,
        orcamento_id,
        numero,
        valor,
        vencimento_previsto
    FROM orcamentos_condicoes
    WHERE orcamento_id = ?
    ORDER BY numero ASC
");

$stmt_condicoes->execute([$id]);

$condicoes = $stmt_condicoes->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| TOTAL DO ORÇAMENTO
|--------------------------------------------------------------------------
*/

$total_itens = 0.0;

foreach ($itens as $item) {

    $quantidade = (float)$item['quantidade'];
    $valor = (float)$item['valor_unitario'];

    $total_itens += $quantidade * $valor;
}


/*
|--------------------------------------------------------------------------
| STATUS DO ORÇAMENTO
|--------------------------------------------------------------------------
*/

$status_orcamento = $orc['status'] ?? 'pendente';

$orcamento_pendente =
    $status_orcamento === 'pendente';

$orcamento_bloqueado =
    in_array(
        $status_orcamento,
        [
            'parcialmente_aceito',
            'aceito',
            'parcialmente_recusado',
            'recusado'
        ],
        true
    );

$orcamento_confirmado =
    in_array(
        $status_orcamento,
        [
            'parcialmente_aceito',
            'aceito'
        ],
        true
    );

$orcamento_recusado =
    in_array(
        $status_orcamento,
        [
            'parcialmente_recusado',
            'recusado'
        ],
        true
    );


/*
|--------------------------------------------------------------------------
| TEXTO DO STATUS
|--------------------------------------------------------------------------
*/

$status_texto = match ($status_orcamento) {

    'parcialmente_aceito'
    => 'Parcialmente aceito',

    'aceito'
    => 'Aceito',

    'parcialmente_recusado'
    => 'Parcialmente recusado',

    'recusado'
    => 'Recusado',

    default
    => 'Pendente'
};


/*
|--------------------------------------------------------------------------
| CLASSE DO STATUS
|--------------------------------------------------------------------------
*/

$status_classe = match ($status_orcamento) {

    'parcialmente_aceito'
    => 'status-parcialmente-aceito',

    'aceito'
    => 'status-aceito',

    'parcialmente_recusado'
    => 'status-parcialmente-recusado',

    'recusado'
    => 'status-recusado',

    default
    => 'status-pendente'
};


/*
|--------------------------------------------------------------------------
| FUNÇÕES
|--------------------------------------------------------------------------
*/

function moedaBR($valor): string
{
    return 'R$ ' . number_format(
        (float)$valor,
        2,
        ',',
        '.'
    );
}

function dataBR($data): string
{
    if (empty($data)) {
        return '—';
    }

    $timestamp = strtotime($data);

    return $timestamp
        ? date('d/m/Y', $timestamp)
        : '—';
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
        Orçamento #<?= $id ?> - Evoluqi
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
        href="css/navbar.css">

    <link
        rel="stylesheet"
        href="css/vis_orcamento.css">

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


    <main class="content">

        <div class="orc-container">


            <!-- =====================================================
             CABEÇALHO
        ====================================================== -->

            <div class="header-actions">

                <div>

                    <h1>

                        Orçamento #<?= $id ?>

                        <span
                            class="status-badge <?= htmlspecialchars($status_classe) ?>">
                            <?= htmlspecialchars($status_texto) ?>
                        </span>

                    </h1>

                </div>


                <div class="btn-group">

                    <a
                        href="orcamento.php"
                        class="btn btn-outline">
                        ← Voltar
                    </a>


                    <a
                        href="gerar_orcamento_pdf.php?id=<?= $id ?>"
                        target="_blank"
                        class="btn btn-success">
                        📥 Baixar PDF
                    </a>


                    <?php if ($orcamento_pendente): ?>

                        <a
                            href="editar_orcamento.php?id=<?= $id ?>"
                            class="btn btn-primary">
                            ✏️ Editar
                        </a>


                        <form
                            method="POST"
                            action="aceitar_orcamento.php"
                            class="form-acao"
                            onsubmit="return confirm('Confirmar este orçamento? A confirmação não gera receita financeira.');">

                            <?= csrf_field() ?>

                            <input
                                type="hidden"
                                name="id"
                                value="<?= (int)$id ?>">

                            <button
                                type="submit"
                                class="btn btn-confirmar">
                                ✓ Confirmar
                            </button>

                        </form>


                        <form
                            method="POST"
                            action="recusar_orcamento.php"
                            class="form-acao"
                            onsubmit="return confirm('Recusar este orçamento?');">

                            <?= csrf_field() ?>

                            <input
                                type="hidden"
                                name="id"
                                value="<?= (int)$id ?>">

                            <button
                                type="submit"
                                class="btn btn-recusar">
                                ✕ Recusar
                            </button>

                        </form>

                    <?php endif; ?>

                </div>

            </div>


            <!-- =====================================================
             DADOS DO PACIENTE
        ====================================================== -->

            <h2>
                👤 Dados do Paciente
            </h2>


            <div class="info-grid">

                <div class="info-item">

                    <label>Nome</label>

                    <span>
                        <?= htmlspecialchars($orc['paciente']) ?>
                    </span>

                </div>


                <div class="info-item">

                    <label>CPF</label>

                    <span>
                        <?= !empty($orc['cpf'])
                            ? htmlspecialchars($orc['cpf'])
                            : '—'
                        ?>
                    </span>

                </div>


                <div class="info-item">

                    <label>Telefone</label>

                    <span>
                        <?= !empty($orc['telefone'])
                            ? htmlspecialchars($orc['telefone'])
                            : '—'
                        ?>
                    </span>

                </div>


                <div class="info-item">

                    <label>E-mail</label>

                    <span>
                        <?= !empty($orc['email'])
                            ? htmlspecialchars($orc['email'])
                            : '—'
                        ?>
                    </span>

                </div>


                <div class="info-item">

                    <label>Data de criação</label>

                    <span>
                        <?= dataBR($orc['data_criacao']) ?>
                    </span>

                </div>


                <div class="info-item">

                    <label>Validade</label>

                    <span>
                        <?= dataBR($orc['validade']) ?>
                    </span>

                </div>

            </div>


            <!-- =====================================================
             PROCEDIMENTOS / ITENS
        ====================================================== -->

            <h2>
                🦷 Procedimentos
            </h2>


            <?php if (empty($itens)): ?>

                <p class="sem-itens">
                    Nenhum item registrado.
                </p>

            <?php else: ?>

                <div class="table-wrapper">

                    <table>

                        <thead>

                            <tr>

                                <th>
                                    Descrição
                                </th>

                                <th class="col-qtd">
                                    Qtd
                                </th>

                                <th class="col-valor">
                                    Unitário
                                </th>

                                <th class="col-valor">
                                    Subtotal
                                </th>

                                <th>
                                    Decisão
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach ($itens as $item): ?>

                                <?php

                                $subtotal =
                                    (float)$item['quantidade']
                                    *
                                    (float)$item['valor_unitario'];

                                ?>

                                <tr>

                                    <td>
                                        <?= htmlspecialchars(
                                            $item['descricao']
                                        ) ?>
                                    </td>

                                    <td class="text-center">
                                        <?= (int)$item['quantidade'] ?>
                                    </td>

                                    <td class="text-right">
                                        <?= moedaBR(
                                            $item['valor_unitario']
                                        ) ?>
                                    </td>

                                    <td class="text-right">

                                        <strong>
                                            <?= moedaBR($subtotal) ?>
                                        </strong>

                                    </td>

                                    <td>

                                        <?php if (($item['status'] ?? 'pendente') === 'pendente'): ?>

                                            <div class="acoes-item">

                                                <form
                                                    method="POST"
                                                    action="decidir_item_orcamento.php"
                                                    onsubmit="return confirm('Aceitar este item do orçamento?');">
                                                    <?= csrf_field() ?>

                                                    <input
                                                        type="hidden"
                                                        name="item_id"
                                                        value="<?= (int)$item['id'] ?>">

                                                    <input
                                                        type="hidden"
                                                        name="decisao"
                                                        value="aceito">

                                                    <button
                                                        type="submit"
                                                        class="btn btn-confirmar">
                                                        ✓ Aceitar
                                                    </button>

                                                </form>


                                                <form
                                                    method="POST"
                                                    action="decidir_item_orcamento.php"
                                                    onsubmit="return confirm('Recusar este item do orçamento?');">
                                                    <?= csrf_field() ?>

                                                    <input
                                                        type="hidden"
                                                        name="item_id"
                                                        value="<?= (int)$item['id'] ?>">

                                                    <input
                                                        type="hidden"
                                                        name="decisao"
                                                        value="recusado">

                                                    <button
                                                        type="submit"
                                                        class="btn btn-recusar">
                                                        ✕ Recusar
                                                    </button>

                                                </form>

                                            </div>

                                        <?php elseif (($item['status'] ?? '') === 'aceito'): ?>

                                            <span class="status-item status-aceito">
                                                ✓ Aceito
                                            </span>

                                        <?php elseif (($item['status'] ?? '') === 'recusado'): ?>

                                            <span class="status-item status-recusado">
                                                ✕ Recusado
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>


            <!-- =====================================================
             TOTAL
        ====================================================== -->

            <div class="total-box">

                <div class="total-label">
                    Valor Total do Orçamento
                </div>

                <div class="total-value">
                    <?= moedaBR($total_itens) ?>
                </div>

            </div>


            <!-- =====================================================
             OBSERVAÇÕES
        ====================================================== -->

            <?php if (!empty($orc['observacoes'])): ?>

                <h2>
                    📝 Observações
                </h2>

                <div class="observacoes-box">

                    <?= nl2br(
                        htmlspecialchars(
                            $orc['observacoes']
                        )
                    ) ?>

                </div>

            <?php endif; ?>


            <!-- =====================================================
             CONDIÇÕES DE PAGAMENTO
        ====================================================== -->

            <!-- =====================================================
     CONDIÇÕES COMERCIAIS
====================================================== -->

            <section class="parcelas-section">

                <div class="parcelas-header">

                    <div class="parcelas-titulo">

                        <div>

                            <h3>
                                💳 Condições Comerciais
                            </h3>

                            <span class="parcelas-resumo">

                                <?php if (!empty($condicoes)): ?>

                                    <?= count($condicoes) ?>
                                    <?= count($condicoes) === 1
                                        ? 'condição'
                                        : 'condições' ?>

                                    ·

                                    <?= moedaBR(
                                        array_sum(
                                            array_column(
                                                $condicoes,
                                                'valor'
                                            )
                                        )
                                    ) ?>

                                <?php else: ?>

                                    Nenhuma condição definida

                                <?php endif; ?>

                            </span>

                        </div>

                    </div>


                    <div class="orcamento-aviso-financeiro">

                        <strong>
                            <i class="fa-solid fa-circle-info"></i>
                            Importante sobre este orçamento
                        </strong>

                        <p>
                            Os valores e condições apresentados neste documento
                            são uma proposta comercial e não representam uma
                            receita financeira registrada.
                        </p>

                        <p>
                            O valor final poderá ser alterado após a avaliação
                            e a definição dos procedimentos efetivamente
                            realizados.
                        </p>

                        <p>
                            A confirmação do orçamento não gera automaticamente
                            uma receita nem coloca este valor em "A receber".
                        </p>

                    </div>

                </div>


                <?php if (!empty($condicoes)): ?>

                    <div class="table-wrapper">

                        <table class="tabela-parcelas">

                            <thead>

                                <tr>

                                    <th>
                                        Condição
                                    </th>

                                    <th>
                                        Vencimento previsto
                                    </th>

                                    <th class="text-right">
                                        Valor
                                    </th>

                                </tr>

                            </thead>


                            <tbody>

                                <?php foreach ($condicoes as $condicao): ?>

                                    <tr>

                                        <td>

                                            <strong>
                                                <?= (int)$condicao['numero'] ?>ª
                                            </strong>

                                        </td>


                                        <td>

                                            <?= dataBR(
                                                $condicao['vencimento_previsto']
                                            ) ?>

                                        </td>


                                        <td class="text-right">

                                            <?= moedaBR(
                                                $condicao['valor']
                                            ) ?>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php else: ?>

                    <div class="sem-itens">

                        Nenhuma condição comercial foi cadastrada
                        para este orçamento.

                    </div>

                <?php endif; ?>

            </section>


            <!-- =====================================================
             AVISO DE STATUS FINANCEIRO
        ====================================================== -->

            <?php if ($orcamento_confirmado): ?>

                <div class="financeiro-integracao">

                    <div class="financeiro-integracao-icon">
                        <i class="fa-solid fa-circle-info"></i>
                    </div>

                    <div class="financeiro-integracao-conteudo">

                        <strong>
                            Orçamento confirmado
                        </strong>

                        <p>
                            A confirmação registra apenas a aprovação da
                            proposta comercial. Nenhuma receita financeira
                            é criada automaticamente.
                        </p>

                        <p>
                            Quando houver uma cobrança efetiva, ela poderá
                            ser registrada separadamente no financeiro.
                        </p>

                    </div>

                </div>

            <?php endif; ?>


            <!-- =====================================================
             RODAPÉ
        ====================================================== -->

            <div class="footer-note">

                Dentech <?= date('Y') ?>

                |

                Documento gerado automaticamente.

                Valores sujeitos a alteração conforme avaliação clínica.

            </div>


        </div>

    </main>

</body>

</html>