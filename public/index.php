<?php
// Front Controller do Painel
require_once dirname(__DIR__) . '/app/core/auth.php';

if (isLoggedIn()) {
    // usuário autenticado → carrega painel
    require_once dirname(__DIR__) . '/app/pages/main.php';
} else {
    // não logado → carrega tela de login
    require_once dirname(__DIR__) . '/app/pages/login.php';
}
