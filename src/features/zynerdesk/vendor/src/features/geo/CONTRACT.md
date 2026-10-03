# Contrato: geo

Expone `createGeoService().lookup(ip)`. Devuelve geolocalización aproximada o `null`,
usa caché temporal y trata fallos externos como resultado vacío. No persiste datos ni
permite que una caída del proveedor interrumpa la API principal.
