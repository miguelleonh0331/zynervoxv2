# Contrato: web

Expone `createWebService({ send })` para servir únicamente archivos bajo `web` con
tipos conocidos. Rechaza traversal y rutas fuera de la lista admitida. No decide
autorización ni implementa endpoints de negocio.
