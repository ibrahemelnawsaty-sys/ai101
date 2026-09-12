<?php

declare(strict_types=1);

/**
 * The certificate can be saved as a PDF (PRD §9.17).
 *
 * WHY THIS SUITE EXISTS
 * Nothing on the platform generated a certificate file, so the download route
 * read a column no code writes and answered 404 for every holder — and the
 * letter announcing the certificate pointed at it (D-81). The browser now
 * makes the PDF from a print sheet, as it does for the digital card (D-57).
 *
 * @see PRD §9.17 · BR-25 · D-57, D-81
 */
beforeEach(function (): void {
    freezeAt(riyadhAt('2026-11-20 12:00:00'));

    $this->cohort = makeCohort();
    $this->participant = makeParticipant($this->cohort);
});

it('BR-25: ورقة الشهادة المطبوعة تحمل الاسم والرقم التسلسلي ورمز التحقّق ورابطه', function (): void {
    $certificate = issueCertificateFor($this->participant, $this->cohort);

    $this->actingAs($this->participant)
        ->get(route('certificate.print'))
        ->assertOk()
        ->assertSee('class="certsheet"', false)
        ->assertSee($certificate->serial_number, false)
        ->assertSee('<svg', false)
        ->assertSee(e(route('certificate.verify', ['code' => $certificate->verify_code])), false)
        ->assertSee('<title>'.e((string) __('certificates.doc_heading')).' · ', false);
});

it('D-81: عنوان التنزيل القديم يقود إلى الورقة المطبوعة، وزرّ الصفحة يشير إليها', function (): void {
    issueCertificateFor($this->participant, $this->cohort);

    $this->actingAs($this->participant)
        ->get(route('certificate.download'))
        ->assertRedirect(route('certificate.print'));

    $this->actingAs($this->participant)
        ->get(route('certificate'))
        ->assertOk()
        ->assertSee(route('certificate.print'), false);
});

it('BR-25: لا ورقة لمن لا شهادة له، ولا لشهادة ملغاة', function (): void {
    $this->actingAs($this->participant)->get(route('certificate.print'))->assertNotFound();

    issueCertificateFor($this->participant, $this->cohort, ['revoked_at' => riyadhAt('2026-11-21 09:00:00')]);

    $this->actingAs($this->participant)->get(route('certificate.print'))->assertNotFound();
});
