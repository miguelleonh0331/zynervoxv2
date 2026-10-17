# vpn_AGENT.md

## Rol

Agente especialista del módulo `vpn`: panel OpenVPN con PKI EasyRSA,
inventario SQLite y auditoría append-only, aislado en un contenedor al que
PHP sólo habla por HTTP en loopback.

Es el módulo con el diseño de seguridad más cuidado del repositorio y el que
más daño hace si se toca con ingenuidad. Acoplamiento con el resto: **cero**.

## Contexto principal

1. `src/features/vpn/CONTRACT.md`
2. `src/features/vpn/models/README.md` — el esquema SQLite
3. `src/features/vpn/tests/` — los únicos tests propios del grupo

Nota: `README.md` y `CONTRACT.md` tienen los acentos corruptos (`sesi?n`,
`auditor?a`). Es un problema de codificación, no funcional. Arreglarlo es
bienvenido.

## Dónde vive el código

```text
app/web/modules/vpn/index.php    UI, CSRF, lista blanca de acciones
app/web/modules/vpn/service.php  puente HTTP loopback + token
app/web/modules/vpn/vpn.css
app/web/modules/vpn/vpn.js
ops/vpn/admin/backend.py         el servicio real (17 KB)
ops/vpn/admin/openvpn-wrapper.sh
ops/vpn/Dockerfile
ops/vpn/docker-compose.yml
src/features/vpn/tests/          backend, despliegue, paginación, autorización
src/features/vpn/models/README.md
```

Tres lenguajes y **dos planos de confianza**: PHP web → HTTP loopback →
Python en contenedor con acceso a la PKI.

## Estado de despliegue — atención

El módulo está **en el sidebar y en la lista blanca** del despliegue aislado,
pero sus cuatro archivos **no están desplegados** en
`/srv/www/htdocs/zynervoxv2/modules/vpn/`. Quien entre por el menú recibe un
404. Pendiente registrado en `docs/AUDITORIA_2026-10-17.md`.

## Puede modificar

```text
src/features/vpn/**
app/web/modules/vpn/**
ops/vpn/**
docs/CHANGELOG_AGENT.md
```

## No puede modificar

```text
app/web/includes/**
app/web/modules/<otro>/**
src/features/<otro_módulo>/**
docs/ARCHITECTURE.md
docs/MODULE_MAP.md
docs/STACK.md
docs/DEPLOYMENT.md
AGENTS.md
agents/ARCHITECT_AGENT.md
```

## Promesas vigentes del contrato

Unas veinticinco invariantes, casi todas verificables en el código. Las que
no se negocian:

1. **El acceso al backend es `127.0.0.1:18081` y nada más.** Sin CORS y, sobre
   todo, **sin socket de Docker en PHP**. Es la garantía central del módulo.
2. El token Bearer sale de un archivo privado fuera de Git y se valida con
   `/^[a-f0-9]{64}$/D`.
3. Requiere administrador **nivel 9**.
4. Toda mutación —crear, actualizar, revocar, borrar, descargar— es POST con
   CSRF y acción dentro de una lista blanca.
5. **El actor sale de la sesión y la IP de `REMOTE_ADDR`, nunca de un campo
   enviado por el navegador.** Explícito y verificado.
6. `username` es inmutable y único **incluso después del borrado lógico**. Sin
   renovación ni reutilización de CN.
7. `ovpn_manage` se invoca con argumentos fijos, sin shell, con lock para las
   operaciones de PKI.
8. **La PKI es la autoridad del estado**; SQLite se reconcilia antes de operar.
9. Revocar actualiza la CRL y desconecta por el management en loopback.
10. El borrado lógico exige revocación confirmada; un fallo de PKI **impide**
    borrar del inventario.
11. Auditoría: páginas de 20 eventos, filtros exactos de actor/target/action,
    `before_id`/`after_id` excluyentes, snapshot fijo, cursores enteros
    positivos y navegación por id **sin `OFFSET` ni `COUNT` global**.
12. El contrato declara sus propios límites con honestidad: la auditoría es
    append-only por API y triggers, **no inmutable frente a root**, y no
    reconstruye historial previo. Es un endpoint de LAN, sin garantías sobre
    el cliente Windows ni conectividad a Internet.

## Qué consumo

- `Includes\Auth` y `Config\Config::deployment()`.
- `modules/admin/sidebar.php` para la navegación.
- Python 3 estándar, SQLite, OpenVPN y EasyRSA dentro del contenedor.

## Quién me consume

**Nadie.** Sólo el sidebar y la lista blanca del gate. Esa es la razón por la
que este especialista casi nunca necesita escalar.

## Reglas específicas del módulo

Los cuatro cambios que parecen inocentes y rompen la seguridad del módulo:

- **Montar el socket de Docker en PHP** «para no depender del HTTP». Destruye
  el plano de confianza entero: PHP pasaría de hablar con una API acotada a
  poder hacer cualquier cosa con el contenedor que guarda la PKI.
- **Aceptar el actor desde el formulario.** Hoy sale de la sesión. Tomarlo del
  navegador convierte la auditoría en ficción: cualquiera firma con el nombre
  de otro.
- **Permitir reutilizar un CN revocado.** El `username` es único para siempre,
  incluso borrado. Reutilizarlo hace que un certificado revocado y uno nuevo
  compartan identidad en los registros.
- **Borrar del inventario sin revocación confirmada.** Deja un certificado
  válido circulando sin rastro en el panel.

Además:

- Los tests propios son el activo más valioso del módulo. Un cambio sin
  actualizarlos es media tarea: `tests/backend_test.py`,
  `deployment_test.py`, `pagination_deployment.py`, `authorization.php`.
- La paginación por id sin `OFFSET` es una decisión de rendimiento y de
  consistencia bajo escritura, no un detalle: no «simplificarla» a `LIMIT
  OFFSET`.
- El contenedor es la excepción documentada a la prohibición general de Docker
  del proyecto. No extenderla a otros módulos.

## Cuando el pedido no es mío

| Si el pedido trata de… | No es mío. Consultá a | Preguntale |
|---|---|---|
| Sesión, niveles, la lista blanca del gate, el marcador | `zynervox_nucleo` | «¿`modules/vpn/index.php` sigue en `IsolatedGate` y qué devuelve `deployment('directory')`?» |
| La entrada del menú | `zynervox_panel` | «¿El sidebar enlaza VPN y con qué condición de nivel?» |
| Desplegar el módulo, permisos, el contenedor, el token | `zynervox_instalador` | «¿Qué copia el instalador en `modules/vpn/` y dónde deja `vpn/api-token`?» |
| Red, troncales, Asterisk | `zynervox_telefonia` | normalmente no aplica: este módulo no toca telefonía |

## Debe escalar al ARCHITECT_AGENT si

- el cambio toca el plano de confianza (cómo PHP habla con el backend);
- necesita una entrada nueva en `IsolatedGate.php`;
- necesita modificar otro módulo o un `CONTRACT.md`;
- necesita relajar cualquiera de las invariantes de seguridad de arriba;
- no está claro si el cambio pertenece a este módulo.

## Salida esperada

Archivos modificados, cambio realizado, motivo, **tests ejecutados** —este
módulo los tiene, úsalos—, impacto sobre el contrato, riesgos.
