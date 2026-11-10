import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite'
import laravel from 'laravel-vite-plugin'

export default defineConfig(({ command }) => ({
  server: {
    host: 'fillmein.local',
    port: 6012,
    strictPort: true,
    cors: true,
    hmr: {
      protocol: 'ws',
      host: 'fillmein.local',
      port: 6012,
    },
  },

  base: command === 'build'
    ? '/wp-content/themes/fillmein/public/build/'
    : '/build/',

  plugins: [
    tailwindcss(),

    laravel({
      input: [
        'resources/css/fillmein.css',
        'resources/js/fillmein.js',
      ],
      refresh: true,
    }),
  ],

  resolve: {
    alias: {
      '@scripts': '/resources/js',
      '@styles': '/resources/css',
    },
  },
}))
