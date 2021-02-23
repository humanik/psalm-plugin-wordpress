<?php

namespace PsalmWordPress;

use PhpParser\Node\Expr\FuncCall;
use PhpParser;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\IssueBuffer;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use Psalm\Plugin\Hook\AfterEveryFunctionCallAnalysisInterface;
use Psalm\Plugin\Hook\FunctionParamsProviderInterface;
use Psalm\Plugin\Hook\BeforeFileAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Storage\FileStorage;
use Psalm\Storage\FunctionLikeParameter;
use SimpleXMLElement;
use Psalm\Type\Union;
use Psalm\Type;
use Psalm;
use Psalm\Type\Atomic\TCallable;
use PhpParser\Node\Arg;
use Psalm\Type\Atomic;
use Exception;
use Webmozart\Assert\Assert;

class Plugin implements
    PluginEntryPointInterface,
    AfterEveryFunctionCallAnalysisInterface,
    FunctionParamsProviderInterface,
    BeforeFileAnalysisInterface
{
    /**
     * @var array<string, array{types: list<Union>}>
     */
    public static $hooks = [];

    public function __invoke(RegistrationInterface $registration, ?SimpleXMLElement $config = null): void
    {
        $registration->registerHooksFromClass(static::class);
        array_map([ $registration, 'addStubFile' ], $this->getStubFiles());
        $this->loadStubbedHooks();
    }

    /**
     * @return string[]
     */
    private function getStubFiles(): array
    {
        return [
            static::getVendorFile('/php-stubs/wordpress-stubs/wordpress-stubs.php'),
            __DIR__ . '/stubs/overrides.php',
        ];
    }

    protected static function getVendorFile(string $filename): string
    {
        foreach ([dirname(__DIR__, 2), __DIR__ . '/vendor'] as $dir) {
            $path = realpath($dir . DIRECTORY_SEPARATOR . $filename);
            if ($path) {
                return $path;
            }
        }

        throw new \Exception("Can't find vendors folder");
    }

    protected static function loadStubbedHooks(): void
    {
        if (static::$hooks) {
            return;
        }

        $hooks = array_merge(
            static::getHooksFromFile(static::getVendorFile('/johnbillion/wp-hooks/hooks/actions.json')),
            static::getHooksFromFile(static::getVendorFile('/johnbillion/wp-hooks/hooks/filters.json'))
        );

        static::$hooks = $hooks;
    }

    /**
     *
     * @param string $filepath
     * @return array<string, array{ types: list<Union> }>
     */
    protected static function getHooksFromFile(string $filepath): array
    {
        Assert::file($filepath);
        /** @var list<array{ name: string, file: string, type: 'action'|'filter', doc: array{ description: string, long_description: string, long_description_html: string, tags: list<array{ name: string, content: string, types?: list<string>}> } }> */
        $hooks = json_decode(file_get_contents($filepath), true);
        $hook_map = [];
        foreach ($hooks as $hook) {
            $params = array_filter($hook['doc']['tags'], function ($tag) {
                return $tag['name'] === 'param';
            });

            $types = array_column($params, 'types');

            $types = array_map(function ($type): string {
                return implode('|', $type);
            }, $types);

            $hook_map[ $hook['name'] ] = [
                'types' => array_map([ Type::class, 'parseString' ], $types),
            ];
        }

        return $hook_map;
    }

    public static function beforeAnalyzeFile(
        StatementsSource $statements_source,
        Context $file_context,
        FileStorage $file_storage,
        Codebase $codebase
    ): void {
        $statements = $codebase->getStatementsForFile($statements_source->getFilePath());
        $traverser = new PhpParser\NodeTraverser();
        $hook_visitor = new HookNodeVisitor();
        $traverser->addVisitor($hook_visitor);
        try {
            $traverser->traverse($statements);
        } catch (Exception $e) {
        }

        foreach ($hook_visitor->hooks as $hook_name => $types) {
            static::registerHook($hook_name, $types);
        }
    }

    public static function afterEveryFunctionCallAnalysis(
        FuncCall $expr,
        string $function_id,
        Context $context,
        StatementsSource $statements_source,
        Codebase $codebase
    ): void {
        $apply_functions = [
            'apply_filters',
            'apply_filters_ref_array',
            'apply_filters_deprecated',
            'do_action',
            'do_action_ref_array',
            'do_action_deprecated',
        ];

        if (! in_array($function_id, $apply_functions, true)) {
            return;
        }

        if (! $expr->args[0]->value instanceof String_) {
            return;
        }

        $name = $expr->args[0]->value->value;
        // Check if this hook is already documented.
        if (isset(static::$hooks[ $name ])) {
            return;
        }

        $types = array_map(function (Arg $arg) use ($statements_source) {
            $type = $statements_source->getNodeTypeProvider()->getType($arg->value);
            if (! $type) {
                $type = Type::parseString('mixed');
            } else {
                $sub_types = array_values($type->getAtomicTypes());
                $sub_types = array_map(function (Atomic $type): Atomic {
                    if ($type instanceof Atomic\TTrue || $type instanceof Atomic\TFalse) {
                        return new Atomic\TBool();
                    } elseif ($type instanceof Atomic\TLiteralString) {
                        return new Atomic\TString();
                    } elseif ($type instanceof Atomic\TLiteralInt) {
                        return new Atomic\TInt();
                    } elseif ($type instanceof Atomic\TLiteralFloat) {
                        return new Atomic\TFloat();
                    }

                    return $type;
                }, $sub_types);
                $type = new Union($sub_types);
            }

            return $type;
        }, array_slice($expr->args, 1));

        static::registerHook($name, $types);
    }

    public static function getFunctionIds(): array
    {
        return [
            'add_action',
            'add_filter',
        ];
    }

    /**
     * @param  list<PhpParser\Node\Arg>    $call_args
     *
     * @return ?array<int, \Psalm\Storage\FunctionLikeParameter>
     */
    public static function getFunctionParams(
        StatementsSource $statements_source,
        string $function_id,
        array $call_args,
        Context $context = null,
        CodeLocation $code_location = null
    ): ?array {
        static::loadStubbedHooks();

        // Currently we only support detecting the hook name if it's a string.
        if (! $call_args[0]->value instanceof String_) {
            return null;
        }

        $hook_name = $call_args[0]->value->value;
        $hook = static::$hooks[ $hook_name ] ?? null;

        if (! $hook) {
            if ($code_location) {
                IssueBuffer::accepts(
                    new HookNotFound(
                        'Hook ' . $hook_name . ' not found.',
                        $code_location
                    )
                );
            }
            return [];
        }

        // Check how many args the filter is registered with.
        /** @var int */
        $num_args = $call_args[ 3 ]->value->value ?? 1;
        // Limit the required type params on the hook to match the registered number.
        $hook_types = array_slice($hook['types'], 0, $num_args);

        $hook_params = array_map(function (Union $type): FunctionLikeParameter {
            return new FunctionLikeParameter('param', false, $type, null, null, false);
        }, $hook_types);

        $is_action = $function_id === 'add_action';

        $return = [
            new FunctionLikeParameter('Hook', false, Type::parseString('string'), null, null, false),
            new FunctionLikeParameter('Callback', false, new Union([
                new TCallable(
                    'callable',
                    $hook_params,
                    // Actions must return null/void. Filters must return the same type as the first param.
                    $is_action ? Type::parseString('void|null') : $hook['types'][0]
                ),
            ]), null, null, false),
            new FunctionLikeParameter('Priority', false, Type::parseString('int|null')),
            new FunctionLikeParameter('Priority', false, Type::parseString('int|null')),
        ];
        return $return;
    }

    /**
     * @param string $hook
     * @param list<Union> $types
     * @return void
     */
    public static function registerHook(string $hook, array $types)
    {
        static::$hooks[ $hook ] = [
            'types' => $types,
        ];
    }
}
