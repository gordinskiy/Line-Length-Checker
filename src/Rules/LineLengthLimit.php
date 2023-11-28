<?php

declare(strict_types=1);

namespace Gordinskiy\LineLengthChecker\Rules;

use PhpCsFixer\Fixer\ConfigurableFixerInterface;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolver;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolverInterface;
use PhpCsFixer\FixerConfiguration\FixerOptionBuilder;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

class LineLengthLimit implements ConfigurableFixerInterface
{
    private int $maxLength = 120;

    /** @var string */
    private const OPTION_MAX_LENGTH = 'max_length';

    public function getConfigurationDefinition(): FixerConfigurationResolverInterface
    {
        return new FixerConfigurationResolver([
            (new FixerOptionBuilder(self::OPTION_MAX_LENGTH, 'Maximum line length.'))
                ->setAllowedTypes(['int'])
                ->setDefault(120)
                ->getOption(),
        ]);
    }

    public function configure(array $configuration): void
    {
        $configuration = $this->getConfigurationDefinition()->resolve($configuration);

        if ($maxLength = $configuration[self::OPTION_MAX_LENGTH] ?? null) {
            $this->maxLength = $maxLength;
        }
    }

    public function getName(): string
    {
        return 'Gordinskiy/line_length_limit';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Line must be no longer than 120 characters.',
            [],
            'Check if all line no longer then 120 characters.'
            . "Doesn't fix anything. Only works for check command or with --dry-run flag."
        );
    }

    public function isRisky(): bool
    {
        return false;
    }

    public function getPriority(): int
    {
        // Lower than single_line_comment_style rule
        return -32;
    }

    public function supports(\SplFileInfo $file): bool
    {
        return true;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        $lineLength = 0;

        foreach ($tokens as $token) {
            $tokenContent = $token->getContent();

            if (str_contains($tokenContent, PHP_EOL)) {
                $lines = explode(PHP_EOL, $tokenContent);

                $previousLineEnd = array_shift($lines);
                $nextLineBegin = array_pop($lines);

                if (!$this->isValidLength($lineLength + strlen($previousLineEnd))) {
                    return true;
                }

                foreach ($lines as $line) {
                    if (!$this->isValidLength(strlen($line))) {
                        return true;
                    }
                }

                $lineLength = strlen($nextLineBegin);
            } else {
                $lineLength += strlen($tokenContent);
            }
        }

        return false;
    }

    private function isValidLength(int $length): bool
    {
        return $length <= $this->maxLength;
    }

    public function fix(\SplFileInfo $file, Tokens $tokens): void
    {
        if (!$this->isDryRun()) {
            return;
        }

        $index = 0;
        $lineLength = 0;

        while ($token = $tokens[$index] ?? null) {
            $tokenContent = $token->getContent();

            $lineBreaksCount = substr_count($tokenContent, PHP_EOL);

            if ($lineBreaksCount === 0) {
                $lineLength += strlen($tokenContent);
            } elseif ($lineBreaksCount === 1) {
                if (!$this->isValidLength($lineLength)) {
                    $tokens->insertAt($index, [
                        new Token([T_WHITESPACE, ' ']),
                        new Token([T_COMMENT, '# Line too long'])
                    ]);
                }

                $lineLength = strlen(str_replace(PHP_EOL, '', $tokenContent));
            } else {
                $mustBeRebuild = false;
                $buffer = $lines = explode(PHP_EOL, $tokenContent);

                $previousLineEnd = array_shift($lines);
                $nextLineBegin = array_pop($lines);

                if (!$this->isValidLength($lineLength + strlen($previousLineEnd))) {
                    $buffer[0] = $buffer[0] . ' # Line too long';
                    $mustBeRebuild = true;
                }

                foreach ($lines as $key => $line) {
                    if (!$this->isValidLength(strlen($line))) {
                        $buffer[$key + 1] .= ' # Line too long';
                        $mustBeRebuild = true;
                    }
                }

                if ($mustBeRebuild) {
                    $tokens->clearAt($index);
                    $tokens->insertAt($index, [new Token([$token->getId(), implode(PHP_EOL, $buffer)])]);
                }

                $lineLength = strlen($nextLineBegin);
            }

            $index++;
        }
    }

    private function isDryRun(): bool
    {
        if (in_array('check', $_SERVER['argv'])) {
            return true;
        }

        if (in_array('--dry-run', $_SERVER['argv'])) {
            return true;
        }

        return false;
    }
}
