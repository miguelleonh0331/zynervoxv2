# Módulo: whatsapp

## Propósito
Integración con la WhatsApp Cloud API de Meta: webhook firmado, envío de mensajes
(texto/media/plantilla), descarga de media, ventana de servicio de 24 h con
autorizaciones de un solo uso, sincronización y construcción de plantillas.

## Alcance
- Incluye: `GET/POST /webhook/meta`, validación `X-Hub-Signature-256` con comparación
  constante, resolución de empresa por `phone_number_id`/WABA ID, `sendMeta`,
  descarga y persistencia de media, `window_overrides`, plantillas Meta
  (tabla `plantillas`, upload de imágenes, sincronización 15 min, evento `plantilla:estado`).
- No incluye: clasificación de entrantes (`conversaciones`) ni colas de envío masivo
  (`broadcasts`).

## Estructura
- `api/`: webhook y rutas de plantillas.
- `services/`: sendMeta, verificación de firma, descarga de media, ventana de servicio.
- `models/`: acceso a `plantillas`, `window_overrides`, lectura de líneas/credenciales.
- `tests/`: pruebas del módulo.

## Decisiones heredadas
- El webhook responde 200 rápido y rechaza firmas inválidas con 401 (comparación
  constante sobre el cuerpo crudo).
- Líneas desconocidas (phone_number_id no registrado): rechazadas sin crear datos.
- La ventana de servicio usa un margen propio (20 h por defecto, configurable) para no
  llegar al borde de las 24 h reales de Meta; pasado el margen, el envío requiere
  `window_overrides` de un solo uso, que caduca y no se reutiliza.
- Estados de plantilla se sincronizan en tiempo real vía webhook pero no alteran la
  salud del número (eso es `salud`).
- Los HTML reciben la ruta base mediante `__APP_BASE__`; el marcador del nombre de la
  variable se protege al sustituir (incidente documentado en el original).
