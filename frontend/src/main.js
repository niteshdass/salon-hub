import '@fontsource-variable/fraunces'
import '@fontsource-variable/manrope'
import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { ACCENT_STORAGE_KEY, applyAccent } from './lib/theme'

// Paint before the first frame: waiting for /auth/me would flash the brand
// terracotta and then snap to the salon's colour.
applyAccent(localStorage.getItem(ACCENT_STORAGE_KEY))

const app = createApp(App)

app.use(createPinia())
app.use(router)

app.mount('#app')
