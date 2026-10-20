<?php

namespace Pecle\Search\Core\Contracts;

/**
 * De dónde salen las entidades de un índice: cada una con su id y el texto
 * por el que se la busca (el nombre de una tela, un color…). El módulo lo
 * tokeniza y lo asocia al id.
 */
interface EntitySource
{
    /** @return iterable<string|int, string> id de la entidad => texto buscable */
    public function entities(): iterable;
}
