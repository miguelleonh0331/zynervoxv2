# Módulo: salud

## Propósito
Observabilidad de los números de WhatsApp: semáforo por línea basado exclusivamente en
la respuesta oficial de Meta (calidad, estado del número, validez de conexión),
alertas abiertas, histórico y sondeo periódico escalonado por empresa.

## Alcance
- Incluye: `numero_salud` (histórico por línea), `salud_alertas`, sondeo Meta cada 30 min,
  sondeo manual con cooldown persistente, `/api/salud` y `/api/salud/global` (superadmin),
  vista privada `/salud`, actualización en tiempo real vía webhook (quality_update,
  account_update, template_status), corte automático configurable por empresa.
- No incluye: pausar envíos (eso lo aplica `broadcasts` consultando el estado que este
  módulo publica).

## Estructura
- `api/`: rutas de salud, alertas, sondeo, resolución y global.
- `services/`: normalización Green/Yellow/Red/NA, semáforo, sondeador.
- `models/`: acceso a `numero_salud` y `salud_alertas`.
- `tests/`: pruebas del módulo.

## Vista
`views/salud/index.html` — calidad, nombre verificado, estados, tier, throughput,
plataforma, conexión e histórico Meta (máx. 30 sondeos por barra, con tooltip).

## Decisiones heredadas
- Semáforo solo con datos Meta: las métricas internas antiguas ya no influyen.
- `name_status` (p. ej. `PENDING_REVIEW`) se muestra aparte y no degrada el semáforo.
- En esta etapa el módulo observa y alerta; el corte de envíos solo actúa si el
  superadmin activa `salud_corte_automatico` (predeterminado: solo avisar).
- Sondeo escalonado por empresa para no golpear Graph API en ráfaga.
