<?php

namespace Pecle\Search\Core;

/**
 * Normalización compartida por el indexado y la consulta: todo a ASCII
 * [a-z0-9] — sin acentos, ñ -> n, sin símbolos. DEBE aplicarse idéntica al
 * construir el índice y al buscar, o el trie no coincide.
 *
 * Sin ext-intl a propósito (el módulo debe poder copiarse a cualquier
 * proyecto): el plegado de acentos usa una tabla propia.
 */
final class Normalizer
{
    private const ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'ç' => 'c', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c', 'ď' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i',
        'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o',
        'ŕ' => 'r', 'ř' => 'r', 'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's', 'ţ' => 't', 'ť' => 't',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
        "\u{FFFD}" => 'n', // mojibake de la ñ
    ];

    /** Minúsculas y sin acentos; lo que no es latino queda tal cual. */
    public function fold(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $stripped = preg_replace('/\p{Mn}/u', '', $text); // marcas combinantes sueltas
        if ($stripped !== null) {
            $text = $stripped;
        }

        return strtr($text, self::ACCENTS);
    }

    /**
     * Cómo está escrita de verdad cada palabra de un texto (con sus acentos y
     * mayúsculas), por la palabra tal como se guarda en el índice. Las que
     * se escriben igual con otras formas ("Algodón", "ALGODON") quedan con la primera.
     *
     * @return array<string, string> palabra del índice => palabra tal como se escribió
     */
    public function displayWords(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}\p{M}]+/u', $text, $matches);

        $words = [];
        foreach ($matches[0] as $word) {
            $folded = $this->fold($word);
            if (preg_match('/^[a-z0-9]+$/', $folded) === 1) {
                $words[substr($folded, 0, 64)] ??= $word;
            }
        }

        return $words;
    }

    /**
     * Las palabras de un texto tal como se guardan en el índice de búsqueda y
     * como se buscan: [a-z0-9]+ ya plegado, sin repetidas. Se conservan los
     * números y los códigos ("150cm", "001"): el buscador debe poder
     * encontrarlos aunque el corrector no los toque.
     *
     * @return list<string>
     */
    public function searchTokens(string $text, int $maxTokens = 64, int $maxLength = 64): array
    {
        preg_match_all('/[a-z0-9]+/', $this->fold($text), $matches);

        $tokens = [];
        foreach ($matches[0] as $token) {
            $token = substr((string) $token, 0, $maxLength);
            if (! in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
            if (count($tokens) === $maxTokens) {
                break;
            }
        }

        return $tokens;
    }
}
