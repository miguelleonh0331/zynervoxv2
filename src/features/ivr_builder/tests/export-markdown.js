const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const context = vm.createContext({});
vm.runInContext(fs.readFileSync(path.resolve(__dirname, '../../../../app/web/ivr_builder/assets/js/editor/export-markdown.js'), 'utf8'), context);
const flow = {flow_code: '02', name: 'Prueba', start: 'start', nodes: {
    start: {type: 'menu', next: 'end', fallback: 'end', edges: {'1': 'end'},
        stt_intents: [{id: 'si', phrases: ['sí "gracias"'], target: 'end'}],
        ranges: [{min: 1, max: 5, target: 'end'}], message: '```json\ntexto'},
    end: {type: 'hangup'}
}};
const before = JSON.stringify(flow);
const markdown = context.flowMarkdown(flow);
assert.equal(JSON.stringify(flow), before, 'export must not mutate the editor');
assert.match(markdown, /entry\(\["Inicio"\]\) --> node0/);
assert.equal((markdown.match(/node0 -->/g) || []).length, 5, 'all connection types exported');
assert.match(markdown, /#34;gracias#34;/, 'Mermaid labels escape quotes');
assert.ok(markdown.includes('````json\n'), 'embedded backticks cannot close JSON fence');
const encoded = markdown.split('````json\n')[1].split('\n````')[0];
assert.deepEqual(JSON.parse(encoded), flow, 'complete configuration preserved');
assert.match(context.flowMarkdown({nodes: {}}), /flowchart TD/);
console.log('PASS: all connection types, escaping, Markdown fences, full configuration and no mutation');
