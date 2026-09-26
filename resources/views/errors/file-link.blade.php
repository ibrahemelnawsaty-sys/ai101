{{--
    403 — a signed file link past its time, or altered (D-124).

    Every private file opens through a signed link that lasts a short
    while (art. 24). Past it, the refusal says what happened and what to
    do — refresh the page the file was opened from — instead of the
    generic 403, which says the page belongs to another role. The refusal
    was already written to audit_logs with its IP (art. 8).

    @see D-124 · CONSTITUTION art. 8, art. 15, art. 24
--}}

<x-layout.error-page code="403"
    :title="__('errors.pages.file_link.title')"
    :body="__('errors.pages.file_link.body')" />
