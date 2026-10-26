// DOM minimo y permisivo. Objetivo: detectar errores provocados por los DATOS
// (undefined.prop, indices fuera de rango), no por la ausencia de navegador.
const noop = () => {};
function makeNode(tag = 'div') {
  const self = {
    tagName: String(tag).toUpperCase(), nodeName: String(tag).toUpperCase(),
    children: [], childNodes: [], style: {}, dataset: {},
    textContent: '', innerHTML: '', value: '', id: '', title: '', placeholder: '',
    hidden: false, checked: false, disabled: false, scrollTop: 0, scrollHeight: 0,
    offsetWidth: 100, offsetHeight: 40,
    classList: { add: noop, remove: noop, toggle: noop, contains: () => false },
    append(...k) { for (const x of k) if (x && typeof x === 'object') this.children.push(x); },
    appendChild(x) { this.children.push(x); return x; },
    prepend: noop, remove: noop, insertBefore: (x) => x, replaceChildren: noop,
    setAttribute: noop, getAttribute: () => null, removeAttribute: noop,
    addEventListener: noop, removeEventListener: noop, focus: noop, blur: noop, click: noop,
    querySelector: () => makeNode(), querySelectorAll: () => [makeNode()],
    closest: () => null, contains: () => false, scrollIntoView: noop,
    getBoundingClientRect: () => ({top:0,left:0,width:100,height:40,bottom:40,right:100}),
    animate: () => ({ finished: Promise.resolve(), cancel: noop }),
  };
  return new Proxy(self, {
    get(t, k) {
      if (k in t) return t[k];
      if (typeof k === 'symbol') return undefined;
      // Propiedad DOM desconocida: devolver algo inocuo y encadenable.
      return undefined;
    },
    set(t, k, v) { t[k] = v; return true; },
  });
}
const document = {
  createElement: (t) => makeNode(t),
  createElementNS: (ns, t) => makeNode(t),
  createTextNode: (t) => ({ nodeType: 3, textContent: String(t) }),
  createDocumentFragment: () => makeNode('fragment'),
  getElementById: () => makeNode(),
  querySelector: () => makeNode(),
  querySelectorAll: () => [],
  addEventListener: noop, removeEventListener: noop,
  body: makeNode('body'), documentElement: makeNode('html'), hidden: false,
};
globalThis.document = document;
globalThis.window = globalThis;
globalThis.requestAnimationFrame = (f) => { f(0); return 1; };
globalThis.cancelAnimationFrame = noop;
globalThis.getComputedStyle = () => ({ getPropertyValue: () => '' });
globalThis.matchMedia = () => ({ matches: false, addEventListener: noop });
globalThis.localStorage = { getItem: () => null, setItem: noop, removeItem: noop };
globalThis.navigator = { userAgent: 'node' };
globalThis.alert = noop;
module.exports = { makeNode };
