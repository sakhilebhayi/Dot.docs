/*
 * dot-ui Tailwind 3 preset: maps the shared type scale into Tailwind in one place. Values are var() references into css/dot-type.css,
 * so the scale (sizes, weights, line heights, letter spacing) lives in ONE file and the host config never repeats a number.
 *
 *   // tailwind.config.js (CommonJS)
 *   module.exports = { presets: [require('./dot-ui/tailwind/tailwind-preset.cjs')], content: [...] }
 *   // tailwind.config.js (ESM, Laravel's default "type": "module")
 *   import dotPreset from './dot-ui/tailwind/tailwind-preset.cjs'; export default { presets: [dotPreset], content: [...] }
 *
 * Gives: font-sans / font-mono, and text-dot-display ... text-dot-table (size + line-height + letter-spacing + weight in one class).
 * It is a .cjs file so it loads in both module systems. Tailwind 4 hosts use tailwind-theme.css instead.
 * The host must still load css/dot-roboto.css and css/dot-type.css (the tokens and the font files).
 */
module.exports = {
  theme: {
    extend: {
      fontFamily: {
        sans: ['var(--dot-font-sans)'],
        mono: ['var(--dot-font-mono)'],
      },
      fontSize: {
        'dot-display': ['var(--dot-text-display-size)', { lineHeight: 'var(--dot-text-display-lh)', letterSpacing: 'var(--dot-text-display-ls)', fontWeight: 'var(--dot-text-display-weight)' }],
        'dot-h1': ['var(--dot-text-h1-size)', { lineHeight: 'var(--dot-text-h1-lh)', letterSpacing: 'var(--dot-text-h1-ls)', fontWeight: 'var(--dot-text-h1-weight)' }],
        'dot-h2': ['var(--dot-text-h2-size)', { lineHeight: 'var(--dot-text-h2-lh)', letterSpacing: 'var(--dot-text-h2-ls)', fontWeight: 'var(--dot-text-h2-weight)' }],
        'dot-h3': ['var(--dot-text-h3-size)', { lineHeight: 'var(--dot-text-h3-lh)', letterSpacing: 'var(--dot-text-h3-ls)', fontWeight: 'var(--dot-text-h3-weight)' }],
        'dot-h4': ['var(--dot-text-h4-size)', { lineHeight: 'var(--dot-text-h4-lh)', letterSpacing: 'var(--dot-text-h4-ls)', fontWeight: 'var(--dot-text-h4-weight)' }],
        'dot-body-lg': ['var(--dot-text-body-lg-size)', { lineHeight: 'var(--dot-text-body-lg-lh)', letterSpacing: 'var(--dot-text-body-lg-ls)', fontWeight: 'var(--dot-text-body-lg-weight)' }],
        'dot-body': ['var(--dot-text-body-size)', { lineHeight: 'var(--dot-text-body-lh)', letterSpacing: 'var(--dot-text-body-ls)', fontWeight: 'var(--dot-text-body-weight)' }],
        'dot-body-sm': ['var(--dot-text-body-sm-size)', { lineHeight: 'var(--dot-text-body-sm-lh)', letterSpacing: 'var(--dot-text-body-sm-ls)', fontWeight: 'var(--dot-text-body-sm-weight)' }],
        'dot-caption': ['var(--dot-text-caption-size)', { lineHeight: 'var(--dot-text-caption-lh)', letterSpacing: 'var(--dot-text-caption-ls)', fontWeight: 'var(--dot-text-caption-weight)' }],
        'dot-label': ['var(--dot-text-label-size)', { lineHeight: 'var(--dot-text-label-lh)', letterSpacing: 'var(--dot-text-label-ls)', fontWeight: 'var(--dot-text-label-weight)' }],
        'dot-button': ['var(--dot-text-button-size)', { lineHeight: 'var(--dot-text-button-lh)', letterSpacing: 'var(--dot-text-button-ls)', fontWeight: 'var(--dot-text-button-weight)' }],
        'dot-input': ['var(--dot-text-input-size)', { lineHeight: 'var(--dot-text-input-lh)', letterSpacing: 'var(--dot-text-input-ls)', fontWeight: 'var(--dot-text-input-weight)' }],
        'dot-table': ['var(--dot-text-table-size)', { lineHeight: 'var(--dot-text-table-lh)', letterSpacing: 'var(--dot-text-table-ls)', fontWeight: 'var(--dot-text-table-weight)' }],
      },
    },
  },
};
