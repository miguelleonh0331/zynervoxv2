function flowMarkdown(current) {
    const entries = Object.entries(current.nodes || {});
    const refs = new Map(entries.map(([id], i) => [id, 'node' + i]));
    const label = value => Array.from(String(value ?? '')).map(char => /[\p{L}\p{N} _.,:-]/u.test(char) ? char : '#' + char.codePointAt(0) + ';').join('');
    const diagram = ['flowchart TD'];
    if (refs.has(current.start)) diagram.push('    entry(["Inicio"]) --> ' + refs.get(current.start));
    entries.forEach(([id, node]) => diagram.push('    ' + refs.get(id) + '["' + label(id + ' · ' + node.type) + '"]'));
    entries.forEach(([id, node]) => {
        const connect = (target, text) => {
            if (refs.has(target)) diagram.push('    ' + refs.get(id) + ' -->|"' + label(text) + '"| ' + refs.get(target));
        };
        connect(node.next, 'Siguiente / válido');
        connect(node.fallback, 'Alternativa / timeout / error');
        Object.entries(node.edges || {}).forEach(([key, target]) => connect(target, 'DTMF ' + key));
        (node.stt_intents || []).forEach(intent => connect(intent.target, 'STT ' + intent.id + ': ' + (intent.phrases || []).join(', ')));
        (node.ranges || []).forEach((range, i) => connect(range.target, 'Rango ' + (i + 1) + ': ' + JSON.stringify(range)));
    });
    const json = JSON.stringify(current, null, 2);
    const fence = '`'.repeat(Math.max(3, ...Array.from(json.matchAll(/`+/g), match => match[0].length + 1)));
    return '# IVR ' + String(current.flow_code || '') + ' — ' + String(current.name || '').replace(/[\r\n]/g, ' ') + '\n\n'
        + 'Exportación del estado actual del editor, incluidos cambios sin publicar.\n\n'
        + '## Diagrama\n\n```mermaid\n' + diagram.join('\n') + '\n```\n\n'
        + '## Configuración de nodos y conexiones\n\n' + fence + 'json\n' + json + '\n' + fence + '\n';
}

function exportFlowMarkdown() {
    if (!Object.keys(flow.nodes || {}).length) return alert('El flujo no tiene nodos para exportar.');
    const current = {...flow, flow_code: flowCode, name: $('flowName').value.trim(), start: $('start').value,
        list_id: $('flowList').value ? Number($('flowList').value) : null};
    const url = URL.createObjectURL(new Blob([flowMarkdown(current)], {type: 'text/markdown;charset=utf-8'}));
    const link = document.createElement('a');
    link.href = url;
    link.download = 'flujo-' + String(flowCode).replace(/[^a-z0-9_-]/gi, '_') + '.md';
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
