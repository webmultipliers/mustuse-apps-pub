import { defineConfig } from 'vitest/config';
import { resolve } from 'path';

export default defineConfig({
    resolve: {
        alias: {
            '@shared': resolve(__dirname, 'assets/shared'),
            '@editorial': resolve(__dirname, 'assets/editorial'),
            '@developer': resolve(__dirname, 'assets/developer'),
            '@preview': resolve(__dirname, 'assets/preview'),
        },
    },
    test: {
        environment: 'jsdom',
        include: ['tests/ts/**/*.test.ts'],
        coverage: {
            include: ['assets/**/*.ts'],
            reporter: ['text', 'html'],
        },
    },
});
