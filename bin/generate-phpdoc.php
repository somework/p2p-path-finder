#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

const ROOT_NAMESPACE = 'SomeWork\\P2PPathFinder\\';
const SRC_DIR = __DIR__.'/../src';
const OUTPUT_FILE = __DIR__.'/../docs/api/index.md';

/**
 * @return list<class-string>
 */
function discoverClasses(string $directory): array
{
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
    );

    $classes = [];
    foreach ($iterator as $file) {
        if (!$file->isFile() || 'php' !== $file->getExtension()) {
            continue;
        }

        $relative = str_replace($directory.'/', '', $file->getPathname());
        $class = ROOT_NAMESPACE.str_replace(['/', '.php'], ['\\', ''], $relative);
        if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
            continue;
        }

        $classes[] = $class;
    }

    sort($classes);

    return $classes;
}

function normalizeDocComment(false|string|null $comment): string
{
    if (false === $comment || null === $comment) {
        return '';
    }

    $lines = preg_split('/\r?\n/', $comment) ?: [];
    $stripped = [];
    $skipIndentedAnnotation = false;
    $annotationState = [
        'handledReturn' => false,
        'handledParams' => [],
    ];
    foreach ($lines as $line) {
        $line = trim($line);
        if (str_starts_with($line, '/**')) {
            $line = substr($line, 3);
        }
        if (str_ends_with($line, '*/')) {
            $line = substr($line, 0, -2);
        }
        $line = ltrim($line, '* ');
        if ('' === $line) {
            $skipIndentedAnnotation = false;
            $stripped[] = '';
            continue;
        }
        if (str_starts_with($line, '@')) {
            $line = transformAnnotationLine($line, $annotationState);
            if (null === $line) {
                $skipIndentedAnnotation = true;
                continue;
            }
            $skipIndentedAnnotation = false;
        } elseif ($skipIndentedAnnotation) {
            continue;
        }
        $stripped[] = $line;
    }

    $text = trim(implode("\n", $stripped));

    return $text;
}

function formatAnnotationType(string $type): string
{
    return htmlspecialchars($type, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatAnnotationDescription(string $description): string
{
    return htmlspecialchars($description, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function transformAnnotationLine(string $line, array &$annotationState): ?string
{
    if (str_starts_with($line, '@psalm-type ')) {
        return 'psalm-type '.substr($line, strlen('@psalm-type '));
    }

    if (str_starts_with($line, '@phpstan-type ')) {
        return 'phpstan-type '.substr($line, strlen('@phpstan-type '));
    }

    if (preg_match('/^@(?:(psalm|phpstan)-)?param\s+([^$]+)\s+\$([A-Za-z0-9_]+)(.*)$/', $line, $matches)) {
        $parameterName = $matches[3];
        if (isset($annotationState['handledParams'][$parameterName])) {
            return null;
        }

        $annotationState['handledParams'][$parameterName] = true;

        $type = formatAnnotationType(trim($matches[2]));
        $description = trim($matches[4]);
        $suffix = '' === $description ? '' : ' — '.formatAnnotationDescription($description);

        return sprintf('Parameter $%s: %s%s', $parameterName, $type, $suffix);
    }

    if (preg_match('/^@(?:(psalm|phpstan)-)?return\s+(.+)$/', $line, $matches)) {
        if ($annotationState['handledReturn']) {
            return null;
        }

        $annotationState['handledReturn'] = true;

        return 'Returns: '.formatAnnotationType(trim($matches[2]));
    }

    return null;
}

function describeMethodSignature(\ReflectionMethod $method): string
{
    $parameters = [];
    foreach ($method->getParameters() as $parameter) {
        $signature = '';
        if ($parameter->hasType()) {
            $signature .= describeType($parameter->getType()).' ';
        }
        if ($parameter->isVariadic()) {
            $signature .= '...';
        }
        $signature .= '$'.$parameter->getName();
        if ($parameter->isOptional() && $parameter->isDefaultValueAvailable()) {
            $default = $parameter->getDefaultValue();
            $signature .= ' = '.describeDefault($default);
        }
        $parameters[] = $signature;
    }

    $signature = $method->getName().'('.implode(', ', $parameters).')';
    if ($method->hasReturnType()) {
        $signature .= ': '.describeType($method->getReturnType());
    }

    return $signature;
}

function describeType(\ReflectionType $type): string
{
    if ($type instanceof \ReflectionNamedType) {
        return ($type->allowsNull() && 'mixed' !== $type->getName() ? '?' : '').$type->getName();
    }

    if ($type instanceof \ReflectionUnionType) {
        return implode('|', array_map(static fn (\ReflectionType $t): string => describeType($t), $type->getTypes()));
    }

    if ($type instanceof \ReflectionIntersectionType) {
        return implode('&', array_map(static fn (\ReflectionType $t): string => describeType($t), $type->getTypes()));
    }

    return 'mixed';
}

function describeDefault(mixed $value): string
{
    return match (true) {
        is_string($value) => "'".$value."'",
        is_bool($value) => $value ? 'true' : 'false',
        null === $value => 'null',
        is_array($value) => '[]',
        default => (string) $value,
    };
}

$classes = discoverClasses(SRC_DIR);

$buffer = [];
$buffer[] = '# API Documentation';
$buffer[] = '';
$buffer[] = 'This file is generated by `bin/generate-phpdoc.php` and summarises the available public APIs.';
$buffer[] = '';

foreach ($classes as $class) {
    $reflection = new \ReflectionClass($class);
    if ($reflection->isAnonymous()) {
        continue;
    }

    $classDocComment = $reflection->getDocComment();
    if (false !== $classDocComment && str_contains($classDocComment, '@internal')) {
        continue;
    }

    $buffer[] = '## '.$reflection->getName();
    $classDoc = normalizeDocComment($reflection->getDocComment());
    if ('' !== $classDoc) {
        $buffer[] = $classDoc;
    }
    $buffer[] = '';

    $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
    $methodBuffer = [];

    foreach ($methods as $method) {
        if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
            continue;
        }

        $methodDocComment = $method->getDocComment();
        if (false !== $methodDocComment && str_contains($methodDocComment, '@internal')) {
            continue;
        }

        $methodBuffer[] = '### '.$method->getName();
        $methodBuffer[] = '`'.$method->getDeclaringClass()->getShortName().'::'.describeMethodSignature($method).'`';
        $doc = normalizeDocComment($methodDocComment);
        if ('' !== $doc) {
            $methodBuffer[] = '';
            $methodBuffer[] = $doc;
        }
        $methodBuffer[] = '';
    }

    if ([] !== $methodBuffer) {
        $buffer[] = '### Public methods';
        $buffer[] = '';
        $buffer = array_merge($buffer, $methodBuffer);
    }
}

file_put_contents(OUTPUT_FILE, implode("\n", $buffer));

fwrite(STDOUT, "Generated documentation for ".count($classes)." types.".PHP_EOL);
