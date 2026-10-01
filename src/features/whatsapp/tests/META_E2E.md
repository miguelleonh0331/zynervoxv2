# Gate E2E con Meta

Este gate demuestra envío y recepción reales. No debe ejecutarse con una empresa,
línea, destinatario ni token de producción.

## Precondiciones

- Empresa Meta de laboratorio y aplicación en modo de prueba.
- Línea WhatsApp exclusiva de laboratorio.
- Destinatario que haya consentido recibir la prueba.
- `waba_id`, `app_id`, `phone_number_id`, access token, app secret y verify token.
- Callback HTTPS público con certificado válido:
  `<URL_PUBLICA><WHATSAPP_BASE_PATH>/webhook/meta`.
- Smoke test local en verde antes de registrar credenciales.

Los secretos se cargan desde **Empresas > Credenciales** en Zynerwaba. Nunca se
escriben en este repositorio, comandos, capturas, logs ni documentos de evidencia.

## Ejecución

1. Crear una empresa de laboratorio y registrar su línea con el `phone_number_id`.
2. Guardar las credenciales cifradas desde el panel y confirmar la consulta de salud.
3. Registrar el callback y el verify token en Meta; la verificación debe devolver el
   challenge y una prueba con token incorrecto debe responder `403`.
4. Desde el destinatario consentido, enviar un mensaje único hacia la línea.
5. Confirmar que aparece una sola conversación, contacto y mensaje entrante en la
   empresa correcta, sin datos visibles para otra empresa.
6. Responder desde la bandeja dentro de la ventana de servicio.
7. Confirmar el identificador Meta y la progresión `sent`/`delivered`; confirmar
   `read` cuando el destinatario abra el mensaje.
8. Reiniciar solo el servicio Compose `app` y comprobar que conversación, estados y
   sesión persisten.
9. Repetir el evento entrante con el mismo identificador y comprobar idempotencia:
   no debe crearse un segundo mensaje.

## Evidencia de aprobación

Registrar en el checkpoint, sin contenido personal ni secretos:

- fecha UTC, commit y URL base;
- empresa/línea usando alias no sensible;
- HTTP de verificación del webhook;
- IDs Meta entrante y saliente parcialmente enmascarados;
- estados finales y resultado de aislamiento multiempresa;
- resultado tras reinicio e idempotencia;
- limpieza o revocación de las credenciales temporales.

El gate falla si falta cualquiera de las dos direcciones, si no hay estados de Meta,
si existe duplicación o si una empresa puede consultar datos de otra.
