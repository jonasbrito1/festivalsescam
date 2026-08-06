/* ============================================================================
   Comportamento do menu lateral.

   Dois modos, decididos pela largura da tela:

     >= 1024px  o menu recolhe para um rail de icones e volta a expandir.
                A escolha fica guardada, para nao ter de repetir a cada pagina.

     <  1024px  o menu vira gaveta sobreposta, com fundo escurecido, fechando
                por clique fora, Esc ou ao escolher um item.

   O botao e o mesmo nos dois casos.
   ========================================================================= */
(function () {
    'use strict';

    var LIMITE = 1024;
    var CHAVE = 'festival:menu-recolhido';

    var shell = document.querySelector('.admin-shell, .judge-shell');
    if (!shell) {
        return;
    }

    var sidebar = shell.querySelector('.admin-sidebar');
    var botoes = document.querySelectorAll('[data-toggle-menu]');
    var overlay = shell.querySelector('[data-menu-overlay]');

    if (!sidebar || !botoes.length) {
        return;
    }

    function telaLarga() {
        return window.innerWidth >= LIMITE;
    }

    function marcarBotoes(expandido) {
        botoes.forEach(function (b) {
            b.setAttribute('aria-expanded', expandido ? 'true' : 'false');
        });
    }

    /* ---- Desktop: rail ---- */
    function aplicarRecolhido(recolhido) {
        shell.classList.toggle('is-menu-collapsed', recolhido);
        marcarBotoes(!recolhido);

        try {
            localStorage.setItem(CHAVE, recolhido ? '1' : '0');
        } catch (e) {
            // Navegacao privativa pode bloquear o armazenamento; o menu
            // continua funcionando, so nao lembra da escolha.
        }
    }

    /* ---- Celular: gaveta ---- */
    function abrirGaveta(abrir) {
        shell.classList.toggle('is-menu-open', abrir);
        document.body.classList.toggle('is-menu-open', abrir);
        marcarBotoes(abrir);

        if (overlay) {
            overlay.hidden = !abrir;
        }

        if (abrir) {
            var primeiro = sidebar.querySelector('a, button');
            if (primeiro) {
                primeiro.focus({ preventScroll: true });
            }
        }
    }

    function alternar() {
        if (telaLarga()) {
            aplicarRecolhido(!shell.classList.contains('is-menu-collapsed'));
        } else {
            abrirGaveta(!shell.classList.contains('is-menu-open'));
        }
    }

    botoes.forEach(function (b) {
        b.addEventListener('click', function (e) {
            e.preventDefault();
            alternar();
        });
    });

    if (overlay) {
        overlay.addEventListener('click', function () {
            abrirGaveta(false);
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && shell.classList.contains('is-menu-open')) {
            abrirGaveta(false);
            var alvo = document.querySelector('[data-toggle-menu]');
            if (alvo) {
                alvo.focus();
            }
        }
    });

    // Escolher um item fecha a gaveta: sem isso o menu cobre a pagina recem
    // carregada no celular.
    sidebar.querySelectorAll('.admin-menu a').forEach(function (link) {
        link.addEventListener('click', function () {
            if (!telaLarga()) {
                abrirGaveta(false);
            }
        });
    });

    /* ---- Estado inicial e mudanca de tamanho ---- */
    function sincronizar() {
        if (telaLarga()) {
            // Sair do celular para o desktop nao pode deixar a gaveta "presa".
            shell.classList.remove('is-menu-open');
            document.body.classList.remove('is-menu-open');
            if (overlay) {
                overlay.hidden = true;
            }

            var guardado = '0';
            try {
                guardado = localStorage.getItem(CHAVE) || '0';
            } catch (e) {
                guardado = '0';
            }

            shell.classList.toggle('is-menu-collapsed', guardado === '1');
            marcarBotoes(guardado !== '1');
        } else {
            shell.classList.remove('is-menu-collapsed');
            marcarBotoes(shell.classList.contains('is-menu-open'));
        }
    }

    var aguardando;
    window.addEventListener('resize', function () {
        window.clearTimeout(aguardando);
        aguardando = window.setTimeout(sincronizar, 150);
    });

    sincronizar();
})();
