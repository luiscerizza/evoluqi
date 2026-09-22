<?php

require_once 'config/auth.php';
require_once 'config/csrf.php';

exigirLogin();

require_once 'conexao/conexao.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: orcamento.php');
    exit;
}

validar_csrf();

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    die('Orçamento inválido.');
}

try {

    $stmt = $pdo->prepare("
        UPDATE orcamentos
        SET
            arquivado = 1,
            arquivado_em = NOW()
        WHERE id = ?
          AND arquivado = 0
    ");

    $stmt->execute([$id]);

    header('Location: orcamento.php');
    exit;
} catch (PDOException $e) {

    error_log(
        "ERRO AO ARQUIVAR ORÇAMENTO #{$id}: " .
            $e->getMessage()
    );

    die('Ocorreu um erro ao arquivar o orçamento.');
}
