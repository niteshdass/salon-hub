import { mergeConfig, defineConfig } from 'vitest/config'
import viteConfig from './vite.config.js'

// Extends the real build config (alias, plugins) with `mergeConfig` rather
// than duplicating it, so the test environment can never silently diverge
// from `vite build` — e.g. the `@` alias only needs to be defined once.
//
// This assumes vite.config.js default-exports a plain object. If it is ever
// converted to the function form (`defineConfig(({ mode }) => ({ ... }))`,
// e.g. to branch on env), `mergeConfig` will receive a function instead of
// config and silently drop the alias/plugins or throw — update this file to
// call that function first if that conversion ever happens.
export default mergeConfig(
  viteConfig,
  defineConfig({
    test: {
      environment: 'jsdom',
      include: ['src/**/*.spec.js'],
    },
  }),
)
