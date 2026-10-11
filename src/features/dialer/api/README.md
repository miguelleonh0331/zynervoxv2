# API del módulo

La fachada pública es `Includes\Dialer` en `app/web/includes/Dialer.php`.
Los paneles administrativos y de agente aplican Auth y CSRF; no existe una
API HTTP pública sin autenticación. `dialer_agent.php?poll=1` devuelve únicamente
el estado del usuario autenticado, sin permitir mutaciones por GET.

Operación: `setEngineState`, `workerTick`, `originateNext` y `claimLead`.
Agente: `agentJoinQueue`, `agentLeaveQueue`, `agentSetPause`, `setDisposition`.
Telefonía: `originateFields`, `markAnswered`, `verifyRecording`, `markAmd`,
`closeCall`, `reconcileUnanswered` (incluye recuperación PBX_LOST).
Reportería: `kpis`, `abandonByLevel`.

El AGI es CLI-only. El worker supervisado exige lock exclusivo y no acepta
ejecución web. Los consumidores externos no acceden a helpers privados.

Ficha: `agentPanelState`, `agentCurrentLead` (únicamente usuario autenticado).
Telefonía añade `markBridged` para Gosub del agente; CONNECT prevalece al cierre.
Grabaciones: `recordings` devuelve metadatos sin rutas; `recording` entrega
file/size únicamente a consumidores de servidor que apliquen Auth 7/8/9.
HTTP: `dialer_report.php?view=recordings`, audio por ID y Range único.
