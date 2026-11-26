<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Unit;

use PHPUnit\Framework\TestCase;

class LangCleanlinessTest extends TestCase
{
    /**
     * Эмодзи, variation selectors, joiners и декоративные стрелки.
     * AGENTS.md §3.6 запрещает такой декор в пользовательских строках.
     */
    private const FORBIDDEN_SYMBOLS = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}'
        .'\x{2190}-\x{21FF}\x{FE0F}\x{200D}]/u';

    public function test_language_files_do_not_contain_emoji_or_decorative_symbols(): void
    {
        $files = glob(__DIR__.'/../../lang/*/*.php');

        $this->assertNotFalse($files);
        $this->assertNotSame([], $files);

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            $this->assertNotFalse($contents);

            $found = [];

            $count = preg_match_all(self::FORBIDDEN_SYMBOLS, $contents, $matches);

            $this->assertNotFalse($count);

            if ($count > 0) {
                foreach ($matches[0] as $symbol) {
                    $found[] = sprintf('U+%04X', (int) mb_ord($symbol));
                }
            }

            $this->assertSame(
                [],
                $found,
                sprintf(
                    'В %s найдены эмодзи или декоративные символы: %s',
                    basename($file),
                    implode(', ', $found),
                ),
            );
        }
    }
}
