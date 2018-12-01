<?php

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace PhpCsFixer\Fixer\FunctionNotation;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\DocBlock\Annotation;
use PhpCsFixer\DocBlock\DocBlock;
use PhpCsFixer\Fixer\ConfigurationDefinitionFixerInterface;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolver;
use PhpCsFixer\FixerConfiguration\FixerOptionBuilder;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\VersionSpecification;
use PhpCsFixer\FixerDefinition\VersionSpecificCodeSample;
use PhpCsFixer\Preg;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * @author Jan Gantzert <jan@familie-gantzert.de>
 */
final class PhpdocToParamTypeFixer extends AbstractFixer implements ConfigurationDefinitionFixerInterface
{
    const CLASS_REGEX = '/^\\\\?[a-zA-Z_\\x7f-\\xff](?:\\\\?[a-zA-Z0-9_\\x7f-\\xff]+)*(?<array>\[\])*$/';

    /**
     * @var array
     */
    private $blacklistFuncNames = [
        [T_STRING, '__clone'],
        [T_STRING, '__destruct'],
    ];

    /**
     * @var array
     */
    private $skippedTypes = [
        'mixed' => true,
        'resource' => true,
        'static' => true,
    ];

    /** @var int */
    private $minimumPhpVersion = 70000;

    /**
     * {@inheritdoc}
     */
    public function getDefinition()
    {
        return new FixerDefinition(
            'EXPERIMENTAL: Takes `@param` annotations of non-mixed types and adjusts accordingly the function signature. Requires PHP >= 7.0.',
            [
                new VersionSpecificCodeSample(
                    '<?php

/** @param string $bar */
function my_foo($bar)
{}
',
                    new VersionSpecification(70000)
                ),
                new VersionSpecificCodeSample(
                    '<?php

/** @param string|null $bar */
function my_foo($bar)
{}
',
                    new VersionSpecification(70100)
                ),
            ],
            null,
            '[1] This rule is EXPERIMENTAL and is not covered with backward compatibility promise. [2] `@param` annotation is mandatory for the fixer to make changes, signatures of methods without it (no docblock, inheritdocs) will not be fixed. [3] Manual actions are required if inherited signatures are not properly documented.'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function isCandidate(Tokens $tokens)
    {
        return \PHP_VERSION_ID >= 70000 && $tokens->isTokenKindFound(T_FUNCTION);
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority()
    {
        // should be run before NoSuperfluousPhpdocTagsFixer.
        return 8;
    }

    /**
     * {@inheritdoc}
     */
    public function isRisky()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function createConfigurationDefinition()
    {
        return new FixerConfigurationResolver([
            (new FixerOptionBuilder('scalar_types', 'Fix also scalar types; may have unexpected behaviour due to PHP bad type coercion system.'))
                ->setAllowedTypes(['bool'])
                ->setDefault(true)
                ->getOption(),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    protected function applyFix(\SplFileInfo $file, Tokens $tokens)
    {
        for ($index = $tokens->count() - 1; 0 < $index; --$index) {
            if (!$tokens[$index]->isGivenKind(T_FUNCTION)) {
                continue;
            }

            $funcName = $tokens->getNextMeaningfulToken($index);
            if ($tokens[$funcName]->equalsAny($this->blacklistFuncNames, false)) {
                continue;
            }

            $paramTypeAnnotations = $this->findParamAnnotations($tokens, $index);

            foreach ($paramTypeAnnotations as $paramTypeAnnotation) {
                if (\PHP_VERSION_ID < 70000) {
                    continue;
                }

                $types = array_values($paramTypeAnnotation->getTypes());

                $paramType = current($types);
                if (isset($this->skippedTypes[$paramType])) {
                    continue;
                }

                $hasIterable = false;
                $hasNull = false;
                $hasVoid = false;
                $hasArray = false;
                $hasString = false;
                $hasInt = false;
                $hasFloat = false;
                $hasBool = false;
                $hasCallable = false;
                $hasObject = false;
                foreach ($types as $key => $type) {
                    if (1 !== Preg::match(self::CLASS_REGEX, $type, $matches)) {
                        continue;
                    }
                    if (isset($matches['array'])) {
                        $hasArray = true;
                        unset($types[$key]);
                    }
                    if ($this->typeIsOfIterable($type)) {
                        $hasIterable = true;
                        unset($types[$key]);
                        $this->setMinimumPhpVersionToAtLeast(70100);
                    }
                    if ($this->typeIsOfNull($type)) {
                        $hasNull = true;
                        unset($types[$key]);
                        $this->setMinimumPhpVersionToAtLeast(70100);
                    }
                    if ($this->typeIsOfVoid($type)) {
                        $hasVoid = true;
                        unset($types[$key]);
                    }
                    if ($this->typeIsOfString($type)) {
                        $hasString = true;
                        unset($types[$key]);
                    }
                    if ($this->typeIsOfInt($type)) {
                        $hasInt = true;
                        unset($types[$key]);
                    }
                    if ($this->typeIsOfFloat($type)) {
                        $hasFloat = true;
                        unset($types[$key]);
                    }
                    if ($this->typeIsOfBool($type)) {
                        $hasBool = true;
                        unset($types[$key]);
                    }
                    if ($this->typeIsOfCallable($type)) {
                        $hasCallable = true;
                        unset($types[$key]);
                    }
                    if ($this->typeIsOfArray($type)) {
                        $hasArray = true;
                        unset($types[$key]);
                    }
                    if ($this->typeIsOfObject($type)) {
                        $hasObject = true;
                        unset($types[$key]);
                        $this->setMinimumPhpVersionToAtLeast(70200);
                    }
                }

                if (\PHP_VERSION_ID < $this->minimumPhpVersion) {
                    continue;
                }

                if (1 < \count($types)) {
                    continue;
                }

                if (0 === \count($types)) {
                    $paramType = '';
                }

                if (1 === \count($types)) {
                    $paramType = array_shift($types);
                }

                $startIndex = $tokens->getNextTokenOfKind($index, ['(']) + 1;
                $variableIndex = $this->findCorrectVariable($tokens, $startIndex - 1, $paramTypeAnnotation);
                if (null === $variableIndex) {
                    continue;
                }

                if (!('(' === $tokens[$variableIndex - 1]->getContent()) && $this->hasParamTypeHint($tokens, $variableIndex - 2)) {
                    continue;
                }

                $this->fixFunctionDefinition(
                    $paramType,
                    $tokens,
                    $variableIndex,
                    $hasNull,
                    $hasArray,
                    $hasIterable,
                    $hasVoid,
                    $hasString,
                    $hasInt,
                    $hasFloat,
                    $hasBool,
                    $hasCallable,
                    $hasObject
                );
            }
        }
    }

    /**
     * Find all the param annotations in the function's PHPDoc comment.
     *
     * @param Tokens $tokens
     * @param int    $index  The index of the function token
     *
     * @return Annotation[]
     */
    private function findParamAnnotations(Tokens $tokens, $index)
    {
        do {
            $index = $tokens->getPrevNonWhitespace($index);
        } while ($tokens[$index]->isGivenKind([
            T_COMMENT,
            T_ABSTRACT,
            T_FINAL,
            T_PRIVATE,
            T_PROTECTED,
            T_PUBLIC,
            T_STATIC,
        ]));

        if (!$tokens[$index]->isGivenKind(T_DOC_COMMENT)) {
            return [];
        }

        $doc = new DocBlock($tokens[$index]->getContent());

        return $doc->getAnnotationsOfType('param');
    }

    private function setMinimumPhpVersionToAtLeast(int $int)
    {
        if ($this->minimumPhpVersion < $int) {
            $this->minimumPhpVersion = $int;
        }
    }

    /**
     * @param Tokens $tokens
     * @param $index
     * @param $paramTypeAnnotation
     *
     * @return null|int
     */
    private function findCorrectVariable(Tokens $tokens, $index, $paramTypeAnnotation)
    {
        $nextFunction = $tokens->getNextTokenOfKind($index, [[T_FUNCTION]]);
        $variableIndex = $tokens->getNextTokenOfKind($index, [[T_VARIABLE]]);
        if (\is_int($nextFunction) && $variableIndex > $nextFunction) {
            return null;
        }
        if (!isset($tokens[$variableIndex])) {
            return null;
        }
        $variableToken = $tokens[$variableIndex]->getContent();
        Preg::match('/@param\s*[^\s]+\s*([^\s]+)/', $paramTypeAnnotation->getContent(), $paramVariable);
        if (isset($paramVariable[1]) && $paramVariable[1] === $variableToken) {
            return $variableIndex;
        }

        return $this->findCorrectVariable($tokens, $index + 1, $paramTypeAnnotation);
    }

    /**
     * Determine whether the function already has a param type hint.
     *
     * @param Tokens $tokens
     * @param int    $index  The index of the end of the function definition line, EG at { or ;
     *
     * @return bool
     */
    private function hasParamTypeHint(Tokens $tokens, $index)
    {
        return $tokens[$index]->isGivenKind([T_STRING, T_NS_SEPARATOR, CT::T_ARRAY_TYPEHINT, T_CALLABLE, CT::T_NULLABLE_TYPE]);
    }

    /**
     * @param string $paramType
     * @param Tokens $tokens
     * @param int    $index       The index of the end of the function definition line, EG at { or ;
     * @param bool   $hasNull
     * @param bool   $hasArray
     * @param bool   $hasIterable
     * @param bool   $hasVoid
     * @param bool   $hasString
     * @param bool   $hasInt
     * @param bool   $hasFloat
     * @param bool   $hasBool
     * @param bool   $hasCallable
     * @param bool   $hasObject
     */
    private function fixFunctionDefinition(
        $paramType,
        Tokens $tokens,
        $index,
        $hasNull,
        $hasArray,
        $hasIterable,
        $hasVoid,
        $hasString,
        $hasInt,
        $hasFloat,
        $hasBool,
        $hasCallable,
        $hasObject
    ) {
        if (true === $hasNull) {
            $newTokens[] = new Token([CT::T_NULLABLE_TYPE, '?']);
        }
        if (true === $hasVoid) {
            $newTokens[] = new Token('void');
        }
        if (true === $hasIterable && true === $hasArray) {
            $newTokens[] = new Token([CT::T_ARRAY_TYPEHINT, 'array']);
        } elseif (true === $hasIterable) {
            $newTokens[] = new Token([T_STRING, 'iterable']);
        } elseif (true === $hasArray) {
            $newTokens[] = new Token([CT::T_ARRAY_TYPEHINT, 'array']);
        }
        if (true === $hasString) {
            $newTokens[] = new Token([T_STRING, 'string']);
        }
        if (true === $hasInt) {
            $newTokens[] = new Token([T_STRING, 'int']);
        }
        if (true === $hasFloat) {
            $newTokens[] = new Token([T_STRING, 'float']);
        }
        if (true === $hasBool) {
            $newTokens[] = new Token([T_STRING, 'bool']);
        }
        if (true === $hasCallable) {
            $newTokens[] = new Token([T_CALLABLE, 'callable']);
        }
        if (true === $hasObject) {
            $newTokens[] = new Token([T_STRING, 'object']);
        }

        foreach (explode('\\', $paramType) as $nsIndex => $value) {
            if (0 === $nsIndex && '' === $value) {
                continue;
            }

            if (0 < $nsIndex) {
                $newTokens[] = new Token([T_NS_SEPARATOR, '\\']);
            }
            $newTokens[] = new Token([T_STRING, $value]);
        }
        $newTokens[] = new Token([T_WHITESPACE, ' ']);
        $tokens->insertAt($index, $newTokens);
    }

    private function typeIsOfIterable($type)
    {
        return 'iterable' === $type ? true : false;
    }

    private function typeIsOfNull($type)
    {
        return 'null' === $type ? true : false;
    }

    private function typeIsOfVoid($type)
    {
        return 'void' === $type ? true : false;
    }

    private function typeIsOfString($type)
    {
        return 'string' === $type ? true : false;
    }

    private function typeIsOfInt($type)
    {
        return 'int' === $type ? true : false;
    }

    private function typeIsOfFloat($type)
    {
        return 'float' === $type ? true : false;
    }

    private function typeIsOfBool($type)
    {
        return 'bool' === $type ? true : false;
    }

    private function typeIsOfCallable($type)
    {
        return 'callable' === $type ? true : false;
    }

    private function typeIsOfArray($type)
    {
        return 'array' === $type ? true : false;
    }

    private function typeIsOfObject($type)
    {
        return 'object' === $type ? true : false;
    }
}
