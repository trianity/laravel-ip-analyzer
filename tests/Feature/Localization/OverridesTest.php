<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Trianity\IpAnalyzer\IpAnalyzerServiceProvider;
use Trianity\IpAnalyzer\Support\Messages;

it('loads partial application vendor overrides and English fallback overrides from real files', function () {
    $root = sys_get_temp_dir().'/ip-translations-'.bin2hex(random_bytes(6));
    mkdir($root.'/vendor/ip-analyzer/hu', 0700, true);
    mkdir($root.'/vendor/ip-analyzer/en', 0700, true);
    file_put_contents($root.'/vendor/ip-analyzer/hu/messages.php', "<?php return ['progress' => ['start' => 'Saját kezdőüzenet']];");
    file_put_contents($root.'/vendor/ip-analyzer/en/messages.php', "<?php return ['progress' => ['start' => 'Custom English startup']];");
    app('translation.loader')->addPath($root);
    try {
        app()->setLocale('hu');
        $messages = app(Messages::class);
        expect($messages->get('progress.start'))->toBe('Saját kezdőüzenet')
            ->and($messages->get('phases.hash'))->toBe('SHA-256 számítása');
        app()->setLocale('de');
        app('translator')->setFallback('hu');
        expect($messages->get('progress.start'))->toBe('Custom English startup')
            ->and($messages->get('phases.hash'))->toBe('Computing SHA-256');
    } finally {
        app('files')->deleteDirectory($root);
    }
});

it('publishes optional translations to the host lang directory and survives config cache', function () {
    $paths = ServiceProvider::pathsToPublish(IpAnalyzerServiceProvider::class, 'ip-analyzer-translations');
    $target = array_values($paths)[0];
    expect(is_dir($target))->toBeFalse();
    try {
        expect(Artisan::call('vendor:publish', ['--tag' => 'ip-analyzer-translations']))->toBe(0);
        foreach (['en', 'hu'] as $locale) {
            expect(file_get_contents($target.'/'.$locale.'/messages.php'))->toBe(file_get_contents(array_key_first($paths).'/'.$locale.'/messages.php'));
        }
        expect(Artisan::call('config:cache'))->toBe(0);
        $this->refreshApplication();
        expect(app()->configurationIsCached())->toBeTrue();
        app()->setLocale('hu');
        expect(app(Messages::class)->get('progress.start'))->toContain('több percig');
        app()->setLocale('en');
        expect(app(Messages::class)->get('progress.start'))->toContain('several minutes');
    } finally {
        Artisan::call('config:clear');
        app('files')->deleteDirectory($target);
    }
});
