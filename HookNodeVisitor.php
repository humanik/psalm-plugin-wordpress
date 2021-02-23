<?php

namespace PsalmWordPress;

use Psalm;
use PhpParser;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Psalm\Type;
use Psalm\Type\Union;

class HookNodeVisitor extends PhpParser\NodeVisitorAbstract
{
    /** @var ?PhpParser\Comment\Doc */
    protected $last_doc = null;

    /** @var array<string, list<Union>> */
    public $hooks = [];

    public function enterNode(PhpParser\Node $origNode)
    {
        $apply_functions = [
            'apply_filters',
            'apply_filters_ref_array',
            'apply_filters_deprecated',
            'do_action',
            'do_action_ref_array',
            'do_action_deprecated',
        ];

        if ($origNode->getDocComment()) {
            $this->last_doc = $origNode->getDocComment();
        }

        if (
            $this->last_doc &&
            $origNode instanceof FuncCall &&
            $origNode->name instanceof Name &&
            in_array((string) $origNode->name, $apply_functions, true)
        ) {
            if (! $origNode->args[0]->value instanceof String_) {
                $this->last_doc = null;
                return null;
            }

            $hook_name = $origNode->args[0]->value->value;
            $comment = Psalm\DocComment::parsePreservingLength($this->last_doc);

            // Todo: test namespace resolution.
            $comments = Psalm\Internal\PhpVisitor\Reflector\FunctionLikeDocblockParser::parse($this->last_doc);

            // Todo: handle no comments
            /** @psalm-suppress InternalProperty */
            $types = array_map(function (array $comment_type): Union {
                return Type::parseString($comment_type['type']);
            }, $comments->params);
            $types = array_values($types);
            $this->hooks[ $hook_name ] = $types;
            $this->last_doc = null;
        }

        return null;
    }
}
