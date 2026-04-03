<?php
/**
 * Lang.php — Load and retrieve translation strings.
 */

class Lang
{
    private static array $strings = [];
    private static string $current = 'ru';

    public static function load(string $lang): void
    {
        $supported = ['ru', 'en'];
        $lang = in_array($lang, $supported) ? $lang : DEFAULT_LANG;
        self::$current = $lang;
        $file = LANG_DIR . '/' . $lang . '.php';
        if (file_exists($file)) {
            self::$strings = require $file;
        } else {
            self::$strings = [];
        }
    }

    /**
     * Get a translated string, optionally interpolating :placeholders.
     */
    public static function get(string $key, array $replace = []): string
    {
        $str = self::$strings[$key] ?? $key;
        foreach ($replace as $k => $v) {
            $str = str_replace(':' . $k, (string)$v, $str);
        }
        return $str;
    }

    public static function current(): string
    {
        return self::$current;
    }
}
