/* ============================================================================
   Comportamento do menu lateral.

   Dois modos, decididos pela largura da tela:

     >= 1024px  o menu recolhe para um rail de icones e volta a expandir.
                A escolha fica guardada, para nao ter de repetir a cada pagina.

     <  1024px  o menu vira gaveta sobreposta, com fundo escurecido, fechando
                por clique fora, Esc ou ao escolher um item.

   O botao e o mesmo nos dois casos.
   ========================================================================= */

/* ============================================================================
   Menu da conta no cabecalho.

   Fecha por clique fora, Esc e ao perder o foco por Tab. Fica separado do
   bloco do menu lateral de proposito: aquele desiste cedo quando nao acha a
   barra, e o menu da conta nao pode depender disso.
   ========================================================================= */
(function () {
    'use strict';

    var caixa = document.querySelector('[data-perfil]');
    if (!caixa) {
        return;
    }

    var botao = caixa.querySelector('[data-perfil-botao]');
    var gaveta = caixa.querySelector('.perfil-menu');

    if (!botao || !gaveta) {
        return;
    }

    function abrir(sim) {
        gaveta.hidden = !sim;
        botao.setAttribute('aria-expanded', sim ? 'true' : 'false');
    }

    botao.addEventListener('click', function (e) {
        e.preventDefault();
        abrir(gaveta.hidden);
    });

    document.addEventListener('click', function (e) {
        if (!gaveta.hidden && !caixa.contains(e.target)) {
            abrir(false);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !gaveta.hidden) {
            abrir(false);
            botao.focus();
        }
    });

    // Sair da area por Tab tambem fecha: deixar a gaveta aberta atras do
    // conteudo confunde quem navega pelo teclado.
    caixa.addEventListener('focusout', function (e) {
        if (!gaveta.hidden && !caixa.contains(e.relatedTarget)) {
            abrir(false);
        }
    });
})();

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


/* ============================================================================
   Planilha SER SESC.

   Duas coisas acontecem aqui:

     1. Cada celula grava sozinha ao sair dela. Uma requisicao por celula, com
        a propria linha e coluna — nao um "salvar tudo" que reescreveria o que
        outra pessoa acabou de lancar.

     2. A pagina pergunta ao servidor, de poucos em poucos segundos, se algo
        mudou. Se mudou, so as celulas afetadas sao reescritas.

   A tela NAO recarrega sozinha, ao contrario do placar. Recarregar no meio de
   uma digitacao apaga o que estava sendo digitado e leva o cursor embora.
   ========================================================================= */
(function () {
    'use strict';

    var painel = document.querySelector('[data-ser-planilha]');
    if (!painel) {
        return;
    }

    var estado = document.querySelector('[data-ser-estado]');
    var relogio = document.querySelector('[data-ser-relogio]');
    var revisao = painel.dataset.revisao || '';
    var intervalo = (parseInt(painel.dataset.intervalo, 10) || 5) * 1000;
    var pendentes = 0;

    function csrf() {
        var campo = document.querySelector('input[name="_csrf"]');
        return campo ? campo.value : '';
    }

    function aviso(texto, tipo) {
        if (!estado) {
            return;
        }
        estado.textContent = texto;
        estado.className = 'ser-aviso ' + (tipo || '');
    }

    /* ---- Normalizacao do que foi digitado ---- */
    function limpar(bruto) {
        return String(bruto).trim().replace(',', '.');
    }

    function valido(texto) {
        if (texto === '') {
            return true;   // celula esvaziada e "sem nota", nao erro
        }
        var n = Number(texto);
        return !isNaN(n) && n >= 0 && n <= 10;
    }

    /* ---- Gravacao de uma celula ---- */
    function gravar(campo) {
        var texto = limpar(campo.value);

        if (!valido(texto)) {
            campo.classList.add('erro');
            aviso('Nota inválida: use um número de 0 a 10.', 'erro');
            campo.focus();
            return;
        }

        if (texto === (campo.dataset.ultimo || '')) {
            return;   // saiu do campo sem mudar nada
        }

        campo.classList.remove('erro');
        campo.classList.add('salvando');
        pendentes += 1;
        aviso('Salvando…', '');

        var corpo = new URLSearchParams();
        corpo.append('_csrf', csrf());
        corpo.append('action', 'ser_salvar_nota');
        corpo.append('alvo', campo.dataset.alvo);
        corpo.append('id', campo.dataset.id);
        corpo.append('campo', campo.dataset.campo);
        corpo.append('nota', texto);

        fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: corpo.toString()
        }).then(function (r) {
            return r.json().then(function (dados) {
                return { ok: r.ok, dados: dados };
            });
        }).then(function (res) {
            campo.classList.remove('salvando');

            if (!res.ok || !res.dados.ok) {
                campo.classList.add('erro');
                aviso(res.dados.mensagem || 'Falha ao salvar.', 'erro');
                return;
            }

            campo.dataset.ultimo = texto;
            campo.classList.add('salvo');
            window.setTimeout(function () { campo.classList.remove('salvo'); }, 1200);

            revisao = res.dados.revisao || revisao;
            aplicarTotais(res.dados.blocos);
            marcarHora();
            aviso('Salvo.', 'ok');
        }).catch(function () {
            campo.classList.remove('salvando');
            campo.classList.add('erro');
            /* O texto digitado continua na tela: apagar aqui faria a pessoa
               perder a nota por causa de uma falha de rede. */
            aviso('Sem conexão com o servidor. A nota NÃO foi salva — tente de novo.', 'erro');
        }).then(function () {
            pendentes -= 1;
        });
    }

    /* ---- Totais ---- */
    function formatar(n) {
        var texto = Number(n).toFixed(2);
        return texto.replace(/\.?0+$/, '').replace('.', ',') || '0';
    }

    function aplicarTotais(blocos) {
        if (!blocos) {
            return;
        }

        Object.keys(blocos).forEach(function (id) {
            var bloco = blocos[id];
            var selo = document.querySelector('[data-ser-total-bloco="' + id + '"]');

            if (selo) {
                var maximo = selo.textContent.split(' de ')[1] || '';
                selo.textContent = formatar(bloco.total_geral) + ' de ' + maximo.trim();
                selo.classList.toggle('ativo', bloco.total_geral > 0);
                selo.classList.toggle('pendente', bloco.total_geral <= 0);
            }

            Object.keys(bloco.turmas || {}).forEach(function (turmaId) {
                var celula = document.querySelector('[data-ser-total-turma="' + turmaId + '"]');
                if (celula) {
                    celula.textContent = formatar(bloco.turmas[turmaId]);
                }
            });
        });
    }

    function marcarHora() {
        if (!relogio) {
            return;
        }
        var agora = new Date();
        relogio.textContent = agora.toLocaleDateString('pt-BR') + ' ' +
            String(agora.getHours()).padStart(2, '0') + ':' +
            String(agora.getMinutes()).padStart(2, '0');
    }

    /* ---- Ligacao dos campos ---- */
    document.querySelectorAll('.ser-nota').forEach(function (campo) {
        campo.dataset.ultimo = limpar(campo.value);

        /* So 'change'. Ele ja dispara ao sair do campo com valor alterado —
           somar um 'blur' faria duas requisicoes para a mesma nota, porque a
           segunda parte antes de a primeira ter registrado o valor novo. */
        campo.addEventListener('change', function () { gravar(campo); });

        campo.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                campo.blur();
            }
        });
    });

    /* ---- Atualizacao vinda dos outros ---- */
    function ocupado() {
        if (document.hidden || pendentes > 0) {
            return true;
        }
        var foco = document.activeElement;
        return !!(foco && foco.classList && foco.classList.contains('ser-nota'));
    }

    function consultar() {
        if (ocupado()) {
            return;
        }

        var url = window.location.pathname + '?page=dashboard&section=planilha' +
            '&ser_estado=1&revisao=' + encodeURIComponent(revisao);

        fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (dados) {
                if (!dados.ok || !dados.mudou) {
                    return;
                }

                revisao = dados.revisao || revisao;

                Object.keys(dados.celulas || {}).forEach(function (chave) {
                    var campo = document.querySelector('[data-ser-celula="' + chave + '"]');

                    /* Nunca escreve por cima do campo em foco: seria arrancar
                       o numero da mao de quem esta digitando. */
                    if (!campo || campo === document.activeElement) {
                        return;
                    }

                    var novo = dados.celulas[chave];
                    if (limpar(campo.value) !== limpar(novo)) {
                        campo.value = novo;
                        campo.dataset.ultimo = limpar(novo);
                        campo.classList.add('alheio');
                        window.setTimeout(function () { campo.classList.remove('alheio'); }, 2000);
                    }
                });

                aplicarTotais(dados.blocos);
                marcarHora();
                aviso('Atualizado com o que foi lançado por outra pessoa.', 'ok');
            })
            .catch(function () {
                aviso('Sem conexão — a tela pode estar desatualizada.', 'erro');
            });
    }

    window.setInterval(consultar, intervalo);
})();
