<?php

namespace Flying\Composer\Plugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Plugin\PluginInterface;
use Composer\Util\Filesystem;
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
        // This is the only moment when we can override Wordpress installation path
        if ($this->io->isInteractive()) {
            $installDir = 'wordpress';
            $extra = $this->composer->getPackage()->getExtra();
            if (array_key_exists('wordpress-install-dir', $extra)) {
                $installDir = $extra['wordpress-install-dir'];
            }
            try {
                $installDir = $this->io->askAndValidate(sprintf('Wordpress installation directory [<comment>%s</comment>]: ', $installDir), function ($value) use ($installDir) {
                    if ($value === '') {
                        $value = $installDir;
                    }
                    $fs = new Filesystem();
                    if ($fs->isAbsolutePath($value)) {
                        throw new \InvalidArgumentException('Wordpress installation directory should be defined as relative path');
                    }
                    $root = $fs->normalizePath($this->getProjectRoot());
                    $path = $fs->normalizePath($this->getProjectRoot() . '/' . $value);
                    if (strpos($path, $root) !== 0) {
                        throw new \InvalidArgumentException('Wordpress installation directory should reside within project root');
                    }
                    if (file_exists($path)) {
                        throw new \InvalidArgumentException('Wordpress installation directory is already exists');
                    }
                    return $fs->findShortestPath($root, $path, true);
                }, null, $installDir);
                $extra['wordpress-install-dir'] = $installDir;
                $this->composer->getPackage()->setExtra($extra);
            } catch (\Exception $e) {

            }
        }
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
        $this->createWordpressConfig();
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
                $io->write('<info>composer.json configuration is skipped because running in non-interactive mode. Update it later</info>');
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
     * Create main wp-config.php and local local-config.php configuration files for Wordpress upon creation of new project
     */
    private function createWordpressConfig()
    {
        try {
            $io = $this->getIO();
            if (!$io->isInteractive()) {
                $io->write('<info>wp-config.php configuration is skipped because running in non-interactive mode</info>');
                return;
            }
            $configurations = [
                'global' => [
                    'file'    => 'wp-config.php',
                    'entries' => [],
                ],
                'local'  => [
                    'file'    => 'local-config.php',
                    'entries' => [],
                ],
            ];
            foreach ($configurations as $item) {
                if (file_exists($this->getProjectRoot() . '/' . $item['file'])) {
                    $io->write('<comment>' . $item['file'] . ' configuration file is already exists, skipping</comment>');
                    return;
                }
            }

            // Generate keys and salts
            $keysAndSalts = [
                'AUTH_KEY',
                'SECURE_AUTH_KEY',
                'LOGGED_IN_KEY',
                'NONCE_KEY',
                'AUTH_SALT',
                'SECURE_AUTH_SALT',
                'LOGGED_IN_SALT',
                'NONCE_SALT',
            ];
            foreach ($keysAndSalts as $key) {
                $value = '';
                for ($i = 0; $i < 64; $i++) {
                    $value .= chr(random_int(33, 126));
                }
                $configurations['global']['entries'][$key] = [
                    'name'     => $key,
                    'constant' => true,
                    'value'    => $value,
                ];
            }

            // Perform database connection configuration
            // Database tables prefix should be defined in any way
            $configurations['global']['entries']['table_prefix'] = [
                'name'     => 'table_prefix',
                'constant' => false,
                'value'    => 'wp_',
            ];
            if ($io->askConfirmation('<info>Do you want to configure database connection parameters?</info> <comment>[Y,n]</comment>: ', true)) {
                $databaseParameters = [
                    [
                        'name'      => 'DB_HOST',
                        'constant'  => true,
                        'question'  => 'Database hostname',
                        'default'   => 'localhost',
                        'validator' => function ($value) {
                            if (filter_var('http://' . $value . '/', FILTER_VALIDATE_URL) === false) {
                                throw new \InvalidArgumentException('Invalid database host name');
                            }
                            return $value;
                        }
                    ],
                    [
                        'name'      => 'DB_NAME',
                        'constant'  => true,
                        'question'  => 'Database name',
                        'validator' => function ($value) {
                            $value = trim($value);
                            if ($value !== '' && (!preg_match('/^[^\x00\xff\x5c\/\.\:]+$/i', $value))) {
                                throw new \InvalidArgumentException('Database name can\'t contain special characters');
                            }
                            return $value;
                        }
                    ],
                    [
                        'name'      => 'DB_USER',
                        'constant'  => true,
                        'question'  => 'Database user name',
                        'validator' => function ($value) {
                            if (strlen($value) > 32) {
                                throw new \InvalidArgumentException('Database user name can\'t exceed 32 characters');
                            }
                            return $value;
                        }
                    ],
                    [
                        'name'     => 'DB_PASSWORD',
                        'constant' => true,
                        'question' => 'Database user password',
                    ],
                    [
                        'name'     => 'DB_CHARSET',
                        'constant' => true,
                        'question' => 'Database charset',
                        'default'  => 'utf8',
                    ],
                    [
                        'name'     => 'DB_COLLATE',
                        'constant' => true,
                        'default'  => 'utf8',
                    ],
                    [
                        'name'      => 'table_prefix',
                        'constant'  => false,
                        'question'  => 'Database tables prefix',
                        'default'   => 'wp_',
                        'target'    => 'global',
                        'validator' => function ($value) {
                            if ($value !== '' && substr($value, -1) !== '_') {
                                $value .= '_';
                            }
                            return $value;
                        }
                    ],
                ];
                foreach ($databaseParameters as $entry) {
                    $default = array_key_exists('default', $entry) ? $entry['default'] : null;
                    if (array_key_exists('question', $entry)) {
                        $question = $entry['question'] . ($default !== null ? ' [<comment>' . $default . '</comment>]' : '') . ': ';
                        if (array_key_exists('validator', $entry)) {
                            $value = $io->askAndValidate($question, $entry['validator'], null, $default);
                        } else {
                            $value = $io->ask($question, $default);
                        }
                    } else {
                        $value = $default;
                    }
                    $result = [
                        'name'     => $entry['name'],
                        'constant' => $entry['constant'],
                        'value'    => (string)$value,
                    ];
                    $configurations[array_key_exists('target', $entry) ? $entry['target'] : 'local']['entries'][$entry['name']] = $result;
                }
            }

            // Site URL configuration
            if ($io->askConfirmation('<info>Do you want to configure site URL parameters?</info> <comment>[Y,n]</comment>: ', true)) {
                $siteUrl = $io->askAndValidate('Enter URL of home page of this Wordpress site: ', function ($value) use ($io) {
                    if (strpos($value, '://') === false) {
                        $value = 'http://' . $value;
                    }
                    $p = parse_url($value);
                    if (!is_array($p)) {
                        throw new \InvalidArgumentException('Invalid URL');
                    }
                    if (!array_key_exists('host', $p)) {
                        throw new \InvalidArgumentException('Invalid URL, no host name is found');
                    }
                    if (array_key_exists('query', $p)) {
                        throw new \InvalidArgumentException('Invalid URL, no query string should be included');
                    }
                    if (array_key_exists('user', $p) || array_key_exists('pass', $p)) {
                        throw new \InvalidArgumentException('Invalid URL, no access credentials should be included');
                    }
                    if (!array_key_exists('scheme', $p)) {
                        $p['scheme'] = 'http';
                    }
                    if (array_key_exists('path', $p)) {
                        $p['path'] = rtrim($p['path'], '/');
                    }
                    return $p['scheme'] . '://' . $p['host'] . (array_key_exists('port', $p) ? ':' . $p['port'] : '') . (array_key_exists('path', $p) ? ':' . $p['path'] : '/');
                });
                $configurations['local']['entries']['WP_SITEURL'] = [
                    'name'     => 'WP_SITEURL',
                    'constant' => true,
                    'value'    => $siteUrl,
                ];
                $p = parse_url($siteUrl);
                $configurations['local']['entries']['WP_HOME'] = [
                    'name'     => 'WP_HOME',
                    'constant' => true,
                    'value'    => $p['scheme'] . '://' . $p['host'] . (array_key_exists('port', $p) ? ':' . $p['port'] : ''),
                ];
            }

            // Generate configuration files
            $executor = new ProcessExecutor($io);
            foreach ($configurations as $target => $configuration) {
                $templatePath = __DIR__ . '/templates/' . $configuration['file'] . '.tpl';
                $template = null;
                if (is_file($templatePath)) {
                    $template = file_get_contents($templatePath);
                }
                if (!is_string($template) || $template === '') {
                    $template = '<?php' . "\n";
                }
                foreach ($configuration['entries'] as $entry) {
                    $name = $entry['name'];
                    $value = $entry['value'];
                    if (is_string($value)) {
                        $value = "'" . addslashes($entry['value']) . "'";
                    } elseif ($value === null) {
                        $value = 'null';
                    } elseif ($value === true) {
                        $value = 'true';
                    } elseif ($value === false) {
                        $value = 'false';
                    }
                    if ($entry['constant']) {
                        $code = sprintf("define('%s', %s);", $name, $value);
                    } else {
                        $code = sprintf("$%s = %s;", $name, $value);
                    }
                    $template = preg_replace('/\/\*\s*\{\s*' . $name . '\s*\}\s*\*\//usi', $code, $template);
                }
                $template = preg_replace('/\/\*\s*\{\s*.+?\s*\}\s*\*\//usi', '', $template);
                if ($target === 'local') {
                    // There may be missed entries into local configuration file
                    $template = preg_replace('/(\r?\n){2,}/i', "\n\n", $template);
                }
                $configPath = $this->getProjectRoot() . '/' . $configuration['file'];
                file_put_contents($configPath, $template);
                if (!file_exists($configPath)) {
                    throw new \RuntimeException('Failed to write ' . $configuration['file'] . ' configuration file');
                }
                $tmp = tempnam(sys_get_temp_dir(), 'wpskt');
                $exitcode = $executor->execute(sprintf('%s -l %s > %s', PHP_BINARY, escapeshellarg($configPath), $tmp));
                unlink($tmp);
                if ($exitcode !== 0) {
                    unlink($configPath);
                    throw new \RuntimeException('Failed to generate ' . $configuration['file'] . ' configuration file');
                }
            }
            $io->write('<info>Wordpress configuration files are successfully created</info>');
        } catch (\Exception $e) {
            $this->getIO()->writeError(sprintf('<error>Wordpress configuration files generation failed: %s</error>', $e->getMessage()));
        }
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
