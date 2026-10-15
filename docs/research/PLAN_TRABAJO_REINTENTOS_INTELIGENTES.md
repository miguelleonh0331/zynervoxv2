# Plan de trabajo: reintentos inteligentes de Bot IVR — validado contra el mercado

Fecha: 2026-10-14. Estado: documento de investigación y planificación.
**No contiene código ni instala nada.** Es la hoja de ruta para el ingeniero
que implementará el motor, construida sobre `REINTENTOS_INTELIGENTES_BOT_IVR.md`
(mismo proyecto, mismo folder) más investigación externa de mercado.

## 0. Contexto operativo de la sesión (para retomar sin perder nada)

- **Carpeta de trabajo (local):** `E:\servidores\zynerdesk\proyectos\zynervoxv2`
- **Punto de control (git):** rama `main`, commit `35863db`
  ("Document smart retry investigation and compatibility handoff"),
  6 commits adelante de `origin/main` (sin pushear). Restauración segura si
  algo sale mal:
  ```
  git restore --source=35863db --staged --worktree .
  ```
  (requiere árbol limpio antes de ejecutar; genera un commit nuevo de
  restauración — nunca `reset --hard`.)
- **Cambios pendientes sin comitear al abrir este punto de control:**
  - `app/web/includes/IsolatedGate.php` modificado — **ajeno a este plan,
    no tocar.**
  - Este mismo archivo (`PLAN_TRABAJO_REINTENTOS_INTELIGENTES.md`), sin
    trackear hasta que el usuario autorice el commit.
- **Servidor remoto:** `mirmidon` (SSH ed25519, sin password, skill
  `zynertools`). Verificar al retomar: `python tools/health.py mirmidon`.
- **Flujo de despliegue (recordatorio fijo):**
  1. Edición solo en local (ruta de arriba).
  2. Sync automático a `mirmidon:/mnt/zynervoxv2-fuente/` (origen; no tocar,
     no verificar manualmente).
  3. Deploy a `/srv/www/htdocs/zynervoxv2/` (producción) solo con la palabra
     **"procede"**.
  4. Verificar archivo desplegado + funcionalidad real.
  5. Commit + push → `github.com/miguelleonh0331/zynervoxv2`.
- **Optimus:** encendido para este proyecto (Ultracave + Ponytail full).
  Verificar al retomar: `python optimus.py status --project
  "E:\servidores\zynerdesk\proyectos\zynervoxv2"`.
- **Nunca:** campaña 50 ni su lista productiva para pruebas; saltar la
  Fase 3; `reset --hard`/`clean -fd` sin autorización explícita; tocar
  `IsolatedGate.php`.

El paso a paso detallado (Fase 0 a Fase 5) está en la sección 2 de este
mismo documento, sin cambios.

---

Este documento responde dos preguntas:
1. ¿Lo que ya se diseñó en el documento original coincide con cómo lo resuelven
   los sistemas comerciales (Genesys, Amazon Connect, Exotel, ViciDial)?
2. ¿Qué pasos concretos, en qué orden, debe seguir el ingeniero para pasar de
   la investigación a un motor funcionando, sin romper nada en producción?

---

## 1. Veredicto rápido: ¿el documento original está bien orientado?

**Sí.** Las decisiones ya tomadas en `REINTENTOS_INTELIGENTES_BOT_IVR.md`
coinciden con las prácticas de los sistemas líderes del mercado. No hay que
cambiar el enfoque, solo hay que ejecutarlo con algunos ajustes menores que
el mercado ya resolvió y que conviene copiar. Detalle punto por punto abajo.

| Decisión del doc original | ¿Qué hace el mercado? | Veredicto |
|---|---|---|
| Pesos por estado (NEW, NO_RESPONDE_F1, AMD_HANGUP) | Nadie grande pondera por estado de forma proporcional; usan **límites por disposición** (intentos + espera por resultado). Pesos proporcionales sí existen pero en *distribución de leads entre agentes*, no en selección de reintento. | **Innovación razonable, no hay receta estándar que copiar 1:1.** Hay que justificar bien los valores (fase A ya lo exige). |
| Selección proporcional para evitar inanición de estados de bajo peso | ViciDial **no resuelve este problema**: solo mezcla "leads frescos vs. pool de reciclado" en porcentaje fijo (60/40), sin ponderar entre disposiciones. Es un hueco real del mercado. | **El documento original va más lejos que ViciDial; mantenerlo, documentar bien el weighted round-robin.** |
| Límite global + límite por ventana temporal, por teléfono | Genesys: límite por contacto Y por número de teléfono, con reseteo diario/nunca/personalizado 2-30 días. Exotel: 0-3 reintentos con backoff lineal o exponencial. | **Coincide.** Adoptar el patrón de Genesys: dos contadores independientes (por contacto, por número) con política de reseteo explícita. |
| Espera antes de volver a llamar, por estado | ViciDial: Busy=5min/5 intentos, No Answer=1h/3 intentos, Voicemail=2-4h/1-2 intentos, con topes totales (12-15). Genesys: 5-480 min configurable por disposición. | **Coincide y da rangos de referencia** (no como norma, como punto de partida razonable). |
| Horario y zona horaria explícitos | Estándar en toda la industria (Genesys, Amazon Connect, Exotel). Amazon Connect además separa ventanas de comunicación por regulación. | **Coincide.** |
| Campaña en espera (no finalizada) cuando hay reintentos futuros | Mismo patrón en Amazon Connect y Genesys: campaña "activa con trabajo pendiente" vs. "agotada". | **Coincide.** |
| Dashboard con caché, sin rehacer SQL por request | No es una práctica de la industria del dialer en sí, es una decisión de arquitectura propia del proyecto. Válida y ya resuelta en Fase anterior. | **Mantener sin cambios.** |

### 1.1. Lo que el mercado hace y el documento original NO menciona todavía

Esto no invalida el documento; son refuerzos que conviene añadir al diseño final:

1. **Límite de abandono legal (TCPA, EE. UU., no aplica igual en Perú pero es
   buena práctica técnica):** máximo 3% de llamadas contestadas por humano sin
   conectar a un agente/IVR en 2 segundos, medido por campaña en ventanas de
   30 días. Zynervox es IVR automatizado (no transfiere a agente humano en la
   mayoría de flujos), así que la exposición legal es distinta, pero el
   **principio técnico aplica igual**: no sobrecargar de llamadas repetidas a
   la misma persona en poco tiempo. Debe quedar como límite de diseño, no como
   checkbox legal.
2. **AMD tiene una tasa de falsos positivos documentada de 5-20%** (Asterisk
   estándar está en el rango alto, 10-20%). Esto confirma lo que ya advertía
   el documento original en la sección 5.3: *"no equiparar ANSWER con contacto
   efectivo"* y *"no asumir que AMD_HANGUP demuestra una máquina"*. El mercado
   respalda exactamente esa cautela. Recomendación nueva: cuando el motor
   decida el peso/prioridad de AMD_HANGUP, considerar que 1 de cada 6-10
   "máquinas detectadas" puede ser una persona real a la que se le cortó la
   llamada. No usarlo como exclusión definitiva, usarlo como estado de reintento
   con prioridad media, nunca como estado terminal inmediato.
3. **Exclusiones del conteo de intentos:** Genesys no cuenta contra el límite
   los bloqueos por horario, números inválidos, DNC (no llamar) y llamadas
   saltadas por preview. Zynervox debería definir su propia lista de
   "intentos que no cuentan" (p. ej. error técnico de originación, falla de
   confirmación AMI/SSH) — esto ya está insinuado en la sección 7 del
   documento original ("separar fallos técnicos de contacto fallido") pero
   conviene convertirlo en una tabla explícita de exclusiones antes de programar.
4. **Procesamiento de múltiples números por contacto:** si en el futuro un
   lead tiene más de un teléfono, Genesys agota todos los intentos del primer
   número antes de pasar al segundo. Zynervox hoy no tiene este caso (según
   lo investigado, es un teléfono por lead), pero si aparece, ya hay un patrón
   de referencia.

### 1.2. Riesgos detectados en el sistema propio que el mercado ayuda a confirmar

- La sección 5.1 del documento original señala que `dialCandidates` usa
  `NOT EXISTS` de intento previo dentro del mismo `dial_run_id` como barrera.
  Esto es frágil para reintentos **porque el mercado siempre separa el conteo
  de intentos del ciclo de ejecución (run)**: Genesys y Exotel cuentan
  intentos por contacto/número de forma persistente, no por sesión de
  campaña. Confirma que la condición 3 debe rediseñarse como lo indica el
  documento (no es opcional, es el bloqueador #1 de esta funcionalidad).
- La inexistencia de un estado `waiting` persistido (sección 5.2) es
  consistente con cómo Amazon Connect y Genesys lo resuelven: la campaña
  queda "activa" y el trabajo futuro se consulta por fecha, no por un estado
  especial. Confirma la opción recomendada en el documento original
  ("conservar state=running y añadir próxima fecha").
- El uso de AMD como filtro automático de contacto es la **fuente de riesgo
  más alta** según la evidencia de mercado (hasta 20% de error). Cualquier
  valor de peso que el ingeniero proponga para AMD_HANGUP debe ir acompañado
  de una nota de riesgo y, si es posible, de una revisión manual de muestra
  antes de fijar su peso definitivo.

---

## 2. Plan de trabajo para el ingeniero — paso a paso

**Regla de oro: cada fase termina en un documento o una prueba, no en código
desplegado.** No avanzar de fase sin el "listo para continuar" del usuario.

### Fase 0 — Preparación (30-60 min)

1. Leer, en este orden:
   - `docs/research/REINTENTOS_INTELIGENTES_BOT_IVR.md` (documento madre, ya existe)
   - Este documento (`PLAN_TRABAJO_REINTENTOS_INTELIGENTES.md`)
   - `AGENTS.md`, `docs/MODULE_MAP.md`
   - `agents/ARCHITECT_AGENT.md`
   - `src/features/bot_ivr/README.md`, `CONTRACT.md`, `agents/bot_ivr_AGENT.md`
2. Confirmar estado de Git: rama, HEAD, diferencia con `origin/main`. No tocar
   `app/web/includes/IsolatedGate.php` (cambio ajeno, ya documentado en el doc madre).
3. Confirmar en el VPS (mirmidon) que la revisión desplegada coincide con la
   local antes de asumir nada sobre el comportamiento real. Usar zynertools,
   sin cambios remotos.

**Entregable de la Fase 0:** ninguno formal, es checklist de arranque.

---

### Fase 1 — Investigación dirigida (la fase más larga, 100% lectura y SQL de solo lectura)

Objetivo: contestar, con evidencia (archivo + función + línea, o resultado de
consulta), las 15 preguntas de la sección 8 del documento madre. Usar esta
tabla como checklist de avance:

| # | Pregunta | Dónde buscar la respuesta | Estado |
|---|---|---|---|
| 1 | Opt-in y rollback | `dial_runs.php`, `campaigns_service.php`, migración 012 | ☐ |
| 2 | Persistencia de reglas/fechas/límites | Esquema real (`SHOW CREATE TABLE`), migraciones 005/012 | ☐ |
| 3 | Estados: intermedios/efectivos/definitivos/reintentables | `ivr_graph_service.php`, flujo publicado real de campaña 50 | ☐ |
| 4 | Cambios tardíos (PREANSWER después de FINISH) | `preanswer.php`, `calls.php` (`finishCall`) | ☐ |
| 5 | Orden de locks | `dial_runs.php` (`claimDialLead`, `transitionDialRun`) | ☐ |
| 6 | Citas vs. reciclado automático | `campaign_edit.php`, columnas scheduled/start_time/end_time | ☐ |
| 7 | Canales/anexos | `list_call_worker.php`, `baresip_origin.php`, pool 4002-4050 | ☐ |
| 8 | Mismo teléfono en dos listas/campañas | `leads.php` (`importLeads`), esquema de leads | ☐ |
| 9 | Cálculo eficiente del próximo vencimiento | Índice `(list_id,status,next_call_at,lead_id)`, EXPLAIN | ☐ |
| 10 | Comportamiento de Stop | `list_call_worker.php` (`bot_list_call_stop`) | ☐ |
| 11 | Horario antes del claim | `dial_runs.php`, config_json, zona horaria | ☐ |
| 12 | Compatibilidad de snapshots antiguos | `campaigns_service.php`, versión de config_json | ☐ |
| 13 | Reutilización de audios/TTS | `list_edit.php`, firma de audio | ☐ |
| 14 | Publicación de espera/próxima fecha en monitor | `campaign_monitor_worker.php`, `campaign_monitor_cache.php` | ☐ |
| 15 | Progreso: únicos/acumulados/futuros/efectivos | `campaign-monitor-db.php` (test existente) | ☐ |

Reglas durante esta fase (ya definidas en el doc madre, se repiten aquí por
seguridad):
- Solo lecturas. Nada de `EXPLAIN ANALYZE` sin evaluar el costo antes.
- No usar la campaña 50 ni su lista productiva para insertar datos de prueba.
- No ejecutar los instaladores (`bot-ivr-baresip.sh`, `call-audit.sh`, etc.)
  para "ver qué pasa". Son instaladores, no herramientas de diagnóstico.

**Entregable de la Fase 1:** la tabla anterior completa, con evidencia, dentro
del documento de análisis de la Fase 2 (no es un archivo separado).

---

### Fase 2 — Documento de análisis y diseño (el entregable grande)

Crear `docs/research/REINTENTOS_INTELIGENTES_ANALISIS_COMPATIBILIDAD.md`
(nombre exacto exigido por el documento madre, sección 11).

Debe incluir, en este orden, y nada menos:

1. **Fecha y revisión investigada** (commit HEAD, fecha de la inspección en VPS).
2. **Hallazgos de la Fase 1**, con archivo/función/línea exacta para cada
   una de las 15 respuestas.
3. **Diagrama de estados** de un lead: NEW → (intentos) → estado intermedio →
   estado final/reintentable → fin de ciclo. Incluir dónde entra AMD_HANGUP y
   por qué no es un estado terminal automático (ver sección 1.1 de este plan).
4. **Decisiones confirmadas vs. pendientes** (copiar la lista de la sección 2
   del documento madre y marcar cuáles ya se resolvieron en la Fase 1).
5. **Modelo de datos**: qué tablas/columnas nuevas hacen falta (reglas por
   estado: habilitado, peso, espera, máximo de reintentos; contador por
   teléfono y por ventana temporal). Proponer la migración aditiva (solo el
   diseño, no ejecutarla).
6. **Algoritmo de selección final**, con el weighted round-robin elegido y
   justificación de por qué resuelve el hueco que ViciDial no resuelve
   (sección 1.1 de este plan). Incluir pseudocódigo, no código real.
7. **Matriz de cambios por ruta**: cada archivo de las tablas 4.1 a 4.5 del
   documento madre, con "cambia" / "no cambia" y por qué.
8. **Compatibilidad de runs anteriores**: cómo una campaña sin la política
   nueva sigue funcionando exactamente igual (opt-in explícito).
9. **Estrategia para clasificaciones tardías**: PREANSWER después de FINISH,
   ligada a `attempt_id`, idempotente.
10. **Plan de pruebas**: extender la tabla de la sección 9 del documento
    madre con los casos nuevos ya listados ahí (200 leads, límite agotado,
    mix ponderado, dos workers compitiendo, Stop en cada fase, etc.).
11. **Consultas e índices**: qué índice nuevo hace falta para resolver la
    pregunta 9 de la tabla de arriba sin escanear todos los leads cada
    segundo. Incluir el `EXPLAIN` esperado.
12. **Impacto en caché, canales y pool**: confirmar que el monitor sigue sin
    hacer SQL agregada desde el navegador, y que el pool de anexos 4002-4050
    no se reserva durante la espera entre reintentos.
13. **Instalación detallada paso a paso** (para la Fase C), incluyendo backup,
    orden de despliegue, cómo activar la política apagada por defecto.
14. **Observabilidad**: qué métricas nuevas debe exponer el monitor (espera,
    próxima fecha, contactos únicos vs. intentos acumulados vs. efectivos).
15. **Rollback**: cómo apagar la política nueva sin perder intentos, agendas
    ni auditoría, y qué pasa con los reintentos futuros ya calculados.
16. **Criterios de éxito**: copiar y adaptar la sección 12 del documento madre.

**Entregable de la Fase 2:** el archivo `.md` completo, listo para que el
usuario lo apruebe antes de escribir una sola línea de código.

---

### Fase 3 — Aprobación y alcance de desarrollo (checkpoint obligatorio)

1. Presentar el documento de la Fase 2 al usuario.
2. Esperar aprobación explícita de: valores iniciales de pesos, límites por
   teléfono/ventana, definición de "contacto efectivo", lista de estados
   terminales vs. reintentables.
3. **No continuar a desarrollo sin esta aprobación.** Esto es un alto
   obligatorio, no una sugerencia: el documento madre lo exige en la sección
   11, Fase B ("Solo tras resolver el análisis y contar con autorización").

---

### Fase 4 — Desarrollo en aislamiento (fuera del alcance de este documento de investigación)

Se detalla solo como referencia de lo que viene después, **no se ejecuta aquí**:
migración aditiva, cambios de contrato en `BotIvrRepository.php`, nuevo
algoritmo de selección en `dial_runs.php`, pruebas unitarias nuevas, política
inicialmente deshabilitada, validación en campaña/lista de QA separada
(nunca la campaña 50 productiva).

---

### Fase 5 — Instalación controlada (fuera del alcance de este documento)

Backup, drenado de listas, verificación de compatibilidad con workers activos,
activación gradual, monitoreo, y plan de rollback ya diseñado en la Fase 2.

---

## 3. Checklist ultra-resumido para el ingeniero (una página)

```
[ ] Fase 0: leer documentos y confirmar Git/VPS, sin tocar nada.
[ ] Fase 1: contestar las 15 preguntas con evidencia real (solo lectura).
[ ] Fase 2: escribir REINTENTOS_INTELIGENTES_ANALISIS_COMPATIBILIDAD.md completo.
[ ] Fase 3: presentar al usuario, esperar aprobación de valores y definiciones.
[ ] Fase 4: (futuro, con autorización) desarrollo aislado + pruebas.
[ ] Fase 5: (futuro, con autorización) instalación controlada + rollback listo.
```

**Nunca:** usar campaña 50 ni su lista productiva para pruebas. Nunca saltar
la Fase 3. Nunca activar la política nueva por defecto encendida.

---

## 4. Fuentes de la investigación de mercado

- Genesys Cloud, configuración de límites de intento:
  https://help.genesys.cloud/articles/configure-attempt-limits-entry/
- Genesys Cloud, mejoras de control de intentos:
  https://help.genesys.cloud/announcements/attempt-control-enhancements/
- Amazon Connect, mejores prácticas de campañas salientes:
  https://docs.aws.amazon.com/connect/latest/adminguide/outbound-campaign-best-practices.html
- Exotel, lógica de reintento de campañas:
  https://developer.exotel.com/docs/campaign-guides/campaign-retry-logic
- ViciDial, framework de reciclado de leads (matriz de disposición):
  https://dev.to/gamlin/youre-throwing-away-30-of-your-vicidial-conversions-a-lead-recycling-framework-5922
- Tasa de falsos positivos de AMD (Asterisk / industria):
  https://tryready.com/blog/dialer-answering-machine-detection-false-positive
- TCPA, regla de 3% de abandono (referencia de buena práctica técnica, no
  aplica igual en jurisdicción peruana, pero el principio de no sobrecargar
  de llamadas repetidas es válido igual):
  https://justcall.io/blog/call-abandonment-rate.html

Estas fuentes documentan prácticas de proveedores a una fecha determinada; no
son garantía de comportamiento futuro ni sustituyen la verificación propia en
el sistema de Zynervox.

---

## 5. Nota final

Este plan **no modifica ningún archivo del proyecto, no instala nada y no
autoriza llamadas reales.** Es la guía ordenada para que el ingeniero sepa
exactamente qué leer, qué preguntar, qué escribir y en qué orden, antes de
tocar una sola línea de `dial_runs.php` o de cualquier otro archivo listado
en el documento madre.
