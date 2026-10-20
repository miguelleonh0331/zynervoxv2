# Maqueta de supervisión (`app/web/demo-sup`)

Pantalla de supervisor para demostración comercial. **No es el sistema.**
No tiene PHP, ni base de datos, ni sesión, ni discador: son cuatro archivos
estáticos y una carpeta de audios.

- **Fuente**: `app/web/demo-sup/`
- **Desplegada en**: `/srv/www/htdocs/zynervoxv2/demo-sup/` (root:www, 750/640)
- **Se ve en**: `http://<host>/zynervoxv2/demo-sup/`
- **Pruebas**: `src/features/dialer/tests/console/sup-check.js` (143 comprobaciones)

Al ser estática no pasa por `IsolatedGate.php`, que sólo intercepta PHP. No
hubo que tocar la lista blanca ni ningún archivo de producción.

## Qué hay

Diez vistas, con dos modos desde la portada: **recorrido guiado** (se explica
solo, con voz) y **explorar** (navegación libre, todo responde al clic).

| Vista | Contenido |
|---|---|
| Piso en vivo | Semáforo del marcador, línea de tiempo del piso, tabla del equipo, llamadas en curso, alertas accionables |
| Campañas | Salientes y entrantes, panel de asignación agentes × campañas, agentes mixtos, cola de entrantes |
| Carga de base | Archivo → mapeo asistido → validación → destino, más historial con botón de revertir |
| Segmentación | Constructor con contador en vivo, reglas de reintento, mapa de calor por día y hora, salud de las listas |
| Clientes | Buscador, ficha completa, historial por canal, WhatsApp con respuesta del supervisor |
| Conversaciones | Bandeja de WhatsApp de todo el piso |
| Comunicación | Broadcast con imagen y acuse de recibo, chat interno, manos levantadas |
| Calidad | Grabaciones, puntaje por criterio, momentos para la base de conocimiento |
| Equipo | Asistencia, pausas contra el tope, ranking, seguimiento de coaching |
| Reportes | Los 20 indicadores del motor, embudo, producto, curva contra la meta |
| Modo TV | Wallboard proyectable (único fondo oscuro, a propósito) |

## Asignación de agentes

Son **dos cosas distintas**, y la maqueta las separa igual que la base:

| | Qué significa | Quién decide | Dónde vive de verdad |
|---|---|---|---|
| **Habilitado en** | En qué campañas *puede* trabajar | El supervisor | `v2_dialer_agent_campaigns` (relación: un agente, muchas campañas) |
| **Trabajando en** | En cuál está *ahora mismo* | El agente, al entrar | `v2_dialer_agent_status.selected_campaign_id` |

En la pantalla: `✓` habilitado, `●` trabajando ahí ahora.

Tres gestos, a propósito redundantes:

- **Mover**: arrastrar la pastilla a otra tarjeta. Sale de una, entra en la otra.
- **Sacar**: la ✕ de la pastilla, o arrastrarla a «Sin asignar».
- **Estar en dos a la vez**: marcar las dos casillas en la grilla.

La fuente única es `AGENTES[].camps`; las campañas **no** guardan su propia
lista y la derivan con `agentesDe()`. Con dos listas había que sincronizarlas
a mano y se desincronizan en cuanto alguien está en dos campañas.

**Agente mixto**: habilitado a la vez en una saliente y una entrante. Lleva
insignia `MIXTO` y la regla escrita al pie: *mientras haya alguien esperando
en la cola entrante, el marcador no le entrega llamadas salientes.* Va con
sello — el discador no tiene dirección de campaña (sólo `queue_name`), así
que las entrantes y el blending son escenografía.

## Qué tiene respaldo real y qué es escenografía

Los datos que se ven son **inventados** en los dos casos. La distinción es si
el motor actual *podría* calcularlos hoy o no.

**El motor ya lo calcula** (ver `Dialer::kpis/occupancy/abandonByLevel` y el
esquema `v2_dialer_*`): contactabilidad, conversión, abandono, ASR, AHT,
ocupación, estados de agente (`agent_intervals`: ACTIVE/PAUSED/WRAPUP),
pausas por motivo, nivel de marcado, grabaciones (`recording_path`),
embudo (`is_contact`/`is_sale`), producto y monto (`leads.custom_fields`).

**No existe en el sistema** — va con sello `PRÓXIMAMENTE` en pantalla:

- **Escucha, susurro e irrupción.** `ChanSpy` no aparece en todo el
  repositorio. Es trabajo de dialplan, no de pantalla.
- **Puntaje de calidad.** No hay tabla de scoring. (El texto transcrito del
  bot sí se persiste en `zynervox_bot_call_events.payload_json`, sin consumidor.)
- **Broadcast y chat interno.** No hay canal agente↔supervisor.

Y hay dos cosas que la pantalla muestra y la base **no tiene como entidad**:
*Conversaciones* y *Cotizaciones* en el embudo.

## La voz

Mismo esquema que `app/web/demo`: 36 frases pregrabadas en `audio/`, con el
nombre derivado del hash del propio texto (`hashTexto` en `guion.js` ==
`nombre_archivo` en el script de Python). **La página nunca llama a la API**:
funciona sin internet y no gasta créditos al reproducirse.

```
sudo -n python3 scripts/demo_voz_sup.py costo       # cuánto costaría
sudo -n python3 scripts/demo_voz_sup.py generar     # sólo lo que cambió
sudo -n python3 scripts/demo_voz_sup.py verificar   # frases sin audio
```

El script reutiliza `scripts/demo_voz.py` y sólo cambia de dónde salen los
textos. La llave vive en `/root/elebencpia.txt`, no se imprime, no se versiona
y no llega al navegador.

Lo pendiente se decide por la **existencia del archivo**, no por el índice:
como el nombre sale del texto, si el `.mp3` está, corresponde a ese texto.
Antes se comparaba contra el índice —ordenado por posición— y agregar una
frase en el medio corría la numeración y marcaba como pendientes a todas las
siguientes, ya grabadas. Para cambiar de voz hay que usar `--forzar`.

Si se corrige una frase, cambia su hash y hay que regenerar **esa sola**.
`sup-check.js` avisa: falla la comprobación «todas las frases tienen audio».

## Reglas que no se pueden romper

1. **No tocar producción.** La maqueta vive en su carpeta. `modules/agc/` y
   `modules/admin/` no se modifican para que la demostración se vea mejor.
2. **Lo que no existe lleva sello.** Si se agrega una función de adorno, va
   con `PRÓXIMAMENTE`. El valor del tablero está en que lo demás es creíble.
3. **El guion usa las funciones de verdad.** `irA()`, `abrirEscucha()`,
   `accion()` — las mismas que usa una persona. No hay un camino especial
   para la demo: lo que se narra se puede repetir a mano.
4. **La voz y el foco van en la misma cola.** `enfocar()` se encola en
   `VOZ.cola` igual que la frase. Si se aplicara al llamarlo, el recuadro
   iría una frase adelantado. Ya pasó una vez en la otra presentación.

## Pruebas

```
src/features/dialer/tests/console/run-console.sh
```

Corre las tres suites: consola del asesor (219), presentación del asesor (59)
y supervisión (143). La de supervisión navega cada vista, aprieta botones,
recorre los 17 capítulos del guion y comprueba, entre otras cosas, que ningún
foco apunte a un elemento inexistente y que ninguna frase hable de una zona
mientras se ilumina otra.
