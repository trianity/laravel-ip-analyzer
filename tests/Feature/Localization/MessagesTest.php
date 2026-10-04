<?php

use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Trianity\IpAnalyzer\IpAnalyzerServiceProvider;
use Trianity\IpAnalyzer\Support\Messages;

it('resolves the active locale with package English fallback without changing the app', function ($locale, $expected) {
    app()->setLocale($locale);
    app('translator')->setFallback('hu');
    $before = config('app');
    expect(app(Messages::class)->get('progress.start'))->toContain($expected)
        ->and(app()->getLocale())->toBe($locale)
        ->and(app('translator')->getFallback())->toBe('hu')
        ->and(config('app'))->toBe($before);
})->with([['en', 'several minutes'], ['hu', 'több percig'], ['de', 'several minutes'], ['hu_HU', 'several minutes'], ['en_GB', 'several minutes']]);

it('uses English for a missing active key and honors English override and pluralization', function () {
    $loader = new ArrayLoader;
    $loader->addMessages('hu', 'messages', ['progress' => ['start' => 'Magyar']], 'ip-analyzer');
    $loader->addMessages('en', 'messages', ['progress' => ['ranges' => '{1}:count custom range|[0,*]:count custom ranges']], 'ip-analyzer');
    $loader->addMessages('de', 'messages', ['progress' => ['ranges' => 'Wrong fallback']], 'ip-analyzer');
    $translator = new Translator($loader, 'hu');
    $translator->setFallback('de');
    $messages = new Messages($translator);
    expect($messages->choice('progress.ranges', 1))->toBe('1 custom range')
        ->and($messages->choice('progress.ranges', 2))->toBe('2 custom ranges')
        ->and($translator->getLocale())->toBe('hu')->and($translator->getFallback())->toBe('de');
});

it('uses current locale on a retained adapter and substitutes placeholders', function () {
    $messages = app(Messages::class);
    app()->setLocale('hu');
    expect($messages->get('progress.elapsed', ['elapsed' => '1.5']))->toBe('Eltelt: 1.5 s');
    app()->setLocale('en');
    expect($messages->get('progress.elapsed', ['elapsed' => '1.5']))->toBe('Elapsed: 1.5 s')
        ->and($messages->choice('progress.ranges', 1))->toBe('1 CIDR range')
        ->and($messages->choice('progress.ranges', 2))->toBe('2 CIDR ranges');
});

it('ships identical English Hungarian keys and placeholder sets', function () {
    $root = __DIR__.'/../../../lang/';
    $en = Arr::dot(require $root.'en/messages.php');
    $hu = Arr::dot(require $root.'hu/messages.php');
    expect(array_keys($hu))->toBe(array_keys($en));
    foreach ($en as $key => $text) {
        preg_match_all('/:[a-z_]+/', $text, $a);
        preg_match_all('/:[a-z_]+/', $hu[$key], $b);
        $left = array_unique($a[0]);
        sort($left);
        $right = array_unique($b[0]);
        sort($right);
        expect($right)->toBe($left);
    }
});

it('registers translations without copying files and offers the conventional publication path', function () {
    $paths = ServiceProvider::pathsToPublish(IpAnalyzerServiceProvider::class, 'ip-analyzer-translations');
    expect($paths)->toHaveCount(1)->and(array_values($paths)[0])->toBe(lang_path('vendor/ip-analyzer'));
    expect(app('translator')->get('ip-analyzer::messages.progress.start', [], 'en', false))->toContain('several minutes');
    expect(is_dir(lang_path('vendor/ip-analyzer')))->toBeFalse();
});

it('falls back per missing string key without using the host fallback language', function () {
    $loader = new ArrayLoader;
    $loader->addMessages('hu', 'messages', ['progress' => ['start' => 'Magyar']], 'ip-analyzer');
    $loader->addMessages('en', 'messages', ['progress' => ['elapsed' => 'English: :elapsed']], 'ip-analyzer');
    $loader->addMessages('de', 'messages', ['progress' => ['elapsed' => 'Wrong', 'absent' => 'Wrong fallback']], 'ip-analyzer');
    $translator = new Translator($loader, 'hu');
    $translator->setFallback('de');
    $messages = new Messages($translator);
    expect($messages->get('progress.elapsed', ['elapsed' => '2.5']))->toBe('English: 2.5')
        ->and($messages->get('progress.absent'))->toBe('ip-analyzer::messages.progress.absent');
});
