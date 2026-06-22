<template>
  <div class="app">
    <h1>📧 Email Campaign Dashboard</h1>
    <CampaignDashboard />
  </div>
</template>

<script setup>
import CampaignDashboard from './components/CampaignDashboard.vue'
</script>

<style>
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  background: #f5f7fa;
  color: #333;
  min-height: 100vh;
}

.app {
  max-width: 900px;
  margin: 0 auto;
  padding: 2rem;
}

h1 {
  text-align: center;
  margin-bottom: 2rem;
  color: #1a1a2e;
}
</style>
