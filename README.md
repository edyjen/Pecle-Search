# PecleSearch

Buscador por palabras con corrección silenciosa de errores de tecleo. *Pecle = Pedro + Claude.*

- **Índice de palabras** (`search_tokens`): cada entidad (una tela, un color…) queda asociada a sus
  palabras normalizadas (minúsculas, sin acentos). Buscar es un `WHERE EXISTS` por palabra: cada
  palabra del término debe ser el **comienzo** de alguna palabra de la entidad (todas deben cumplirse).
  No busca por mitad de palabra.
- **Corrector**: antes de buscar, cada palabra del término se corrige contra el vocabulario del propio
  índice, en dos etapas que compiten por costo:
  - *Capa 1*: sustituciones por teclas vecinas y "mano corrida" (teclado QWERTY español): "Lqna" → "Lana".
  - *Etapa 2* (`edits`): una letra de más, una de menos o dos cruzadas — "jerssey", "jrsey", "jersye" →
    "jersey"; también un plural cuando solo existe el singular ("telas" → "tela"). Solo aterriza en palabras
    completas y reutiliza el trie de la capa 1: no construye ni guarda nada.
  - *Shift o Mayús pulsado en lugar de una letra*: en el teléfono Shift mayusculiza solo la letra siguiente
    ("lNa") y en escritorio Mayús mayusculiza todo lo que sigue ("lNA"); en ambos, por "lana", falta la A. Una
    mayúscula a media palabra (una sola seguida de minúsculas, o mayúsculas hasta el final) dice dónde falta la
    letra, y se prueba solo ahí con las teclas que tocan Shift y Mayús: A y Z (0,7), Q y S (1,0). Como hay
    evidencia, vale también para palabras de tres letras. Una mayúscula inicial no cuenta.
  Deja intactas las palabras que ya existen o se están escribiendo (prefijo), las de menos de 3 letras y
  las que llevan dígitos. No corrige dos errores a la vez (una omisión y una sustitución juntas).
- **Autocompletado** (`PecleSearchManager::suggest`): completa lo que se escribe con las palabras que ya
  existen en un índice — "Alg" → "Alg|odón", "Algodón Gr" → "Algodón Gr|ueso" — y, si hay un error de
  tecleo, sugiere la corrección. Ver «Autocompletado» más abajo.
- Si algo falla (caché caída, índice sin construir, módulo apagado) el término pasa tal cual: corregir
  es una mejora, nunca una condición para buscar.

## Estructura

```
config/pecle_search.php   valores por defecto (se mezclan en config('pecle_search'))
src/Core/                 PHP puro, sin Illuminate ni App\
src/Laravel/              único lugar que toca Illuminate
database/migrations/      tablas search_tokens y search_indexes
tests/                    Pest + Testbench; Fixtures/layer1-golden.json es la referencia del algoritmo
```

Los tests se corren con `composer install && vendor/bin/pest`.

`Core/` se puede usar sin Laravel implementando `Contracts\TokenStore` (el índice) e
`Contracts\IndexStore` (caché del vocabulario) y alimentando un `Indexer` con `EntitySource`s.
Un test (`ModuleIsolationTest`) falla si `Core/` importa algo de Laravel o del resto de la app.

## Instalación

```bash
composer require edyjen/pecle-search
```

Mientras el repo sea privado, añadir antes a `composer.json` del proyecto:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/edyjen/pecle-search" }]
```

Para desarrollar el paquete junto a un proyecto, usar `{ "type": "path", "url": "../pecle-search" }`.

1. El provider (`Pecle\Search\Laravel\PecleSearchServiceProvider`) se descubre solo.
2. `php artisan migrate` (el paquete carga sus migraciones; son idempotentes si las tablas ya existen).
3. Publicar la config si hace falta: `php artisan vendor:publish --tag=pecle-search-config`.
4. Declarar los índices en `config/pecle_search.php` (ver `config.php`):
   ```php
   'indexes' => ['products' => [['model' => Product::class, 'columns' => ['name', 'sku']]]],
   ```
   Una columna puede venir de una relación con punto (`'brand.name'`). Para que el cambio de un modelo
   relacionado reindexe a sus dependientes, `'watch' => [['model' => Brand::class, 'foreign_key' => 'brand_id', 'attributes' => ['name']]]`.
   Una fuente más compleja es una clase que implemente `Core\Contracts\EntitySource`.
5. `php artisan pecle-search:rebuild` para la carga inicial (conviene una migración que lo llame al
   desplegar y un `Schedule::command('pecle-search:rebuild')` nocturno de respaldo).
6. En las consultas: `Product::query()->whereIndexed('products', $term, ['name', 'sku'])`. El tercer
   argumento es el respaldo (columnas para un `LIKE`, o una función) mientras el índice no esté construido.
   Para corregir antes: `app(PecleSearchManager::class)->resolve($term, 'products')` devuelve una
   `Resolution` (`original`, `applied`, `corrected()`, `toArray()`) para mostrar «Mostrando resultados
   para…».

## Lo que mantiene el índice solo

`IndexSynchronizer` escucha `saved`/`restored`/`deleted` de los modelos de cada índice y reindexa esa
entidad al confirmarse la transacción. **No ve** lo que se escriba sin eventos de Eloquent
(`DB::table()`, `->update()` masivo, importaciones en bloque): ahí hay que reconstruir
(`pecle-search:rebuild`). El vocabulario del corrector se refresca con un job único con retraso, que
agrupa ráfagas de cambios.

## Interruptor

`config('pecle_search.enabled')` enciende o apaga la corrección. Un proyecto puede delegarlo en otra
fuente (un parámetro editable en la interfaz) con `PecleSearchManager::enableUsing(fn () => ...)`.
Apagada solo la corrección: la búsqueda por palabras sigue funcionando.

## Autocompletado

`PecleSearchManager::suggest($texto, $indice, $limite)` devuelve `Suggestion`s (`text`, `completion`,
`corrected`). El texto pasa primero por el corrector (si está encendido), así que "Algdon Gr" llega
como "algodon Gr". Después:

- Las palabras terminadas acotan: solo se sugiere lo que aparece **en las mismas entidades** que todas
  esas palabras ("Algodón Gr" no ofrece "Granito" si ninguna tela es "Algodón Granito"). Se usan
  las últimas `suggest.context_words` (6).
- La palabra a medio escribir se completa por prefijo, ordenada por cuántas entidades la usan. Si el
  texto termina en espacio se sugiere la palabra que sigue.
- El índice guarda las palabras sin acentos; cómo se escriben de verdad se recupera del texto original
  de las entidades (`Contracts\EntityTexts`, que implementa `EloquentEntitySource`). Sin eso se muestra
  la palabra plegada. Las sugerencias siguen el estilo de lo escrito ("alg" → "algodón", "ALG" → "ALGODÓN").
- `completion` es lo que falta, para mostrarlo como texto fantasma detrás de lo escrito
  (`text` = lo escrito + `completion`). Es `null` si la sugerencia cambia algo de lo ya escrito (una
  corrección, un acento que faltaba).

Para sugerir los valores de **una** columna conviene un índice solo con esa columna (si no, el
índice mezcla, p. ej., nombres y códigos). Necesita `Contracts\CompletionStore` (lo implementa
`DatabaseTokenStore`); un `Completer` se puede usar sin Laravel con otra implementación.

## Calibración pendiente

Las constantes del motor (`alpha`, `radius`, `cost_per_char`…) nacieron en un prototipo y no están
calibradas contra errores de tecleo reales. El tope de costo aceptable por defecto es
`max(2, letras)`: con errores de una tecla corrige ~97 % bien; con una cadena aleatoria sobre un
vocabulario de supermercado "corrige" ~8 %.

## La etapa 2, por dentro

Una edición en la posición i deja intacto lo tecleado antes de i; como la palabra ya se sabe desconocida,
solo se prueban las posiciones hasta donde lo tecleado todavía coincide con el comienzo de alguna palabra
(poda exacta: `EditStageTest` la compara con probar todas las ediciones por fuerza bruta). Visita unas
decenas de nodos; la capa 1 explora cientos.

Los costos (`edits.*` en `config.php`) se comparan con el de una sustitución (alpha × distancia entre teclas:
1,6 en la misma fila): los errores mecánicos típicos (letra repetida 1,0, dedo gordo 1,3, transposición 1,3,
falta una letra 1,5) cuestan menos; la letra de más cualquiera (1,8) más. Tocar la primera letra o borrar la
última sin que sea repetida lleva un recargo de 0,8. Gana el menor costo; con el mismo, el mejor rank. **Atajo
exacto**: la capa 1 cuesta al menos una sustitución a la tecla más cercana (1,6), así que si la etapa 2
encuentra algo más barato la capa 1 ni se ejecuta (resultado idéntico, y es la parte cara).

`edits.prefix_landing` (encendido) deja que, si nada más corrige, la etapa 2 aterrice en el comienzo de una
palabra: sirve para lo que se está escribiendo con un error ("jrse" mientras se escribe "jrsey"), a costa de
cambiar más palabras legítimas que no están en el índice (con el vocabulario de IdeaTex, +2 a +7 puntos). No
puede distinguir una palabra a medio escribir de una completa que no existe, así que la interfaz debe avisar
de la corrección y dejar buscar lo escrito. Se apaga con `edits.prefix_landing = false`.

## Medir y calibrar

`php artisan pecle-search:evaluate <índice> [--sample=400] [--seed=1]` mide el corrector con el vocabulario
real del índice: fabrica errores de tecleo de nueve tipos (sustitución por tecla vecina, doble, mano corrida,
omisión, inserción, transposición, letra repetida, tecla vecina de más y letra doble con una menos) y
compara varios topes de costo y márgenes de ambigüedad, con la etapa 2 encendida, apagada o las dos
(`--edits=on|off|both`). Para cada configuración informa cuántos errores corrige bien, cuántos corrige mal
y —lo más importante— cuántas palabras legítimas que no están en el índice cambia por error. Misma semilla,
mismos errores.

`pecle_search.log_corrections` (o `PECLE_SEARCH_LOG_CORRECTIONS`) deja en el log cada corrección aplicada
(`pecle_search.corrected`: lo escrito, lo buscado y su costo): son los errores de tecleo reales con los que
contrastar lo medido con errores fabricados.
