<template>
  <div class="load-generator">
    <h3>🚀 Load Generator</h3>
    <div class="controls">
      <div class="field">
        <label for="gen-campaign">Campaign ID:</label>
        <input id="gen-campaign" v-model="genCampaignId" type="text" placeholder="camp-1" />
      </div>
      <div class="field">
        <label for="gen-count">Event Count:</label>
        <input id="gen-count" v-model.number="eventCount" type="number" min="1" max="100000" />
      </div>
      <div class="field">
        <label for="gen-concurrency">Concurrency:</label>
        <input id="gen-concurrency" v-model.number="concurrency" type="number" min="1" max="100" />
      </div>
      <button @click="startGeneration" :disabled="isRunning">
        {{ isRunning ? 'Sending...' : 'Fire Events' }}
      </button>
    </div>

    <div v-if="progress.total > 0" class="progress">
      <div class="progress-bar">
        <div class="progress-fill" :style="{ width: progressPercent + '%' }"></div>
      </div>
      <p>
        {{ progress.sent }} / {{ progress.total }} sent
        <span v-if="progress.errors > 0" class="error-count">({{ progress.errors }} errors)</span>
        <span v-if="!isRunning && progress.sent === progress.total"> — Done! {{ elapsedTime }}ms</span>
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, defineProps } from 'vue'

const props = defineProps({
  campaignId: { type: String, default: '' }
})

const API_BASE = import.meta.env.VITE_API_BASE || ''

const genCampaignId = ref(props.campaignId || 'camp-1')
const eventCount = ref(100)
const concurrency = ref(10)
const isRunning = ref(false)
const startTime = ref(0)
const endTime = ref(0)
const progress = ref({ total: 0, sent: 0, errors: 0 })

const progressPercent = computed(() => {
  if (progress.value.total === 0) return 0
  return Math.round((progress.value.sent / progress.value.total) * 100)
})

const elapsedTime = computed(() => {
  if (!endTime.value || !startTime.value) return 0
  return endTime.value - startTime.value
})

const EVENT_TYPES = ['sent', 'opened', 'clicked', 'bounced']

function generateEventId() {
  return `evt-${Date.now()}-${Math.random().toString(36).substring(2, 10)}`
}

async function sendEvent(campaignId, index) {
  const event = {
    event_id: generateEventId(),
    campaign_id: campaignId,
    type: EVENT_TYPES[index % EVENT_TYPES.length],
    timestamp: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z')
  }

  try {
    const response = await fetch(`${API_BASE}/events`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(event)
    })

    if (!response.ok) {
      progress.value.errors++
    }
  } catch {
    progress.value.errors++
  }

  progress.value.sent++
}

async function startGeneration() {
  const campaign = genCampaignId.value.trim() || 'camp-1'
  const total = eventCount.value
  const conc = concurrency.value

  isRunning.value = true
  startTime.value = Date.now()
  endTime.value = 0
  progress.value = { total, sent: 0, errors: 0 }

  // Send events in batches with controlled concurrency
  const queue = Array.from({ length: total }, (_, i) => i)

  async function worker() {
    while (queue.length > 0) {
      const index = queue.shift()
      if (index === undefined) break
      await sendEvent(campaign, index)
    }
  }

  const workers = Array.from({ length: Math.min(conc, total) }, () => worker())
  await Promise.all(workers)

  endTime.value = Date.now()
  isRunning.value = false
}
</script>

<style scoped>
.load-generator {
  background: white;
  padding: 1.5rem;
  border-radius: 8px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
  margin-top: 1rem;
}

.load-generator h3 {
  margin-bottom: 1rem;
}

.controls {
  display: flex;
  gap: 1rem;
  align-items: flex-end;
  flex-wrap: wrap;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.field label {
  font-size: 0.8rem;
  font-weight: 600;
  color: #666;
}

.field input {
  padding: 0.4rem 0.6rem;
  border: 1px solid #ddd;
  border-radius: 4px;
  width: 120px;
}

.field input[type="text"] {
  width: 150px;
}

.controls button {
  padding: 0.5rem 1.25rem;
  background: #e63946;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-weight: 600;
}

.controls button:disabled {
  background: #ccc;
  cursor: not-allowed;
}

.progress {
  margin-top: 1rem;
}

.progress-bar {
  height: 8px;
  background: #eee;
  border-radius: 4px;
  overflow: hidden;
  margin-bottom: 0.5rem;
}

.progress-fill {
  height: 100%;
  background: #4361ee;
  transition: width 0.2s;
}

.progress p {
  font-size: 0.85rem;
  color: #666;
}

.error-count {
  color: #e63946;
}
</style>
