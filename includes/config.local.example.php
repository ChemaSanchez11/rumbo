<?php
/**
 * Copia este archivo como includes/config.local.php en el servidor y ajústalo.
 * config.local.php no se sube a git.
 */

// --- Opción A: Stadia Maps con clave (si no registras el dominio) ---
// define('MAP_TILES', [
//     'light'       => 'https://tiles.stadiamaps.com/tiles/alidade_smooth/{z}/{x}/{y}{r}.png?api_key=TU_CLAVE',
//     'dark'        => 'https://tiles.stadiamaps.com/tiles/alidade_smooth_dark/{z}/{x}/{y}{r}.png?api_key=TU_CLAVE',
//     'subdomains'  => '',
//     'maxZoom'     => 20,
//     'attribution' => '&copy; Stadia Maps &copy; OpenMapTiles &copy; OpenStreetMap',
// ]);

// --- Opción B: CARTO con clave ---
// Pega las URLs exactas que te muestre CARTO para los estilos Voyager y Dark Matter.
// define('MAP_TILES', [
//     'light'       => 'URL_VOYAGER_CON_TU_CLAVE',
//     'dark'        => 'URL_DARK_MATTER_CON_TU_CLAVE',
//     'subdomains'  => 'abcd',
//     'maxZoom'     => 20,
//     'attribution' => '&copy; OpenStreetMap &copy; CARTO',
// ]);
