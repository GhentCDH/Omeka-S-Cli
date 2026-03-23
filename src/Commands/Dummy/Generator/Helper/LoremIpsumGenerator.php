<?php

namespace OSC\Commands\Dummy\Generator\Helper;

class LoremIpsumGenerator
{
    private const WORDS = [
        'lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing', 'elit',
        'sed', 'do', 'eiusmod', 'tempor', 'incididunt', 'ut', 'labore', 'et', 'dolore',
        'magna', 'aliqua', 'enim', 'ad', 'minim', 'veniam', 'quis', 'nostrud',
        'exercitation', 'ullamco', 'laboris', 'nisi', 'aliquip', 'ex', 'ea', 'commodo',
        'consequat', 'duis', 'aute', 'irure', 'in', 'reprehenderit', 'voluptate', 'velit',
        'esse', 'cillum', 'eu', 'fugiat', 'nulla', 'pariatur', 'excepteur', 'sint',
        'occaecat', 'cupidatat', 'non', 'proident', 'sunt', 'culpa', 'qui', 'officia',
        'deserunt', 'mollit', 'anim', 'id', 'est', 'laborum',
    ];

    public static function generate(int $minWords = 5, int $maxWords = 10): string
    {
        if ($minWords < 1) {
            throw new \InvalidArgumentException('Minimum number of words must be at least 1.');
        }
        if ($maxWords < $minWords) {
            throw new \InvalidArgumentException('Maximum number of words must be greater than or equal to minimum.');
        }

        $count = random_int($minWords, $maxWords);
        $words = [];
        $wordCount = count(self::WORDS);

        for ($i = 0; $i < $count; $i++) {
            $words[] = self::WORDS[random_int(0, $wordCount - 1)];
        }

        return implode(' ', $words);
    }
}
