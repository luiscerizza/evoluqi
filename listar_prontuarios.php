<?php

require_once 'config/auth.php';
exigirLogin();

require_once 'conexao/conexao.php';

$stmt = $pdo->query("
    SELECT *
    FROM prontuarios
    WHERE arquivado = 0
    ORDER BY paciente ASC
");

$prontuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json; charset=utf-8');

echo json_encode($prontuarios);
?>