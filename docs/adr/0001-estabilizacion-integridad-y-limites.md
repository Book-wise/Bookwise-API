# ADR-0001: límites provisionales de estabilización

Estado: PROVISIONAL — POR REVISAR CON SEBACIRK

## Decisiones

- Git: `develop` es la única rama base vigente; no existe una rama `qa`.
- Servicios: Bookwise es hoy la fuente de servicios. No se implementa aún sincronización WooCommerce Product a `Service` o `ServicePack`.
- Carlitox: conserva los scopes actuales y no obtiene escritura de reservas.
- Refund WooCommerce: cancela la reserva asociada; no crea una reversa financiera automática. La conciliación financiera queda pendiente y se registra en el log técnico del webhook.
- Configuración: marca y configuración varían por instalación mediante configuración de entorno; esta fase no implementa multitenancy.

## Consecuencias de esta fase

- No cambian los estados ni las reglas comerciales de reservas.
- Los Resources Laravel y sus envelopes `{ "data": ... }` se mantienen.
- Los eventos `product.created`, `product.updated` y `product.deleted` de WooCommerce no se implementan.
