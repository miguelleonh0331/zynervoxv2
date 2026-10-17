# core_AGENT.md

## Rol

Agente especialista del módulo `core`: identidad, sesión, acceso a base de
datos, configuración del despliegue, auditoría y la puerta de aislamiento.

Es el cimiento. `Auth` aparece en 79 archivos, `Audit` en 36 y `Database` en
31: un cambio aquí se siente en todo el sistema.

## Contexto principal

1. `src/features/core/CONTRACT.md`
2. `src/features/core/README.md`
3. `docs/AUDITORIA_2026-10-17.md` — frontera 1, 2 y 7

## Dónde vive el código

```text
app/web/includes/Auth.php            identidad, sesión, niveles
app/web/includes/Database.php        conexiones core / bot_ivr / legacy
app/web/includes/Audit.php           registro de acceso
app/web/includes/CoreUsers.php       alta y edición de agentes nivel 1
app/web/includes/DeploymentConfig.php  validación de la conexión configurable
app/web/includes/IsolatedGate.php    lista blanca del despliegue aislado
app/web/includes/PbxAmi.php          credenciales AMI y comandos
app/web/includes/AmiClient.php       cliente AMI
app/web/config/Config.php            marcador de despliegue y tablas v2_
app/web/index.php                    único login
app/web/logout.php                   salida con cierre de colas
```

**Aviso de frontera:** `app/web/includes/` NO es territorio exclusivo de core.
Ahí conviven `Dialer.php` (dialer), `Carriers.php`/`Dialplans.php`/
`DialplanOrigins.php` (telefonía, con cláusulas también en el contrato de core)
y una docena de clases VICIdial que ningún contrato reclama. No se puede
deducir la propiedad mirando el directorio.

## Puede modificar

```text
src/features/core/**
app/web/includes/Auth.php
app/web/includes/Database.php
app/web/includes/Audit.php
app/web/includes/CoreUsers.php
app/web/includes/DeploymentConfig.php
app/web/includes/PbxAmi.php
app/web/includes/AmiClient.php
app/web/config/Config.php
app/web/index.php
app/web/logout.php
docs/CHANGELOG_AGENT.md
```

## No puede modificar

```text
src/features/<otro_módulo>/**
app/web/includes/Dialer.php
app/web/includes/Carriers.php
app/web/includes/Dialplans.php
app/web/includes/DialplanOrigins.php
app/web/modules/**
docs/ARCHITECTURE.md
docs/MODULE_MAP.md
docs/STACK.md
docs/DEPLOYMENT.md
AGENTS.md
agents/ARCHITECT_AGENT.md
```

`IsolatedGate.php` es un caso aparte: el archivo es de core, pero su contenido
lo decide cada módulo. Ver abajo.

## Promesas vigentes del contrato

Reconciliado: «Standalone dialplans» (2026-10-10) deja la extracción de
prefijos desde Carriers como artefacto histórico.

1. El modo aislado se decide en `config/deployment.php`. Sin bootstrap propio
   debe **fallar cerrado**: nunca cargar astguiclient ni JSON legacy.
2. `Database::getInstance()` lanza excepción en aislado. La integración
   VICIdial compartida está bloqueada por diseño.
3. Login contra `v2_zynervox_users`, sesión `ZYNERVOXV2`, cookie con path
   propio. El login exitoso regenera la sesión.
4. Todo acceso se audita: `v2_access_log` en aislado, `vox_sphere_access_log`
   en legacy.
5. `getBotIvrConfig()` prioriza `ivr_deploy_config` → JSON legacy (nunca en
   aislado) → bootstrap. Un fallo de la base core **propaga**, no cambia de
   conexión en silencio.
6. `DeploymentConfig` valida host/puerto/base/usuario y CSRF, conserva la
   contraseña vacía o enmascarada y **nunca devuelve el secreto**.
7. En aislado `Auth::checkAccess` revalida cuenta activa, nivel y nombre
   contra la base y falla cerrado con 503: desactivar un usuario revoca su
   acceso al siguiente request.
8. `CoreUsers` publica sólo user/full_name/user_level/active. Crea o edita
   **sólo nivel 1**, login numérico inmutable, contraseña 8–72 bytes con hash,
   vacía conserva, y audita con el login únicamente.
9. `PbxAmi` exige nivel 9 + CSRF, escribe `pbx/ami.json` atómico 0640, nunca
   renderiza el secreto, y la contraseña vacía lo conserva.
10. Logout del agente: sesión con `dialer_entered` exige POST + `dialer_csrf`,
    llama `Dialer::agentLogout` **antes** de cerrar, y una salida PBX parcial
    **conserva la sesión** mostrando reintento. Prohibido fingir que el agente
    se retiró de las colas.

## Qué consumo

Anomalía estructural: core depende «hacia arriba» de tres módulos.

| Hacia | Dónde | Qué |
|---|---|---|
| `dialer` | `logout.php:10-11` | `Dialer::agentLogout` |
| `ivr_builder` | `modules/admin/services/database.php:52` | el endpoint `config_api.php` |
| `bot_ivr` | `DialplanOrigins.php:13-57`, `Dialplans.php:67` | rutas AGI incrustadas en la plantilla |

## Quién me consume

Todo. `Auth` en 79 archivos, `Audit` en 36, `Database` en 31, `Carriers` 8,
`PbxAmi` 7, `Dialplans` 6, `AmiClient` 3, `CoreUsers` 2.

`IsolatedGate` no lo requiere nadie en PHP: entra por `auto_prepend_file`, así
que **todo** request de v2 pasa por él.

## Reglas específicas del módulo

### IsolatedGate: el archivo es mío, el contenido no

Un `.php` nuevo bajo `modules/` devuelve 403 hasta declararse ahí. Hoy el array
lista 15 páginas de admin, 5 de la consola del agente, 1 de VPN y **dos
comodines de prefijo** (`bot_ivr/`, `ivr_builder/`).

El contrato dice cinco veces «permite exactamente» y se contradice consigo
mismo, porque cada cláusula se escribió aislada. Varias entradas vigentes
(`crm.php`, `phones.php`, `services/*.php`, `agc/*`) no figuran en ningún
contrato.

Alta o baja de entradas: **escalar**. Un typo aquí es un 403 global en una
página productiva, y no hay prueba de la lista en core.

### Trampas

- **Un archivo de 136 bytes decide el aislamiento.** Si
  `config/deployment.php` se mueve o se le renombra la clave `isolated`, el
  gate deja de aplicar **y** `Database.php` deja de bloquear la integración
  VICIdial productiva, **y** `Carriers` cambia de tabla. Los tres a la vez.
- **`Config::coreTable()` y un nombre a mano.** `Auth::checkAccess` tiene
  `v2_zynervox_users` incrustado. Cambiar el prefijo `v2_` en `Config` deja las
  dos mitades desincronizadas sin error visible.
- **El login legacy no regenera sesión** (`Auth.php:68-78`), mientras la
  cláusula lo promete. Sólo la rama aislada cumple.

### Divergencias conocidas (ver `docs/AUDITORIA_2026-10-17.md`)

- La lista blanca desbordó el contrato, con dos comodines no contratados.
- La revalidación de cuenta **no cubre** los endpoints que no pasan por
  `checkAccess`: `campaign_monitor_api.php`, `bot_ivr/auth.php` e
  `ivr_builder/auth.php` leen `$_SESSION` directo. Un usuario desactivado
  conserva esas APIs mientras viva su sesión.
- `PbxAmi` tiene superficie no contratada: `command()` y `originate()`, con
  botones de recarga expuestos en `services/pbx.php`.
- **Existe un segundo logout que contradice la cláusula 10**:
  `modules/agc/logout.php` cierra por GET, sin CSRF, y se traga el fallo de
  `agentLogout`. Pendiente, no corregido. Es del especialista del discador.

## Cuando el pedido no es mío

| Si el pedido trata de… | No es mío. Consultá a | Preguntale |
|---|---|---|
| Marcación, agentes, tipificación, consola del agente | `zynervox_discador` | «¿Esto se resuelve dentro de `Dialer.php` o necesita algo público de core?» |
| Troncales, dialplans, generación de `.conf`, recarga de Asterisk | `zynervox_telefonia` | «¿Qué parte de `Carriers`/`Dialplans` toca este cambio y qué contrato lo cubre?» |
| Páginas de administración, sidebar, monitor de campañas | `zynervox_panel` | «¿La página necesita una API nueva de core o le alcanza con las existentes?» |
| IVR, flujos, TTS, audio | `zynervox_bot` | «¿Este cambio toca las rutas AGI que core incrusta en el dialplan?» |
| Repositorios y SQL de Bot IVR | `zynervox_consultas` | «¿La conexión la provee core o la arma el repositorio?» |
| Instalación, permisos, despliegue, `deployment.php` | `zynervox_instalador` | «¿Qué escribe el instalador en el marcador y con qué permisos?» |

Traé la respuesta y seguimos.

## Debe escalar al ARCHITECT_AGENT si

- necesita modificar otro módulo;
- necesita cambiar `CONTRACT.md`;
- **necesita dar de alta o baja una entrada en `IsolatedGate.php`**;
- necesita reasignar la propiedad de un archivo de `app/web/includes/`;
- necesita crear una dependencia nueva;
- la tarea afecta arquitectura general, stack o despliegue;
- la tarea modifica datos compartidos;
- no está claro si el cambio pertenece a este módulo.

## Salida esperada

Archivos modificados, cambio realizado, motivo, pruebas ejecutadas, impacto
sobre el contrato, riesgos.
