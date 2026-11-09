import { createRouter, createWebHistory } from 'vue-router';

import { useAuthStore } from '@/stores/auth';
import DashboardPage from '@/pages/DashboardPage.vue';
import ForgotPasswordPage from '@/pages/ForgotPasswordPage.vue';
import LoginPage from '@/pages/LoginPage.vue';
import NotFoundPage from '@/pages/NotFoundPage.vue';
import ResetPasswordPage from '@/pages/ResetPasswordPage.vue';
import TwoFactorChallengePage from '@/pages/TwoFactorChallengePage.vue';

const router = createRouter({
    history: createWebHistory('/'),
    routes: [
        {
            path: '/login',
            name: 'login',
            component: LoginPage,
            meta: { public: true },
        },
        {
            path: '/forgot-password',
            name: 'forgot-password',
            component: ForgotPasswordPage,
            meta: { public: true },
        },
        {
            path: '/reset-password',
            name: 'reset-password',
            component: ResetPasswordPage,
            meta: { public: true },
        },
        {
            path: '/two-factor-challenge',
            name: 'two-factor-challenge',
            component: TwoFactorChallengePage,
            meta: { public: true },
        },
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

router.beforeEach(async (to) => {
    if (to.meta.public) {
        return true;
    }

    const auth = useAuthStore();

    if (auth.user === null && !auth.loading) {
        await auth.fetchMe();
    }

    if (auth.user === null) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }

    return true;
});

export default router;
