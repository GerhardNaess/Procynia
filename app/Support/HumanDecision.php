<?php

namespace App\Support;

/**
 * The decisions only a person may take, one case per kind of decision.
 *
 * A service that takes one of these calls HumanDecisionGuard::assert() with its case before it
 * writes, and then applies its own authorization rule. The guard decides what kind of actor may
 * decide at all (a person, not an integration, not through the API); the service still decides
 * whether this person may.
 *
 * permission() names the catalogue key the decision rides on, where there is one. Some decisions
 * rest on ownership (a document owner vouching for their own source) or on the tender role matrix
 * rather than the catalogue; those return null. A decision can be human-only even when its key is
 * not — adopting an AI-proposed process flow needs only quality.edit, but accepting a machine's
 * proposal is exactly the step a machine must not take for itself.
 *
 * See docs/external-api-phase-0-plan.md §4.3–§4.4.
 */
enum HumanDecision: string
{
    // Enterprise Wiki: review and publication.
    case WIKI_PAGE_SUBMIT = 'wiki.page.submit';
    case WIKI_PAGE_WITHDRAW = 'wiki.page.withdraw';
    case WIKI_PAGE_EDIT = 'wiki.page.edit';
    case WIKI_PAGE_APPROVE = 'wiki.page.approve';
    case WIKI_PAGE_RETURN = 'wiki.page.return';
    case WIKI_PAGE_DELETE = 'wiki.page.delete';
    case WIKI_QA_ASSIGN = 'wiki.qa.assign';
    case WIKI_CLAIM_DECIDE = 'wiki.claim.decide';
    case WIKI_CLAIM_REPAIR = 'wiki.claim.repair';
    case WIKI_DOCUMENT_OWNER_DECIDE = 'wiki.document_owner.decide';

    // Enterprise Wiki: source administration.
    case WIKI_SOURCE_UPLOAD = 'wiki.source.upload';
    case WIKI_SOURCE_DELETE = 'wiki.source.delete';
    case WIKI_SOURCE_OWNER_CHANGE = 'wiki.source.owner_change';
    case WIKI_RUN_START = 'wiki.run.start';
    case WIKI_RUN_CANCEL = 'wiki.run.cancel';
    case WIKI_RUN_DISMISS = 'wiki.run.dismiss';

    case QUALITY_FLOW_APPROVE = 'quality.flow.approve';
    case QUALITY_FLOW_ADOPT = 'quality.flow.adopt';
    case QUALITY_DELETE = 'quality.delete';

    case RISK_ASSESS = 'risk.assess';
    case RISK_ACCEPT = 'risk.accept';
    case RISK_DELETE = 'risk.delete';

    case OBJECTIVE_MEASURE = 'objective.measure';
    case OBJECTIVE_DELETE = 'objective.delete';

    /** Close, cancel and reopen a case, and verify the effect of an action. */
    case IMPROVEMENT_CLOSE = 'improvement.close';
    case IMPROVEMENT_DELETE = 'improvement.delete';

    case COMPLIANCE_ASSESS = 'compliance.assess';
    case COMPLIANCE_AUDIT = 'compliance.audit';
    case COMPLIANCE_DELETE = 'compliance.delete';

    case SUPPLIER_ASSESS = 'supplier.assess';
    case SUPPLIER_ASSURE = 'supplier.assure';
    case SUPPLIER_DELETE = 'supplier.delete';

    case MANAGEMENT_REVIEW_FINALIZE = 'management_review.finalize';
    case MANAGEMENT_REVIEW_DELETE = 'management_review.delete';

    case TENDER_REQUIREMENT_DECIDE = 'tender.requirement.decide';
    case TENDER_BID_STATUS = 'tender.bid_status';

    /** The catalogue key the decision rides on, or null when it rests on ownership or the tender role matrix. */
    public function permission(): ?string
    {
        return match ($this) {
            self::WIKI_PAGE_SUBMIT,
            self::WIKI_PAGE_WITHDRAW,
            self::WIKI_PAGE_EDIT => CustomerPermissionCatalog::WIKI_EDIT,
            self::WIKI_PAGE_APPROVE => CustomerPermissionCatalog::WIKI_APPROVE,
            self::WIKI_PAGE_RETURN,
            self::WIKI_QA_ASSIGN,
            self::WIKI_CLAIM_DECIDE,
            self::WIKI_CLAIM_REPAIR => CustomerPermissionCatalog::WIKI_REVIEW,
            self::WIKI_PAGE_DELETE => CustomerPermissionCatalog::WIKI_DELETE,
            self::WIKI_SOURCE_UPLOAD,
            self::WIKI_SOURCE_DELETE,
            self::WIKI_SOURCE_OWNER_CHANGE,
            self::WIKI_RUN_START,
            self::WIKI_RUN_CANCEL,
            self::WIKI_RUN_DISMISS => CustomerPermissionCatalog::WIKI_SOURCE_MANAGE,
            self::QUALITY_FLOW_APPROVE => CustomerPermissionCatalog::QUALITY_APPROVE,
            self::QUALITY_FLOW_ADOPT => CustomerPermissionCatalog::QUALITY_EDIT,
            self::QUALITY_DELETE => CustomerPermissionCatalog::QUALITY_DELETE,
            self::RISK_ASSESS => CustomerPermissionCatalog::RISK_ASSESS,
            self::RISK_ACCEPT => CustomerPermissionCatalog::RISK_ACCEPT,
            self::RISK_DELETE => CustomerPermissionCatalog::RISK_DELETE,
            self::OBJECTIVE_MEASURE => CustomerPermissionCatalog::OBJECTIVE_MEASURE,
            self::OBJECTIVE_DELETE => CustomerPermissionCatalog::OBJECTIVE_DELETE,
            self::IMPROVEMENT_CLOSE => CustomerPermissionCatalog::IMPROVEMENT_CLOSE,
            self::IMPROVEMENT_DELETE => CustomerPermissionCatalog::IMPROVEMENT_DELETE,
            self::COMPLIANCE_ASSESS => CustomerPermissionCatalog::COMPLIANCE_ASSESS,
            self::COMPLIANCE_AUDIT => CustomerPermissionCatalog::COMPLIANCE_AUDIT,
            self::COMPLIANCE_DELETE => CustomerPermissionCatalog::COMPLIANCE_DELETE,
            self::SUPPLIER_ASSESS => CustomerPermissionCatalog::SUPPLIER_ASSESS,
            self::SUPPLIER_ASSURE => CustomerPermissionCatalog::SUPPLIER_ASSURE,
            self::SUPPLIER_DELETE => CustomerPermissionCatalog::SUPPLIER_DELETE,
            self::MANAGEMENT_REVIEW_FINALIZE => CustomerPermissionCatalog::MANAGEMENT_REVIEW_FINALIZE,
            self::MANAGEMENT_REVIEW_DELETE => CustomerPermissionCatalog::MANAGEMENT_REVIEW_DELETE,
            self::WIKI_DOCUMENT_OWNER_DECIDE,
            self::TENDER_REQUIREMENT_DECIDE,
            self::TENDER_BID_STATUS => null,
        };
    }
}
