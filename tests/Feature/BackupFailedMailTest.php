<?php

use Jiannius\Backup\Mail\BackupFailed;

it('renders the failure email with the subject and error message', function () {
    $mailable = new BackupFailed(new RuntimeException('Connection refused'));

    $mailable->assertHasSubject('[Laravel] Backup failed');
    $mailable->assertSeeInHtml('Connection refused');
    $mailable->assertSeeInText('Connection refused');
});

it('escapes html-special characters in the error message', function () {
    $mailable = new BackupFailed(new RuntimeException('Host <db> is unreachable & not retried'));

    $mailable->assertSeeInHtml('Host &lt;db&gt; is unreachable &amp; not retried', escape: false);
    $mailable->assertSeeInText('Host <db> is unreachable & not retried');
});
