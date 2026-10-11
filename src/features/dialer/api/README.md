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
