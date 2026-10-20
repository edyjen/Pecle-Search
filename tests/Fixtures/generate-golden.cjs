'use strict';

/**
 * Genera layer1-golden.json: la salida de referencia de layer1Search.js
 * (ClaudeAutocomplete) sobre un vocabulario y unos queries fijos. Es la fuente
 * de verdad del port a PHP, porque el JS ya no se mantiene.
 *
 *   node generate-golden.cjs <ruta/a/ClaudeAutocomplete> [salida.json]
 *
 * Determinista: PRNG con semilla fija, sin fechas. Se corrió una sola vez; no
 * hace falta repetirlo salvo que se quiera ampliar la cobertura.
 */

const path = require('node:path');
const fs = require('node:fs');

const src = path.resolve(process.argv[2] || '');
const out = path.resolve(process.argv[3] || path.join(__dirname, 'layer1-golden.json'));
if (!fs.existsSync(path.join(src, 'layer1Search.js'))) {
  console.error('uso: node generate-golden.cjs <ruta/a/ClaudeAutocomplete> [salida.json]');
  process.exit(1);
}

const { DatabaseSync } = require('node:sqlite');
const { Trie } = require(path.join(src, 'trie.js'));
const { layer1Search } = require(path.join(src, 'layer1Search.js'));
const { KEY_COORDS } = require(path.join(src, 'keyboard.js'));
const { foldAscii } = require(path.join(src, 'normalize.js'));

const TOP_K = 24;
const DB_WORDS = 3000;

// --- vocabulario ----------------------------------------------------------
const db = new DatabaseSync(path.join(src, 'data.db'), { readOnly: true });
const dbRows = db
  .prepare('SELECT text, freq FROM word ORDER BY freq DESC, text ASC LIMIT ?')
  .all(DB_WORDS)
  .map((r) => [r.text, r.freq]);
db.close();

const TEXTILE = `lana 380|algodon 420|poliester 360|denim 300|gabardina 210|licra 190|lino 160|seda 150|terciopelo 120
franela 140|jersey 230|popelina 110|satin 130|tul 90|encaje 85|mezclilla 170|gamuza 100|toalla 95|fieltro 70
lona 80|rayon 75|viscosa 105|nylon 115|spandex 60|elastano 65|pique 98|interlock 55|rib 50|tafeta 45|organza 40
crepe 72|chifon 35|azul 500|rojo 480|verde 470|negro 520|blanco 510|amarillo 300|naranja 280|morado 260|gris 340
beige 220|marfil 90|turquesa 150|fucsia 130|vinotinto 110|celeste 140|marino 200|crudo 120|kaki 100|mostaza 90
coral 85|salmon 80|lila 70|rosado 190|algodonado 20|lanilla 15|cardada 25|peinada 25|estampado 160|liso 300|rayado 90
cuadros 80|bordado 70|sublimado 30|reactivo 28|tintorada 60|lote 400|rollo 450|metros 380|kilos 300`
  .split('|')
  .flatMap((chunk) => chunk.split('\n'))
  .map((s) => s.trim())
  .filter(Boolean)
  .map((s) => { const [t, f] = s.split(' '); return [t, Number(f)]; });

const seen = new Set(dbRows.map(([t]) => t));
const vocabulary = [...dbRows, ...TEXTILE.filter(([t]) => !seen.has(t))];

const trie = new Trie();
vocabulary.forEach(([text, freq], i) => trie.insert(text, i + 1, freq));
trie.buildTopK(TOP_K);

// --- queries --------------------------------------------------------------
let seed = 20261014;
const rnd = () => { seed = (seed * 1664525 + 1013904223) % 4294967296; return seed / 4294967296; };
const pick = (arr) => arr[Math.floor(rnd() * arr.length)];

const keys = [...KEY_COORDS.entries()];
const keyAt = (x, y) => keys.find(([, p]) => p.x === x && p.y === y)?.[0] ?? null;
const neighbors = (ch) => {
  const p = KEY_COORDS.get(ch);
  if (!p) return [];
  return keys.filter(([k, q]) => k !== ch && Math.hypot(q.x - p.x, q.y - p.y) <= 1.8).map(([k]) => k);
};
const shiftRight = (ch) => {
  const p = KEY_COORDS.get(ch);
  if (!p) return ch;
  const row = keys.filter(([, q]) => q.y === p.y).sort((a, b) => a[1].x - b[1].x).map(([k]) => k);
  const i = row.indexOf(ch);
  return row[i + 1] ?? ch;
};

const queries = new Map(); // query -> nota
const add = (q, note) => { if (!queries.has(q)) queries.set(q, note); };

// casos de benchLayer1.js
for (const [q, n] of [
  ['queso', 'bench'], ['chocolate', 'bench'], ['qieso', 'bench'], ['wirdp', 'bench'], ['wirso', 'bench'],
  ['cjocolate', 'bench'], ['faihilia', 'bench'], ['falniila', 'bench'], ['faihiilw', 'bench'],
  ['gflztlna', 'bench'], ['expfclal', 'bench'], ['harnia', 'capa2'], ['lehce', 'capa2'],
  ['qeso', 'capa2'], ['quesoo', 'capa2'],
]) add(q, n);

// textiles escritos a mano (incluye mayusculas y acentos: prueba la normalizacion)
for (const q of ['lqna', 'Lqna', 'LANA', 'algodpn', 'Algodón', 'ALGODÓN', 'poliwster', 'dwnim', 'denin', 'licea',
  'gabardona', 'tercippelo', 'franwla', 'mezvlilla', 'azyl', 'rpjo', 'bkanco', 'amarilli', 'vinotonto',
  'lan', 'alg', 'pol', 'azu', 'a', 'la', 'de', 'zzzzzz', 'ññññ', '1234', 'lana-', 'l@na', 'xq', 'yyyyyyyy', ''])
  add(q, 'textil');

// generados: exacto, prefijo, 1 sustitucion, 2 sustituciones, deriva a la derecha
const pool = vocabulary.map(([t]) => t).filter((t) => t.length >= 4 && /^[a-z]+$/.test(t));
for (let n = 0; n < 60; n++) {
  const w = pick(pool);
  add(w, 'exacto');
  add(w.slice(0, Math.max(2, Math.ceil(w.length * (0.5 + rnd() * 0.3)))), 'prefijo');
  for (const subs of [1, 2]) {
    const arr = [...w];
    for (let s = 0; s < subs; s++) {
      const i = Math.floor(rnd() * arr.length);
      const nb = neighbors(arr[i]);
      if (nb.length) arr[i] = pick(nb);
    }
    add(arr.join(''), `${subs}-sustitucion`);
  }
  const from = Math.floor(rnd() * w.length);
  const len = Math.max(2, Math.floor(rnd() * (w.length - from)) + 1);
  add([...w].map((c, i) => (i >= from && i < from + len ? shiftRight(c) : c)).join(''), 'deriva');
}

// --- ejecucion ------------------------------------------------------------
const OPTS = [
  { label: 'default', opts: {} },
  { label: 'estrecho', opts: { narrowDepth: 2, beamWidth: 10 } },
  { label: 'top3', opts: { maxResults: 3 } },
];

const cases = [];
for (const [query, note] of queries) {
  for (const { label, opts } of OPTS) {
    // el JS no tolera '' (devuelve [] por qLen === 0), se incluye a proposito
    const results = layer1Search(trie, query, opts).map((r) => ({
      text: r.text, wordId: r.wordId, cost: r.cost, freq: r.freq, rank: r.rank,
    }));
    cases.push({ query, normalized: foldAscii(query), note, opts: label, options: opts, results });
  }
}

const json = {
  meta: {
    engine: 'ClaudeAutocomplete/layer1Search.js',
    topK: TOP_K,
    defaults: { narrowDepth: 4, beamWidth: 60, maxResults: 8 },
    constants: { ALPHA: 1.6, RADIUS: 1.8, FREQ_GAMMA: 1.0, COMPLETITUD_WEIGHT: 0.3 },
    note: 'wordId = posicion en vocabulary + 1. El orden de vocabulary es el orden de insercion en el trie (afecta desempates).',
    cases: cases.length,
  },
  vocabulary,
  cases,
};
fs.writeFileSync(out, JSON.stringify(json));
console.log(`vocabulario ${vocabulary.length} | queries ${queries.size} | casos ${cases.length} | ${(fs.statSync(out).size / 1024).toFixed(0)} KB -> ${out}`);
