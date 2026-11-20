import js from '@eslint/js';
import globals from 'globals';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import jsxA11y from 'eslint-plugin-jsx-a11y';

// Correctness-focused lint (CI gate: errors fail, warnings are reported only).
// Stylistic rules are deliberately off; JS formatting is not enforced.
// Existing violations are recorded in eslint-suppressions.json (bulk suppressions),
// so only new problems fail. Regenerate after fixing some: npx eslint . --prune-suppressions
const shared = {
    'no-empty': ['error', { allowEmptyCatch: true }],
    'no-unused-vars': ['error', { ignoreRestSiblings: true, argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
};

export default [
    { ignores: ['public/**', 'vendor/**', 'node_modules/**', 'storage/**', 'bootstrap/**', 'test-results/**', 'playwright-report/**'] },
    js.configs.recommended,
    {
        files: ['resources/js/**/*.{js,jsx}'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            parserOptions: { ecmaFeatures: { jsx: true } },
            globals: { ...globals.browser, route: 'readonly' },
        },
        settings: { react: { version: 'detect' } },
        plugins: { react, 'react-hooks': reactHooks, 'jsx-a11y': jsxA11y },
        rules: {
            ...react.configs.recommended.rules,
            ...react.configs['jsx-runtime'].rules,
            ...jsxA11y.flatConfigs.recommended.rules,
            ...shared,
            'react/prop-types': 'off',
            // Deliberate keyboard-first data entry: the first field of forms and dialogs is focused.
            'jsx-a11y/no-autofocus': 'off',
            'react-hooks/rules-of-hooks': 'error',
            'react-hooks/exhaustive-deps': 'warn',
        },
    },
    {
        // PrintSheet passes keys through a spread props object ({...common}), which react/jsx-key cannot see.
        files: ["resources/js/Components/PrintSheet.jsx"],
        rules: { "react/jsx-key": "off" },
    },
    {
        // Playwright specs: Node test code plus browser code inside page.evaluate().
        files: ['e2e/**/*.js'],
        languageOptions: { ecmaVersion: 'latest', sourceType: 'module', globals: { ...globals.node, ...globals.browser } },
        rules: { ...shared, 'no-control-regex': 'off' },
    },
    {
        files: ['*.config.js'],
        languageOptions: { ecmaVersion: 'latest', sourceType: 'module', globals: { ...globals.node } },
    },
];
