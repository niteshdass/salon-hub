<script setup>
import { ref } from 'vue'
import api from '@/lib/api'

const loading = ref(false)
const result = ref(null)
const error = ref(null)

async function testApi() {
  loading.value = true
  result.value = null
  error.value = null
  try {
    const { data } = await api.get('/hello')
    result.value = data
  } catch (e) {
    error.value = e.message || 'Request failed'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <main class="min-h-screen flex items-center justify-center bg-slate-50 p-6">
    <div class="w-full max-w-md rounded-2xl bg-white p-8 shadow-lg ring-1 ring-slate-200">
      <h1 class="text-2xl font-bold text-slate-900">SalonHub</h1>
      <p class="mt-1 text-sm text-slate-500">Frontend ↔ Backend connectivity check</p>

      <button
        type="button"
        :disabled="loading"
        class="mt-6 w-full rounded-lg bg-indigo-600 px-4 py-2.5 font-medium text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
        @click="testApi"
      >
        {{ loading ? 'Testing…' : 'Test API' }}
      </button>

      <div
        v-if="result"
        class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800"
      >
        <p class="font-semibold">✓ {{ result.message }}</p>
        <pre class="mt-2 overflow-x-auto text-xs text-emerald-700">{{ JSON.stringify(result, null, 2) }}</pre>
      </div>

      <div
        v-if="error"
        class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700"
      >
        ✗ {{ error }}
      </div>
    </div>
  </main>
</template>
