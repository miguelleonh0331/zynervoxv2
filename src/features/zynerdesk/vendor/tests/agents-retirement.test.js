"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const { createAgentsService } = require("../src/features/agents");

test("retirar es idempotente y conserva el primer administrador", async () => {
  const calls = [];
  const pool = { query: async (sql, params) => {
    calls.push({ sql, params });
    return [{ affectedRows: 1 }];
  } };
  const service = createAgentsService({ pool });

  assert.equal(await service.setRetired("pc-01", 7, true), true);
  assert.match(calls[0].sql, /COALESCE\(retired_at, NOW\(\)\)/);
  assert.match(calls[0].sql, /COALESCE\(retired_by_user_id, \?\)/);
  assert.deepEqual(calls[0].params, [7, "pc-01"]);
});

test("restaurar limpia las marcas de retiro", async () => {
  const calls = [];
  const pool = { query: async (sql, params) => {
    calls.push({ sql, params });
    return [{ affectedRows: 1 }];
  } };
  const service = createAgentsService({ pool });

  assert.equal(await service.setRetired("pc-02", 8, false), true);
  assert.match(calls[0].sql, /retired_at=NULL, retired_by_user_id=NULL/);
  assert.deepEqual(calls[0].params, ["pc-02"]);
});

test("una operacion sobre equipo inexistente devuelve false", async () => {
  let call = 0;
  const pool = { query: async () => (++call === 1 ? [{ affectedRows: 0 }] : [[]]) };
  const service = createAgentsService({ pool });
  assert.equal(await service.setRetired("missing", 1, true), false);
});

test("repetir el mismo retiro sigue respondiendo como exito", async () => {
  let call = 0;
  const pool = { query: async () => (++call === 1 ? [{ affectedRows: 0 }] : [[{ found: 1 }]]) };
  const service = createAgentsService({ pool });
  assert.equal(await service.setRetired("pc-03", 1, true), true);
});
