'use strict';
// FASE 6 · Configuración inicial NEUTRA del Robot para una hija nueva (4-nov-2026). La llama empaquetar.php.
//
//   node herramientas/robot-inicial.js <carpeta_del_robot_extraído> <salida.json>
//
// El robot, si arranca sin configuración, se siembra con lib/seed.js, que es la de TECNOVA (negocio, Yape 947…, el
// conector 450V con sus packs, FAQ con la razón social). Aquí se parte de esa misma estructura (para que siempre sea
// válida con el código de ESA versión) y se vacía todo lo de TECNOVA. Placeholders que pone el instalador:
// __EMPRESA__ y __AGENTE__. IA: el modelo TeamSocLo SIN key (se compra en la Tienda IA). Arranca APAGADO.
// Si el resultado todavía tiene algo que parezca de tecnova, se detiene y el paquete no se arma.
const fs = require('fs');
const path = require('path');

const [, , dir, salida] = process.argv;
if (!dir || !salida) {
  console.error('Uso: node robot-inicial.js <carpeta_robot> <salida.json>');
  process.exit(1);
}
const seed = require(path.resolve(dir, 'lib/seed.js'));
const c = seed.crear({});

c.negocio = Object.assign({}, c.negocio, {
  nombre: '__EMPRESA__', razon_social: '', descripcion: '', a_quien: '', tienda_fisica: false,
  texto_tienda: '', web: '',
});
c.camila = Object.assign({}, c.camila, {
  nombre: '__AGENTE__',
  bot_honesto: 'Si preguntan si eres un bot, di con naturalidad que eres la asistente virtual de __EMPRESA__ y que un asesor puede atenderlo si lo prefiere.',
});
c.pagos = Object.assign({}, c.pagos, { metodos: [] });
c.productos = [];
c.reglas = (c.reglas || []).map((r) => (r.id === 'r6'
  ? Object.assign({}, r, { a: 'datos técnicos del producto' })
  : r));
c.derivacion = Object.assign({}, c.derivacion, {
  situaciones: (c.derivacion.situaciones || []).filter((s) => !/camila|600v|combos|pack de/i.test(s)),
  anuncios: [],
});
const textoEtapa = {
  saludo: { objetivo: 'Saludar como __AGENTE__ e ir directo al producto del anuncio.' },
  precios: { pasos: 'Da los packs por escrito con su precio, di cómo es el envío y termina con una pregunta de cierre: "¿cuál te separo?".' },
  confirmado: { objetivo: 'Registrar el pedido en el CRM.' },
};
c.etapas = (c.etapas || []).map((e) => Object.assign({}, e, textoEtapa[e.id] || {}));
c.faq = (c.faq || []).filter((f) => !/tecnova|e\.i\.r\.l/i.test(JSON.stringify(f)));
c.recontactos = (c.recontactos || []).filter((r) => !/conector/i.test(r.texto || ''));
c.ia = {
  modelos: [{ id: 'm_teamsoclo', nombre: 'TeamSocLo', rol: 'rapido', endpoint: 'https://gpt.teamsoclo.site/v1', modelo: 'gpt-6.1-sol', clave: '', activo: true }],
  tope_diario_usd: 5,
  aviso_pct: 80,
};

const texto = JSON.stringify(c, null, 2);
// Solo los VALORES (la clave «camila» es el nombre del bloque en el código, no un dato).
const valores = [];
JSON.stringify(c, (k, v) => { if (typeof v === 'string') valores.push(v); return v; });
const prohibido = /tecnova|947\s?670\s?561|924\s?966\s?666|conector|camila|joel|julbany|miox|vitaweb|lumtec|e\.i\.r\.l|tecnovaplus/i;
const m = valores.join('\n').match(prohibido);
if (m) {
  console.error(`robot-inicial: todavía contiene «${m[0]}», que parece de la madre. No armo el paquete.`);
  process.exit(1);
}
if (c.ia.modelos.some((x) => x.clave)) {
  console.error('robot-inicial: un modelo trae key. No armo el paquete.');
  process.exit(1);
}
fs.mkdirSync(path.dirname(salida), { recursive: true });
fs.writeFileSync(salida, texto + '\n');
console.log(`Robot inicial: ${c.etapas.length} etapas, ${c.reglas.length} reglas, ${c.faq.length} FAQ, ${c.recontactos.length} recontactos, 0 productos, 0 medios de pago, IA sin key.`);
