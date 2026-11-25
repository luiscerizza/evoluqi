<?php
$paginaAtual = basename($_SERVER['PHP_SELF']);

function menuAtivo($paginas)
{
    global $paginaAtual;

    if (!is_array($paginas)) {
        $paginas = [$paginas];
    }

    return in_array($paginaAtual, $paginas, true) ? 'active' : '';
}
?>

<aside class="sidebar">

    <div class="sidebar-logo">
        <a href="index.php">
            <img src="img/icon.png" alt="Dentech">
        </a>
    </div>

    <nav class="sidebar-nav">

        <a
            href="index.php"
            class="nav-link <?= menuAtivo('index.php') ?>">
            <i class="fa-solid fa-house"></i>
            <span>Dashboard</span>
        </a>

        <a
            href="prontuarios.php"
            class="nav-link <?= menuAtivo([
                                'prontuarios.php',
                                'visualizar_prontuario.php',
                                'editar_prontuario.php'
                            ]) ?>">
            <i class="fa-regular fa-folder-open"></i>
            <span>Prontuários</span>
        </a>

        <a
            href="agendamentos.php"
            class="nav-link <?= menuAtivo('agendamentos.php') ?>">
            <i class="fa-regular fa-calendar"></i>
            <span>Agendamentos</span>
        </a>

        <a
            href="orcamento.php"
            class="nav-link <?= menuAtivo([
                                'orcamento.php',
                                'novo_orcamento.php',
                                'visualizar_orcamento.php',
                                'editar_orcamento.php'
                            ]) ?>">
            <i class="fa-solid fa-file-invoice-dollar"></i>
            <span>Orçamentos</span>
        </a>

        <a
            href="plano_tratamento.php"
            class="nav-link <?= menuAtivo([
                                'plano_tratamento.php',
                                'novo_plano_tratamento.php',
                                'visualizar_plano_tratamento.php',
                                'editar_plano_tratamento.php'
                            ]) ?>">
            <i class="fa-solid fa-tooth"></i>
            <span>Planos de Tratamento</span>
        </a>

        <a
            href="financeiro.php"
            class="nav-link <?= menuAtivo([
                                'financeiro.php',
                                'novo_lancamento.php',
                                'editar_lancamento.php',
                                'visualizar_lancamento.php'
                            ]) ?>">
            <i class="fa-solid fa-dollar-sign"></i>
            <span>Financeiro</span>
        </a>

        <a
            href="inventario.php"
            class="nav-link <?= menuAtivo('inventario.php') ?>">
            <i class="fa-solid fa-box"></i>
            <span>Estoque</span>
        </a>

        <a
            href="servicos.php"
            class="nav-link <?= menuAtivo([
                                'servicos.php',
                                'novo_servico.php',
                                'editar_servico.php'
                            ]) ?>">
            <i class="fa-solid fa-list-check"></i>
            <span>Serviços</span>
        </a>

        <div class="nav-link">
            <i class="fa-regular fa-circle-question"></i>
            <span>Ajuda</span>
        </div>

    </nav>

</aside>