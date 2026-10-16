# Roadmap

- CRM: hoy es solo la maqueta de interfaz del escritorio de ventas
  (`modules/admin/crm_app.php` y `crm-assets/`), portada desde el proyecto
  independiente `crm-escritorio`. Mantiene los 14 formularios y su geometría,
  pero el estado vive en memoria: no hay base de datos, ni persistencia, ni
  usuarios propios. Falta definir e implementar su backend.
- Zynerdesk: descargar/publicar el agente Windows
  (`miguelleonh0331/synervox-remoteo-agent`) y conectar el flujo de alta de
  equipos. Expresamente pendiente, no implementado en esta etapa.
- Zynerdesk: SSO firmado implementado; mantener la prueba E2E al actualizar
  la imagen upstream o el proxy.
- Construir el módulo separado de Operaciones WhatsApp: conversaciones, contactos, campañas, listas y envíos.
- Ejecutar el gate E2E con una empresa, línea Meta y destinatario exclusivos de laboratorio.
- Reconstruir y publicar la imagen Zynerwaba con los overrides integrados; eliminar parches de runtime cuando la imagen verificada los sustituya.
- Etiquetar el commit Git y la imagen Docker con la misma versión y registrar sus hashes/digests.
- Probar instalación completa en un Ubuntu limpio de laboratorio.
- Convertir rutas absolutas de Asterisk en configuración externa.
- Añadir rollback automatizado y respaldo previo verificable.
- Añadir pruebas de integración sin llamadas reales.
- Versionar paquetes mediante GitHub Releases.
