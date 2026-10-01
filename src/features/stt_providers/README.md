# Módulo: Stt Providers

Administra cuentas y API keys de AssemblyAI, Deepgram, ElevenLabs, Gladia,
Groq y Speechmatics desde la sesión administrativa de Zynervox.

- `vendor/`: snapshot saneado de `stt-providers`.
- `vendor/auth_bridge.php`: exige sesión Zynervox nivel 9.
- `installer/stt-providers.sh`: crea esquema/usuario MariaDB aislados.
- `app/web/modules/admin/stt_providers.php`: shell visual del menú principal.

Las API keys viven en la base propia de la instancia y solo se muestran
enmascaradas. No se incluye ninguna key real.

Origen: `https://github.com/miguelleonh0331/stt-providers`, commit
`fdbe4caf22c0345f42b67e884d74dc7c99dc75c5`.
