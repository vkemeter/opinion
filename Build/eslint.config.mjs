import js from '@eslint/js';
import globals from 'globals';

// Run from the repository root: make lint-js
export default [
  js.configs.recommended,
  {
    files: ['Resources/Public/JavaScript/**/*.js'],
    languageOptions: { ecmaVersion: 'latest', sourceType: 'module', globals: globals.browser },
  },
  {
    // The admin panel loads module scripts as classic scripts
    files: ['Resources/Public/JavaScript/adminpanel-module.js'],
    languageOptions: { sourceType: 'script' },
  },
  {
    files: ['Tests/Browser/**/*.mjs'],
    languageOptions: { ecmaVersion: 'latest', sourceType: 'module', globals: { ...globals.node, ...globals.browser } },
  },
];
