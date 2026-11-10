import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: ['./resources/views/**/*.blade.php', './resources/js/**/*.jsx'],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'Figtree', ...defaultTheme.fontFamily.sans],
                mono: ['"JetBrains Mono"', ...defaultTheme.fontFamily.mono],
            },
            colors: {
                brand: {
                    50: '#eef4ff', 100: '#dbe7ff', 200: '#bcd2ff', 300: '#8db4ff', 400: '#5b8cfa',
                    500: '#3b6bf0', 600: '#2551d6', 700: '#1f41ae', 800: '#1e3a8a', 900: '#1b2f6b',
                },
                ink: { 900: '#0f172a', 800: '#1e293b', 700: '#334155' },
            },
            boxShadow: {
                card: '0 1px 2px rgba(15,23,42,.04), 0 1px 3px rgba(15,23,42,.06)',
                pop: '0 10px 30px -8px rgba(15,23,42,.25)',
            },
        },
    },

    plugins: [forms],
};
