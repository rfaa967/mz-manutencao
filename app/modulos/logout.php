<?php
logout();
sessao_iniciar();
flash('Sessão encerrada.', 'info');
redirect('login');
