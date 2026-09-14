<?php

declare(strict_types=1);

namespace Aiya\Core\Command;

use Aiya\Core\Api\Contract\Contract;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * WP-CLI: `wp aiya contracts snapshot [--out=path]`
 *
 * Reflects every Api/Contract DTO (constructor promoted properties:
 * name, type, nullability) into a stable JSON snapshot. The front end
 * commits that file and its vitest suite compares the hand-written zod
 * schemas against it, so a backend contract change without a schema
 * update turns the front-end tests red. Stable output: classes sorted,
 * properties in declaration order, no timestamps.
 */
final class ContractsSnapshot
{
    public static function register(): void
    {
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('aiya contracts snapshot', [self::class, 'run']);
        }
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $assocArgs
     */
    /**
     * Merge-style DTOs flatten their inner DTO in toArray(), so constructor
     * reflection would not describe the wire shape. These are hand-declared
     * in the exact wire form; a change here must keep toArray() in sync.
     *
     * @var array<string, list<array{name: string, type: string, nullable: bool}>>
     */
    private const WIRE_SHAPES = [
        'PostDetail' => [
            ['name' => 'id', 'type' => 'int', 'nullable' => false],
            ['name' => 'slug', 'type' => 'string', 'nullable' => false],
            ['name' => 'url', 'type' => 'string', 'nullable' => false],
            ['name' => 'type', 'type' => 'string', 'nullable' => false],
            ['name' => 'title', 'type' => 'string', 'nullable' => false],
            ['name' => 'excerpt', 'type' => 'string', 'nullable' => false],
            ['name' => 'publishedAt', 'type' => 'string', 'nullable' => false],
            ['name' => 'updatedAt', 'type' => 'string', 'nullable' => false],
            ['name' => 'readingMinutes', 'type' => 'int', 'nullable' => false],
            ['name' => 'thumbnail', 'type' => 'Image', 'nullable' => true],
            ['name' => 'author', 'type' => 'Author', 'nullable' => false],
            ['name' => 'categories', 'type' => 'array', 'nullable' => false],
            ['name' => 'tags', 'type' => 'array', 'nullable' => false],
            ['name' => 'metrics', 'type' => 'PostMetrics', 'nullable' => false],
            ['name' => 'badges', 'type' => 'array', 'nullable' => false],
            ['name' => 'content', 'type' => 'object', 'nullable' => false],
            ['name' => 'locked', 'type' => 'bool', 'nullable' => false],
            ['name' => 'featured', 'type' => 'Image', 'nullable' => true],
            ['name' => 'seo', 'type' => 'Seo', 'nullable' => false],
            ['name' => 'breadcrumbs', 'type' => 'array', 'nullable' => false],
            ['name' => 'previous', 'type' => 'PostSummary', 'nullable' => true],
            ['name' => 'next', 'type' => 'PostSummary', 'nullable' => true],
        ],
        'DiscussionDetail' => [
            ['name' => 'id', 'type' => 'int', 'nullable' => false],
            ['name' => 'url', 'type' => 'string', 'nullable' => false],
            ['name' => 'title', 'type' => 'string', 'nullable' => false],
            ['name' => 'board', 'type' => 'DiscussionBoard', 'nullable' => true],
            ['name' => 'status', 'type' => 'string', 'nullable' => false],
            ['name' => 'author', 'type' => 'Author', 'nullable' => false],
            ['name' => 'postRef', 'type' => 'PostRef', 'nullable' => true],
            ['name' => 'replies', 'type' => 'array', 'nullable' => false],
            ['name' => 'tags', 'type' => 'array', 'nullable' => false],
            ['name' => 'images', 'type' => 'array', 'nullable' => false],
            ['name' => 'lastReplyAt', 'type' => 'string', 'nullable' => false],
            ['name' => 'publishedAt', 'type' => 'string', 'nullable' => false],
            ['name' => 'canEdit', 'type' => 'bool', 'nullable' => false],
            ['name' => 'canDelete', 'type' => 'bool', 'nullable' => false],
            ['name' => 'canReply', 'type' => 'bool', 'nullable' => false],
            ['name' => 'contentHtml', 'type' => 'string', 'nullable' => false],
            ['name' => 'content', 'type' => 'object', 'nullable' => false],
        ],
    ];

    /**
     * @param list<string>          $args
     * @param array<string, string> $assocArgs
     */
    public static function run(array $args, array $assocArgs): void
    {
        $dtos = [];
        foreach (self::WIRE_SHAPES as $name => $properties) {
            $dtos[$name] = $properties;
        }
        $files = glob(AIYA_CORE_PATH . 'src/Api/Contract/*.php');
        if ($files === false) {
            $files = [];
        }
        foreach ($files as $file) {
            $name = basename((string) $file, '.php');
            if ($name === 'Contract') {
                continue; // version/namespace holder, not a DTO
            }

            $fqcn = 'Aiya\\Core\\Api\\Contract\\' . $name;
            if (!class_exists($fqcn) || isset(self::WIRE_SHAPES[$name])) {
                continue;
            }

            $constructor = (new ReflectionClass($fqcn))->getConstructor();
            if ($constructor === null) {
                continue;
            }

            $properties = [];
            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();
                $properties[] = [
                    'name' => $parameter->getName(),
                    'type' => self::typeName($type),
                    'nullable' => $type !== null && $type->allowsNull(),
                ];
            }
            $dtos[$name] = $properties;
        }
        ksort($dtos);

        $json = (string) wp_json_encode([
            'contractVersion' => Contract::VERSION,
            'dtos' => $dtos,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $out = (string) ($assocArgs['out'] ?? '');
        if ($out !== '') {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI export target lives outside WP; WP_Filesystem is not the tool here
            file_put_contents($out, $json . "\n");
            \WP_CLI::success("Snapshot written to {$out}");
        } else {
            \WP_CLI::line($json);
        }
    }

    private static function typeName(?ReflectionType $type): string
    {
        if ($type === null) {
            return 'mixed';
        }
        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(
                static fn (ReflectionType $part): string => self::typeName($part),
                $type->getTypes()
            ));
        }
        if (!$type instanceof ReflectionNamedType) {
            return 'mixed';
        }

        $name = $type->getName();

        $pos = strrpos($name, '\\');
        if ($pos === false) {
            return $name;
        }

        return substr($name, $pos + 1);
    }
}
