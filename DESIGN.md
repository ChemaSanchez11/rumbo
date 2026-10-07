# Sistema de diseño: Rumbo

> Fuente única de verdad para la interfaz. Todo cambio visual debe cumplir este documento.

**Lectura del encargo:** app de navegación para conductores en España (modo *Operate*: el usuario viene a hacer una tarea), consultada de un vistazo dentro del coche, de día y de noche. Lenguaje de la señalización vial española, construido con CSS nativo, una tipografía de carretera autoalojada y un único gesto distintivo.

**Diales:** `DESIGN_VARIANCE 4` · `MOTION_INTENSITY 3` · `VISUAL_DENSITY 6`.
Es una herramienta, no una landing: simetría práctica, movimiento mínimo y con propósito, densidad de app diaria.

## 1. Atmósfera

Sobria, legible a un metro de distancia y con el pulso de la carretera. La interfaz se aparta para que mande el mapa; el carácter llega por cuatro vías y solo por ellas: la tipografía de cartel, la paleta de la señalización, la densidad y el gesto distintivo.

**Gesto distintivo:** la información de la ruta se dibuja con el lenguaje de la red de carreteras española.
- Las instrucciones de giro son **carteles de orientación** (azul para autovía y autopista, blanco para carretera convencional) con el filete interior blanco de los carteles reales.
- Cada referencia de carretera es una **placa de identificación** con su color oficial: A/AP/M-30 en azul, N en rojo, E en verde, autonómicas en naranja y locales en amarillo.
- El clima a lo largo de la ruta se lee como una fila de **hitos kilométricos**.

## 2. Paleta y funciones

Tema según la luz del día (`js/theme.js`): claro entre el amanecer y la puesta de sol, oscuro el resto, calculado con la ubicación GPS (Madrid si no hay). No depende del modo del sistema. El día y la noche son el mismo sistema, no dos webs.

| Token | Día | Noche | Función |
|---|---|---|---|
| `--surface` (Asfalto) | `#FBFBFC` | `#15191F` | Panel inferior, barra superior, popups |
| `--surface-2` (Arcén) | `#F0F2F5` | `#1E232B` | Campos, filas en hover, segmentos |
| `--line` (Marca vial) | `#DCE0E6` | `#2B323C` | Divisiones de 1px, bordes de campo |
| `--ink` (Tinta) | `#14181F` | `#ECEFF3` | Texto principal |
| `--ink-2` | `#495260` | `#AEB5C0` | Texto secundario |
| `--ink-3` | `#646D7B` | `#8C95A2` | Metadatos (≥4,5:1 sobre surface) |
| `--accent` (Azul ruta) | `#1F5FD1` | `#7AA5F2` | El único acento: ruta en el mapa, botón principal, foco, selección |
| `--accent-ink` | `#FFFFFF` | `#0B1424` | Texto sobre el acento |

**Colores de señalización** (semánticos, fijos en ambos temas; no son acentos decorativos):
`--sign-blue #1D4F9C` · `--sign-red #C4302B` · `--sign-green #1F7A45` · `--sign-orange #D9781E` · `--sign-yellow #F2C230`.

**Gravedad de avisos y clima:** peligro = `--sign-red`, aviso = `--sign-orange`, precaución = `#9A7B14`, bueno = `#2F8F5B`.
**Combustible:** gasolina = `--accent`, diésel = `#B7791F`.

Prohibido: negro puro `#000`, blanco puro de fondo, degradados de texto, brillos de neón, morados de "IA", mezclar grises cálidos y fríos.

## 3. Tipografía

- **Interfaz:** *Overpass* (variable 100-900, autoalojada en `assets/fonts/`). Deriva de la Highway Gothic de la señalización; tiene la anchura y la apertura que pide la lectura en marcha.
- **Cifras:** la misma *Overpass* en 700-800 con `font-variant-numeric: tabular-nums` para velocidad, hora de llegada, kilómetros, precios y temperaturas. Se descartó Overpass Mono: abría huecos en los decimales con coma ("312 ,4").
- Escala: 12 · 14 · 16 · 20 · 28 · 40 px. Cuerpo 16px mínimo en campos (evita el zoom de iOS). Tracking de titulares hasta `-0.02em`; nada por debajo de `-0.04em`.
- Jerarquía por peso (400 · 600 · 800) antes que por tamaño. Mayúsculas solo en las placas de carretera.
- Prohibido: Inter, fuentes del sistema como voz de marca, serifas.

## 4. Componentes

- **Botón principal:** fondo `--accent`, texto `--accent-ink`, 52px de alto, radio 12px. `:active` → `scale(0.98)`. Estado de carga con barra de progreso integrada, nunca un spinner circular.
- **Botón secundario:** fondo `--surface-2`, texto `--ink`.
- **Botones flotantes del mapa:** 48px, radio 14px, `--surface` con sombra tintada. "Reportar" es el único relleno de acento. Estado activo: anillo de acento de 2px.
- **Control segmentado** (Autovía / Carretera, Gasolina / Diésel): carril `--surface-2`, segmento activo `--surface` con texto `--ink`. Semántica `aria-pressed`.
- **Campos:** etiqueta visible encima (nunca un placeholder como etiqueta), 48px de alto, radio 12px, borde `--line`, foco con anillo de acento de 2px.
- **Sugerencias:** lista en el flujo debajo del campo (no flotante), filas de 52px con icono, texto principal y detalle.
- **Placa de carretera:** radio 4px como las placas reales, Overpass 800 en mayúsculas, color según la red.
- **Cartel de orientación:** azul o blanco, filete interior de 2px, flecha de maniobra, placa de carretera, instrucción y distancia en cifras tabulares.
- **Hitos kilométricos:** cabeza de color acento con el km en cifras tabulares y cuerpo con icono del tiempo y temperatura.
- **Marcadores del mapa:** avisos como placas cuadradas redondeadas del color de su gravedad con icono blanco; gasolineras como etiqueta con el precio en cifras tabulares; posición GPS como punto de acento con halo.
- **Estados vacíos:** icono, frase que explica qué pasa y qué hacer. **Errores:** en línea, con icono y `role="alert"`; nada de `alert()`.
- **Avisos al usuario:** toast inferior que desaparece solo, `role="status"`.

Radios: placas 4px · controles 12px · flotantes 14px · panel 20px (esquinas superiores) · marcadores circulares 50%.

## 5. Maquetación

- **Móvil (< 900px):** el mapa ocupa toda la pantalla; panel inferior con asa que se pliega y despliega; botones flotantes en columna a la derecha; barra superior y cartel en modo ruta.
- **Escritorio (≥ 900px):** el panel se convierte en una columna flotante de 400px a la izquierda, con su propio scroll; la barra superior y el cartel se alinean al área del mapa.
- Márgenes laterales de 16px, objetivos táctiles de 44px como mínimo, `100dvh`, nunca `100vh` solo.
- Escala de capas (`z-index`): mapa 0 · flotantes 10 · panel 20 · barra y cartel 30 · toast 40 · diálogo 50.

## 6. Movimiento

Un único momento con autor: al entrar en modo ruta, el cartel de orientación baja desde la barra superior (`translateY` + `opacity`, 320ms, `cubic-bezier(0.16, 1, 0.3, 1)`). El resto es respuesta a acciones: cambio de panel (240ms), aparición escalonada de listas (40ms entre filas), pulsación de botones y halo del GPS.

Solo se anima `transform` y `opacity`. Con `prefers-reduced-motion: reduce` todo pasa a estático e instantáneo.

## 7. Prohibido

- Emojis en la interfaz (los iconos son Phosphor *bold*, una sola familia y un solo grosor).
- Inter, serifas y fuentes del sistema como voz de marca.
- Negro puro, neón, degradados de texto y cristal decorativo.
- Etiquetas tipo *eyebrow* sobre los títulos, números de sección y separadores `·` en cadena.
- Rayas de color de más de 1px en el lateral de filas o tarjetas.
- Tarjetas anidadas y cuadrículas de tarjetas iguales.
- `alert()`, spinners circulares, placeholders como etiqueta.
- Guiones largos en los textos visibles.
- HTML de terceros sin escapar: todo dato externo pasa por `escapeHtml()`.
