<?php

namespace Flying\Composer\Plugin\IO;

use Composer\Config;
use Composer\IO\IOInterface;

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

    private function haveAnswer($question, $default = null)
    {
        return $this->getAnswer($question, $default) !== null;
    }

    private function getAnswer($question, $default = null, $validator = null)
    {
        $question = trim($question);
        $questions = [$question];
        $question = preg_replace('/\<\/?(info|comment|question|error)\>/', '', $question);
        $questions[] = $question;
        $question = preg_replace('/\s*(\[[^\]]*\])?\:\s*$/', '', $question);
        $questions[] = $question;
        foreach ($questions as $q) {
            foreach ($this->questionnaire as $qq => $answer) {
                if (stripos(trim($qq), $q) === 0) {
                    try {
                        if (is_callable($validator)) {
                            $answer = $validator($answer);
                        }
                        return $answer !== null ? $answer : $default;
                    } catch (\Exception $e) {
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
    public function isInteractive()
    {
        return count($this->questionnaire) || $this->delegate->isInteractive();
    }

    /**
     * {@inheritdoc}
     */
    public function isVerbose()
    {
        return $this->delegate->isVerbose();
    }

    /**
     * {@inheritdoc}
     */
    public function isVeryVerbose()
    {
        return $this->delegate->isVeryVerbose();
    }

    /**
     * {@inheritdoc}
     */
    public function isDebug()
    {
        return $this->delegate->isDebug();
    }

    /**
     * {@inheritdoc}
     */
    public function isDecorated()
    {
        return $this->delegate->isDecorated();
    }

    /**
     * {@inheritdoc}
     */
    public function write($messages, $newline = true, $verbosity = self::NORMAL)
    {
        return $this->delegate->write($messages, $newline, $verbosity);
    }

    /**
     * {@inheritdoc}
     */
    public function writeError($messages, $newline = true, $verbosity = self::NORMAL)
    {
        return $this->delegate->writeError($messages, $newline, $verbosity);
    }

    /**
     * {@inheritdoc}
     */
    public function overwrite($messages, $newline = true, $size = null, $verbosity = self::NORMAL)
    {
        return $this->delegate->overwrite($messages, $newline, $size, $verbosity);
    }

    /**
     * {@inheritdoc}
     */
    public function overwriteError($messages, $newline = true, $size = null, $verbosity = self::NORMAL)
    {
        return $this->delegate->overwriteError($messages, $newline, $size, $verbosity);
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
    public function askConfirmation($question, $default = true)
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
    public function getAuthentications()
    {
        return $this->delegate->getAuthentications();
    }

    /**
     * {@inheritdoc}
     */
    public function hasAuthentication($repositoryName)
    {
        return $this->delegate->hasAuthentication($repositoryName);
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthentication($repositoryName)
    {
        return $this->delegate->getAuthentication($repositoryName);
    }

    /**
     * {@inheritdoc}
     */
    public function setAuthentication($repositoryName, $username, $password = null)
    {
        return $this->delegate->setAuthentication($repositoryName, $username, $password);
    }

    /**
     * {@inheritdoc}
     */
    public function loadConfiguration(Config $config)
    {
        return $this->delegate->loadConfiguration($config);
    }
}
