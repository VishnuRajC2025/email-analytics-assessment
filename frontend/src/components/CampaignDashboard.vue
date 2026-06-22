<template>
  <div class="dashboard">
    <!-- Campaign selector -->
    <div class="campaign-selector">
      <label for="campaign-input">Campaign ID:</label>
      <input
        id="campaign-input"
        v-model="campaignId"
        type="text"
        placeholder="Enter campaign ID (e.g. camp-1)"
        @keyup.enter="startPolling"
      />
      <button @click="startPolling" :disabled="!campaignId.trim()">Track</button>
    </div>

    <!-- Loading state -->
    <div v-if="state === 'loading'" class="state-card loading">
      <div class="spinner"></div>
      <p>Loading campaign stats...</p>
    </div>

    <!-- Error state -->
    <div v-else-if="state === 'error'" class="state-card error">
      <p class="error-icon">⚠️</p>
      <p>{{ errorMessage }}</p>
      <button @click="fetchStats" class="retry-btn">Retry</button>
    </div>

    <!-- Empty state -->
    <div v-else-if="state === 'empty'" class="state-card empty">
      <p class="empty-icon">📭</p>
      <p>No events recorded for campaign <strong>{{ activeCampaign }}</strong> yet.</p>
      <p class="hint">Events will appear here as they stream in.</p>
    </div>

    <!-- Data state -->
    <div v-else-if="state === 'data'" class="stats-container">
      <h2>Campaign: {{ activeCampaign }}</h2>

      <div class="stats-grid">
        <div class="stat-card sent">
          <div class="stat-value">{{ stats.sent.toLocaleString() }}</div>
          <div class="stat-label">Sent</div>
        </div>
        <div class="stat-card opened">
          <div class="stat-value">{{ stats.opened.toLocaleString() }}</div>
          <div class="stat-label">Opened</div>
          <div class="stat-rate">{{ openRate }}%</div>
        </div>
        <div class="stat-card clicked">
          <div class="stat-value">{{ stats.clicked.toLocaleString() }}</div>
          <div class="stat-label">Clicked</div>
          <div class="stat-rate">{{ clickRate }}%</div>
        </div>
        <div class="stat-card bounced">
          <div class="stat-value">{{ stats.bounced.toLocaleString() }}</div>
          <div class="stat-label">Bounced</div>
          <div class="stat-rate">{{ bounceRate }}%</div>
        </div>
      </div>

      <div class="last-updated">
        Last updated: {{ lastUpdatedFormatted }}
        <span class="poll-indicator" :class="{ stale: isStale }">
          {{ isStale ? '⚠️ Stale' : '🟢 Live' }}
        </span>
      </div>
    </div>

    <!-- Idle state (no campaign selected yet) -->
    <div v-else class="state-card idle">
      <p>Enter a campaign ID above to start tracking.</p>
    </div>

    <!-- Load Generator -->
    <LoadGenerator :campaignId="activeCampaign || campaignId" />
  </div>
</template>

<script setup>
import { ref, computed, onUnmounted } from 'vue'
import LoadGenerator from './LoadGenerator.vue'

const API_BASE = import.meta.env.VITE_API_BASE || ''
const POLL_INTERVAL = 5000 // 5 seconds
const STALE_THRESHOLD = 15000 // 15 seconds without update = stale

const campaignId = ref('')
const activeCampaign = ref('')
const stats = ref({ sent: 0, opened: 0, clicked: 0, bounced: 0 })
const state = ref('idle') // idle | loading | data | empty | error
const errorMessage = ref('')
const lastUpdated = ref(null)
let pollTimer = null

// Derived rates
const openRate = computed(() => {
  if (stats.value.sent === 0) return '0.0'
  return ((stats.value.opened / stats.value.sent) * 100).toFixed(1)
})

const clickRate = computed(() => {
  if (stats.value.sent === 0) return '0.0'
  return ((stats.value.clicked / stats.value.sent) * 100).toFixed(1)
})

const bounceRate = computed(() => {
  if (stats.value.sent === 0) return '0.0'
  return ((stats.value.bounced / stats.value.sent) * 100).toFixed(1)
})

const lastUpdatedFormatted = computed(() => {
  if (!lastUpdated.value) return 'Never'
  return lastUpdated.value.toLocaleTimeString()
})

const isStale = computed(() => {
  if (!lastUpdated.value) return false
  return (Date.now() - lastUpdated.value.getTime()) > STALE_THRESHOLD
})

function startPolling() {
  const id = campaignId.value.trim()
  if (!id) return

  activeCampaign.value = id
  state.value = 'loading'

  // Clear existing timer
  if (pollTimer) {
    clearTimeout(pollTimer)
    pollTimer = null
  }

  // Fetch immediately, then schedule next poll after completion
  poll()
}

async function poll() {
  await fetchStats()
  // Schedule next poll only after current request completes (no stacking)
  pollTimer = setTimeout(poll, POLL_INTERVAL)
}

async function fetchStats() {
  try {
    const response = await fetch(`${API_BASE}/campaigns/${encodeURIComponent(activeCampaign.value)}/stats`)

    if (!response.ok) {
      throw new Error(`Server returned ${response.status}`)
    }

    const data = await response.json()
    stats.value = data
    lastUpdated.value = new Date()

    // Determine if empty (all counts zero)
    const total = data.sent + data.opened + data.clicked + data.bounced
    state.value = total === 0 ? 'empty' : 'data'
    errorMessage.value = ''
  } catch (err) {
    // Only show error if we haven't loaded data yet, or if it persists
    if (state.value === 'loading' || state.value === 'error') {
      state.value = 'error'
      errorMessage.value = err.message || 'Failed to reach the stats endpoint'
    }
    // If we already have data, keep showing it (stale indicator will appear)
  }
}

onUnmounted(() => {
  if (pollTimer) {
    clearTimeout(pollTimer)
  }
})
</script>

<style scoped>
.dashboard {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.campaign-selector {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  background: white;
  padding: 1rem 1.5rem;
  border-radius: 8px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.campaign-selector label {
  font-weight: 600;
  white-space: nowrap;
}

.campaign-selector input {
  flex: 1;
  padding: 0.5rem 0.75rem;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 0.95rem;
}

.campaign-selector button {
  padding: 0.5rem 1.25rem;
  background: #4361ee;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-weight: 600;
}

.campaign-selector button:disabled {
  background: #ccc;
  cursor: not-allowed;
}

.state-card {
  background: white;
  padding: 3rem;
  border-radius: 8px;
  text-align: center;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.state-card.error {
  border-left: 4px solid #e63946;
}

.error-icon, .empty-icon {
  font-size: 2.5rem;
  margin-bottom: 0.75rem;
}

.retry-btn {
  margin-top: 1rem;
  padding: 0.5rem 1rem;
  background: #e63946;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.hint {
  color: #888;
  font-size: 0.9rem;
  margin-top: 0.5rem;
}

.spinner {
  width: 40px;
  height: 40px;
  border: 4px solid #eee;
  border-top-color: #4361ee;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  margin: 0 auto 1rem;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.stats-container {
  background: white;
  padding: 1.5rem;
  border-radius: 8px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.stats-container h2 {
  margin-bottom: 1rem;
  color: #1a1a2e;
}

.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1rem;
  margin-bottom: 1rem;
}

.stat-card {
  padding: 1.25rem;
  border-radius: 8px;
  text-align: center;
}

.stat-card.sent { background: #e8f4fd; }
.stat-card.opened { background: #e8fdf0; }
.stat-card.clicked { background: #fef3e8; }
.stat-card.bounced { background: #fde8e8; }

.stat-value {
  font-size: 1.75rem;
  font-weight: 700;
  margin-bottom: 0.25rem;
}

.stat-label {
  font-size: 0.85rem;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: #666;
}

.stat-rate {
  font-size: 0.9rem;
  color: #444;
  margin-top: 0.25rem;
  font-weight: 600;
}

.last-updated {
  text-align: right;
  font-size: 0.85rem;
  color: #888;
}

.poll-indicator {
  margin-left: 0.5rem;
}

.poll-indicator.stale {
  color: #e63946;
  font-weight: 600;
}

@media (max-width: 600px) {
  .stats-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .campaign-selector {
    flex-wrap: wrap;
  }
}
</style>
