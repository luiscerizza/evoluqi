<?php
require_once 'config/auth.php';
exigirLogin();
if (
    empty($_POST['csrf_token']) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Token de segurança inválido.'
    ]);
    exit;
}
require_once 'conexao/conexao.php'; // ← Importante!

header('Content-Type: application/json');

try {
    if (!isset($_POST['id']) || empty($_POST['id'])) {
        throw new Exception("ID não informado.");
    }

    $id = (int)$_POST['id'];
    if ($id <= 0) throw new Exception("ID inválido.");

    $stmt = $pdo->prepare("
    UPDATE prontuarios
    SET
        arquivado = 1,
        arquivado_em = NOW()
    WHERE id = ?
      AND arquivado = 0
");

    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception("Prontuário não encontrado ou já arquivado.");
    }

    echo json_encode([
        'success' => true,
        'message' => 'Prontuário arquivado com sucesso.'
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
