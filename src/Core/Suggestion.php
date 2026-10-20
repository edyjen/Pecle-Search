<?php

namespace Pecle\Search\Core;

/** Una sugerencia de autocompletado. */
final readonly class Suggestion
{
    public function __construct(
        /** El texto completo con el que se reemplaza lo escrito. */
        public string $text,
        /**
         * Lo que falta por escribir, para mostrarlo como texto fantasma detrás de lo escrito
         * (text = lo escrito + completion). null si no se puede: hubo una corrección o
         * cambia algo de lo ya escrito (acento, mayúscula).
         */
        public ?string $completion,
        /** ¿Lo escrito tenía un error de tecleo que se corrigió? */
        public bool $corrected,
    ) {}

    /** @return array{text: string, completion: string|null, corrected: bool} */
    public function toArray(): array
    {
        return ['text' => $this->text, 'completion' => $this->completion, 'corrected' => $this->corrected];
    }
}
