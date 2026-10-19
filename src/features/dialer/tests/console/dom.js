'use strict';
/* Mini-DOM suficiente para ejecutar crm.js fuera de un navegador. */
const VOID = new Set(['meta', 'link', 'input', 'br', 'hr', 'img']);

class TextNode {
  constructor(t) { this.nodeType = 3; this.data = String(t); this.parentNode = null; }
  get textContent() { return this.data; }
  get tagName() { return null; }
  get childNodes() { return []; }
}

class El {
  constructor(tag) {
    this.nodeType = 1;
    this.tagName = String(tag).toUpperCase();
    this.childNodes = []; this.parentNode = null;
    this.style = {setProperty(k, v) { this[k] = v; }};
    this.dataset = {}; this._attrs = {}; this._cls = new Set(); this._listeners = {};
    this._text = ''; this.checked = false; this.disabled = false; this.hidden = false; this.selected = false;
  }
  _adopt(n) { const x = (n instanceof El || n instanceof TextNode) ? n : new TextNode(n); x.parentNode = this; return x; }
  // Insertar un nodo que ya tiene padre lo MUEVE, como en el navegador. Sin
  // esto quedaba duplicado en los dos sitios y las pruebas no veían los
  // traslados (p. ej. la hoja de tipificación yendo y viniendo de la capa).
  append(...nodes) {
    for (const n of nodes) {
      if (n === null || n === undefined || n === false) continue;
      if (n && n.parentNode && Array.isArray(n.parentNode.childNodes)) {
        const i = n.parentNode.childNodes.indexOf(n);
        if (i >= 0) n.parentNode.childNodes.splice(i, 1);
      }
      this.childNodes.push(this._adopt(n));
    }
  }
  replaceChildren(...nodes) {
    // Los hijos que salen quedan desvinculados, como en el DOM real.
    for (const viejo of this.childNodes) if (viejo.parentNode === this) viejo.parentNode = null;
    this.childNodes = [];
    if (this.tagName === 'SELECT') this._valueSet = undefined;
    this.append(...nodes);
  }
  get children() { return this.childNodes.filter(n => n.nodeType === 1); }
  get firstChild() { return this.childNodes[0] || null; }
  get value() {
    if (this._valueSet !== undefined) return this._valueSet;
    if (this.tagName === 'SELECT') {
      const o = this.querySelectorAll('option');
      const s = o.find(x => x.selected) || o[0];
      if (!s) return '';
      const v = s.getAttribute('value');
      return v !== null ? v : s.textContent;
    }
    if (this.tagName === 'TEXTAREA') return this.textContent;
    return '';
  }
  set value(v) { this._valueSet = String(v); }
  get textContent() { return this.childNodes.length ? this.childNodes.map(n => n.textContent).join('') : this._text; }
  set textContent(v) { this.childNodes = []; this._text = String(v); }
  get innerHTML() { return this._html || ''; }
  set innerHTML(v) { this._html = String(v); this.childNodes = []; this._text = String(v).replace(/<[^>]*>/g, ''); }
  setAttribute(k, v) {
    this._attrs[k] = String(v);
    if (k === 'class') this._cls = new Set(String(v).split(/\s+/).filter(Boolean));
    if (k === 'id') this.id = String(v);
    if (k === 'value') this._valueSet = String(v);
    if (k === 'hidden') this.hidden = true;
    if (k === 'disabled') this.disabled = true;
    if (k === 'selected') this.selected = true;
    if (k === 'checked') this.checked = true;
    if (k.startsWith('data-')) this.dataset[k.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase())] = String(v);
  }
  getAttribute(k) { return k in this._attrs ? this._attrs[k] : null; }
  get title() { return this._attrs.title || ''; }
  set title(v) { this._attrs.title = String(v); }
  get className() { return [...this._cls].join(' '); }
  set className(v) { this._cls = new Set(String(v).split(/\s+/).filter(Boolean)); this._attrs.class = String(v); }
  get classList() {
    const s = this._cls;
    return {
      add: (...c) => c.forEach(x => s.add(x)),
      remove: (...c) => c.forEach(x => s.delete(x)),
      contains: c => s.has(c),
      toggle: (c, f) => { const on = f === undefined ? !s.has(c) : !!f; on ? s.add(c) : s.delete(c); return on; },
    };
  }
  matches(sel) {
    return sel.split(',').map(s => s.trim()).some(part => {
      const simple = part.split(/\s+/).pop();
      // Se admite #id: el navegador lo entiende y el código de la consola lo
      // usa (closest('#gestionHost')); sin esto las pruebas daban por falso
      // algo que en el navegador es cierto.
      const m = simple.match(/^([a-zA-Z][\w-]*)?(#[\w-]+)?((?:\.[\w-]+)*)((?:\[[^\]]+\])*)$/);
      if (!m) return false;
      const [, tag, id, classes, attrs] = m;
      if (tag && this.tagName !== tag.toUpperCase()) return false;
      if (id && this.getAttribute('id') !== id.slice(1)) return false;
      for (const c of (classes.match(/\.[\w-]+/g) || [])) if (!this._cls.has(c.slice(1))) return false;
      for (const a of (attrs.match(/\[[^\]]+\]/g) || [])) {
        const am = a.slice(1, -1).match(/^([\w-]+)(?:=["']?([^"'\]]*)["']?)?$/);
        if (!am) return false;
        const have = this.getAttribute(am[1]);
        if (have === null) return false;
        if (am[2] !== undefined && have !== am[2]) return false;
      }
      return true;
    });
  }
  closest(sel) { let n = this; while (n) { if (n.nodeType === 1 && n.matches(sel)) return n; n = n.parentNode; } return null; }
  querySelectorAll(sel) {
    const out = [], want = sel.split(',').map(s => s.trim());
    (function walk(node) {
      for (const c of node.childNodes) if (c.nodeType === 1) {
        if (want.some(w => c.matches(w) && ancestry(c, w))) out.push(c);
        walk(c);
      }
    })(this);
    return out;
  }
  querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
  addEventListener(t, fn) { (this._listeners[t] ||= []).push(fn); }
  dispatch(type, extra) {
    const ev = Object.assign({type, target: this, preventDefault() {}, stopPropagation() {}}, extra || {});
    for (const fn of (this._listeners[type] || [])) fn.call(this, ev);
    return ev;
  }
  focus() {}
  click() { this.dispatch('click'); }
  scrollIntoView() {}
  remove() {
    if (this.parentNode) this.parentNode.childNodes = this.parentNode.childNodes.filter(n => n !== this);
    this.parentNode = null;   // queda desconectado, como en el DOM real
  }
  get clientWidth() { return 1200; }
  get clientHeight() { return 700; }
}

function ancestry(node, sel) {
  const parts = sel.trim().split(/\s+/);
  if (parts.length < 2) return true;
  let n = node.parentNode, i = parts.length - 2;
  while (n && i >= 0) { if (n.nodeType === 1 && n.matches(parts[i])) i--; n = n.parentNode; }
  return i < 0;
}

function parseHTML(html) {
  const root = new El('root'), stack = [root];
  const re = /<!--[\s\S]*?-->|<!doctype[^>]*>|<(\/?)([a-zA-Z][\w-]*)((?:\s+[^\s=/>]+(?:\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+))?)*)\s*(\/?)>|([^<]+)/gi;
  let m;
  while ((m = re.exec(html))) {
    const [, close, tag, attrs, selfClose, text] = m;
    if (text !== undefined) { const t = text.replace(/\s+/g, ' '); if (t.trim()) stack[stack.length - 1].append(new TextNode(t)); continue; }
    if (!tag) continue;
    const name = tag.toLowerCase();
    if (close) {
      for (let i = stack.length - 1; i > 0; i--) if (stack[i].tagName === name.toUpperCase()) { stack.length = i; break; }
      continue;
    }
    const node = new El(name);
    const ar = /([^\s=/>]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+)))?/g;
    let a;
    while ((a = ar.exec(attrs || ''))) node.setAttribute(a[1], a[2] !== undefined ? a[2] : (a[3] !== undefined ? a[3] : (a[4] !== undefined ? a[4] : '')));
    stack[stack.length - 1].append(node);
    if (!selfClose && !VOID.has(name)) stack.push(node);
  }
  return root;
}

function makeDocument(html) {
  const root = parseHTML(html), byId = {};
  const index = n => { for (const c of n.childNodes) if (c.nodeType === 1) { if (c.id) byId[c.id] = c; index(c); } };
  index(root);
  const origAppend = El.prototype.append;
  El.prototype.append = function (...nodes) {
    origAppend.apply(this, nodes);
    for (const n of nodes) if (n instanceof El) (function ix(x) { if (x.id) byId[x.id] = x; for (const c of x.childNodes) if (c.nodeType === 1) ix(c); })(n);
  };
  // Como en el navegador: un nodo desprendido del árbol ya no se encuentra.
  const conectado = n => { while (n) { if (n === root) return true; n = n.parentNode; } return false; };
  return {
    body: root.querySelector('body') || root,
    _root: root,
    activeElement: null,
    createElement: t => new El(t),
    // Los iconos deben nacer en el espacio de nombres de SVG: creados con
    // createElement el navegador no los dibuja. Acá se registra el espacio
    // para poder comprobarlo desde las pruebas.
    createElementNS: (ns, t) => { const n = new El(t); n.namespaceURI = ns; return n; },
    getElementById: id => {
      const n = byId[id];
      if (!n) return null;
      if (!conectado(n)) { delete byId[id]; return null; }
      return n;
    },
    querySelector: s => root.querySelector(s),
    querySelectorAll: s => root.querySelectorAll(s),
    addEventListener(t, fn) { (this._l ||= {})[t] = (this._l[t] || []).concat(fn); },
  };
}

module.exports = {El, TextNode, parseHTML, makeDocument};
