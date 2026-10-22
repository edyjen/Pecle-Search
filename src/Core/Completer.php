<?php

namespace Pecle\Search\Core;

use Pecle\Search\Core\Contracts\CompletionStore;
use Closure;

/**
 * Autocompleta lo que se escribe, palabra por palabra, con lo que ya existe en
 * un índice: "Alg" -> "Alg|odón"; "Algodón Gr" -> "Algodón Gr|ueso".
 *
 * Recibe el query ya pasado por el corrector (Resolution), así que un error de
 * tecleo llega arreglado ("Algdon Gr" -> "algodon Gr"). Cada sugerencia sale
 * de las entidades que contienen TODAS las palabras anteriores: "Algodón Gr"
 * solo ofrece lo que de verdad acompaña a "algodón", no cualquier palabra con
 * "gr". Si el query termina en espacio se sugiere la palabra que sigue.
 *
 * El índice guarda las palabras sin acentos; cómo se escribe cada una de
 * verdad (acentos, mayúsculas) se recupera del texto original de las entidades.
 */
final class Completer
{
    private const WORD = '/[\p{L}\p{N}\p{M}]+/u';

    public function __construct(
        private readonly CompletionStore $store,
        private readonly Normalizer $normalizer,
        /** Cuántas palabras anteriores se usan para acotar (las últimas). */
        private readonly int $contextWords = 6,
        /**
         * La palabra que se está escribiendo solo se corrige si ya tiene este largo: con menos letras casi
         * cualquier cosa «se parece» a algo, y lo más probable es que simplemente no haya terminado.
         */
        private readonly int $minCorrectedLength = 4,
    ) {}

    /**
     * @param  Closure(list<string>): array<string, string>  $texts  ids de entidades => su texto original
     * @return list<Suggestion>
     */
    public function suggest(string $index, Resolution $resolution, Closure $texts, int $limit = 6): array
    {
        $resolution = $this->withoutShortCorrection($resolution);
        $applied = $resolution->applied;
        preg_match_all(self::WORD, $applied, $matches, PREG_OFFSET_CAPTURE);
        /** @var list<array{0: string, 1: int}> $runs */
        $runs = $matches[0];
        if ($runs === [] || $limit < 1) {
            return [];
        }

        // ¿Se está escribiendo una palabra (termina en letra) o ya terminó (termina en espacio)?
        $open = preg_match('/[\p{L}\p{N}\p{M}]$/u', $applied) === 1;
        $last = $open ? array_pop($runs) : null;

        $context = [];
        foreach ($runs as [$run]) {
            foreach ($this->tokens($run) as $token) {
                $context[$token] = $token;
            }
        }
        $context = array_slice(array_values($context), -$this->contextWords);

        $prefix = '';
        if ($last !== null) {
            $pieces = $this->tokens($last[0]);
            if (count($pieces) !== 1) {
                return [];
            }
            $prefix = $pieces[0];
        }
        if ($context === [] && $prefix === '') {
            return [];
        }

        $rows = $this->store->completions($index, $context, $prefix, $limit + 1);
        if ($rows === []) {
            return [];
        }

        $forms = $this->forms($rows, $texts);
        $corrected = $resolution->corrected();

        // Lo anterior a la palabra que se completa; las palabras corregidas recuperan sus acentos.
        $fixed = [];
        foreach ($resolution->tokens as $token) {
            if ($token->corrected()) {
                // Una palabra partida en dos ("lana merino") deja las dos como corregidas.
                foreach (preg_split('/\s+/u', mb_strtolower($token->applied), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                    $fixed[$word] = true;
                }
            }
        }
        $base = '';
        $cursor = 0;
        foreach ($runs as [$run, $offset]) {
            $base .= substr($applied, $cursor, $offset - $cursor);
            $base .= $this->restored($run, $fixed, $forms);
            $cursor = $offset + strlen($run);
        }
        $base .= $last !== null ? substr($applied, $cursor, $last[1] - $cursor) : substr($applied, $cursor);

        // Si lo anterior cambió (corrección, acento), el texto fantasma ya no encaja detrás de lo escrito.
        $altered = $base !== substr($applied, 0, $last !== null ? $last[1] : strlen($applied));

        $suggestions = [];
        foreach ($rows as $row) {
            $display = $forms[$row['token']] ?? $row['token'];

            $suggestion = $last === null
                ? new Suggestion($base.$display, $corrected || $altered ? null : $display, $corrected)
                : $this->completeWord($base, $last[0], $display, $corrected, $altered);
            if ($suggestion === null || isset($suggestions[$suggestion->text])) {
                continue;
            }

            $suggestions[$suggestion->text] = $suggestion;
            if (count($suggestions) === $limit) {
                break;
            }
        }

        return array_values($suggestions);
    }

    /** Deshace la corrección de la última palabra si es demasiado corta para fiarse de ella. */
    private function withoutShortCorrection(Resolution $resolution): Resolution
    {
        $tokens = $resolution->tokens;
        $token = end($tokens);
        $endsWithWord = preg_match('/[\p{L}\p{N}\p{M}]$/u', $resolution->applied) === 1;
        if ($token === false || ! $endsWithWord || ! $token->corrected() || mb_strlen($token->original) >= $this->minCorrectedLength
            || ! str_ends_with($resolution->applied, $token->applied)) {
            return $resolution;
        }

        $tokens[array_key_last($tokens)] = new TokenResolution($token->original, $token->original);

        return new Resolution($resolution->original, substr($resolution->applied, 0, -strlen($token->applied)).$token->original, $tokens);
    }

    /** La sugerencia de $display para la palabra a medio escribir $typed, o null si no aporta nada. */
    private function completeWord(string $base, string $typed, string $display, bool $corrected, bool $altered): ?Suggestion
    {
        $length = mb_strlen($typed);

        // Se escribió el comienzo de la palabra tal cual: lo que falta se muestra detrás.
        if (! $corrected && ! $altered && mb_strlen($display) > $length && mb_strtolower(mb_substr($display, 0, $length)) === mb_strtolower($typed)) {
            $remainder = $this->remainderLike($typed, $display, mb_substr($display, $length));

            return new Suggestion($base.$typed.$remainder, $remainder, false);
        }

        // Cambia lo ya escrito (un error corregido, un acento que faltaba): se ofrece la palabra entera.
        // Pero nunca se le quitan los acentos que ya escribió: el catálogo puede tenerla sin ellos.
        if (! $corrected && $this->hasAccents($typed) && $this->normalizer->fold($display) === $this->normalizer->fold($typed)) {
            return null;
        }
        $word = $this->caseLike($typed, $display);
        if (! $corrected && ! $altered && $word === $typed) {
            return null;
        }

        return new Suggestion($base.$word, null, $corrected);
    }

    /** Cómo se escribe de verdad cada palabra, por palabra del índice. */
    private function forms(array $rows, Closure $texts): array
    {
        $ids = [];
        foreach ($rows as $row) {
            array_push($ids, ...$row['ids']);
        }

        $seen = [];
        foreach ($texts(array_values(array_unique($ids))) as $text) {
            foreach ($this->normalizer->displayWords((string) $text) as $token => $word) {
                $seen[$token][$word] = ($seen[$token][$word] ?? 0) + 1;
            }
        }

        $forms = [];
        foreach ($seen as $token => $words) {
            arsort($words); // la más frecuente; a igual cantidad, la primera que se vio
            $forms[(string) $token] = (string) array_key_first($words);
        }

        return $forms;
    }

    /**
     * Una palabra ya escrita, como debe quedar en la sugerencia: si el corrector la cambió o si
     * le faltan los acentos, se escribe como está en el catálogo; si no, tal cual.
     */
    private function restored(string $run, array $fixed, array $forms): string
    {
        $tokens = $this->tokens($run);
        $display = count($tokens) === 1 ? ($forms[$tokens[0]] ?? null) : null;
        if ($display === null || mb_strtolower($display) === mb_strtolower($run)) {
            return $run;
        }

        // Sin acentos (los que ya escribió se respetan): igual a la del catálogo salvo por ellos. Si no, solo si el corrector la tocó.
        $onlyAccents = ! $this->hasAccents($run) && $this->normalizer->fold($display) === $this->normalizer->fold($run);

        return $onlyAccents || isset($fixed[mb_strtolower($run)]) ? $this->caseLike($run, $display) : $run;
    }

    /** @return list<string> */
    private function tokens(string $run): array
    {
        preg_match_all('/[a-z0-9]+/', $this->normalizer->fold($run), $matches);

        return array_map(fn (string $token) => substr($token, 0, 64), $matches[0]);
    }

    /** $word con las mayúsculas de lo que se escribió: "ALG" -> "ALGODÓN", "alg" -> "algodón", "Alg" -> "Algodón". */
    private function caseLike(string $typed, string $word): string
    {
        if ($this->isUpper($typed)) {
            return mb_strtoupper($word, 'UTF-8');
        }

        $body = $this->isUpper($word) ? mb_strtolower($word, 'UTF-8') : $word;
        $first = mb_substr($typed, 0, 1);
        $head = $first !== mb_strtolower($first, 'UTF-8') ? mb_strtoupper(mb_substr($body, 0, 1), 'UTF-8') : mb_strtolower(mb_substr($body, 0, 1), 'UTF-8');

        return $head.mb_substr($body, 1);
    }

    /** Lo que falta de la palabra, escrito al estilo de lo que ya se tecleó. */
    private function remainderLike(string $typed, string $display, string $remainder): string
    {
        if ($this->isUpper($typed)) {
            return mb_strtoupper($remainder, 'UTF-8');
        }
        if ($this->isUpper($display) && $typed !== mb_strtoupper($typed, 'UTF-8')) {
            return mb_strtolower($remainder, 'UTF-8'); // catálogo en mayúsculas, escribió en minúsculas
        }

        return $remainder;
    }

    /** ¿Tiene letras fuera de ASCII (acentos, ñ)? */
    private function hasAccents(string $text): bool
    {
        return preg_match('/[^ -]/', $text) === 1;
    }

    /** Más de una letra y todas en mayúscula. */
    private function isUpper(string $text): bool
    {
        return mb_strlen($text) > 1 && $text === mb_strtoupper($text, 'UTF-8') && preg_match('/\p{L}/u', $text) === 1;
    }
}
