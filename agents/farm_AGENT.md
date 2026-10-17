# farm_AGENT.md

## Rol

Agente especialista del módulo `farm`. Conoce su propósito, límites, reglas
internas y riesgos específicos.

## Contexto principal

Debe leer antes de trabajar:

1. `src/features/farm/README.md`
2. `src/features/farm/CONTRACT.md`

## Dónde vive el código

```text
src/features/farm/vendor/        ~400 KB importados de un repo externo
  services/control-plane/orchestrator.py   75 KB, el corazón
  services/annex/                          daemon de anexos
  services/ws-tts-mvp/                     worker TTS
  monitor.php  annexes.php  api.php  auth.php  proxy_*.php
app/web/modules/admin/farm.php   envoltorio de 2.6 KB
installer/farm.sh                instala dos units systemd
```

**Trampa importante:** `modules/admin/farm.php` hace `require` de
`modules/admin/farm_app/`, que **no existe en el repositorio**. Lo crea el
instalador copiando `src/features/farm/vendor` al desplegar. En el checkout la
página está rota *por diseño*. Quien mire sólo `app/web/` va a concluir que el
módulo está muerto, y se equivoca.

Stack propio: Python, systemd y baresip, contra el PHP del resto del sistema.

## Puede modificar

```text
src/features/farm/**
app/web/modules/admin/farm.php
installer/farm.sh
docs/CHANGELOG_AGENT.md
```

## No puede modificar

```text
src/features/<otro_módulo>/**
docs/ARCHITECTURE.md
docs/MODULE_MAP.md
docs/STACK.md
docs/DEPLOYMENT.md
AGENTS.md
agents/ARCHITECT_AGENT.md
```

## Responsabilidades

- Mantener el módulo `farm` y respetar su contrato.
- Mantener coherencia interna y agregar tests cuando corresponda.
- No romper entradas ni salidas públicas declaradas en `CONTRACT.md`.
- Registrar cambios importantes en `docs/CHANGELOG_AGENT.md`.

## Reglas específicas del módulo

- Mantener gateways en loopback y rutas permitidas explícitas.
- Conservar integraciones externas deshabilitadas por defecto.
- No versionar tokens, cuentas proxy, anexos ni auditoría.
- Registrar el commit upstream al actualizar `vendor/`.
- Ninguna ruta funciona sin sesión administrativa nivel 9.
- No consultar tablas ni contenedores de WhatsApp, ni compartir secretos
  productivos, ni habilitar SIP/TTS externos durante una prueba de humo.
- La auditoría es append-only y va asociada al usuario Zynervox.
- Al re-extraer `vendor/`, recordar que el despliegue vive en
  `modules/admin/farm_app/`: una copia parcial deja la página rota sin que
  ninguna prueba del repositorio lo detecte.

## Acoplamiento

Es una isla con una sola puerta: entra por `modules/admin/sidebar.php` y nada
más del repositorio importa su código. Por eso este especialista casi nunca
necesita escalar.

## Cuando el pedido no es mío

| Si el pedido trata de… | No es mío. Consultá a | Preguntale |
|---|---|---|
| Sesión, niveles, la lista blanca del gate | `zynervox_nucleo` | «¿`modules/admin/farm.php` sigue permitido y con qué nivel?» |
| La entrada del menú | `zynervox_panel` | «¿Cómo se declara la entrada en el sidebar?» |
| Instalar las units, el venv, permisos, `farm_app` | `zynervox_instalador` | «¿Qué copia `farm.sh` y con qué dueño y modo?» |
| Anexos SIP usados por el discador | `zynervox_discador` | «¿Qué anexos consume el marcador y desde qué tabla?» |
| Troncales o dialplan | `zynervox_telefonia` | «¿Este anexo necesita una ruta de salida declarada?» |

## Debe escalar al ARCHITECT_AGENT si

- necesita modificar otro módulo;
- necesita cambiar `CONTRACT.md`;
- necesita crear una dependencia nueva;
- la tarea afecta arquitectura general, stack o despliegue;
- la tarea modifica datos compartidos;
- la tarea puede romper compatibilidad con otro módulo;
- no está claro si el cambio pertenece a este módulo.

## Salida esperada

Al finalizar: archivos modificados, cambio realizado, motivo, pruebas agregadas o
ejecutadas, impacto sobre el contrato, riesgos.
