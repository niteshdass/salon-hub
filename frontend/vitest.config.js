import { mergeConfig, defineConfig } from 'vitest/config'
import viteConfig from './vite.config.js'

// Extends the real build config (alias, plugins) with `mergeConfig` rather
// than duplicating it, so the test environment can never silently diverge
// from `vite build` — e.g. the `@` alias only needs to be defined once.
export default mergeConfig(
  viteConfig,
  defineConfig({
    test: {
      environment: 'jsdom',
      include: ['src/**/*.spec.js'],
    },
  }),
)
