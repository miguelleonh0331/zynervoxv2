# installer

Código: `installer/`. Despliega primero la web, intenta instalar dependencias y
activa Asterisk/VICIdial solo cuando están disponibles. El diagnóstico normal acepta
estado parcial; `check.sh --strict` exige la plataforma completa.
