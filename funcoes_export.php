<?php

/**
 * Exporta uma consulta SQL para CSV compatível com Excel em português/Brasil.
 *
 * A consulta deve usar aliases amigáveis para as colunas quando desejado.
 */
function exportarParaCSV(
    PDO $pdo,
    string $titulo,
    string $sql,
    array $params = [],
    string $nomeArquivo = 'exportacao'
): void {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $nomeArquivo = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $nomeArquivo);
    $nomeArquivo = trim($nomeArquivo, '_') ?: 'exportacao';

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="' .
            $nomeArquivo . '_' . date('Y-m-d_H-i-s') . '.csv"'
    );
    header('Pragma: no-cache');
    header('Expires: 0');

    $saida = fopen('php://output', 'wb');

    // BOM UTF-8 para o Excel reconhecer acentos corretamente.
    fwrite($saida, "\xEF\xBB\xBF");

    if (empty($linhas)) {
        fputcsv($saida, ['Nenhum registro encontrado'], ';');
        fclose($saida);
        exit;
    }

    $cabecalhos = array_keys($linhas[0]);
    fputcsv($saida, $cabecalhos, ';');

    $statusLabels = [
        'pendente' => 'Pendente',
        'parcialmente_aceito' => 'Parcialmente aceito',
        'aceito' => 'Aceito',
        'parcialmente_recusado' => 'Parcialmente recusado',
        'recusado' => 'Recusado'
    ];

    foreach ($linhas as $linha) {
        $registro = [];

        foreach ($cabecalhos as $chave) {
            $valor = $linha[$chave] ?? '';
            if ($chave === 'Status') {
                $valor = $statusLabels[$valor] ?? $valor;
            }

            if (
                in_array(
                    $chave,
                    ['Data de criação', 'Validade'],
                    true
                ) && !empty($valor)
            ) {
                $timestamp = strtotime((string)$valor);
                if ($timestamp !== false) {
                    $valor = date('d/m/Y', $timestamp);
                }
            }

            if ($chave === 'Valor total' && is_numeric($valor)) {
                $valor = 'R$ ' . number_format(
                    (float)$valor,
                    2,
                    ',',
                    '.'
                );
            }

            $registro[] = $valor;
        }

        fputcsv($saida, $registro, ';');
    }

    fclose($saida);
    exit;
}
