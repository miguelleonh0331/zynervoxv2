# Contrato: signaling

Expone `registerSignaling(ctx)` sobre un servidor HTTP existente. Gestiona salas,
viewers, autorización periódica, negociación WebRTC y auditoría de audio/cámara.
Requiere servicios públicos de auth/users, utilidades de límites y pool MySQL. Un
agente solo puede tener una sesión de audio y una de cámara simultáneas.
