 import { createPinia } from 'pinia';
import { createApp } from 'vue';
import ui from '@nuxt/ui/vue-plugin';

import { initTheme } from '@/composables/useTheme';
import App from '@/App.vue';
import router from '@/router';

initTheme();

createApp(App).use(createPinia()).use(router).use(ui).mount('#app');
