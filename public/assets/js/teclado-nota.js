/* ===========================================================================
 * Teclado de nota dentro da própria tela
 *
 * ---------------------------------------------------------------------------
 * POR QUE ISTO EXISTE
 * ---------------------------------------------------------------------------
 * No tablet, tocar o campo da nota abre o teclado do sistema. Ele ocupa metade
 * da tela, empurra a ficha para cima e esconde justamente a linha que está
 * sendo preenchida — o jurado perde de vista o critério que está avaliando.
 *
 * Este arquivo troca aquele teclado por um desenhado na página: fica preso ao
 * rodapé, tem altura conhecida, e a ficha ganha um respiro embaixo para que a
 * linha em edição continue visível.
 *
 * ---------------------------------------------------------------------------
 * DECISÕES QUE PARECEM DETALHE E NÃO SÃO
 * ---------------------------------------------------------------------------
 * Só em tela de toque. No computador o teclado físico é melhor do que qualquer
 * coisa que se desenhe, então nada aqui roda.
 *
 * O inputmode="none" é aplicado por JAVASCRIPT, não no HTML. Se este arquivo
 * falhar em carregar, o campo volta a ser um number comum e o teclado do
 * sistema aparece — ruim, mas o jurado consegue lançar a nota. Se estivesse
 * fixo no HTML, uma falha aqui deixaria o campo mudo.
 *
 * Nota fora da faixa não é corrigida em silêncio. Quem digita 1 quando queria
 * 10 e vê o sistema gravar 9,0 nunca descobre o erro. O Pronto recusa e
 * sinaliza; corrigir é de quem avalia.
 * ======================================================================== */

(function () {
    'use strict';

    /* Tela de toque apenas. */
    if (!window.matchMedia || !window.matchMedia('(pointer: coarse)').matches) {
        return;
    }

    var painel = null;
    var leitura = null;
    var titulo = null;
    var faixa = null;
    var alvo = null;    /* o .score-box em edição */
    var buffer = '';    /* o que foi digitado, como texto */

    function porExtenso(texto) {
        return String(texto).replace('.', ',');
    }

    function limites() {
        var min = parseFloat(alvo && alvo.min);
        var max = parseFloat(alvo && alvo.max);
        var passo = parseFloat(alvo && alvo.step);

        return {
            min: isNaN(min) ? 0 : min,
            max: isNaN(max) ? 10 : max,
            passo: (isNaN(passo) || passo <= 0) ? 0.1 : passo
        };
    }

    function casasDecimais(passo) {
        var partes = String(passo).split('.');

        return partes.length > 1 ? partes[1].length : 0;
    }

    function construir() {
        painel = document.createElement('div');
        painel.className = 'teclado-nota';
        painel.setAttribute('role', 'group');
        painel.setAttribute('aria-label', 'Teclado para lançar a nota');
        painel.hidden = true;

        var html = '' +
            '<div class="teclado-nota-topo">' +
                '<div class="teclado-nota-alvo">' +
                    '<strong data-tn-titulo></strong>' +
                    '<small data-tn-faixa></small>' +
                '</div>' +
                '<output class="teclado-nota-leitura" data-tn-leitura>—</output>' +
            '</div>' +
            '<div class="teclado-nota-grade">';

        ['7', '8', '9', '4', '5', '6', '1', '2', '3', ','].forEach(function (tecla) {
            html += '<button type="button" data-tn-tecla="' + tecla + '">' + tecla + '</button>';
        });

        html += '<button type="button" data-tn-tecla="0">0</button>' +
                '<button type="button" class="tn-apagar" data-tn-apagar aria-label="Apagar último dígito">&#9003;</button>' +
            '</div>' +
            '<div class="teclado-nota-rodape">' +
                '<button type="button" class="tn-limpar" data-tn-limpar>Limpar</button>' +
                '<button type="button" class="tn-pronto" data-tn-pronto>Pronto</button>' +
            '</div>';

        painel.innerHTML = html;
        document.body.appendChild(painel);

        leitura = painel.querySelector('[data-tn-leitura]');
        titulo = painel.querySelector('[data-tn-titulo]');
        faixa = painel.querySelector('[data-tn-faixa]');

        /* Sem isto o toque tira o foco do campo e o teclado se fecharia a cada
           dígito. O preventDefault no pointerdown mantém o foco onde está. */
        painel.addEventListener('pointerdown', function (evento) {
            evento.preventDefault();
        });

        painel.addEventListener('click', aoTocarTecla);
    }

    function desenharLeitura() {
        if (!leitura) {
            return;
        }

        leitura.textContent = buffer === '' ? '—' : porExtenso(buffer);
        leitura.classList.remove('tn-erro');
    }

    function abrir(campo) {
        if (!painel) {
            construir();
        }

        alvo = campo;
        buffer = String(campo.value || '').replace(',', '.');

        var lim = limites();
        var linha = campo.closest('.criterion-row');
        var nome = linha ? linha.querySelector('.criterion-name strong') : null;

        titulo.textContent = nome ? nome.textContent : 'Nota';
        faixa.textContent = 'de ' + porExtenso(lim.min) + ' a ' + porExtenso(lim.max);

        desenharLeitura();
        painel.hidden = false;
        document.body.classList.add('com-teclado-nota');

        /* A linha em edição precisa continuar à vista com o teclado aberto. */
        if (linha && linha.scrollIntoView) {
            window.setTimeout(function () {
                linha.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }, 50);
        }
    }

    function fechar() {
        if (!painel || painel.hidden) {
            return;
        }

        painel.hidden = true;
        document.body.classList.remove('com-teclado-nota');
        alvo = null;
        buffer = '';
    }

    /* Escreve no campo o que já dá para interpretar, sem corrigir nada ainda:
       a conferência de faixa acontece no Pronto. */
    function espelharNoCampo() {
        if (!alvo) {
            return;
        }

        var n = parseFloat(buffer);

        if (buffer === '') {
            alvo.value = '';
        } else if (!isNaN(n)) {
            alvo.value = String(n);
        }

        alvo.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function aoTocarTecla(evento) {
        var botao = evento.target.closest('button');

        if (!botao || !alvo) {
            return;
        }

        if (botao.hasAttribute('data-tn-pronto')) {
            concluir();
            return;
        }

        if (botao.hasAttribute('data-tn-limpar')) {
            buffer = '';
            desenharLeitura();
            espelharNoCampo();
            return;
        }

        if (botao.hasAttribute('data-tn-apagar')) {
            buffer = buffer.slice(0, -1);
            desenharLeitura();
            espelharNoCampo();
            return;
        }

        var tecla = botao.getAttribute('data-tn-tecla');

        if (tecla === null) {
            return;
        }

        if (tecla === ',') {
            /* Uma vírgula só, e nunca começando por ela. */
            if (buffer.indexOf('.') === -1) {
                buffer = (buffer === '' ? '0' : buffer) + '.';
            }
        } else if (buffer.replace('.', '').length < 4) {
            buffer += tecla;
        }

        desenharLeitura();
        espelharNoCampo();
    }

    function concluir() {
        if (!alvo) {
            return;
        }

        var lim = limites();
        var n = parseFloat(buffer);

        if (buffer === '' || isNaN(n) || n < lim.min || n > lim.max) {
            /* Recusa e mostra o porquê, em vez de corrigir por conta própria. */
            leitura.classList.add('tn-erro');
            leitura.textContent = buffer === '' ? 'informe a nota' : porExtenso(buffer) + ' fora da faixa';
            return;
        }

        var casas = casasDecimais(lim.passo);
        var arredondada = Math.round(n / lim.passo) * lim.passo;

        alvo.value = arredondada.toFixed(casas);
        alvo.dispatchEvent(new Event('input', { bubbles: true }));
        alvo.dispatchEvent(new Event('change', { bubbles: true }));
        fechar();
    }

    /* -------------------------------------------------------------------
     * Ligação com a ficha
     * ---------------------------------------------------------------- */

    document.addEventListener('focusin', function (evento) {
        var campo = evento.target;

        if (!campo.matches || !campo.matches('.score-box') || campo.disabled || campo.readOnly) {
            fechar();
            return;
        }

        /* Impede o teclado do sistema. Feito aqui, e não no HTML, para que uma
           falha ao carregar este arquivo devolva o comportamento antigo. */
        campo.setAttribute('inputmode', 'none');
        abrir(campo);
    });

    /* Tocar fora da ficha e fora do teclado fecha. */
    document.addEventListener('pointerdown', function (evento) {
        if (!painel || painel.hidden) {
            return;
        }

        if (evento.target.closest('.teclado-nota') || evento.target.closest('.score-box')) {
            return;
        }

        fechar();
    });

    /* Enviar a ficha com o teclado aberto deixaria o rodapé preso na tela. */
    document.addEventListener('submit', fechar, true);
}());
