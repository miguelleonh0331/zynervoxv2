# Persistencia

SQLite `/etc/openvpn/admin/inventory.sqlite3` dentro del volumen privado Docker.
Schema creado idempotentemente por backend.initialize. Tablas clients y audit.
clients guarda metadatos y deleted_at; audit es append-only a nivel aplicación.
PKI EasyRSA es autoridad de validez. No almacenar certificados/claves en SQL.
