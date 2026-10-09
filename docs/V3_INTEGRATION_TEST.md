# Integración v3 en v2: pruebas antes de publicar

Actualización: el desatendido ahora despliega v2 aislado. Consultar V2_ISOLATION.md.
No activa módulos opcionales ni motor de llamadas compartido; la configuración
central y el acceso administrativo usan tablas v2_* y usuarios exclusivos.

Fuente: zynervoxv3, snapshot 44bb716. Base v2: 5f0a959.
Cambios locales; no publicados todavía.

## Interfaz

1. Instalar en servidor de desarrollo, no sobre producción sin respaldo.
2. Entrar como administrador nivel 9 y abrir Servicios > Base de datos.
3. Probar conexión, guardar y recargar. Contraseña debe aparecer enmascarada.
4. Cambiar otro campo dejando contraseña vacía: debe conservar el secreto.
5. Abrir campañas/listas Bot IVR e IVR Builder: deben usar la misma conexión.
6. En el engranaje IVR verificar Asterisk; su configuración permanece separada.
7. Comprobar que usuarios no administradores no acceden al panel/API.

Guardar otra conexión NO copia datos: preparar allí los esquemas/datos antes
de cambiar. Conexión administrativa core permanece en su archivo de bootstrap.
Servicios > Test es una pantalla en construcción, no un diagnóstico funcional.

## Instalador

Desde la raíz de la fuente en Linux:

```bash
sudo bash installer/unattended.sh --dry-run
sudo bash installer/unattended.sh
```

Por defecto omite paquetes: preparar PHP/PDO MySQL, Python3, cliente/servidor
MySQL local, Apache, Node y dependencias de módulos opcionales. Sin MySQL la
instalación es parcial y no debe considerarse operativa. Consultar salida/check.sh.
La contraseña inicial administrativa queda en el archivo privado indicado por
el instalador, nunca en la salida.

Repetir la instalación conserva configuración, campañas, flujos y STT.
No usar --fresh: está rechazado para impedir borrados.
JSON legacy sin bootstrap requiere migración explícita; no se trasladan bases
ni datos antiguos automáticamente. Respaldar antes de cualquier migración.
El fallback legacy requiere Core operativo y su tabla ivr_deploy_config instalada.
Se verifica el puerto de la instancia MySQL antes de modificar esquemas.

Después de validar y publicar en GitHub:

```bash
sudo bash installer/unattended.sh --update-source
```

Exige main limpio y actualización fast-forward, nunca descarta trabajo local.

## Validación automatizada

```bash
php src/features/core/tests/deployment-config.php
sudo bash src/features/installer/tests/central-database.sh
```

La segunda prueba inicia su propio MySQL aislado con puerto aleatorio: instala
dos veces, verifica datos/configuración/permisos y ejecuta suites de repositorios.
No conecta a bases productivas. Requiere mysqld y PHP PDO MySQL disponibles.
