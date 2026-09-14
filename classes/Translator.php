<?php

declare(strict_types=1);

namespace App;

final class Translator
{
    private static ?self $instance = null;

    /** @var array<string, string> */
    private array $lines = [];

    private string $locale;

    /** @var list<string> */
    public const SUPPORTED = ['en', 'pl', 'es', 'de', 'uk'];

    /** @return array<string, array{flag: string, label_key: string}> */
    public static function availableLocales(): array
    {
        return [
            'en' => ['flag' => 'EN', 'label_key' => 'lang.en'],
            'pl' => ['flag' => '🇵🇱', 'label_key' => 'lang.pl'],
            'es' => ['flag' => '🇪🇸', 'label_key' => 'lang.es'],
            'de' => ['flag' => '🇩🇪', 'label_key' => 'lang.de'],
            'uk' => ['flag' => '🇺🇦', 'label_key' => 'lang.uk'],
        ];
    }

    private function __construct(string $locale)
    {
        $baseDir = dirname(__DIR__) . '/lang/';
        $enLines = require $baseDir . 'en.php';
        $enLines = is_array($enLines) ? $enLines : [];

        $this->locale = $locale;
        $file = $baseDir . $locale . '.php';

        if (!is_file($file)) {
            $this->locale = 'en';
            $this->lines = $enLines;
            return;
        }

        $localeLines = require $file;
        $this->lines = array_merge($enLines, is_array($localeLines) ? $localeLines : []);
    }

    public static function init(): self
    {
        Session::ensureStarted();

        if (isset($_GET['lang']) && in_array($_GET['lang'], self::SUPPORTED, true)) {
            $_SESSION['lang'] = $_GET['lang'];
        }

        $locale = $_SESSION['lang'] ?? 'en';
        if (!in_array($locale, self::SUPPORTED, true)) {
            $locale = 'en';
        }

        if (self::$instance === null || self::$instance->locale !== $locale) {
            self::$instance = new self($locale);
        }

        return self::$instance;
    }

    public static function get(string $key, array $replace = []): string
    {
        return self::init()->translate($key, $replace);
    }

    public function translate(string $key, array $replace = []): string
    {
        $text = $this->lines[$key] ?? $key;

        foreach ($replace as $name => $value) {
            $text = str_replace(':' . $name, (string) $value, $text);
        }

        return $text;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->lines;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function switchUrl(string $lang): string
    {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
        return $script . '?lang=' . urlencode($lang);
    }

    public function querySuffix(): string
    {
        return $this->locale === 'en' ? '' : '?lang=' . urlencode($this->locale);
    }
}
