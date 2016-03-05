<?php

namespace Flying\Composer\Plugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Plugin\PluginInterface;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class WordpressSkeletonToolsPlugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var Composer
     */
    private $composer;
    /**
     * @var IOInterface
     */
    private $io;
    /**
     * @var string
     */
    private $root;
    /**
     * @var array
     */
    private $gitConfig;

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * @return Composer
     */
    public function getComposer()
    {
        return $this->composer;
    }

    /**
     * @return IOInterface
     */
    public function getIO()
    {
        return $this->io;
    }

    /**
     * @return string
     * @throws \RuntimeException
     */
    public function getProjectRoot()
    {
        if (!$this->root) {
            $this->root = dirname($this->getComposer()->getConfig()->get('vendor-dir'));
        }
        return $this->root;
    }

    public function onCreateProject()
    {
        $this->modifyComposer();
    }

    /**
     * Update composer.json to prepare it to use by newly created project
     * Code of this function is partially taken from Composer because of similar functionality
     *
     * @see Composer\Command\InitCommand::interact
     */
    private function modifyComposer()
    {
        try {
            $io = $this->getIO();
            if (!$io->isInteractive()) {
                $io->write('<info>composer.json configuration skipped because running in non-interactive mode. Update it later</info>');
                return;
            }
            $composerConfig = new JsonFile($this->getProjectRoot() . '/composer.json');
            if (!$composerConfig->exists()) {
                $io->write('<comment>composer.json is not found, skipping its configuration, you need to create it later</comment>');
                return;
            }
            try {
                /** @var array $config */
                $config = $composerConfig->read();
            } catch (\RuntimeException $e) {
                $io->write('<error>composer.json is not valid, skipping its configuration, you need to create it later</error>');
                return;
            }
            $config['name'] = '';
            $config['description'] = '';
            $config['authors'] = [];
            unset($config['version'], $config['type'], $config['keywords'], $config['homepage'], $config['time'], $config['license'], $config['support'], $config['require-dev']);
            $io->write('<info>composer.json will be modified now to match your project settings</info>');

            // Get package name 
            $git = $this->getGitConfig();
            $name = basename($this->getProjectRoot());
            $name = preg_replace('{(?:([a-z])([A-Z])|([A-Z])([A-Z][a-z]))}', '\\1\\3-\\2\\4', $name);
            $name = strtolower($name);
            if (array_key_exists('github.user', $git)) {
                $name = $git['github.user'] . '/' . $name;
            } elseif (!empty($_SERVER['USERNAME'])) {
                $name = $_SERVER['USERNAME'] . '/' . $name;
            } elseif (get_current_user()) {
                $name = get_current_user() . '/' . $name;
            } else {
                // package names must be in the format foo/bar
                $name = $name . '/' . $name;
            }
            $name = strtolower($name);
            $name = $io->askAndValidate(
                'Package name (<vendor>/<name>) [<comment>' . $name . '</comment>]: ',
                function ($value) use ($name) {
                    if (null === $value) {
                        return $name;
                    }

                    if (!preg_match('{^[a-z0-9_.-]+/[a-z0-9_.-]+$}', $value)) {
                        throw new \InvalidArgumentException(
                            'The package name ' . $value . ' is invalid, it should be lowercase and have a vendor name, a forward slash, and a package name, matching: [a-z0-9_.-]+/[a-z0-9_.-]+'
                        );
                    }

                    return $value;
                },
                null,
                $name
            );
            $config['name'] = $name;

            // Get package description
            $description = '';
            $description = $io->ask('Description [<comment>' . $description . '</comment>]: ', $description);
            $config['description'] = $description;

            // Get package author
            $author = '';
            $parseAuthor = function ($author) {
                if (preg_match('/^(?P<name>[- \.,\p{L}\p{N}\'’]+) <(?P<email>.+?)>$/u', $author, $match) && filter_var($match['email'], FILTER_VALIDATE_EMAIL) !== false) {
                    return [
                        'name'  => trim($match['name']),
                        'email' => $match['email'],
                    ];
                }
                return null;
            };
            $formatAuthor = function ($name, $email) {
                return sprintf('%s <%s>', $name, $email);
            };
            if (array_key_exists('user.name', $git) && array_key_exists('user.email', $git)) {
                $author = $formatAuthor($git['user.name'], $git['user.email']);
                if (!$parseAuthor($author)) {
                    $author = '';
                }
            }
            $author = $io->askAndValidate('Author [<comment>' . $author . '</comment>, n to skip]: ', function ($value) use ($parseAuthor, $formatAuthor, $author) {
                if ($value === 'n' || $value === 'no') {
                    return null;
                }
                $value = $value ?: $author;
                $author = $parseAuthor($value);
                if (!is_array($author)) {
                    throw new \InvalidArgumentException(
                        'Invalid author string.  Must be in the format: ' .
                        'John Smith <john@example.com>'
                    );
                }
                return $formatAuthor($author['name'], $author['email']);
            }, null, $author);
            if ($author) {
                $config['authors'][] = $parseAuthor($author);
            }
            $composerConfig->write($config);
            $io->write('<info>composer.json is successfully updated</info>');
        } catch (\Exception $e) {
            $this->getIO()->writeError('composer.json configuration failed due to exception: ' . $e->getMessage());
        }
    }

    /**
     * Taken from Composer sources
     *
     * @see Composer\Command\InitCommand::getGitConfig
     * @return array
     */
    private function getGitConfig()
    {
        if (null !== $this->gitConfig) {
            return $this->gitConfig;
        }

        try {
            $finder = new ExecutableFinder();
            $gitBin = $finder->find('git');

            $cmd = new Process(sprintf('%s config -l', ProcessExecutor::escape($gitBin)));
            $cmd->run();

            if ($cmd->isSuccessful()) {
                $this->gitConfig = array();
                preg_match_all('{^([^=]+)=(.*)$}m', $cmd->getOutput(), $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $this->gitConfig[$match[1]] = $match[2];
                }

                return $this->gitConfig;
            }

            return $this->gitConfig = array();
        } catch (\Exception $e) {

        }
        return array();
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            'post-create-project-cmd' => 'onCreateProject',
        ];
    }
}
