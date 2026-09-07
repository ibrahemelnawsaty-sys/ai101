{{--
    403 - Forbidden

    Shown whenever a Policy, a scope check or a role middleware refused. The
    copy never says WHY beyond "this belongs to another role or another
    account": telling a user which record exists and which does not is itself a
    horizontal-access leak (Article 22).

    The refusal itself was already written to audit_logs with the IP address
    before this page was rendered (Article 8, Article 22).

    @see PRD §8, §11.1 · BR-22, BR-23, BR-28 · CONSTITUTION Articles 5, 7, 8, 22
--}}

<x-layout.error-page code="403" />
