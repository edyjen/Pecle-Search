<?php

/*
 * El módulo debe poder copiarse a otro proyecto: Core/ no puede depender ni
 * de Laravel ni del resto de la aplicación, y Laravel/ solo de Illuminate y
 * del propio módulo.
 */
function pecleSourceFiles(string $directory): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/src/'.$directory)) as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

it('Core no depende de Laravel ni del resto de la app', function () {
    $files = pecleSourceFiles('Core');
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect(file_get_contents($file))
            ->not->toMatch('/\bIlluminate\\\\/', basename($file).' usa Illuminate')
            ->not->toMatch('/\bApp\\\\/', basename($file).' usa código de la app');
    }
});

it('Laravel solo depende de Illuminate y del propio módulo', function () {
    $files = pecleSourceFiles('Laravel');
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect(file_get_contents($file))
            ->not->toMatch('/\bApp\\\\/', basename($file).' usa código de la app');
    }
});
