<?php

namespace Pecle\Search\Core\Contracts;

/**
 * Una fuente de entidades que también sabe devolver el texto original de
 * algunas de ellas por su id. El autocompletado lo usa para mostrar las palabras
 * como están escritas de verdad (con acentos y mayúsculas): el índice las guarda
 * plegadas. Es opcional; una fuente sin esto simplemente muestra la palabra plegada.
 */
interface EntityTexts
{
    /**
     * @param  list<string>  $ids
     * @return array<string, string> id => texto por el que se busca la entidad
     */
    public function texts(array $ids): array;
}
