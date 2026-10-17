# Especialistas de ZynervoxV2

Cada área del sistema tiene un especialista: un archivo con su alcance, las
promesas del contrato que no puede romper, de quién depende, y las trampas que
no se ven leyendo el código.

Se invocan por su skill `zynervox_*`. La skill es sólo la puerta: el
conocimiento vive acá, en el repositorio.

## Diccionario

No hace falta recordar el nombre exacto. Cualquiera de las palabras de la
primera columna lleva al mismo lugar.

| Si decís… | Skill | Ficha | Qué cubre |
|---|---|---|---|
| discador, marcador, dialer, consola del agente, tipificación, campañas de agentes, rellamada | `zynervox_discador` | `dialer_AGENT.md` | Motor de marcación y la consola con que el agente lo opera |
| bot, ivr, flujos, editor de flujos, tts, audio, listas automáticas, reenviar | `zynervox_bot` | `bot_ivr_AGENT.md` | Campañas automáticas, motor IVR y el constructor visual |
| núcleo, core, login, sesión, usuarios, permisos, auditoría, base de datos, aislamiento | `zynervox_nucleo` | `core_AGENT.md` | Identidad, conexiones, configuración y la puerta de aislamiento |
| telefonía, asterisk, troncales, carriers, dialplan, rutas de salida, recarga | `zynervox_telefonia` | `telephony_AGENT.md` | Generación de la configuración de Asterisk y su recarga |
| panel, administración, sidebar, menú, monitor de campañas, pantallas de admin | `zynervox_panel` | `admin_AGENT.md` | Armazón de la administración y el monitor compartido |
| consultas, repositorios, sql, queries, conexión de bot | `zynervox_consultas` | `zynervox_queries_AGENT.md` | Frontera de conexión y repositorios SQL de Bot IVR |
| vpn, openvpn, certificados, pki | `zynervox_vpn` | `vpn_AGENT.md` | Panel OpenVPN con PKI, inventario y auditoría |
| granja, farm, anexos, workers, proxies | `zynervox_granja` | `farm_AGENT.md` | Pool de anexos SIP y flota de workers |
| instalador, despliegue, permisos, instalación, rutas | `zynervox_instalador` | `installer_AGENT.md` | Instalación, despliegue y configuración del entorno |
| lista, especialistas, quién hace qué, índice | `zynervox_list` | este archivo | El índice |

## Cómo funciona el tráfico entre áreas

Cuando un pedido se sale del terreno de un especialista, **no improvisa y no
escala**: te dice a quién preguntarle y qué preguntarle, en una frase que
podés copiar. Vos consultás al otro, traés la respuesta, y el primero sigue.

Cada ficha tiene una sección «Cuando el pedido no es mío» con esa tabla.

El `ARCHITECT_AGENT` queda sólo para lo que de verdad es arquitectura: crear
módulos, cambiar un `CONTRACT.md`, mover un límite entre áreas, o dar de alta
una entrada en `IsolatedGate.php`.

## Fuera de alcance por ahora

| Área | Motivo |
|---|---|
| `whatsapp`, `zynerdesk` | Excepción de migración. Su backend corre en `zynervoxv2205`; en `zynervoxv2` sólo hay la fachada PHP. Ver `docs/AUDITORIA_2026-10-17.md`, sección 6 |
| `stt_providers` | Monolito de vendor que no se edita, más 1.4 KB de envoltorio. Ningún código versionado consume lo que administra |
| `agent` | Módulo zombi: su contrato no protege nada y lo vivo que reclama ya es del discador. Pendiente de archivar o redefinir como legacy |
| `reporting` | No son reportes: es un espejo de leads apagado. Propuesto para fusionar con `core` |
| `ivr_builder` | Tiene ficha propia de contrato, pero su especialista es `zynervox_bot`: la frontera entre ambos es porosa en los dos sentidos |

## Cómo se mantiene esto

```bash
# crear un especialista nuevo (regenera MODULE_MAP.md solo)
python3 scripts/repo_agent.py create-agent --repo /opt/zynervoxv2-fuente --module <módulo>

# regenerar el mapa
python3 scripts/repo_agent.py map --repo /opt/zynervoxv2-fuente

# validar consistencia
python3 scripts/repo_agent.py validate --repo /opt/zynervoxv2-fuente

# bitácoras (son append-only: no editarlas a mano)
python3 scripts/repo_agent.py log-decision --repo ... --title ... --context ... --decision ... --reason ...
python3 scripts/repo_agent.py log-change   --repo ... --agent ... --type ... --summary ... --reason ...
```

`docs/MODULE_MAP.md` es autogenerado. No editarlo.

Para agregar una palabra al diccionario, editá la tabla de arriba. Las skills
en `/opt/codex-web/skills/zynervox_*/` son locales de la máquina, no se
versionan, y apuntan acá.

## Estado de cada ficha

| Ficha | Contenido |
|---|---|
| `dialer_AGENT.md` | Completa, con defectos abiertos documentados |
| `core_AGENT.md` | Completa |
| `bot_ivr_AGENT.md` | Completa, cubre también `ivr_builder` |
| `admin_AGENT.md` | Completa |
| `telephony_AGENT.md` | Completa, **con el perímetro en disputa declarado** |
| `zynervox_queries_AGENT.md` | Completa |
| `vpn_AGENT.md` | Completa |
| `installer_AGENT.md` | Completa |
| `farm_AGENT.md` | Buena; heredada y ampliada |
| `whatsapp_AGENT.md`, `zynerdesk_AGENT.md` | Existen, fuera de alcance |
| `_DEFAULT_MODULE_AGENT.md` | Para los módulos sin especialista |
| `ARCHITECT_AGENT.md` | Escalamiento |
