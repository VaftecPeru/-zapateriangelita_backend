# Zapatería Angelita - Persistencia de imágenes y mantenimiento de BD

## Media persistente

Los archivos cargados por clientes/administración no deben vivir dentro de la carpeta reemplazable del backend.

Configurar en producción:

```env
MEDIA_ROOT=/home/USUARIO_CPANEL/angelita_media
MEDIA_URL_PREFIX=/api/media
```

El directorio debe existir o ser creable por PHP. Estructura prevista:

```text
angelita_media/
  uploads/
    products/
    banners/
    settings/
```

El backend mantiene compatibilidad con rutas existentes como:

```text
/api/media/uploads/products/archivo.webp
/uploads/banners/archivo.webp
/storage/uploads/products/archivo.webp
```

Cuando un archivo legacy es solicitado, el backend intenta copiarlo automáticamente al MEDIA_ROOT persistente. De esta forma un deploy futuro no vuelve a depender del contenido de storage/app/public o public/uploads del release anterior.

## Política de respaldo

Un respaldo completo debe contener:

1. Dump SQL de MySQL/MariaDB.
2. Copia del directorio definido por MEDIA_ROOT.

No reemplazar MEDIA_ROOT durante un deploy de frontend/backend.

## Mantenimiento del dump auditado 20/09/2026

El dump de producción entregado mostró:

- 7 productos con inventario maestro, por talla y por variante coherente.
- products.price almacenado como VARCHAR aunque se usa como precio numérico.
- products.product_code sin índice UNIQUE físico.
- products.img definido NOT NULL aunque el código permite productos sin imagen.
- 3 direcciones idénticas para el mismo usuario.
- 503 tokens Sanctum, de los cuales 177 pertenecían a usuarios que ya no existen.
- una cuenta con 315 tokens activos acumulados.
- slugs faltantes en marcas creadas posteriormente.
- colores Arena, Café, Crema y Marrón oscuro con el fallback gris #888888.
- las cinco migraciones recientes estaban aplicadas físicamente pero no registradas en la tabla migrations.

La migración 2026_09_20_040000_harden_production_data_integrity.php corrige estas inconsistencias de forma compatible con el código actual.
