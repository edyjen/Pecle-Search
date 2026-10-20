<?php

namespace Pecle\Search\Laravel;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Vuelve a derivar el vocabulario del corrector desde el índice. Se despacha
 * con un pequeño retraso y es único hasta que empieza a correr: una ráfaga de
 * cambios (una importación, una edición masiva) produce una sola
 * actualización del vocabulario, que ya recoge todo lo anterior.
 */
final class RefreshVocabularyJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly string $index) {}

    public function uniqueId(): string
    {
        return $this->index;
    }

    public function handle(PecleSearchManager $manager): void
    {
        $manager->refreshVocabulary($this->index);
    }
}
