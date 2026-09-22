<?php

/**
 * Calcula o status geral de um orçamento
 * com base nos status dos seus itens.
 *
 * @param array $itens Lista de itens do orçamento.
 * @return string
 */
function calcularStatusOrcamento(array $itens): string
{
    if (count($itens) === 0) {
        return 'pendente';
    }

    $total = count($itens);
    $aceitos = 0;
    $recusados = 0;
    $pendentes = 0;

    foreach ($itens as $item) {
        switch ($item['status'] ?? 'pendente') {
            case 'aceito':
                $aceitos++;
                break;

            case 'recusado':
                $recusados++;
                break;

            default:
                $pendentes++;
                break;
        }
    }

    /*
     * Todos os itens estão pendentes.
     */
    if ($pendentes === $total) {
        return 'pendente';
    }

    /*
     * Todos os itens foram aceitos.
     */
    if ($aceitos === $total) {
        return 'aceito';
    }

    /*
     * Todos os itens foram recusados.
     */
    if ($recusados === $total) {
        return 'recusado';
    }

    /*
     * Existe pelo menos um item aceito.
     *
     * A aceitação tem precedência quando há
     * mistura de itens aceitos e recusados.
     */
    if ($aceitos > 0) {
        return 'parcialmente_aceito';
    }

    /*
     * Não existem itens aceitos, mas existe
     * pelo menos um item recusado.
     */
    if ($recusados > 0) {
        return 'parcialmente_recusado';
    }

    return 'pendente';
}