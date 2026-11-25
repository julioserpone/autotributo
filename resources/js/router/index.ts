import { createRouter, createWebHistory } from 'vue-router';

import { useAuthStore } from '@/stores/auth';
import { useCompanyStore } from '@/stores/company';

// Carga diferida de las vistas: cada página se descarga solo cuando se navega a
// ella. Reduce el bundle inicial y la memoria del navegador en la primera carga.
const DashboardPage = () => import('@/pages/DashboardPage.vue');
const ForgotPasswordPage = () => import('@/pages/ForgotPasswordPage.vue');
const CompaniesPage = () => import('@/pages/CompaniesPage.vue');
const InvitationsPage = () => import('@/pages/InvitationsPage.vue');
const AcceptInvitationPage = () => import('@/pages/AcceptInvitationPage.vue');
const ClassificationReviewPage = () => import('@/pages/ClassificationReviewPage.vue');
const FiscalDocumentInboxPage = () => import('@/pages/FiscalDocumentInboxPage.vue');
const FiscalFindingsPage = () => import('@/pages/FiscalFindingsPage.vue');
const Form104Page = () => import('@/pages/Form104Page.vue');
const LoginPage = () => import('@/pages/LoginPage.vue');
const NotFoundPage = () => import('@/pages/NotFoundPage.vue');
const ResetPasswordPage = () => import('@/pages/ResetPasswordPage.vue');
const SyncMonitorPage = () => import('@/pages/SyncMonitorPage.vue');
const TwoFactorChallengePage = () => import('@/pages/TwoFactorChallengePage.vue');
const TwoFactorSetupPage = () => import('@/pages/TwoFactorSetupPage.vue');

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
            path: '/companies',
            name: 'companies',
            component: CompaniesPage,
            meta: { title: 'Contribuyentes' },
        },
        {
            path: '/invitations',
            name: 'invitations',
            component: InvitationsPage,
            meta: { title: 'Equipo e invitaciones' },
        },
        {
            path: '/accept-invitation',
            name: 'accept-invitation',
            component: AcceptInvitationPage,
            meta: { public: true, title: 'Aceptar invitación' },
        },
        {
            path: '/fiscal_documents',
            name: 'fiscal_documents',
            component: FiscalDocumentInboxPage,
            meta: { title: 'Comprobantes', requiresCompany: true },
        },
        {
            path: '/fiscal-findings',
            name: 'fiscal-findings',
            component: FiscalFindingsPage,
            meta: { title: 'Hallazgos fiscales', requiresCompany: true },
        },
        {
            path: '/classifications',
            name: 'classifications',
            component: ClassificationReviewPage,
            meta: { title: 'Revisión de clasificación', requiresCompany: true },
        },
        {
            path: '/declarations/104',
            name: 'form-104',
            component: Form104Page,
            meta: { title: 'Formulario 104', requiresCompany: true },
        },
        {
            path: '/sync',
            name: 'sync',
            component: SyncMonitorPage,
            meta: { title: 'Sincronización', requiresCompany: true },
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

    if (to.meta.requiresCompany === true) {
        const companyStore = useCompanyStore();

        if (!companyStore.loaded) {
            await companyStore.fetchCompanies();
        }

        if (companyStore.requiresTwoFactorSetup) {
            return { name: 'two-factor-setup' };
        }

        if (!companyStore.hasCompany) {
            return { name: 'companies', query: { onboarding: '1' } };
        }
    }

    return true;
});

export default router;
