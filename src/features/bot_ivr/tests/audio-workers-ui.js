// Execute the real fixture page script; no browser, network, SQL or TTS calls.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const html = fs.readFileSync(process.argv[2], 'utf8');
const script = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].at(-1)[1];
const select = html.match(/<select id="list_audio_workers"[^>]*>([\s\S]*?)<\/select>/)[1];
const options = [...select.matchAll(/<option value="(\d+)"/g)].map(match => ({value: match[1]}));
const selected = select.match(/value="(\d+)" selected/)[1];
const key = 'zynervox:audio-workers:fixture-admin:50:56';

function page(storage, job = null, blockedStorage = false) {
    const elements = new Map();
    function element() {
        return {value: '', dataset: {}, options: [], listeners: {}, append() {}, replaceChildren() {},
            addEventListener(event, handler) {this.listeners[event] = handler;}};
    }
    function get(id) {
        if (!elements.has(id)) elements.set(id, element());
        return elements.get(id);
    }
    Object.assign(get('list_audio_workers'), {value: selected, options});
    const location = {href: 'http://fixture/list_edit.php?id=56&campaign_id=50', assign(target) {this.href = String(target);}};
    const context = {
        document: {hidden: false, getElementById: get, createElement: element, addEventListener() {}},
        localStorage: {
            getItem(name) {if (blockedStorage) throw Error('Unavailable'); return storage.get(name) ?? null;},
            setItem(name, value) {if (blockedStorage) throw Error('Unavailable'); storage.set(name, value);},
        },
        location, URL, setTimeout() {}, clearTimeout() {},
        fetch: () => new Promise(() => {}),
    };
    const source = job ? script.replace(/const initial = [^\n]+;/, 'const initial = ' + JSON.stringify(job) + ';') : script;
    vm.runInNewContext(source, context);
    return {speed: get('list_audio_workers'), provider: get('list_audio_provider'), location};
}

const storage = new Map();
let current = page(storage);
assert.equal(current.speed.value, '15', 'Last job speed must be initial value');
current.speed.value = '35';
current.speed.listeners.change();
assert.equal(storage.get(key), '35');
current.provider.value = 'ws:7';
current.provider.listeners.change();
assert.equal(new URL(current.location.href).searchParams.get('tts'), 'ws:7');
assert.equal(page(storage).speed.value, '35', 'Provider reload must preserve speed');
assert.equal(page(storage).speed.disabled, false);
storage.set(key, '7');
assert.equal(page(storage).speed.value, '15', 'Invalid saved option must be ignored');
storage.clear();
storage.set('zynervox:audio-workers:other-admin:50:56', '60');
storage.set('zynervox:audio-workers:fixture-admin:50:57', '60');
assert.equal(page(storage).speed.value, '15', 'Preferences must be scoped to account/list');
assert.equal(page(storage, null, true).speed.value, '15', 'Unavailable storage must preserve server fallback');
storage.set(key, '35');
for (const state of ['starting', 'running']) {
    const active = page(storage, {state, workers: 20, active: true, job_id: 'fixture'});
    assert.equal(active.speed.value, '20', 'Active job must display actual workers');
    assert.equal(active.speed.disabled, true);
}
console.log('PASS: speed change/save/reload, provider reload, account/list scope, invalid/unavailable storage and active job workers');
