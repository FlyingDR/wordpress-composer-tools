<?php /** @noinspection DevelopmentDependenciesUsageInspection */

namespace Flying\Composer\Plugin\IO;

use Composer\Config;
use Composer\IO\IOInterface;
use Throwable;

class AutomatedIO implements IOInterface
{
    /**
     * @var IOInterface
     */
    private $delegate;
    /**
     * @var array
     */
    private $questionnaire;

    /**
     * AutomatedIO constructor.
     *
     * @param IOInterface $delegate
     * @param array $questionnaire
     */
    public function __construct(IOInterface $delegate, array $questionnaire = [])
    {
        $this->delegate = $delegate;
        $this->questionnaire = $questionnaire;
    }

    private function haveAnswer($question, $default = null): bool
    {
        return $this->getAnswer($question, $default) !== null;
    }

    private function getAnswer($question, $default = null, $validator = null)
    {
        $question = trim($question);
        $questions = [$question];
        /** @noinspection RegExpRedundantEscape */
        $question = preg_replace('/\<\/?(info|comment|question|error)\>/', '', $question);
        $questions[] = $question;
        /** @noinspection RegExpRedundantEscape */
        $question = preg_replace('/\s*(\[[^\]]*\])?\:\s*$/', '', $question);
        $questions[] = $question;
        foreach ($questions as $q) {
            foreach ($this->questionnaire as $qq => $answer) {
                if (stripos(trim($qq), $q) === 0) {
                    try {
                        if (is_callable($validator)) {
                            $answer = $validator($answer);
                        }
                        return $answer ?? $default;
                    } catch (Throwable $e) {
                        return $default;
                    }
                }
            }
        }
        return $default;
    }

    /**
     * {@inheritdoc}
     */
    public function isInteractive(): bool
    {
        return count($this->questionnaire) || $this->delegate->isInteractive();
    }

    /**
     * {@inheritdoc}
     */
    public function isVerbose(): bool
    {
        return $this->delegate->isVerbose();
    }

    /**
     * {@inheritdoc}
     */
    public function isVeryVerbose(): bool
    {
        return $this->delegate->isVeryVerbose();
    }

    /**
     * {@inheritdoc}
     */
    public function isDebug(): bool
    {
        return $this->delegate->isDebug();
    }

    /**
     * {@inheritdoc}
     */
    public function isDecorated(): bool
    {
        return $this->delegate->isDecorated();
    }

    /**
     * {@inheritdoc}
     */
    public function write($messages, $newline = true, $verbosity = self::NORMAL): void
    {
        $this->delegate->write($messages, $newline, $verbosity);
    }

    /**
     * {@inheritdoc}
     */
    public function writeError($messages, $newline = true, $verbosity = self::NORMAL): void
    {
        $this->delegate->writeError($messages, $newline, $verbosity);
    }

    /**
     * {@inheritdoc}
     */
    public function overwrite($messages, $newline = true, $size = null, $verbosity = self::NORMAL): void
    {
        $this->delegate->overwrite($messages, $newline, $size, $verbosity);
    }

    /**
     * {@inheritdoc}
     */
    public function overwriteError($messages, $newline = true, $size = null, $verbosity = self::NORMAL): void
    {
        $this->delegate->overwriteError($messages, $newline, $size, $verbosity);
    }

    /**
     * {@inheritdoc}
     */
    public function ask($question, $default = null)
    {
        if ($this->haveAnswer($question, $default)) {
            return $this->getAnswer($question, $default);
        }
        return $this->delegate->ask($question, $default);
    }

    /**
     * {@inheritdoc}
     */
    public function askConfirmation($question, $default = true): bool
    {
        if ($this->haveAnswer($question, $default)) {
            return (boolean)$this->getAnswer($question, $default);
        }
        return $this->delegate->askConfirmation($question, $default);
    }

    /**
     * {@inheritdoc}
     */
    public function askAndValidate($question, $validator, $attempts = null, $default = null)
    {
        if ($this->haveAnswer($question, $default)) {
            return $this->getAnswer($question, $default, $validator);
        }
        return $this->delegate->askAndValidate($question, $validator, $attempts, $default);
    }

    /**
     * {@inheritdoc}
     */
    public function askAndHideAnswer($question)
    {
        if ($this->haveAnswer($question)) {
            return $this->getAnswer($question);
        }
        return $this->delegate->askAndHideAnswer($question);
    }

    /**
     * {@inheritdoc}
     */
    public function select($question, $choices, $default, $attempts = false, $errorMessage = 'Value "%s" is invalid', $multiselect = false)
    {
        if ($this->haveAnswer($question, $default)) {
            return $this->getAnswer($question, $default);
        }
        return $this->delegate->select($question, $choices, $default, $attempts, $errorMessage, $multiselect);
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthentications(): array
    {
        return $this->delegate->getAuthentications();
    }

    /**
     * {@inheritdoc}
     */
    public function hasAuthentication($repositoryName): bool
    {
        return $this->delegate->hasAuthentication($repositoryName);
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthentication($repositoryName): array
    {
        return $this->delegate->getAuthentication($repositoryName);
    }

    /**
     * {@inheritdoc}
     */
    public function setAuthentication($repositoryName, $username, $password = null): void
    {
        $this->delegate->setAuthentication($repositoryName, $username, $password);
    }

    /**
     * {@inheritdoc}
     */
    public function loadConfiguration(Config $config): void
    {
        $this->delegate->loadConfiguration($config);
    }

    public function writeRaw($messages, $newline = true, $verbosity = self::NORMAL): void
    {
        $this->delegate->writeRaw($messages, $newline, $verbosity);
    }

    public function writeErrorRaw($messages, $newline = true, $verbosity = self::NORMAL): void
    {
        $this->delegate->writeErrorRaw($messages, $newline, $verbosity);
    }

    public function emergency($message, array $context = array()): void
    {
        $this->delegate->emergency($message, $context);
    }

    public function alert($message, array $context = array()): void
    {
        $this->delegate->alert($message, $context);
    }

    public function critical($message, array $context = array()): void
    {
        $this->delegate->critical($message, $context);
    }

    public function error($message, array $context = array()): void
    {
        $this->delegate->error($message, $context);
    }

    public function warning($message, array $context = array()): void
    {
        $this->delegate->warning($message, $context);
    }

    public function notice($message, array $context = array()): void
    {
        $this->delegate->notice($message, $context);
    }

    public function info($message, array $context = array()): void
    {
        $this->delegate->info($message, $context);
    }

    public function debug($message, array $context = array()): void
    {
        $this->delegate->debug($message, $context);
    }

    public function log($level, $message, array $context = array()): void
    {
        $this->delegate->log($level, $message, $context);
    }
}
