# ADR-0001: límites provisionales de estabilización

Estado: PROVISIONAL — POR REVISAR CON SEBACIRK

## Decisiones

- Git: `develop` es la única rama base vigente; no existe una rama `qa`.
- Servicios: Bookwise es hoy la fuente de servicios. No se implementa aún sincronización WooCommerce Product a `Service` o `ServicePack`.
- Carlitox: el alcance definitivo de escritura de reservas no está aprobado.
- Refund WooCommerce: cancela la reserva asociada; no crea una reversa financiera automática. La conciliación financiera queda pendiente y se registra en el log técnico del webhook.
- Configuración: marca y configuración varían por instalación mediante configuración de entorno; esta fase no implementa multitenancy.

## Consecuencias de esta fase

- No cambian los estados ni las reglas comerciales de reservas.
- Los Resources Laravel y sus envelopes `{ "data": ... }` se mantienen.
- Los eventos `product.created`, `product.updated` y `product.deleted` de WooCommerce no se implementan.

## Restauración posterior a la integración de `develop`

Estado: PROVISIONAL — POR REVISAR CON SEBACIRK

- La agenda se serializa por `location` dentro de una transacción. Las reservas,
  reprogramaciones, bloqueos de agenda y el procesamiento de `order.completed`
  adquieren el mismo lock antes de comprobar conflictos. Las sedes distintas no
  comparten ese lock.
- Los providers sólo pueden listar, consultar, modificar o cancelar reservas
  asignadas a su propio `provider_id`; para crear, su provider debe pertenecer a
  la sede solicitada. Esta es una restricción de seguridad provisional, no una
  redefinición del modelo de asignación de profesionales.
- POR CONFIRMAR CON SEBACIRK: las pruebas incorporadas desde `develop` esperan
  que `agent` cree y cancele reservas, aunque una versión anterior de este ADR
  las restringía. La fase de estabilización conserva el comportamiento y la
  trazabilidad (`created_via` / `last_modified_via`) de `develop`; no amplía
  scopes ni diseña permisos nuevos.
- El secreto de WooCommerce es obligatorio. Los registros técnicos almacenan un
  subconjunto de entrega sin billing ni shipping; la carga completa permanece
  sólo en el mensaje de cola durante el procesamiento. La retención y cifrado de
  ese transporte temporal deben confirmarse antes de producción.
- Los reintentos de `completed` y `refunded` se separan por topic. El refund
  toma el lock de agenda, sólo cancela una vez y no altera ventas ni
  transacciones financieras.
