import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/**
 * Night is a class on <html>, set server-side from the `theme` cookie in
 * resources/views/layouts/app.blade.php so a day-mode reload never flashes.
 *
 * Colours are NOT Tailwind palette entries: every chrome colour is a CSS
 * custom property defined once in resources/css/shell.css (:root = day,
 * html.dark = night), and Tailwind only ever reads them through var().
 * That is what keeps one palette instead of two.
 *
 * @type {import('tailwindcss').Config}
 */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Atkinson Hyperlegible Next', 'Atkinson Hyperlegible', ...defaultTheme.fontFamily.sans],
                mono: ['Atkinson Hyperlegible Mono', ...defaultTheme.fontFamily.mono],
                serif: ['Source Serif 4', ...defaultTheme.fontFamily.serif],
            },
            colors: {
                desk: 'var(--desk)',
                'desk-raised': 'var(--desk-raised)',
                rule: 'var(--rule)',
                paper: 'var(--paper)',
                text: 'var(--text)',
                'text-2': 'var(--text-2)',
                ink: 'var(--ink)',
                marker: 'var(--marker)',
                signal: 'var(--signal)',
                good: 'var(--good)',
                danger: 'var(--danger)',
            },
        },
    },

    plugins: [forms, typography],
};
