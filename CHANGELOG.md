# Changelog · VendGuard

Registro de los hitos de producto y de los módulos cerrados bajo el ciclo SDD.
El proyecto no publica versiones semánticas independientes: cada entrada corresponde
a un módulo funcional certificado por la batería global.

## [Módulo 16] · Avería Oportunista In Situ — 2026-10-19

**Estado:** Cerrado · especificación funcional, contratos técnicos y batería de pruebas en verde.

### Añadido

- Alta y asunción de averías sobre otras máquinas de la sede en la que el técnico ya
  está interviniendo, con modalidades `NEW` y `ASSUMED` y trazabilidad en auditoría.
- Ventana de gracia de 30 minutos tras resolver o pausar una parada, con tarjeta
  temporal «Sede recién visitada (30 min)» y margen de 5 minutos si el formulario ya
  estaba abierto.
- Flujo móvil de tres toques (`TechnicianOpportunisticModal`) con catálogo de máquinas
  de la sede, aviso sanitario de otra sede y bloqueo de convivencia entre sedes.
- Reapertura in situ en garantía de 48 horas y sustitución atómica del ticket por un
  sucesor limpio en la tercera reincidencia (avería crónica).
- Insignias «Oportunista In Situ · Nueva/Asumida» en triaje y ficha de detalle, KPI de
  desplazamientos evitados y desglose por submotivo.
- Aviso oportunista en el portal de sede con el técnico anonimizado como
  «Servicio Técnico Oficial · Operador #[Código]» (Art. V.4).
- Notificaciones de la nueva modalidad en el centro de salida: alerta prioritaria a
  Coordinación al posponer una parada de frío, aviso a la sede en reaperturas y
  desasignación por correo al técnico saliente (sin Telegram, RF-TG-06).

### Verificado

- Batería global: 293 suites y 11.507 aserciones al 100 % en verde, con la guarda de
  coherencia documental activa y la base del arnés restaurada a semillas.
- Cobertura específica del módulo: esquema, políticas puras, escrituras atómicas,
  notificaciones y privacidad, servicio de aplicación, API y superficies Vue.
