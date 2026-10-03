'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const appPath = path.join(__dirname, '..', 'vendor', 'public', 'app.js');
const source = fs.readFileSync(appPath, 'utf8');
const indexPath = path.join(__dirname, '..', 'vendor', 'public', 'index.html');
const indexSource = fs.readFileSync(indexPath, 'utf8');

for (const endpoint of [
  '/auto-replies',
  '/assignment-reservations',
  '/classifications',
  '/metrics',
  '/mention-users',
  '/quick-replies',
]) {
  assert(
    source.includes(`optionalApi("${endpoint}"`),
    `${endpoint} must tolerate a missing optional backend route`,
  );
}

assert(
  source.includes('optionalApi(`/contacts/${state.active.id}/internal-notes`, [])'),
  'missing internal-notes must not prevent opening a conversation',
);
assert(
  source.includes('optionalApi(`/contacts/${state.active.id}/timeline`, [])'),
  'missing timeline must not prevent opening a conversation',
);
assert(
  source.includes('api(`/contacts/${state.active.id}/messages`, {'),
  'text send must use the implemented contact messages endpoint',
);
assert(!source.includes('api("/send", {'), 'legacy-missing /send endpoint must not be used');
assert(
  indexSource.includes('app.js?v=20261004-1'),
  'the fixed frontend must use a new cache-busting version',
);

console.log('inbox legacy backend compatibility regression: OK');
