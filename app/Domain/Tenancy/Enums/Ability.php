<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Enums;

/**
 * Acciones autorizables del dominio.
 *
 * Se declaran como enum para que la matriz rol × acción sea explícita y
 * verificable por tests, sin depender de strings dispersos en controladores
 * o policies.
 */
enum Ability: string
{
    case CompaniesView = 'companies.view';
    case CompaniesManage = 'companies.manage';
    case CompaniesArchive = 'companies.archive';

    case CredentialsView = 'credentials.view';
    case CredentialsManage = 'credentials.manage';

    case SyncView = 'sync.view';
    case SyncStart = 'sync.start';
    case SyncCancel = 'sync.cancel';

    case InvoicesView = 'invoices.view';
    case InvoicesReview = 'invoices.review';
    case InvoicesExport = 'invoices.export';

    case RulesManage = 'rules.manage';

    case DeclarationsView = 'declarations.view';
    case DeclarationsApprove = 'declarations.approve';
    case DeclarationsExport = 'declarations.export';

    case MembersView = 'members.view';
    case MembersInvite = 'members.invite';
    case MembersManage = 'members.manage';

    case AuditView = 'audit.view';
}
