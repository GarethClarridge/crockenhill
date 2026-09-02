<?php

declare(strict_types=1);

/**
 * @return array<int, array{line:int, message:string}>
 */
function collectTypingErrors(string $filePath): array
{
    $contents = file_get_contents($filePath);

    if ($contents === false) {
        return [[
            'line' => 1,
            'message' => 'Unable to read file.',
        ]];
    }

    $errors = [];

    if (! hasStrictTypesDeclaration($contents)) {
        $errors[] = [
            'line' => 1,
            'message' => 'Missing declare(strict_types=1); as the first statement.',
        ];
    }

    $tokens = token_get_all($contents);
    $braceDepth = 0;
    $classDepth = null;
    $awaitingClassBrace = false;
    $classLikeTokens = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM];
    $visibilityTokens = [T_PUBLIC, T_PROTECTED, T_PRIVATE];

    for ($index = 0, $count = count($tokens); $index < $count; $index++) {
        $token = $tokens[$index];

        if (is_array($token) && in_array($token[0], $classLikeTokens, true)) {
            if (isClassConstantReference($tokens, $index)) {
                continue;
            }

            $awaitingClassBrace = true;

            continue;
        }

        if ($token === '{') {
            $braceDepth++;

            if ($awaitingClassBrace) {
                $classDepth = $braceDepth;
                $awaitingClassBrace = false;
            }

            continue;
        }

        if ($token === '}') {
            if ($classDepth !== null && $braceDepth === $classDepth) {
                $classDepth = null;
            }

            $braceDepth = max(0, $braceDepth - 1);

            continue;
        }

        if ($classDepth === null || $braceDepth !== $classDepth || ! is_array($token)) {
            continue;
        }

        if (in_array($token[0], $visibilityTokens, true)) {
            $previousSignificantToken = previousSignificantToken($tokens, $index);

            if ($previousSignificantToken === '(' || $previousSignificantToken === ',') {
                continue;
            }

            if (propertyDeclarationMissingType($tokens, $index)) {
                $errors[] = [
                    'line' => $token[2],
                    'message' => 'Class property is missing an explicit type.',
                ];
            }

            continue;
        }

        if ($token[0] === T_FUNCTION) {
            $functionName = functionNameAtIndex($tokens, $index);

            if ($functionName === null || strcasecmp($functionName, '__construct') === 0) {
                continue;
            }

            if (! functionHasExplicitReturnType($tokens, $index)) {
                $errors[] = [
                    'line' => $token[2],
                    'message' => sprintf('Method %s() is missing an explicit return type.', $functionName),
                ];
            }
        }
    }

    return $errors;
}

function hasStrictTypesDeclaration(string $contents): bool
{
    $tokens = token_get_all($contents);
    $declaration = '';
    $seenDeclare = false;

    foreach ($tokens as $token) {
        if (! $seenDeclare) {
            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (! is_array($token) || $token[0] !== T_DECLARE) {
                return false;
            }

            $seenDeclare = true;
        }

        $declaration .= is_array($token) ? $token[1] : $token;

        if ($token === ';') {
            break;
        }
    }

    if (! $seenDeclare) {
        return false;
    }

    return preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/', $declaration) === 1;
}

/**
 * @param  array<int, mixed>  $tokens
 */
function isClassConstantReference(array $tokens, int $index): bool
{
    $previous = previousSignificantToken($tokens, $index);

    return is_array($previous) && $previous[0] === T_DOUBLE_COLON;
}

/**
 * @param  array<int, mixed>  $tokens
 */
function previousSignificantToken(array $tokens, int $index): mixed
{
    for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
        $token = $tokens[$cursor];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $token;
    }

    return null;
}

/**
 * Properties inherited from Laravel base classes that cannot be re-typed in
 * child classes: the parent declares them without a type, and PHP forbids a
 * subclass adding (or changing) the type of an inherited property. Requiring a
 * type on these would make the class fatal at load time.
 *
 * Sourced from Illuminate\Database\Eloquent\Model and the Concerns traits it
 * composes, Illuminate\Console\Command, and
 * Illuminate\Foundation\Support\Providers\AuthServiceProvider.
 *
 * @var list<string>
 */
const LARAVEL_INHERITED_PROPERTIES = [
    '$fillable', '$guarded', '$hidden', '$visible', '$appends',
    '$with', '$withCount', '$perPage', '$incrementing', '$timestamps',
    '$dateFormat', '$casts', '$dispatchesEvents', '$observables',
    '$relations', '$touches', '$table', '$primaryKey', '$keyType',
    '$connection', '$classCastCache', '$attributeCastCache',
    '$signature', '$description',
    '$attributes', '$policies',
];

/**
 * @param  array<int, mixed>  $tokens
 */
function propertyDeclarationMissingType(array $tokens, int $index): bool
{
    $modifiers = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY];

    for ($cursor = $index; isset($tokens[$cursor]); $cursor++) {
        $token = $tokens[$cursor];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        if (is_array($token) && in_array($token[0], $modifiers, true)) {
            continue;
        }

        if (is_array($token) && $token[0] === T_CONST) {
            return false;
        }

        if (is_array($token) && $token[0] === T_FUNCTION) {
            return false;
        }

        if (is_array($token) && $token[0] === T_VARIABLE) {
            if (in_array($token[1], LARAVEL_INHERITED_PROPERTIES, true)) {
                return false;
            }

            return true;
        }

        return false;
    }

    return false;
}

/**
 * @param  array<int, mixed>  $tokens
 */
function functionNameAtIndex(array $tokens, int $index): ?string
{
    for ($cursor = $index + 1; isset($tokens[$cursor]); $cursor++) {
        $token = $tokens[$cursor];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        if ($token === '&') {
            continue;
        }

        if (is_array($token) && $token[0] === T_STRING) {
            return $token[1];
        }

        return null;
    }

    return null;
}

/**
 * @param  array<int, mixed>  $tokens
 */
function functionHasExplicitReturnType(array $tokens, int $index): bool
{
    $cursor = $index;

    while (isset($tokens[$cursor]) && $tokens[$cursor] !== '(') {
        $cursor++;
    }

    if (! isset($tokens[$cursor])) {
        return false;
    }

    $parenthesisDepth = 0;

    for (; isset($tokens[$cursor]); $cursor++) {
        $token = $tokens[$cursor];

        if ($token === '(') {
            $parenthesisDepth++;

            continue;
        }

        if ($token === ')') {
            $parenthesisDepth--;

            if ($parenthesisDepth === 0) {
                $cursor++;
                break;
            }
        }
    }

    for (; isset($tokens[$cursor]); $cursor++) {
        $token = $tokens[$cursor];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        if ($token === ':') {
            return true;
        }

        if ($token === '{' || $token === ';') {
            return false;
        }
    }

    return false;
}

$files = array_slice($argv, 1);
$failed = false;

foreach ($files as $filePath) {
    if (! is_file($filePath)) {
        continue;
    }

    $errors = collectTypingErrors($filePath);

    if ($errors === []) {
        continue;
    }

    $failed = true;

    foreach ($errors as $error) {
        fwrite(
            STDERR,
            sprintf('[typing-baseline] %s:%d %s', $filePath, $error['line'], $error['message']).PHP_EOL
        );
    }
}

exit($failed ? 1 : 0);
