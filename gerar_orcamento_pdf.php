<?php

// =========================================================
// GERAR ORÇAMENTO EM PDF
// Dentech - Novo padrão visual
// =========================================================

require_once 'config/auth.php';
exigirLogin();

require_once 'conexao/conexao.php';

// =========================================================
// RECEBER ID DO ORÇAMENTO
// =========================================================

$id_orc = $_GET['id'] ?? ($_POST['id'] ?? 0);

$id_orc = (int)$id_orc;

if ($id_orc <= 0) {
    die('ID do orçamento não informado.');
}


// =========================================================
// BUSCAR ORÇAMENTO + PACIENTE
// =========================================================

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

$stmt->execute([$id_orc]);

$orc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$orc) {
    die('Orçamento não encontrado.');
}


// =========================================================
// BUSCAR ITENS DO ORÇAMENTO
// =========================================================

$stmt_itens = $pdo->prepare("
    SELECT *
    FROM orcamentos_itens
    WHERE orcamento_id = ?
    ORDER BY id ASC
");

$stmt_itens->execute([$id_orc]);

$itens = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);


// =========================================================
// BUSCAR PARCELAS
// =========================================================

$stmt_par = $pdo->prepare("
    SELECT *
    FROM parcelas
    WHERE orcamento_id = ?
    ORDER BY numero_parcela ASC
");

$stmt_par->execute([$id_orc]);

$parcelas = $stmt_par->fetchAll(PDO::FETCH_ASSOC);


// =========================================================
// STATUS DO ORÇAMENTO
// =========================================================

$status_orcamento = $orc['status'] ?? 'pendente';

switch ($status_orcamento) {

    case 'aceito':
        $status_texto = 'Confirmado';
        $status_cor = '#198754';
        $status_fundo = '#d1fae5';
        break;

    case 'recusado':
        $status_texto = 'Recusado';
        $status_cor = '#dc3545';
        $status_fundo = '#fee2e2';
        break;

    default:
        $status_texto = 'Pendente';
        $status_cor = '#856404';
        $status_fundo = '#fff3cd';
        break;
}


// =========================================================
// LOGO
// =========================================================

$logo_base64 = '';

/*
 * O Dompdf deste ambiente não possui a extensão GD.
 * Por isso, usamos JPG para o logo do PDF em vez de PNG.
 *
 * Crie o arquivo:
 *     img/logo_pdf.jpg
 *
 * O ideal é que ele tenha fundo branco e o logo sem a caixa preta.
 */
$logo_path = 'img/logo_pdf.jpg';

if (!file_exists($logo_path)) {
    // Fallback para o logo antigo, caso logo_pdf.jpg ainda não exista.
    $logo_path = 'img/logo.jpg';
}

if (file_exists($logo_path)) {
    $imageData = base64_encode(
        file_get_contents($logo_path)
    );

    $logo_base64 =
        'data:image/jpeg;base64,' .
        $imageData;
}


// =========================================================
// CALCULAR TOTAL DOS ITENS
// =========================================================

$total = 0;

$linhas_itens = '';

foreach ($itens as $item) {

    $quantidade = (int)($item['quantidade'] ?? 0);

    $valor_unitario = (float)($item['valor_unitario'] ?? 0);

    $subtotal = $quantidade * $valor_unitario;

    $total += $subtotal;


    $descricao = htmlspecialchars(
        $item['descricao'] ?? '',
        ENT_QUOTES,
        'UTF-8'
    );


    $linhas_itens .= "
        <tr>

            <td class='item-description'>
                {$descricao}
            </td>

            <td class='item-center'>
                {$quantidade}
            </td>

            <td class='item-right'>
                R$ " . number_format(
        $valor_unitario,
        2,
        ',',
        '.'
    ) . "
            </td>

            <td class='item-right item-total'>
                R$ " . number_format(
        $subtotal,
        2,
        ',',
        '.'
    ) . "
            </td>

        </tr>
    ";
}


// =========================================================
// MONTAR PARCELAS
// =========================================================

$html_parcelas = '';

if (!empty($parcelas)) {

    $linhas_parcelas = '';

    foreach ($parcelas as $parcela) {

        $numero = (int)($parcela['numero_parcela'] ?? 0);

        $valor = (float)($parcela['valor'] ?? 0);

        $vencimento = !empty($parcela['vencimento'])
            ? date(
                'd/m/Y',
                strtotime($parcela['vencimento'])
            )
            : '—';


        $status_parcela = $parcela['status'] ?? 'pendente';


        switch ($status_parcela) {

            case 'paga':
                $parcela_status_texto = 'Paga';
                $parcela_status_cor = '#198754';
                $parcela_status_fundo = '#d1fae5';
                break;

            case 'atrasada':
                $parcela_status_texto = 'Atrasada';
                $parcela_status_cor = '#dc3545';
                $parcela_status_fundo = '#fee2e2';
                break;

            default:
                $parcela_status_texto = 'Pendente';
                $parcela_status_cor = '#856404';
                $parcela_status_fundo = '#fff3cd';
                break;
        }


        $linhas_parcelas .= "
            <tr>

                <td class='item-center'>
                    {$numero}ª
                </td>

                <td class='item-center'>
                    {$vencimento}
                </td>

                <td class='item-right'>
                    R$ " . number_format(
            $valor,
            2,
            ',',
            '.'
        ) . "
                </td>

                <td class='item-center'>

                    <span
                        class='parcel-status'
                        style='
                            background: {$parcela_status_fundo};
                            color: {$parcela_status_cor};
                        '
                    >
                        {$parcela_status_texto}
                    </span>

                </td>

            </tr>
        ";
    }


    $html_parcelas = "

        <div class='section-title'>
            CONDIÇÕES DE PAGAMENTO
        </div>

        <table class='items-table parcelas-table'>

            <thead>

                <tr>

                    <th style='width:15%; text-align:center;'>
                        PARCELA
                    </th>

                    <th style='width:25%; text-align:center;'>
                        VENCIMENTO
                    </th>

                    <th style='width:30%; text-align:right;'>
                        VALOR
                    </th>

                    <th style='width:30%; text-align:center;'>
                        STATUS
                    </th>

                </tr>

            </thead>

            <tbody>

                {$linhas_parcelas}

            </tbody>

        </table>

    ";
}


// =========================================================
// OBSERVAÇÕES
// =========================================================

$html_observacoes = '';

if (!empty($orc['observacoes'])) {

    $observacoes = nl2br(
        htmlspecialchars(
            $orc['observacoes'],
            ENT_QUOTES,
            'UTF-8'
        )
    );


    $html_observacoes = "

        <div class='observacoes-box'>

            <div class='observacoes-title'>
                Observações
            </div>

            <div class='observacoes-text'>
                {$observacoes}
            </div>

        </div>

    ";
}


// =========================================================
// DADOS FORMATADOS
// =========================================================

$paciente = htmlspecialchars(
    $orc['paciente'] ?? '',
    ENT_QUOTES,
    'UTF-8'
);


$cpf = !empty($orc['cpf'])
    ? htmlspecialchars(
        $orc['cpf'],
        ENT_QUOTES,
        'UTF-8'
    )
    : '—';


$telefone = !empty($orc['telefone'])
    ? htmlspecialchars(
        $orc['telefone'],
        ENT_QUOTES,
        'UTF-8'
    )
    : '—';


$email = !empty($orc['email'])
    ? htmlspecialchars(
        $orc['email'],
        ENT_QUOTES,
        'UTF-8'
    )
    : '—';


$data_criacao = !empty($orc['data_criacao'])
    ? date(
        'd/m/Y',
        strtotime($orc['data_criacao'])
    )
    : '—';


$validade = !empty($orc['validade'])
    ? date(
        'd/m/Y',
        strtotime($orc['validade'])
    )
    : '—';


// =========================================================
// HTML DO PDF
// =========================================================

$html = "

<!DOCTYPE html>

<html lang='pt-BR'>

<head>

<meta charset='UTF-8'>

<style>


/* =====================================================
   CONFIGURAÇÃO DA PÁGINA
   ===================================================== */

@page {

    margin: 13mm 15mm 15mm 15mm;

}


body {

    font-family:
        Helvetica,
        Arial,
        sans-serif;

    font-size: 9.5pt;

    color: #2d3748;

    line-height: 1.45;

    margin: 0;

    padding: 0;

}

/* Mantém todos os blocos dentro da mesma largura útil da página. */
*, *:before, *:after {
    box-sizing: border-box;
}


/* =====================================================
   CABEÇALHO
   ===================================================== */

.header {

    width: 100%;

    display: table;

    border-bottom: 2px solid #2563eb;

    padding-bottom: 13px;

    margin-bottom: 20px;

}


.header-left {

    display: table-cell;

    width: 35%;

    vertical-align: middle;

}


.header-right {

    display: table-cell;

    width: 65%;

    text-align: right;

    vertical-align: middle;

}


.logo {
    width: 125px;
    max-height: 65px;
    object-fit: contain;
}


.document-title {

    margin: 0;

    color: #17213a;

    font-size: 17pt;

    font-weight: bold;

}


.document-subtitle {

    margin-top: 4px;

    color: #718096;

    font-size: 8pt;

}


.status-badge {

    display: inline-block;

    margin-top: 7px;

    padding: 5px 11px;

    border-radius: 12px;

    font-size: 8pt;

    font-weight: bold;

}


/* =====================================================
   IDENTIFICAÇÃO
   ===================================================== */

.document-info {

    width: 100%;

    margin-bottom: 18px;

}


.document-info table {

    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;

}


.document-info td {

    width: 33.33%;

    padding: 7px 10px;

    border: 1px solid #e2e8f0;

    background: #f8fafc;

}


.info-label {

    display: block;

    margin-bottom: 2px;

    color: #718096;

    font-size: 7pt;

    font-weight: bold;

    text-transform: uppercase;

    letter-spacing: 0.5px;

}


.info-value {

    color: #17213a;

    font-size: 9pt;

    font-weight: 600;

}


/* =====================================================
   TÍTULOS DAS SEÇÕES
   ===================================================== */

.section-title {

    width: 100%;
    margin-top: 18px;
    margin-bottom: 0;
    padding: 8px 11px;

    background: #2563eb;

    color: #ffffff;

    border-radius: 5px 5px 0 0;

    font-size: 8.5pt;

    font-weight: bold;

    letter-spacing: 0.4px;

}


/* =====================================================
   DADOS DO PACIENTE
   ===================================================== */

.info-table {

    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;
    border: 1px solid #e2e8f0;
    border-top: none;

}


.info-table td {

    padding: 10px 11px;

    border-bottom: 1px solid #edf2f7;

    vertical-align: top;

}


.info-table tr:last-child td {

    border-bottom: none;

}


/* =====================================================
   TABELA DE ITENS
   ===================================================== */

.items-table {

    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;

}


.items-table th {

    padding: 8px 10px;

    background: #f1f5f9;

    color: #334155;

    border: 1px solid #e2e8f0;

    font-size: 8pt;

    font-weight: bold;

}




.items-table th:nth-child(1),
.items-table td:nth-child(1) {
    width: 48%;
}

.items-table th:nth-child(2),
.items-table td:nth-child(2) {
    width: 12%;
}

.items-table th:nth-child(3),
.items-table td:nth-child(3) {
    width: 20%;
}

.items-table th:nth-child(4),
.items-table td:nth-child(4) {
    width: 20%;
}

.items-table td {

    padding: 9px 10px;

    border-left: 1px solid #e2e8f0;

    border-right: 1px solid #e2e8f0;

    border-bottom: 1px solid #e2e8f0;

    font-size: 8.5pt;

}


.items-table tbody tr:nth-child(even) {

    background: #f8fafc;

}


.item-description {

    text-align: left;

}


.item-center {

    text-align: center;

}


.item-right {

    text-align: right;

}


.item-total {

    font-weight: bold;

    color: #17213a;

}


/* =====================================================
   TOTAL
   ===================================================== */

.total-box {

    width: 97%;
    max-width: 97%;
    margin-top: 12px;
    padding: 12px 15px;
    box-sizing: border-box;

    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-left: 4px solid #2563eb;

    overflow: hidden;

}


.total-table {

    width: 100%;
    max-width: 100%;
    table-layout: fixed;
    border-collapse: collapse;
    margin: 0;
    padding: 0;
    box-sizing: border-box;

}



.total-table td:first-child {
    width: 60%;
}

.total-table td:last-child {
    width: 40%;
}

.total-table td {
    padding: 0;
    border: 0;
}

.total-label {

    color: #475569;

    font-size: 9pt;

    font-weight: bold;

    text-transform: uppercase;

}


.total-value {

    color: #2563eb;

    font-size: 16pt;

    font-weight: bold;

    text-align: right;

}


/* =====================================================
   OBSERVAÇÕES
   ===================================================== */

.observacoes-box {

    margin-top: 17px;

    padding: 11px 13px;

    background: #fffbeb;

    border: 1px solid #fde68a;

    border-radius: 5px;

}


.observacoes-title {

    margin-bottom: 5px;

    color: #92400e;

    font-size: 8.5pt;

    font-weight: bold;

}


.observacoes-text {

    color: #78350f;

    font-size: 8.5pt;

}


/* =====================================================
   PARCELAS
   ===================================================== */

.parcelas-table {

    page-break-inside: auto;

}


.parcelas-table tr {

    page-break-inside: avoid;

}


.parcel-status {

    display: inline-block;

    padding: 4px 9px;

    border-radius: 10px;

    font-size: 7.5pt;

    font-weight: bold;

}


/* =====================================================
   ASSINATURAS
   ===================================================== */

.signatures {

    width: 100%;

    display: table;

    margin-top: 48px;

}


.sign-box {

    display: table-cell;

    width: 45%;

    text-align: center;

    vertical-align: bottom;

}


.sign-space {

    height: 45px;

}


.sign-line {

    border-top: 1px solid #94a3b8;

    padding-top: 6px;

    color: #64748b;

    font-size: 8pt;

}


/* =====================================================
   RODAPÉ
   ===================================================== */

.footer {

    margin-top: 25px;

    padding-top: 9px;

    border-top: 1px solid #e2e8f0;

    text-align: center;

    color: #94a3b8;

    font-size: 7pt;

    line-height: 1.5;

}


/* =====================================================
   QUEBRA DE PÁGINA
   ===================================================== */

.section-break {

    page-break-before: auto;

}


</style>

</head>


<body>


<!-- ===================================================
     CABEÇALHO
     =================================================== -->

<div class='header'>

    <div class='header-left'>

        " .
    (
        $logo_base64
        ? "<img src='{$logo_base64}' class='logo'>"
        : "<strong style='font-size:18pt;color:#2563eb;'>DENTECH</strong>"
    )
    . "

    </div>


    <div class='header-right'>

        <h1 class='document-title'>
            ORÇAMENTO ODONTOLÓGICO
        </h1>

        <div class='document-subtitle'>
            Sistema de Gestão Odontológica Dentech
        </div>


        <span
            class='status-badge'
            style='
                background: {$status_fundo};
                color: {$status_cor};
            '
        >
            {$status_texto}
        </span>

    </div>

</div>


<!-- ===================================================
     INFORMAÇÕES DO DOCUMENTO
     =================================================== -->

<div class='document-info'>

    <table>

        <tr>

            <td>

                <span class='info-label'>
                    Orçamento
                </span>

                <span class='info-value'>
                    #{$id_orc}
                </span>

            </td>


            <td>

                <span class='info-label'>
                    Data de emissão
                </span>

                <span class='info-value'>
                    {$data_criacao}
                </span>

            </td>


            <td>

                <span class='info-label'>
                    Válido até
                </span>

                <span class='info-value'>
                    {$validade}
                </span>

            </td>

        </tr>

    </table>

</div>


<!-- ===================================================
     DADOS DO PACIENTE
     =================================================== -->

<div class='section-title'>
    DADOS DO PACIENTE
</div>


<table class='info-table'>

    <tr>

        <td width='40%'>

            <span class='info-label'>
                Nome completo
            </span>

            <span class='info-value'>
                {$paciente}
            </span>

        </td>


        <td width='30%'>

            <span class='info-label'>
                CPF
            </span>

            <span class='info-value'>
                {$cpf}
            </span>

        </td>


        <td width='30%'>

            <span class='info-label'>
                Telefone
            </span>

            <span class='info-value'>
                {$telefone}
            </span>

        </td>

    </tr>


    <tr>

        <td colspan='3'>

            <span class='info-label'>
                E-mail
            </span>

            <span class='info-value'>
                {$email}
            </span>

        </td>

    </tr>

</table>


<!-- ===================================================
     PROCEDIMENTOS
     =================================================== -->

<div class='section-title'>
    PROCEDIMENTOS E VALORES
</div>


<table class='items-table'>

    <thead>

        <tr>

            <th style='width:46%;'>
                DESCRIÇÃO
            </th>

            <th style='width:12%; text-align:center;'>
                QTD.
            </th>

            <th style='width:21%; text-align:right;'>
                VALOR UNIT.
            </th>

            <th style='width:21%; text-align:right;'>
                SUBTOTAL
            </th>

        </tr>

    </thead>


    <tbody>

        " .
    (
        !empty($linhas_itens)
        ? $linhas_itens
        : "
                <tr>
                    <td colspan='4' style='text-align:center;color:#718096;'>
                        Nenhum procedimento registrado.
                    </td>
                </tr>
            "
    )
    . "

    </tbody>

</table>


<!-- ===================================================
     TOTAL
     =================================================== -->

<div class='total-box'>

    <table class='total-table'>

        <tr>

            <td>

                <div class='total-label'>
                    Valor total do orçamento
                </div>

            </td>


            <td>

                <div class='total-value'>
                    R$ " .
    number_format(
        $total,
        2,
        ',',
        '.'
    )
    . "
                </div>

            </td>

        </tr>

    </table>

</div>


<!-- ===================================================
     OBSERVAÇÕES
     =================================================== -->

{$html_observacoes}


<!-- ===================================================
     PARCELAS
     =================================================== -->

{$html_parcelas}


<!-- ===================================================
     ASSINATURAS
     =================================================== -->

<div class='signatures'>

    <div class='sign-box'>

        <div class='sign-space'></div>

        <div class='sign-line'>
            Assinatura do Paciente
        </div>

    </div>


    <div style='width:10%; display:table-cell;'></div>


    <div class='sign-box'>

        <div class='sign-space'></div>

        <div class='sign-line'>
            Assinatura do Cirurgião-Dentista
        </div>

    </div>

</div>


<!-- ===================================================
     RODAPÉ
     =================================================== -->

<div class='footer'>

    Dentech - Sistema de Gestão Odontológica<br>

    Documento gerado em " .
    date('d/m/Y \\à\\s H:i')
    . "

    <br>

    Este documento apresenta uma proposta de tratamento
    e não substitui a avaliação clínica profissional.

</div>


</body>

</html>
";

