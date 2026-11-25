
<?php

require_once 'config/auth.php';
exigirLogin();

require_once 'conexao/conexao.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método não permitido.');
    }

    // Validação CSRF
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (
        empty($csrfToken) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $csrfToken)
    ) {
        throw new Exception('Token de segurança inválido.');
    }

    $prontuarioId = (int)($_POST['prontuario_id'] ?? 0);
    $registroId = (int)($_POST['registro_id'] ?? 0);
    $registroVinculadoId = (int)($_POST['registro_vinculado_id'] ?? 0);

    $tipoRegistro = $_POST['tipo_registro'] ?? 'tratamento_geral';

    $tiposPermitidos = [
        'tratamento_geral',
        'tratamento_dente',
        'superficie',
        'estado',
        'implante'
    ];

    if (!in_array($tipoRegistro, $tiposPermitidos, true)) {
        throw new Exception('Tipo de registro inválido.');
    }

    if ($prontuarioId <= 0 || $registroId <= 0) {
        throw new Exception('Prontuário ou registro inválido.');
    }

    /*
     * Restauração de registros arquivados.
     * Estados clínicos exigem validação de sequência para não sobrescrever
     * alterações posteriores no mesmo dente.
     */
    $acao = $_POST['acao'] ?? 'arquivar';

    if ($acao === 'classificar_arquivamento_manual') {

        if ($tipoRegistro !== 'superficie') {
            throw new Exception(
                'Esta classificação só é permitida para registros de superfície.'
            );
        }

        $stmtClassificar = $pdo->prepare("
        UPDATE odontograma_registros r
        INNER JOIN odontograma_dentes d
            ON d.id = r.odontograma_dente_id
        SET r.motivo_arquivamento = 'arquivado_manual'
        WHERE r.id = ?
          AND d.prontuario_id = ?
          AND r.arquivado = 1
          AND r.superficie <> 'geral'
          AND COALESCE(r.motivo_arquivamento, '') NOT IN (
              'encerrado_por_estado',
              'arquivado_manual'
          )
    ");

        $stmtClassificar->execute([
            $registroId,
            $prontuarioId
        ]);

        if ($stmtClassificar->rowCount() !== 1) {
            throw new Exception(
                'O registro não existe, já foi classificado ou não pode ser alterado.'
            );
        }

        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Registro arquivado manualmente. O histórico original foi preservado.'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }



    if ($acao === 'excluir_vinculo_plano') {

        $pdo->beginTransaction();

        if ($tipoRegistro === 'tratamento_geral') {

            $stmt = $pdo->prepare("
                SELECT id, plano_tratamento_item_id
                FROM odontograma_tratamentos_gerais
                WHERE id = ?
                  AND prontuario_id = ?
                FOR UPDATE
            ");

            $stmt->execute([$registroId, $prontuarioId]);

            $registro = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$registro || empty($registro['plano_tratamento_item_id'])) {
                throw new Exception(
                    'Este registro não possui vínculo com um plano de tratamento.'
                );
            }

            $stmtDesvincular = $pdo->prepare("
                UPDATE odontograma_tratamentos_gerais
                SET plano_tratamento_item_id = NULL
                WHERE id = ?
                  AND prontuario_id = ?
                  AND plano_tratamento_item_id IS NOT NULL
            ");

            $stmtDesvincular->execute([$registroId, $prontuarioId]);
        } elseif ($tipoRegistro === 'tratamento_dente') {

            $stmt = $pdo->prepare("
                SELECT t.id, t.plano_tratamento_item_id
                FROM odontograma_tratamentos_dentes t
                INNER JOIN odontograma_dentes d
                    ON d.id = t.odontograma_dente_id
                WHERE t.id = ?
                  AND d.prontuario_id = ?
                FOR UPDATE
            ");

            $stmt->execute([$registroId, $prontuarioId]);

            $registro = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$registro || empty($registro['plano_tratamento_item_id'])) {
                throw new Exception(
                    'Este registro não possui vínculo com um plano de tratamento.'
                );
            }

            $stmtDesvincular = $pdo->prepare("
                UPDATE odontograma_tratamentos_dentes t
                INNER JOIN odontograma_dentes d
                    ON d.id = t.odontograma_dente_id
                SET t.plano_tratamento_item_id = NULL
                WHERE t.id = ?
                  AND d.prontuario_id = ?
                  AND t.plano_tratamento_item_id IS NOT NULL
            ");

            $stmtDesvincular->execute([$registroId, $prontuarioId]);
        } elseif ($tipoRegistro === 'superficie') {

            $stmt = $pdo->prepare("
                SELECT
                    r.id,
                    r.plano_tratamento_item_id,
                    r.arquivado
                FROM odontograma_registros r
                INNER JOIN odontograma_dentes d
                    ON d.id = r.odontograma_dente_id
                WHERE r.id = ?
                  AND d.prontuario_id = ?
                FOR UPDATE
            ");

            $stmt->execute([$registroId, $prontuarioId]);

            $registro = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$registro || empty($registro['plano_tratamento_item_id'])) {
                throw new Exception(
                    'Este registro não possui vínculo com um plano de tratamento.'
                );
            }

            // Remove somente o vínculo.
            // O registro clínico, seu histórico e suas relações permanecem.
            // Funciona tanto para registros ativos quanto arquivados.
            $stmtDesvincular = $pdo->prepare("
                UPDATE odontograma_registros r
                INNER JOIN odontograma_dentes d
                    ON d.id = r.odontograma_dente_id
                SET r.plano_tratamento_item_id = NULL
                WHERE r.id = ?
                  AND d.prontuario_id = ?
                  AND r.plano_tratamento_item_id IS NOT NULL
            ");

            $stmtDesvincular->execute([$registroId, $prontuarioId]);
        } else {
            throw new Exception(
                'Este tipo de registro não permite desvinculação do plano.'
            );
        }

        if ($stmtDesvincular->rowCount() !== 1) {
            throw new Exception(
                'Não foi possível remover o vínculo com o plano.'
            );
        }

        $pdo->commit();

        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Vínculo removido. O registro clínico e seu histórico foram preservados.'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }


    if ($acao !== 'arquivar') {
        throw new Exception('Ação inválida.');
    }

    if ($tipoRegistro === 'tratamento_geral') {
        $stmtRegistro = $pdo->prepare("SELECT id, plano_tratamento_item_id FROM odontograma_tratamentos_gerais WHERE id = ? AND prontuario_id = ? AND arquivado = 0 LIMIT 1");
        $stmtRegistro->execute([$registroId, $prontuarioId]);
    } elseif ($tipoRegistro === 'tratamento_dente') {
        $stmtRegistro = $pdo->prepare("SELECT t.id, t.plano_tratamento_item_id FROM odontograma_tratamentos_dentes t INNER JOIN odontograma_dentes d ON d.id = t.odontograma_dente_id WHERE t.id = ? AND d.prontuario_id = ? AND t.arquivado = 0 LIMIT 1");
        $stmtRegistro->execute([$registroId, $prontuarioId]);
    } else {

        $tabelasPermitidas = [
            'superficie' => 'odontograma_registros',
            'estado' => 'odontograma_estados_historico',
            'implante' => 'odontograma_implantes_historico'
        ];

        $tabela = $tabelasPermitidas[$tipoRegistro];


        $tabela = $tabelasPermitidas[$tipoRegistro];
        $colunaVinculo = $tipoRegistro === 'superficie' ? ', h.plano_tratamento_item_id' : '';
        $stmtRegistro = $pdo->prepare("SELECT h.id{$colunaVinculo} FROM {$tabela} h INNER JOIN odontograma_dentes d ON d.id = h.odontograma_dente_id WHERE h.id = ? AND d.prontuario_id = ? AND h.arquivado = 0 LIMIT 1");
        $stmtRegistro->execute([$registroId, $prontuarioId]);
    }

    $registroParaArquivar = $stmtRegistro->fetch(PDO::FETCH_ASSOC);

    if (!$registroParaArquivar) {
        throw new Exception(
            'Registro não encontrado, já arquivado ou não pertence a este prontuário.'
        );
    }

    // O arquivamento é permitido mesmo quando existe vínculo com o plano.
    // O plano_tratamento_item_id é preservado; por isso, a FK continua
    // impedindo a exclusão do item do plano até a exclusão definitiva do vínculo.


    $pdo->beginTransaction();

    $denteId = null;
    $estadoAnterior = null;
    $deveRestaurarEstado = false;

    $statusImplanteArquivado = null;
    $statusImplanteAnterior = 'nao_se_aplica';
    $deveRestaurarImplante = false;

    if ($tipoRegistro === 'implante') {

        // Localiza e bloqueia o histórico e o dente durante a operação.
        $stmtImplante = $pdo->prepare("
        SELECT
            h.odontograma_dente_id,
            h.status,
            h.data_registro,
            d.implante_status
        FROM odontograma_implantes_historico h
        INNER JOIN odontograma_dentes d
            ON d.id = h.odontograma_dente_id
        WHERE h.id = ?
          AND d.prontuario_id = ?
          AND h.arquivado = 0
        FOR UPDATE
    ");

        $stmtImplante->execute([
            $registroId,
            $prontuarioId
        ]);

        $dadosImplante = $stmtImplante->fetch(PDO::FETCH_ASSOC);

        if (!$dadosImplante) {
            throw new Exception(
                'Histórico de implante não encontrado ou já arquivado.'
            );
        }

        $denteId = (int) $dadosImplante['odontograma_dente_id'];
        $statusImplanteArquivado = $dadosImplante['status'];
        $dataImplanteArquivado = $dadosImplante['data_registro'];
        $statusImplanteAtual = $dadosImplante['implante_status'];

        // Verifica se existe um evento posterior ainda ativo.
        $stmtImplantePosterior = $pdo->prepare("
        SELECT id
        FROM odontograma_implantes_historico
        WHERE odontograma_dente_id = ?
          AND arquivado = 0
          AND id <> ?
          AND (
              data_registro > ?
              OR (
                  data_registro = ?
                  AND id > ?
              )
          )
        LIMIT 1
        FOR UPDATE
    ");

        $stmtImplantePosterior->execute([
            $denteId,
            $registroId,
            $dataImplanteArquivado,
            $dataImplanteArquivado,
            $registroId
        ]);

        $existeImplantePosterior =
            (bool) $stmtImplantePosterior->fetchColumn();

        // Só restaura se o evento arquivado ainda representar
        // o status atual do dente e não houver evento posterior ativo.
        if (
            $statusImplanteAtual === $statusImplanteArquivado &&
            !$existeImplantePosterior
        ) {

            // Busca o evento anterior que continua ativo.
            $stmtImplanteAnterior = $pdo->prepare("
            SELECT status
            FROM odontograma_implantes_historico
            WHERE odontograma_dente_id = ?
              AND arquivado = 0
              AND id <> ?
              AND (
                  data_registro < ?
                  OR (
                      data_registro = ?
                      AND id < ?
                  )
              )
            ORDER BY data_registro DESC, id DESC
            LIMIT 1
            FOR UPDATE
        ");

            $stmtImplanteAnterior->execute([
                $denteId,
                $registroId,
                $dataImplanteArquivado,
                $dataImplanteArquivado,
                $registroId
            ]);

            $statusAnteriorEncontrado =
                $stmtImplanteAnterior->fetchColumn();

            if ($statusAnteriorEncontrado !== false) {
                $statusImplanteAnterior = $statusAnteriorEncontrado;
            }

            $deveRestaurarImplante = true;
        }
    }


    if ($tipoRegistro === 'estado') {
        // Identifica o dente e o estado que será arquivado.
        $stmtEstado = $pdo->prepare("
        SELECT
            h.odontograma_dente_id,
            h.estado,
            h.data_registro
        FROM odontograma_estados_historico h
        INNER JOIN odontograma_dentes d
            ON d.id = h.odontograma_dente_id
        WHERE h.id = ?
          AND d.prontuario_id = ?
          AND h.arquivado = 0
        FOR UPDATE
    ");

        $stmtEstado->execute([$registroId, $prontuarioId]);
        $dadosEstado = $stmtEstado->fetch(PDO::FETCH_ASSOC);

        if (!$dadosEstado) {
            throw new Exception(
                'Estado não encontrado ou já arquivado.'
            );
        }

        $denteId = (int) $dadosEstado['odontograma_dente_id'];
        $estadoArquivado = $dadosEstado['estado'];
        $dataRegistroArquivado = $dadosEstado['data_registro'];

        // Valida o tratamento vinculado ao mesmo dente e prontuário.
        if ($registroVinculadoId > 0) {
            $stmtTratamentoVinculado = $pdo->prepare("
    SELECT r.id
    FROM odontograma_registros r
    INNER JOIN odontograma_dentes d
        ON d.id = r.odontograma_dente_id
    WHERE r.id = ?
      AND r.odontograma_dente_id = ?
      AND d.prontuario_id = ?
      AND r.estado_historico_id = ?
      AND r.plano_tratamento_item_id IS NOT NULL
      AND r.arquivado = 0
    FOR UPDATE
");

            $stmtTratamentoVinculado->execute([
                $registroVinculadoId,
                $denteId,
                $prontuarioId,
                $registroId
            ]);

            if (!$stmtTratamentoVinculado->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception(
                    'O tratamento vinculado não foi encontrado ou não pertence a este dente.'
                );
            }
        }

        $operacaoEstado = null;

        if ($tipoRegistro === 'estado') {
            // Toda alteração de estado geral possui uma operação vinculada,
            // inclusive estados clínicos comuns (Hígido, Cárie, Restaurado etc.).
            $stmtOperacaoEstado = $pdo->prepare("
        SELECT
            estado_geral_anterior,
            registro_geral_anterior_id,
            registro_geral_atual_id
        FROM odontograma_estados_operacoes
        WHERE estado_historico_id = ?
        FOR UPDATE
    ");

            $stmtOperacaoEstado->execute([$registroId]);

            $operacaoEstado = $stmtOperacaoEstado->fetch(PDO::FETCH_ASSOC);
        }

        // Confere o estado atual do dente.
        $stmtDente = $pdo->prepare("
        SELECT estado_geral
        FROM odontograma_dentes
        WHERE id = ?
          AND prontuario_id = ?
        FOR UPDATE
    ");

        $stmtDente->execute([$denteId, $prontuarioId]);
        $estadoAtual = $stmtDente->fetchColumn();

        // Verifica se existe um estado geral posterior ainda ativo.
        $stmtEventoPosterior = $pdo->prepare("
    SELECT id
    FROM odontograma_estados_historico
    WHERE odontograma_dente_id = ?
      AND arquivado = 0
      AND (
          data_registro > ?
          OR (
              data_registro = ?
              AND id > ?
          )
      )
    LIMIT 1
");

        $stmtEventoPosterior->execute([
            $denteId,
            $dataRegistroArquivado,
            $dataRegistroArquivado,
            $registroId
        ]);

        $existeEventoPosterior = (bool) $stmtEventoPosterior->fetchColumn();

        // Só recalcula se o registro arquivado representar o estado atual
        // e não existir um evento posterior ainda ativo.
        if (
            $estadoAtual === $estadoArquivado &&
            !$existeEventoPosterior
        ) {
            $deveRestaurarEstado = true;
        } else {
            $deveRestaurarEstado = false;
        }

        if ($deveRestaurarEstado) {
            if ($operacaoEstado !== null) {
                $registroGeralAnteriorId = $operacaoEstado['registro_geral_anterior_id'];


                if ($registroGeralAnteriorId !== null) {
                    $stmtHistoricoAnteriorAtivo = $pdo->prepare("
    SELECT
        r.condicao,
        r.estado_historico_id,
        r.arquivado AS registro_arquivado,
        r.motivo_arquivamento,
        h.estado AS estado_historico,
        h.arquivado AS historico_arquivado
    FROM odontograma_registros r
    LEFT JOIN odontograma_estados_historico h
        ON h.id = r.estado_historico_id
       AND h.odontograma_dente_id = r.odontograma_dente_id
    WHERE r.id = ?
      AND r.odontograma_dente_id = ?
      AND r.superficie = 'geral'
    LIMIT 1
");

                    $stmtHistoricoAnteriorAtivo->execute([
                        (int) $registroGeralAnteriorId,
                        $denteId
                    ]);

                    $registroAnterior = $stmtHistoricoAnteriorAtivo->fetch(PDO::FETCH_ASSOC);

                    if (!$registroAnterior) {

                        $estadoAnterior = 'nao_avaliado';
                    } else {

                        $registroAnteriorPodeSerRestaurado =
                            (int)$registroAnterior['registro_arquivado'] === 0
                            || in_array(
                                $registroAnterior['motivo_arquivamento'],
                                ['encerrado_por_estado', 'substituido'],
                                true
                            );

                        if (!$registroAnteriorPodeSerRestaurado) {

                            $estadoAnterior = 'nao_avaliado';
                        } elseif ($registroAnterior['estado_historico_id'] === null) {

                            // Compatibilidade com registros antigos.
                            $estadoAnterior =
                                $registroAnterior['condicao'] === $operacaoEstado['estado_geral_anterior']
                                ? $operacaoEstado['estado_geral_anterior']
                                : 'nao_avaliado';
                        } elseif (
                            (int)$registroAnterior['historico_arquivado'] === 0
                            && $registroAnterior['estado_historico'] === $operacaoEstado['estado_geral_anterior']
                        ) {

                            $estadoAnterior = $registroAnterior['estado_historico'];
                        } else {

                            $estadoAnterior = 'nao_avaliado';
                        }
                    }
                } else {
                    $estadoAnterior = 'nao_avaliado';
                }
            } else {
                // Compatibilidade com históricos antigos, sem dados da operação.
                $stmtHistoricoAnterior = $pdo->prepare("
            SELECT estado
            FROM odontograma_estados_historico
            WHERE odontograma_dente_id = ?
              AND id <> ?
              AND arquivado = 0
            ORDER BY data_registro DESC, id DESC
            LIMIT 1
        ");

                $stmtHistoricoAnterior->execute([
                    $denteId,
                    $registroId
                ]);

                $estadoAnterior = $stmtHistoricoAnterior->fetchColumn();
            }

            if ($estadoAnterior === false || $estadoAnterior === null) {
                $estadoAnterior = 'nao_avaliado';
            }
        }
    }


    if ($tipoRegistro === 'tratamento_geral') {
        $stmtArquivar = $pdo->prepare("UPDATE odontograma_tratamentos_gerais SET arquivado = 1 WHERE id = ? AND prontuario_id = ? AND arquivado = 0");
        $stmtArquivar->execute([$registroId, $prontuarioId]);
    } elseif ($tipoRegistro === 'tratamento_dente') {
        $stmtArquivar = $pdo->prepare("UPDATE odontograma_tratamentos_dentes t INNER JOIN odontograma_dentes d ON d.id = t.odontograma_dente_id SET t.arquivado = 1 WHERE t.id = ? AND d.prontuario_id = ? AND t.arquivado = 0");
        $stmtArquivar->execute([$registroId, $prontuarioId]);
    } else {
        $tabelasPermitidas = [
            'superficie' => 'odontograma_registros',
            'estado' => 'odontograma_estados_historico',
            'implante' => 'odontograma_implantes_historico'
        ];

        $tabela = $tabelasPermitidas[$tipoRegistro];

        if ($tipoRegistro === 'superficie') {
            $stmtArquivar = $pdo->prepare("
            UPDATE odontograma_registros r
            INNER JOIN odontograma_dentes d
                ON d.id = r.odontograma_dente_id
            SET
                r.arquivado = 1,
                r.motivo_arquivamento = 'arquivado_manual'
            WHERE r.id = ?
              AND d.prontuario_id = ?
              AND r.arquivado = 0
        ");
        } else {
            $stmtArquivar = $pdo->prepare("
            UPDATE {$tabela} h
            INNER JOIN odontograma_dentes d
                ON d.id = h.odontograma_dente_id
            SET h.arquivado = 1
            WHERE h.id = ?
              AND d.prontuario_id = ?
              AND h.arquivado = 0
        ");
        }

        $stmtArquivar->execute([$registroId, $prontuarioId]);
    }



    if ($stmtArquivar->rowCount() !== 1) {
        throw new Exception(
            'Não foi possível arquivar o tratamento. Atualize o histórico e tente novamente.'
        );
    }


    // Restaura o evento anterior somente da mesma superfície.
    if ($tipoRegistro === 'superficie') {

        // Recupera o registro recém-arquivado e bloqueia sua linha.
        $stmtSuperficieArquivada = $pdo->prepare("
        SELECT
            r.id,
            r.odontograma_dente_id,
            r.superficie,
            r.data_registro
        FROM odontograma_registros r
        INNER JOIN odontograma_dentes d
            ON d.id = r.odontograma_dente_id
        WHERE r.id = ?
          AND d.prontuario_id = ?
          AND r.arquivado = 1
        LIMIT 1
        FOR UPDATE
    ");

        $stmtSuperficieArquivada->execute([
            $registroId,
            $prontuarioId
        ]);

        $superficieArquivada = $stmtSuperficieArquivada->fetch(PDO::FETCH_ASSOC);

        if (!$superficieArquivada) {
            throw new Exception(
                'Não foi possível identificar a superfície arquivada.'
            );
        }

        $superficieDenteId = (int) $superficieArquivada['odontograma_dente_id'];
        $nomeSuperficie = $superficieArquivada['superficie'];
        $dataSuperficieArquivada = $superficieArquivada['data_registro'];

        // O registro geral não participa da restauração individual de superfícies.
        if ($nomeSuperficie !== 'geral') {

            // Verifica se existe evento posterior ainda ativo na mesma superfície.
            $stmtEventoPosterior = $pdo->prepare("
            SELECT id
            FROM odontograma_registros
            WHERE odontograma_dente_id = ?
              AND superficie = ?
              AND arquivado = 0
              AND (
                  data_registro > ?
                  OR (
                      data_registro = ?
                      AND id > ?
                  )
              )
            LIMIT 1
            FOR UPDATE
        ");

            $stmtEventoPosterior->execute([
                $superficieDenteId,
                $nomeSuperficie,
                $dataSuperficieArquivada,
                $dataSuperficieArquivada,
                $registroId
            ]);

            $existeEventoPosterior = (bool) $stmtEventoPosterior->fetchColumn();

            // Só procura restauração quando não existe evento posterior ativo.
            if (!$existeEventoPosterior) {


                // Busca os eventos anteriores da mesma superfície,
                // do mais recente para o mais antigo.
                $stmtEventosAnteriores = $pdo->prepare("
                SELECT
                    id,
                    arquivado,
                    motivo_arquivamento
                FROM odontograma_registros
                WHERE odontograma_dente_id = ?
                  AND superficie = ?
                  AND (
                      data_registro < ?
                      OR (
                          data_registro = ?
                          AND id < ?
                      )
                  )
                ORDER BY data_registro DESC, id DESC
                FOR UPDATE
            ");

                $stmtEventosAnteriores->execute([
                    $superficieDenteId,
                    $nomeSuperficie,
                    $dataSuperficieArquivada,
                    $dataSuperficieArquivada,
                    $registroId
                ]);

                $eventosAnteriores = $stmtEventosAnteriores->fetchAll(PDO::FETCH_ASSOC);

                $eventoParaRestaurar = null;

                foreach ($eventosAnteriores as $eventoAnterior) {

                    // Ignora registros arquivados manualmente.
                    if (
                        (int) $eventoAnterior['arquivado'] === 1 &&
                        $eventoAnterior['motivo_arquivamento'] === 'arquivado_manual'
                    ) {
                        continue;
                    }

                    // O primeiro evento não arquivado manualmente
                    // precisa ter sido substituído automaticamente.
                    if (
                        (int) $eventoAnterior['arquivado'] === 1 &&
                        $eventoAnterior['motivo_arquivamento'] === 'substituido'
                    ) {
                        $eventoParaRestaurar = $eventoAnterior;
                    }

                    // Não ultrapassa eventos ativos ou arquivados
                    // por outros motivos.
                    break;
                }

                if ($eventoParaRestaurar !== null) {

                    $stmtRestaurarSuperficieAnterior = $pdo->prepare("
                    UPDATE odontograma_registros
                    SET
                        arquivado = 0,
                        motivo_arquivamento = NULL
                    WHERE id = ?
                      AND odontograma_dente_id = ?
                      AND superficie = ?
                      AND arquivado = 1
                      AND motivo_arquivamento = 'substituido'
                ");

                    $stmtRestaurarSuperficieAnterior->execute([
                        (int) $eventoParaRestaurar['id'],
                        $superficieDenteId,
                        $nomeSuperficie
                    ]);

                    if ($stmtRestaurarSuperficieAnterior->rowCount() !== 1) {
                        throw new Exception(
                            'Não foi possível restaurar o evento anterior da superfície.'
                        );
                    }
                }
            }
        }
    }



    // Restaura o status anterior do implante quando a sequência permite.
    if (
        $tipoRegistro === 'implante' &&
        $deveRestaurarImplante
    ) {

        $stmtAtualizarImplante = $pdo->prepare("
        UPDATE odontograma_dentes
        SET
            implante_status = ?,
            atualizado_em = NOW()
        WHERE id = ?
          AND prontuario_id = ?
    ");

        $stmtAtualizarImplante->execute([
            $statusImplanteAnterior,
            $denteId,
            $prontuarioId
        ]);

        if ($stmtAtualizarImplante->rowCount() > 1) {
            throw new Exception(
                'A restauração afetou mais de um dente.'
            );
        }
    }


    // Arquiva o registro geral associado ao histórico exato.
    // Não aplica a estados especiais, que possuem fluxo próprio.
    if (
        $tipoRegistro === 'estado' &&
        $operacaoEstado === null
    ) {
        $stmtArquivarRegistroGeral = $pdo->prepare("
        UPDATE odontograma_registros
        SET
            arquivado = 1,
            motivo_arquivamento = 'arquivado_manual'
        WHERE odontograma_dente_id = ?
          AND estado_historico_id = ?
          AND superficie = 'geral'
          AND arquivado = 0
    ");

        $stmtArquivarRegistroGeral->execute([
            $denteId,
            $registroId
        ]);
    }


    if ($tipoRegistro === 'estado' && $operacaoEstado !== null) {

        // Arquiva somente o registro geral criado pela operação desfeita.
        // Isso deve acontecer mesmo quando existe um evento posterior.
        $registroGeralAtualId = $operacaoEstado['registro_geral_atual_id'];


        if ($registroGeralAtualId !== null) {
            $stmtArquivarGeralAtual = $pdo->prepare("
        UPDATE odontograma_registros
        SET
            arquivado = 1,
            motivo_arquivamento = 'encerrado_por_estado'
        WHERE id = ?
          AND odontograma_dente_id = ?
          AND superficie = 'geral'
          AND arquivado = 0
    ");

            $stmtArquivarGeralAtual->execute([
                (int) $registroGeralAtualId,
                $denteId
            ]);

            if ($stmtArquivarGeralAtual->rowCount() !== 1) {
                // O registro pode ter sido arquivado por uma transição posterior
                // (por exemplo, Extraído -> Implante) com motivo "substituido".
                // Nesse caso, basta confirmar que o ID exato da operação existe
                // e já está arquivado. O motivo original é preservado.
                $stmtVerificarGeralAtual = $pdo->prepare("
            SELECT arquivado
            FROM odontograma_registros
            WHERE id = ?
              AND odontograma_dente_id = ?
              AND superficie = 'geral'
            LIMIT 1
            FOR UPDATE
        ");

                $stmtVerificarGeralAtual->execute([
                    (int) $registroGeralAtualId,
                    $denteId
                ]);

                $situacaoGeralAtual = $stmtVerificarGeralAtual->fetchColumn();

                if ($situacaoGeralAtual === false || (int) $situacaoGeralAtual !== 1) {
                    throw new Exception(
                        'Não foi possível confirmar o encerramento do estado geral criado pela operação.'
                    );
                }
            }
        }

        // Arquiva em conjunto somente os eventos de implante vinculados
        // explicitamente a esta operação de Extraído/Ausente.
        $stmtArquivarHistoricoImplante = $pdo->prepare("
            UPDATE odontograma_implantes_historico
            SET arquivado = 1
            WHERE odontograma_dente_id = ?
              AND estado_historico_id = ?
              AND arquivado = 0
        ");
        $stmtArquivarHistoricoImplante->execute([
            $denteId,
            $registroId
        ]);

        // A restauração só acontece quando a sequência dos eventos permite.
        if ($deveRestaurarEstado) {

            // Reativa somente o registro geral anterior vinculado à operação.
            $registroGeralAnteriorId = $operacaoEstado['registro_geral_anterior_id'];

            if ($registroGeralAnteriorId !== null) {

                $stmtRestaurarGeralAnterior = $pdo->prepare("
    UPDATE odontograma_registros r
    SET
        r.arquivado = 0,
        r.motivo_arquivamento = NULL
    WHERE r.id = ?
      AND r.odontograma_dente_id = ?
      AND r.superficie = 'geral'
      AND r.arquivado = 1
     AND r.motivo_arquivamento IN (
    'encerrado_por_estado',
    'substituido'
)
      AND (
          (
              r.estado_historico_id IS NULL
              AND r.condicao = ?
          )
          OR EXISTS (
              SELECT 1
              FROM odontograma_estados_historico h
              WHERE h.id = r.estado_historico_id
                AND h.odontograma_dente_id = r.odontograma_dente_id
                AND h.arquivado = 0
                AND h.estado = r.condicao
          )
      )
");


                $stmtRestaurarGeralAnterior->execute([
                    (int) $registroGeralAnteriorId,
                    $denteId,
                    $operacaoEstado['estado_geral_anterior']
                ]);



                if ($stmtRestaurarGeralAnterior->rowCount() !== 1) {

                    // Confirma a situação atual do registro geral anterior.
                    $stmtVerificarGeralAnterior = $pdo->prepare("
        SELECT
            r.arquivado,
            r.motivo_arquivamento,
            CASE
                WHEN (
                    (
                        r.estado_historico_id IS NULL
                        AND r.condicao = ?
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM odontograma_estados_historico h
                        WHERE h.id = r.estado_historico_id
                          AND h.odontograma_dente_id = r.odontograma_dente_id
                          AND h.arquivado = 0
                          AND h.estado = r.condicao
                    )
                )
                THEN 1
                ELSE 0
            END AS compativel
        FROM odontograma_registros r
        WHERE r.id = ?
          AND r.odontograma_dente_id = ?
          AND r.superficie = 'geral'
        LIMIT 1
    ");

                    $stmtVerificarGeralAnterior->execute([
                        $operacaoEstado['estado_geral_anterior'],
                        (int) $registroGeralAnteriorId,
                        $denteId
                    ]);

                    $registroAnterior = $stmtVerificarGeralAnterior->fetch(PDO::FETCH_ASSOC);

                    // O registro precisa existir e continuar compatível.
                    if (!$registroAnterior) {
                        throw new Exception(
                            'O registro geral anterior da operação não foi encontrado.'
                        );
                    }

                    // Se já está ativo, nenhuma restauração é necessária.
                    if ((int) $registroAnterior['arquivado'] === 0) {
                        // Mantém como está.
                    } elseif (
                        !in_array(
                            $registroAnterior['motivo_arquivamento'],
                            ['encerrado_por_estado', 'substituido'],
                            true
                        )
                    ) {
                        // Arquivamento manual, substituição ou registro legado.
                        // Preserva o arquivamento.
                    } elseif ((int) $registroAnterior['compativel'] !== 1) {
                        // O histórico associado não está mais ativo ou compatível.
                        // Não reativa o registro.
                    } else {
                        // O registro deveria ter sido restaurado, mas o UPDATE não conseguiu.
                        throw new Exception(
                            'Não foi possível restaurar o estado geral anterior do dente.'
                        );
                    }
                }
            }

            // Reativa somente as superfícies registradas antes da operação.
            $stmtRestaurarSuperficies = $pdo->prepare("
            UPDATE odontograma_registros r
            INNER JOIN odontograma_estados_superficies es
                ON es.registro_superficie_id = r.id
            SET
                r.arquivado = 0,
                r.motivo_arquivamento = NULL
            WHERE es.estado_historico_id = ?
                AND r.odontograma_dente_id = ?
                AND r.superficie <> 'geral'
                AND r.arquivado = 1
                AND r.motivo_arquivamento = 'encerrado_por_estado'
        ");

            $stmtRestaurarSuperficies->execute([
                $registroId,
                $denteId
            ]);
        }
    }


    // Arquiva também o tratamento vinculado ao estado clínico.
    if ($tipoRegistro === 'estado' && $registroVinculadoId > 0) {
        $stmtArquivarVinculado = $pdo->prepare("
    UPDATE odontograma_registros
    SET
        arquivado = 1,
        motivo_arquivamento = 'arquivado_manual'
    WHERE id = ?
      AND odontograma_dente_id = ?
      AND estado_historico_id = ?
      AND arquivado = 0
");

        $stmtArquivarVinculado->execute([
            $registroVinculadoId,
            $denteId,
            $registroId
        ]);


        if ($stmtArquivarVinculado->rowCount() !== 1) {
            // O registro pode já ter sido arquivado pela lógica anterior.
            // O motivo esperado depende do estado clínico removido.
            $motivoEsperado = in_array(
                $estadoArquivado,
                ['extraido', 'ausente'],
                true
            )
                ? 'encerrado_por_estado'
                : 'arquivado_manual';

            $stmtVerificarVinculado = $pdo->prepare("
        SELECT 1
        FROM odontograma_registros
        WHERE id = ?
          AND odontograma_dente_id = ?
          AND estado_historico_id = ?
          AND arquivado = 1
          AND motivo_arquivamento = ?
        LIMIT 1
    ");

            $stmtVerificarVinculado->execute([
                $registroVinculadoId,
                $denteId,
                $registroId,
                $motivoEsperado
            ]);

            if (!$stmtVerificarVinculado->fetchColumn()) {
                throw new Exception(
                    'Não foi possível arquivar o tratamento vinculado ao estado clínico.'
                );
            }
        }
    }

    if (
        $tipoRegistro === 'estado' &&
        $deveRestaurarEstado &&
        $estadoAnterior !== null
    ) {
        $stmtAtualizarDente = $pdo->prepare("
        UPDATE odontograma_dentes
        SET estado_geral = ?,
            atualizado_em = NOW()
        WHERE id = ?
          AND prontuario_id = ?
    ");

        $stmtAtualizarDente->execute([
            $estadoAnterior,
            $denteId,
            $prontuarioId
        ]);

        if ($stmtAtualizarDente->rowCount() > 1) {
            throw new Exception(
                'A restauração afetou mais de um dente.'
            );
        }
    }

    $pdo->commit();

    $mensagensSucesso = [
        'tratamento_geral' => 'Tratamento de arcada completa arquivado com sucesso.',
        'tratamento_dente' => 'Tratamento do dente arquivado com sucesso.',
        'superficie' => 'Registro de superfície arquivado com sucesso.',
        'estado' => 'Registro de estado geral arquivado com sucesso.',
        'implante' => 'Registro de implante arquivado com sucesso.'
    ];

    echo json_encode([
        'sucesso' => true,
        'mensagem' => $mensagensSucesso[$tipoRegistro]
    ], JSON_UNESCAPED_UNICODE);

    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);

    error_log('Erro ao arquivar tratamento do odontograma: ' . $e->getMessage());

    echo json_encode([
        'sucesso' => false,
        'mensagem' => $e instanceof PDOException
            ? 'Não foi possível arquivar o tratamento. Tente novamente.'
            : $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
