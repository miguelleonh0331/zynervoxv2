# Contrato: telephony

Genera configuración PJSIP y dialplan, y solicita recarga mediante un wrapper sudo
restringido. No reinicia Asterisk y no modifica configuración global sin validación.
SIP/RTP, carriers y secretos son configuración externa por servidor.

## 2026-10-14 - Baresip 7307 compatibility origin

ARCHITECT_AGENT approval: common generated contexts include zynervoxv2-baresip, its G() answer split and origin cleanup. An explicit _7307X. route in the annex context test_cortar forwards only to the authenticated one-use token handler. No early Answer on the origin. MixMonitor begins on the outbound callee via b(); after answer the origin removes its hangup handler and exits, while the callee independently records ANSWER/FINISH and runs the existing no-native-AMD IVR. Native caller uniqueid/linkedid remain audit metadata; the reserved v2 call token remains the application call_id. Existing Local origins and unrelated dialplan contexts retain their behavior. Activate by dialplan reload, never an Asterisk restart.
