import { defineConfig } from 'vitest/config';

// Testes de componente React em jsdom. O Vitest 4 usa o oxc, que já aplica o
// JSX automático (não precisa importar React nem configurar esbuild/plugin).
export default defineConfig({
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: './resources/js/test/setup.js',
        include: ['resources/js/**/*.test.{js,jsx}'],
    },
});
