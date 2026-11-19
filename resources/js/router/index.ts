import { createRouter, createWebHistory } from 'vue-router';

import { useAuthStore } from '@/stores/auth';
import DashboardPage from '@/pages/DashboardPage.vue';
import ForgotPasswordPage from '@/pages/ForgotPasswordPage.vue';
import FiscalDocumentInboxPage from '@/pages/FiscalDocumentInboxPage.vue';
import LoginPage from '@/pages/LoginPage.vue';
import NotFoundPage from '@/pages/NotFoundPage.vue';
import ResetPasswordPage from '@/pages/ResetPasswordPage.vue';
import SyncMonitorPage from '@/pages/SyncMonitorPage.vue';
import TwoFactorChallengePage from '@/pages/TwoFactorChallengePage.vue';
import TwoFactorSetupPage from '@/pages/TwoFactorSetupPage.vue';

const router = createRouter({
    history: createWebHistory('/'),
    routes: [
        {
            path: '/login',
            name: 'login',
            component: LoginPage,
            meta: { public: true, title: 'Iniciar sesión' },
        },
        {
            path: '/forgot-password',
            name: 'forgot-password',
            component: ForgotPasswordPage,
            meta: { public: true, title: 'Recuperar contraseña' },
        },
        {
            path: '/reset-password',
            name: 'reset-password',
            component: ResetPasswordPage,
            meta: { public: true, title: 'Restablecer contraseña' },
        },
        {
            path: '/two-factor-challenge',
            name: 'two-factor-challenge',
            component: TwoFactorChallengePage,
            meta: { public: true, title: 'Verificación en dos pasos' },
        },
        {
            path: '/two-factor/setup',
            name: 'two-factor-setup',
            component: TwoFactorSetupPage,
            meta: { title: 'Seguridad' },
        },
        {
            path: '/',
            name: 'dashboard',
            component: DashboardPage,
            meta: { title: 'Panel' },
        },
        {
            path: '/fiscal_documents',
            name: 'fiscal_documents',
            component: FiscalDocumentInboxPage,
            meta: { title: 'Comprobantes' },
        },
        {
            path: '/sync',
            name: 'sync',
            component: SyncMonitorPage,
            meta: { title: 'Sincronización' },
        },
        {
            path: '/:pathMatch(.*)*',
            name: 'not-found',
            component: NotFoundPage,
            meta: { public: true, title: 'No encontrado' },
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
