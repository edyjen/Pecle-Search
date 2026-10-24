<?php

/*
 * Configuración por defecto del módulo PecleSearch. El proyecto puede
 * sobreescribir cualquier valor con su propio config/pecle_search.php.
 */
return [

    // Interruptor general. Un proyecto puede reemplazarlo en tiempo de ejecución
    // (p. ej. con un parámetro editable desde la interfaz) con
    // PecleSearchManager::enableUsing(fn () => ...).
    'enabled' => env('PECLE_SEARCH_ENABLED', true),

    // Dónde se guardan los vocabularios. store = null usa la caché por defecto.
    'cache' => [
        'store' => env('PECLE_SEARCH_CACHE_STORE'),
        'prefix' => 'pecle_search',
    ],

    // Motor de corrección. Las constantes de costo nacieron en el prototipo y
    // están sin calibrar contra errores de tecleo reales.
    'engine' => [
        'alpha' => 1.6,               // escala del costo de sustitución por distancia entre teclas
        'radius' => 1.8,              // más lejos que esto (en teclas) la sustitución es imposible
        'narrow_depth' => 4,          // hasta aquí la búsqueda no se recorta
        'beam_width' => 60,           // después, solo estos estados sobreviven por nivel
        'max_results' => 8,
        'freq_gamma' => 1.0,          // peso de la frecuencia en el ranking
        'completeness_weight' => 0.3, // desempate a favor de la palabra exacta
        'top_k' => 24,                // candidatos que recuerda cada nodo del trie
    ],

    // Qué se acepta como corrección.
    'corrector' => [
        'min_length' => 3,     // palabras más cortas no se tocan
        'cost_per_char' => 1.0, // costo máximo = max(min_cost, cost_per_char × letras)
        'min_cost' => 2.0,
        'min_margin' => 0.0,    // ventaja mínima del mejor candidato sobre otro distinto; con menos, no se corrige (0 = no se exige)
    ],

    // Etapa 2 del corrector: una letra de más, una de menos o dos cruzadas ("jerssey", "jrsey", "jersye").
    // Compite por costo con la corrección de teclas vecinas (los costos se comparan con alpha × distancia: 1,6 en la
    // misma fila). Solo aterriza en palabras completas, salvo prefix_landing.
    'edits' => [
        'enabled' => true,
        'min_length' => 4,              // palabras más cortas no pasan por esta etapa
        'duplicate' => 1.0,             // letra de más que repite la de al lado: "jerssey"
        'fat_finger' => 1.3,            // letra de más vecina en el teclado de la de al lado: "jersdey"
        'extra' => 1.8,                 // letra de más cualquiera
        'double_omission' => 1.0,       // falta una de dos letras iguales: "amarilo"
        'omission' => 1.5,              // falta una letra cualquiera: "jrsey"
        'transposition' => 1.3,         // dos letras vecinas cruzadas: "jersye"
        'first_letter_penalty' => 0.8,  // recargo si la edición toca la primera letra
        'last_letter_penalty' => 0.8,   // recargo si se borra la última letra y no es repetida (plurales)
        // Shift o Mayús pulsado en lugar de una letra: deja una mayúscula a media palabra ("lNa" en el teléfono, "lNA"
        // en escritorio, por "lana"). La mayúscula marca dónde falta la letra, que es una de las teclas vecinas de esas dos.
        'shift_slip' => 0.7,            // la letra que falta es la A o la Z
        'shift_slip_near' => 1.0,       // ... o la Q o la S
        // Una letra de la fila inferior en lugar del espacio ("lanabmerino" por "lana merino"): la palabra no es nada
        // conocido pero se parte en dos palabras del índice. Los costos dependen de lo cerca que esa tecla queda de la barra.
        'split_words' => true,          // apagar para no partir palabras
        'space_slip' => 1.0,            // la letra del corte es la V o la B
        'space_slip_mid' => 1.1,        // ... la C o la N
        'space_slip_far' => 1.3,        // ... la X o la M
        'split_min_half' => 3,          // largo mínimo de cada mitad (la derecha, si es una palabra completa)
        'split_min_prefix' => 3,        // largo mínimo de la mitad derecha si es solo un comienzo (se está escribiendo): con 2 parte más palabras legítimas
        // Para una palabra a medio escribir con un error ("jrse" mientras se escribe "jrsey"): si nada más corrige,
        // aterrizar en el comienzo de una palabra. Corrige más, pero también cambia más palabras legítimas que no
        // están en el índice (no puede distinguirlas de una a medio escribir); la interfaz avisa y deja buscar lo escrito.
        'prefix_landing' => true,
    ],

    // Autocompletado (PecleSearchManager::suggest): completa lo que se escribe con las palabras del índice.
    'suggest' => [
        'context_words' => 6,         // cuántas palabras anteriores acotan lo que se sugiere (las últimas)
        'min_corrected_length' => 4,  // la palabra que se está escribiendo no se corrige con menos letras (aún no la termina)
    ],

    // Registra cada corrección aplicada (lo escrito, lo buscado y su costo) para calibrar con datos reales.
    'log_corrections' => env('PECLE_SEARCH_LOG_CORRECTIONS', false),

    // Sincronización automática: al guardar/borrar un modelo de un índice se
    // reindexa esa entidad. El vocabulario del corrector se refresca en un job
    // único, con este retraso, para agrupar ráfagas de cambios.
    'sync' => [
        'enabled' => true,
        'vocabulary_delay_seconds' => 30,
    ],

    // Índices con nombre. Cada índice lista sus fuentes de entidades (lo que se busca):
    //   - un modelo y sus columnas (admite relaciones con punto): ['model' => Fabric::class, 'columns' => ['name', 'code']]
    //   - o una clase que implemente Core\Contracts\EntitySource (esta no se sincroniza sola).
    // Un modelo puede declarar 'watch' (modelos relacionados cuyo cambio afecta el texto):
    //   'watch' => [['model' => Fabric::class, 'foreign_key' => 'fabric_id', 'attributes' => ['name']]]
    'indexes' => [
        // 'fabrics' => [
        //     ['model' => App\Models\Fabric::class, 'columns' => ['name', 'code']],
        // ],
        //
        // **`numeric_index => true`** cuando el id de la entidad es numérico (autoincremental): el índice
        // vive en `numeric_search_tokens` y la búsqueda compara entero con entero, sin convertir la llave
        // a texto; así el motor usa la PK del índice (medido: 15× más rápido en el caso selectivo).
        // Por defecto el id se guarda como texto (ULID/UUID) en `search_tokens`, que es lo correcto para
        // proyectos como IdeaTex. **Todas las fuentes de un índice tienen que coincidir**: la tabla y el
        // espacio de `entity_id` son del índice, así que uno mixto se rechaza; separá las entidades en
        // dos índices (como `fabrics` y `fabric_names`).
        //
        // 'patients' => [
        //     ['model' => App\Models\Patient::class, 'columns' => ['name'], 'numeric_index' => true],
        // ],
    ],
];
