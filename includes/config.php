<?php
/**
 * Configuración de Rumbo
 */

// Google Maps Geocoding (mejor con números de casa)
define('GOOGLE_GEOCODE_URL', 'https://maps.googleapis.com/maps/api/geocode/json');
define('GOOGLE_API_KEY', 'AIzaSyAWvK-TFi20pbLVLz3eo1fEDfYBqnLV6H8');

// OpenCage Geocoding (gratis, sin tarjeta, buen soporte de números)
define('OPENCAGE_URL', 'https://api.opencagedata.com/geocode/v1/json');
define('OPENCAGE_KEY', '78d04832cf5840998b2d338fd87b0d76');

// Nominatim (geocoding) — OpenStreetMap
define('NOMINATIM_URL', 'https://nominatim.openstreetmap.org/search');
define('NOMINATIM_USER_AGENT', 'Rumbo/1.0 (proyecto-educativo)');

// OSRM (enrutamiento) — servidor público de demo
define('OSRM_URL', 'http://router.project-osrm.org/route/v1/driving');

// Open-Meteo (clima) — sin API key, totalmente gratuito
define('OPENMETEO_URL', 'https://api.open-meteo.com/v1/forecast');

// DGT — tráfico e incidencias
define('DGT_INCIDENCIAS_URL', 'https://infocar.dgt.es/etraffic/BuscarIncidentes');
define('DGT_RSS_URL', 'https://infocar.dgt.es/rss/incidencias.xml');

// Ministerio de Industria — precios carburantes (datos oficiales, sin API key)
define('FUEL_API_URL', 'https://sedeaplicaciones.minetur.gob.es/ServiciosRESTCarburantes/PreciosCarburantes/EstacionesTerrestres/');

// Límites de España continental + Baleares
define('SPAIN_BOUNDS', [
    'south' => 35.5,
    'west'  => -10.0,
    'north' => 44.0,
    'east'  => 4.5
]);

// Cuántos puntos muestrear a lo largo de la ruta para el clima
define('SAMPLE_INTERVAL_KM', 45);
define('MAX_SAMPLES', 20);