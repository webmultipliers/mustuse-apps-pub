import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
    build: {
        outDir: 'dist',
        manifest: true,
        rollupOptions: {
            input: {
                editorial: resolve(__dirname, 'assets/editorial/main.ts'),
                preview: resolve(__dirname, 'assets/preview/main.ts'),
                'editorial-style': resolve(__dirname, 'assets/editorial/main.scss'),
                'preview-style': resolve(__dirname, 'assets/preview/main.scss'),
            },
            output: {
                entryFileNames: 'js/[name].js',
                assetFileNames: 'css/[name].css',
            },
        },
    },
    css: {
        preprocessorOptions: {
            scss: {
                additionalData: '',
            },
        },
    },
});
