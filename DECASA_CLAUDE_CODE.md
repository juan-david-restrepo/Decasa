# DECASA — Sistema de Gestión de Ventas e Inventario
> Archivo de contexto para Claude Code. Lee esto completo antes de escribir cualquier código.

---

## 1. Descripción del negocio

**Decasa** es una empresa de muebles (sofás, comedores, sillas, etc.) con varias tiendas físicas en Colombia. Fabrica productos estándar y también hace productos **personalizados** en sus propias fábricas con un plazo de entrega de 30 días.

Los vendedores atienden clientes de forma **física** (en tienda) o **virtual** (WhatsApp, redes sociales). El sistema debe funcionar desde el **celular** del vendedor sin necesidad de instalar ninguna app nativa — se implementa como una **PWA (Progressive Web App)**.

---

## 2. Stack tecnológico

| Capa | Tecnología |
|------|-----------|
| Backend | Laravel 11 (PHP) — API REST |
| Frontend | Vue 3 + Vite — SPA / PWA |
| Estilos | Tailwind CSS |
| Base de datos | MySQL (nueva BD: `decasa_system`) |
| Auth | Laravel Sanctum (tokens) |
| Gráficas | Chart.js |
| Exports | Laravel Excel (maatwebsite/excel) |
| Colas | Laravel Queue (alertas automáticas) |
| Hosting | VPS (DigitalOcean / Railway / Hostinger) |

**Nota importante:** Ya existe una BD MySQL separada (`decasa_whatsapp`) usada por un asistente de WhatsApp con 180 productos. NO tocar esa BD. Solo importar los productos con un script de migración ya lo hice si puedes ejecutar una consulta en la terminal para mirarlo.

---

## 3. Roles del sistema

| Rol | Permisos |
|-----|---------|
| `vendedor` | Crear órdenes, registrar pagos, ver sus propias ventas, buscar productos/clientes, ver sus estadísticas personales |
| `supervisor` | Todo lo anterior + ver ventas de TODOS los vendedores, todas las tiendas, reportes globales, gestionar inventario, estadísticas avanzadas |

---

## 4. Flujo de negocio

### 4.1 Tipos de venta

**Tipo A — Producto en inventario (stock disponible)**
1. Vendedor selecciona cliente y productos del catálogo
2. Sistema verifica stock disponible en la tienda seleccionada
3. Se registra la orden y se **reserva** el stock
4. Cliente paga **50% de anticipo** → se registra el pago
5. Se agenda fecha de entrega
6. Al entregar: cliente paga el **50% restante** (de una sola vez o en abonos)
7. Orden pasa a estado `entregado`

**Tipo B — Producto personalizado**
1. Vendedor registra specs del producto (color, tela, medidas, acabados → JSON)
2. Cliente paga **50% de anticipo**
3. Orden entra a producción → plazo de **30 días**
4. Sistema alerta si se acerca o supera la fecha límite
5. Al entregar: cliente paga el 50% restante (o abona)
6. Orden pasa a estado `entregado`

### 4.2 Canales de venta
- `fisica` — el vendedor está en la tienda con el cliente
- `whatsapp` — venta por WhatsApp
- `red_social` — venta por Instagram, Facebook u otra red
- `otro`

### 4.3 Tiendas y vendedores
- Hay **varias tiendas**; cada vendedor tiene una **tienda predeterminada**
- Al crear una orden, el vendedor puede **cambiar la tienda** (si está vendiendo en otra)
- El **inventario es por tienda** — mismos productos, distinto stock en cada tienda
- El **catálogo de productos es único** para todas las tiendas

---

## 5. Schema de base de datos

**BD:** `decasa_system` — crear nueva, independiente de `decasa_whatsapp`.

```sql
CREATE DATABASE decasa_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE decasa_system;

-- Tiendas
CREATE TABLE tiendas (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  nombre     VARCHAR(100) NOT NULL,
  ciudad     VARCHAR(80),
  direccion  VARCHAR(200),
  telefono   VARCHAR(20),
  activa     BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Usuarios (vendedores y supervisores)
CREATE TABLE usuarios (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  nombre            VARCHAR(100) NOT NULL,
  email             VARCHAR(120) UNIQUE NOT NULL,
  password_hash     VARCHAR(255) NOT NULL,
  rol               ENUM('vendedor','supervisor') DEFAULT 'vendedor',
  tienda_default_id INT,
  activo            BOOLEAN DEFAULT TRUE,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tienda_default_id) REFERENCES tiendas(id)
);

-- Clientes
CREATE TABLE clientes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  nombre     VARCHAR(120) NOT NULL,
  cedula     VARCHAR(20) UNIQUE,
  telefono   VARCHAR(20),
  email      VARCHAR(120),
  direccion  VARCHAR(200),
  canal_pref ENUM('fisica','whatsapp','red_social','otro'),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Catálogo de productos (único para todas las tiendas)
CREATE TABLE productos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  nombre         VARCHAR(150) NOT NULL,
  categoria      VARCHAR(80),
  precio_base    DECIMAL(12,2) NOT NULL,
  personalizable BOOLEAN DEFAULT FALSE,
  descripcion    TEXT,
  foto_url       VARCHAR(255),
  medidas        varchar(200),
  material       varchar(200),
  activo         BOOLEAN DEFAULT TRUE,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Stock por tienda
CREATE TABLE inventario (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  producto_id         INT NOT NULL,
  tienda_id           INT NOT NULL,
  cantidad_disponible INT DEFAULT 0,
  cantidad_reservada  INT DEFAULT 0,
  stock_minimo        INT DEFAULT 1,
  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE (producto_id, tienda_id),
  FOREIGN KEY (producto_id) REFERENCES productos(id),
  FOREIGN KEY (tienda_id)   REFERENCES tiendas(id)
);

-- Auditoría de movimientos de inventario
CREATE TABLE inventario_movimientos (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  producto_id INT NOT NULL,
  tienda_id   INT NOT NULL,
  tipo        ENUM('entrada','salida','reserva','liberacion'),
  cantidad    INT NOT NULL,
  motivo      VARCHAR(200),
  usuario_id  INT,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (producto_id) REFERENCES productos(id),
  FOREIGN KEY (tienda_id)   REFERENCES tiendas(id),
  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)
);

-- Órdenes
CREATE TABLE ordenes (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id   INT NOT NULL,
  vendedor_id  INT NOT NULL,
  tienda_id    INT NOT NULL,
  canal        ENUM('fisica','whatsapp','red_social','otro'),
  estado       ENUM('pendiente_anticipo','en_produccion','listo_entrega','entregado','cancelado') DEFAULT 'pendiente_anticipo',
  valor_total  DECIMAL(12,2) NOT NULL,
  anticipo_pct DECIMAL(5,2) DEFAULT 50.00,
  notas        TEXT,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (cliente_id)  REFERENCES clientes(id),
  FOREIGN KEY (vendedor_id) REFERENCES usuarios(id),
  FOREIGN KEY (tienda_id)   REFERENCES tiendas(id)
);

-- Items de cada orden
CREATE TABLE orden_items (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  orden_id              INT NOT NULL,
  producto_id           INT NOT NULL,
  cantidad              INT DEFAULT 1,
  precio_unitario       DECIMAL(12,2) NOT NULL,
  es_personalizado      BOOLEAN DEFAULT FALSE,
  specs_personalizacion JSON,
  fecha_entrega_prom    DATE,
  FOREIGN KEY (orden_id)    REFERENCES ordenes(id),
  FOREIGN KEY (producto_id) REFERENCES productos(id)
);

-- Pagos (anticipo + abonos + saldo final)
CREATE TABLE pagos (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  orden_id    INT NOT NULL,
  vendedor_id INT NOT NULL,
  tipo        ENUM('anticipo','abono','saldo_final'),
  monto       DECIMAL(12,2) NOT NULL,
  metodo      ENUM('efectivo','transferencia','tarjeta','otro'),
  referencia  VARCHAR(100),
  notas       TEXT,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (orden_id)    REFERENCES ordenes(id),
  FOREIGN KEY (vendedor_id) REFERENCES usuarios(id)
);

-- Producción (solo para items personalizados)
CREATE TABLE produccion (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  orden_item_id    INT NOT NULL UNIQUE,
  fecha_inicio     DATE NOT NULL,
  fecha_compromiso DATE NOT NULL,
  fecha_real       DATE,
  estado           ENUM('en_proceso','listo','retrasado','entregado') DEFAULT 'en_proceso',
  motivo_retraso   TEXT,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (orden_item_id) REFERENCES orden_items(id)
);

-- Vista: saldo pendiente por orden
CREATE VIEW v_saldo_ordenes AS
  SELECT
    o.id AS orden_id,
    o.valor_total,
    COALESCE(SUM(p.monto), 0) AS total_pagado,
    o.valor_total - COALESCE(SUM(p.monto), 0) AS saldo_pendiente
  FROM ordenes o
  LEFT JOIN pagos p ON p.orden_id = o.id
  GROUP BY o.id, o.valor_total;

-- Vista: pedidos retrasados
CREATE VIEW v_retrasos AS
  SELECT
    p.id,
    o.id AS orden_id,
    c.nombre AS cliente,
    c.telefono,
    pr.nombre AS producto,
    p.fecha_compromiso,
    DATEDIFF(CURDATE(), p.fecha_compromiso) AS dias_retraso,
    p.motivo_retraso,
    u.nombre AS vendedor,
    t.nombre AS tienda
  FROM produccion p
  JOIN orden_items oi ON oi.id = p.orden_item_id
  JOIN ordenes o      ON o.id  = oi.orden_id
  JOIN clientes c     ON c.id  = o.cliente_id
  JOIN productos pr   ON pr.id = oi.producto_id
  JOIN usuarios u     ON u.id  = o.vendedor_id
  JOIN tiendas t      ON t.id  = o.tienda_id
  WHERE p.estado = 'retrasado'
     OR (p.estado = 'en_proceso' AND p.fecha_compromiso < CURDATE());

-- Índices para reportes
CREATE INDEX idx_ordenes_vendedor ON ordenes(vendedor_id);
CREATE INDEX idx_ordenes_tienda   ON ordenes(tienda_id);
CREATE INDEX idx_ordenes_estado   ON ordenes(estado);
CREATE INDEX idx_ordenes_fecha    ON ordenes(created_at);
CREATE INDEX idx_pagos_orden      ON pagos(orden_id);
CREATE INDEX idx_inv_tienda       ON inventario(tienda_id);
```


-- Inicializar inventario en 0 para cada producto x tienda
INSERT INTO inventario (producto_id, tienda_id, cantidad_disponible)
SELECT p.id, t.id, 0
FROM productos p CROSS JOIN tiendas t;
```

##  Estado actual de la BD y desarrollo (en progreso)

- `decasa_system` creada con todas las tablas del schema
- 207 productos migrados desde `decasa_whatsapp`
- 4 tiendas insertadas (IDs 1-4)
- Inventario inicializado en 0 para los 828 registros (207 productos × 4 tiendas)
- Backend: ✅ completo (todos los endpoints de la sección 7 implementados)
- Frontend:
  - ✅ Login, Dashboard, NuevaOrdenView (flujo de 3 pasos)
  - ✅ OrdenesView con filtros, scroll infinito, OrdenDetalleView, RegistroPagoModal
  - ✅ Componentes reusables: BadgeEstado, MoneyDisplay, EmptyState
  - ⏳ InventarioView, ProduccionView, ClientesView (básico)
  - ⏳ ReportesView (estadísticas globales supervisor) — pendiente sección 6.6
  - ⏳ StatsVendedorView (estadísticas personales vendedor) — pendiente sección 6.7
  - ⏳ PWA (manifest + service worker)
- Hosting: backend en Herd (Laravel Nginx) → `http://decasa-api.test`
- Proxy Vite configurado: `/api` → `http://decasa-api.test`

---

## 6. Módulos del sistema

### 6.1 Autenticación
- Login con email + password
- Laravel Sanctum para tokens de API
- El token se guarda en localStorage del frontend
- Middleware de roles: `role:vendedor` y `role:supervisor`
- Al hacer login, la respuesta incluye: token, nombre, rol, tienda_default_id

### 6.2 Módulo de ventas (Órdenes)

**Crear orden (vendedor):**
1. Seleccionar o crear cliente (buscar por nombre o cédula)
2. Seleccionar tienda (predeterminada del vendedor, con opción de cambiar)
3. Seleccionar canal de venta
4. Agregar productos:
   - Buscar por nombre (fulltext sobre `productos.nombre`)
   - Ver stock disponible en la tienda seleccionada
   - Si `personalizable = true`, mostrar campos extra: color, tela, medidas, acabado
   - Si es personalizado, calcular `fecha_entrega_prom = hoy + 30 días`
5. Ver resumen con valor total
6. Registrar anticipo (50% por defecto, campo editable)
7. Guardar → crea `ordenes`, `orden_items`, `pagos` (tipo anticipo) y actualiza `inventario.cantidad_reservada`
8. Si hay items personalizados → crear registro en `produccion`

**Registrar pago (vendedor):**
- Seleccionar orden pendiente del cliente
- Ingresar monto, método, referencia
- El sistema calcula saldo restante automáticamente con `v_saldo_ordenes`
- Si saldo = 0 y todos los items entregados → orden pasa a `entregado`

**Listado de órdenes (vendedor):**
- Solo sus propias órdenes
- Filtros: estado, fecha, tienda
- Ver detalle de cada orden con historial de pagos

### 6.3 Módulo de inventario

**Vista por tienda:**
- Tabla con todos los productos, stock disponible y reservado
- Buscador por nombre/categoría
- Botón "Agregar stock" → registra entrada en `inventario_movimientos`
- Alerta visual si `cantidad_disponible <= stock_minimo`

**Producto más vendido:**
- Query sobre `orden_items` agrupado por `producto_id`, filtrable por tienda y período

### 6.4 Módulo de clientes

- Búsqueda por nombre o cédula
- Historial de órdenes del cliente
- Saldo pendiente total
- Última compra y canal preferido

### 6.5 Módulo de producción (supervisor y vendedor)

- Lista de todos los pedidos personalizados en proceso
- Estado: en proceso / listo / retrasado / entregado
- Días restantes o días de retraso
- Al marcar como retrasado → campo obligatorio `motivo_retraso`
- Alertas automáticas (Laravel Queue) cuando `fecha_compromiso - CURDATE() <= 3 días`

### 6.6 Estadísticas personales del VENDEDOR

**Acceso:** cualquier rol (vendedor y supervisor). El vendedor SOLO ve sus propios datos.

**Filtro de período (siempre presente):**
- Presets rápidos: `Hoy`, `Esta semana`, `Este mes`, `Mes anterior`, `Año actual`, `Personalizado` (date picker desde/hasta)

#### Tarjetas KPI (sección "Mis Estadísticas")
| Métrica | Fórmula | Descripción |
|---------|---------|-------------|
| **Dinero vendido** | `SUM(pagos.monto)` del vendedor en el período | Total real cobrado, no solo valor de órdenes |
| **Órdenes creadas** | `COUNT(ordenes)` del vendedor en el período | Cuántas órdenes generó |
| **Órdenes entregadas** | `COUNT(ordenes WHERE estado='entregado')` | Ventas cerradas exitosamente |
| **Órdenes pendientes** | `COUNT(ordenes WHERE estado NOT IN ('entregado','cancelado'))` | Ventas en proceso |
| **Ticket promedio** | `dinero_vendido / órdenes_entregadas` | Promedio por venta cerrada |
| **Cartera pendiente** | `SUM(ordenes.valor_total - SUM(pagos.monto))` de órdenes no pagadas | Dinero que aún debe cobrar |

#### Gráfica de tendencia
- **Tipo:** línea (Chart.js)
- **Eje X:** días del período seleccionado
- **Eje Y:** dinero vendido acumulativo por día
- Muestra dos líneas: (1) dinero cobrado, (2) valor de órdenes creadas
- Permite ver la diferencia entre lo que vendió y lo que realmente cobró

#### Productos más vendidos (top 5 del vendedor)
- Ranking de los 5 productos que más ha vendido este vendedor en el período
- Cada item muestra: nombre del producto, cantidad vendida, valor total
- Gráfica de barras horizontal

#### Órdenes recientes
- Últimas 5 órdenes creadas por el vendedor
- Cada orden muestra: ID, cliente, estado, valor total, saldo pendiente
- Click → navega a `OrdenDetalleView`

---

### 6.7 Estadísticas y reportes del SUPERVISOR (panel global)

**Acceso:** solo rol `supervisor`. Ve TODOS los datos de TODOS los vendedores y TODAS las tiendas.

**Filtros globales (aplicables a TODAS las secciones):**
- Período con presets rápidos: `Hoy`, `Esta semana`, `Este mes`, `Mes anterior`, `Año actual`, `Personalizado` (date picker desde/hasta)
- Filtro por tienda: `Todas` (default) o una tienda específica

---

#### A) Panel de Resumen Global (Dashboard financiero)

Tarjetas KPI principales del período + tienda seleccionados:

| Métrica | Fórmula | Descripción |
|---------|---------|-------------|
| **Ingresos totales** | `SUM(pagos.monto)` de TODAS las tiendas | Dinero REAL ingresado en caja |
| **Órdenes totales** | `COUNT(ordenes)` | Todas las órdenes del período |
| **Órdenes entregadas** | `COUNT(ordenes WHERE estado='entregado')` | Ventas cerradas |
| **Cartera pendiente** | `SUM(saldo_pendiente)` de órdenes no pagadas | Dinero por cobrar |
| **Ticket promedio** | `ingresos / órdenes_entregadas` | Promedio por venta cerrada |
| **Órdenes canceladas** | `COUNT(ordenes WHERE estado='cancelado')` | Ventas perdidas |
| **Comparativa** | vs período anterior | % de cambio (↑ verde si subió, ↓ rojo si bajó) |

**Gráfica principal:**
- **Tipo:** línea
- **Eje X:** días del período
- **Eje Y:** dinero ingresado por día
- Permite alternar entre vista "diaria", "semanal" y "mensual"
- Muestra también una línea del período anterior para comparar

---

#### B) Estadísticas por Tienda

**Objetivo:** ver cuánto genera cada tienda, comparar rendimiento.

**Tarjetas por tienda (grid de cards):**
- Nombre de la tienda + ciudad
- Dinero vendido en el período
- Número de órdenes (entregadas + pendientes)
- Ticket promedio de esa tienda
- Vendedor destacado (el que más vendió en esa tienda)

**Gráfica:**
- **Tipo:** barras
- **Eje X:** nombre de cada tienda
- **Eje Y:** dinero vendido
- Colores diferentes por tienda

**Click en una tienda → Drill-down:**
- Muestra las mismas estadísticas pero filtradas solo a esa tienda
- Lista de vendedores de esa tienda con su ranking
- Top productos de esa tienda
- Órdenes recientes de esa tienda

**Comparativa de tiendas:**
- Tabla con todas las tiendas lado a lado:
  | Tienda | Ingresos | Órdenes | Entregadas | Canceladas | Ticket promedio |
  |--------|----------|---------|------------|------------|----------------|

---

#### C) Estadísticas por Vendedor

**Ranking general:**
- Tabla ordenada por dinero vendido (descendente)
- Columnas: nombre, tienda, dinero vendido, órdenes, entregadas, canceladas, ticket promedio, cartera pendiente
- Badge de posición: 🥇 🥈 🥉 para los top 3

**Gráfica:**
- **Tipo:** barras horizontales
- Eje Y: nombre del vendedor
- Eje X: dinero vendido
- Cada barra tiene el color de la tienda del vendedor

**Click en un vendedor → Perfil individual:**
- Misma vista que el vendedor ve en sus estadísticas personales (sección 6.6) pero desde la perspectiva del supervisor
- Además muestra:
  - Comparativa del vendedor contra el promedio del equipo
  - % que representa del total de la empresa
  - Distribución de sus ventas por canal (física, whatsapp, red social)
  - Historial completo de órdenes filtrable

---

#### D) Productos más vendidos

**Dos tabs:**
1. **Por cantidad:** productos que más se vendieron en número de unidades
2. **Por valor:** productos que generaron más dinero

**Filtros adicionales:**
- Por tienda: muestra top solo de esa tienda
- Por categoría: filtra por categoría de producto

**Tabla de resultados:**
| # | Producto | Categoría | Cantidad | Valor total | Precio promedio |
|---|----------|-----------|----------|-------------|----------------|

**Gráfica:**
- **Tipo:** dona
- Muestra distribución del valor vendido por categoría de producto

---

#### E) Cartera pendiente (cuentas por cobrar)

- Total de dinero pendiente de cobro de TODAS las órdenes no pagadas completamente
- Desglose por orden:
  | Orden | Cliente | Vendedor | Tienda | Valor total | Pagado | Saldo | Días sin pagar |
- Ordenable por: saldo mayor, días más antiguo, vendedor, tienda
- Filtro por vendedor y tienda
- Alerta visual: rojo si >15 días sin pagar, naranja 7-15 días, amarillo <7 días

---

#### F) Retrasos de producción

- Vista `v_retrasos` con días de retraso
- Alertas visuales por severidad:
  - Rojo: >7 días de retraso
  - Naranja: 3-7 días
  - Amarillo: <3 días
- Por cada retraso: cliente, teléfono, producto, vendedor, tienda, días de retraso, motivo

---

#### G) Exportar a Excel

- Cualquier sección tiene botón "Exportar" que descarga un `.xlsx` con los datos filtrados actualmente
- Usar Laravel Excel (`maatwebsite/excel`)
- Nombre del archivo: `decasa_reporte_{tipo}_{fecha_inicio}_{fecha_fin}.xlsx`

---

### 6.8 Guías visuales (aplicar a todos los módulos)

- **Sombras:** usar sombras consistentes en todas las tarjetas (`shadow-sm` como base, `shadow-md` en hover)
- **Cards:** fondo blanco, bordes redondeados (`rounded-xl` o `rounded-2xl`), padding generoso (`p-4` o `p-5`)
- **Separación:** las secciones dentro de una vista deben estar claramente separadas con espacio (`space-y-4`)
- **KPI cards:** números grandes y prominentes, label pequeño debajo, icono o color sutil de fondo
- **Colores de estado:** mismos badges en todo el sistema (amarillo pendiente, azul en_produccion, verde listo/entregado, rojo cancelado)
- **Navegación:** bottom tab bar en móvil, header sticky con nombre de la sección y botón de logout
- **Gráficas:** fondo blanco, bordes suaves, leyenda clara, tooltips al hover/touch

---

## 7. API REST — Endpoints principales

### Auth
```
POST   /api/auth/login
POST   /api/auth/logout
GET    /api/auth/me
```

### Tiendas
```
GET    /api/tiendas
```

### Productos
```
GET    /api/productos?search=silla&tienda_id=1
GET    /api/productos/{id}
```

### Clientes
```
GET    /api/clientes?search=juan
POST   /api/clientes
GET    /api/clientes/{id}
GET    /api/clientes/{id}/ordenes
```

### Órdenes
```
GET    /api/ordenes                    (vendedor: solo las suyas | supervisor: todas)
POST   /api/ordenes
GET    /api/ordenes/{id}
PATCH  /api/ordenes/{id}/estado
```

### Pagos
```
POST   /api/ordenes/{id}/pagos
GET    /api/ordenes/{id}/pagos
```

### Inventario
```
GET    /api/inventario?tienda_id=1
POST   /api/inventario/entrada         (agregar stock)
```

### Producción
```
GET    /api/produccion
PATCH  /api/produccion/{id}
```

### Reportes y Estadísticas

**Solo supervisor:**
```
GET    /api/reportes/ventas?desde=&hasta=&tienda_id=
GET    /api/reportes/vendedores?desde=&hasta=
GET    /api/reportes/productos-top?tienda_id=&limit=10
GET    /api/reportes/pendientes
GET    /api/reportes/retrasos
GET    /api/reportes/exportar?tipo=ventas&desde=&hasta=   (descarga Excel)
```

**Ambos roles (vendedor ve solo lo suyo, supervisor ve todo):**
```
GET    /api/stats/panel?periodo=hoy|semana|mes&desde=&hasta=&tienda_id=    (KPIs principales)
GET    /api/stats/tiendas?desde=&hasta=                                     (desglose por tienda)
GET    /api/stats/vendedor/{id}?desde=&hasta=                               (perfil individual)
GET    /api/stats/vendedores/me?desde=&hasta=                               (stats personales)
GET    /api/stats/cartera?desde=&hasta=&tienda_id=                          (cuentas por cobrar)
GET    /api/stats/tendencia?desde=&hasta=&agrupado=dia|semana|mes&tienda_id= (datos para gráfica de línea)
GET    /api/stats/productos?desde=&hasta=&tienda_id=&tipo=cantidad|valor    (top productos)
```

---

## 8. Estructura de carpetas

### Backend (Laravel)
```
decasa-api/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── AuthController.php
│   │   │   ├── OrdenController.php
│   │   │   ├── PagoController.php
│   │   │   ├── ClienteController.php
│   │   │   ├── ProductoController.php
│   │   │   ├── InventarioController.php
│   │   │   ├── ProduccionController.php
│   │   │   └── ReporteController.php
│   │   └── Middleware/
│   │       └── CheckRole.php
│   ├── Models/
│   │   ├── Tienda.php
│   │   ├── Usuario.php
│   │   ├── Cliente.php
│   │   ├── Producto.php
│   │   ├── Inventario.php
│   │   ├── InventarioMovimiento.php
│   │   ├── Orden.php
│   │   ├── OrdenItem.php
│   │   ├── Pago.php
│   │   └── Produccion.php
│   ├── Jobs/
│   │   └── AlertarRetrasoProduccion.php
│   └── Exports/
│       └── ReporteExport.php
├── database/
│   └── migrations/
│       └── (una migration por tabla)
└── routes/
    └── api.php
```

### Frontend (Vue 3)
```
decasa-app/
├── public/
│   ├── manifest.json          (PWA)
│   └── sw.js                  (Service Worker)
├── src/
│   ├── api/                   (funciones axios por módulo)
│   │   ├── auth.js
│   │   ├── ordenes.js
│   │   ├── productos.js
│   │   ├── clientes.js
│   │   ├── inventario.js
│   │   ├── produccion.js
│   │   └── reportes.js
│   ├── components/
│   │   ├── common/            (botones, inputs, tablas reutilizables)
│   │   │   ├── BadgeEstado.vue
│   │   │   ├── MoneyDisplay.vue
│   │   │   └── EmptyState.vue
│   │   ├── ordenes/
│   │   │   └── RegistroPagoModal.vue
│   │   ├── inventario/
│   │   ├── clientes/
│   │   └── reportes/
│   ├── views/
│   │   ├── LoginView.vue
│   │   ├── DashboardView.vue
│   │   ├── OrdenesView.vue
│   │   ├── OrdenDetalleView.vue
│   │   ├── NuevaOrdenView.vue
│   │   ├── ClientesView.vue
│   │   ├── InventarioView.vue
│   │   ├── ProduccionView.vue
│   │   ├── ReportesView.vue       (solo supervisor - estadísticas globales)
│   │   └── StatsVendedorView.vue  (estadísticas personales del vendedor)
│   ├── router/
│   │   └── index.js           (rutas con guards por rol)
│   ├── stores/
│   │   ├── auth.js            (Pinia)
│   │   └── tiendas.js
│   └── main.js
└── vite.config.js
```

---

## 9. Reglas de negocio críticas

1. **Reserva de inventario:** Al crear una orden con productos de stock, `inventario.cantidad_reservada` aumenta. Solo se descuenta de `cantidad_disponible` al marcar la orden como `entregado`. Si se cancela, liberar la reserva.

2. **Anticipo obligatorio:** No se puede guardar una orden sin registrar al menos el anticipo del 50%.

3. **Producción automática:** Si un `orden_item` tiene `es_personalizado = TRUE`, automáticamente crear un registro en `produccion` con `fecha_inicio = hoy` y `fecha_compromiso = hoy + 30 días`.

4. **Alerta de retraso:** Laravel Queue debe correr diariamente y marcar como `retrasado` cualquier producción cuya `fecha_compromiso < CURDATE()` y estado sea `en_proceso`.

5. **Rol en respuesta de API:** El frontend usa el campo `rol` del usuario para mostrar/ocultar secciones. El backend valida el rol en cada endpoint protegido.

6. **Búsqueda de productos:** Usar `LIKE %término%` sobre `nombre` y `categoria`. Mostrar siempre el stock de la tienda seleccionada en la orden.

7. **Cambio de tienda en orden:** El vendedor puede cambiar la tienda activa antes de confirmar la orden. El stock a verificar es el de la tienda seleccionada, no la predeterminada.

---

## 10. Por dónde empezar

**Orden de desarrollo recomendado:**

1. `laravel new decasa-api` → configurar `.env` con BD `decasa_system`
2. Instalar: `sanctum`, `maatwebsite/excel`, `spatie/laravel-query-builder`
3. Crear migrations y ejecutarlas
4. Correr script de migración de productos desde `decasa_whatsapp`
5. Crear modelos con relaciones Eloquent
6. Crear `AuthController` + login/logout + middleware de roles
7. Crear `ProductoController` con búsqueda
8. Crear `OrdenController` con lógica de reserva de inventario
9. Crear `PagoController`
10. `npm create vue@latest decasa-app` → instalar Tailwind + Pinia + Vue Router + Axios
11. Crear store de auth con Pinia (login, token, rol)
12. Crear router con guards (si no autenticado → login; si vendedor → sin reportes)
13. Construir `NuevaOrdenView` (el flujo más complejo del frontend)
14. Construir `OrdenesView` con filtros, scroll infinito, vista de detalle y registro de pagos
15. Construir `InventarioView` y `ProduccionView`
16. Construir `ClientesView` con vista de detalle
17. Construir `ReportesView` con Chart.js (solo supervisor) — panel de estadísticas global
18. Construir `StatsVendedorView` — estadísticas personales del vendedor
19. Crear nuevos endpoints API de estadísticas (`/api/stats/*`) en el backend
20. Configurar PWA (manifest.json + service worker)
21. Refinar UI: sombras consistentes, transiciones, estados de carga, toasts de error

---

## 11. Notas adicionales

- **Moneda:** Pesos colombianos (COP). No se necesita conversión.
- **Zona horaria:** `America/Bogota` — configurar en Laravel (`config/app.php`) y MySQL.
- **Imágenes de productos:** Por ahora guardar solo URL en `foto_url`. Las fotos pueden estar en la BD del asistente de WhatsApp o en un bucket S3 futuro.
- **La BD del asistente de WhatsApp NO se modifica.** Solo se lee para migrar productos.
- **El sistema se llama "Decasa" internamente.** No exponer datos de una tienda a vendedores de otra (el supervisor sí ve todo).
