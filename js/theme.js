/**
 * Tema según la luz del día: oscuro desde la puesta de sol hasta el amanecer.
 * Se carga en <head> para fijar el tema antes de pintar la página.
 */
(function () {
    const DEFAULT_LOC = { lat: 40.42, lon: -3.70 }; // Madrid, hasta tener GPS
    const STORAGE_KEY = 'rumbo-last-location';
    const THEME_COLORS = { light: '#FBFBFC', dark: '#15191F' };
    const RAD = Math.PI / 180;

    let loc = DEFAULT_LOC;
    try {
        const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
        if (saved && isFinite(saved.lat) && isFinite(saved.lon)) loc = saved;
    } catch (e) {}

    /** Amanecer y puesta de sol (ms UTC) con la ecuación del amanecer */
    function sunTimes(date, lat, lon) {
        // Día juliano a mediodía UTC de esa fecha, para que toda la tarde use el mismo día
        const noon = Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate(), 12);
        const julian = noon / 86400000 + 2440587.5;
        const n = Math.round(julian - 2451545.0);
        const meanSolarTime = n - lon / 360;
        const M = (357.5291 + 0.98560028 * meanSolarTime) % 360;
        const C = 1.9148 * Math.sin(M * RAD) + 0.02 * Math.sin(2 * M * RAD) + 0.0003 * Math.sin(3 * M * RAD);
        const lambda = (M + C + 180 + 102.9372) % 360;
        const transit = 2451545.0 + meanSolarTime + 0.0053 * Math.sin(M * RAD) - 0.0069 * Math.sin(2 * lambda * RAD);
        const sinDecl = Math.sin(lambda * RAD) * Math.sin(23.4397 * RAD);
        const cosDecl = Math.cos(Math.asin(sinDecl));
        const cosHour = (Math.sin(-0.833 * RAD) - Math.sin(lat * RAD) * sinDecl) / (Math.cos(lat * RAD) * cosDecl);
        if (cosHour > 1) return { polarNight: true };
        if (cosHour < -1) return { polarDay: true };
        const hour = Math.acos(cosHour) / RAD / 360;
        const toMs = j => (j - 2440587.5) * 86400000;
        return { rise: toMs(transit - hour), set: toMs(transit + hour) };
    }

    function themeFor(date) {
        const s = sunTimes(date, loc.lat, loc.lon);
        if (s.polarNight) return 'dark';
        if (s.polarDay) return 'light';
        const t = date.getTime();
        return t >= s.rise && t < s.set ? 'light' : 'dark';
    }

    function apply() {
        const theme = themeFor(new Date());
        const root = document.documentElement;
        if (root.dataset.theme === theme) return;
        root.dataset.theme = theme;
        const meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute('content', THEME_COLORS[theme]);
        document.dispatchEvent(new CustomEvent('rumbo:themechange', { detail: { theme } }));
    }

    window.RumboTheme = {
        apply,
        isDark: () => document.documentElement.dataset.theme === 'dark',
        setLocation(lat, lon) {
            loc = { lat: Math.round(lat * 100) / 100, lon: Math.round(lon * 100) / 100 };
            try { localStorage.setItem(STORAGE_KEY, JSON.stringify(loc)); } catch (e) {}
            apply();
        },
        sunTimes
    };

    apply();
    setInterval(apply, 60000);
})();
