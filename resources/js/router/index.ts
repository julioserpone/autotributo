import { createRouter, createWebHistory } from 'vue-router';

import DashboardPage from '@/pages/DashboardPage.vue';
import NotFoundPage from '@/pages/NotFoundPage.vue';

const router = createRouter({
    history: createWebHistory(import.meta.env.BASE_URL),
    routes: [
        {
            path: '/',
            name: 'dashboard',
            component: DashboardPage,
        },
        {
            path: '/:pathMatch(.*)*',
            name: 'not-found',
            component: NotFoundPage,
        },
    ],
    scrollBehavior: () => ({ top: 0 }),
});

export default router;
