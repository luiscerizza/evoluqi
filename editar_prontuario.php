<?php
require_once 'config/auth.php';
exigirLogin();
require_once 'conexao/conexao.php';

$prontuario = null;
$is_new = !isset($_GET['id']) || empty($_GET['id']);

if (!$is_new) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM prontuarios WHERE id = ?");
    $stmt->execute([$id]);
    $prontuario = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$prontuario) {
        die("Prontuário não encontrado.");
    }
    if (
        !$is_new &&
        isset($prontuario['arquivado']) &&
        (int)$prontuario['arquivado'] === 1
    ) {
        die("Este prontuário está arquivado e não pode ser editado.");
    }
}

function calcularIdade($dataNascimento)
{
    if (empty($dataNascimento)) return '';
    $nascimento = new DateTime($dataNascimento);
    $hoje = new DateTime();
    $idade = $hoje->diff($nascimento)->y;
    return $idade >= 0 ? "{$idade} anos" : '';
}

$idade_exibicao = '';
if (!$is_new && !empty($prontuario['nascimento'])) {
    $idade_exibicao = calcularIdade($prontuario['nascimento']);
}

?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_new ? 'Novo Prontuário' : 'Editar Prontuário' ?> - Dentech</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/variables.css">
    <link rel="stylesheet" href="css/layout.css">
    <link rel="stylesheet" href="css/edt_prontuario.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="icon" type="image/png" href="img/icon.PNG">
</head>

<body>

    <body>

        <?php include 'navbar.php'; ?>

        <div class="content">

            <div class="container">

                <main>

                    <div class="page-header">

                        <div class="page-header-info">

                            <div class="breadcrumb">
                                <span>Prontuários</span>
                                <span class="breadcrumb-separator">/</span>
                                <span><?= $is_new ? 'Novo prontuário' : 'Editar prontuário' ?></span>
                            </div>

                            <h1>
                                <?= $is_new ? 'Novo Prontuário' : 'Editar Prontuário' ?>
                            </h1>

                            <p>
                                <?= $is_new
                                    ? 'Cadastre os dados e informações de saúde do paciente.'
                                    : 'Consulte e atualize os dados e informações de saúde do paciente.'
                                ?>
                            </p>

                        </div>

                    </div>

                    <form id="prontuarioForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                        <?php if (!$is_new): ?>
                            <input type="hidden" name="id" value="<?= $prontuario['id'] ?>">
                        <?php endif; ?>

                        <!-- DADOS DO PACIENTE -->
                        <div class="section">
                            <h2 class="section-title">Dados do Paciente</h2>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Nome completo</label>
                                    <input type="text" name="paciente" value="<?= htmlspecialchars($is_new ? '' : ($prontuario['paciente'] ?? '')) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Data de nascimento</label>
                                    <input type="date" name="nascimento" value="<?= $is_new ? '' : ($prontuario['nascimento'] ?? '') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Idade</label>
                                    <input type="text" name="idade" value="<?= htmlspecialchars($idade_exibicao) ?>" readonly placeholder="Calculada automaticamente">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Sexo</label>
                                    <select name="sexo">
                                        <option value="">Selecione</option>
                                        <option value="Masculino" <?= (!$is_new && ($prontuario['sexo'] ?? '') == 'Masculino') ? 'selected' : '' ?>>Masculino</option>
                                        <option value="Feminino" <?= (!$is_new && ($prontuario['sexo'] ?? '') == 'Feminino') ? 'selected' : '' ?>>Feminino</option>
                                        <option value="Outro" <?= (!$is_new && ($prontuario['sexo'] ?? '') == 'Outro') ? 'selected' : '' ?>>Outro</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Estado civil</label>
                                    <input type="text" name="estado_civil" value="<?= htmlspecialchars($is_new ? '' : ($prontuario['estado_civil'] ?? '')) ?>" placeholder="Solteiro, Casado, etc.">
                                </div>
                                <div class="form-group">
                                    <label>Profissão</label>
                                    <input type="text" name="profissao" value="<?= htmlspecialchars($is_new ? '' : ($prontuario['profissao'] ?? '')) ?>">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>RG</label>
                                    <input type="text" name="rg" value="<?= htmlspecialchars($is_new ? '' : ($prontuario['rg'] ?? '')) ?>" placeholder="00.000.000-00">
                                </div>
                                <div class="form-group">
                                    <label>CPF</label>
                                    <input type="text" name="cpf" value="<?= htmlspecialchars($is_new ? '' : ($prontuario['cpf'] ?? '')) ?>" placeholder="000.000.000-00">
                                </div>
                                <div class="form-group">
                                    <label>CEP</label>
                                    <input type="text" name="cep" value="<?= htmlspecialchars($is_new ? '' : ($prontuario['cep'] ?? '')) ?>" placeholder="00000-000">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Endereço completo</label>
                                <textarea name="endereco" placeholder="Rua, número, bairro, cidade..."><?= htmlspecialchars($is_new ? '' : ($prontuario['endereco'] ?? '')) ?></textarea>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Telefone</label>
                                    <input type="text" name="telefone" value="<?= htmlspecialchars($is_new ? '' : ($prontuario['telefone'] ?? '')) ?>" placeholder="(00) 00000-0000">
                                </div>
                                <div class="form-group">
                                    <label>E-mail</label>
                                    <input type="email" name="email" value="<?= htmlspecialchars($is_new ? '' : ($prontuario['email'] ?? '')) ?>">
                                </div>
                            </div>
                        </div>

                        <!-- ANAMNESE -->
                        <div class="section">
                            <h2 class="section-title">Anamnese Odontológica</h2>

                            <?php
                            $problemas_saude_atual = $prontuario['problemas_saude'] ?? '';
                            $doencas_atual = $prontuario['doencas_transmissiveis'] ?? '';
                            ?>

                            <!-- Pergunta 1 -->
                            <div class="form-group">
                                <label>Já realizou algum tratamento odontológico?</label>
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <input type="radio" name="tratamento_odonto_sim" value="1" id="odonto_sim" <?= (!$is_new && !empty($prontuario['tratamento_odonto'])) ? 'checked' : '' ?>>
                                        <label for="odonto_sim">Sim</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="radio" name="tratamento_odonto_sim" value="0" id="odonto_nao" <?= (!$is_new && empty($prontuario['tratamento_odonto'])) ? 'checked' : '' ?>>
                                        <label for="odonto_nao">Não</label>
                                    </div>
                                </div>
                                <div class="condicional">
                                    <input type="text" name="tratamento_odonto" placeholder="Qual?" value="<?= htmlspecialchars($is_new ? '' : ($prontuario['tratamento_odonto'] ?? '')) ?>">
                                </div>
                            </div>

                            <!-- Pergunta 2 -->
                            <div class="form-group">
                                <label>Está realizando algum tratamento médico?</label>
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <input type="radio" name="tratamento_medico_sim" value="1" id="medico_sim" <?= (!$is_new && !empty($prontuario['tratamento_medico'])) ? 'checked' : '' ?>>
                                        <label for="medico_sim">Sim</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="radio" name="tratamento_medico_sim" value="0" id="medico_nao" <?= (!$is_new && empty($prontuario['tratamento_medico'])) ? 'checked' : '' ?>>
                                        <label for="medico_nao">Não</label>
                                    </div>
                                </div>
                                <div class="condicional">
                                    <input type="text" name="tratamento_medico" placeholder="Qual?" value="<?= htmlspecialchars($is_new ? '' : ($prontuario['tratamento_medico'] ?? '')) ?>">
                                </div>
                            </div>

                            <!-- Pergunta 3 -->
                            <div class="form-group">
                                <label>Faz uso de algum medicamento contínuo?</label>
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <input type="radio" name="medicamento_continuo_sim" value="1" id="med_cont_sim" <?= (!$is_new && !empty($prontuario['medicamento_continuo'])) ? 'checked' : '' ?>>
                                        <label for="med_cont_sim">Sim</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="radio" name="medicamento_continuo_sim" value="0" id="med_cont_nao" <?= (!$is_new && empty($prontuario['medicamento_continuo'])) ? 'checked' : '' ?>>
                                        <label for="med_cont_nao">Não</label>
                                    </div>
                                </div>
                                <div class="condicional">
                                    <input type="text" name="medicamento_continuo" placeholder="Qual?" value="<?= htmlspecialchars($is_new ? '' : ($prontuario['medicamento_continuo'] ?? '')) ?>">
                                </div>
                            </div>

                            <!-- Pergunta 4 -->
                            <div class="form-group">
                                <label>Possui alergia a medicamentos?</label>
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <input type="radio" name="alergia_medicamento_sim" value="1" id="alerg_med_sim" <?= (!$is_new && !empty($prontuario['alergia_medicamento'])) ? 'checked' : '' ?>>
                                        <label for="alerg_med_sim">Sim</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="radio" name="alergia_medicamento_sim" value="0" id="alerg_med_nao" <?= (!$is_new && empty($prontuario['alergia_medicamento'])) ? 'checked' : '' ?>>
                                        <label for="alerg_med_nao">Não</label>
                                    </div>
                                </div>
                                <div class="condicional">
                                    <input type="text" name="alergia_medicamento" placeholder="Qual?" value="<?= htmlspecialchars($is_new ? '' : ($prontuario['alergia_medicamento'] ?? '')) ?>">
                                </div>
                            </div>

                            <!-- Pergunta 5 -->
                            <div class="form-group">
                                <label>Possui outras alergias (alimentos, etc.)?</label>
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <input type="radio" name="alergia_outras_sim" value="1" id="alerg_outras_sim" <?= (!$is_new && !empty($prontuario['alergia_outras'])) ? 'checked' : '' ?>>
                                        <label for="alerg_outras_sim">Sim</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="radio" name="alergia_outras_sim" value="0" id="alerg_outras_nao" <?= (!$is_new && empty($prontuario['alergia_outras'])) ? 'checked' : '' ?>>
                                        <label for="alerg_outras_nao">Não</label>
                                    </div>
                                </div>
                                <div class="condicional">
                                    <input type="text" name="alergia_outras" placeholder="Qual?" value="<?= htmlspecialchars($is_new ? '' : ($prontuario['alergia_outras'] ?? '')) ?>">
                                </div>
                            </div>

                            <!-- Pergunta 6: Problemas de saúde -->
                            <div class="form-group">
                                <label>Possui algum problema de saúde?</label>
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="problemas_saude[]" value="Diabetes" <?= (!$is_new && $problemas_saude_atual && strpos($problemas_saude_atual, 'Diabetes') !== false) ? 'checked' : '' ?>>
                                        <label>Diabetes</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="problemas_saude[]" value="Hipertensão" <?= (!$is_new && $problemas_saude_atual && strpos($problemas_saude_atual, 'Hipertensão') !== false) ? 'checked' : '' ?>>
                                        <label>Hipertensão</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="problemas_saude[]" value="Problemas Cardíacos" <?= (!$is_new && $problemas_saude_atual && strpos($problemas_saude_atual, 'Problemas Cardíacos') !== false) ? 'checked' : '' ?>>
                                        <label>Problemas Cardíacos</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="problemas_saude[]" value="Distúrbios Sanguíneos" <?= (!$is_new && $problemas_saude_atual && strpos($problemas_saude_atual, 'Distúrbios Sanguíneos') !== false) ? 'checked' : '' ?>>
                                        <label>Distúrbios Sanguíneos</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="problemas_saude[]" value="Distúrbio Renal" <?= (!$is_new && $problemas_saude_atual && strpos($problemas_saude_atual, 'Distúrbio Renal') !== false) ? 'checked' : '' ?>>
                                        <label>Distúrbio Renal</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="problemas_saude[]" value="Problemas Respiratórios" <?= (!$is_new && $problemas_saude_atual && strpos($problemas_saude_atual, 'Problemas Respiratórios') !== false) ? 'checked' : '' ?>>
                                        <label>Problemas Respiratórios</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="problemas_saude[]" value="Problemas Gastrointestinais" <?= (!$is_new && $problemas_saude_atual && strpos($problemas_saude_atual, 'Problemas Gastrointestinais') !== false) ? 'checked' : '' ?>>
                                        <label>Problemas Gastrointestinais</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="problemas_saude[]" value="Hepatite" <?= (!$is_new && $problemas_saude_atual && strpos($problemas_saude_atual, 'Hepatite') !== false) ? 'checked' : '' ?>>
                                        <label>Hepatite</label>
                                    </div>
                                </div>
                                <div class="condicional">
                                    <input type="text" name="problemas_saude_outros" placeholder="Especifique outros problemas"
                                        value="<?= htmlspecialchars($is_new ? '' : (strpos($problemas_saude_atual, 'Outros:') !== false ? trim(str_replace('Outros:', '', $problemas_saude_atual)) : '')) ?>">
                                </div>
                            </div>

                            <!-- Gravidez -->
                            <div class="form-group">
                                <label>Está grávida?</label>
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <input type="radio" name="gravida" value="1" id="grav_sim" <?= (!$is_new && !empty($prontuario['gravida_meses'])) ? 'checked' : '' ?>>
                                        <label for="grav_sim">Sim</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="radio" name="gravida" value="0" id="grav_nao" <?= (!$is_new && empty($prontuario['gravida_meses'])) ? 'checked' : '' ?>>
                                        <label for="grav_nao">Não</label>
                                    </div>
                                </div>
                                <div class="condicional">
                                    <input type="text" name="gravida_meses" placeholder="Quantos meses?" value="<?= htmlspecialchars($is_new ? '' : ($prontuario['gravida_meses'] ?? '')) ?>">
                                </div>
                            </div>

                            <!-- Fumo -->
                            <div class="form-group">
                                <label>Fuma?</label>
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <input type="radio" name="fuma" value="1" id="fuma_sim" <?= (!$is_new && (!empty($prontuario['fuma_tempo']) || !empty($prontuario['fuma_cigarros_dia']))) ? 'checked' : '' ?>>
                                        <label for="fuma_sim">Sim</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="radio" name="fuma" value="0" id="fuma_nao" <?= (!$is_new && empty($prontuario['fuma_tempo']) && empty($prontuario['fuma_cigarros_dia'])) ? 'checked' : '' ?>>
                                        <label for="fuma_nao">Não</label>
                                    </div>
                                </div>
                                <div class="condicional">
                                    <div class="form-row">
                                        <div class="form-group">
                                            <input type="text" name="fuma_tempo" placeholder="A quanto tempo?" value="<?= htmlspecialchars($is_new ? '' : ($prontuario['fuma_tempo'] ?? '')) ?>">
                                        </div>
                                        <div class="form-group">
                                            <input type="text" name="fuma_cigarros_dia" placeholder="Cigarros por dia" value="<?= htmlspecialchars($is_new ? '' : ($prontuario['fuma_cigarros_dia'] ?? '')) ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Bebida alcoólica -->
                            <div class="form-group">
                                <label>Faz uso de bebida alcoólica?</label>
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <input type="radio" name="bebida" value="1" id="bebida_sim" <?= (!$is_new && !empty($prontuario['bebida_frequencia'])) ? 'checked' : '' ?>>
                                        <label for="bebida_sim">Sim</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="radio" name="bebida" value="0" id="bebida_nao" <?= (!$is_new && empty($prontuario['bebida_frequencia'])) ? 'checked' : '' ?>>
                                        <label for="bebida_nao">Não</label>
                                    </div>
                                </div>
                                <div class="condicional">
                                    <input type="text" name="bebida_frequencia" placeholder="Qual a frequência?" value="<?= htmlspecialchars($is_new ? '' : ($prontuario['bebida_frequencia'] ?? '')) ?>">
                                </div>
                            </div>

                            <!-- Drogas -->
                            <div class="form-group">
                                <label>Usa ou já usou drogas?</label>
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <input type="radio" name="drogas_sim" value="1" id="drogas_sim" <?= (!$is_new && !empty($prontuario['drogas_uso'])) ? 'checked' : '' ?>>
                                        <label for="drogas_sim">Sim</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="radio" name="drogas_sim" value="0" id="drogas_nao" <?= (!$is_new && empty($prontuario['drogas_uso'])) ? 'checked' : '' ?>>
                                        <label for="drogas_nao">Não</label>
                                    </div>
                                </div>
                                <div class="condicional">
                                    <input type="text" name="drogas_uso" placeholder="Qual?" value="<?= htmlspecialchars($is_new ? '' : ($prontuario['drogas_uso'] ?? '')) ?>">
                                </div>
                            </div>

                            <!-- Doenças transmissíveis -->
                            <div class="form-group">
                                <label>Já teve doenças transmissíveis?</label>
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <input type="radio" name="doencas_trans_sim" value="1" id="doencas_sim" <?= (!$is_new && !empty($doencas_atual)) ? 'checked' : '' ?>>
                                        <label for="doencas_sim">Sim</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="radio" name="doencas_trans_sim" value="0" id="doencas_nao" <?= (!$is_new && empty($doencas_atual)) ? 'checked' : '' ?>>
                                        <label for="doencas_nao">Não</label>
                                    </div>
                                </div>
                                <div class="condicional">
                                    <div class="checkbox-group" style="margin-top:8px;">
                                        <div class="checkbox-item">
                                            <input type="checkbox" name="doencas_lista[]" value="HIV" <?= (!$is_new && $doencas_atual && strpos($doencas_atual, 'HIV') !== false) ? 'checked' : '' ?>>
                                            <label>HIV</label>
                                        </div>
                                        <div class="checkbox-item">
                                            <input type="checkbox" name="doencas_lista[]" value="Sífilis" <?= (!$is_new && $doencas_atual && strpos($doencas_atual, 'Sífilis') !== false) ? 'checked' : '' ?>>
                                            <label>Sífilis</label>
                                        </div>
                                        <div class="checkbox-item">
                                            <input type="checkbox" name="doencas_lista[]" value="Gonorreia" <?= (!$is_new && $doencas_atual && strpos($doencas_atual, 'Gonorreia') !== false) ? 'checked' : '' ?>>
                                            <label>Gonorreia</label>
                                        </div>
                                        <div class="checkbox-item">
                                            <input type="checkbox" name="doencas_lista[]" value="HPV" <?= (!$is_new && $doencas_atual && strpos($doencas_atual, 'HPV') !== false) ? 'checked' : '' ?>>
                                            <label>HPV</label>
                                        </div>
                                    </div>
                                    <div class="condicional">
                                        <input type="text" name="doencas_outros" placeholder="Especifique"
                                            value="<?= htmlspecialchars($is_new ? '' : (strpos($doencas_atual, 'Outras:') !== false ? trim(str_replace('Outras:', '', $doencas_atual)) : '')) ?>">
                                    </div>
                                </div>
                            </div>

                            <!-- Câncer familiar -->
                            <div class="form-group">
                                <label>Você ou alguém da família já teve câncer?</label>
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <input type="radio" name="cancer_familiar_sim" value="1" id="cancer_sim" <?= (!$is_new && !empty($prontuario['cancer_familiar'])) ? 'checked' : '' ?>>
                                        <label for="cancer_sim">Sim</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="radio" name="cancer_familiar_sim" value="0" id="cancer_nao" <?= (!$is_new && empty($prontuario['cancer_familiar'])) ? 'checked' : '' ?>>
                                        <label for="cancer_nao">Não</label>
                                    </div>
                                </div>
                                <div class="condicional">
                                    <input type="text" name="cancer_familiar" placeholder="Quem?" value="<?= htmlspecialchars($is_new ? '' : ($prontuario['cancer_familiar'] ?? '')) ?>">
                                </div>
                            </div>

                            <!-- Tratamento de câncer -->
                            <div class="form-group">
                                <label>Realizou tratamento de câncer?</label>
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <input type="radio" name="tratamento_cancer_sim" value="1" id="trat_cancer_sim" <?= (!$is_new && !empty($prontuario['tratamento_cancer'])) ? 'checked' : '' ?>>
                                        <label for="trat_cancer_sim">Sim</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="radio" name="tratamento_cancer_sim" value="0" id="trat_cancer_nao" <?= (!$is_new && empty($prontuario['tratamento_cancer'])) ? 'checked' : '' ?>>
                                        <label for="trat_cancer_nao">Não</label>
                                    </div>
                                </div>
                                <div class="condicional">
                                    <input type="text" name="tratamento_cancer" placeholder="Qual? (Radioterapia, Quimioterapia, etc.)" value="<?= htmlspecialchars($is_new ? '' : ($prontuario['tratamento_cancer'] ?? '')) ?>">
                                </div>
                            </div>
                        </div>

                        <!-- OBSERVAÇÕES GERAIS -->
                        <div class="section">
                            <h2 class="section-title">Observações</h2>
                            <div class="form-group">
                                <label>Observações adicionais</label>
                                <textarea name="observacoes" rows="4" placeholder="Informações complementares sobre o paciente..."><?= htmlspecialchars($is_new ? '' : ($prontuario['observacoes'] ?? '')) ?></textarea>
                            </div>
                        </div>

                        <!-- Ações -->
                        <div class="actions">
                            <button type="button" class="btn cancel" onclick="window.location='visualizar_prontuario.php?id=<?= $prontuario['id'] ?? '' ?>'">Cancelar</button>
                            <?php if (!$is_new): ?>
                                <button type="button" class="btn delete" onclick="deleteProntuario()">Excluir</button>
                            <?php endif; ?>
                            <button type="submit" class="btn save"><?= $is_new ? 'Criar Prontuário' : 'Salvar Alterações' ?></button>
                        </div>
                    </form>
                </main>
            </div>
        </div>
    </body>

    <script>
        document.addEventListener('DOMContentLoaded', () => {

            // ========================================
            // IDADE AUTOMÁTICA
            // ========================================

            const nascimentoInput = document.querySelector('input[name="nascimento"]');
            const idadeInput = document.querySelector('input[name="idade"]');

            if (nascimentoInput && idadeInput) {

                nascimentoInput.addEventListener('input', function() {

                    if (!this.value) {
                        idadeInput.value = '';
                        return;
                    }

                    // Evita problemas de fuso horário
                    const [y, m, d] = this.value.split('-').map(Number);

                    const birthDate = new Date(y, m - 1, d);
                    const today = new Date();

                    let age =
                        today.getFullYear() -
                        birthDate.getFullYear();

                    const monthDiff =
                        today.getMonth() -
                        birthDate.getMonth();

                    if (
                        monthDiff < 0 ||
                        (
                            monthDiff === 0 &&
                            today.getDate() < birthDate.getDate()
                        )
                    ) {
                        age--;
                    }

                    idadeInput.value =
                        age >= 0 ? `${age} anos` : '';
                });
            }


            // ========================================
            // CAMPOS CONDICIONAIS
            // ========================================

            function configurarCampoCondicional(nomeRadio, valorSim) {

                const radios =
                    document.querySelectorAll(
                        `input[name="${nomeRadio}"]`
                    );

                radios.forEach(radio => {

                    radio.addEventListener('change', () => {

                        const grupo =
                            radio.closest('.form-group');

                        const condicional =
                            grupo?.querySelector('.condicional');

                        if (!condicional) {
                            return;
                        }

                        condicional.style.display =
                            radio.checked &&
                            radio.value === valorSim ?
                            'block' :
                            'none';
                    });
                });
            }


            configurarCampoCondicional(
                'tratamento_odonto_sim',
                '1'
            );

            configurarCampoCondicional(
                'tratamento_medico_sim',
                '1'
            );

            configurarCampoCondicional(
                'medicamento_continuo_sim',
                '1'
            );

            configurarCampoCondicional(
                'alergia_medicamento_sim',
                '1'
            );

            configurarCampoCondicional(
                'alergia_outras_sim',
                '1'
            );

            configurarCampoCondicional(
                'gravida',
                '1'
            );

            configurarCampoCondicional(
                'fuma',
                '1'
            );

            configurarCampoCondicional(
                'bebida',
                '1'
            );

            configurarCampoCondicional(
                'drogas_sim',
                '1'
            );

            configurarCampoCondicional(
                'doencas_trans_sim',
                '1'
            );

            configurarCampoCondicional(
                'cancer_familiar_sim',
                '1'
            );

            configurarCampoCondicional(
                'tratamento_cancer_sim',
                '1'
            );


            // ========================================
            // MOSTRAR CAMPOS CONDICIONAIS
            // JÁ MARCADOS AO ABRIR A PÁGINA
            // ========================================

            document
                .querySelectorAll('input[type="radio"]:checked')
                .forEach(radio => {

                    radio.dispatchEvent(
                        new Event('change')
                    );
                });


            // ========================================
            // MÁSCARAS
            // ========================================

            function aplicarMascara(input, mascara) {

                input.addEventListener('input', function() {

                    let valor =
                        this.value.replace(/\D/g, '');


                    // ====================================
                    // CPF
                    // ====================================

                    if (mascara === 'cpf') {

                        valor =
                            valor.substring(0, 11);

                        if (valor.length > 9) {

                            valor = valor.replace(
                                /^(\d{3})(\d{3})(\d{3})(\d{0,2}).*/,
                                '$1.$2.$3-$4'
                            );

                        } else if (valor.length > 6) {

                            valor = valor.replace(
                                /^(\d{3})(\d{3})(\d{0,3}).*/,
                                '$1.$2.$3'
                            );

                        } else if (valor.length > 3) {

                            valor = valor.replace(
                                /^(\d{3})(\d{0,3}).*/,
                                '$1.$2'
                            );
                        }
                    }


                    // ====================================
                    // CEP
                    // ====================================
                    else if (mascara === 'cep') {

                        valor =
                            valor.substring(0, 8);

                        if (valor.length > 5) {

                            valor = valor.replace(
                                /^(\d{5})(\d{0,3}).*/,
                                '$1-$2'
                            );
                        }
                    }


                    // ====================================
                    // TELEFONE
                    // ====================================
                    else if (mascara === 'telefone') {

                        valor =
                            valor.substring(0, 11);

                        if (valor.length > 10) {

                            valor = valor.replace(
                                /^(\d{2})(\d{5})(\d{0,4}).*/,
                                '($1) $2-$3'
                            );

                        } else if (valor.length > 6) {

                            valor = valor.replace(
                                /^(\d{2})(\d{4})(\d{0,4}).*/,
                                '($1) $2-$3'
                            );

                        } else if (valor.length > 2) {

                            valor = valor.replace(
                                /^(\d{2})(\d{0,5}).*/,
                                '($1) $2'
                            );
                        }
                    }


                    this.value = valor;
                });
            }


            // ========================================
            // ATIVAR MÁSCARAS
            // ========================================

            const cpfInput =
                document.querySelector(
                    'input[name="cpf"]'
                );

            const cepInput =
                document.querySelector(
                    'input[name="cep"]'
                );

            const telefoneInput =
                document.querySelector(
                    'input[name="telefone"]'
                );


            if (cpfInput) {
                aplicarMascara(
                    cpfInput,
                    'cpf'
                );
            }

            if (cepInput) {
                aplicarMascara(
                    cepInput,
                    'cep'
                );
            }

            if (telefoneInput) {
                aplicarMascara(
                    telefoneInput,
                    'telefone'
                );
            }


            // ========================================
            // VALIDAÇÃO DO CPF
            // ========================================

            function cpfValido(cpf) {

                cpf =
                    cpf.replace(/\D/g, '');

                // Precisa ter 11 dígitos
                if (cpf.length !== 11) {
                    return false;
                }

                // Rejeita CPFs com todos os números iguais
                if (/^(\d)\1{10}$/.test(cpf)) {
                    return false;
                }


                // ====================================
                // PRIMEIRO DÍGITO
                // ====================================

                let soma = 0;
                let resto;

                for (let i = 1; i <= 9; i++) {

                    soma +=
                        parseInt(
                            cpf.charAt(i - 1)
                        ) * (11 - i);
                }

                resto =
                    (soma * 10) % 11;

                if (resto === 10) {
                    resto = 0;
                }

                if (
                    resto !==
                    parseInt(cpf.charAt(9))
                ) {
                    return false;
                }


                // ====================================
                // SEGUNDO DÍGITO
                // ====================================

                soma = 0;

                for (let i = 1; i <= 10; i++) {

                    soma +=
                        parseInt(
                            cpf.charAt(i - 1)
                        ) * (12 - i);
                }

                resto =
                    (soma * 10) % 11;

                if (resto === 10) {
                    resto = 0;
                }

                return (
                    resto ===
                    parseInt(cpf.charAt(10))
                );
            }


            // ========================================
            // VALIDAÇÃO DA DATA DE NASCIMENTO
            // ========================================

            function dataNascimentoValida(data) {

                if (!data) {
                    return false;
                }

                const dataNascimento =
                    new Date(
                        data + 'T00:00:00'
                    );

                const hoje = new Date();

                hoje.setHours(
                    0,
                    0,
                    0,
                    0
                );

                return dataNascimento <= hoje;
            }


            // ========================================
            // SALVAR PRONTUÁRIO COM AJAX
            // ========================================

            const prontuarioForm =
                document.getElementById(
                    'prontuarioForm'
                );


            if (prontuarioForm) {

                prontuarioForm.addEventListener(
                    'submit',
                    async function(e) {

                        e.preventDefault();


                        // ====================================
                        // CAMPOS
                        // ====================================

                        const cpf =
                            document.querySelector(
                                'input[name="cpf"]'
                            );

                        const nascimento =
                            document.querySelector(
                                'input[name="nascimento"]'
                            );

                        const email =
                            document.querySelector(
                                'input[name="email"]'
                            );


                        // ====================================
                        // VALIDAR CPF
                        // ====================================

                        if (
                            cpf &&
                            cpf.value.trim() !== ''
                        ) {

                            if (
                                !cpfValido(
                                    cpf.value
                                )
                            ) {

                                alert(
                                    'CPF inválido. Verifique o número informado.'
                                );

                                cpf.focus();

                                return;
                            }
                        }


                        // ====================================
                        // VALIDAR DATA DE NASCIMENTO
                        // ====================================

                        if (
                            nascimento &&
                            !dataNascimentoValida(
                                nascimento.value
                            )
                        ) {

                            alert(
                                'A data de nascimento é inválida.'
                            );

                            nascimento.focus();

                            return;
                        }


                        // ====================================
                        // VALIDAR E-MAIL
                        // ====================================

                        if (
                            email &&
                            email.value.trim() !== ''
                        ) {

                            if (
                                !email.checkValidity()
                            ) {

                                alert(
                                    'Informe um e-mail válido.'
                                );

                                email.focus();

                                return;
                            }
                        }


                        // ====================================
                        // PREPARAR DADOS
                        // ====================================

                        const formData =
                            new FormData(this);


                        // CSRF
                        formData.append(
                            'csrf_token',
                            '<?= $_SESSION['csrf_token'] ?>'
                        );


                        // ====================================
                        // ENVIAR PARA PHP
                        // ====================================

                        try {

                            const response =
                                await fetch(
                                    'salvar_prontuario.php', {
                                        method: 'POST',
                                        body: formData
                                    }
                                );


                            // Pega a resposta como texto
                            const texto =
                                await response.text();


                            // Mostra no console
                            // para facilitar diagnóstico
                            console.log(
                                'Resposta do servidor:',
                                texto
                            );


                            // ====================================
                            // CONVERTER JSON
                            // ====================================

                            let result;

                            try {

                                result =
                                    JSON.parse(texto);

                            } catch (jsonError) {

                                console.error(
                                    'Resposta não é um JSON válido:',
                                    texto
                                );

                                alert(
                                    'O servidor retornou uma resposta inválida. Verifique o console.'
                                );

                                return;
                            }


                            // ====================================
                            // SUCESSO
                            // ====================================

                            if (result.success) {

                                if (result.redirect) {

                                    window.location.href =
                                        result.redirect;

                                } else {

                                    alert(
                                        'Prontuário salvo com sucesso!'
                                    );

                                    window.location.href =
                                        'prontuarios.php';
                                }

                            }


                            // ====================================
                            // ERRO DO PHP
                            // ====================================
                            else {

                                alert(
                                    'Erro ao salvar:\n' +
                                    (
                                        result.error ||
                                        'Erro desconhecido.'
                                    )
                                );
                            }


                        } catch (err) {

                            console.error(
                                'Erro na requisição:',
                                err
                            );

                            alert(
                                'Erro de conexão. Tente novamente.'
                            );
                        }
                    }
                );
            }


            // ========================================
            // FIM DO DOMCONTENTLOADED
            // ========================================

        });


        // ============================================
        // EXCLUIR PRONTUÁRIO
        // ============================================
        // Esta função fica fora do DOMContentLoaded
        // porque pode ser chamada pelo onclick do HTML.
        // ============================================

        function deleteProntuario() {

            if (
                !confirm(
                    "⚠️ ATENÇÃO:\n" +
                    "Excluir este prontuário removerá " +
                    "agendamentos, procedimentos e " +
                    "orçamentos vinculados.\n\n" +
                    "Deseja continuar?"
                )
            ) {
                return;
            }


            const id =
                document.querySelector(
                    'input[name="id"]'
                )?.value;


            if (!id) {
                return;
            }


            fetch(
                    'excluir_prontuario.php', {
                        method: 'POST',

                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },

                        body: 'id=' +
                            encodeURIComponent(id) +
                            '&csrf_token=' +
                            encodeURIComponent(
                                '<?= $_SESSION['csrf_token'] ?>'
                            )
                    }
                )

                .then(response => response.json())

                .then(data => {

                    if (data.success) {

                        alert(
                            'Prontuário excluído com sucesso!'
                        );

                        window.location.href =
                            'prontuarios';

                    } else {

                        alert(
                            'Erro: ' +
                            data.error
                        );
                    }
                })

                .catch(err => {

                    console.error(err);

                    alert(
                        'Erro de conexão.'
                    );
                });
        }
    </script>
</body>

</html>