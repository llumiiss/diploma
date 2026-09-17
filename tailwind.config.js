/**
 * Konfiguracja Tailwinda (ta sama paleta co wcześniej w includes/head.php).
 *
 * Budowanie: `npm install && npm run css` → assets/css/app.css. Gotowy arkusz jest
 * w repozytorium, więc aplikacja działa bez Node i bez internetu (wymaganie N3).
 * Skaner klas czyta pliki PHP i szablony komponentów Vue z assets/js.
 */
module.exports = {
    content: [
        './*.php',
        './includes/**/*.php',
        './assets/js/**/*.js',
    ],
    theme: {
        extend: {
            colors: {
                brand: {
                    50: '#eff6ff',
                    100: '#dbeafe',
                    200: '#bfdbfe',
                    300: '#93c5fd',
                    400: '#60a5fa',
                    500: '#3b82f6',
                    600: '#2563eb',
                    700: '#1d4ed8',
                    800: '#1e40af',
                    900: '#1e3a5f',
                },
            },
            fontFamily: {
                sans: ['Inter', 'system-ui', 'sans-serif'],
            },
        },
    },
    plugins: [],
};
