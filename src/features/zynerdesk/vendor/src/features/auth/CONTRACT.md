# Contrato: auth

Expone `createAuthService({ pool })`: cookies seguras, sesión vigente, rol admin,
dirección cliente y validación constante del token de agentes. Depende de `config`,
`crypto` y del pool; no conoce rutas, HTML ni señalización. Devuelve `null` cuando no
hay sesión y `false` ante credenciales de agente inválidas.
