<?php

use Pecle\Search\Core\Completer;
use Pecle\Search\Core\Contracts\CompletionStore;
use Pecle\Search\Core\Normalizer;
use Pecle\Search\Core\Resolution;
use Pecle\Search\Core\Suggestion;
use Pecle\Search\Core\TokenResolution;

/** Un índice en memoria con la misma regla que el de la base de datos. */
class PecleFakeCompletions implements CompletionStore
{
    /** @param array<string, string> $entities id => texto */
    public function __construct(private readonly array $entities, private readonly Normalizer $normalizer = new Normalizer) {}

    public function completions(string $index, array $context, string $prefix, int $limit): array
    {
        $found = [];
        foreach ($this->entities as $id => $text) {
            $tokens = $this->normalizer->searchTokens($text);
            foreach ($context as $word) {
                if (array_filter($tokens, fn (string $t) => str_starts_with($t, $word)) === []) {
                    continue 2;
                }
            }
            foreach ($tokens as $token) {
                if (in_array($token, $context, true) || ! str_starts_with($token, $prefix)) {
                    continue;
                }
                $found[$token]['ids'][(string) $id] = (string) $id;
            }
        }

        $rows = [];
        foreach ($found as $token => $data) {
            $rows[] = ['token' => (string) $token, 'entities' => count($data['ids']), 'ids' => array_slice(array_values($data['ids']), 0, 2)];
        }
        usort($rows, fn ($a, $b) => [$b['token'] === $prefix, $b['entities'], $a['token']] <=> [$a['token'] === $prefix, $a['entities'], $b['token']]);

        return array_slice($rows, 0, $limit);
    }
}

function pecleCompleter(array $entities): array
{
    $completer = new Completer(new PecleFakeCompletions($entities), new Normalizer);

    return [
        fn (string $typed, ?Resolution $resolution = null, int $limit = 6) => $completer->suggest(
            'names',
            $resolution ?? Resolution::unchanged($typed),
            fn (array $ids) => array_intersect_key($entities, array_flip($ids)),
            $limit,
        ),
    ];
}

function pecleTexts(array $suggestions): array
{
    return array_map(fn (Suggestion $s) => $s->text, $suggestions);
}

$catalog = [
    'a' => 'Algodón Grueso',
    'b' => 'Algodón Gris',
    'c' => 'Algodón Gris Claro',
    'd' => 'Lana Granito',
    'e' => 'Lino',
    'f' => 'Jersey Algodón',
];

describe('autocomplete', function () use ($catalog) {
    it('completes the word being typed, showing what is missing behind it', function () use ($catalog) {
        [$suggest] = pecleCompleter($catalog);

        $suggestions = $suggest('Alg');

        expect($suggestions[0]->text)->toBe('Algodón')
            ->and($suggestions[0]->completion)->toBe('odón')
            ->and($suggestions[0]->corrected)->toBeFalse()
            ->and(pecleTexts($suggestions))->toBe(['Algodón']);
    });

    it('only offers what goes with the words already written', function () use ($catalog) {
        [$suggest] = pecleCompleter($catalog);

        // «Granito» es de otra tela: con «algodón» antes solo cuenta lo que lo acompaña.
        expect(pecleTexts($suggest('Algodón Gr')))->toBe(['Algodón Gris', 'Algodón Grueso'])
            ->and($suggest('Algodón Gr')[1]->completion)->toBe('ueso')
            ->and(pecleTexts($suggest('Lana Gr')))->toBe(['Lana Granito']);
    });

    it('suggests the next word after a space', function () use ($catalog) {
        [$suggest] = pecleCompleter($catalog);

        $suggestions = $suggest('Algodón ');

        expect(pecleTexts($suggestions))->toBe(['Algodón Gris', 'Algodón Claro', 'Algodón Grueso', 'Algodón Jersey'])
            ->and($suggestions[0]->completion)->toBe('Gris');
    });

    it('matches the style of what was typed', function () use ($catalog) {
        [$suggest] = pecleCompleter($catalog);

        expect($suggest('alg')[0]->text)->toBe('algodón')
            ->and($suggest('ALG')[0]->text)->toBe('ALGODÓN')
            ->and($suggest('ALG')[0]->completion)->toBe('ODÓN');

        [$upper] = pecleCompleter(['x' => 'ALGODÓN PIMA']);
        expect($upper('alg')[0]->text)->toBe('algodón')   // catálogo en mayúsculas, escribió en minúsculas
            ->and($upper('Alg')[0]->text)->toBe('Algodón');
    });

    it('offers the accented spelling when the word was typed without it, but nothing when it is already right', function () use ($catalog) {
        [$suggest] = pecleCompleter($catalog);

        $suggestion = $suggest('algodon')[0];

        expect($suggestion->text)->toBe('algodón')
            ->and($suggestion->completion)->toBeNull()
            ->and($suggestion->corrected)->toBeFalse()
            ->and($suggest('Algodón'))->toBe([]);
    });

    it('writes the earlier words as the catalog has them, without ghost text when that changes what was typed', function () use ($catalog) {
        [$suggest] = pecleCompleter($catalog);

        $suggestions = $suggest('algodon gr');

        expect(pecleTexts($suggestions))->toBe(['algodón gris', 'algodón grueso'])
            ->and($suggestions[0]->completion)->toBeNull()
            ->and($suggestions[0]->corrected)->toBeFalse();
    });

    it('never takes away the accents the user typed, even if the catalog spells the word without them', function () {
        [$suggest] = pecleCompleter(['a' => 'Algodon Rojo', 'b' => 'Algodon Azul']);

        $suggestions = $suggest('Algodón R');

        expect(pecleTexts($suggestions))->toBe(['Algodón Rojo'])
            ->and($suggestions[0]->completion)->toBe('ojo') // lo escrito queda tal cual: el texto fantasma sigue valiendo
            ->and($suggest('Algodón'))->toBe([]);
    });

    it('suggests the correction of a typo, with the accents restored and no ghost text', function () use ($catalog) {
        [$suggest] = pecleCompleter($catalog);

        // El corrector ya cambió «Algdon» por «Algodon» y dejó «Gr» como estaba.
        $resolution = new Resolution('Algdon Gr', 'Algodon Gr', [
            new TokenResolution('Algdon', 'Algodon', 1.5, 'missing_letter'),
            new TokenResolution('Gr', 'Gr'),
        ]);
        $suggestions = $suggest('Algdon Gr', $resolution);

        expect(pecleTexts($suggestions))->toBe(['Algodón Gris', 'Algodón Grueso'])
            ->and($suggestions[0]->corrected)->toBeTrue()
            ->and($suggestions[0]->completion)->toBeNull();
    });

    it('replaces a half-typed word that the corrector fixed', function () use ($catalog) {
        [$suggest] = pecleCompleter($catalog);

        $resolution = new Resolution('lgod', 'algod', [new TokenResolution('lgod', 'algod', 1.5, TokenResolution::PREFIX)]);
        $suggestion = $suggest('lgod', $resolution)[0];

        expect($suggestion->text)->toBe('algodón')
            ->and($suggestion->corrected)->toBeTrue()
            ->and($suggestion->completion)->toBeNull();
    });

    it('does not trust the correction of a word that is too short to be finished', function () use ($catalog) {
        [$suggest] = pecleCompleter($catalog);

        // «Alr» (3 letras) pasaría por «Alg» para el corrector, pero lo más probable es que siga escribiendo.
        $resolution = new Resolution('Alr', 'Alg', [new TokenResolution('Alr', 'Alg', 1.6, 'keyboard')]);

        expect($suggest('Alr', $resolution))->toBe([]);

        // Con el largo suficiente sí se corrige.
        $long = new Resolution('Algodpn', 'Algodon', [new TokenResolution('Algodpn', 'Algodon', 1.6, 'keyboard')]);
        expect(pecleTexts($suggest('Algodpn', $long)))->toBe(['Algodón']);
    });

    it('offers nothing for what does not exist, an empty query or a word that goes with nothing', function () use ($catalog) {
        [$suggest] = pecleCompleter($catalog);

        expect($suggest('Zzz'))->toBe([])
            ->and($suggest(''))->toBe([])
            ->and($suggest('   '))->toBe([])
            ->and($suggest('Lino Gr'))->toBe([]);
    });

    it('respects the limit', function () use ($catalog) {
        [$suggest] = pecleCompleter($catalog);

        expect($suggest('Algodón ', null, 2))->toHaveCount(2);
    });

    it('completes a word that was split in two, because a letter took the place of the space', function () {
        $entities = ['a' => 'Lana Merino', 'b' => 'Lana Merina', 'c' => 'Lino'];
        [$suggest] = pecleCompleter($entities);
        // "lanabmer" ya pasó por el corrector: una sola palabra escrita se convirtió en dos.
        $resolution = new Resolution('lanabmer', 'lana mer', [new TokenResolution('lanabmer', 'lana mer', 1.0, 'letter_for_space')]);

        $suggestions = $suggest('lanabmer', $resolution);

        // Sigue el estilo de lo escrito (minúsculas), como en cualquier otra corrección.
        expect(pecleTexts($suggestions))->toEqualCanonicalizing(['lana merino', 'lana merina'])
            ->and($suggestions[0]->corrected)->toBeTrue()
            ->and($suggestions[0]->completion)->toBeNull();
    });
});
