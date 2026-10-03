# Contrato: media

Define la frontera de audio y cámara bajo demanda: autorización, una sesión por tipo,
auditoría y cierre determinista. Su implementación vive temporalmente dentro de
`signaling`; no graba contenido multimedia y no expone streams sin permiso vigente.
