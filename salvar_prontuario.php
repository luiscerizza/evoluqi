<?php
require_once 'config/auth.php';
require_once 'config/csrf.php';
exigirLogin();
require_once 'conexao/conexao.php';

// Limpa qualquer saída anterior
if (ob_get_level()) ob_end_clean();

// Garante que NADA seja enviado antes do JSON
header('Content-Type: application/json');

try {

    validar_csrf();
    // Validação básica
    $paciente = trim($_POST['paciente'] ?? '');
    $nascimento = $_POST['nascimento'] ?? '';

    if (empty($paciente) || empty($nascimento)) {
        throw new Exception("Nome e data de nascimento são obrigatórios.");
    }

    $data_obj = DateTime::createFromFormat('Y-m-d', $nascimento);
    if (!$data_obj || $data_obj->format('Y-m-d') !== $nascimento) {
        throw new Exception("Data de nascimento inválida.");
    }

    // Processar problemas de saúde (com fallback seguro)
    $problemas_saude = [];
    if (!empty($_POST['problemas_saude']) && is_array($_POST['problemas_saude'])) {
        $problemas_saude = array_filter($_POST['problemas_saude']);
    }
    if (!empty($_POST['problemas_saude_outros'])) {
        $problemas_saude[] = 'Outros: ' . trim($_POST['problemas_saude_outros']);
    }
    $problemas_saude_str = implode(', ', $problemas_saude);

    // Processar doenças transmissíveis
    $doencas = [];
    if (!empty($_POST['doencas_lista']) && is_array($_POST['doencas_lista'])) {
        $doencas = array_filter($_POST['doencas_lista']);
    }
    if (!empty($_POST['doencas_outros'])) {
        $doencas[] = 'Outras: ' . trim($_POST['doencas_outros']);
    }
    $doencas_str = implode(', ', $doencas);

    // Processar campos SIM/NÃO
    $tratamento_odonto = (!empty($_POST['tratamento_odonto_sim']) && $_POST['tratamento_odonto_sim'] == '1') ? ($_POST['tratamento_odonto'] ?? '') : '';
    $tratamento_medico = (!empty($_POST['tratamento_medico_sim']) && $_POST['tratamento_medico_sim'] == '1') ? ($_POST['tratamento_medico'] ?? '') : '';
    $medicamento_continuo = (!empty($_POST['medicamento_continuo_sim']) && $_POST['medicamento_continuo_sim'] == '1') ? ($_POST['medicamento_continuo'] ?? '') : '';
    $alergia_medicamento = (!empty($_POST['alergia_medicamento_sim']) && $_POST['alergia_medicamento_sim'] == '1') ? ($_POST['alergia_medicamento'] ?? '') : '';
    $alergia_outras = (!empty($_POST['alergia_outras_sim']) && $_POST['alergia_outras_sim'] == '1') ? ($_POST['alergia_outras'] ?? '') : '';
    $gravida_meses = (!empty($_POST['gravida']) && $_POST['gravida'] == '1') ? ($_POST['gravida_meses'] ?? '') : '';
    $fuma_tempo = (!empty($_POST['fuma']) && $_POST['fuma'] == '1') ? ($_POST['fuma_tempo'] ?? '') : '';
    $fuma_cigarros_dia = (!empty($_POST['fuma']) && $_POST['fuma'] == '1') ? ($_POST['fuma_cigarros_dia'] ?? '') : '';
    $bebida_frequencia = (!empty($_POST['bebida']) && $_POST['bebida'] == '1') ? ($_POST['bebida_frequencia'] ?? '') : '';
    $drogas_uso = (!empty($_POST['drogas_sim']) && $_POST['drogas_sim'] == '1') ? ($_POST['drogas_uso'] ?? '') : '';
    $cancer_familiar = (!empty($_POST['cancer_familiar_sim']) && $_POST['cancer_familiar_sim'] == '1') ? ($_POST['cancer_familiar'] ?? '') : '';
    $tratamento_cancer = (!empty($_POST['tratamento_cancer_sim']) && $_POST['tratamento_cancer_sim'] == '1') ? ($_POST['tratamento_cancer'] ?? '') : '';

    // Normalizar CPF
    $cpf = trim($_POST['cpf'] ?? '');
    $cpf = preg_replace('/\D/', '', $cpf);

    // CPF não informado
    if ($cpf === '') {
        $cpf = null;
    }

    // CPF informado deve ter 11 dígitos
    if ($cpf !== null && strlen($cpf) !== 11) {
        echo json_encode([
            'success' => false,
            'error' => 'CPF inválido. Informe os 11 dígitos do CPF ou deixe o campo vazio.'
        ]);
        exit;
    }

    if (isset($_POST['id']) && !empty($_POST['id'])) {
        // EDITAR

        $id = (int) $_POST['id'];

        if ($id <= 0) {
            throw new Exception("ID do prontuário inválido.");
        }

        // Verifica se o prontuário existe e se está arquivado
        $stmt_verificacao = $pdo->prepare("
        SELECT id, arquivado
        FROM prontuarios
        WHERE id = ?
        LIMIT 1
    ");

        $stmt_verificacao->execute([$id]);

        $prontuario_existente = $stmt_verificacao->fetch(PDO::FETCH_ASSOC);

        if (!$prontuario_existente) {
            throw new Exception("Prontuário não encontrado.");
        }

        if ((int) $prontuario_existente['arquivado'] === 1) {
            throw new Exception("Este prontuário está arquivado e não pode ser editado.");
        }

        $stmt = $pdo->prepare("
        UPDATE prontuarios SET
            paciente = ?, nascimento = ?, sexo = ?, estado_civil = ?, profissao = ?,
            rg = ?, cpf = ?, endereco = ?, cep = ?, telefone = ?, email = ?,
            tratamento_odonto = ?, tratamento_medico = ?, medicamento_continuo = ?,
            alergia_medicamento = ?, alergia_outras = ?, problemas_saude = ?,
            gravida_meses = ?, fuma_tempo = ?, fuma_cigarros_dia = ?,
            bebida_frequencia = ?, drogas_uso = ?, doencas_transmissiveis = ?,
            cancer_familiar = ?, tratamento_cancer = ?, observacoes = ?
        WHERE id = ?
    ");
        $stmt->execute([
            $paciente,
            $nascimento,
            $_POST['sexo'] ?? null,
            $_POST['estado_civil'] ?? null,
            $_POST['profissao'] ?? null,
            $_POST['rg'] ?? null,
            $cpf,
            $_POST['endereco'] ?? null,
            $_POST['cep'] ?? null,
            $_POST['telefone'] ?? null,
            $_POST['email'] ?? null,
            $tratamento_odonto,
            $tratamento_medico,
            $medicamento_continuo,
            $alergia_medicamento,
            $alergia_outras,
            $problemas_saude_str,
            $gravida_meses,
            $fuma_tempo,
            $fuma_cigarros_dia,
            $bebida_frequencia,
            $drogas_uso,
            $doencas_str,
            $cancer_familiar,
            $tratamento_cancer,
            $_POST['observacoes'] ?? '',
            $id
        ]);

        echo json_encode(['success' => true, 'redirect' => 'prontuarios.php']);
    } else {
        // CRIAR NOVO
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO prontuarios (
                paciente, nascimento, sexo, estado_civil, profissao,
                rg, cpf, endereco, cep, telefone, email,
                tratamento_odonto, tratamento_medico, medicamento_continuo,
                alergia_medicamento, alergia_outras, problemas_saude,
                gravida_meses, fuma_tempo, fuma_cigarros_dia,
                bebida_frequencia, drogas_uso, doencas_transmissiveis,
                cancer_familiar, tratamento_cancer, observacoes
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?
            )
        ");
        $stmt->execute([
            $paciente,
            $nascimento,
            $_POST['sexo'] ?? null,
            $_POST['estado_civil'] ?? null,
            $_POST['profissao'] ?? null,
            $_POST['rg'] ?? null,
            $cpf,
            $_POST['endereco'] ?? null,
            $_POST['cep'] ?? null,
            $_POST['telefone'] ?? null,
            $_POST['email'] ?? null,
            $tratamento_odonto,
            $tratamento_medico,
            $medicamento_continuo,
            $alergia_medicamento,
            $alergia_outras,
            $problemas_saude_str,
            $gravida_meses,
            $fuma_tempo,
            $fuma_cigarros_dia,
            $bebida_frequencia,
            $drogas_uso,
            $doencas_str,
            $cancer_familiar,
            $tratamento_cancer,
            $_POST['observacoes'] ?? ''
        ]);

        $novo_id = (int) $pdo->lastInsertId();

        /*
 * Inicializa o odontograma do paciente.
 *
 * 32 dentes permanentes
 * 20 dentes decíduos
 *
 * Todos começam como "nao_avaliado".
 */
        $dentes_permanentes = [
            '11',
            '12',
            '13',
            '14',
            '15',
            '16',
            '17',
            '18',
            '21',
            '22',
            '23',
            '24',
            '25',
            '26',
            '27',
            '28',
            '31',
            '32',
            '33',
            '34',
            '35',
            '36',
            '37',
            '38',
            '41',
            '42',
            '43',
            '44',
            '45',
            '46',
            '47',
            '48'
        ];

        $dentes_deciduos = [
            '51',
            '52',
            '53',
            '54',
            '55',
            '61',
            '62',
            '63',
            '64',
            '65',
            '71',
            '72',
            '73',
            '74',
            '75',
            '81',
            '82',
            '83',
            '84',
            '85'
        ];

        $stmt_odontograma = $pdo->prepare("
    INSERT INTO odontograma_dentes (
        prontuario_id,
        numero_dente,
        denticao,
        estado_geral
    ) VALUES (?, ?, ?, 'nao_avaliado')
");

        foreach ($dentes_permanentes as $numero_dente) {
            $stmt_odontograma->execute([
                $novo_id,
                $numero_dente,
                'permanente'
            ]);
        }

        foreach ($dentes_deciduos as $numero_dente) {
            $stmt_odontograma->execute([
                $novo_id,
                $numero_dente,
                'decidua'
            ]);
        }

        registrarLog(
            $pdo,
            'Criou prontuário',
            'prontuarios',
            $novo_id,
            'Novo paciente cadastrado no prontuário e odontograma inicializado'
        );

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'redirect' => 'termo_conscentimento.php?id=' . $novo_id
        ]);
    }

    exit; // ← Importante: evita saída adicional

} catch (PDOException $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($e->errorInfo[1] == 1062) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => 'Este CPF já está cadastrado para outro paciente.'
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }

    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}
