<?php

/**
 * Cache mínimo do sistema.
 *
 * ---------------------------------------------------------------------------
 * O QUE É, E O QUE DELIBERADAMENTE NÃO É
 * ---------------------------------------------------------------------------
 * É memória de UMA requisição. Nasce quando a página começa a ser montada e
 * morre quando ela termina. Duas pessoas abrindo a mesma tela ao mesmo tempo
 * não compartilham nada.
 *
 * NÃO é cache entre requisições, e isso é escolha, não limitação. Este sistema
 * apura competição ao vivo: um jurado lança a nota e a coordenação precisa vê-la
 * na tela seguinte. Guardar resultado de uma requisição para outra significa
 * escolher por quanto tempo mostrar número velho — e num festival não existe
 * duração aceitável para isso. O servidor também não tem APCu nem Redis, então
 * o único cache compartilhado possível seria em disco: um arquivo a mais para
 * ficar velho, travar e mentir.
 *
 * O ganho vem de outro lugar: dentro de uma mesma requisição o sistema pergunta
 * a mesma coisa ao banco várias vezes — o banco inteiro, as regras do evento, a
 * planilha. Perguntar uma vez só é seguro por construção, porque nada mudou no
 * meio do caminho.
 *
 * ---------------------------------------------------------------------------
 * A REGRA QUE NÃO PODE SER QUEBRADA
 * ---------------------------------------------------------------------------
 * Quem ESCREVE, esquece. Toda gravação precisa chamar cache_esquecer() com o
 * que invalidou, senão o resto da requisição continua enxergando o valor
 * anterior — e o pior tipo de defeito é o que só aparece quando duas pessoas
 * mexem na mesma tela ao mesmo tempo.
 */

declare(strict_types=1);

/** Tudo o que já foi calculado nesta requisição. */
$GLOBALS['_cache'] = [];

/**
 * Devolve o valor guardado; calcula e guarda na primeira vez.
 *
 * A chave é o nome do que está sendo guardado, com os parâmetros que o
 * definem: 'regras:9' e 'regras:10' são coisas diferentes.
 */
function cache_lembrar(string $chave, callable $calcular): mixed
{
    if (array_key_exists($chave, $GLOBALS['_cache'])) {
        return $GLOBALS['_cache'][$chave];
    }

    return $GLOBALS['_cache'][$chave] = $calcular();
}

/**
 * Descarta o que foi guardado.
 *
 * Sem argumento, descarta tudo. Com um prefixo, descarta só o ramo: esquecer
 * 'ser' derruba 'ser:planilha' e 'ser:notas' juntos, que é o que se quer
 * quando uma célula da planilha é gravada.
 */
function cache_esquecer(string $prefixo = ''): void
{
    if ($prefixo === '') {
        $GLOBALS['_cache'] = [];

        return;
    }

    foreach (array_keys($GLOBALS['_cache']) as $chave) {
        if ($chave === $prefixo || str_starts_with($chave, $prefixo . ':')) {
            unset($GLOBALS['_cache'][$chave]);
        }
    }
}
