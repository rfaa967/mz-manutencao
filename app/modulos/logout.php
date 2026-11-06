<?php
// Logout só por POST (com CSRF), para que um link/imagem externo não derrube a sessão.
if (!is_post()) redirect(usuario() && pode('ver') ? 'dashboard' : 'm');
logout();
sessao_iniciar();
flash('Sessão encerrada.', 'info');
redirect('login');
