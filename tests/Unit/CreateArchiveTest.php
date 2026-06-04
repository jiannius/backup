<?php

use Illuminate\Support\Facades\File;
use Jiannius\Backup\Actions\CreateArchive;

beforeEach(function () {
    $this->temp = sys_get_temp_dir().'/archiver-test-'.uniqid();

    File::makeDirectory($this->temp.'/source/cache', 0755, true);
    File::put($this->temp.'/source/one.txt', 'one');
    File::put($this->temp.'/source/app.log', 'log');
    File::put($this->temp.'/source/cache/two.txt', 'two');
    File::put($this->temp.'/db.sql', 'CREATE TABLE examples;');
});

afterEach(function () {
    File::deleteDirectory($this->temp);
});

it('zips the dump at the root and folder contents under files/', function () {
    $zipPath = $this->temp.'/backup.zip';
    $source = $this->temp.'/source';

    (new CreateArchive)->handle($zipPath, $this->temp.'/db.sql', [$source]);

    $zip = new ZipArchive;
    $zip->open($zipPath);

    expect($zip->getFromName('db.sql'))->toBe('CREATE TABLE examples;');
    expect($zip->locateName('files/'.ltrim($source, '/').'/one.txt'))->not->toBeFalse();
    expect($zip->locateName('files/'.ltrim($source, '/').'/cache/two.txt'))->not->toBeFalse();
    expect($zip->locateName('files/'.ltrim($source, '/').'/app.log'))->not->toBeFalse();
    $zip->close();
});

it('honors exclude glob patterns against folder-relative paths', function () {
    $zipPath = $this->temp.'/backup.zip';
    $source = $this->temp.'/source';

    (new CreateArchive)->handle($zipPath, $this->temp.'/db.sql', [$source], ['*.log', 'cache/*']);

    $zip = new ZipArchive;
    $zip->open($zipPath);

    expect($zip->locateName('files/'.ltrim($source, '/').'/one.txt'))->not->toBeFalse();
    expect($zip->locateName('files/'.ltrim($source, '/').'/app.log'))->toBeFalse();
    expect($zip->locateName('files/'.ltrim($source, '/').'/cache/two.txt'))->toBeFalse();
    $zip->close();
});

it('creates a files-only archive when no dump is given', function () {
    $zipPath = $this->temp.'/backup.zip';
    $source = $this->temp.'/source';

    (new CreateArchive)->handle($zipPath, null, [$source]);

    $zip = new ZipArchive;
    $zip->open($zipPath);

    expect($zip->locateName('db.sql'))->toBeFalse();
    expect($zip->locateName('files/'.ltrim($source, '/').'/one.txt'))->not->toBeFalse();
    $zip->close();
});

it('throws when an included folder does not exist', function () {
    expect(fn () => (new CreateArchive)->handle($this->temp.'/backup.zip', null, [$this->temp.'/missing']))
        ->toThrow(RuntimeException::class, 'does not exist');
});
