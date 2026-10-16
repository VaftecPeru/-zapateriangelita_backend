# Mejora: atributos opcionales de inventario (Zapatería Angelita)

Estado: análisis técnico en rama de trabajo. **No desplegar ni fusionar todavía.**

## Diagnóstico del código estable
- El importador Laravel exige actualmente COLOR y TALLA en el encabezado y en cada fila.
- La consolidación de variantes solo incorpora combinaciones con ambos valores presentes.
- El persistidor recrea existencias por variante y catálogos de tallas/colores; una modificación parcial puede provocar pérdida de stock o selectores vacíos.
- La interfaz de importación React describe explícitamente una variante como color + talla.

## Contrato funcional acordado
1. Color vacío + talla: inventario por talla, sin selector de color.
2. Talla vacía + color: inventario por color, sin selector de talla.
3. Ambos vacíos: producto simple con stock general.
4. Ambos presentes: inventario por combinación.
5. No convertir automáticamente «color desconocido» en un color ficticio.
6. Stock desconocido nunca equivale a stock 0 ni se reparte entre tallas.
7. Agotado = stock conocido 0; dato pendiente = venta bloqueada hasta conciliar.
8. Preservar importación previa, actualización de productos y trazabilidad de movimientos.

## Implementación requerida antes de merge
- Backend: validar encabezados sin obligar color/talla; aceptar dimensiones opcionales; persistir una clave canónica por variante sin violar restricciones de BD; preservar actualización y movimientos; asegurar que checkout resuelva la misma clave.
- Frontend: editor, vista previa, ficha de producto, carrito y checkout deben ocultar selectores ausentes y deshabilitar variantes sin stock.
- QA: pruebas de cuatro modalidades, duplicados, idempotencia, stock, rollback, compra y compatibilidad de plantillas existentes.
- Criterios de liberación: PHPUnit, lint, build, pruebas UI y GitHub Actions verdes; aprobación explícita para develop, main y despliegue.

## Inventario real
La ausencia de nombres, precios y desglose de existencias en el Excel real sigue requiriendo conciliación con el cliente. No se completarán valores por inferencia.
