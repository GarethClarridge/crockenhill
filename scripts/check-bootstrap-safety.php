<?php

declare(strict_types=1);

$file = $argv[1] ?? null;

if (! is_string($file) || $file === '') {
    fwrite(STDERR, "Missing bootstrap file path.\n");
    exit(1);
}

$source = file_get_contents($file);

if ($source === false) {
    fwrite(STDERR, "Unable to read {$file}\n");
    exit(1);
}

$lines = preg_split("/\r\n|\n|\r/", $source);
$tokens = token_get_all($source);
$matches = [];
$count = count($tokens);

$text = static function (array|string $token): string {
    return is_array($token) ? $token[1] : $token;
};

$line = static function (array|string $token): int {
    return is_array($token) ? $token[2] : 0;
};

$nextNonWhitespace = static function (array $tokens, int $index) use ($count): ?int {
    for ($i = $index + 1; $i < $count; $i++) {
        if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $i;
    }

    return null;
};

$findMatchingSymbol = static function (array $tokens, int $startIndex, string $openSymbol, string $closeSymbol) use ($count, $text): ?int {
    $depth = 0;

    for ($i = $startIndex; $i < $count; $i++) {
        $tokenText = $text($tokens[$i]);

        if ($tokenText === $openSymbol) {
            $depth++;

            continue;
        }

        if ($tokenText === $closeSymbol) {
            $depth--;

            if ($depth === 0) {
                return $i;
            }
        }
    }

    return null;
};

$findClosureBodyBounds = static function (array $tokens, int $closureIndex) use ($count, $text, $nextNonWhitespace, $findMatchingSymbol): ?array {
    $paramsIndex = $nextNonWhitespace($tokens, $closureIndex);

    if ($paramsIndex === null || $text($tokens[$paramsIndex]) !== '(') {
        return null;
    }

    $paramsEnd = $findMatchingSymbol($tokens, $paramsIndex, '(', ')');

    if ($paramsEnd === null) {
        return null;
    }

    for ($i = $paramsEnd + 1; $i < $count; $i++) {
        $token = $tokens[$i];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        if ($text($token) !== '{') {
            continue;
        }

        $bodyEnd = $findMatchingSymbol($tokens, $i, '{', '}');

        return $bodyEnd === null ? null : [$i, $bodyEnd];
    }

    return null;
};

$skipArrowFunction = static function (array $tokens, int $fnIndex) use ($count, $text, $nextNonWhitespace, $findMatchingSymbol): int {
    $paramsIndex = $nextNonWhitespace($tokens, $fnIndex);

    if ($paramsIndex === null || $text($tokens[$paramsIndex]) !== '(') {
        return $fnIndex;
    }

    $paramsEnd = $findMatchingSymbol($tokens, $paramsIndex, '(', ')');

    if ($paramsEnd === null) {
        return $fnIndex;
    }

    $depth = 0;

    for ($i = $paramsEnd + 1; $i < $count; $i++) {
        $tokenText = $text($tokens[$i]);

        if (in_array($tokenText, ['(', '[', '{'], true)) {
            $depth++;

            continue;
        }

        if (in_array($tokenText, [')', ']', '}'], true)) {
            if ($depth > 0) {
                $depth--;
            }

            continue;
        }

        if ($depth === 0 && in_array($tokenText, [',', ';', ')'], true)) {
            return $i - 1;
        }
    }

    return $count - 1;
};

$recordDirectConfigCalls = static function (array $tokens, int $bodyStart, int $bodyEnd) use (&$matches, $text, $line, $nextNonWhitespace, $findClosureBodyBounds, $skipArrowFunction, $lines): void {
    for ($i = $bodyStart + 1; $i < $bodyEnd; $i++) {
        $token = $tokens[$i];

        if (is_array($token) && $token[0] === T_FUNCTION) {
            $bounds = $findClosureBodyBounds($tokens, $i);

            if ($bounds !== null) {
                $i = $bounds[1];
            }

            continue;
        }

        if (is_array($token) && $token[0] === T_FN) {
            $i = $skipArrowFunction($tokens, $i);

            continue;
        }

        if (! is_array($token) || strtolower($token[1]) !== 'config') {
            continue;
        }

        $nextIndex = $nextNonWhitespace($tokens, $i);

        if ($nextIndex === null || $text($tokens[$nextIndex]) !== '(') {
            continue;
        }

        $lineNumber = $line($token);
        $sourceLine = $lines[$lineNumber - 1] ?? '';
        $matches[] = $lineNumber.':'.rtrim($sourceLine);
    }
};

for ($i = 0; $i < $count; $i++) {
    $token = $tokens[$i];

    if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'withMiddleware') {
        continue;
    }

    $openParenIndex = $nextNonWhitespace($tokens, $i);

    if ($openParenIndex === null || $text($tokens[$openParenIndex]) !== '(') {
        continue;
    }

    $closureIndex = $nextNonWhitespace($tokens, $openParenIndex);

    if ($closureIndex === null || ! is_array($tokens[$closureIndex]) || $tokens[$closureIndex][0] !== T_FUNCTION) {
        continue;
    }

    $bounds = $findClosureBodyBounds($tokens, $closureIndex);

    if ($bounds === null) {
        continue;
    }

    $recordDirectConfigCalls($tokens, $bounds[0], $bounds[1]);
    $i = $bounds[1];
}

echo implode(PHP_EOL, $matches);
