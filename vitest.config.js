import { defineConfig } from 'vitest/config';

// Testes de componente React em jsdom. Usa o JSX automático do esbuild
// (não precisa importar React), evitando incompatibilidades de plugin com o Vite 8.
export default defineConfig({
    esbuild: { jsx: 'automatic' },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: './resources/js/test/setup.js',
        include: ['resources/js/**/*.test.{js,jsx}'],
    },
});
